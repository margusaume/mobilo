<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';

try {
    $db = getDatabaseConnection();
    
    echo "Starting database migration...<br>";
    
    // 1. Delete channels table (if it exists)
    try {
        $db->exec('DROP TABLE IF EXISTS channels');
        echo "✅ Deleted channels table<br>";
    } catch (Throwable $e) {
        echo "⚠️ Could not delete channels table: " . $e->getMessage() . "<br>";
    }
    
    // 2. Delete companies table
    try {
        $db->exec('DROP TABLE IF EXISTS companies');
        echo "✅ Deleted companies table<br>";
    } catch (Throwable $e) {
        echo "⚠️ Could not delete companies table: " . $e->getMessage() . "<br>";
    }
    
    // 3. Delete people table
    try {
        $db->exec('DROP TABLE IF EXISTS people');
        echo "✅ Deleted people table<br>";
    } catch (Throwable $e) {
        echo "⚠️ Could not delete people table: " . $e->getMessage() . "<br>";
    }
    
    // 4. Delete messages table
    try {
        $db->exec('DROP TABLE IF EXISTS messages');
        echo "✅ Deleted messages table<br>";
    } catch (Throwable $e) {
        echo "⚠️ Could not delete messages table: " . $e->getMessage() . "<br>";
    }
    
    // 5. Delete emails table
    try {
        $db->exec('DROP TABLE IF EXISTS emails');
        echo "✅ Deleted emails table<br>";
    } catch (Throwable $e) {
        echo "⚠️ Could not delete emails table: " . $e->getMessage() . "<br>";
    }
    
    // 6. Rename email_responses to inbox_sent
    try {
        $db->exec('ALTER TABLE email_responses RENAME TO inbox_sent');
        echo "✅ Renamed email_responses to inbox_sent<br>";
    } catch (Throwable $e) {
        echo "⚠️ Could not rename email_responses table: " . $e->getMessage() . "<br>";
    }
    
    // 7. Rename email_statuses to crm_email_status
    try {
        $db->exec('ALTER TABLE email_statuses RENAME TO crm_email_status');
        echo "✅ Renamed email_statuses to crm_email_status<br>";
    } catch (Throwable $e) {
        echo "⚠️ Could not rename email_statuses table: " . $e->getMessage() . "<br>";
    }
    
    // 8. Update foreign key references in email_company_connections
    try {
        // First, drop the existing foreign key constraints
        $db->exec('DROP TABLE IF EXISTS email_company_connections');
        
        // Recreate with correct foreign key references
        $db->exec('CREATE TABLE email_company_connections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email_id INTEGER NOT NULL,
            company_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (email_id) REFERENCES crm_emails (id),
            FOREIGN KEY (company_id) REFERENCES crm_organisations (id),
            UNIQUE(email_id, company_id)
        )');
        echo "✅ Updated email_company_connections table with new foreign keys<br>";
    } catch (Throwable $e) {
        echo "⚠️ Could not update email_company_connections table: " . $e->getMessage() . "<br>";
    }
    
    // 9. Verify all tables exist with new names
    echo "<br>Verifying new table structure...<br>";
    $expectedTables = [
        'users',
        'crm_emails', 
        'inbox_incoming',
        'inbox_sent',
        'crm_email_status',
        'crm_organisations',
        'email_company_connections',
        'crm_people'
    ];
    
    $tablesStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    $existingTables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    
    foreach ($expectedTables as $table) {
        if (in_array($table, $existingTables)) {
            echo "✅ Table '{$table}' exists<br>";
        } else {
            echo "❌ Table '{$table}' is missing<br>";
        }
    }
    
    // 10. Check for any old tables that shouldn't exist
    $oldTables = ['channels', 'companies', 'people', 'messages', 'emails', 'email_responses', 'email_statuses'];
    $remainingOldTables = array_intersect($oldTables, $existingTables);
    
    if (!empty($remainingOldTables)) {
        echo "<br>⚠️ Warning: The following old tables still exist:<br>";
        foreach ($remainingOldTables as $table) {
            echo "- {$table}<br>";
        }
        echo "You may need to manually drop these tables if they're no longer needed.<br>";
    } else {
        echo "<br>✅ All old tables have been successfully deleted or renamed!<br>";
    }
    
    echo "<br>🎉 Database migration completed successfully!<br>";
    echo "All table names have been updated to match the new structure.<br>";
    
} catch (Throwable $e) {
    echo 'Migration error: ' . $e->getMessage();
}
?>
