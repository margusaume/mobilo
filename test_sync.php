<?php
// Simple test to check IMAP sync
session_start();
require_once __DIR__ . '/inc/db.php';

// Simulate being logged in
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'test';

// Simulate POST request
$_POST['sync_imap'] = '1';

// Include the IMAP sync logic from dashboard.php
try {
    $db = getDatabaseConnection();
    
    // Check if IMAP extension is available
    if (!extension_loaded('imap')) {
        throw new Exception('IMAP extension is not installed on this server');
    }
    
    $cfg = [];
    $cfgFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
    if (is_file($cfgFile)) { 
        $cfg = require $cfgFile; 
    } else {
        // Use hardcoded values
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
    
    if (empty($host) || empty($username) || empty($password)) {
        throw new Exception('IMAP configuration missing');
    }
    
    // Connect to IMAP
    $connectionString = "{{$host}:{$port}/imap/{$encryption}/novalidate-cert}INBOX";
    $inbox = imap_open($connectionString, $username, $password);
    
    if (!$inbox) {
        $error = imap_last_error();
        throw new Exception("Failed to connect to IMAP server: {$error}");
    }
    
    // Get message count
    $messageCount = imap_num_msg($inbox);
    $newEmailsCount = 0;
    
    // Get existing message IDs from database
    $existingIds = [];
    $stmt = $db->query('SELECT message_id FROM inbox_incoming');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingIds[$row['message_id']] = true;
    }
    
    echo "<h2>IMAP Sync Test</h2>";
    echo "<p>Total messages in IMAP: $messageCount</p>";
    echo "<p>Existing messages in DB: " . count($existingIds) . "</p>";
    
    // Process new messages
    for ($i = 1; $i <= $messageCount; $i++) {
        $header = imap_headerinfo($inbox, $i);
        $messageId = $header->message_id ?? '';
        
        // Skip if already exists
        if (isset($existingIds[$messageId])) {
            continue;
        }
        
        // Extract email details
        $from = $header->from[0] ?? null;
        $fromEmail = $from ? $from->mailbox . '@' . $from->host : '';
        $fromName = $from ? ($from->personal ?? '') : '';
        $subject = $header->subject ?? '';
        $date = $header->date ?? '';
        
        // Get email body
        $body = imap_fetchbody($inbox, $i, 1);
        
        // Save to database
        $stmt = $db->prepare('INSERT INTO inbox_incoming (message_id, from_email, from_name, subject, mail_date, content_plain, attachments, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $messageId,
            $fromEmail,
            $fromName,
            $subject,
            $date,
            $body,
            json_encode([]),
            date('Y-m-d H:i:s')
        ]);
        
        $newEmailsCount++;
        echo "<p>Added new email: $fromEmail - $subject</p>";
    }
    
    imap_close($inbox);
    
    echo "<p><strong>Result: Successfully synced $newEmailsCount new emails</strong></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'><strong>Error: " . htmlspecialchars($e->getMessage()) . "</strong></p>";
}
?>
