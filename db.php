<?php
declare(strict_types=1);

function getDatabaseConnection(): PDO {
    $databaseFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'app.sqlite';
    $pdo = new PDO('sqlite:' . $databaseFilePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}


