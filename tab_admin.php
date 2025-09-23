<?php
declare(strict_types=1);

// expects $db to be available
?>
<section style="text-align:left; max-width:1024px">
  <h2>ADMIN</h2>
  <nav style="display:flex; gap:10px; margin: 8px 0 16px">
    <a href="dashboard.php?tab=admin&sub=users" style="text-decoration:none; padding:6px 10px; border:1px solid #ddd; border-radius:6px">Users</a>
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


