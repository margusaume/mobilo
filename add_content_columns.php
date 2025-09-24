<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';

try {
    $db = getDatabaseConnection();
    
    echo "Adding content columns to inbox_incoming table...<br><br>";
    
    // Check current table structure
    echo "Current table structure:<br>";
    $columnsStmt = $db->query("PRAGMA table_info(inbox_incoming)");
    $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    
    $existingColumns = [];
    foreach ($columns as $col) {
        $existingColumns[] = $col['name'];
        echo "- {$col['name']} ({$col['type']})<br>";
    }
    
    // Add missing columns
    $contentColumns = [
        'content_plain' => 'TEXT',
        'content_html' => 'TEXT',
        'attachments' => 'TEXT',
        'full_headers' => 'TEXT'
    ];
    
    echo "<br>Adding missing columns:<br>";
    foreach ($contentColumns as $colName => $colType) {
        if (!in_array($colName, $existingColumns)) {
            try {
                $db->exec("ALTER TABLE inbox_incoming ADD COLUMN {$colName} {$colType}");
                echo "✅ Added {$colName} column<br>";
            } catch (Throwable $e) {
                echo "❌ Error adding {$colName}: " . $e->getMessage() . "<br>";
            }
        } else {
            echo "✅ {$colName} column already exists<br>";
        }
    }
    
    echo "<br>Final table structure:<br>";
    $columnsStmt = $db->query("PRAGMA table_info(inbox_incoming)");
    $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    
    foreach ($columns as $col) {
        echo "- {$col['name']} ({$col['type']})<br>";
    }
    
    echo "<br>✅ Content columns setup completed!<br>";
    echo "Now try viewing your email message again.<br>";
    
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage();
}
?>
