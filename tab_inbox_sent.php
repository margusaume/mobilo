<?php
declare(strict_types=1);

// expects $db, $flashMessage, $flashError, $emailStatuses to be available
?>
<h4>Sent Emails</h4>
<?php
  // Fetch sent emails from inbox_sent table
  $sentEmails = [];
  try {
    $stmt = $db->query('SELECT er.*, m.from_email, m.from_name, m.subject as original_subject 
                        FROM inbox_sent er 
                        LEFT JOIN inbox_incoming m ON er.email_id = m.id 
                        ORDER BY er.sent_at DESC');
    $sentEmails = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
  } catch (Throwable $e) {
    echo '<div class="alert alert-danger">Error fetching sent emails: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
  }
?>

<?php if (empty($sentEmails)) { ?>
  <p class="text-muted">No sent emails found.</p>
<?php } else { ?>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead>
        <tr>
          <th>ID</th>
          <th>To</th>
          <th>Subject</th>
          <th>Sent Date</th>
          <th>Original Email</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sentEmails as $sent) { ?>
          <tr>
            <td><?php echo (int)$sent['id']; ?></td>
            <td>
              <?php 
                // Get recipient email from original message
                $recipientEmail = (string)($sent['from_email'] ?? 'Unknown');
                $recipientName = (string)($sent['from_name'] ?? '');
                if ($recipientName && $recipientName !== $recipientEmail) {
                  echo htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . ' &lt;' . htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8') . '&gt;';
                } else {
                  echo htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8');
                }
              ?>
            </td>
            <td><?php echo htmlspecialchars((string)$sent['subject'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$sent['sent_at'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td>
              <?php if ($sent['original_subject']) { ?>
                <small class="text-muted">Re: <?php echo htmlspecialchars((string)$sent['original_subject'], ENT_QUOTES, 'UTF-8'); ?></small>
              <?php } else { ?>
                <small class="text-muted">Original email not found</small>
              <?php } ?>
            </td>
            <td>
              <button class="btn btn-sm btn-outline-primary" onclick="showSentEmailDetails(<?php echo (int)$sent['id']; ?>)">
                View Details
              </button>
            </td>
          </tr>
          <tr id="sent-details-<?php echo (int)$sent['id']; ?>" style="display: none;">
            <td colspan="6">
              <div class="p-3 bg-light border rounded">
                <h6>Email Content:</h6>
                <div class="mb-3">
                  <strong>Subject:</strong> <?php echo htmlspecialchars((string)$sent['subject'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div class="mb-3">
                  <strong>Body:</strong>
                  <div class="p-2 bg-white border rounded" style="max-height: 200px; overflow-y: auto;">
                    <?php echo nl2br(htmlspecialchars((string)$sent['body'], ENT_QUOTES, 'UTF-8')); ?>
                  </div>
                </div>
                <div>
                  <strong>Sent:</strong> <?php echo htmlspecialchars((string)$sent['sent_at'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
              </div>
            </td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
  </div>
  
  <script>
  function showSentEmailDetails(id) {
    const detailsRow = document.getElementById('sent-details-' + id);
    if (detailsRow.style.display === 'none') {
      detailsRow.style.display = 'table-row';
    } else {
      detailsRow.style.display = 'none';
    }
  }
  </script>
<?php } ?>
