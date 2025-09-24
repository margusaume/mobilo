<?php
// Simple debug to check what's in database vs IMAP
session_start();
require_once __DIR__ . '/inc/db.php';

// Simulate being logged in
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'test';

echo "<h2>IMAP Debug Information</h2>";

try {
    // Check IMAP extension
    if (!extension_loaded('imap')) {
        echo "<p style='color:red'>IMAP extension is NOT installed</p>";
        exit;
    }
    echo "<p style='color:green'>IMAP extension is available</p>";
    
    // Connect to database
    $db = getDatabaseConnection();
    echo "<p style='color:green'>Database connected</p>";
    
    // Check what's in database
    $stmt = $db->query('SELECT COUNT(*) as count FROM inbox_incoming');
    $dbCount = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Emails in database: <strong>{$dbCount['count']}</strong></p>";
    
    // Show recent emails from database
    $stmt = $db->query('SELECT message_id, from_email, subject, mail_date FROM inbox_incoming ORDER BY id DESC LIMIT 5');
    $recentEmails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Recent emails in database:</h3>";
    echo "<table border='1' style='border-collapse:collapse'>";
    echo "<tr><th>Message ID</th><th>From</th><th>Subject</th><th>Date</th></tr>";
    foreach ($recentEmails as $email) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars(substr($email['message_id'], 0, 50)) . "...</td>";
        echo "<td>" . htmlspecialchars($email['from_email']) . "</td>";
        echo "<td>" . htmlspecialchars($email['subject']) . "</td>";
        echo "<td>" . htmlspecialchars($email['mail_date']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Try to connect to IMAP
    $host = 'imap.zone.eu';
    $port = 993;
    $username = 'info@teenus.ee';
    $password = 'Yyd12321df42Xgus9WHT8xhic';
    $encryption = 'ssl';
    
    $connectionString = "{{$host}:{$port}/imap/{$encryption}/novalidate-cert}INBOX";
    echo "<p>Connecting to: $connectionString</p>";
    
    $inbox = imap_open($connectionString, $username, $password);
    
    if (!$inbox) {
        echo "<p style='color:red'>Failed to connect to IMAP: " . imap_last_error() . "</p>";
        exit;
    }
    
    echo "<p style='color:green'>Connected to IMAP successfully!</p>";
    
    // Get message count
    $messageCount = imap_num_msg($inbox);
    echo "<p>Messages in IMAP inbox: <strong>$messageCount</strong></p>";
    
    // Show recent messages from IMAP
    echo "<h3>Recent messages from IMAP:</h3>";
    echo "<table border='1' style='border-collapse:collapse'>";
    echo "<tr><th>#</th><th>Message ID</th><th>From</th><th>Subject</th><th>Date</th></tr>";
    
    $startMsg = max(1, $messageCount - 4); // Show last 5 messages
    for ($i = $startMsg; $i <= $messageCount; $i++) {
        $header = imap_headerinfo($inbox, $i);
        $messageId = $header->message_id ?? '';
        $from = $header->from[0] ?? null;
        $fromEmail = $from ? $from->mailbox . '@' . $from->host : '';
        $fromName = $from ? ($from->personal ?? '') : '';
        $subject = $header->subject ?? '';
        $date = $header->date ?? '';
        
        echo "<tr>";
        echo "<td>$i</td>";
        echo "<td>" . htmlspecialchars(substr($messageId, 0, 50)) . "...</td>";
        echo "<td>" . htmlspecialchars($fromEmail . ($fromName ? " ($fromName)" : "")) . "</td>";
        echo "<td>" . htmlspecialchars($subject) . "</td>";
        echo "<td>" . htmlspecialchars($date) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    imap_close($inbox);
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
