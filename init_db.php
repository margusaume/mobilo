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


