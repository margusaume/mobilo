<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.html?error=1');
    exit;
}

$username = htmlspecialchars((string)($_SESSION['username'] ?? 'user'), ENT_QUOTES, 'UTF-8');

// Fetch table list and preview rows
$tables = [];
$tableSamples = [];
try {
	$db = getDatabaseConnection();
	$tablesStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
	$tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
	foreach ($tables as $t) {
		// Quote identifier safely by wrapping in double quotes and replacing any existing
		$identifier = '"' . str_replace('"', '""', $t) . '"';
		$rowsStmt = $db->query("SELECT * FROM {$identifier} LIMIT 10");
		$tableSamples[$t] = $rowsStmt ? $rowsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}
} catch (Throwable $e) {
	$tables = [];
	$tableSamples = [];
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard</title>
  <style>
    html, body { height: 100%; margin: 0; }
    body { display: grid; place-items: center; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Arial, Helvetica, "Apple Color Emoji", "Segoe UI Emoji"; }
    .container { text-align: center; }
  </style>
</head>
<body>
  <main class="container">
    <h1>Welcome, <?php echo $username; ?>!</h1>
    <p>You are logged in.</p>
    <p><a href="logout.php">Log out</a></p>

    <section style="text-align:left; max-width:920px">
      <h2 style="text-align:center">Database overview</h2>
      <?php if (empty($tables)) { ?>
        <p style="text-align:center; color:#666">No user tables found.</p>
      <?php } else { ?>
        <ul>
          <?php foreach ($tables as $t) { ?>
            <li><strong><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></strong></li>
          <?php } ?>
        </ul>

        <?php foreach ($tables as $t) { $rows = $tableSamples[$t] ?? []; ?>
          <h3><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?> (first 10 rows)</h3>
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
  </main>
</body>
</html>


