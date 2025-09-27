<?php
/**
 * Database Setup Script
 * Run this file once to create all required database tables
 * URL: https://yourdomain.com/temp/setup_database.php
 */

// Database configuration - UPDATE THESE VALUES
$host = 'localhost';
$dbname = 'mobilo_db';           // ← Change to your database name
$username = 'mobilo_user';       // ← Change to your database username  
$password = 'your_secure_password'; // ← Change to your database password

// Common Cloudways database settings:
// Host: localhost (or your server IP)
// Database: usually something like 'virt86672_mobilo' 
// Username: usually something like 'virt86672_mobilo'
// Password: the one you set when creating the database

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>Database Setup</h1>";
    echo "<p>Setting up database tables...</p>";
    
    // Create CRM Organisations table
    $sql = "CREATE TABLE IF NOT EXISTS crm_organisations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        domain VARCHAR(255),
        phone VARCHAR(50),
        address TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created crm_organisations table</p>";
    
    // Create CRM People table
    $sql = "CREATE TABLE IF NOT EXISTS crm_people (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        organisation_id INT,
        phone VARCHAR(50),
        email VARCHAR(255),
        position VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (organisation_id) REFERENCES crm_organisations(id) ON DELETE SET NULL
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created crm_people table</p>";
    
    // Create CRM Emails table
    $sql = "CREATE TABLE IF NOT EXISTS crm_emails (
        id INT AUTO_INCREMENT PRIMARY KEY,
        person_id INT,
        email VARCHAR(255) NOT NULL,
        is_primary BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (person_id) REFERENCES crm_people(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created crm_emails table</p>";
    
    // Create CRM Email Status table
    $sql = "CREATE TABLE IF NOT EXISTS crm_email_status (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        color VARCHAR(7) DEFAULT '#007bff',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
        $stmt = $pdo->prepare("INSERT IGNORE INTO crm_email_status (name, color) VALUES (?, ?)");
        $stmt->execute([$status['name'], $status['color']]);
    }
    echo "<p>✅ Inserted default email statuses</p>";
    
    // Create Inbox Incoming table
    $sql = "CREATE TABLE IF NOT EXISTS inbox_incoming (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id VARCHAR(255) UNIQUE,
        from_email VARCHAR(255),
        from_name VARCHAR(255),
        subject TEXT,
        content_plain TEXT,
        content_html TEXT,
        attachments TEXT,
        full_headers TEXT,
        mail_date DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status_id INT DEFAULT 1,
        FOREIGN KEY (status_id) REFERENCES crm_email_status(id)
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created inbox_incoming table</p>";
    
    // Create Inbox Sent table
    $sql = "CREATE TABLE IF NOT EXISTS inbox_sent (
        id INT AUTO_INCREMENT PRIMARY KEY,
        to_email VARCHAR(255),
        to_name VARCHAR(255),
        subject TEXT,
        content TEXT,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created inbox_sent table</p>";
    
    // Create Inbox Deleted table
    $sql = "CREATE TABLE IF NOT EXISTS inbox_deleted (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id VARCHAR(255),
        from_email VARCHAR(255),
        from_name VARCHAR(255),
        subject TEXT,
        content_plain TEXT,
        content_html TEXT,
        attachments TEXT,
        full_headers TEXT,
        mail_date DATETIME,
        deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        original_id INT
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created inbox_deleted table</p>";
    
    // Create Email Responses table
    $sql = "CREATE TABLE IF NOT EXISTS email_responses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        original_message_id INT,
        to_email VARCHAR(255),
        to_name VARCHAR(255),
        subject TEXT,
        content TEXT,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (original_message_id) REFERENCES inbox_incoming(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created email_responses table</p>";
    
    // Create Users table
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        email VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_login TIMESTAMP NULL
    )";
    $pdo->exec($sql);
    echo "<p>✅ Created users table</p>";
    
    // Insert default admin user (password: admin123)
    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT IGNORE INTO users (username, password_hash, email) VALUES (?, ?, ?)");
    $stmt->execute(['admin', $adminPassword, 'admin@example.com']);
    echo "<p>✅ Created default admin user (username: admin, password: admin123)</p>";
    
    echo "<h2>🎉 Database Setup Complete!</h2>";
    echo "<p><strong>Next steps:</strong></p>";
    echo "<ul>";
    echo "<li>Change the database credentials in this file</li>";
    echo "<li>Delete this file after setup for security</li>";
    echo "<li>Login with username: <strong>admin</strong>, password: <strong>admin123</strong></li>";
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<h1>❌ Database Setup Failed</h1>";
    echo "<p><strong>Error:</strong> " . $e->getMessage() . "</p>";
    
    echo "<h2>🔧 How to Fix This:</h2>";
    echo "<ol>";
    echo "<li><strong>Edit this file</strong> (temp/setup_database.php)</li>";
    echo "<li><strong>Update the database credentials</strong> at the top of the file:</li>";
    echo "</ol>";
    
    echo "<div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #007bff; margin: 10px 0;'>";
    echo "<strong>Current values (WRONG):</strong><br>";
    echo "Host: <code>$host</code><br>";
    echo "Database: <code>$dbname</code><br>";
    echo "Username: <code>$username</code><br>";
    echo "Password: <code>$password</code><br>";
    echo "</div>";
    
    echo "<div style='background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 10px 0;'>";
    echo "<strong>You need to change them to your actual values:</strong><br>";
    echo "• Go to your Cloudways panel<br>";
    echo "• Find your database credentials<br>";
    echo "• Update the 4 values in this file<br>";
    echo "• Save and refresh this page<br>";
    echo "</div>";
    
    echo "<p><strong>Common Cloudways format:</strong></p>";
    echo "<ul>";
    echo "<li>Database: <code>virt86672_mobilo</code> (or similar)</li>";
    echo "<li>Username: <code>virt86672_mobilo</code> (or similar)</li>";
    echo "<li>Password: <code>your_actual_password</code></li>";
    echo "<li>Host: <code>localhost</code> (usually)</li>";
    echo "</ul>";
}
?>
