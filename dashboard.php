<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/smtp.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.html?error=1');
    exit;
}

$username = htmlspecialchars((string)($_SESSION['username'] ?? 'user'), ENT_QUOTES, 'UTF-8');
$activeTab = 'users';
if (isset($_GET['tab'])) {
    $tab = $_GET['tab'];
    if ($tab === 'crm_organisations' || $tab === 'inbox' || $tab === 'crm' || $tab === 'admin' || $tab === 'users') {
        $activeTab = $tab;
    }
}

// Prepare DB, create crm_organisations table if missing, and handle create action
$tables = [];
$tableSamples = [];
$crm_organisations = [];
$crm_emailsList = [];
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
	// Ensure crm_organisations table exists
	$db->exec('CREATE TABLE IF NOT EXISTS crm_organisations (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		name TEXT NOT NULL,
		homepage_url TEXT NOT NULL,
		logo_path TEXT,
		created_at TEXT NOT NULL
	)');

    // Email-related tables
    $db->exec('CREATE TABLE IF NOT EXISTS crm_email_status (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key TEXT UNIQUE NOT NULL,
        label TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS crm_emails (
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
		FOREIGN KEY(email_id) REFERENCES crm_emails(id)
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
	$cntRow = $db->query('SELECT COUNT(1) AS c FROM crm_email_status')->fetch();
	if ((int)($cntRow['c'] ?? 0) === 0) {
		$seed = $db->prepare('INSERT INTO crm_email_status (key, label) VALUES (:k, :l)');
		foreach ([['new','New'],['read','Read'],['replied','Replied'],['ignore','Ignore'],['marketing','Marketing']] as $s) {
			$seed->execute([':k'=>$s[0], ':l'=>$s[1]]);
		}
	}
    // Load statuses (still available for future use)
    $st = $db->query('SELECT id, key, label FROM crm_email_status ORDER BY id');
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) { $emailStatuses[(int)$row['id']] = $row; }

    // Auto-migrate legacy crm_emails schema to new unique (email, name) schema
    $cols = [];
    $ti = $db->query('PRAGMA table_info(crm_emails)');
    while ($ci = $ti->fetch(PDO::FETCH_ASSOC)) { $cols[] = $ci['name']; }
    if (in_array('message_id', $cols, true) || in_array('from_addr', $cols, true)) {
        $db->beginTransaction();
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS crm_emails_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                name TEXT,
                created_at TEXT NOT NULL
            )');
            // Extract distinct email/name from legacy rows
            $legacy = $db->query('SELECT DISTINCT from_addr FROM crm_emails WHERE from_addr IS NOT NULL');
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
                    $ins = $db->prepare('INSERT OR IGNORE INTO crm_emails_new (email, name, created_at) VALUES (:e,:n,:t)');
                    $ins->execute([':e'=>$emailOnly, ':n'=>$name, ':t'=>(new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                }
            }
            $db->exec('DROP TABLE crm_emails');
            $db->exec('ALTER TABLE crm_emails_new RENAME TO crm_emails');
            $db->commit();
        } catch (Throwable $migrE) {
            $db->rollBack();
        }
    }

	// Handle create channel POST
	if ($activeTab === 'crm_organisations' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
				$ins = $db->prepare('INSERT INTO crm_organisations (name, homepage_url, logo_path, created_at) VALUES (:n, :h, :l, :t)');
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
				$db->prepare('UPDATE crm_emails SET status_id=:s WHERE id=:id')->execute([':s'=>$statusId, ':id'=>$emailId]);
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
				$stTo = $db->prepare('SELECT email, name FROM crm_emails WHERE id = :id');
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
						// Save the sent message to email_responses table
						$db->prepare('INSERT INTO email_responses (email_id, body, sent_via, created_at) VALUES (:e,:b,:v,:t)')
							->execute([':e'=>$emailId, ':b'=>$body, ':v'=>'smtp', ':t'=>(new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
						
						// Update email status to replied if status system exists
						$repliedId = null; 
						foreach ($emailStatuses as $sid=>$s) { 
							if ($s['key']==='replied') { $repliedId = $sid; break; } 
						}
						if ($repliedId) { 
							$db->prepare('UPDATE crm_emails SET status_id=:s WHERE id=:id')->execute([':s'=>$repliedId, ':id'=>$emailId]); 
						}
						
						$flashMessage = 'Email sent successfully to ' . htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');
					} else {
						$flashError = 'SMTP error: ' . $msg;
					}
				} else {
					$flashError = 'SMTP not configured or recipient missing.';
				}
			} else {
				$flashError = 'Please fill in both subject and message.';
			}
		}
		
		// Handle IMAP sync
		if (isset($_POST['sync_imap']) && $_POST['sync_imap'] === '1') {
			// Clear any previous output
			if (ob_get_level()) {
				ob_clean();
			}
			
			// Start output buffering to catch any errors
			ob_start();
			
			// Return JSON response for AJAX
			header('Content-Type: application/json');
			
			try {
				// Check if IMAP extension is available
				if (!extension_loaded('imap')) {
					throw new Exception('IMAP extension is not installed on this server');
				}
				$cfg = [];
				$cfgFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
				if (is_file($cfgFile)) { 
					$cfg = require $cfgFile; 
				}
				
				$imapCfg = $cfg['imap'] ?? [];
				$host = (string)($imapCfg['host'] ?? '');
				$port = (int)($imapCfg['port'] ?? 993);
				$username = (string)($imapCfg['username'] ?? '');
				$password = (string)($imapCfg['password'] ?? '');
				$encryption = (string)($imapCfg['encryption'] ?? 'ssl');
				
				if (empty($host) || empty($username) || empty($password)) {
					throw new Exception('IMAP configuration missing');
				}
				
				// Connect to IMAP
				$connectionString = "{{$host}:{$port}/imap/{$encryption}/novalidate-cert}INBOX";
				$inbox = imap_open($connectionString, $username, $password);
				
				if (!$inbox) {
					$error = imap_last_error();
					throw new Exception("Failed to connect to IMAP server: {$error}. Connection string: {$connectionString}");
				}
				
				// Get message count
				$messageCount = imap_num_msg($inbox);
				$newEmailsCount = 0;
				
				// Get existing message IDs from database
				$existingIds = [];
				$stmt = $db->query('SELECT message_id FROM inbox_incoming');
				while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
					$existingIds[$row['message_id']] = true;
				}
				
				// Process new messages
				for ($i = 1; $i <= $messageCount; $i++) {
					$header = imap_headerinfo($inbox, $i);
					$messageId = $header->message_id ?? '';
					
					// Skip if already exists
					if (isset($existingIds[$messageId])) {
						continue;
					}
					
					// Extract email details
					$from = $header->from[0] ?? null;
					$fromEmail = $from ? $from->mailbox . '@' . $from->host : '';
					$fromName = $from ? ($from->personal ?? '') : '';
					$subject = $header->subject ?? '';
					$date = $header->date ?? '';
					
					// Get email body and attachments
					$body = imap_fetchbody($inbox, $i, 1);
					$structure = imap_fetchstructure($inbox, $i);
					$attachments = [];
					
					// Process attachments
					if (isset($structure->parts) && is_array($structure->parts)) {
						foreach ($structure->parts as $partNum => $part) {
							if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
								$filename = '';
								if (isset($part->dparameters) && is_array($part->dparameters)) {
									foreach ($part->dparameters as $param) {
										if (strtolower($param->attribute) === 'filename') {
											$filename = $param->value;
											break;
										}
									}
								}
								if ($filename) {
									$attachments[] = $filename;
								}
							}
						}
					}
					
					// Save to database
					$stmt = $db->prepare('INSERT INTO inbox_incoming (message_id, from_email, from_name, subject, mail_date, content_plain, attachments, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
					$stmt->execute([
						$messageId,
						$fromEmail,
						$fromName,
						$subject,
						$date,
						$body,
						json_encode($attachments),
						date('Y-m-d H:i:s')
					]);
					
					$newEmailsCount++;
				}
				
				imap_close($inbox);
				
				// Clean any output buffer and send JSON
				ob_clean();
				echo json_encode([
					'success' => true,
					'new_emails' => $newEmailsCount,
					'message' => "Successfully synced {$newEmailsCount} new emails"
				]);
				
			} catch (Exception $e) {
				// Clean any output buffer and send JSON error
				ob_clean();
				echo json_encode([
					'success' => false,
					'error' => $e->getMessage()
				]);
			}
			exit;
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

	// Fetch crm_organisations list
	// Channels table has been removed
	$channels = [];
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

    <?php if ($activeTab === 'crm_organisations') { ?>
      <div class="row">
        <div class="col-lg-8 mx-auto">
          <div class="card">
            <div class="card-header">
              <h5 class="card-title mb-0">Add Channel</h5>
            </div>
            <div class="card-body">
              <?php if ($flashMessage) { ?><div class="alert alert-success"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
              <?php if ($flashError) { ?><div class="alert alert-danger"><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
              <form action="dashboard.php?tab=crm_organisations" method="post" enctype="multipart/form-data">
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
              <?php if (empty($crm_organisations)) { ?>
                <p class="text-muted">No crm_organisations yet.</p>
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
                      <?php foreach ($crm_organisations as $ch) { ?>
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
      <?php include __DIR__ . '/tab_inbox.php'; ?>
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


