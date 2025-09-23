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
    if ($tab === 'channels' || $tab === 'inbox' || $tab === 'crm' || $tab === 'admin' || $tab === 'users') {
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
  <!-- Bootstrap CSS -->
  <link href="css/bootstrap.min.css" rel="stylesheet">
  <!-- Custom CSS -->
  <link href="css/custom.css" rel="stylesheet">
</head>
<body>
  <div class="dashboard-container">
    <!-- Header -->
    <div class="row mb-4">
      <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h1 class="h3 mb-1">Welcome, <?php echo $username; ?>!</h1>
            <p class="text-muted mb-0">You are logged in.</p>
          </div>
          <div>
            <a href="logout.php" class="btn btn-outline-secondary">Log out</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Navigation -->
    <div class="row mb-4">
      <div class="col-12">
        <ul class="nav nav-pills justify-content-center">
          <li class="nav-item">
            <a class="nav-link <?php echo $activeTab==='inbox'?'active':''; ?>" href="dashboard.php?tab=inbox">INBOX</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo $activeTab==='crm'?'active':''; ?>" href="dashboard.php?tab=crm">CRM</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo $activeTab==='admin'?'active':''; ?>" href="dashboard.php?tab=admin">ADMIN</a>
          </li>
        </ul>
      </div>
    </div>

    <?php if ($activeTab === 'channels') { ?>
      <div class="row">
        <div class="col-lg-8 mx-auto">
          <div class="card">
            <div class="card-header">
              <h5 class="card-title mb-0">Add Channel</h5>
            </div>
            <div class="card-body">
              <?php if ($flashMessage) { ?><div class="alert alert-success"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
              <?php if ($flashError) { ?><div class="alert alert-danger"><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
              <form action="dashboard.php?tab=channels" method="post" enctype="multipart/form-data">
                <div class="mb-3">
                  <label for="channel_name" class="form-label">Channel name</label>
                  <input id="channel_name" name="channel_name" type="text" class="form-control" required />
                </div>
                <div class="mb-3">
                  <label for="channel_homepage" class="form-label">Channel homepage</label>
                  <input id="channel_homepage" name="channel_homepage" type="url" class="form-control" placeholder="https://example.com" required />
                </div>
                <div class="mb-3">
                  <label for="channel_logo" class="form-label">Upload logo (png/jpg/gif/webp)</label>
                  <input id="channel_logo" name="channel_logo" type="file" class="form-control" accept=".png,.jpg,.jpeg,.gif,.webp" />
                </div>
                <button type="submit" class="btn btn-primary">Create channel</button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <div class="row mt-4">
        <div class="col-12">
          <div class="card">
            <div class="card-header">
              <h5 class="card-title mb-0">Channels</h5>
            </div>
            <div class="card-body">
              <?php if (empty($channels)) { ?>
                <p class="text-muted">No channels yet.</p>
              <?php } else { ?>
                <div class="table-responsive">
                  <table class="table table-striped">
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
                          <td><a href="<?php echo htmlspecialchars((string)$ch['homepage_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Visit</a></td>
                          <td>
                            <?php if (!empty($ch['logo_path'])) { ?>
                              <img src="<?php echo htmlspecialchars((string)$ch['logo_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="logo" class="channel-logo" />
                            <?php } else { echo '<span class="text-muted">-</span>'; } ?>
                          </td>
                          <td><?php echo htmlspecialchars((string)$ch['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                      <?php } ?>
                    </tbody>
                  </table>
                </div>
              <?php } ?>
            </div>
          </div>
        </div>
      </div>
    <?php } else if ($activeTab === 'inbox') { ?>
      <div class="row">
        <div class="col-12">
          <div class="card">
            <div class="card-header">
              <h5 class="card-title mb-0">Inbox</h5>
            </div>
            <div class="card-body">
        <?php
        $imapSupported = function_exists('imap_open');
        $emails = [];
        $imapError = '';
        if (!$imapSupported) {
            echo '<div class="alert alert-danger">PHP IMAP extension is not available on this server.</div>';
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
                echo '<div class="alert alert-warning">Missing IMAP credentials. Copy config.example.php to config.local.php and fill in your details.</div>';
            }
        }
        ?>
        <?php if ($imapError) { ?><div class="alert alert-danger"><?php echo htmlspecialchars($imapError, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        <?php if (!empty($emails)) { ?>
          <div class="table-responsive">
            <table class="table table-hover email-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>From</th>
                  <th>Subject</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($emails as $em) {
                      // use row index for view
                      $viewUrl = 'dashboard.php?tab=inbox&view_idx=' . urlencode((string)$em['index']);
                   ?>
                  <tr style="cursor: pointer;" onclick="window.location.href='<?php echo $viewUrl; ?>'">
                    <td><?php echo (int)$em['index']; ?></td>
                    <td><?php echo htmlspecialchars((string)$em['from'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$em['subject'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$em['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
          <?php
            // Detail panel for a selected message
            $viewIdx = isset($_GET['view_idx']) ? (int)$_GET['view_idx'] : 0;
            if ($viewIdx > 0) {
                $detail = null; $attachments = [];
                // reopen IMAP and fetch by index
                if ($host && $usernameCfg && $passwordCfg) {
                    $flags = '/imap';
                    if ($encryption === 'ssl' || $encryption === 'tls') { $flags .= '/ssl'; }
                    if ($encryption === 'starttls') { $flags .= '/tls'; }
                    if (!$validateCert) { $flags .= '/novalidate-cert'; }
                    $mailbox = sprintf('{%s:%d%s}INBOX', $host, $port, $flags);
                    $inbox2 = @imap_open($mailbox, $usernameCfg, $passwordCfg, 0, 1, ['DISABLE_AUTHENTICATOR' => 'gssapi']);
                    if ($inbox2) {
                        $msgNo = $viewIdx;
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
                        @imap_close($inbox2);
                    }
                }
          ?>
          <?php if (!empty($detail)) { ?>
            <div class="message-detail mt-4 p-3">
              <h5 class="mb-3">Message Details</h5>
              <div class="row mb-3">
                <div class="col-md-4"><strong>From:</strong> <?php echo htmlspecialchars($detail['from'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="col-md-8"><strong>Subject:</strong> <?php echo htmlspecialchars($detail['subject'], ENT_QUOTES, 'UTF-8'); ?></div>
              </div>
              <div class="mb-3"><strong>Date:</strong> <?php echo htmlspecialchars($detail['date'], ENT_QUOTES, 'UTF-8'); ?></div>
              <div class="mb-3">
                <strong>Content:</strong>
                <div class="message-content p-3 mt-2">
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
              <div class="mb-3">
                <strong>Attachments:</strong>
                <?php if (empty($detail['attachments'])) { echo '<span class="text-muted"> none</span>'; } else { ?>
                  <ul class="list-unstyled">
                    <?php foreach ($detail['attachments'] as $att) { ?>
                      <li><span class="badge bg-secondary"><?php echo htmlspecialchars((string)$att['filename'], ENT_QUOTES, 'UTF-8'); ?></span></li>
                    <?php } ?>
                  </ul>
                <?php } ?>
              </div>
              <div class="mt-3">
                <div class="accordion" id="replyAccordion">
                  <div class="accordion-item">
                    <h2 class="accordion-header">
                      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#replyCollapse" aria-expanded="false" aria-controls="replyCollapse">
                        Reply
                      </button>
                    </h2>
                    <div id="replyCollapse" class="accordion-collapse collapse" data-bs-parent="#replyAccordion">
                      <div class="accordion-body">
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
                          <form action="dashboard.php?tab=inbox&view_idx=<?php echo urlencode((string)$viewIdx); ?>" method="post">
                            <input type="hidden" name="action" value="reply" />
                            <input type="hidden" name="email_id" value="<?php echo (int)$contactId; ?>" />
                            <div class="mb-3">
                              <input type="text" name="subject" class="form-control" placeholder="Subject" required />
                            </div>
                            <div class="mb-3">
                              <textarea name="body" rows="6" class="form-control" placeholder="Your reply..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Send & Save</button>
                          </form>
                        <?php } else { echo '<p class="text-muted">No contact email found to reply.</p>'; } ?>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php } ?>
          <?php } // end detail panel ?>
        <?php } else if ($imapSupported && !$imapError) { ?>
          <p class="text-muted">No emails found.</p>
        <?php } ?>
            </div>
          </div>
        </div>
      </div>
    <?php } else if ($activeTab === 'crm') { ?>
      <?php include __DIR__ . '/tab_crm_email.php'; ?>
    <?php } else if ($activeTab === 'admin') { ?>
      <?php include __DIR__ . '/tab_admin.php'; ?>
    <?php } else if ($activeTab === 'site') { ?>
      <div class="row">
        <div class="col-12">
          <div class="card">
            <div class="card-header">
              <h5 class="card-title mb-0">Site Specification</h5>
            </div>
            <div class="card-body">
              <?php
                $dataModelPath = __DIR__ . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'data-model.md';
                $uiBehaviorPath = __DIR__ . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'ui-behavior.md';
                $renderMd = function(string $path): string {
                    if (!is_file($path)) { return '<p class="text-muted">Missing: ' . htmlspecialchars(basename($path), ENT_QUOTES, 'UTF-8') . '</p>'; }
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
              <h5>Data model</h5>
              <div class="p-3 border rounded bg-light"><?php echo $renderMd($dataModelPath); ?></div>
              <h5 class="mt-4">UI behavior</h5>
              <div class="p-3 border rounded bg-light"><?php echo $renderMd($uiBehaviorPath); ?></div>
            </div>
          </div>
        </div>
      </div>
    <?php } else { ?>
      <div class="row">
        <div class="col-12">
          <div class="card">
            <div class="card-header">
              <h5 class="card-title mb-0 text-center">Welcome to Dashboard</h5>
            </div>
            <div class="card-body text-center">
              <p class="text-muted">Select a tab above to get started.</p>
            </div>
          </div>
        </div>
      </div>
    <?php } ?>
  </div>

  <!-- Bootstrap JS -->
  <script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>


