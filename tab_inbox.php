<?php
declare(strict_types=1);

// expects $db, $flashMessage, $flashError, $emailStatuses to be available

// Start load time tracking
$pageStartTime = microtime(true);
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h5 class="card-title mb-0">Inbox</h5>
      </div>
      <div class="card-body">
        <!-- INBOX Navigation -->
        <ul class="nav nav-pills mb-4">
          <li class="nav-item">
            <a class="nav-link <?php echo ($_GET['sub'] ?? '') === '' || ($_GET['sub'] ?? '') === 'list' ? 'active' : ''; ?>" href="dashboard.php?tab=inbox&sub=list">List</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo ($_GET['sub'] ?? '') === 'sent' ? 'active' : ''; ?>" href="dashboard.php?tab=inbox&sub=sent">Sent</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo ($_GET['sub'] ?? '') === 'settings' ? 'active' : ''; ?>" href="dashboard.php?tab=inbox&sub=settings">Settings</a>
          </li>
        </ul>
        <?php
        $sub = isset($_GET['sub']) ? (string)$_GET['sub'] : 'list';
        if ($sub === 'list') {
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
            // First, try to get emails from database (fast)
            try {
                $dbEmails = $db->query('SELECT * FROM messages ORDER BY mail_date DESC LIMIT ' . $limit);
                $emails = $dbEmails ? $dbEmails->fetchAll(PDO::FETCH_ASSOC) : [];
                
                // Convert database format to display format
                $emails = array_map(function($email) {
                    return [
                        'index' => $email['id'],
                        'message_id' => $email['message_id'],
                        'from' => $email['from_name'] ? $email['from_name'] . ' <' . $email['from_email'] . '>' : $email['from_email'],
                        'subject' => $email['subject'],
                        'date' => $email['mail_date'],
                    ];
                }, $emails);
                
                // Check if we need to sync with IMAP (only if database is empty or very old)
                $lastSync = $db->query('SELECT MAX(created_at) as last_sync FROM messages')->fetch();
                $needsSync = empty($emails) || !$lastSync || 
                    (time() - strtotime($lastSync['last_sync'])) > 300; // 5 minutes
                
                if ($needsSync) {
                    // Background sync with IMAP (only fetch new emails)
                    $flags = '/imap';
                    if ($encryption === 'ssl' || $encryption === 'tls') { $flags .= '/ssl'; }
                    if ($encryption === 'starttls') { $flags .= '/tls'; }
                    if (!$validateCert) { $flags .= '/novalidate-cert'; }
                    $mailbox = sprintf('{%s:%d%s}INBOX', $host, $port, $flags);
                    $inbox = @imap_open($mailbox, $usernameCfg, $passwordCfg, 0, 1, [
                        'DISABLE_AUTHENTICATOR' => 'gssapi'
                    ]);
                    
                    if ($inbox !== false) {
                        $num = imap_num_msg($inbox);
                        $start = max(1, $num - $limit + 1);
                        
                        // Only process new emails (not in database)
                        for ($i = $num; $i >= $start; $i--) {
                            $overview = imap_headerinfo($inbox, $i);
                            $msgId = isset($overview->message_id) ? (string)$overview->message_id : '';
                            if ($msgId === '') {
                                $fallback = ((string)($overview->fromaddress ?? '')) . '|' . ((string)($overview->subject ?? '')) . '|' . ((string)($overview->date ?? ''));
                                $msgId = 'fallback:' . sha1($fallback);
                            }
                            
                            // Check if email already exists in database
                            $exists = $db->prepare('SELECT id FROM messages WHERE message_id = :msgId');
                            $exists->execute([':msgId' => $msgId]);
                            if ($exists->fetch()) {
                                continue; // Skip if already in database
                            }
                            
                            $subj = isset($overview->subject) ? (string)imap_utf8($overview->subject) : '';
                            $from = isset($overview->fromaddress) ? (string)$overview->fromaddress : '';
                            $dateStr = isset($overview->date) ? (string)$overview->date : '';
                            
                            // Insert new email into database
                            try {
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
                        
                        // Refresh emails from database after sync
                        $dbEmails = $db->query('SELECT * FROM messages ORDER BY mail_date DESC LIMIT ' . $limit);
                        $emails = $dbEmails ? $dbEmails->fetchAll(PDO::FETCH_ASSOC) : [];
                        $emails = array_map(function($email) {
                            return [
                                'index' => $email['id'],
                                'message_id' => $email['message_id'],
                                'from' => $email['from_name'] ? $email['from_name'] . ' <' . $email['from_email'] . '>' : $email['from_email'],
                                'subject' => $email['subject'],
                                'date' => $email['mail_date'],
                            ];
                        }, $emails);
                    } else {
                        $imapError = imap_last_error() ?: 'Unable to connect to mailbox.';
                    }
                }
            } catch (Throwable $e) {
                $imapError = 'Database error: ' . $e->getMessage();
            }
        } else {
            echo '<div class="alert alert-warning">Missing IMAP credentials. Create config.local.php and fill in your IMAP/SMTP details.</div>';
        }
      }
      ?>
      <?php if ($flashMessage) { ?><div class="alert alert-success"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
      <?php if ($flashError) { ?><div class="alert alert-danger"><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
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
                <th>Organization</th>
                <th>Load Time</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $viewIdx = isset($_GET['view_idx']) ? (int)$_GET['view_idx'] : 0;
              foreach ($emails as $em) {
                    // use row index for view - navigate to message page
                    $viewUrl = 'dashboard.php?tab=inbox&sub=message&id=' . urlencode((string)$em['index']);
                    
                    // Check for attachments in this email
                    $attachmentCount = 0;
                    if ($host && $usernameCfg && $passwordCfg) {
                        try {
                            $flags = '/imap';
                            if ($encryption === 'ssl' || $encryption === 'tls') { $flags .= '/ssl'; }
                            if ($encryption === 'starttls') { $flags .= '/tls'; }
                            if (!$validateCert) { $flags .= '/novalidate-cert'; }
                            $mailbox = sprintf('{%s:%d%s}INBOX', $host, $port, $flags);
                            $inboxCheck = @imap_open($mailbox, $usernameCfg, $passwordCfg, 0, 1, ['DISABLE_AUTHENTICATOR' => 'gssapi']);
                            if ($inboxCheck) {
                                $struct = @imap_fetchstructure($inboxCheck, (int)$em['index']);
                                if ($struct && !empty($struct->parts)) {
                                    foreach ($struct->parts as $p) {
                                        $isAttachment = false;
                                        if (!empty($p->dparameters)) {
                                            foreach ($p->dparameters as $dp) {
                                                if (strtolower($dp->attribute) == 'filename') { $isAttachment = true; break; }
                                            }
                                        }
                                        if (!$isAttachment && !empty($p->parameters)) {
                                            foreach ($p->parameters as $pp) {
                                                if (strtolower($pp->attribute) == 'name') { $isAttachment = true; break; }
                                            }
                                        }
                                        if ($isAttachment) { $attachmentCount++; }
                                    }
                                }
                                @imap_close($inboxCheck);
                            }
                        } catch (Throwable $ign) {}
                    }
                 ?>
                <?php
                  // Get company and people info for this email
                  $companyLabel = '';
                  $peopleLabels = '';
                  $fromEmail = (string)$em['from'];
                  $domain = '';
                  $fromName = '';
                  
                  // Extract email address and name
                  if (preg_match('/<([^>]+)>/', $fromEmail, $mFrom)) {
                    $fromEmail = strtolower(trim($mFrom[1]));
                    $fromName = trim(preg_replace('/<[^>]+>/', '', $fromEmail));
                  } else {
                    $fromEmail = strtolower(trim($fromEmail));
                    $fromName = $fromEmail;
                  }
                  
                  // Extract domain from email
                  if (strpos($fromEmail, '@') !== false) {
                    $domain = strtolower(trim(substr($fromEmail, strpos($fromEmail, '@') + 1)));
                  }
                  
                  // Check if domain exists in companies table
                  if ($domain !== '') {
                    try {
                      $compStmt = $db->prepare('SELECT id, name FROM companies WHERE domain = :domain');
                      $compStmt->execute([':domain' => $domain]);
                      $company = $compStmt->fetch();
                      if ($company) {
                        $companyId = (int)$company['id'];
                        $companyName = (string)$company['name'];
                        $companyLabel = '<a href="dashboard.php?tab=crm&sub=organisations" class="badge bg-success text-decoration-none" title="Company: ' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '">🏢 ' . htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') . '</a>';
                      }
                    } catch (Throwable $ign) {}
                  }
                  
                  // Check if people exist in database by name
                  if ($fromName !== '') {
                    try {
                      $peopleStmt = $db->prepare('SELECT id, name FROM people WHERE name LIKE :name');
                      $peopleStmt->execute([':name' => '%' . $fromName . '%']);
                      $people = $peopleStmt->fetchAll();
                      if ($people) {
                        foreach ($people as $person) {
                          $personId = (int)$person['id'];
                          $personName = (string)$person['name'];
                          $peopleLabels .= '<a href="dashboard.php?tab=crm&sub=people" class="badge bg-primary ms-1 text-decoration-none" title="Person: ' . htmlspecialchars($personName, ENT_QUOTES, 'UTF-8') . '">👤 ' . htmlspecialchars($personName, ENT_QUOTES, 'UTF-8') . '</a>';
                        }
                      }
                    } catch (Throwable $ign) {}
                  }
                  
                  // Also check if people exist by email address
                  if ($fromEmail !== '') {
                    try {
                      $emailPeopleStmt = $db->prepare('SELECT p.id, p.name FROM people p LEFT JOIN emails e ON p.name = e.name WHERE e.email = :email');
                      $emailPeopleStmt->execute([':email' => $fromEmail]);
                      $emailPeople = $emailPeopleStmt->fetchAll();
                      if ($emailPeople) {
                        foreach ($emailPeople as $person) {
                          $personId = (int)$person['id'];
                          $personName = (string)$person['name'];
                          // Check if this person is already in peopleLabels to avoid duplicates
                          if (strpos($peopleLabels, $personName) === false) {
                            $peopleLabels .= '<a href="dashboard.php?tab=crm&sub=people" class="badge bg-primary ms-1 text-decoration-none" title="Person: ' . htmlspecialchars($personName, ENT_QUOTES, 'UTF-8') . '">👤 ' . htmlspecialchars($personName, ENT_QUOTES, 'UTF-8') . '</a>';
                          }
                        }
                      }
                    } catch (Throwable $ign) {}
                  }
                ?>
                <tr style="cursor: pointer;" onclick="window.location.href='<?php echo $viewUrl; ?>'">
                  <td><?php echo (int)$em['index']; ?></td>
                  <td>
                    <?php echo htmlspecialchars((string)$em['from'], ENT_QUOTES, 'UTF-8'); ?>
                  </td>
                  <td>
                    <?php echo htmlspecialchars((string)$em['subject'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($attachmentCount > 0) { ?>
                      <span class="badge bg-info ms-2" title="<?php echo $attachmentCount; ?> attachment(s)">
                        📎 <?php echo $attachmentCount; ?>
                      </span>
                    <?php } ?>
                  </td>
                  <td><?php echo htmlspecialchars((string)$em['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td>
                    <?php if ($companyLabel) { ?>
                      <div style="margin-bottom: 4px;"><?php echo $companyLabel; ?></div>
                    <?php } ?>
                    <?php if ($peopleLabels) { ?>
                      <div><?php echo $peopleLabels; ?></div>
                    <?php } ?>
                    <?php if (!$companyLabel && !$peopleLabels) { ?>
                      <span style="color: #999; font-size: 12px;">No matches</span>
                    <?php } ?>
                  </td>
                  <td>
                    <?php 
                      $currentTime = microtime(true);
                      $loadTime = round(($currentTime - $pageStartTime) * 1000, 2);
                      echo '<span style="font-family: monospace; font-size: 11px; color: #666;">' . $loadTime . 'ms</span>';
                    ?>
                  </td>
                </tr>
              <?php } ?>
            </tbody>
          </table>
        </div>
      <?php } else if ($imapSupported && !$imapError) { ?>
        <p class="text-muted">No emails found.</p>
      <?php } ?>
      <?php } else if ($sub === 'sent') { ?>
        <h6>Sent Emails</h6>
        <p class="text-muted">Sent emails functionality coming soon.</p>
      <?php } else if ($sub === 'settings') { ?>
        <h6>INBOX Settings</h6>
        <div class="row">
          <div class="col-md-6">
            <div class="card">
              <div class="card-header">
                <h6 class="card-title mb-0">IMAP Configuration</h6>
              </div>
              <div class="card-body">
                <p class="text-muted">Configure your IMAP settings for email retrieval.</p>
                <a href="dashboard.php?tab=admin&sub=database" class="btn btn-outline-primary btn-sm">View Configuration</a>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card">
              <div class="card-header">
                <h6 class="card-title mb-0">Email Filters</h6>
              </div>
              <div class="card-body">
                <p class="text-muted">Set up email filtering and organization rules.</p>
                <button class="btn btn-outline-secondary btn-sm" disabled>Coming Soon</button>
              </div>
            </div>
          </div>
        </div>
      <?php } else if ($sub === 'message') { ?>
        <?php include __DIR__ . '/tab_inbox_message.php'; ?>
      <?php } ?>
      </div>
    </div>
  </div>
</div>
