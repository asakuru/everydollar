<?php
/**
 * Simple Web Deployment Script
 * 
 * Usage: https://fuzzysolution.com/everydollar/deploy.php?key=YOUR_SECRET_KEY
 */

// SECURITY: Change this key!
// I've generated a random one for you:
$accessKey = 'deploy_secure_882910';

if (($_GET['key'] ?? '') !== $accessKey) {
    header('HTTP/1.0 403 Forbidden');
    die('Access Denied');
}

// Configuration
$repoPath = '/home/ravenscv/repositories/everydollar';
$phpPath = '/opt/cpanel/ea-php81/root/usr/bin/php';
$composerPath = '/usr/local/bin/composer';

// Headers for real-time output
header('Content-Type: text/plain');
header('X-Accel-Buffering: no'); // Nginx
ini_set('output_buffering', '0');
ini_set('implicit_flush', '1');

echo "Starting Deployment...\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "----------------------------------------\n";

$commands = [
    // 1. Go to repo
    "cd {$repoPath}",

    // 2. Discard local changes (fix conflicting files) and pull
    "echo 'Git: Fetching and Resetting...'",
    "git fetch origin main",
    "git reset --hard origin/main",

    // 3. Install Dependencies
    "echo 'Composer: Installing Dependencies...'",
    "{$phpPath} {$composerPath} install --no-dev --optimize-autoloader 2>&1",

    // 4. Run Migrations
    "echo 'Database: Running Migrations...'",
    "{$phpPath} migrations/migrate.php 2>&1",

    // 5. Clear Cache
    "echo 'System: Clearing Cache...'",
    "rm -rf storage/cache/twig/*",
    "echo 'Cache cleared.'"
];

foreach ($commands as $cmd) {
    echo "\n> $cmd\n";
    // Capture stderr too
    passthru($cmd);
    flush();
}

echo "\n----------------------------------------\n";
echo "DEPLOYMENT COMPLETE.\n";
