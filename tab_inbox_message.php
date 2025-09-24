<?php
declare(strict_types=1);

// expects $db, $flashMessage, $flashError, $emailStatuses to be available

// Get message ID from URL
$messageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($messageId <= 0) {
    echo '<div class="alert alert-danger">Invalid message ID.</div>';
    return;
}

// Get next and previous message IDs for navigation
$nextMessageId = null;
$prevMessageId = null;
try {
    // Get all message IDs ordered by ID for navigation
    $allMessagesStmt = $db->query('SELECT id FROM inbox_incoming ORDER BY id ASC');
    $allMessageIds = $allMessagesStmt ? $allMessagesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    
    $currentIndex = array_search($messageId, $allMessageIds);
    if ($currentIndex !== false) {
        // Get previous message ID
        if ($currentIndex > 0) {
            $prevMessageId = $allMessageIds[$currentIndex - 1];
        }
        // Get next message ID
        if ($currentIndex < count($allMessageIds) - 1) {
            $nextMessageId = $allMessageIds[$currentIndex + 1];
        }
    }
} catch (Throwable $e) {
    // Ignore errors, navigation buttons will be disabled
}

// Fetch message from database
$message = null;
try {
    $stmt = $db->prepare('SELECT * FROM inbox_incoming WHERE id = :id');
    $stmt->execute([':id' => $messageId]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo '<div class="alert alert-danger">Error fetching message: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    return;
}

if (!$message) {
    echo '<div class="alert alert-warning">Message not found.</div>';
    return;
}

// Handle reply submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reply') {
    $recipient = (string)($_POST['recipient'] ?? '');
    $subject = (string)($_POST['subject'] ?? '');
    $body = (string)($_POST['body'] ?? '');

    if ($recipient && $subject && $body) {
        require_once __DIR__ . '/inc/smtp.php';
        
        // Get SMTP config
        $cfg = [];
        $cfgFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
        echo '<div class="alert alert-info">Debug: Config file path: ' . htmlspecialchars($cfgFile, ENT_QUOTES, 'UTF-8') . '</div>';
        echo '<div class="alert alert-info">Debug: Config file exists: ' . (is_file($cfgFile) ? 'YES' : 'NO') . '</div>';
        
        if (is_file($cfgFile)) {
            $cfg = require $cfgFile;
            echo '<div class="alert alert-info">Debug: Loaded config from file</div>';
        } else {
            // Use hardcoded values (try port 25 without encryption)
            $cfg = [
                'smtp' => [
                    'host' => 'smtp.zone.eu',
                    'port' => 25,
                    'username' => 'info@teenus.ee',
                    'password' => 'Yyd12321df42Xgus9WHT8xhic',
                    'encryption' => 'none'
                ]
            ];
            echo '<div class="alert alert-info">Debug: Using hardcoded config</div>';
        }
        
        echo '<div class="alert alert-info">Debug: Full config: ' . htmlspecialchars(print_r($cfg, true), ENT_QUOTES, 'UTF-8') . '</div>';
        
        // Test basic connectivity
        $smtpCfg = $cfg['smtp'] ?? [];
        $smtpHost = (string)($smtpCfg['host'] ?? '');
        $smtpPort = (int)($smtpCfg['port'] ?? 587);
        
        echo '<div class="alert alert-info">Debug: Testing connectivity to ' . htmlspecialchars($smtpHost, ENT_QUOTES, 'UTF-8') . ':' . $smtpPort . '</div>';
        
        $testConnection = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 5);
        if ($testConnection) {
            echo '<div class="alert alert-success">Debug: Basic connectivity test PASSED</div>';
            fclose($testConnection);
        } else {
            echo '<div class="alert alert-warning">Debug: Basic connectivity test FAILED: ' . htmlspecialchars($errstr, ENT_QUOTES, 'UTF-8') . ' (' . $errno . ')</div>';
        }
        
        $smtpCfg = $cfg['smtp'] ?? [];
        $smtpHost = (string)($smtpCfg['host'] ?? '');
        $smtpPort = (int)($smtpCfg['port'] ?? 587);
        $smtpEncryption = strtolower((string)($smtpCfg['encryption'] ?? 'tls'));
        $smtpUsername = (string)($smtpCfg['username'] ?? '');
        $smtpPassword = (string)($smtpCfg['password'] ?? '');

        if ($smtpHost && $smtpUsername && $smtpPassword) {
            try {
                echo '<div class="alert alert-info">Debug: Starting email send...</div>';
                echo '<div class="alert alert-info">Debug: SMTP Host: ' . htmlspecialchars($smtpHost, ENT_QUOTES, 'UTF-8') . '</div>';
                echo '<div class="alert alert-info">Debug: SMTP Port: ' . $smtpPort . '</div>';
                echo '<div class="alert alert-info">Debug: SMTP Encryption: ' . htmlspecialchars($smtpEncryption, ENT_QUOTES, 'UTF-8') . '</div>';
                echo '<div class="alert alert-info">Debug: SMTP Username: ' . htmlspecialchars($smtpUsername, ENT_QUOTES, 'UTF-8') . '</div>';
                echo '<div class="alert alert-info">Debug: Recipient: ' . htmlspecialchars($recipient, ENT_QUOTES, 'UTF-8') . '</div>';
                echo '<div class="alert alert-info">Debug: Subject: ' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</div>';
                
                $result = sendSmtpEmail($smtpHost, $smtpPort, $smtpEncryption, $smtpUsername, $smtpPassword, $smtpUsername, 'System', $recipient, 'Recipient', $subject, $body);
                
                echo '<div class="alert alert-info">Debug: SMTP Result: ' . htmlspecialchars(print_r($result, true), ENT_QUOTES, 'UTF-8') . '</div>';
                
                if ($result[0] === true) {
                    $flashMessage = 'Email replied successfully!';
                    
                    // Log response in inbox_sent table
                    $stmt = $db->prepare('INSERT INTO inbox_sent (email_id, subject, body, sent_at, created_at) VALUES (:email_id, :subject, :body, :sent_at, :created_at)');
                    $stmt->execute([
                        ':email_id' => $messageId,
                        ':subject' => $subject,
                        ':body' => $body,
                        ':sent_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                        ':created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                    ]);
                    echo '<div class="alert alert-success">Debug: Email logged to database successfully</div>';
                } else {
                    $flashError = 'SMTP Error: ' . $result[1];
                    echo '<div class="alert alert-danger">Debug: SMTP failed: ' . htmlspecialchars($result[1], ENT_QUOTES, 'UTF-8') . '</div>';
                }
            } catch (Throwable $e) {
                $flashError = 'Error sending reply: ' . $e->getMessage();
                echo '<div class="alert alert-danger">Debug: Exception: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
            }
        } else {
            $flashError = 'SMTP credentials not configured. Please check config.local.php';
        }
    } else {
        $flashError = 'Recipient, subject, and body are required to send a reply.';
    }
}

// Fetch email content - database first, then IMAP as fallback
$emailContent = null;
$attachments = [];

// First, try to get content from database
$contentPlain = (string)($message['content_plain'] ?? '');
$contentHtml = (string)($message['content_html'] ?? '');
$attachmentsList = (string)($message['attachments'] ?? '');
$fullHeaders = (string)($message['full_headers'] ?? '');

if ($contentPlain || $contentHtml) {
    // Content is available in database
    $emailContent = [
        'body' => $contentHtml ?: $contentPlain,
        'header' => $fullHeaders,
        'is_html' => !empty($contentHtml),
        'source' => 'database'
    ];
    
    // Parse attachments from database
    if ($attachmentsList) {
        $attachmentNames = explode(',', $attachmentsList);
        foreach ($attachmentNames as $filename) {
            $filename = trim($filename);
            if ($filename) {
                $attachments[] = [
                    'filename' => $filename,
                    'source' => 'database'
                ];
            }
        }
    }
} else {
    // Fallback to IMAP if content not in database
    $imapSupported = function_exists('imap_open');
    
    if ($imapSupported) {
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

        if ($host && $usernameCfg && $passwordCfg) {
            try {
                $flags = '/imap';
                if ($encryption === 'ssl' || $encryption === 'tls') { $flags .= '/ssl'; }
                if ($encryption === 'starttls') { $flags .= '/tls'; }
                if (!$validateCert) { $flags .= '/novalidate-cert'; }
                $mailbox = sprintf('{%s:%d%s}INBOX', $host, $port, $flags);
                
                $inbox = @imap_open($mailbox, $usernameCfg, $passwordCfg, 0, 1, [
                    'DISABLE_AUTHENTICATOR' => 'gssapi'
                ]);
                
                if ($inbox) {
                    // Find message by message_id
                    $messageIdToFetch = $message['message_id'];
                    $messageUid = imap_search($inbox, 'HEADER Message-ID "' . $messageIdToFetch . '"', SE_UID);
                    
                    if ($messageUid) {
                        $messageUid = reset($messageUid); // Get the first UID
                        $fullHeader = imap_fetchheader($inbox, $messageUid, FT_UID);
                        $body = imap_fetchbody($inbox, $messageUid, 1, FT_UID | FT_PEEK); // Fetch plain text part
                        if (empty(trim($body))) {
                            $body = imap_fetchbody($inbox, $messageUid, 2, FT_UID | FT_PEEK); // Try HTML part
                        }
                        $body = imap_qprint(imap_base64($body)); // Decode if quoted-printable or base64
                        
                        $emailContent = [
                            'body' => $body,
                            'header' => $fullHeader,
                            'source' => 'imap'
                        ];

                        // Fetch attachments
                        $structure = imap_fetchstructure($inbox, $messageUid, FT_UID);
                        if (isset($structure->parts) && count($structure->parts)) {
                            for ($i = 0; $i < count($structure->parts); $i++) {
                                if (isset($structure->parts[$i]->disposition) && in_array(strtolower($structure->parts[$i]->disposition), ['attachment', 'inline'])) {
                                    $filename = $structure->parts[$i]->dparameters[0]->value ?? 'attachment';
                                    $attachmentContent = imap_fetchbody($inbox, $messageUid, $i + 1, FT_UID | FT_PEEK);
                                    $attachmentContent = imap_base64($attachmentContent); // Assuming base64 for attachments
                                    
                                    $attachments[] = [
                                        'filename' => $filename,
                                        'content' => base64_encode($attachmentContent), // Encode for download link
                                        'mime_type' => $structure->parts[$i]->subtype,
                                        'source' => 'imap'
                                    ];
                                }
                            }
                        }
                    }
                    @imap_close($inbox);
                }
            } catch (Throwable $e) {
                // IMAP fetch failed, continue without content
            }
        }
    }
}
?>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Email Message</h5>
        <div class="btn-group" role="group">
          <?php if ($prevMessageId) { ?>
            <a href="dashboard.php?tab=inbox&sub=message&id=<?php echo (int)$prevMessageId; ?>" class="btn btn-outline-primary btn-sm">
              ← Previous
            </a>
          <?php } else { ?>
            <button class="btn btn-outline-secondary btn-sm" disabled>← Previous</button>
          <?php } ?>
          
          <a href="dashboard.php?tab=inbox&sub=list" class="btn btn-secondary btn-sm">
            Back to Inbox
          </a>
          
          <?php if ($nextMessageId) { ?>
            <a href="dashboard.php?tab=inbox&sub=message&id=<?php echo (int)$nextMessageId; ?>" class="btn btn-outline-primary btn-sm">
              Next →
            </a>
          <?php } else { ?>
            <button class="btn btn-outline-secondary btn-sm" disabled>Next →</button>
          <?php } ?>
        </div>
      </div>
      <div class="card-body">
        <?php if ($flashMessage) { ?>
          <div class="alert alert-success"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php } ?>
        <?php if ($flashError) { ?>
          <div class="alert alert-danger"><?php echo htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php } ?>

        <!-- Message Information -->
        <div class="row mb-4">
          <div class="col-md-8">
            <h4><?php echo htmlspecialchars((string)$message['subject'], ENT_QUOTES, 'UTF-8'); ?></h4>
            <div class="mb-3">
              <strong>From:</strong> <?php echo htmlspecialchars((string)$message['from_name'], ENT_QUOTES, 'UTF-8'); ?> 
              &lt;<?php echo htmlspecialchars((string)$message['from_email'], ENT_QUOTES, 'UTF-8'); ?>&gt;
            </div>
            <div class="mb-3">
              <strong>Date:</strong> <?php echo htmlspecialchars((string)$message['mail_date'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div class="mb-3">
              <strong>Message ID:</strong> 
              <code style="font-size: 12px;"><?php echo htmlspecialchars((string)$message['message_id'], ENT_QUOTES, 'UTF-8'); ?></code>
            </div>
            <?php if (!empty($message['snippet'])) { ?>
              <div class="mb-3">
                <strong>Snippet:</strong> <?php echo htmlspecialchars((string)$message['snippet'], ENT_QUOTES, 'UTF-8'); ?>
              </div>
            <?php } ?>
            
            <!-- Organization and People Matches -->
            <?php
              // Get company and people info for this email
              $companyLabel = '';
              $peopleLabels = '';
              $fromEmail = (string)$message['from_email'];
              $domain = '';
              $fromName = (string)$message['from_name'];
              
              // Extract domain from email
              if (strpos($fromEmail, '@') !== false) {
                $domain = strtolower(trim(substr($fromEmail, strpos($fromEmail, '@') + 1)));
              }
              
              // Check if domain exists in companies table
              if ($domain !== '') {
                try {
                  $compStmt = $db->prepare('SELECT id, name FROM crm_organisations WHERE domain = :domain');
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
                  $peopleStmt = $db->prepare('SELECT id, name FROM crm_people WHERE name LIKE :name');
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
                  $emailPeopleStmt = $db->prepare('SELECT p.id, p.name FROM crm_people p LEFT JOIN crm_emails e ON p.name = e.name WHERE e.email = :email');
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
            
            <?php if ($companyLabel || $peopleLabels) { ?>
              <div class="mb-3">
                <strong>CRM Matches:</strong><br>
                <?php if ($companyLabel) { ?>
                  <div style="margin-bottom: 4px;"><?php echo $companyLabel; ?></div>
                <?php } ?>
                <?php if ($peopleLabels) { ?>
                  <div><?php echo $peopleLabels; ?></div>
                <?php } ?>
              </div>
            <?php } ?>
          </div>
          <div class="col-md-4">
            <div class="card bg-light">
              <div class="card-body">
                <h6 class="card-title">Message Details</h6>
                <p class="card-text">
                  <strong>Database ID:</strong> <?php echo (int)$message['id']; ?><br>
                  <strong>Created:</strong> <?php echo htmlspecialchars((string)$message['created_at'], ENT_QUOTES, 'UTF-8'); ?><br>
                  <strong>Data Source:</strong> 
                  <span class="badge bg-info">Database</span> Basic info (headers, metadata)<br>
                  <span class="badge bg-warning">IMAP</span> Full content & attachments
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Email Content -->
        <?php if ($emailContent && !empty($emailContent['body'])) { ?>
          <div class="mb-4">
            <h5>Email Content 
              <span class="badge bg-<?php echo ($emailContent['source'] ?? '') === 'database' ? 'success' : 'warning'; ?> ms-2">
                <?php echo ($emailContent['source'] ?? '') === 'database' ? 'Database' : 'IMAP'; ?>
              </span>
            </h5>
            <div class="message-content p-3" style="background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; max-height: 500px; overflow-y: auto;">
              <?php 
                if (!empty($emailContent['is_html'])) {
                  // Display HTML content safely
                  echo $emailContent['body'];
                } else {
                  // Display plain text content
                  echo nl2br(htmlspecialchars($emailContent['body'], ENT_QUOTES, 'UTF-8'));
                }
              ?>
            </div>
          </div>
        <?php } else { ?>
          <div class="mb-4">
            <h5>Email Content</h5>
            <div class="alert alert-info">
              <i class="fas fa-info-circle"></i> Full email content not available. This may be due to IMAP configuration issues or the email being moved/deleted from the server.
            </div>
          </div>
        <?php } ?>

        <!-- Attachments -->
        <?php if (!empty($attachments)) { ?>
          <div class="mb-4">
            <h5>Attachments</h5>
            <div class="list-group">
              <?php foreach ($attachments as $attachment) { ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                  <div>
                    <i class="fas fa-paperclip me-2"></i>
                    <?php echo htmlspecialchars($attachment['filename'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (isset($attachment['source'])) { ?>
                      <span class="badge bg-<?php echo $attachment['source'] === 'database' ? 'success' : 'warning'; ?> ms-2">
                        <?php echo $attachment['source'] === 'database' ? 'DB' : 'IMAP'; ?>
                      </span>
                    <?php } ?>
                  </div>
                  <?php if (isset($attachment['content'])) { ?>
                    <a href="data:<?php echo htmlspecialchars($attachment['mime_type'], ENT_QUOTES, 'UTF-8'); ?>;base64,<?php echo $attachment['content']; ?>" 
                       download="<?php echo htmlspecialchars($attachment['filename'], ENT_QUOTES, 'UTF-8'); ?>" 
                       class="btn btn-sm btn-primary">
                      <i class="fas fa-download"></i> Download
                    </a>
                  <?php } else { ?>
                    <span class="text-muted">Content not available</span>
                  <?php } ?>
                </div>
              <?php } ?>
            </div>
          </div>
        <?php } ?>

        <!-- Reply Form -->
        <div class="mt-4">
          <div class="accordion" id="replyAccordion">
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#replyCollapse" aria-expanded="false" aria-controls="replyCollapse">
                  <i class="fas fa-reply me-2"></i> Reply to Email
                </button>
              </h2>
              <div id="replyCollapse" class="accordion-collapse collapse" data-bs-parent="#replyAccordion">
                <div class="accordion-body">
                  <form action="dashboard.php?tab=inbox&sub=message&id=<?php echo (int)$messageId; ?>" method="post">
                    <input type="hidden" name="action" value="reply" />
                    <div class="mb-3">
                      <label for="reply_recipient" class="form-label">To:</label>
                      <input type="email" class="form-control" id="reply_recipient" name="recipient" 
                             value="<?php echo htmlspecialchars((string)$message['from_email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="mb-3">
                      <label for="reply_subject" class="form-label">Subject:</label>
                      <input type="text" class="form-control" id="reply_subject" name="subject" 
                             value="Re: <?php echo htmlspecialchars((string)$message['subject'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="mb-3">
                      <label for="reply_body" class="form-label">Message:</label>
                      <textarea class="form-control" id="reply_body" name="body" rows="8" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-success">
                      <i class="fas fa-paper-plane"></i> Send Reply
                    </button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
