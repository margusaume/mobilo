<?php
/**
 * SQLite Database Setup Script
 * Run this file once to create all required database tables
 * URL: https://yourdomain.com/temp/setup_database_sqlite.php
 */

// SQLite database file path
$dbFile = __DIR__ . '/../database.sqlite';

try {
    // Create database file if it doesn't exist
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>SQLite Database Setup</h1>";
    echo "<p>Setting up database tables...</p>";
    echo "<p><strong>Database file:</strong> $dbFile</p>";
    
    // Create CRM Organisations table
    $sql = "CREATE TABLE IF NOT EXISTS crm_organisations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        domain TEXT,
        phone TEXT,
        address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created crm_organisations table</p>";
    
    // Create CRM People table
    $sql = "CREATE TABLE IF NOT EXISTS crm_people (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        organisation_id INTEGER,
        phone TEXT,
        email TEXT,
        position TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (organisation_id) REFERENCES crm_organisations(id) ON DELETE SET NULL
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created crm_people table</p>";
    
    // Create CRM Emails table
    $sql = "CREATE TABLE IF NOT EXISTS crm_emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        person_id INTEGER,
        email TEXT NOT NULL,
        is_primary BOOLEAN DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (person_id) REFERENCES crm_people(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created crm_emails table</p>";
    
    // Create CRM Email Status table
    $sql = "CREATE TABLE IF NOT EXISTS crm_email_status (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        color TEXT DEFAULT '#007bff',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created crm_email_status table</p>";
    
    // Insert default email statuses
    $statuses = [
        ['name' => 'New', 'color' => '#28a745'],
        ['name' => 'In Progress', 'color' => '#ffc107'],
        ['name' => 'Replied', 'color' => '#17a2b8'],
        ['name' => 'Closed', 'color' => '#6c757d']
    ];
    
    foreach ($statuses as $status) {
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO crm_email_status (name, color) VALUES (?, ?)");
        $stmt->execute([$status['name'], $status['color']]);
    }
    echo "<p>✅ Inserted default email statuses</p>";
    
    // Create Inbox Incoming table
    $sql = "CREATE TABLE IF NOT EXISTS inbox_incoming (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id TEXT UNIQUE,
        from_email TEXT,
        from_name TEXT,
        subject TEXT,
        content_plain TEXT,
        content_html TEXT,
        attachments TEXT,
        full_headers TEXT,
        mail_date DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        status_id INTEGER DEFAULT 1,
        FOREIGN KEY (status_id) REFERENCES crm_email_status(id)
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created inbox_incoming table</p>";
    
    // Create Inbox Sent table
    $sql = "CREATE TABLE IF NOT EXISTS inbox_sent (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        to_email TEXT,
        to_name TEXT,
        subject TEXT,
        content TEXT,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created inbox_sent table</p>";
    
    // Create Inbox Deleted table
    $sql = "CREATE TABLE IF NOT EXISTS inbox_deleted (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id TEXT,
        from_email TEXT,
        from_name TEXT,
        subject TEXT,
        content_plain TEXT,
        content_html TEXT,
        attachments TEXT,
        full_headers TEXT,
        mail_date DATETIME,
        deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        original_id INTEGER
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created inbox_deleted table</p>";
    
    // Create Email Responses table
    $sql = "CREATE TABLE IF NOT EXISTS email_responses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        original_message_id INTEGER,
        to_email TEXT,
        to_name TEXT,
        subject TEXT,
        content TEXT,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (original_message_id) REFERENCES inbox_incoming(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created email_responses table</p>";
    
    // Create Users table
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        email TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_login DATETIME
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created users table</p>";
    
    // Insert default admin user (password: admin123)
    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO users (username, password_hash, email) VALUES (?, ?, ?)");
    $stmt->execute(['admin', $adminPassword, 'admin@example.com']);
    echo "<p>✅ Created default admin user (username: admin, password: admin123)</p>";
    
    // Test database connection
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM crm_organisations");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<h2>🎉 SQLite Database Setup Complete!</h2>";
    echo "<p><strong>Database file created:</strong> $dbFile</p>";
    echo "<p><strong>File size:</strong> " . number_format(filesize($dbFile)) . " bytes</p>";
    echo "<p><strong>Tables created:</strong> " . $result['count'] . " (test query successful)</p>";
    
    echo "<p><strong>Next steps:</strong></p>";
    echo "<ul>";
    echo "<li>✅ Database is ready to use</li>";
    echo "<li>🔐 Login with username: <strong>admin</strong>, password: <strong>admin123</strong></li>";
    echo "<li>🗑️ Delete this setup file after confirming everything works</li>";
    echo "</ul>";
    
    echo "<div style='background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 10px 0;'>";
    echo "<strong>✅ SQLite Database Ready!</strong><br>";
    echo "Your database file is located at: <code>$dbFile</code><br>";
    echo "No server configuration needed - it's just a file!";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<h1>❌ SQLite Database Setup Failed</h1>";
    echo "<p><strong>Error:</strong> " . $e->getMessage() . "</p>";
    
    echo "<h2>🔧 How to Fix This:</h2>";
    echo "<ol>";
    echo "<li><strong>Check file permissions</strong> - make sure the web server can write to the directory</li>";
    echo "<li><strong>Check directory exists</strong> - make sure the temp folder exists</li>";
    echo "<li><strong>Check disk space</strong> - make sure there's enough space</li>";
    echo "</ol>";
    
    echo "<div style='background: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; margin: 10px 0;'>";
    echo "<strong>Common issues:</strong><br>";
    echo "• Directory permissions (chmod 755 on temp folder)<br>";
    echo "• Disk space full<br>";
    echo "• PHP SQLite extension not enabled<br>";
    echo "</div>";
}
?>
