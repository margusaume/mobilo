<?php
// Debug script for remote server
session_start();
require_once __DIR__ . '/inc/db.php';

// Simulate being logged in
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'test';

// Clear any output
if (ob_get_level()) {
    ob_clean();
}

// Start output buffering
ob_start();

// Set JSON header
header('Content-Type: application/json');

try {
    echo "Starting debug...\n";
    
    // Check if IMAP extension is available
    if (!extension_loaded('imap')) {
        throw new Exception('IMAP extension is not installed on this server');
    }
    echo "IMAP extension is available\n";
    
    $db = getDatabaseConnection();
    echo "Database connected\n";
    
    $cfg = [];
    $cfgFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
    if (is_file($cfgFile)) { 
        $cfg = require $cfgFile; 
        echo "Config file loaded\n";
    } else {
        echo "Config file not found, using default values\n";
        // Use the values you provided earlier
        $cfg = [
            'imap' => [
                'host' => 'imap.zone.eu',
                'port' => 993,
                'username' => 'info@teenus.ee',
                'password' => 'Yyd12321df42Xgus9WHT8xhic',
                'encryption' => 'ssl'
            ]
        ];
    }
    
    $imapCfg = $cfg['imap'] ?? [];
    $host = (string)($imapCfg['host'] ?? '');
    $port = (int)($imapCfg['port'] ?? 993);
    $username = (string)($imapCfg['username'] ?? '');
    $password = (string)($imapCfg['password'] ?? '');
    $encryption = (string)($imapCfg['encryption'] ?? 'ssl');
    
    echo "Host: $host\n";
    echo "Port: $port\n";
    echo "Username: $username\n";
    echo "Password: " . (empty($password) ? 'EMPTY' : 'SET') . "\n";
    echo "Encryption: $encryption\n";
    
    if (empty($host) || empty($username) || empty($password)) {
        throw new Exception('IMAP configuration missing');
    }
    
    // Connect to IMAP
    $connectionString = "{{$host}:{$port}/imap/{$encryption}/novalidate-cert}INBOX";
    echo "Connection string: $connectionString\n";
    
    $inbox = imap_open($connectionString, $username, $password);
    
    if (!$inbox) {
        $error = imap_last_error();
        throw new Exception("Failed to connect to IMAP server: {$error}");
    }
    
    echo "Connected to IMAP successfully!\n";
    
    // Get message count
    $messageCount = imap_num_msg($inbox);
    echo "Message count: $messageCount\n";
    
    // Get existing message IDs from database
    $existingIds = [];
    $stmt = $db->query('SELECT message_id FROM inbox_incoming');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingIds[$row['message_id']] = true;
    }
    echo "Existing messages in DB: " . count($existingIds) . "\n";
    
    // Process first 3 messages for testing
    $newEmailsCount = 0;
    $maxMessages = min($messageCount, 3);
    
    for ($i = 1; $i <= $maxMessages; $i++) {
        echo "Processing message $i...\n";
        
        $header = imap_headerinfo($inbox, $i);
        $messageId = $header->message_id ?? '';
        
        echo "Message ID: $messageId\n";
        
        // Skip if already exists
        if (isset($existingIds[$messageId])) {
            echo "Message already exists, skipping\n";
            continue;
        }
        
        // Extract email details
        $from = $header->from[0] ?? null;
        $fromEmail = $from ? $from->mailbox . '@' . $from->host : '';
        $fromName = $from ? ($from->personal ?? '') : '';
        $subject = $header->subject ?? '';
        $date = $header->date ?? '';
        
        echo "From: $fromEmail ($fromName)\n";
        echo "Subject: $subject\n";
        echo "Date: $date\n";
        
        // Get email body
        $body = imap_fetchbody($inbox, $i, 1);
        echo "Body length: " . strlen($body) . "\n";
        
        // Save to database
        $stmt = $db->prepare('INSERT INTO inbox_incoming (message_id, from_email, from_name, subject, mail_date, content_plain, attachments, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $result = $stmt->execute([
            $messageId,
            $fromEmail,
            $fromName,
            $subject,
            $date,
            $body,
            json_encode([]), // No attachments for now
            date('Y-m-d H:i:s')
        ]);
        
        if ($result) {
            echo "Message saved to database\n";
            $newEmailsCount++;
        } else {
            echo "Failed to save message to database\n";
        }
    }
    
    imap_close($inbox);
    echo "IMAP connection closed\n";
    
    // Clean output buffer and send JSON
    ob_clean();
    echo json_encode([
        'success' => true,
        'new_emails' => $newEmailsCount,
        'message' => "Successfully synced {$newEmailsCount} new emails",
        'debug_output' => ob_get_contents()
    ]);
    
} catch (Exception $e) {
    // Clean output buffer and send JSON error
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_output' => ob_get_contents()
    ]);
}
?>
