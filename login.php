<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/inc/db.php';

function redirectWithError(): void {
    header('Location: index.html?error=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithError();
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
if ($username === '' || $password === '') {
    redirectWithError();
}

try {
    $db = getDatabaseConnection();
    $stmt = $db->prepare('SELECT id, username, password_hash FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        redirectWithError();
    }

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    header('Location: dashboard.php');
    exit;
} catch (Throwable $e) {
    redirectWithError();
}


