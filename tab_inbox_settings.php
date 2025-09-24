<?php
declare(strict_types=1);

// expects $db, $flashMessage, $flashError, $emailStatuses to be available
?>
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
