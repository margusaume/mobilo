<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.html?error=1');
    exit;
}

$username = htmlspecialchars((string)($_SESSION['username'] ?? 'user'), ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard</title>
  <style>
    html, body { height: 100%; margin: 0; }
    body { display: grid; place-items: center; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Arial, Helvetica, "Apple Color Emoji", "Segoe UI Emoji"; }
    .container { text-align: center; }
  </style>
</head>
<body>
  <main class="container">
    <h1>Welcome, <?php echo $username; ?>!</h1>
    <p>You are logged in.</p>
    <p><a href="logout.php">Log out</a></p>
  </main>
</body>
</html>


