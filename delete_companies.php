<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';

try {
    $db = getDatabaseConnection();
    
    echo "Deleting companies table...<br>";
    
    // Delete the companies table
    $db->exec('DROP TABLE IF EXISTS companies');
    echo "✅ Successfully deleted companies table<br>";
    
    // Verify it's gone
    $tablesStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='companies'");
    $result = $tablesStmt ? $tablesStmt->fetch() : false;
    
    if ($result) {
        echo "❌ Companies table still exists<br>";
    } else {
        echo "✅ Companies table has been completely removed<br>";
    }
    
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage();
}
?>
