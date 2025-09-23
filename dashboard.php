<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.html?error=1');
    exit;
}

$username = htmlspecialchars((string)($_SESSION['username'] ?? 'user'), ENT_QUOTES, 'UTF-8');
$activeTab = 'users';
if (isset($_GET['tab'])) {
    $tab = $_GET['tab'];
    if ($tab === 'channels' || $tab === 'inbox' || $tab === 'users') {
        $activeTab = $tab;
    }
}

// Prepare DB, create channels table if missing, and handle create action
$tables = [];
$tableSamples = [];
$channels = [];
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
                        $emails[] = [
                            'index' => $i,
                            'from' => isset($overview->fromaddress) ? (string)$overview->fromaddress : '',
                            'subject' => isset($overview->subject) ? (string)imap_utf8($overview->subject) : '',
                            'date' => isset($overview->date) ? (string)$overview->date : '',
                        ];
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
                <?php foreach ($emails as $em) { ?>
                  <tr>
                    <td><?php echo (int)$em['index']; ?></td>
                    <td><?php echo htmlspecialchars((string)$em['from'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$em['subject'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$em['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        <?php } else if ($imapSupported && !$imapError) { ?>
          <p style="color:#666">No emails found.</p>
        <?php } ?>
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


