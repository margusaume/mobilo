<?php
declare(strict_types=1);

// expects $db, $flashMessage, $flashError, $emailStatuses to be available

// Start load time tracking
$pageStartTime = microtime(true);

// Get sub-tab parameter
$sub = isset($_GET['sub']) ? (string)$_GET['sub'] : 'list';
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
          <?php if (($sub === '' || $sub === 'list')) { ?>
          <li class="nav-item">
            <button class="btn btn-outline-primary btn-sm me-2" id="refreshInboxBtn" title="Refresh inbox from IMAP">
              <i class="fas fa-sync-alt" id="refreshIcon"></i> Refresh
            </button>
          </li>
          <?php } ?>
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
                  
                  // Organization matching
                  $organizationLabels = [];
                  
                  // 1) Check if sender name matches people in crm_people table
                  if (!empty($email['from_name'])) {
                    $peopleStmt = $db->prepare('SELECT name FROM crm_people WHERE name = ?');
                    $peopleStmt->execute([$email['from_name']]);
                    $peopleMatches = $peopleStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($peopleMatches as $person) {
                      $organizationLabels[] = '<a href="dashboard.php?tab=crm&sub=people" class="badge bg-primary text-decoration-none">👤 ' . htmlspecialchars($person['name'], ENT_QUOTES, 'UTF-8') . '</a>';
                    }
                  }
                  
                  // 2) Check if sender email domain is in crm_organisations table
                  $domain = substr(strrchr($email['from_email'], '@'), 1);
                  if ($domain) {
                    $orgStmt = $db->prepare('SELECT name FROM crm_organisations WHERE domain = ?');
                    $orgStmt->execute([$domain]);
                    $orgMatches = $orgStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($orgMatches as $org) {
                      $orgName = $org['name'] ?: $domain; // Use name if available, otherwise use domain
                      $organizationLabels[] = '<a href="dashboard.php?tab=crm&sub=organisations" class="badge bg-success text-decoration-none">🏢 ' . htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') . '</a>';
                    }
                  }
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
                      <?php if (!empty($organizationLabels)) { ?>
                        <?php echo implode(' ', $organizationLabels); ?>
                      <?php } else { ?>
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
        <?php } else { ?>
          <p class="text-muted">No emails found in database.</p>
        <?php } ?>
        
        <?php } else if ($sub === 'sent') { 
          include __DIR__ . '/tab_inbox_sent.php';
        } else if ($sub === 'settings') { 
          include __DIR__ . '/tab_inbox_settings.php';
        } else if ($sub === 'message') { ?>
          <?php include __DIR__ . '/tab_inbox_message.php'; ?>
        <?php } ?>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const refreshBtn = document.getElementById('refreshInboxBtn');
    const refreshIcon = document.getElementById('refreshIcon');
    
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            // Disable button and show loading
            refreshBtn.disabled = true;
            refreshIcon.classList.add('fa-spin');
            refreshBtn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Syncing...';
            
            // Make AJAX request to sync IMAP
            fetch('inc/sync_imap.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'sync_imap=1'
            })
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                return response.text().then(text => {
                    console.log('Raw response:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        throw new Error('Invalid JSON response: ' + text.substring(0, 200));
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    // Show success message
                    showAlert('success', `Successfully synced ${data.new_emails} new emails`);
                    // Refresh the page to show new emails
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    let errorMsg = data.error;
                    if (errorMsg.includes('IMAP extension is not installed')) {
                        errorMsg = 'IMAP extension is not installed on this server. Please contact your hosting provider.';
                    } else if (errorMsg.includes('IMAP configuration missing')) {
                        errorMsg = 'IMAP configuration is missing. Please check config.local.php file.';
                    } else if (errorMsg.includes('Failed to connect to IMAP server')) {
                        errorMsg = 'Failed to connect to IMAP server. Please check your credentials and server settings.';
                    }
                    showAlert('danger', 'Error syncing emails: ' + errorMsg);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('danger', 'Error syncing emails: ' + error.message);
            })
            .finally(() => {
                // Re-enable button
                refreshBtn.disabled = false;
                refreshIcon.classList.remove('fa-spin');
                refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh';
            });
        });
    }
    
    function showAlert(type, message) {
        // Create alert element
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        // Insert at top of card body
        const cardBody = document.querySelector('.card-body');
        cardBody.insertBefore(alertDiv, cardBody.firstChild);
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
});
</script>
