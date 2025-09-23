<?php
declare(strict_types=1);

// Copy this file to config.local.php and fill in real credentials.
// Ensure config.local.php is NOT committed to Git.

return [
    'imap' => [
        // Example: imap.zone.eu with SSL on 993
        'host' => 'imap.zone.eu',
        'port' => 993,
        // one of: 'ssl', 'tls', 'starttls', or 'none'
        'encryption' => 'ssl',
        'username' => 'info@example.com',
        'password' => 'REPLACE_ME',
        // Optional: validate certs; some shared hosts may need novalidate-cert
        'validate_cert' => false,
        // Max emails to fetch per page
        'limit' => 20,
    ],
    'smtp' => [
        // For sending (not used yet). Example: smtp.zone.eu 465 SSL/TLS
        'host' => 'smtp.zone.eu',
        'port' => 465,
        'encryption' => 'ssl', // or 'starttls'
        'username' => 'info@example.com',
        'password' => 'REPLACE_ME',
    ],
];


