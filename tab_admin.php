<?php
declare(strict_types=1);

// expects $db to be available
?>
<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h5 class="card-title mb-0">ADMIN</h5>
      </div>
      <div class="card-body">
        <ul class="nav nav-pills mb-4">
          <li class="nav-item">
            <a class="nav-link <?php echo ($_GET['sub'] ?? '') === 'users' ? 'active' : ''; ?>" href="dashboard.php?tab=admin&sub=users">Users</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo ($_GET['sub'] ?? '') === 'channels' ? 'active' : ''; ?>" href="dashboard.php?tab=admin&sub=channels">Channels</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo ($_GET['sub'] ?? '') === 'database' ? 'active' : ''; ?>" href="dashboard.php?tab=admin&sub=database">Database</a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php echo ($_GET['sub'] ?? '') === 'docs' ? 'active' : ''; ?>" href="dashboard.php?tab=admin&sub=docs">Site docs</a>
          </li>
        </ul>
        <?php
          $sub = isset($_GET['sub']) ? (string)$_GET['sub'] : 'users';
          if ($sub === 'docs') {
              $dataModelPath = __DIR__ . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'data-model.md';
              $uiBehaviorPath = __DIR__ . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'ui-behavior.md';
              $renderMd = function($path) {
                  if (!is_file($path)) { return '<p class="text-muted">Missing: ' . htmlspecialchars(basename($path), ENT_QUOTES, 'UTF-8') . '</p>'; }
                  $txt = (string)@file_get_contents($path);
                  $safe = htmlspecialchars($txt, ENT_QUOTES, 'UTF-8');
                  $safe = preg_replace('/^## (.*)$/m', '<h3>$1</h3>', $safe);
                  $safe = preg_replace('/^### (.*)$/m', '<h4>$1</h4>', $safe);
                  $safe = preg_replace('/^- (.*)$/m', '<li>$1</li>', $safe);
                  $safe = preg_replace('/\n{2,}/', "\n\n", $safe);
                  $safe = preg_replace('/(?:<li>.*<\/li>\n?)+/s', '<ul>$0</ul>', (string)$safe);
                  return nl2br($safe);
              };
        ?>
          <h5>Data model</h5>
          <div class="p-3 border rounded bg-light"><?php echo $renderMd($dataModelPath); ?></div>
          <h5 class="mt-4">UI behavior</h5>
          <div class="p-3 border rounded bg-light"><?php echo $renderMd($uiBehaviorPath); ?></div>
        <?php } else if ($sub === 'database') { ?>
          <h5>Database Overview</h5>
          <?php
            $tables = [];
            $tableSamples = [];
            try {
              $tablesStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
              $tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
              foreach ($tables as $t) {
                $identifier = '"' . str_replace('"', '""', $t) . '"';
                $rowsStmt = $db->query("SELECT * FROM {$identifier} LIMIT 10");
                $tableSamples[$t] = $rowsStmt ? $rowsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
              }
            } catch (Throwable $e) {}
          ?>
          <?php if (empty($tables)) { ?>
            <p class="text-muted">No user tables found.</p>
          <?php } else { ?>
            <ul class="list-unstyled">
              <?php foreach ($tables as $t) { ?>
                <li><strong><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></strong></li>
              <?php } ?>
            </ul>
            <?php foreach ($tables as $t) { $rows = $tableSamples[$t] ?? []; ?>
              <h6 class="mt-4"><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?> (first 10 rows)</h6>
              <?php if (empty($rows)) { ?>
                <p class="text-muted">No rows.</p>
              <?php } else { $cols = array_keys($rows[0]); ?>
                <div class="table-responsive">
                  <table class="table table-striped table-sm">
                    <thead>
                      <tr>
                        <?php foreach ($cols as $c) { ?>
                          <th><?php echo htmlspecialchars((string)$c, ENT_QUOTES, 'UTF-8'); ?></th>
                        <?php } ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($rows as $r) { ?>
                        <tr>
                          <?php foreach ($cols as $c) { $val = (string)($r[$c] ?? ''); ?>
                            <td style="white-space:pre-wrap; word-break:break-word"><?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?></td>
                          <?php } ?>
                        </tr>
                      <?php } ?>
                    </tbody>
                  </table>
                </div>
              <?php } ?>
            <?php } ?>
          <?php } ?>
        <?php } else if ($sub === 'channels') { ?>
          <h5>Channels</h5>
          <?php
            $flashError = '';$flashMessage='';
            $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
            if (!is_dir($uploadsDir)) { @mkdir($uploadsDir, 0775, true); }
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['tab'] ?? '') === 'admin' && ($_GET['sub'] ?? '') === 'channels') {
              $name = trim((string)($_POST['channel_name'] ?? ''));
              $homepage = trim((string)($_POST['channel_homepage'] ?? ''));
              $logoPathRel = null;
              if ($name === '' || $homepage === '') {
                $flashError = 'Channel name and homepage are required.';
              } else if (!preg_match('~^https?://~i', $homepage)) {
                $flashError = 'Homepage must start with http:// or https://';
              } else {
                if (!empty($_FILES['channel_logo']['name']) && (int)($_FILES['channel_logo']['error'] ?? 0) === UPLOAD_ERR_OK) {
                  $tmp = (string)$_FILES['channel_logo']['tmp_name'];
                  $orig = (string)$_FILES['channel_logo']['name'];
                  $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                  $allowed = ['png','jpg','jpeg','gif','webp'];
                  if ($ext !== '' && in_array($ext, $allowed, true)) {
                    $basename = 'logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($ext ? ('.' . $ext) : '');
                    $destAbs = $uploadsDir . DIRECTORY_SEPARATOR . $basename;
                    if (@move_uploaded_file($tmp, $destAbs)) { $logoPathRel = 'uploads/' . $basename; }
                    else { $flashError = 'Failed to save uploaded file.'; }
                  } else if ($ext !== '') { $flashError = 'Unsupported file type.'; }
                }
                if ($flashError === '') {
                  $ins = $db->prepare('INSERT INTO channels (name, homepage_url, logo_path, created_at) VALUES (:n, :h, :l, :t)');
                  $ins->execute([':n'=>$name, ':h'=>$homepage, ':l'=>$logoPathRel, ':t'=>(new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                  $flashMessage = 'Channel created.';
                }
              }
            }
            if ($flashMessage) { echo '<div class="alert alert-success">' . htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') . '</div>'; }
            if ($flashError) { echo '<div class="alert alert-danger">' . htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') . '</div>'; }
          ?>
          <form action="dashboard.php?tab=admin&sub=channels" method="post" enctype="multipart/form-data" class="mb-4">
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
          <h6>Channels list</h6>
          <?php
            $chStmt = $db->query('SELECT id, name, homepage_url, logo_path, created_at FROM channels ORDER BY id DESC');
            $channels = $chStmt ? $chStmt->fetchAll(PDO::FETCH_ASSOC) : [];
          ?>
          <?php if (empty($channels)) { ?>
            <p class="text-muted">No channels yet.</p>
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
                  <?php foreach ($channels as $ch) { ?>
                    <tr>
                      <td><?php echo (int)$ch['id']; ?></td>
                      <td><?php echo htmlspecialchars((string)$ch['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><a href="<?php echo htmlspecialchars((string)$ch['homepage_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">Visit</a></td>
                      <td><?php if (!empty($ch['logo_path'])) { ?><img src="<?php echo htmlspecialchars((string)$ch['logo_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="logo" class="channel-logo" /><?php } else { echo '<span class="text-muted">-</span>'; } ?></td>
                      <td><?php echo htmlspecialchars((string)$ch['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          <?php } ?>
        <?php } else { ?>
          <h5>Users</h5>
          <p class="text-muted">User management functionality coming soon.</p>
        <?php } ?>
      </div>
    </div>
  </div>
</div>