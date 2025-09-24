<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';

try {
    $db = getDatabaseConnection();
    
    echo "Starting database setup...<br>";
    
    // Create all necessary tables
    echo "Creating tables...<br>";
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS crm_emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        name TEXT,
        company TEXT,
        created_at TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS inbox_incoming (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id TEXT UNIQUE NOT NULL,
        from_name TEXT,
        from_email TEXT,
        subject TEXT,
        mail_date TEXT,
        snippet TEXT,
        content_plain TEXT,
        content_html TEXT,
        attachments TEXT,
        full_headers TEXT,
        created_at TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS inbox_sent (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email_id INTEGER NOT NULL,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        sent_at TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (email_id) REFERENCES crm_emails (id)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS crm_email_status (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        created_at TEXT NOT NULL
    )');

    // Create crm_organisations table
    $db->exec('CREATE TABLE IF NOT EXISTS crm_organisations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        domain TEXT UNIQUE NOT NULL,
        name TEXT,
        full_name TEXT,
        importance TEXT,
        registry_code TEXT,
        address TEXT,
        logo_path TEXT,
        created_at TEXT NOT NULL
    )');

    // Create email_company_connections table
    $db->exec('CREATE TABLE IF NOT EXISTS email_company_connections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email_id INTEGER NOT NULL,
        company_id INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (email_id) REFERENCES crm_emails (id),
        FOREIGN KEY (company_id) REFERENCES crm_organisations (id),
        UNIQUE(email_id, company_id)
    )');

    // Create crm_people table
    $db->exec('CREATE TABLE IF NOT EXISTS crm_people (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        company_id INTEGER,
        created_at TEXT NOT NULL,
        FOREIGN KEY (company_id) REFERENCES crm_organisations (id)
    )');

    echo "Adding missing columns...<br>";
    
    // Add company column to crm_emails table if it doesn't exist
    try {
        $db->exec('ALTER TABLE crm_emails ADD COLUMN company TEXT');
        echo "Added company column to crm_emails table<br>";
    } catch (Throwable $e) {
        echo "Company column already exists in crm_emails table<br>";
    }

    // Add company_id column to crm_people table if it doesn't exist
    try {
        $db->exec('ALTER TABLE crm_people ADD COLUMN company_id INTEGER');
        echo "Added company_id column to crm_people table<br>";
    } catch (Throwable $e) {
        echo "Company_id column already exists in crm_people table<br>";
    }

    // Add new columns to crm_organisations table if they don't exist
    $newCompanyColumns = [
        'full_name' => 'TEXT',
        'importance' => 'TEXT', 
        'registry_code' => 'TEXT',
        'address' => 'TEXT',
        'logo_path' => 'TEXT'
    ];
    
    foreach ($newCompanyColumns as $colName => $colType) {
        try {
            $db->exec("ALTER TABLE crm_organisations ADD COLUMN {$colName} {$colType}");
        echo "Added {$colName} column to crm_organisations table<br>";
            } catch (Throwable $e) {
                echo "{$colName} column already exists in crm_organisations table<br>";
            }
        }

        // Add missing columns to inbox_sent table if they don't exist
        $emailResponseColumns = [
            'subject' => 'TEXT',
            'sent_at' => 'TEXT',
            'created_at' => 'TEXT'
        ];
        
        foreach ($emailResponseColumns as $colName => $colType) {
            try {
                $db->exec("ALTER TABLE inbox_sent ADD COLUMN {$colName} {$colType}");
                echo "Added {$colName} column to inbox_sent table<br>";
            } catch (Throwable $e) {
                echo "{$colName} column already exists in inbox_sent table<br>";
            }
        }

        // Add missing columns to inbox_incoming table for content storage
        $messageColumns = [
            'content_plain' => 'TEXT',
            'content_html' => 'TEXT',
            'attachments' => 'TEXT',
            'full_headers' => 'TEXT'
        ];
        
        foreach ($messageColumns as $colName => $colType) {
            try {
                $db->exec("ALTER TABLE inbox_incoming ADD COLUMN {$colName} {$colType}");
                echo "Added {$colName} column to inbox_incoming table<br>";
            } catch (Throwable $e) {
                echo "{$colName} column already exists in inbox_incoming table<br>";
            }
        }

    echo "Seeding data...<br>";
    
    // Seed a user only if missing
    $stmt = $db->prepare('SELECT COUNT(1) AS c FROM users WHERE username = :u');
    $stmt->execute([':u' => 'demo']);
    $exists = (int)($stmt->fetch()['c'] ?? 0) > 0;

    if (!$exists) {
        $passwordHash = password_hash('demo123', PASSWORD_DEFAULT);
        $ins = $db->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (:u, :p, :t)');
        $ins->execute([
            ':u' => 'demo',
            ':p' => $passwordHash,
            ':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ]);
        echo "Created demo user<br>";
    } else {
        echo "Demo user already exists<br>";
    }

    // Check if crm_email_status table has name column, if not add it
    try {
        $checkColumn = $db->query("PRAGMA table_info(crm_email_status)");
        $columns = $checkColumn ? $checkColumn->fetchAll(PDO::FETCH_ASSOC) : [];
        $hasNameColumn = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'name') {
                $hasNameColumn = true;
                break;
            }
        }
        
        if (!$hasNameColumn) {
            $db->exec('ALTER TABLE crm_email_status ADD COLUMN name TEXT');
            echo "Added name column to crm_email_status table<br>";
        } else {
            echo "Name column already exists in crm_email_status table<br>";
        }
        
        // Insert some sample email statuses
        $statuses = ['new', 'read', 'replied', 'ignore', 'marketing'];
        $insertedCount = 0;
        foreach ($statuses as $status) {
            $stmt = $db->prepare('INSERT OR IGNORE INTO crm_email_status (name, created_at) VALUES (:n, :t)');
            $result = $stmt->execute([':n' => $status, ':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
            if ($stmt->rowCount() > 0) {
                $insertedCount++;
            }
        }
        echo "Inserted {$insertedCount} email statuses<br>";
    } catch (Throwable $e) {
        echo "Note: Could not update crm_email_status table: " . $e->getMessage() . "<br>";
    }

    echo "Database setup completed successfully!<br>";
    echo "User: demo / demo123<br>";
    echo "All tables created and seeded.<br>";
    
} catch (Throwable $e) {
    echo 'Setup error: ' . $e->getMessage();
}
?>
