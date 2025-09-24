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
          <h5>Database Tables</h5>
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
            <!-- Table Navigation -->
            <ul class="nav nav-tabs mb-3" id="tableTabs" role="tablist">
              <?php foreach ($tables as $index => $t) { ?>
                <li class="nav-item" role="presentation">
                  <button class="nav-link <?php echo $index === 0 ? 'active' : ''; ?>" id="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>-tab" data-bs-toggle="tab" data-bs-target="#<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>" type="button" role="tab" aria-controls="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>" aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>">
                    <?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>
                  </button>
                </li>
              <?php } ?>
            </ul>
            
            <!-- Table Content -->
            <div class="tab-content" id="tableTabsContent">
              <?php foreach ($tables as $index => $t) { $rows = $tableSamples[$t] ?? []; ?>
                <div class="tab-pane fade <?php echo $index === 0 ? 'show active' : ''; ?>" id="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>" role="tabpanel" aria-labelledby="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>-tab">
                  <h6><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?> (first 10 rows)</h6>
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
                </div>
              <?php } ?>
            </div>
          <?php } ?>
        <?php } else { ?>
          <h5>Users</h5>
          <?php
            // Handle user updates
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['tab'] ?? '') === 'admin' && ($_GET['sub'] ?? '') === 'users') {
              $action = (string)($_POST['action'] ?? '');
              if ($action === 'update_user') {
                $userId = (int)($_POST['user_id'] ?? 0);
                $name = trim((string)($_POST['name'] ?? ''));
                if ($userId > 0) {
                  try {
                    $stmt = $db->prepare('UPDATE users SET name = :name WHERE id = :id');
                    $stmt->execute([':name' => $name, ':id' => $userId]);
                    echo '<div class="alert alert-success">User updated successfully.</div>';
                  } catch (Throwable $e) {
                    echo '<div class="alert alert-danger">Error updating user: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
                  }
                }
              }
            }
            
            // Check if users table has name column, if not add it
            try {
              $columns = [];
              $stmt = $db->query("PRAGMA table_info(users)");
              while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $row['name'];
              }
              if (!in_array('name', $columns, true)) {
                $db->exec('ALTER TABLE users ADD COLUMN name TEXT');
              }
            } catch (Throwable $e) {}
            
            // Fetch users
            $users = [];
            try {
              $stmt = $db->query('SELECT id, username, name FROM users ORDER BY id');
              $users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable $e) {}
          ?>
          
          <?php if (empty($users)) { ?>
            <p class="text-muted">No users found.</p>
          <?php } else { ?>
            <div class="table-responsive">
              <table class="table table-striped">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Name</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($users as $user) { ?>
                    <tr>
                      <td><?php echo (int)$user['id']; ?></td>
                      <td><?php echo htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                      <td>
                        <form action="dashboard.php?tab=admin&sub=users" method="post" class="d-inline">
                          <input type="hidden" name="action" value="update_user" />
                          <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>" />
                          <div class="input-group">
                            <input type="text" name="name" value="<?php echo htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" class="form-control form-control-sm" placeholder="Enter name" />
                            <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                          </div>
                        </form>
                      </td>
                      <td>
                        <span class="badge bg-secondary">User</span>
                      </td>
                    </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          <?php } ?>
        <?php } ?>
      </div>
    </div>
  </div>
</div>