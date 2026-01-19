<?php
/**
 * Configuration Template
 * 
 * Copy this file to one of these locations (in order of preference):
 *   1. /home/<cpanel-user>/config/everydollar/config.php (recommended - outside webroot)
 *   2. ./config.php (same directory as this file - development only)
 * 
 * NEVER commit the actual config.php file with secrets!
 */

return [
    // Database Configuration
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'your_database_name',
        'username' => 'your_database_user',
        'password' => 'YOUR_DATABASE_PASSWORD_HERE',
        'charset' => 'utf8mb4',
    ],

    // Application Settings
    'app' => [
        'name' => 'EveryDollar Budget',
        'env' => 'production', // 'development' or 'production'
        'debug' => false,      // Set to true only in development
        'base_path' => '/everydollar',
        'url' => 'https://fuzzysolution.com/everydollar',
    ],

    // Session Configuration
    'session' => [
        'name' => 'ed_session',
        'lifetime' => 0,           // 0 = until browser closes
        'path' => '/everydollar',  // Scoped to this app only
        'secure' => true,          // HTTPS only
        'httponly' => true,        // No JavaScript access
        'samesite' => 'Lax',       // CSRF protection
    ],

    // Security Settings
    'security' => [
        // Secret key for CSRF tokens and other cryptographic operations
        // Generate with: php -r "echo bin2hex(random_bytes(32));"
        'secret_key' => 'GENERATE_A_64_CHARACTER_HEX_STRING_HERE',

        // Enable HSTS header (only enable after confirming HTTPS works correctly)
        // Warning: Once enabled, browsers will refuse HTTP for max_age seconds
        'hsts_enabled' => true,
        'hsts_max_age' => 31536000, // 1 year in seconds

        // Rate limiting thresholds
        'rate_limit' => [
            'login_attempts_per_ip' => 5,
            'login_attempts_per_account' => 3,
            'lockout_minutes' => 15,
        ],

        // Password requirements
        'password' => [
            'min_length' => 12,
            'check_common' => true,
        ],
    ],

    // 2FA Configuration (for future implementation)
    'totp' => [
        // Encryption key for TOTP secrets (32 bytes, base64 encoded)
        // Generate with: php -r "echo base64_encode(random_bytes(32));"
        'encryption_key' => 'GENERATE_A_BASE64_ENCODED_32_BYTE_KEY_HERE',
        'issuer' => 'EveryDollar Budget',
    ],

    // Logging
    'logging' => [
        'path' => ROOT_DIR . '/storage/logs/app.log',
        'level' => 'warning', // debug, info, notice, warning, error, critical, alert, emergency
    ],

    // Twig Template Settings
    'twig' => [
        'cache' => ROOT_DIR . '/storage/cache/twig',
        'debug' => false,
        'auto_reload' => false,
    ],
];
