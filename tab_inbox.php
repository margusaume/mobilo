<?php
declare(strict_types=1);

// expects $db, $flashMessage, $flashError, $emailStatuses to be available
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
              </tr>
            </thead>
            <tbody>
              <?php 
              $viewIdx = isset($_GET['view_idx']) ? (int)$_GET['view_idx'] : 0;
              foreach ($emails as $em) {
                    // use row index for view
                    $viewUrl = 'dashboard.php?tab=inbox&sub=list&view_idx=' . urlencode((string)$em['index']);
                    $isSelected = $viewIdx === (int)$em['index'];
                    
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
                <tr style="cursor: pointer;" onclick="window.location.href='<?php echo $viewUrl; ?>'">
                  <td><?php echo (int)$em['index']; ?></td>
                  <td><?php echo htmlspecialchars((string)$em['from'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td>
                    <?php echo htmlspecialchars((string)$em['subject'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($attachmentCount > 0) { ?>
                      <span class="badge bg-info ms-2" title="<?php echo $attachmentCount; ?> attachment(s)">
                        📎 <?php echo $attachmentCount; ?>
                      </span>
                    <?php } ?>
                  </td>
                  <td><?php echo htmlspecialchars((string)$em['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <?php if ($isSelected) { ?>
                  <tr>
                    <td colspan="4" style="padding: 0; border: none;">
                      <div class="message-detail mt-2 p-3" style="background-color: #f8f9fa; border-left: 4px solid #0d6efd;">
                        <?php
                          // Fetch email detail for selected row
                          $detail = null; $attachments = [];
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
                          <div class="mb-3">
                            <strong>Content:</strong>
                            <div class="message-content p-3 mt-2" style="background-color: white; border: 1px solid #dee2e6; border-radius: 6px; max-height: 300px; overflow-y: auto;">
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
                          <?php if (!empty($detail['attachments'])) { ?>
                            <div class="mb-3">
                              <strong>Attachments:</strong>
                              <ul class="list-unstyled">
                                <?php foreach ($detail['attachments'] as $att) { ?>
                                  <li><span class="badge bg-secondary"><?php echo htmlspecialchars((string)$att['filename'], ENT_QUOTES, 'UTF-8'); ?></span></li>
                                <?php } ?>
                              </ul>
                            </div>
                          <?php } ?>
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
                                      <div class="mb-3">
                                        <strong>Reply to:</strong> <?php echo htmlspecialchars($fromEmailOnly, ENT_QUOTES, 'UTF-8'); ?>
                                      </div>
                                      <form action="dashboard.php?tab=inbox&sub=list&view_idx=<?php echo urlencode((string)$viewIdx); ?>" method="post">
                                        <input type="hidden" name="action" value="reply" />
                                        <input type="hidden" name="email_id" value="<?php echo (int)$contactId; ?>" />
                                        <div class="mb-3">
                                          <label for="reply_subject" class="form-label">Subject</label>
                                          <input type="text" name="subject" id="reply_subject" class="form-control" placeholder="Subject" required />
                                        </div>
                                        <div class="mb-3">
                                          <label for="reply_body" class="form-label">Message</label>
                                          <textarea name="body" id="reply_body" rows="6" class="form-control" placeholder="Your reply..." required></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-primary">Send & Save</button>
                                      </form>
                                    <?php } else { echo '<p class="text-muted">No contact email found to reply.</p>'; } ?>
                                  </div>
                                </div>
                              </div>
                            </div>
                          </div>
                        <?php } ?>
                      </div>
                    </td>
                  </tr>
                <?php } ?>
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
      <?php } ?>
      </div>
    </div>
  </div>
</div>
