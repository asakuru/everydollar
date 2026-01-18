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

// Helper to execute commands robustly
function runCommand($cmd)
{
    echo "\n> $cmd\n";

    // 1. Try system() - prints output as it goes
    if (function_exists('system')) {
        @system($cmd);
        return;
    }

    // 2. Try passthru() - raw output
    if (function_exists('passthru')) {
        @passthru($cmd);
        return;
    }

    // 3. Try exec() - captures output
    if (function_exists('exec')) {
        $output = [];
        @exec($cmd . ' 2>&1', $output);
        echo implode("\n", $output);
        return;
    }

    // 4. Try shell_exec() - returns output as string
    if (function_exists('shell_exec')) {
        echo @shell_exec($cmd . ' 2>&1');
        return;
    }

    echo "ERROR: No shell execution functions available (system, passthru, exec, shell_exec).\n";
    echo "Cannot execute command.\n";
}

$commands = [
    // Git: Fetch and Reset (Chain cd to ensure correct dir)
    "cd {$repoPath} && git fetch origin main",
    "cd {$repoPath} && git reset --hard origin/main",

    // Composer: Install dependencies
    "cd {$repoPath} && {$phpPath} {$composerPath} install --no-dev --optimize-autoloader",

    // Database: Run Migrations
    "cd {$repoPath} && {$phpPath} migrations/migrate.php",

    // Deploy: Copy files to web root
    "cp -R {$repoPath}/public_html/everydollar/. /home/ravenscv/fuzzysolution.com/everydollar/",

    // Cache: Clear Twig cache
    "rm -rf /home/ravenscv/repositories/everydollar/storage/cache/twig/*"
];

foreach ($commands as $cmd) {
    runCommand($cmd);
    flush();
}

echo "\n----------------------------------------\n";
echo "DEPLOYMENT COMPLETE.\n";
