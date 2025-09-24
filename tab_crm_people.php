<?php
declare(strict_types=1);

// expects $db to be available
?>
<section style="text-align:left; max-width:1024px">
  <h2>CRM - People</h2>
  
  <?php
    // Handle POST requests for crm_people updates
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['tab'] ?? '') === 'crm' && ($_GET['sub'] ?? '') === 'crm_people') {
      $act = (string)($_POST['action'] ?? '');
      
      if ($act === 'update_person') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $companyId = (int)($_POST['company_id'] ?? 0);
        
        if ($id > 0) {
          try {
            // Check if company_id column exists
            $checkColumn = $db->query("PRAGMA table_info(crm_people)");
            $columns = $checkColumn ? $checkColumn->fetchAll(PDO::FETCH_ASSOC) : [];
            $hasCompanyIdColumn = false;
            foreach ($columns as $col) {
              if ($col['name'] === 'company_id') {
                $hasCompanyIdColumn = true;
                break;
              }
            }
            
            if ($hasCompanyIdColumn) {
              if ($name !== '') {
                // Update both name and company
                $st = $db->prepare('UPDATE crm_people SET name = :n, company_id = :c WHERE id = :id');
                $st->execute([':n'=>$name, ':c'=>($companyId > 0 ? $companyId : null), ':id'=>$id]);
                echo '<div style="color:green; margin:8px 0">✓ Person updated successfully</div>';
              } else {
                // Update only company
                $st = $db->prepare('UPDATE crm_people SET company_id = :c WHERE id = :id');
                $st->execute([':c'=>($companyId > 0 ? $companyId : null), ':id'=>$id]);
                if ($companyId > 0) {
                  echo '<div style="color:green; margin:8px 0">✓ Company connection saved</div>';
                } else {
                  echo '<div style="color:green; margin:8px 0">✓ Company connection removed</div>';
                }
              }
            } else {
              if ($name !== '') {
                $st = $db->prepare('UPDATE crm_people SET name = :n WHERE id = :id');
                $st->execute([':n'=>$name, ':id'=>$id]);
                echo '<div style="color:orange; margin:8px 0">Person name updated, but company_id column missing. <a href="setup_db.php">Run database setup</a> to add company connections.</div>';
              } else {
                echo '<div style="color:orange; margin:8px 0">Company connections not available. <a href="setup_db.php">Run database setup</a> to add company connections.</div>';
              }
            }
            
            // Handle email update
            if ($email !== '') {
              // Get current person name to update email record
              $personStmt = $db->prepare('SELECT name FROM crm_people WHERE id = :id');
              $personStmt->execute([':id' => $id]);
              $person = $personStmt->fetch();
              
              if ($person) {
                $currentName = $person['name'];
                // Update or insert email record
                $emailStmt = $db->prepare('INSERT OR REPLACE INTO crm_emails (email, name, created_at) VALUES (:e, :n, :t)');
                $emailStmt->execute([':e' => strtolower($email), ':n' => $currentName, ':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                echo '<div style="color:green; margin:8px 0">✓ Email updated successfully</div>';
              }
            }
          } catch (Throwable $upErr) {
            echo '<div style="color:#c00; margin:8px 0">Error updating person: ' . htmlspecialchars($upErr->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
          }
        }
      }
    }
    
    // Fetch crm_people from database with company info
    $crm_people = [];
    $crm_peopleDebugInfo = '';
    try {
      // Check if company_id column exists
      $checkColumn = $db->query("PRAGMA table_info(crm_people)");
      $columns = $checkColumn ? $checkColumn->fetchAll(PDO::FETCH_ASSOC) : [];
      $hasCompanyIdColumn = false;
      foreach ($columns as $col) {
        if ($col['name'] === 'company_id') {
          $hasCompanyIdColumn = true;
          break;
        }
      }
      
      if ($hasCompanyIdColumn) {
        $crm_peopleStmt = $db->query('SELECT p.id, p.name, p.company_id, p.created_at, c.name as company_name, c.domain as company_domain,
                                          e.email as person_email
                                  FROM crm_people p 
                                  LEFT JOIN crm_organisations c ON p.company_id = c.id 
                                  LEFT JOIN crm_emails e ON p.name = e.name
                                  ORDER BY p.id DESC');
        $crm_people = $crm_peopleStmt ? $crm_peopleStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $crm_peopleDebugInfo = 'Query executed successfully. Found ' . count($crm_people) . ' crm_people. Company_id column exists.';
      } else {
        // Fallback query without company_id
        $crm_peopleStmt = $db->query('SELECT p.id, p.name, p.created_at, e.email as person_email
                                  FROM crm_people p 
                                  LEFT JOIN crm_emails e ON p.name = e.name
                                  ORDER BY p.id DESC');
        $crm_people = $crm_peopleStmt ? $crm_peopleStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $crm_peopleDebugInfo = 'Query executed successfully. Found ' . count($crm_people) . ' crm_people. Company_id column missing - <a href="setup_db.php">run setup</a>.';
      }
    } catch (Throwable $e) {
      $crm_peopleDebugInfo = 'Database error: ' . $e->getMessage();
    }
    
    // Fetch crm_organisations for dropdown
    $crm_organisations = [];
    try {
      $compStmt = $db->query('SELECT id, name, domain FROM crm_organisations ORDER BY name');
      $crm_organisations = $compStmt ? $compStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
      // Ignore errors
    }
  ?>
  
  <div style="background-color: #f8f9fa; padding: 8px; margin: 8px 0; border-radius: 4px; font-size: 12px; color: #666;">
    Debug: <?php echo htmlspecialchars($crm_peopleDebugInfo, ENT_QUOTES, 'UTF-8'); ?>
  </div>
  
  <?php if (empty($crm_people)) { ?>
    <p style="color:#666">No crm_people yet. Add some names from the Email tab.</p>
  <?php } else { ?>
    <div style="overflow:auto; border:1px solid #ddd; border-radius:6px">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr style="background-color: #f8f9fa;">
            <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">ID</th>
            <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Name</th>
            <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Email</th>
            <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Company</th>
            <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Created</th>
            <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($crm_people as $person) { ?>
            <tr>
              <td style="padding: 12px; border: 1px solid #ddd;"><?php echo (int)$person['id']; ?></td>
              <td style="padding: 12px; border: 1px solid #ddd;">
                <form action="dashboard.php?tab=crm&sub=crm_people" method="post" style="display: flex; gap: 8px; align-items: center;">
                  <input type="hidden" name="action" value="update_person" />
                  <input type="hidden" name="id" value="<?php echo (int)$person['id']; ?>" />
                  <input type="hidden" name="company_id" value="<?php echo (int)($person['company_id'] ?? 0); ?>" />
                  <input type="text" name="name" value="<?php echo htmlspecialchars((string)$person['name'], ENT_QUOTES, 'UTF-8'); ?>" 
                         style="min-width: 200px; padding: 6px; border: 1px solid #ccc; border-radius: 4px;" required />
                  <button type="submit" style="padding: 6px 12px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Save Name</button>
                </form>
              </td>
              <td style="padding: 12px; border: 1px solid #ddd;">
                <form action="dashboard.php?tab=crm&sub=crm_people" method="post" style="display: flex; gap: 8px; align-items: center;">
                  <input type="hidden" name="action" value="update_person" />
                  <input type="hidden" name="id" value="<?php echo (int)$person['id']; ?>" />
                  <input type="hidden" name="name" value="<?php echo htmlspecialchars((string)$person['name'], ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="company_id" value="<?php echo (int)($person['company_id'] ?? 0); ?>" />
                  <input type="email" name="email" value="<?php echo htmlspecialchars((string)($person['person_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" 
                         style="min-width: 200px; padding: 6px; border: 1px solid #ccc; border-radius: 4px;" placeholder="Enter email address" />
                  <button type="submit" style="padding: 6px 12px; background-color: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;">Save Email</button>
                </form>
              </td>
              <td style="padding: 12px; border: 1px solid #ddd;">
                <?php if ($hasCompanyIdColumn) { ?>
                  <form action="dashboard.php?tab=crm&sub=crm_people" method="post" style="display: flex; gap: 8px; align-items: center;">
                    <input type="hidden" name="action" value="update_person" />
                    <input type="hidden" name="id" value="<?php echo (int)$person['id']; ?>" />
                    <input type="hidden" name="name" value="<?php echo htmlspecialchars((string)$person['name'], ENT_QUOTES, 'UTF-8'); ?>" />
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars((string)($person['person_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                    <select name="company_id" onchange="this.form.submit()" style="min-width: 200px; padding: 6px; border: 1px solid #ccc; border-radius: 4px;">
                      <option value="0">-- No Company --</option>
                      <?php foreach ($crm_organisations as $company) { ?>
                        <option value="<?php echo (int)$company['id']; ?>" 
                                <?php echo ((int)$person['company_id'] === (int)$company['id']) ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string)$company['domain'], ENT_QUOTES, 'UTF-8'); ?>)
                        </option>
                      <?php } ?>
                    </select>
                  </form>
                <?php } else { ?>
                  <span style="color: #999; font-size: 12px;">Company connections not available<br><a href="setup_db.php" style="font-size: 10px;">Run database setup</a></span>
                <?php } ?>
              </td>
              <td style="padding: 12px; border: 1px solid #ddd;"><?php echo htmlspecialchars((string)($person['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
              <td style="padding: 12px; border: 1px solid #ddd;">
                <span style="color: #28a745; font-size: 12px;">✓ Active</span>
              </td>
            </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
  <?php } ?>
</section>
