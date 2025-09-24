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

    $db->exec('CREATE TABLE IF NOT EXISTS emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        name TEXT,
        company TEXT,
        created_at TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id TEXT UNIQUE NOT NULL,
        from_name TEXT,
        from_email TEXT,
        subject TEXT,
        mail_date TEXT,
        snippet TEXT,
        created_at TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS channels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        homepage_url TEXT NOT NULL,
        logo_path TEXT,
        created_at TEXT NOT NULL
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS email_responses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email_id INTEGER NOT NULL,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        sent_at TEXT NOT NULL,
        FOREIGN KEY (email_id) REFERENCES emails (id)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS email_statuses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        created_at TEXT NOT NULL
    )');

    // Create companies table
    $db->exec('CREATE TABLE IF NOT EXISTS companies (
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
        FOREIGN KEY (email_id) REFERENCES emails (id),
        FOREIGN KEY (company_id) REFERENCES companies (id),
        UNIQUE(email_id, company_id)
    )');

    // Create people table
    $db->exec('CREATE TABLE IF NOT EXISTS people (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        company_id INTEGER,
        created_at TEXT NOT NULL,
        FOREIGN KEY (company_id) REFERENCES companies (id)
    )');

    echo "Adding missing columns...<br>";
    
    // Add company column to emails table if it doesn't exist
    try {
        $db->exec('ALTER TABLE emails ADD COLUMN company TEXT');
        echo "Added company column to emails table<br>";
    } catch (Throwable $e) {
        echo "Company column already exists in emails table<br>";
    }

    // Add company_id column to people table if it doesn't exist
    try {
        $db->exec('ALTER TABLE people ADD COLUMN company_id INTEGER');
        echo "Added company_id column to people table<br>";
    } catch (Throwable $e) {
        echo "Company_id column already exists in people table<br>";
    }

    // Add new columns to companies table if they don't exist
    $newCompanyColumns = [
        'full_name' => 'TEXT',
        'importance' => 'TEXT', 
        'registry_code' => 'TEXT',
        'address' => 'TEXT',
        'logo_path' => 'TEXT'
    ];
    
    foreach ($newCompanyColumns as $colName => $colType) {
        try {
            $db->exec("ALTER TABLE companies ADD COLUMN {$colName} {$colType}");
        echo "Added {$colName} column to companies table<br>";
            } catch (Throwable $e) {
                echo "{$colName} column already exists in companies table<br>";
            }
        }

        // Add subject column to email_responses table if it doesn't exist
        try {
            $db->exec('ALTER TABLE email_responses ADD COLUMN subject TEXT');
            echo "Added subject column to email_responses table<br>";
        } catch (Throwable $e) {
            echo "Subject column already exists in email_responses table<br>";
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

    // Check if email_statuses table has name column, if not add it
    try {
        $checkColumn = $db->query("PRAGMA table_info(email_statuses)");
        $columns = $checkColumn ? $checkColumn->fetchAll(PDO::FETCH_ASSOC) : [];
        $hasNameColumn = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'name') {
                $hasNameColumn = true;
                break;
            }
        }
        
        if (!$hasNameColumn) {
            $db->exec('ALTER TABLE email_statuses ADD COLUMN name TEXT');
            echo "Added name column to email_statuses table<br>";
        } else {
            echo "Name column already exists in email_statuses table<br>";
        }
        
        // Insert some sample email statuses
        $statuses = ['new', 'read', 'replied', 'ignore', 'marketing'];
        $insertedCount = 0;
        foreach ($statuses as $status) {
            $stmt = $db->prepare('INSERT OR IGNORE INTO email_statuses (name, created_at) VALUES (:n, :t)');
            $result = $stmt->execute([':n' => $status, ':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
            if ($stmt->rowCount() > 0) {
                $insertedCount++;
            }
        }
        echo "Inserted {$insertedCount} email statuses<br>";
    } catch (Throwable $e) {
        echo "Note: Could not update email_statuses table: " . $e->getMessage() . "<br>";
    }

    echo "Database setup completed successfully!<br>";
    echo "User: demo / demo123<br>";
    echo "All tables created and seeded.<br>";
    
} catch (Throwable $e) {
    echo 'Setup error: ' . $e->getMessage();
}
?>
