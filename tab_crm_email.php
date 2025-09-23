<?php
declare(strict_types=1);

// expects $db to be available
?>
<section style="text-align:left; max-width:1024px">
  <h2>CRM</h2>
  <nav style="display:flex; gap:10px; margin: 8px 0 16px">
    <a href="dashboard.php?tab=crm&sub=email" style="text-decoration:none; padding:6px 10px; border:1px solid #ddd; border-radius:6px">Email</a>
    <a href="dashboard.php?tab=crm&sub=people" style="text-decoration:none; padding:6px 10px; border:1px solid #ddd; border-radius:6px">People</a>
    <a href="dashboard.php?tab=crm&sub=organisations" style="text-decoration:none; padding:6px 10px; border:1px solid #ddd; border-radius:6px">Organisations</a>
  </nav>
  <?php
    $sub = isset($_GET['sub']) ? (string)$_GET['sub'] : 'email';
    if ($sub === 'email') {
  ?>
  <h3>Email (Contacts)</h3>
  <?php
    $rows = [];
    try {
      $emStmt = $db->query('SELECT e.id, e.email, e.name FROM emails e ORDER BY e.id DESC');
      $rows = $emStmt ? $emStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $ign) {}

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['tab'] ?? '') === 'crm' && ($_GET['sub'] ?? '') === 'email') {
      $act = (string)($_POST['action'] ?? '');
      if ($act === 'update_email') {
        $id = (int)($_POST['id'] ?? 0);
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        if ($id > 0 && $email !== '') {
          try {
            $st = $db->prepare('UPDATE emails SET email = :e, name = :n WHERE id = :id');
            $st->execute([':e'=>$email, ':n'=>($name !== '' ? $name : null), ':id'=>$id]);
            echo '<div style="color:green; margin:8px 0">Saved</div>';
          } catch (Throwable $upErr) {
            echo '<div style="color:#c00; margin:8px 0">Error saving: ' . htmlspecialchars($upErr->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
          }
        }
      }
      try {
        $emStmt = $db->query('SELECT e.id, e.email, e.name FROM emails e ORDER BY e.id DESC');
        $rows = $emStmt ? $emStmt->fetchAll(PDO::FETCH_ASSOC) : [];
      } catch (Throwable $ign) {}
    }
  ?>
  <?php if (empty($rows)) { ?>
    <p style="color:#666">No contacts yet. Fetch some emails in INBOX.</p>
  <?php } else { ?>
    <div style="overflow:auto; border:1px solid #ddd; border-radius:6px">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Email</th>
            <th>Name</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $em) { ?>
            <tr>
              <td><?php echo (int)$em['id']; ?></td>
              <td>
                <form action="dashboard.php?tab=crm&sub=email" method="post" style="display:flex; gap:6px; align-items:center">
                  <input type="hidden" name="action" value="update_email" />
                  <input type="hidden" name="id" value="<?php echo (int)$em['id']; ?>" />
                  <input type="text" name="email" value="<?php echo htmlspecialchars((string)$em['email'], ENT_QUOTES, 'UTF-8'); ?>" style="min-width:260px" required />
                  <input type="text" name="name" value="<?php echo htmlspecialchars((string)($em['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="min-width:200px" />
                  <button type="submit">Save</button>
                </form>
              </td>
              <td><?php echo htmlspecialchars((string)($em['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
              <td></td>
            </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
  <?php } else if ($sub === 'people') { ?>
    <h3>People</h3>
    <p style="color:#666">People management coming soon.</p>
  <?php } else if ($sub === 'organisations') { ?>
    <h3>Organisations</h3>
    <p style="color:#666">Organisation management coming soon.</p>
  <?php } ?>
</section>


