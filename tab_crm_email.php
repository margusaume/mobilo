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
      // Handle POST requests for email updates
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
        } else if ($act === 'add_company') {
          $domain = trim((string)($_POST['domain'] ?? ''));
          if ($domain !== '') {
            try {
              // Check if company already exists
              $compStmt = $db->prepare('SELECT id FROM companies WHERE domain = :d');
              $compStmt->execute([':d' => $domain]);
              $existing = $compStmt->fetch();
              
              if (!$existing) {
                // Add new company
                $insComp = $db->prepare('INSERT INTO companies (domain, name, created_at) VALUES (:d, :n, :t)');
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
          if ($name !== '') {
            try {
              // Check if person already exists
              $personStmt = $db->prepare('SELECT id FROM people WHERE name = :n');
              $personStmt->execute([':n' => $name]);
              $existing = $personStmt->fetch();
              
              if (!$existing) {
                // Add new person
                $insPerson = $db->prepare('INSERT INTO people (name, created_at) VALUES (:n, :t)');
                $insPerson->execute([':n' => $name, ':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                echo '<div style="color:green; margin:8px 0">Person added: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>';
              } else {
                echo '<div style="color:orange; margin:8px 0">Person already exists: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>';
              }
            } catch (Throwable $e) {
              echo '<div style="color:#c00; margin:8px 0">Error adding person: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
            }
          }
        } else if ($act === 'update_person') {
          $id = (int)($_POST['id'] ?? 0);
          $name = trim((string)($_POST['name'] ?? ''));
          $companyId = (int)($_POST['company_id'] ?? 0);
          
          // Debug info
          echo '<div style="background-color: #f8f9fa; padding: 8px; margin: 8px 0; border-radius: 4px; font-size: 12px; color: #666;">';
          echo 'Debug: ID=' . $id . ', Name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '", CompanyID=' . $companyId;
          echo '</div>';
          
          if ($id > 0) {
            try {
              // First check if company_id column exists
              $checkColumn = $db->query("PRAGMA table_info(people)");
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
                  $st = $db->prepare('UPDATE people SET name = :n, company_id = :c WHERE id = :id');
                  $st->execute([':n'=>$name, ':c'=>($companyId > 0 ? $companyId : null), ':id'=>$id]);
                } else {
                  // Update only company
                  $st = $db->prepare('UPDATE people SET company_id = :c WHERE id = :id');
                  $st->execute([':c'=>($companyId > 0 ? $companyId : null), ':id'=>$id]);
                }
                
                if ($companyId > 0) {
                  echo '<div style="color:green; margin:8px 0">✓ Company connection saved</div>';
                } else {
                  echo '<div style="color:green; margin:8px 0">✓ Company connection removed</div>';
                }
              } else {
                if ($name !== '') {
                  // Fallback: update only name if company_id column doesn't exist
                  $st = $db->prepare('UPDATE people SET name = :n WHERE id = :id');
                  $st->execute([':n'=>$name, ':id'=>$id]);
                  echo '<div style="color:orange; margin:8px 0">Person name updated, but company_id column missing. <a href="setup_db.php">Run database setup</a> to add company connections.</div>';
                } else {
                  echo '<div style="color:orange; margin:8px 0">Company connections not available. <a href="setup_db.php">Run database setup</a> to add company connections.</div>';
                }
              }
            } catch (Throwable $upErr) {
              echo '<div style="color:#c00; margin:8px 0">Error updating person: ' . htmlspecialchars($upErr->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
            }
          }
        }
      }
      
      // Fetch emails from database
      $rows = [];
      $debugInfo = '';
      try {
        $emStmt = $db->query('SELECT e.id, e.email, e.name FROM emails e ORDER BY e.id DESC');
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
    <p style="color:#666">No contacts yet. Fetch some emails in INBOX.</p>
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
                  // Check if domain already exists in companies table
                  $companyExists = false;
                  try {
                    $compStmt = $db->prepare('SELECT id FROM companies WHERE domain = :d');
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
                    // Check if person already exists in people table
                    $personExists = false;
                    try {
                      $personStmt = $db->prepare('SELECT id FROM people WHERE name = :n');
                      $personStmt->execute([':n' => $name]);
                      $personExists = $personStmt->fetch() !== false;
                    } catch (Throwable $e) {
                      // Ignore errors
                    }
                ?>
                  <button type="button" 
                          onclick="addPerson(<?php echo (int)$em['id']; ?>, '<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>')"
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
  
  <?php } else if ($sub === 'people') { 
    // Fetch people from database with company info
    $people = [];
    $peopleDebugInfo = '';
    try {
      // First check if company_id column exists
      $checkColumn = $db->query("PRAGMA table_info(people)");
      $columns = $checkColumn ? $checkColumn->fetchAll(PDO::FETCH_ASSOC) : [];
      $hasCompanyIdColumn = false;
      foreach ($columns as $col) {
        if ($col['name'] === 'company_id') {
          $hasCompanyIdColumn = true;
          break;
        }
      }
      
      if ($hasCompanyIdColumn) {
        $peopleStmt = $db->query('SELECT p.id, p.name, p.company_id, p.created_at, c.name as company_name, c.domain as company_domain 
                                  FROM people p 
                                  LEFT JOIN companies c ON p.company_id = c.id 
                                  ORDER BY p.id DESC');
        $people = $peopleStmt ? $peopleStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $peopleDebugInfo = 'Query executed successfully. Found ' . count($people) . ' people. Company_id column exists.';
      } else {
        // Fallback query without company_id
        $peopleStmt = $db->query('SELECT id, name, created_at FROM people ORDER BY id DESC');
        $people = $peopleStmt ? $peopleStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $peopleDebugInfo = 'Query executed successfully. Found ' . count($people) . ' people. Company_id column missing - <a href="setup_db.php">run setup</a>.';
      }
    } catch (Throwable $e) {
      $peopleDebugInfo = 'Database error: ' . $e->getMessage();
    }
    
    // Fetch companies for dropdown
    $companies = [];
    try {
      $compStmt = $db->query('SELECT id, name, domain FROM companies ORDER BY name');
      $companies = $compStmt ? $compStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
      // Ignore errors
    }
  ?>
    <h3>People</h3>
    <div style="background-color: #f8f9fa; padding: 8px; margin: 8px 0; border-radius: 4px; font-size: 12px; color: #666;">
      Debug: <?php echo htmlspecialchars($peopleDebugInfo, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    
    <?php if (empty($people)) { ?>
      <p style="color:#666">No people yet. Add some names from the Email tab.</p>
    <?php } else { ?>
      <div style="overflow:auto; border:1px solid #ddd; border-radius:6px">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Company</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($people as $person) { ?>
              <tr>
                <td><?php echo (int)$person['id']; ?></td>
                <td>
                  <input type="text" name="name_<?php echo (int)$person['id']; ?>" value="<?php echo htmlspecialchars((string)$person['name'], ENT_QUOTES, 'UTF-8'); ?>" style="min-width:200px" required />
                </td>
                <td>
                  <?php if ($hasCompanyIdColumn) { ?>
                    <div style="position: relative; min-width:200px">
                      <input type="text" id="company_search_<?php echo (int)$person['id']; ?>" 
                             placeholder="Search companies..." 
                             style="width: 100%; padding: 4px; border: 1px solid #ccc; border-radius: 4px;"
                             onkeyup="filterCompanies(<?php echo (int)$person['id']; ?>)"
                             onfocus="showCompanyDropdown(<?php echo (int)$person['id']; ?>)"
                             onblur="setTimeout(() => hideCompanyDropdown(<?php echo (int)$person['id']; ?>), 200)"
                             value="<?php 
                               if ((int)$person['company_id'] > 0) {
                                 echo htmlspecialchars((string)($person['company_name'] ?? '') . ' (' . (string)($person['company_domain'] ?? '') . ')', ENT_QUOTES, 'UTF-8');
                               }
                             ?>" />
                      <div id="company_dropdown_<?php echo (int)$person['id']; ?>" 
                           style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ccc; border-top: none; max-height: 200px; overflow-y: auto; z-index: 1000;">
                        <div class="company-option" data-id="0" data-name="-- No Company --" onclick="selectAndSaveCompany(<?php echo (int)$person['id']; ?>, 0, '-- No Company --')" 
                             style="padding: 8px; cursor: pointer; <?php echo ((int)$person['company_id'] === 0) ? 'background-color: #f0f0f0;' : ''; ?>">
                          -- No Company --
                        </div>
                        <?php foreach ($companies as $company) { ?>
                          <div class="company-option" data-id="<?php echo (int)$company['id']; ?>" data-name="<?php echo htmlspecialchars((string)$company['name'] . ' (' . (string)$company['domain'] . ')', ENT_QUOTES, 'UTF-8'); ?>" 
                               onclick="selectAndSaveCompany(<?php echo (int)$person['id']; ?>, <?php echo (int)$company['id']; ?>, '<?php echo htmlspecialchars((string)$company['name'] . ' (' . (string)$company['domain'] . ')', ENT_QUOTES, 'UTF-8'); ?>')"
                               style="padding: 8px; cursor: pointer; <?php echo ((int)$person['company_id'] === (int)$company['id']) ? 'background-color: #f0f0f0;' : ''; ?>">
                            <?php echo htmlspecialchars((string)$company['name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string)$company['domain'], ENT_QUOTES, 'UTF-8'); ?>)
                          </div>
                        <?php } ?>
                      </div>
                      <input type="hidden" id="selected_company_<?php echo (int)$person['id']; ?>" value="<?php echo (int)$person['company_id']; ?>" />
                    </div>
                  <?php } else { ?>
                    <span style="color: #999; font-size: 12px;">Company connections not available<br><a href="setup_db.php" style="font-size: 10px;">Run database setup</a></span>
                  <?php } ?>
                </td>
                <td><?php echo htmlspecialchars((string)($person['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <form action="dashboard.php?tab=crm&sub=people" method="post" style="display:inline">
                    <input type="hidden" name="action" value="update_person" />
                    <input type="hidden" name="id" value="<?php echo (int)$person['id']; ?>" />
                    <input type="hidden" name="name" id="save_name_<?php echo (int)$person['id']; ?>" value="" />
                    <button type="submit" onclick="prepareSave(<?php echo (int)$person['id']; ?>)">Save Name</button>
                  </form>
                  <span id="company_status_<?php echo (int)$person['id']; ?>" 
                        style="font-size: 12px; margin-left: 8px; padding: 2px 6px; border-radius: 3px; <?php echo ((int)$person['company_id'] > 0) ? 'background-color: #d4edda; color: #155724;' : 'background-color: #f8f9fa; color: #6c757d;'; ?>">
                    <?php echo ((int)$person['company_id'] > 0) ? '✓ Connected' : 'No Company'; ?>
                  </span>
                </td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  <?php } else if ($sub === 'organisations') { 
    // Fetch companies from database with connected people
    $companies = [];
    $companyDebugInfo = '';
    try {
      // Check if company_id column exists in people table
      $checkColumn = $db->query("PRAGMA table_info(people)");
      $columns = $checkColumn ? $checkColumn->fetchAll(PDO::FETCH_ASSOC) : [];
      $hasCompanyIdColumn = false;
      foreach ($columns as $col) {
        if ($col['name'] === 'company_id') {
          $hasCompanyIdColumn = true;
          break;
        }
      }
      
      if ($hasCompanyIdColumn) {
        $compStmt = $db->query('SELECT c.id, c.domain, c.name, c.created_at, 
                                       GROUP_CONCAT(p.name, ", ") as connected_people,
                                       COUNT(p.id) as people_count
                                FROM companies c 
                                LEFT JOIN people p ON c.id = p.company_id 
                                GROUP BY c.id, c.domain, c.name, c.created_at
                                ORDER BY c.id DESC');
        $companies = $compStmt ? $compStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $companyDebugInfo = 'Query executed successfully. Found ' . count($companies) . ' companies with people connections.';
      } else {
        $compStmt = $db->query('SELECT id, domain, name, created_at FROM companies ORDER BY id DESC');
        $companies = $compStmt ? $compStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $companyDebugInfo = 'Query executed successfully. Found ' . count($companies) . ' companies. Company connections not available - <a href="setup_db.php">run setup</a>.';
      }
    } catch (Throwable $e) {
      $companyDebugInfo = 'Database error: ' . $e->getMessage();
    }
  ?>
    <h3>Organisations</h3>
    <div style="background-color: #f8f9fa; padding: 8px; margin: 8px 0; border-radius: 4px; font-size: 12px; color: #666;">
      Debug: <?php echo htmlspecialchars($companyDebugInfo, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    
    <?php if (empty($companies)) { ?>
      <p style="color:#666">No organisations yet. Add some companies from the Email tab.</p>
    <?php } else { ?>
      <div style="overflow:auto; border:1px solid #ddd; border-radius:6px">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Domain</th>
              <th>Name</th>
              <th>Connected People</th>
              <th>Created</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($companies as $company) { ?>
              <tr>
                <td><?php echo (int)$company['id']; ?></td>
                <td>
                  <span style="background-color: #e9ecef; color: #495057; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-family: monospace;">
                    <?php echo htmlspecialchars((string)$company['domain'], ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                </td>
                <td><?php echo htmlspecialchars((string)($company['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <?php if ($hasCompanyIdColumn && isset($company['connected_people']) && $company['connected_people'] !== null) { 
                    $peopleCount = (int)($company['people_count'] ?? 0);
                    $peopleList = (string)$company['connected_people'];
                  ?>
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                      <span style="background-color: #d4edda; color: #155724; padding: 2px 6px; border-radius: 3px; font-size: 11px; display: inline-block;">
                        <?php echo $peopleCount; ?> person<?php echo $peopleCount !== 1 ? 's' : ''; ?>
                      </span>
                      <div style="font-size: 12px; color: #495057; max-width: 200px; word-wrap: break-word;">
                        <?php echo htmlspecialchars($peopleList, ENT_QUOTES, 'UTF-8'); ?>
                      </div>
                    </div>
                  <?php } else { ?>
                    <span style="color: #999; font-size: 12px;">No connections</span>
                  <?php } ?>
                </td>
                <td><?php echo htmlspecialchars((string)($company['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <span style="color: #28a745; font-size: 12px;">✓ Active</span>
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

    function addPerson(id, name) {
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
      
      form.appendChild(actionInput);
      form.appendChild(nameInput);
      
      document.body.appendChild(form);
      form.submit();
    }

    function showCompanyDropdown(personId) {
      const dropdown = document.getElementById('company_dropdown_' + personId);
      if (dropdown) {
        dropdown.style.display = 'block';
      }
    }

    function hideCompanyDropdown(personId) {
      const dropdown = document.getElementById('company_dropdown_' + personId);
      if (dropdown) {
        dropdown.style.display = 'none';
      }
    }

    function filterCompanies(personId) {
      const searchInput = document.getElementById('company_search_' + personId);
      const dropdown = document.getElementById('company_dropdown_' + personId);
      const filter = searchInput.value.toLowerCase();
      
      if (dropdown) {
        const options = dropdown.getElementsByClassName('company-option');
        for (let i = 0; i < options.length; i++) {
          const text = options[i].textContent.toLowerCase();
          if (text.includes(filter)) {
            options[i].style.display = 'block';
          } else {
            options[i].style.display = 'none';
          }
        }
        dropdown.style.display = 'block';
      }
    }

    function selectCompany(personId, companyId, companyName) {
      const searchInput = document.getElementById('company_search_' + personId);
      const hiddenInput = document.getElementById('selected_company_' + personId);
      const dropdown = document.getElementById('company_dropdown_' + personId);
      
      if (searchInput) {
        searchInput.value = companyName;
      }
      if (hiddenInput) {
        hiddenInput.value = companyId;
      }
      if (dropdown) {
        dropdown.style.display = 'none';
      }
    }

    function selectAndSaveCompany(personId, companyId, companyName) {
      // First update the UI
      selectCompany(personId, companyId, companyName);
      
      // Show immediate visual feedback
      const statusElement = document.getElementById('company_status_' + personId);
      if (statusElement) {
        if (companyId > 0) {
          statusElement.style.backgroundColor = '#d4edda';
          statusElement.style.color = '#155724';
          statusElement.textContent = '✓ Connected';
        } else {
          statusElement.style.backgroundColor = '#f8f9fa';
          statusElement.style.color = '#6c757d';
          statusElement.textContent = 'No Company';
        }
      }
      
      // Get the current name value
      const nameInput = document.querySelector('input[name="name_' + personId + '"]');
      const currentName = nameInput ? nameInput.value : '';
      
      // Create and submit form
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = 'dashboard.php?tab=crm&sub=people';
      form.style.display = 'none';
      
      // Add form fields
      const fields = {
        'action': 'update_person',
        'id': personId.toString(),
        'name': currentName,
        'company_id': companyId.toString()
      };
      
      for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      }
      
      document.body.appendChild(form);
      form.submit();
    }

    function prepareSave(personId) {
      // Get the name input value
      const nameInput = document.querySelector('input[name="name_' + personId + '"]');
      const companyInput = document.getElementById('selected_company_' + personId);
      const saveNameInput = document.getElementById('save_name_' + personId);
      const saveCompanyInput = document.getElementById('save_company_' + personId);
      
      console.log('prepareSave called for personId:', personId);
      console.log('nameInput found:', nameInput);
      console.log('companyInput found:', companyInput);
      console.log('saveNameInput found:', saveNameInput);
      console.log('saveCompanyInput found:', saveCompanyInput);
      
      if (nameInput && saveNameInput) {
        saveNameInput.value = nameInput.value;
        console.log('Name value set to:', nameInput.value);
      } else {
        console.log('Name input or save name input not found');
      }
      
      if (companyInput && saveCompanyInput) {
        saveCompanyInput.value = companyInput.value;
        console.log('Company value set to:', companyInput.value);
      } else {
        console.log('Company input or save company input not found');
      }
    }
</script>