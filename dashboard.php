<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/smtp.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.html?error=1');
    exit;
}

$username = htmlspecialchars((string)($_SESSION['username'] ?? 'user'), ENT_QUOTES, 'UTF-8');
$activeTab = 'users';
if (isset($_GET['tab'])) {
    $tab = $_GET['tab'];
    if ($tab === 'channels' || $tab === 'inbox' || $tab === 'emails' || $tab === 'site' || $tab === 'users') {
        $activeTab = $tab;
    }
}

// Prepare DB, create channels table if missing, and handle create action
$tables = [];
$tableSamples = [];
$channels = [];
$emailsList = [];
$emailStatuses = [];
$flashMessage = '';
$flashError = '';
try {
	$db = getDatabaseConnection();
	// Ensure uploads directory exists
	$uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
	if (!is_dir($uploadsDir)) {
		@mkdir($uploadsDir, 0775, true);
	}
	// Ensure channels table exists
	$db->exec('CREATE TABLE IF NOT EXISTS channels (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		name TEXT NOT NULL,
		homepage_url TEXT NOT NULL,
		logo_path TEXT,
		created_at TEXT NOT NULL
	)');

    // Email-related tables
    $db->exec('CREATE TABLE IF NOT EXISTS email_statuses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key TEXT UNIQUE NOT NULL,
        label TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        name TEXT,
        created_at TEXT NOT NULL
    )');
	$db->exec('CREATE TABLE IF NOT EXISTS email_responses (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		email_id INTEGER NOT NULL,
		body TEXT NOT NULL,
		sent_via TEXT,
		created_at TEXT NOT NULL,
		FOREIGN KEY(email_id) REFERENCES emails(id)
	)');

    // Messages table for INBOX
    $db->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id TEXT UNIQUE NOT NULL,
        from_name TEXT,
        from_email TEXT,
        subject TEXT,
        mail_date TEXT,
        snippet TEXT,
        created_at TEXT NOT NULL
    )');
	// Seed statuses if empty
	$cntRow = $db->query('SELECT COUNT(1) AS c FROM email_statuses')->fetch();
	if ((int)($cntRow['c'] ?? 0) === 0) {
		$seed = $db->prepare('INSERT INTO email_statuses (key, label) VALUES (:k, :l)');
		foreach ([['new','New'],['read','Read'],['replied','Replied'],['ignore','Ignore'],['marketing','Marketing']] as $s) {
			$seed->execute([':k'=>$s[0], ':l'=>$s[1]]);
		}
	}
    // Load statuses (still available for future use)
    $st = $db->query('SELECT id, key, label FROM email_statuses ORDER BY id');
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) { $emailStatuses[(int)$row['id']] = $row; }

    // Auto-migrate legacy emails schema to new unique (email, name) schema
    $cols = [];
    $ti = $db->query('PRAGMA table_info(emails)');
    while ($ci = $ti->fetch(PDO::FETCH_ASSOC)) { $cols[] = $ci['name']; }
    if (in_array('message_id', $cols, true) || in_array('from_addr', $cols, true)) {
        $db->beginTransaction();
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS emails_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                name TEXT,
                created_at TEXT NOT NULL
            )');
            // Extract distinct email/name from legacy rows
            $legacy = $db->query('SELECT DISTINCT from_addr FROM emails WHERE from_addr IS NOT NULL');
            while ($row = $legacy->fetch(PDO::FETCH_ASSOC)) {
                $fromAddr = (string)$row['from_addr'];
                // parse name and email
                $name = null; $emailOnly = $fromAddr;
                if (preg_match('/<([^>]+)>/', $fromAddr, $m)) {
                    $emailOnly = trim($m[1]);
                    $n = trim(str_replace($m[0], '', $fromAddr));
                    $n = trim($n, " \"'\t");
                    if ($n !== '') { $name = $n; }
                } else {
                    // if formatted as Name (email)
                    if (preg_match('/([^\(]+)\(([^\)]+)\)/', $fromAddr, $m2)) {
                        $name = trim($m2[1]);
                        $emailOnly = trim($m2[2]);
                    }
                }
                $emailOnly = strtolower($emailOnly);
                if ($emailOnly !== '') {
                    $ins = $db->prepare('INSERT OR IGNORE INTO emails_new (email, name, created_at) VALUES (:e,:n,:t)');
                    $ins->execute([':e'=>$emailOnly, ':n'=>$name, ':t'=>(new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                }
            }
            $db->exec('DROP TABLE emails');
            $db->exec('ALTER TABLE emails_new RENAME TO emails');
            $db->commit();
        } catch (Throwable $migrE) {
            $db->rollBack();
        }
    }

	// Handle create channel POST
	if ($activeTab === 'channels' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
		$name = trim((string)($_POST['channel_name'] ?? ''));
		$homepage = trim((string)($_POST['channel_homepage'] ?? ''));
		$logoPathRel = null;
		if ($name === '' || $homepage === '') {
			$flashError = 'Channel name and homepage are required.';
		} else if (!preg_match('~^https?://~i', $homepage)) {
			$flashError = 'Homepage must start with http:// or https://';
		} else {
			// Handle optional file upload
			if (!empty($_FILES['channel_logo']['name']) && (int)($_FILES['channel_logo']['error'] ?? 0) === UPLOAD_ERR_OK) {
				$tmp = (string)$_FILES['channel_logo']['tmp_name'];
				$orig = (string)$_FILES['channel_logo']['name'];
				$ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
				$allowed = ['png','jpg','jpeg','gif','webp'];
				if ($ext !== '' && in_array($ext, $allowed, true)) {
					$basename = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($ext ? ('.' . $ext) : '');
					$destAbs = $uploadsDir . DIRECTORY_SEPARATOR . $basename;
					if (@move_uploaded_file($tmp, $destAbs)) {
						$logoPathRel = 'uploads/' . $basename;
					} else {
						$flashError = 'Failed to save uploaded file.';
					}
				} else if ($ext !== '') {
					$flashError = 'Unsupported file type. Allowed: png, jpg, jpeg, gif, webp';
				}
			}

			if ($flashError === '') {
				$ins = $db->prepare('INSERT INTO channels (name, homepage_url, logo_path, created_at) VALUES (:n, :h, :l, :t)');
				$ins->execute([
					':n' => $name,
					':h' => $homepage,
					':l' => $logoPathRel,
					':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
				]);
				$flashMessage = 'Channel created.';
			}
		}
	}

	// Inbox actions: status update, reply log
	if ($activeTab === 'inbox' && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) {
		$action = (string)($_POST['action'] ?? '');
		if ($action === 'set_status') {
			$emailId = (int)($_POST['email_id'] ?? 0);
			$statusId = (int)($_POST['status_id'] ?? 0);
			if ($emailId > 0 && isset($emailStatuses[$statusId])) {
				$db->prepare('UPDATE emails SET status_id=:s WHERE id=:id')->execute([':s'=>$statusId, ':id'=>$emailId]);
				$flashMessage = 'Status updated';
			}
		}
		if ($action === 'reply') {
			$emailId = (int)($_POST['email_id'] ?? 0);
			$subject = trim((string)($_POST['subject'] ?? ''));
			$body = trim((string)($_POST['body'] ?? ''));
			if ($emailId > 0 && $body !== '' && $subject !== '') {
				// find recipient address
				$toRow = null; $toEmail=''; $toName='';
				$stTo = $db->prepare('SELECT email, name FROM emails WHERE id = :id');
				$stTo->execute([':id'=>$emailId]);
				$toRow = $stTo->fetch(PDO::FETCH_ASSOC);
				if ($toRow) { $toEmail = (string)$toRow['email']; $toName = (string)($toRow['name'] ?? ''); }

				$cfg = [];
				$cfgFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
				if (is_file($cfgFile)) { $cfg = require $cfgFile; }
				$smtpCfg = $cfg['smtp'] ?? [];
				$hostS = (string)($smtpCfg['host'] ?? '');
				$portS = (int)($smtpCfg['port'] ?? 465);
				$encS = strtolower((string)($smtpCfg['encryption'] ?? 'ssl'));
				$userS = (string)($smtpCfg['username'] ?? '');
				$passS = (string)($smtpCfg['password'] ?? '');
				$fromEmail = $userS; $fromName = '';

				if ($hostS && $userS && $passS && $toEmail) {
					[$ok, $msg] = sendSmtpEmail($hostS, $portS, $encS, $userS, $passS, $fromEmail, $fromName, $toEmail, $toName, $subject, $body, null);
					if ($ok) {
						$db->prepare('INSERT INTO email_responses (email_id, body, sent_via, created_at) VALUES (:e,:b,:v,:t)')
							->execute([':e'=>$emailId, ':b'=>$body, ':v'=>'smtp', ':t'=>(new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
						$repliedId = null; foreach ($emailStatuses as $sid=>$s) { if ($s['key']==='replied') { $repliedId = $sid; break; } }
						if ($repliedId) { $db->prepare('UPDATE emails SET status_id=:s WHERE id=:id')->execute([':s'=>$repliedId, ':id'=>$emailId]); }
						$flashMessage = 'Email sent';
					} else {
						$flashError = 'SMTP error: ' . $msg;
					}
				} else {
					$flashError = 'SMTP not configured or recipient missing.';
				}
			}
		}
	}

	// Fetch for Users tab (existing overview)
	$tablesStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
	$tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
	foreach ($tables as $t) {
		$identifier = '"' . str_replace('"', '""', $t) . '"';
		$rowsStmt = $db->query("SELECT * FROM {$identifier} LIMIT 10");
		$tableSamples[$t] = $rowsStmt ? $rowsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	// Fetch channels list
	$chStmt = $db->query('SELECT id, name, homepage_url, logo_path, created_at FROM channels ORDER BY id DESC');
	$channels = $chStmt ? $chStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
	// swallow in demo
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard</title>
  <style>
    html, body { height: 100%; margin: 0; }
    body { display: grid; place-items: center; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Arial, Helvetica, "Apple Color Emoji", "Segoe UI Emoji"; }
    .container { text-align: center; }
    .nav { display:flex; gap:12px; justify-content:center; margin: 12px 0 20px }
    .nav a { padding:6px 10px; border:1px solid #ddd; border-radius:6px; text-decoration:none; color:#333 }
    .nav a.active { background:#f5f5f5 }
    .form-row { margin-bottom:10px }
    .label { display:block; font-size:14px; color:#444; margin-bottom:4px }
    input[type=text], input[type=url], input[type=file] { width:100%; padding:8px; box-sizing:border-box }
    button { padding:10px 12px; cursor:pointer }
    table { border-collapse:collapse; width:100% }
    th, td { padding:8px; border-bottom:1px solid #eee; text-align:left }
  </style>
</head>
<body>
  <main class="container">
    <h1>Welcome, <?php echo $username; ?>!</h1>
    <p>You are logged in.</p>
    <p><a href="logout.php">Log out</a></p>

    <nav class="nav">
      <a href="dashboard.php?tab=users" class="<?php echo $activeTab==='users'?'active':''; ?>">Users</a>
      <a href="dashboard.php?tab=channels" class="<?php echo $activeTab==='channels'?'active':''; ?>">Channels</a>
      <a href="dashboard.php?tab=inbox" class="<?php echo $activeTab==='inbox'?'active':''; ?>">INBOX</a>
      <a href="dashboard.php?tab=emails" class="<?php echo $activeTab==='emails'?'active':''; ?>">Emails</a>
      <a href="dashboard.php?tab=site" class="<?php echo $activeTab==='site'?'active':''; ?>">Site</a>
    </nav>

    <?php if ($activeTab === 'channels') { ?>
      <section style="text-align:left; max-width:720px">
        <h2>Add channel</h2>
        <?php if ($flashMessage) { ?><div style="color:green; margin:8px 0"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        <?php if ($flashError) { ?><div style="color:#c00; margin:8px 0"><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        <form action="dashboard.php?tab=channels" method="post" enctype="multipart/form-data">
          <div class="form-row">
            <label class="label" for="channel_name">Channel name</label>
            <input id="channel_name" name="channel_name" type="text" required />
          </div>
          <div class="form-row">
            <label class="label" for="channel_homepage">Channel homepage</label>
            <input id="channel_homepage" name="channel_homepage" type="url" placeholder="https://example.com" required />
          </div>
          <div class="form-row">
            <label class="label" for="channel_logo">Upload logo (png/jpg/gif/webp)</label>
            <input id="channel_logo" name="channel_logo" type="file" accept=".png,.jpg,.jpeg,.gif,.webp" />
          </div>
          <button type="submit">Create channel</button>
        </form>

        <h2 style="margin-top:24px">Channels</h2>
        <?php if (empty($channels)) { ?>
          <p style="color:#666">No channels yet.</p>
        <?php } else { ?>
          <div style="overflow:auto; border:1px solid #ddd; border-radius:6px">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Name</th>
                  <th>Homepage</th>
                  <th>Logo</th>
                  <th>Created</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($channels as $ch) { ?>
                  <tr>
                    <td><?php echo (int)$ch['id']; ?></td>
                    <td><?php echo htmlspecialchars((string)$ch['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><a href="<?php echo htmlspecialchars((string)$ch['homepage_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">link</a></td>
                    <td>
                      <?php if (!empty($ch['logo_path'])) { ?>
                        <img src="<?php echo htmlspecialchars((string)$ch['logo_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="logo" style="height:28px" />
                      <?php } else { echo '-'; } ?>
                    </td>
                    <td><?php echo htmlspecialchars((string)$ch['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        <?php } ?>
      </section>
    <?php } else if ($activeTab === 'inbox') { ?>
      <section style="text-align:left; max-width:920px">
        <h2>Inbox</h2>
        <?php
        $imapSupported = function_exists('imap_open');
        $emails = [];
        $imapError = '';
        if (!$imapSupported) {
            echo '<p style="color:#c00">PHP IMAP extension is not available on this server.</p>';
        } else {
            $cfg = [];
            $cfgFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
            if (is_file($cfgFile)) {
                $cfg = require $cfgFile;
            }
            $imapCfg = $cfg['imap'] ?? [];
            $host = (string)($imapCfg['host'] ?? '');
            $port = (int)($imapCfg['port'] ?? 993);
            $encryption = strtolower((string)($imapCfg['encryption'] ?? 'ssl'));
            $usernameCfg = (string)($imapCfg['username'] ?? '');
            $passwordCfg = (string)($imapCfg['password'] ?? '');
            $validateCert = (bool)($imapCfg['validate_cert'] ?? false);
            $limit = (int)($imapCfg['limit'] ?? 20);

            if ($host && $usernameCfg && $passwordCfg) {
                $flags = '/imap';
                if ($encryption === 'ssl' || $encryption === 'tls') { $flags .= '/ssl'; }
                if ($encryption === 'starttls') { $flags .= '/tls'; }
                if (!$validateCert) { $flags .= '/novalidate-cert'; }
                $mailbox = sprintf('{%s:%d%s}INBOX', $host, $port, $flags);
                $inbox = @imap_open($mailbox, $usernameCfg, $passwordCfg, 0, 1, [
                    'DISABLE_AUTHENTICATOR' => 'gssapi'
                ]);
                if ($inbox === false) {
                    $imapError = imap_last_error() ?: 'Unable to connect to mailbox.';
                } else {
                    $num = imap_num_msg($inbox);
                    $start = max(1, $num - $limit + 1);
                    for ($i = $num; $i >= $start; $i--) {
                        $overview = imap_headerinfo($inbox, $i);
                        $msgId = isset($overview->message_id) ? (string)$overview->message_id : '';
                        if ($msgId === '') {
                            $fallback = ((string)($overview->fromaddress ?? '')) . '|' . ((string)($overview->subject ?? '')) . '|' . ((string)($overview->date ?? ''));
                            $msgId = 'fallback:' . sha1($fallback);
                        }
                        $subj = isset($overview->subject) ? (string)imap_utf8($overview->subject) : '';
                        $from = isset($overview->fromaddress) ? (string)$overview->fromaddress : '';
                        $dateStr = isset($overview->date) ? (string)$overview->date : '';
                        $emails[] = [
                            'index' => $i,
                            'message_id' => $msgId,
                            'from' => $from,
                            'subject' => $subj,
                            'date' => $dateStr,
                        ];
                        // Upsert into messages and ensure contacts (emails)
                        try {
                            // parse from into name/email
                            $fromName = null; $fromEmail = $from;
                            if (preg_match('/<([^>]+)>/', $from, $mFrom)) {
                                $fromEmail = strtolower(trim($mFrom[1]));
                                $n = trim(str_replace($mFrom[0], '', $from));
                                $n = trim($n, " \"'\t");
                                if ($n !== '') { $fromName = $n; }
                            }
                            $snippet = '';
                            $insMsg = $db->prepare('INSERT OR IGNORE INTO messages (message_id, from_name, from_email, subject, mail_date, snippet, created_at) VALUES (:m,:fn,:fe,:s,:d,:n,:t)');
                            $insMsg->execute([':m'=>$msgId, ':fn'=>$fromName, ':fe'=>$fromEmail, ':s'=>$subj, ':d'=>$dateStr, ':n'=>$snippet, ':t'=>(new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                            if ($fromEmail !== '') {
                                $db->prepare('INSERT OR IGNORE INTO emails (email, name, created_at) VALUES (:e,:n,:t)')
                                   ->execute([':e'=>$fromEmail, ':n'=>$fromName, ':t'=>(new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                            }
                        } catch (Throwable $ign) {}
                    }
                    imap_close($inbox);
                }
            } else {
                echo '<p style="color:#c00">Missing IMAP credentials. Copy config.example.php to config.local.php and fill in your details.</p>';
            }
        }
        ?>
        <?php if ($imapError) { ?><div style="color:#c00; margin:8px 0"><?php echo htmlspecialchars($imapError, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        <?php if (!empty($emails)) { ?>
          <div style="overflow:auto; border:1px solid #ddd; border-radius:6px">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>From</th>
                  <th>Subject</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($emails as $em) { $viewUrl = 'dashboard.php?tab=inbox&view=' . urlencode((string)($em['message_id'] ?? '')); ?>
                  <tr>
                    <td><a href="<?php echo $viewUrl; ?>" style="text-decoration:none"><?php echo (int)$em['index']; ?></a></td>
                    <td><a href="<?php echo $viewUrl; ?>" style="text-decoration:none"><?php echo htmlspecialchars((string)$em['from'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td><a href="<?php echo $viewUrl; ?>" style="text-decoration:none"><?php echo htmlspecialchars((string)$em['subject'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td><a href="<?php echo $viewUrl; ?>" style="text-decoration:none"><?php echo htmlspecialchars((string)$em['date'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
          <?php
            // Detail panel for a selected message
            $viewMid = isset($_GET['view']) ? (string)$_GET['view'] : '';
            if ($viewMid !== '') {
                $detail = null; $attachments = [];
                // reopen IMAP and fetch by Message-ID header
                if ($host && $usernameCfg && $passwordCfg) {
                    $flags = '/imap';
                    if ($encryption === 'ssl' || $encryption === 'tls') { $flags .= '/ssl'; }
                    if ($encryption === 'starttls') { $flags .= '/tls'; }
                    if (!$validateCert) { $flags .= '/novalidate-cert'; }
                    $mailbox = sprintf('{%s:%d%s}INBOX', $host, $port, $flags);
                    $inbox2 = @imap_open($mailbox, $usernameCfg, $passwordCfg, 0, 1, ['DISABLE_AUTHENTICATOR' => 'gssapi']);
                    if ($inbox2) {
                        $ids = @imap_search($inbox2, 'HEADER Message-ID "' . addcslashes($viewMid, '"') . '"');
                        if ($ids === false) { $ids = []; }
                        // fallback: linear scan in recent window
                        if (empty($ids)) {
                            $num2 = imap_num_msg($inbox2);
                            $start2 = max(1, $num2 - $limit + 1);
                            for ($i2 = $num2; $i2 >= $start2; $i2--) {
                                $ov = imap_headerinfo($inbox2, $i2);
                                $mid = isset($ov->message_id) ? (string)$ov->message_id : '';
                                if ($mid === '') {
                                    $fallback = ((string)($ov->fromaddress ?? '')) . '|' . ((string)($ov->subject ?? '')) . '|' . ((string)($ov->date ?? ''));
                                    $mid = 'fallback:' . sha1($fallback);
                                }
                                if ($mid === $viewMid) { $ids = [$i2]; break; }
                            }
                        }
                        if (!empty($ids)) {
                            $msgNo = (int)$ids[0];
                            $hdr = imap_headerinfo($inbox2, $msgNo);
                            $struct = @imap_fetchstructure($inbox2, $msgNo);
                            // helper to get body
                            $get_part = function($mbox, $msgno, $p, $partno) use (&$attachments) {
                                $data = '';
                                if ($p->type == TYPETEXT && ($p->subtype == 'PLAIN' || $p->subtype == 'HTML')) {
                                    $data = @imap_fetchbody($mbox, $msgno, $partno);
                                    if ($p->encoding == ENCBASE64) $data = base64_decode($data);
                                    elseif ($p->encoding == ENCQUOTEDPRINTABLE) $data = quoted_printable_decode($data);
                                }
                                // attachments
                                $isAttachment = false; $filename = '';
                                if (!empty($p->dparameters)) {
                                    foreach ($p->dparameters as $dp) {
                                        if (strtolower($dp->attribute) == 'filename') { $isAttachment = true; $filename = (string)$dp->value; }
                                    }
                                }
                                if (!empty($p->parameters)) {
                                    foreach ($p->parameters as $pp) {
                                        if (strtolower($pp->attribute) == 'name') { $isAttachment = true; if ($filename==='') $filename = (string)$pp->value; }
                                    }
                                }
                                if ($isAttachment) {
                                    $attachments[] = [ 'part' => $partno, 'filename' => $filename ];
                                }
                                return $data;
                            };
                            $plain = ''; $html = '';
                            if ($struct && !empty($struct->parts)) {
                                $pno = 1;
                                foreach ($struct->parts as $ix => $p) {
                                    $partno = (string)($ix+1);
                                    $content = $get_part($inbox2, $msgNo, $p, $partno);
                                    if ($p->type == TYPETEXT && $p->subtype == 'PLAIN' && $content !== '') { $plain .= $content; }
                                    if ($p->type == TYPETEXT && $p->subtype == 'HTML' && $content !== '') { $html .= $content; }
                                }
                            } else {
                                $raw = @imap_body($inbox2, $msgNo);
                                $plain = quoted_printable_decode($raw);
                            }
                            $detail = [
                                'from' => isset($hdr->fromaddress) ? (string)$hdr->fromaddress : '',
                                'subject' => isset($hdr->subject) ? (string)imap_utf8($hdr->subject) : '',
                                'date' => isset($hdr->date) ? (string)$hdr->date : '',
                                'plain' => $plain,
                                'html' => $html,
                                'attachments' => $attachments,
                            ];
                        }
                        @imap_close($inbox2);
                    }
                }
          ?>
          <?php if (!empty($detail)) { ?>
            <div style="margin-top:16px; padding:12px; border:1px solid #ddd; border-radius:6px">
              <h3 style="margin-top:0">Message</h3>
              <div><strong>From:</strong> <?php echo htmlspecialchars($detail['from'], ENT_QUOTES, 'UTF-8'); ?></div>
              <div><strong>Subject:</strong> <?php echo htmlspecialchars($detail['subject'], ENT_QUOTES, 'UTF-8'); ?></div>
              <div><strong>Date:</strong> <?php echo htmlspecialchars($detail['date'], ENT_QUOTES, 'UTF-8'); ?></div>
              <div style="margin-top:12px">
                <strong>Content:</strong>
                <div style="border:1px solid #eee; border-radius:6px; padding:10px; background:#fafafa; max-height:420px; overflow:auto">
                  <?php
                    if ($detail['html'] !== '') {
                        // show HTML as escaped preview
                        echo nl2br(htmlspecialchars($detail['html'], ENT_QUOTES, 'UTF-8'));
                    } else {
                        echo nl2br(htmlspecialchars($detail['plain'], ENT_QUOTES, 'UTF-8'));
                    }
                  ?>
                </div>
              </div>
              <div style="margin-top:12px">
                <strong>Attachments:</strong>
                <?php if (empty($detail['attachments'])) { echo '<span style="color:#666"> none</span>'; } else { ?>
                  <ul>
                    <?php foreach ($detail['attachments'] as $att) { ?>
                      <li><?php echo htmlspecialchars((string)$att['filename'], ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php } ?>
                  </ul>
                <?php } ?>
              </div>
              <div style="margin-top:12px">
                <details>
                  <summary>Reply</summary>
                  <?php
                    // Find/create contact id by from email
                    $fromAddr = (string)$detail['from'];
                    $fromEmailOnly = $fromAddr;
                    if (preg_match('/<([^>]+)>/', $fromAddr, $mfe)) { $fromEmailOnly = strtolower(trim($mfe[1])); }
                    $contactId = null;
                    if ($fromEmailOnly !== '') {
                        try {
                            $db->prepare('INSERT OR IGNORE INTO emails (email, name, created_at) VALUES (:e,:n,:t)')
                               ->execute([':e'=>$fromEmailOnly, ':n'=>null, ':t'=>(new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                            $stC = $db->prepare('SELECT id FROM emails WHERE email = :e');
                            $stC->execute([':e'=>$fromEmailOnly]);
                            $rC = $stC->fetch();
                            if ($rC) { $contactId = (int)$rC['id']; }
                        } catch (Throwable $ign) {}
                    }
                  ?>
                  <?php if ($contactId) { ?>
                    <form action="dashboard.php?tab=inbox&view=<?php echo urlencode($viewMid); ?>" method="post" style="margin-top:8px">
                      <input type="hidden" name="action" value="reply" />
                      <input type="hidden" name="email_id" value="<?php echo (int)$contactId; ?>" />
                      <input type="text" name="subject" placeholder="Subject" style="width:100%; margin-bottom:6px" required />
                      <textarea name="body" rows="6" style="width:100%" placeholder="Your reply..." required></textarea>
                      <div style="margin-top:6px"><button type="submit">Send & Save</button></div>
                    </form>
                  <?php } else { echo '<p style="color:#666">No contact email found to reply.</p>'; } ?>
                </details>
              </div>
            </div>
          <?php } ?>
          <?php } // end detail panel ?>
        <?php } else if ($imapSupported && !$imapError) { ?>
          <p style="color:#666">No emails found.</p>
        <?php } ?>
      </section>
    <?php } else if ($activeTab === 'emails') { ?>
      <section style="text-align:left; max-width:1024px">
        <h2>Emails</h2>
        <?php
          $rows = [];
          try {
            $emStmt = $db->query('SELECT e.id, e.email, e.name FROM emails e ORDER BY e.id DESC');
            $rows = $emStmt ? $emStmt->fetchAll(PDO::FETCH_ASSOC) : [];
          } catch (Throwable $ign) {}

          // handle edits
          if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($activeTab === 'emails')) {
            $act = (string)($_POST['action'] ?? '');
            if ($act === 'update_email') {
              $id = (int)($_POST['id'] ?? 0);
              $email = strtolower(trim((string)($_POST['email'] ?? '')));
              $name = trim((string)($_POST['name'] ?? ''));
              if ($id > 0 && $email !== '') {
                try {
                  $st = $db->prepare('UPDATE emails SET email = :e, name = :n WHERE id = :id');
                  $st->execute([':e'=>$email, ':n'=>($name !== '' ? $name : null), ':id'=>$id]);
                  echo '<div style="color:green; margin:8px 0">Saved</div>';
                } catch (Throwable $upErr) {
                  echo '<div style="color:#c00; margin:8px 0">Error saving: ' . htmlspecialchars($upErr->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
                }
              }
            }
            // Reload rows after update
            try {
              $emStmt = $db->query('SELECT e.id, e.email, e.name FROM emails e ORDER BY e.id DESC');
              $rows = $emStmt ? $emStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable $ign) {}
          }
        ?>
        <?php if (empty($rows)) { ?>
          <p style="color:#666">No saved emails yet. Visit the INBOX tab to fetch and save.</p>
        <?php } else { ?>
          <div style="overflow:auto; border:1px solid #ddd; border-radius:6px">
            <table>
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Email</th>
                  <th>Name</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $em) { ?>
                  <tr>
                    <td><?php echo (int)$em['id']; ?></td>
                    <td>
                      <form action="dashboard.php?tab=emails" method="post" style="display:flex; gap:6px; align-items:center">
                        <input type="hidden" name="action" value="update_email" />
                        <input type="hidden" name="id" value="<?php echo (int)$em['id']; ?>" />
                        <input type="text" name="email" value="<?php echo htmlspecialchars((string)$em['email'], ENT_QUOTES, 'UTF-8'); ?>" style="min-width:260px" required />
                        <input type="text" name="name" value="<?php echo htmlspecialchars((string)($em['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="min-width:200px" />
                        <button type="submit">Save</button>
                      </form>
                    </td>
                    <td><?php echo htmlspecialchars((string)($em['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        <?php } ?>
      </section>
    <?php } else if ($activeTab === 'site') { ?>
      <section style="text-align:left; max-width:980px">
        <h2>Site specification</h2>
        <?php
          $dataModelPath = __DIR__ . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'data-model.md';
          $uiBehaviorPath = __DIR__ . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'ui-behavior.md';
          $renderMd = function(string $path): string {
              if (!is_file($path)) { return '<p style="color:#666">Missing: ' . htmlspecialchars(basename($path), ENT_QUOTES, 'UTF-8') . '</p>'; }
              $txt = (string)@file_get_contents($path);
              // minimal Markdown-ish rendering: escape then convert basic headings and line breaks
              $safe = htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');
              $safe = preg_replace('/^## (.*)$/m', '<h3>$1</h3>', $safe);
              $safe = preg_replace('/^### (.*)$/m', '<h4>$1</h4>', $safe);
              $safe = preg_replace('/^- (.*)$/m', '<li>$1</li>', $safe);
              $safe = preg_replace('/\n{2,}/', "\n\n", $safe);
              // wrap consecutive <li> into <ul>
              $safe = preg_replace('/(?:<li>.*<\/li>\n?)+/s', '<ul>$0</ul>', (string)$safe);
              return nl2br($safe);
          };
        ?>
        <h3>Data model</h3>
        <div style="padding:12px; border:1px solid #eee; border-radius:6px; background:#fafafa"><?php echo $renderMd($dataModelPath); ?></div>
        <h3 style="margin-top:20px">UI behavior</h3>
        <div style="padding:12px; border:1px solid #eee; border-radius:6px; background:#fafafa"><?php echo $renderMd($uiBehaviorPath); ?></div>
      </section>
    <?php } else { ?>
      <section style="text-align:left; max-width:920px">
        <h2 style="text-align:center">Database overview</h2>
        <?php if (empty($tables)) { ?>
          <p style="text-align:center; color:#666">No user tables found.</p>
        <?php } else { ?>
          <ul>
            <?php foreach ($tables as $t) { ?>
              <li><strong><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></strong></li>
            <?php } ?>
          </ul>

          <?php foreach ($tables as $t) { $rows = $tableSamples[$t] ?? []; ?>
            <h3><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?> (first 10 rows)</h3>
            <?php if (empty($rows)) { ?>
              <p style="color:#666">No rows.</p>
            <?php } else { $cols = array_keys($rows[0]); ?>
              <div style="overflow:auto; border:1px solid #ddd; border-radius:6px">
                <table style="border-collapse:collapse; min-width:600px; width:100%">
                  <thead>
                    <tr>
                      <?php foreach ($cols as $c) { ?>
                        <th style="text-align:left; padding:8px; border-bottom:1px solid #eee; background:#fafafa"><?php echo htmlspecialchars((string)$c, ENT_QUOTES, 'UTF-8'); ?></th>
                      <?php } ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r) { ?>
                      <tr>
                        <?php foreach ($cols as $c) { $val = (string)($r[$c] ?? ''); ?>
                          <td style="padding:8px; border-bottom:1px solid #f3f3f3; vertical-align:top; white-space:pre-wrap; word-break:break-word"><?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?></td>
                        <?php } ?>
                      </tr>
                    <?php } ?>
                  </tbody>
                </table>
              </div>
            <?php } ?>
          <?php } ?>
        <?php } ?>
      </section>
    <?php } ?>
  </main>
</body>
</html>


