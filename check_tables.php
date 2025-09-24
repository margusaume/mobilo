<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';

try {
    $db = getDatabaseConnection();
    
    echo "Current database tables:<br><br>";
    
    $tablesStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    $tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    
    if (empty($tables)) {
        echo "No tables found in database.<br>";
    } else {
        foreach ($tables as $table) {
            echo "📋 {$table}<br>";
        }
    }
    
    echo "<br>Total tables: " . count($tables) . "<br>";
    
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage();
}
?>
