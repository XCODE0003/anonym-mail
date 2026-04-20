<?php

declare(strict_types=1);

/**
 * Postfix Policy Service
 * 
 * This script runs as a policy daemon for Postfix.
 * It checks if the sender has SMTP blocked.
 * 
 * Run via: spawn(8) in Postfix master.cf
 * 
 * Protocol: Postfix policy delegation protocol
 * https://www.postfix.org/SMTPD_POLICY_README.html
 */

namespace App\Mail;

// Bootstrap
require __DIR__ . '/../../vendor/autoload.php';

use PDO;

// Connect to database
function getDb(): PDO
{
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $_ENV['DB_HOST'] ?? 'postgres',
            $_ENV['DB_PORT'] ?? '5432',
            $_ENV['DB_NAME'] ?? 'mailservice'
        );
        
        $pdo = new PDO(
            $dsn,
            $_ENV['DB_USER'] ?? 'mailservice',
            $_ENV['DB_PASSWORD'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    
    return $pdo;
}

/**
 * Check if sender is blocked from SMTP.
 */
function isSenderBlocked(string $email): bool
{
    $parts = explode('@', $email, 2);
    
    if (count($parts) !== 2) {
        return true; // Invalid email, block
    }
    
    [$localPart, $domain] = $parts;
    
    $pdo = getDb();
    
    $stmt = $pdo->prepare(
        'SELECT smtp_blocked FROM users u
         JOIN domains d ON u.domain_id = d.id
         WHERE u.local_part = :local_part 
           AND d.name = :domain
           AND u.frozen = false
           AND (u.delete_after IS NULL OR u.delete_after > CURRENT_DATE)'
    );
    
    $stmt->execute([
        'local_part' => strtolower($localPart),
        'domain' => strtolower($domain),
    ]);
    
    $result = $stmt->fetch();
    
    if ($result === false) {
        return true; // User not found, block
    }
    
    return (bool) $result['smtp_blocked'];
}

/**
 * Main policy daemon loop.
 */
function main(): void
{
    // Read stdin in a loop
    while (true) {
        $attributes = [];
        
        // Read request attributes until empty line
        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            
            if ($line === '') {
                break;
            }
            
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $attributes[$key] = $value;
            }
        }
        
        // End of input
        if (empty($attributes)) {
            break;
        }
        
        // Get sender
        $sender = $attributes['sender'] ?? '';
        
        // Default response: allow
        $action = 'DUNNO';
        
        // Check if sender is blocked
        if ($sender !== '' && isSenderBlocked($sender)) {
            $unblockUrl = 'https://' . ($_ENV['PRIMARY_DOMAIN'] ?? 'localhost') . '/unblock.php';
            $action = "REJECT SMTP blocked. Please unblock at: $unblockUrl";
        }
        
        // Send response
        echo "action=$action\n\n";
        flush();
    }
}

// Run if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    main();
}
