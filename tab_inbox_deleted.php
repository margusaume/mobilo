<?php
// Deleted emails tab
require_once __DIR__ . '/inc/db.php';

try {
    $db = getDatabaseConnection();
    
    // Check if inbox_deleted table exists, create if not
    $db->exec('CREATE TABLE IF NOT EXISTS inbox_deleted (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id TEXT UNIQUE NOT NULL,
        from_name TEXT,
        from_email TEXT,
        subject TEXT,
        mail_date TEXT,
        snippet TEXT,
        content_plain TEXT,
        content_html TEXT,
        attachments TEXT,
        full_headers TEXT,
        created_at TEXT NOT NULL,
        deleted_at TEXT NOT NULL
    )');
    
    // Fetch deleted emails
    $stmt = $db->query('SELECT * FROM inbox_deleted ORDER BY deleted_at DESC');
    $deletedEmails = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    
} catch (Throwable $e) {
    echo '<div class="alert alert-danger">Database error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    $deletedEmails = [];
}
?>

<div class="mb-3">
  <h6>Deleted Emails</h6>
  <p class="text-muted">Emails that have been moved to trash. They can be restored if needed.</p>
</div>

<?php if (!empty($deletedEmails)) { ?>
  <div class="mb-3">
    <button type="button" class="btn btn-success" id="restoreSelectedBtn" disabled>
      <i class="fas fa-undo"></i> Restore Selected
    </button>
    <button type="button" class="btn btn-danger" id="permanentDeleteBtn" disabled>
      <i class="fas fa-trash-alt"></i> Permanent Delete
    </button>
    <span class="ms-2 text-muted" id="selectedCount">0 selected</span>
  </div>
  
  <div class="table-responsive">
    <table class="table table-hover">
      <thead>
        <tr>
          <th>
            <input type="checkbox" id="selectAll" class="form-check-input">
          </th>
          <th>#</th>
          <th>From</th>
          <th>Subject</th>
          <th>Date</th>
          <th>Deleted At</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($deletedEmails as $email) { ?>
          <tr>
            <td>
              <input type="checkbox" class="form-check-input email-checkbox" value="<?php echo (int)$email['id']; ?>">
            </td>
            <td><?php echo (int)$email['id']; ?></td>
            <td>
              <?php 
                $fromDisplay = $email['from_name'] ? 
                  htmlspecialchars($email['from_name'], ENT_QUOTES, 'UTF-8') . ' &lt;' . htmlspecialchars($email['from_email'], ENT_QUOTES, 'UTF-8') . '&gt;' :
                  htmlspecialchars($email['from_email'], ENT_QUOTES, 'UTF-8');
                echo $fromDisplay;
              ?>
            </td>
            <td><?php echo htmlspecialchars((string)$email['subject'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$email['mail_date'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td>
              <span class="text-muted" style="font-size: 12px;">
                <?php echo htmlspecialchars((string)$email['deleted_at'], ENT_QUOTES, 'UTF-8'); ?>
              </span>
            </td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
  </div>
<?php } else { ?>
  <div class="alert alert-info">
    <i class="fas fa-info-circle"></i> No deleted emails found.
  </div>
<?php } ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Email management functionality
    const selectAllCheckbox = document.getElementById('selectAll');
    const emailCheckboxes = document.querySelectorAll('.email-checkbox');
    const restoreSelectedBtn = document.getElementById('restoreSelectedBtn');
    const permanentDeleteBtn = document.getElementById('permanentDeleteBtn');
    const selectedCountSpan = document.getElementById('selectedCount');
    
    // Select all functionality
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            emailCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateButtons();
        });
    }
    
    // Individual checkbox functionality
    emailCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateButtons();
            updateSelectAllState();
        });
    });
    
    // Restore selected emails
    if (restoreSelectedBtn) {
        restoreSelectedBtn.addEventListener('click', function() {
            const selectedIds = Array.from(emailCheckboxes)
                .filter(checkbox => checkbox.checked)
                .map(checkbox => checkbox.value);
            
            if (selectedIds.length === 0) {
                alert('Please select emails to restore.');
                return;
            }
            
            if (confirm(`Are you sure you want to restore ${selectedIds.length} email(s)?`)) {
                restoreEmails(selectedIds);
            }
        });
    }
    
    // Permanent delete selected emails
    if (permanentDeleteBtn) {
        permanentDeleteBtn.addEventListener('click', function() {
            const selectedIds = Array.from(emailCheckboxes)
                .filter(checkbox => checkbox.checked)
                .map(checkbox => checkbox.value);
            
            if (selectedIds.length === 0) {
                alert('Please select emails to permanently delete.');
                return;
            }
            
            if (confirm(`Are you sure you want to PERMANENTLY DELETE ${selectedIds.length} email(s)? This action cannot be undone!`)) {
                permanentDeleteEmails(selectedIds);
            }
        });
    }
    
    function updateButtons() {
        const selectedCount = document.querySelectorAll('.email-checkbox:checked').length;
        if (restoreSelectedBtn) {
            restoreSelectedBtn.disabled = selectedCount === 0;
        }
        if (permanentDeleteBtn) {
            permanentDeleteBtn.disabled = selectedCount === 0;
        }
        if (selectedCountSpan) {
            selectedCountSpan.textContent = `${selectedCount} selected`;
        }
    }
    
    function updateSelectAllState() {
        const totalCheckboxes = emailCheckboxes.length;
        const checkedCheckboxes = document.querySelectorAll('.email-checkbox:checked').length;
        
        if (selectAllCheckbox) {
            if (checkedCheckboxes === 0) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = false;
            } else if (checkedCheckboxes === totalCheckboxes) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = true;
            } else {
                selectAllCheckbox.indeterminate = true;
            }
        }
    }
    
    function restoreEmails(emailIds) {
        // Show loading state
        restoreSelectedBtn.disabled = true;
        restoreSelectedBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring...';
        
        // Make AJAX request to restore emails
        fetch('dashboard.php?tab=inbox&action=restore_emails', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'email_ids=' + encodeURIComponent(JSON.stringify(emailIds))
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', `Successfully restored ${data.restored_count} email(s)`);
                // Refresh the page to show updated list
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showAlert('danger', 'Error restoring emails: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('danger', 'Error restoring emails: ' + error.message);
        })
        .finally(() => {
            // Reset button state
            restoreSelectedBtn.disabled = false;
            restoreSelectedBtn.innerHTML = '<i class="fas fa-undo"></i> Restore Selected';
        });
    }
    
    function permanentDeleteEmails(emailIds) {
        // Show loading state
        permanentDeleteBtn.disabled = true;
        permanentDeleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        
        // Make AJAX request to permanently delete emails
        fetch('dashboard.php?tab=inbox&action=permanent_delete_emails', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'email_ids=' + encodeURIComponent(JSON.stringify(emailIds))
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAlert('success', `Successfully permanently deleted ${data.deleted_count} email(s)`);
                // Refresh the page to show updated list
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showAlert('danger', 'Error permanently deleting emails: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('danger', 'Error permanently deleting emails: ' + error.message);
        })
        .finally(() => {
            // Reset button state
            permanentDeleteBtn.disabled = false;
            permanentDeleteBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Permanent Delete';
        });
    }
    
    function showAlert(type, message) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        // Insert at the top of the card body
        const cardBody = document.querySelector('.card-body');
        if (cardBody) {
            cardBody.insertBefore(alertDiv, cardBody.firstChild);
        }
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
});
</script>
