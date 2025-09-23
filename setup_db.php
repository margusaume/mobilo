<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';

try {
    $db = getDatabaseConnection();
    
    // Create all necessary tables
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

    // Add company column to emails table if it doesn't exist
    try {
        $db->exec('ALTER TABLE emails ADD COLUMN company TEXT');
    } catch (Throwable $e) {
        // Column might already exist, ignore error
    }

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
    }

    // Insert some sample email statuses
    $statuses = ['new', 'read', 'replied', 'ignore', 'marketing'];
    foreach ($statuses as $status) {
        $stmt = $db->prepare('INSERT OR IGNORE INTO email_statuses (name, created_at) VALUES (:n, :t)');
        $stmt->execute([':n' => $status, ':t' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
    }

    echo "Database setup completed successfully!<br>";
    echo "User: demo / demo123<br>";
    echo "All tables created and seeded.<br>";
    
} catch (Throwable $e) {
    echo 'Setup error: ' . $e->getMessage();
}
?>
