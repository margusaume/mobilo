<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// Initialize SQLite DB and seed a demo user
try {
    $db = getDatabaseConnection();
    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
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

    echo "Database initialized. User: demo / demo123\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Init error: ' . $e->getMessage();
}


