<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';

try {
    $db = getDatabaseConnection();
    
    echo "Debugging message content in database...<br><br>";
    
    // Get message ID from URL
    $messageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($messageId <= 0) {
        echo "Please provide a message ID: ?id=123<br>";
        exit;
    }
    
    echo "Checking message ID: {$messageId}<br><br>";
    
    // Check if table exists
    $tablesStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='inbox_incoming'");
    $tableExists = $tablesStmt ? $tablesStmt->fetch() : false;
    
    if (!$tableExists) {
        echo "❌ Table 'inbox_incoming' does not exist!<br>";
        exit;
    }
    
    echo "✅ Table 'inbox_incoming' exists<br>";
    
    // Check table structure
    echo "<br>Table structure:<br>";
    $columnsStmt = $db->query("PRAGMA table_info(inbox_incoming)");
    $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    
    foreach ($columns as $col) {
        echo "- {$col['name']} ({$col['type']})<br>";
    }
    
    // Fetch the specific message
    echo "<br>Fetching message data:<br>";
    $stmt = $db->prepare('SELECT * FROM inbox_incoming WHERE id = :id');
    $stmt->execute([':id' => $messageId]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$message) {
        echo "❌ Message not found!<br>";
        exit;
    }
    
    echo "✅ Message found<br><br>";
    
    // Check content fields
    $contentPlain = (string)($message['content_plain'] ?? '');
    $contentHtml = (string)($message['content_html'] ?? '');
    $attachmentsList = (string)($message['attachments'] ?? '');
    $fullHeaders = (string)($message['full_headers'] ?? '');
    
    echo "Content analysis:<br>";
    echo "- content_plain: " . (empty($contentPlain) ? "❌ EMPTY" : "✅ " . strlen($contentPlain) . " chars") . "<br>";
    echo "- content_html: " . (empty($contentHtml) ? "❌ EMPTY" : "✅ " . strlen($contentHtml) . " chars") . "<br>";
    echo "- attachments: " . (empty($attachmentsList) ? "❌ EMPTY" : "✅ " . $attachmentsList) . "<br>";
    echo "- full_headers: " . (empty($fullHeaders) ? "❌ EMPTY" : "✅ " . strlen($fullHeaders) . " chars") . "<br>";
    
    echo "<br>Basic message info:<br>";
    echo "- ID: " . (int)$message['id'] . "<br>";
    echo "- From: " . htmlspecialchars((string)$message['from_email'], ENT_QUOTES, 'UTF-8') . "<br>";
    echo "- Subject: " . htmlspecialchars((string)$message['subject'], ENT_QUOTES, 'UTF-8') . "<br>";
    echo "- Date: " . htmlspecialchars((string)$message['mail_date'], ENT_QUOTES, 'UTF-8') . "<br>";
    
    if ($contentPlain || $contentHtml) {
        echo "<br>✅ Content is available in database!<br>";
        echo "The issue might be in the display logic.<br>";
    } else {
        echo "<br>❌ No content found in database.<br>";
        echo "The content columns might be missing or empty.<br>";
    }
    
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage();
}
?>
