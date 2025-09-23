<?php
declare(strict_types=1);

// expects $db to be available
?>
<section style="text-align:left; max-width:1024px">
  <h2>ADMIN</h2>
  <nav style="display:flex; gap:10px; margin: 8px 0 16px">
    <a href="dashboard.php?tab=admin&sub=users" style="text-decoration:none; padding:6px 10px; border:1px solid #ddd; border-radius:6px">Users</a>
    <a href="dashboard.php?tab=admin&sub=channels" style="text-decoration:none; padding:6px 10px; border:1px solid #ddd; border-radius:6px">Channels</a>
    <a href="dashboard.php?tab=admin&sub=docs" style="text-decoration:none; padding:6px 10px; border:1px solid #ddd; border-radius:6px">Site docs</a>
  </nav>
  <?php
    $sub = isset($_GET['sub']) ? (string)$_GET['sub'] : 'users';
    if ($sub === 'docs') {
        $dataModelPath = __DIR__ . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'data-model.md';
        $uiBehaviorPath = __DIR__ . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'ui-behavior.md';
        $renderMd = function($path) {
            if (!is_file($path)) { return '<p style="color:#666">Missing: ' . htmlspecialchars(basename($path), ENT_QUOTES, 'UTF-8') . '</p>'; }
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
    <h3>Data model</h3>
    <div style="padding:12px; border:1px solid #eee; border-radius:6px; background:#fafafa"><?php echo $renderMd($dataModelPath); ?></div>
    <h3 style="margin-top:20px">UI behavior</h3>
    <div style="padding:12px; border:1px solid #eee; border-radius:6px; background:#fafafa"><?php echo $renderMd($uiBehaviorPath); ?></div>
  <?php } else if ($sub === 'channels') { ?>
    <h3>Channels</h3>
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
      if ($flashMessage) { echo '<div style="color:green; margin:8px 0">' . htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') . '</div>'; }
      if ($flashError) { echo '<div style="color:#c00; margin:8px 0">' . htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') . '</div>'; }
    ?>
    <form action="dashboard.php?tab=admin&sub=channels" method="post" enctype="multipart/form-data" style="max-width:720px">
      <div class="form-row">
        <label class="label" for="channel_name">Channel name</label>
        <input id="channel_name" name="channel_name" type="text" required />
      </div>
      <div class="form-row">
        <label class="label" for="channel_homepage">Channel homepage</label>
        <input id="channel_homepage" name="channel_homepage" type="url" placeholder="https://example.com" required />
      </div>
      <div class="form-row">
        <label class="label" for="channel_logo">Upload logo (png/jpg/gif/webp)</label>
        <input id="channel_logo" name="channel_logo" type="file" accept=".png,.jpg,.jpeg,.gif,.webp" />
      </div>
      <button type="submit">Create channel</button>
    </form>
    <h4 style="margin-top:24px">Channels list</h4>
    <?php
      $chStmt = $db->query('SELECT id, name, homepage_url, logo_path, created_at FROM channels ORDER BY id DESC');
      $channels = $chStmt ? $chStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    ?>
    <?php if (empty($channels)) { ?>
      <p style="color:#666">No channels yet.</p>
    <?php } else { ?>
      <div style="overflow:auto; border:1px solid #ddd; border-radius:6px">
        <table>
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
                <td><a href="<?php echo htmlspecialchars((string)$ch['homepage_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">link</a></td>
                <td><?php if (!empty($ch['logo_path'])) { ?><img src="<?php echo htmlspecialchars((string)$ch['logo_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="logo" style="height:28px" /><?php } else { echo '-'; } ?></td>
                <td><?php echo htmlspecialchars((string)$ch['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  <?php } else { ?>
    <h3>Users (DB Overview)</h3>
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
      <p style="color:#666">No user tables found.</p>
    <?php } else { ?>
      <ul>
        <?php foreach ($tables as $t) { ?>
          <li><strong><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></strong></li>
        <?php } ?>
      </ul>
      <?php foreach ($tables as $t) { $rows = $tableSamples[$t] ?? []; ?>
        <h4><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?> (first 10 rows)</h4>
        <?php if (empty($rows)) { ?>
          <p style="color:#666">No rows.</p>
        <?php } else { $cols = array_keys($rows[0]); ?>
          <div style="overflow:auto; border:1px solid #ddd; border-radius:6px">
            <table style="border-collapse:collapse; min-width:600px; width:100%">
              <thead>
                <tr>
                  <?php foreach ($cols as $c) { ?>
                    <th style="text-align:left; padding:8px; border-bottom:1px solid #eee; background:#fafafa"><?php echo htmlspecialchars((string)$c, ENT_QUOTES, 'UTF-8'); ?></th>
                  <?php } ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r) { ?>
                  <tr>
                    <?php foreach ($cols as $c) { $val = (string)($r[$c] ?? ''); ?>
                      <td style="padding:8px; border-bottom:1px solid #f3f3f3; vertical-align:top; white-space:pre-wrap; word-break:break-word"><?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?></td>
                    <?php } ?>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        <?php } ?>
      <?php } ?>
  <?php } ?>
</section>
<?php } ?>