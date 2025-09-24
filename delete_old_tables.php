<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';

try {
    $db = getDatabaseConnection();
    
    echo "Deleting old tables...<br><br>";
    
    $tablesToDelete = ['messages', 'people', 'emails'];
    
    foreach ($tablesToDelete as $table) {
        try {
            $db->exec("DROP TABLE IF EXISTS {$table}");
            echo "✅ Successfully deleted {$table} table<br>";
        } catch (Throwable $e) {
            echo "❌ Error deleting {$table} table: " . $e->getMessage() . "<br>";
        }
    }
    
    echo "<br>Verifying deletion...<br>";
    
    // Check which tables still exist
    $tablesStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    $existingTables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    
    $remainingOldTables = array_intersect($tablesToDelete, $existingTables);
    
    if (empty($remainingOldTables)) {
        echo "✅ All old tables have been successfully deleted!<br>";
    } else {
        echo "⚠️ The following tables still exist:<br>";
        foreach ($remainingOldTables as $table) {
            echo "- {$table}<br>";
        }
    }
    
    echo "<br>Current database tables:<br>";
    foreach ($existingTables as $table) {
        echo "📋 {$table}<br>";
    }
    
    echo "<br>Total tables: " . count($existingTables) . "<br>";
    
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage();
}
?>
