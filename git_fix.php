<?php
/**
 * Git Fix & Verify Script
 * 
 * 1. Clears OPcache
 * 2. Hard resets Git
 * 3. Verifies file content immediately
 */

// Try to clear PHP's internal cache
if (function_exists('opcache_reset')) {
    opcache_reset();
}

header('Content-Type: text/plain');
$repoPath = '/home/ravenscv/repositories/everydollar';

echo "=== DIAGNOSTIC FIX TOOL ===\n";
echo "Timestamp: " . date('H:i:s') . "\n";

if (!is_dir($repoPath)) {
    die("CRITICAL: Repo path does not exist.\n");
}
chdir($repoPath);

// 1. GIT OPERATION
echo "\n[1] Running Git Reset...\n";
$out = [];
exec('git fetch origin main 2>&1', $out);
exec('git reset --hard origin/main 2>&1', $out);
echo implode("\n", $out) . "\n";

// 2. CHECK FILE CONTENT
echo "\n[2] Verifying src/routes.php...\n";
$routesFile = $repoPath . '/src/routes.php';
$content = file_get_contents($routesFile);

if (strpos($content, '/settings/rules') !== false) {
    echo ">>> SUCCESS: The file HAS the new route definition.\n";
    echo "If you still get 404, it is definitely a caching issue.\n";
} else {
    echo ">>> FAILURE: The file is STILL OLD/MISSING the route.\n";
    echo "File Permissions: " . substr(sprintf('%o', fileperms($routesFile)), -4) . "\n";
    echo "File Owner: " . fileowner($routesFile) . "\n";
    echo "Process User: " . get_current_user() . "\n";
}

echo "\n[3] Checking HEAD commit...\n";
exec('git log -n 1 --format="%h - %s"', $log);
echo implode("\n", $log) . "\n";

echo "\nCompleted.";
