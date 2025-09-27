<?php
/**
 * Test Login Connection
 * URL: https://yourdomain.com/temp/test_login.php
 */

require_once __DIR__ . '/../inc/db.php';

echo "<h1>Database Connection Test</h1>";

try {
    $db = getDatabaseConnection();
    echo "<p>✅ Database connection successful!</p>";
    
    // Test if users table exists and has data
    $stmt = $db->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch();
    echo "<p>✅ Users table found with {$result['count']} users</p>";
    
    // Show available users
    $stmt = $db->query("SELECT username, email FROM users");
    $users = $stmt->fetchAll();
    
    echo "<h2>Available Users:</h2>";
    echo "<ul>";
    foreach ($users as $user) {
        echo "<li><strong>{$user['username']}</strong> ({$user['email']})</li>";
    }
    echo "</ul>";
    
    echo "<div style='background: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 10px 0;'>";
    echo "<strong>✅ Database is working!</strong><br>";
    echo "You can now login with: <strong>admin</strong> / <strong>admin123</strong>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<h1>❌ Database Connection Failed</h1>";
    echo "<p><strong>Error:</strong> " . $e->getMessage() . "</p>";
    
    echo "<h2>Possible Issues:</h2>";
    echo "<ul>";
    echo "<li>Database file not found at: " . __DIR__ . "/../database.sqlite</li>";
    echo "<li>File permissions issue</li>";
    echo "<li>SQLite extension not enabled</li>";
    echo "</ul>";
    
    echo "<p><strong>Database file path:</strong> " . realpath(__DIR__ . '/../database.sqlite') . "</p>";
    echo "<p><strong>File exists:</strong> " . (file_exists(__DIR__ . '/../database.sqlite') ? 'Yes' : 'No') . "</p>";
}
?>
