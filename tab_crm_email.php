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
    $debugInfo = '';
    try {
      $emStmt = $db->query('SELECT e.id, e.email, e.name, e.company FROM emails e ORDER BY e.id DESC');
      $rows = $emStmt ? $emStmt->fetchAll(PDO::FETCH_ASSOC) : [];
      $debugInfo = 'Query executed successfully. Found ' . count($rows) . ' rows.';
    } catch (Throwable $e) {
      $debugInfo = 'Database error: ' . $e->getMessage();
    }
  ?>
  <div style="background-color: #f8f9fa; padding: 8px; margin: 8px 0; border-radius: 4px; font-size: 12px; color: #666;">
    Debug: <?php echo htmlspecialchars($debugInfo, ENT_QUOTES, 'UTF-8'); ?>
  </div>

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
      } else if ($act === 'extract_company') {
        $id = (int)($_POST['id'] ?? 0);
        $email = trim((string)($_POST['email'] ?? ''));
        if ($id > 0 && $email !== '') {
          try {
            // Extract domain from email
            $domain = '';
            if (strpos($email, '@') !== false) {
              $domain = strtolower(trim(substr($email, strpos($email, '@') + 1)));
            }
            
            if ($domain !== '') {
              // Check if company already exists
              $compStmt = $db->prepare('SELECT id FROM companies WHERE domain = :d');
              $compStmt->execute([':d' => $domain]);
              $company = $compStmt->fetch();
              
              if (!$company) {
                // Create new company
                $insComp = $db->prepare('INSERT INTO companies (domain, name, created_at) VALUES (:d, :n, :t)');
                $insComp->execute([':d' => $domain, ':n' => $domain, ':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                $companyId = (int)$db->lastInsertId();
              } else {
                $companyId = (int)$company['id'];
              }
              
              // Update email with company
              $updEmail = $db->prepare('UPDATE emails SET company = :c WHERE id = :id');
              $updEmail->execute([':c' => $domain, ':id' => $id]);
              
              // Create connection
              $connStmt = $db->prepare('INSERT OR IGNORE INTO email_company_connections (email_id, company_id, created_at) VALUES (:e, :c, :t)');
              $connStmt->execute([':e' => $id, ':c' => $companyId, ':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
              
              echo '<div style="color:green; margin:8px 0">Company extracted: ' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . '</div>';
            }
          } catch (Throwable $upErr) {
            echo '<div style="color:#c00; margin:8px 0">Error extracting company: ' . htmlspecialchars($upErr->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
          }
        }
      }
      try {
        $emStmt = $db->query('SELECT e.id, e.email, e.name, e.company FROM emails e ORDER BY e.id DESC');
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
            <th>Company</th>
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
                <?php if (!empty($em['company'])) { ?>
                  <span style="background-color: #d4edda; color: #155724; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                    <?php echo htmlspecialchars((string)$em['company'], ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                <?php } else { ?>
                  <span style="cursor: pointer; background-color: #f8f9fa; color: #6c757d; padding: 4px 8px; border-radius: 4px; font-size: 12px; border: 1px dashed #dee2e6;" 
                        onclick="extractCompany(<?php echo (int)$em['id']; ?>, '<?php echo htmlspecialchars((string)$em['email'], ENT_QUOTES, 'UTF-8'); ?>')">
                    Click to extract
                  </span>
                <?php } ?>
              </td>
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
  <?php } ?>
  <?php } else if ($sub === 'people') { ?>
    <h3>People</h3>
    <p style="color:#666">People management coming soon.</p>
  <?php } else if ($sub === 'organisations') { ?>
    <h3>Organisations</h3>
    <p style="color:#666">Organisation management coming soon.</p>
  <?php } ?>
</section>

<script>
function extractCompany(id, email) {
  // Create a form and submit it
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'dashboard.php?tab=crm&sub=email';
  
  const actionInput = document.createElement('input');
  actionInput.type = 'hidden';
  actionInput.name = 'action';
  actionInput.value = 'extract_company';
  
  const idInput = document.createElement('input');
  idInput.type = 'hidden';
  idInput.name = 'id';
  idInput.value = id;
  
  const emailInput = document.createElement('input');
  emailInput.type = 'hidden';
  emailInput.name = 'email';
  emailInput.value = email;
  
  form.appendChild(actionInput);
  form.appendChild(idInput);
  form.appendChild(emailInput);
  
  document.body.appendChild(form);
  form.submit();
}
</script>


