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
          // Simple database query - no IMAP, no matching, just fast display
          $emails = [];
          try {
            $stmt = $db->query('SELECT id, from_name, from_email, subject, mail_date, attachments FROM inbox_incoming ORDER BY mail_date DESC LIMIT 50');
            $emails = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
          } catch (Throwable $e) {
            echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
          }
        ?>
        
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
                <?php foreach ($emails as $email) { 
                  $viewUrl = 'dashboard.php?tab=inbox&sub=message&id=' . (int)$email['id'];
                  
                  // Simple attachment count from database
                  $attachmentCount = 0;
                  if (!empty($email['attachments'])) {
                    $attachmentCount = substr_count($email['attachments'], ',') + 1;
                  }
                  
                  // Simple from display
                  $fromDisplay = $email['from_name'] ? $email['from_name'] . ' <' . $email['from_email'] . '>' : $email['from_email'];
                ?>
                  <tr style="cursor: pointer;" onclick="window.location.href='<?php echo $viewUrl; ?>'">
                    <td><?php echo (int)$email['id']; ?></td>
                    <td><?php echo htmlspecialchars($fromDisplay, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                      <?php echo htmlspecialchars((string)$email['subject'], ENT_QUOTES, 'UTF-8'); ?>
                      <?php if ($attachmentCount > 0) { ?>
                        <span class="badge bg-info ms-2" title="<?php echo $attachmentCount; ?> attachment(s)">
                          📎 <?php echo $attachmentCount; ?>
                        </span>
                      <?php } ?>
                    </td>
                    <td><?php echo htmlspecialchars((string)$email['mail_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                      <span style="color: #999; font-size: 12px;">No matches</span>
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
        <?php } else { ?>
          <p class="text-muted">No emails found in database.</p>
        <?php } ?>
        
        <?php } else if ($sub === 'sent') { 
          include __DIR__ . '/tab_inbox_sent.php';
        } ?>
        <?php } else if ($sub === 'settings') { 
          include __DIR__ . '/tab_inbox_settings.php';
        } else if ($sub === 'message') { ?>
          <?php include __DIR__ . '/tab_inbox_message.php'; ?>
        <?php } ?>
      </div>
    </div>
  </div>
</div>
