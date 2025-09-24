<?php
declare(strict_types=1);

// expects $db to be available
?>
<section style="text-align:left; max-width:1024px">
  <h2>CRM</h2>
  <nav style="display:flex; gap:10px; margin: 8px 0 16px">
    <a href="dashboard.php?tab=crm&sub=email" style="text-decoration:none; padding:6px 10px; border:1px solid #ddd; border-radius:6px">Email</a>
    <a href="dashboard.php?tab=crm&sub=crm_people" style="text-decoration:none; padding:6px 10px; border:1px solid #ddd; border-radius:6px">People</a>
    <a href="dashboard.php?tab=crm&sub=organisations" style="text-decoration:none; padding:6px 10px; border:1px solid #ddd; border-radius:6px">Organisations</a>
  </nav>
  
  <?php
    $sub = isset($_GET['sub']) ? (string)$_GET['sub'] : 'email';
    
    if ($sub === 'email') {
      // Handle POST requests for email updates
      if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['tab'] ?? '') === 'crm' && ($_GET['sub'] ?? '') === 'email') {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'update_email') {
          $id = (int)($_POST['id'] ?? 0);
          $email = strtolower(trim((string)($_POST['email'] ?? '')));
          $name = trim((string)($_POST['name'] ?? ''));
          if ($id > 0 && $email !== '') {
            try {
              $st = $db->prepare('UPDATE crm_emails SET email = :e, name = :n WHERE id = :id');
              $st->execute([':e'=>$email, ':n'=>($name !== '' ? $name : null), ':id'=>$id]);
              echo '<div style="color:green; margin:8px 0">Saved</div>';
            } catch (Throwable $upErr) {
              echo '<div style="color:#c00; margin:8px 0">Error saving: ' . htmlspecialchars($upErr->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
            }
          }
        } else if ($act === 'add_company') {
          $domain = trim((string)($_POST['domain'] ?? ''));
          if ($domain !== '') {
            try {
              // Check if company already exists
              $compStmt = $db->prepare('SELECT id FROM crm_organisations WHERE domain = :d');
              $compStmt->execute([':d' => $domain]);
              $existing = $compStmt->fetch();
              
              if (!$existing) {
                // Add new company
                $insComp = $db->prepare('INSERT INTO crm_organisations (domain, name, created_at) VALUES (:d, :n, :t)');
                $insComp->execute([':d' => $domain, ':n' => $domain, ':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                echo '<div style="color:green; margin:8px 0">Company added: ' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . '</div>';
              } else {
                echo '<div style="color:orange; margin:8px 0">Company already exists: ' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') . '</div>';
              }
            } catch (Throwable $e) {
              echo '<div style="color:#c00; margin:8px 0">Error adding company: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
            }
          }
        } else if ($act === 'add_person') {
          $name = trim((string)($_POST['name'] ?? ''));
          $email = trim((string)($_POST['email'] ?? ''));
          $companyId = (int)($_POST['company_id'] ?? 0);
          
          if ($name !== '') {
            try {
              // Check if person already exists
              $personStmt = $db->prepare('SELECT id FROM crm_people WHERE name = :n');
              $personStmt->execute([':n' => $name]);
              $existing = $personStmt->fetch();
              
              if (!$existing) {
                // Check if company_id column exists in crm_people table
                $checkColumn = $db->query("PRAGMA table_info(crm_people)");
                $columns = $checkColumn ? $checkColumn->fetchAll(PDO::FETCH_ASSOC) : [];
                $hasCompanyIdColumn = false;
                foreach ($columns as $col) {
                  if ($col['name'] === 'company_id') {
                    $hasCompanyIdColumn = true;
                    break;
                  }
                }
                
                // Add new person with company connection if available
                if ($hasCompanyIdColumn) {
                  $insPerson = $db->prepare('INSERT INTO crm_people (name, company_id, created_at) VALUES (:n, :c, :t)');
                  $insPerson->execute([':n' => $name, ':c' => ($companyId > 0 ? $companyId : null), ':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                } else {
                  $insPerson = $db->prepare('INSERT INTO crm_people (name, created_at) VALUES (:n, :t)');
                  $insPerson->execute([':n' => $name, ':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                }
                
                // Also add email to crm_emails table if provided
                if ($email !== '') {
                  $db->prepare('INSERT OR IGNORE INTO crm_emails (email, name, created_at) VALUES (:e, :n, :t)')
                     ->execute([':e' => strtolower($email), ':n' => $name, ':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                }
                
                $message = 'Person added: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                if ($email !== '') {
                  $message .= ' with email: ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
                }
                if ($companyId > 0) {
                  $message .= ' and company connection';
                }
                echo '<div style="color:green; margin:8px 0">' . $message . '</div>';
              } else {
                echo '<div style="color:orange; margin:8px 0">Person already exists: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>';
              }
            } catch (Throwable $e) {
              echo '<div style="color:#c00; margin:8px 0">Error adding person: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
            }
          }
        }
      }
      
      // Fetch crm_emails from database
      $rows = [];
      $debugInfo = '';
      try {
        $emStmt = $db->query('SELECT e.id, e.email, e.name FROM crm_emails e ORDER BY e.id DESC');
        $rows = $emStmt ? $emStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $debugInfo = 'Query executed successfully. Found ' . count($rows) . ' rows.';
      } catch (Throwable $e) {
        $debugInfo = 'Database error: ' . $e->getMessage();
      }
  ?>
  
  <h3>Email (Contacts)</h3>
  <div style="background-color: #f8f9fa; padding: 8px; margin: 8px 0; border-radius: 4px; font-size: 12px; color: #666;">
    Debug: <?php echo htmlspecialchars($debugInfo, ENT_QUOTES, 'UTF-8'); ?>
  </div>
  
  <?php if (empty($rows)) { ?>
    <p style="color:#666">No contacts yet. Fetch some crm_emails in INBOX.</p>
  <?php } else { ?>
    <div style="overflow:auto; border:1px solid #ddd; border-radius:6px">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Email</th>
            <th>Domain</th>
            <th>Name</th>
            <th>People</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $em) { 
            // Extract domain from email
            $domain = '';
            $email = (string)$em['email'];
            if (strpos($email, '@') !== false) {
              $domain = strtolower(trim(substr($email, strpos($email, '@') + 1)));
            }
          ?>
            <tr>
              <td><?php echo (int)$em['id']; ?></td>
              <td>
                <form action="dashboard.php?tab=crm&sub=email" method="post" style="display:flex; gap:6px; align-items:center">
                  <input type="hidden" name="action" value="update_email" />
                  <input type="hidden" name="id" value="<?php echo (int)$em['id']; ?>" />
                  <input type="text" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" style="min-width:260px" required />
                  <input type="text" name="name" value="<?php echo htmlspecialchars((string)($em['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="min-width:200px" />
                  <button type="submit">Save</button>
                </form>
              </td>
              <td>
                <span style="background-color: #e9ecef; color: #495057; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-family: monospace;">
                  <?php echo htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <?php if ($domain !== '') { 
                  // Check if domain already exists in crm_organisations table
                  $companyExists = false;
                  try {
                    $compStmt = $db->prepare('SELECT id FROM crm_organisations WHERE domain = :d');
                    $compStmt->execute([':d' => $domain]);
                    $companyExists = $compStmt->fetch() !== false;
                  } catch (Throwable $e) {
                    // Ignore errors
                  }
                ?>
                  <button type="button" 
                          onclick="addCompany(<?php echo (int)$em['id']; ?>, '<?php echo htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'); ?>')"
                          style="margin-left: 8px; padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 11px; cursor: pointer; background-color: <?php echo $companyExists ? '#d4edda' : '#f8f9fa'; ?>; color: <?php echo $companyExists ? '#155724' : '#6c757d'; ?>;"
                          id="company-btn-<?php echo (int)$em['id']; ?>">
                    <?php echo $companyExists ? '✓ Added' : '+ Add'; ?>
                  </button>
                <?php } ?>
              </td>
              <td><?php echo htmlspecialchars((string)($em['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <?php 
                  $name = trim((string)($em['name'] ?? ''));
                  if ($name !== '') {
                    // Check if person already exists in crm_people table
                    $personExists = false;
                    try {
                      $personStmt = $db->prepare('SELECT id FROM crm_people WHERE name = :n');
                      $personStmt->execute([':n' => $name]);
                      $personExists = $personStmt->fetch() !== false;
                    } catch (Throwable $e) {
                      // Ignore errors
                    }
                    
                    // Get company_id if domain exists in crm_organisations table
                    $companyId = 0;
                    if ($domain !== '') {
                      try {
                        $compStmt = $db->prepare('SELECT id FROM crm_organisations WHERE domain = :d');
                        $compStmt->execute([':d' => $domain]);
                        $company = $compStmt->fetch();
                        if ($company) {
                          $companyId = (int)$company['id'];
                        }
                      } catch (Throwable $e) {
                        // Ignore errors
                      }
                    }
                ?>
                  <button type="button" 
                          onclick="addPerson(<?php echo (int)$em['id']; ?>, '<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>', <?php echo $companyId; ?>)"
                          style="padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 11px; cursor: pointer; background-color: <?php echo $personExists ? '#d4edda' : '#f8f9fa'; ?>; color: <?php echo $personExists ? '#155724' : '#6c757d'; ?>;"
                          id="person-btn-<?php echo (int)$em['id']; ?>">
                    <?php echo $personExists ? '✓ Added' : '+ Add'; ?>
                  </button>
                <?php } else { ?>
                  <span style="color: #999; font-size: 11px;">No name</span>
                <?php } ?>
              </td>
              <td></td>
            </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
  <?php } ?>
  
  <?php } else if ($sub === 'crm_people') { 
    include __DIR__ . '/tab_crm_people.php';
  ?>
  <?php } else if ($sub === 'organisations') { 
    // Handle POST requests for company updates
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['tab'] ?? '') === 'crm' && ($_GET['sub'] ?? '') === 'organisations') {
      $act = (string)($_POST['action'] ?? '');
      
      if ($act === 'update_company') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $importance = trim((string)($_POST['importance'] ?? ''));
        $registryCode = trim((string)($_POST['registry_code'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        
        if ($id > 0) {
          try {
            // Check if new columns exist, if not add them
            $checkColumn = $db->query("PRAGMA table_info(crm_organisations)");
            $columns = $checkColumn ? $checkColumn->fetchAll(PDO::FETCH_ASSOC) : [];
            $existingColumns = array_column($columns, 'name');
            
            $newColumns = [
              'full_name' => 'TEXT',
              'importance' => 'TEXT',
              'registry_code' => 'TEXT',
              'address' => 'TEXT',
              'logo_path' => 'TEXT'
            ];
            
            foreach ($newColumns as $colName => $colType) {
              if (!in_array($colName, $existingColumns)) {
                $db->exec("ALTER TABLE crm_organisations ADD COLUMN {$colName} {$colType}");
              }
            }
            
            // Update company information
            $st = $db->prepare('UPDATE crm_organisations SET name = :n, full_name = :fn, importance = :imp, registry_code = :rc, address = :addr WHERE id = :id');
            $st->execute([
              ':n' => $name,
              ':fn' => $fullName,
              ':imp' => $importance,
              ':rc' => $registryCode,
              ':addr' => $address,
              ':id' => $id
            ]);
            
            echo '<div style="color:green; margin:8px 0">✓ Company information updated successfully</div>';
          } catch (Throwable $upErr) {
            echo '<div style="color:#c00; margin:8px 0">Error updating company: ' . htmlspecialchars($upErr->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
          }
        }
      }
    }
    
    // Fetch crm_organisations from database with connected crm_people
    $crm_organisations = [];
    $companyDebugInfo = '';
    try {
      // Check if company_id column exists in crm_people table
      $checkColumn = $db->query("PRAGMA table_info(crm_people)");
      $columns = $checkColumn ? $checkColumn->fetchAll(PDO::FETCH_ASSOC) : [];
      $hasCompanyIdColumn = false;
      foreach ($columns as $col) {
        if ($col['name'] === 'company_id') {
          $hasCompanyIdColumn = true;
          break;
        }
      }
      
      // First check if new columns exist in crm_organisations table
      $checkCompanyColumn = $db->query("PRAGMA table_info(crm_organisations)");
      $companyColumns = $checkCompanyColumn ? $checkCompanyColumn->fetchAll(PDO::FETCH_ASSOC) : [];
      $existingCompanyColumns = array_column($companyColumns, 'name');
      
      $hasNewColumns = in_array('full_name', $existingCompanyColumns);
      
      if ($hasCompanyIdColumn) {
        if ($hasNewColumns) {
          $compStmt = $db->query('SELECT c.id, c.domain, c.name, c.full_name, c.importance, c.registry_code, c.address, c.logo_path, c.created_at, 
                                         GROUP_CONCAT(p.name, ", ") as connected_crm_people,
                                         COUNT(p.id) as crm_people_count
                                  FROM crm_organisations c 
                                  LEFT JOIN crm_people p ON c.id = p.company_id 
                                  GROUP BY c.id, c.domain, c.name, c.full_name, c.importance, c.registry_code, c.address, c.logo_path, c.created_at
                                  ORDER BY c.id DESC');
        } else {
          $compStmt = $db->query('SELECT c.id, c.domain, c.name, c.created_at, 
                                         GROUP_CONCAT(p.name, ", ") as connected_crm_people,
                                         COUNT(p.id) as crm_people_count
                                  FROM crm_organisations c 
                                  LEFT JOIN crm_people p ON c.id = p.company_id 
                                  GROUP BY c.id, c.domain, c.name, c.created_at
                                  ORDER BY c.id DESC');
        }
        $crm_organisations = $compStmt ? $compStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $companyDebugInfo = 'Query executed successfully. Found ' . count($crm_organisations) . ' crm_organisations with crm_people connections.';
      } else {
        if ($hasNewColumns) {
          $compStmt = $db->query('SELECT id, domain, name, full_name, importance, registry_code, address, logo_path, created_at FROM crm_organisations ORDER BY id DESC');
        } else {
          $compStmt = $db->query('SELECT id, domain, name, created_at FROM crm_organisations ORDER BY id DESC');
        }
        $crm_organisations = $compStmt ? $compStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $companyDebugInfo = 'Query executed successfully. Found ' . count($crm_organisations) . ' crm_organisations. Company connections not available - <a href="setup_db.php">run setup</a>.';
      }
    } catch (Throwable $e) {
      $companyDebugInfo = 'Database error: ' . $e->getMessage();
    }
  ?>
    <h3>Organisations</h3>
    <div style="background-color: #f8f9fa; padding: 8px; margin: 8px 0; border-radius: 4px; font-size: 12px; color: #666;">
      Debug: <?php echo htmlspecialchars($companyDebugInfo, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    
    <?php if (empty($crm_organisations)) { ?>
      <p style="color:#666">No organisations yet. Add some crm_organisations from the Email tab.</p>
    <?php } else { ?>
      <div style="overflow:auto; border:1px solid #ddd; border-radius:6px">
        <table style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="background-color: #f8f9fa;">
              <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">ID</th>
              <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Domain</th>
              <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Name</th>
              <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Full Name</th>
              <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Importance</th>
              <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Registry Code</th>
              <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Address</th>
              <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Connected People</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($crm_organisations as $company) { ?>
              <tr>
                <td style="padding: 12px; border: 1px solid #ddd;"><?php echo (int)$company['id']; ?></td>
                <td style="padding: 12px; border: 1px solid #ddd;">
                  <span style="background-color: #e9ecef; color: #495057; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-family: monospace;">
                    <?php echo htmlspecialchars((string)$company['domain'], ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                </td>
                <td style="padding: 12px; border: 1px solid #ddd;">
                  <form action="dashboard.php?tab=crm&sub=organisations" method="post" style="display: flex; gap: 8px; align-items: center;">
                    <input type="hidden" name="action" value="update_company" />
                    <input type="hidden" name="id" value="<?php echo (int)$company['id']; ?>" />
                    <input type="text" name="name" value="<?php echo htmlspecialchars((string)($company['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" 
                           style="min-width: 120px; padding: 6px; border: 1px solid #ccc; border-radius: 4px;" />
                    <button type="submit" style="padding: 6px 12px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Save</button>
                  </form>
                </td>
                <td style="padding: 12px; border: 1px solid #ddd;">
                  <?php if ($hasNewColumns) { ?>
                    <form action="dashboard.php?tab=crm&sub=organisations" method="post" style="display: flex; gap: 8px; align-items: center;">
                      <input type="hidden" name="action" value="update_company" />
                      <input type="hidden" name="id" value="<?php echo (int)$company['id']; ?>" />
                      <input type="hidden" name="name" value="<?php echo htmlspecialchars((string)($company['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="importance" value="<?php echo htmlspecialchars((string)($company['importance'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="registry_code" value="<?php echo htmlspecialchars((string)($company['registry_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="address" value="<?php echo htmlspecialchars((string)($company['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="text" name="full_name" value="<?php echo htmlspecialchars((string)($company['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" 
                             style="min-width: 150px; padding: 6px; border: 1px solid #ccc; border-radius: 4px;" />
                      <button type="submit" style="padding: 6px 12px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Save</button>
                    </form>
                  <?php } else { ?>
                    <span style="color: #999; font-size: 12px;">Update any field to add new columns</span>
                  <?php } ?>
                </td>
                <td style="padding: 12px; border: 1px solid #ddd;">
                  <?php if ($hasNewColumns) { ?>
                    <form action="dashboard.php?tab=crm&sub=organisations" method="post" style="display: flex; gap: 8px; align-items: center;">
                      <input type="hidden" name="action" value="update_company" />
                      <input type="hidden" name="id" value="<?php echo (int)$company['id']; ?>" />
                      <input type="hidden" name="name" value="<?php echo htmlspecialchars((string)($company['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="full_name" value="<?php echo htmlspecialchars((string)($company['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="registry_code" value="<?php echo htmlspecialchars((string)($company['registry_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="address" value="<?php echo htmlspecialchars((string)($company['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <select name="importance" onchange="this.form.submit()" style="min-width: 100px; padding: 6px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="">-- Select --</option>
                        <option value="High" <?php echo (($company['importance'] ?? '') === 'High') ? 'selected' : ''; ?>>High</option>
                        <option value="Medium" <?php echo (($company['importance'] ?? '') === 'Medium') ? 'selected' : ''; ?>>Medium</option>
                        <option value="Low" <?php echo (($company['importance'] ?? '') === 'Low') ? 'selected' : ''; ?>>Low</option>
                      </select>
                    </form>
                  <?php } else { ?>
                    <span style="color: #999; font-size: 12px;">Update any field to add new columns</span>
                  <?php } ?>
                </td>
                <td style="padding: 12px; border: 1px solid #ddd;">
                  <?php if ($hasNewColumns) { ?>
                    <form action="dashboard.php?tab=crm&sub=organisations" method="post" style="display: flex; gap: 8px; align-items: center;">
                      <input type="hidden" name="action" value="update_company" />
                      <input type="hidden" name="id" value="<?php echo (int)$company['id']; ?>" />
                      <input type="hidden" name="name" value="<?php echo htmlspecialchars((string)($company['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="full_name" value="<?php echo htmlspecialchars((string)($company['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="importance" value="<?php echo htmlspecialchars((string)($company['importance'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="address" value="<?php echo htmlspecialchars((string)($company['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="text" name="registry_code" value="<?php echo htmlspecialchars((string)($company['registry_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" 
                             style="min-width: 120px; padding: 6px; border: 1px solid #ccc; border-radius: 4px;" />
                      <button type="submit" style="padding: 6px 12px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Save</button>
                    </form>
                  <?php } else { ?>
                    <span style="color: #999; font-size: 12px;">Update any field to add new columns</span>
                  <?php } ?>
                </td>
                <td style="padding: 12px; border: 1px solid #ddd;">
                  <?php if ($hasNewColumns) { ?>
                    <form action="dashboard.php?tab=crm&sub=organisations" method="post" style="display: flex; gap: 8px; align-items: center;">
                      <input type="hidden" name="action" value="update_company" />
                      <input type="hidden" name="id" value="<?php echo (int)$company['id']; ?>" />
                      <input type="hidden" name="name" value="<?php echo htmlspecialchars((string)($company['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="full_name" value="<?php echo htmlspecialchars((string)($company['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="importance" value="<?php echo htmlspecialchars((string)($company['importance'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <input type="hidden" name="registry_code" value="<?php echo htmlspecialchars((string)($company['registry_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                      <textarea name="address" style="min-width: 200px; min-height: 60px; padding: 6px; border: 1px solid #ccc; border-radius: 4px; resize: vertical;"><?php echo htmlspecialchars((string)($company['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                      <button type="submit" style="padding: 6px 12px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Save</button>
                    </form>
                  <?php } else { ?>
                    <span style="color: #999; font-size: 12px;">Update any field to add new columns</span>
                  <?php } ?>
                </td>
                <td style="padding: 12px; border: 1px solid #ddd;">
                  <?php if ($hasCompanyIdColumn && isset($company['connected_crm_people']) && $company['connected_crm_people'] !== null) { 
                    $crm_peopleCount = (int)($company['crm_people_count'] ?? 0);
                    $crm_peopleList = (string)$company['connected_crm_people'];
                  ?>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                      <div style="font-size: 12px; color: #495057; max-width: 250px; word-wrap: break-word; line-height: 1.4;">
                        <?php 
                          // Split the comma-separated names and display each on a new line
                          $names = explode(', ', $crm_peopleList);
                          foreach ($names as $index => $name) {
                            $name = trim($name);
                            if ($name !== '') {
                              echo '<div style="margin-bottom: 2px;">';
                              echo '<span style="background-color: #e3f2fd; color: #1565c0; padding: 2px 6px; border-radius: 3px; font-size: 11px; display: inline-block;">';
                              echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                              echo '</span>';
                              echo '</div>';
                            }
                          }
                        ?>
                      </div>
                    </div>
                  <?php } else { ?>
                    <span style="color: #999; font-size: 12px;">No connections</span>
                  <?php } ?>
                </td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  <?php } ?>
</section>

<script>
function addCompany(id, domain) {
  // Create a form and submit it
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'dashboard.php?tab=crm&sub=email';
  
  const actionInput = document.createElement('input');
  actionInput.type = 'hidden';
  actionInput.name = 'action';
  actionInput.value = 'add_company';
  
  const domainInput = document.createElement('input');
  domainInput.type = 'hidden';
  domainInput.name = 'domain';
  domainInput.value = domain;
  
  form.appendChild(actionInput);
  form.appendChild(domainInput);
  
  document.body.appendChild(form);
  form.submit();
}

    function addPerson(id, name, email, companyId) {
      // Create a form and submit it
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = 'dashboard.php?tab=crm&sub=email';
      
      const actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      actionInput.value = 'add_person';
      
      const nameInput = document.createElement('input');
      nameInput.type = 'hidden';
      nameInput.name = 'name';
      nameInput.value = name;
      
      const emailInput = document.createElement('input');
      emailInput.type = 'hidden';
      emailInput.name = 'email';
      emailInput.value = email || '';
      
      const companyInput = document.createElement('input');
      companyInput.type = 'hidden';
      companyInput.name = 'company_id';
      companyInput.value = companyId || 0;
      
      form.appendChild(actionInput);
      form.appendChild(nameInput);
      form.appendChild(emailInput);
      form.appendChild(companyInput);
      
      document.body.appendChild(form);
      form.submit();
    }

</script>