<?php
/**
 * Force Sync with GitHub
 * 
 * FIXES: "Update from remote" failures, conflict errors, and stale code.
 * ACTIONS:
 *  1. Fetches latest code from GitHub.
 *  2. FORCES a reset to match GitHub exactly (discards local changes).
 *  3. Clears PHP cache (OPcache) so changes appear immediately.
 */

// Security: Prevent caching of this output
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header('Content-Type: text/plain');

// Helper to handle output buffering
if (function_exists('ob_end_flush')) {
    while (ob_get_level() > 0)
        ob_end_flush();
}

echo "=== FORCE SYNC WITH GITHUB ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo "---------------------------------\n\n";

// Path Configuration
$repoPath = '/home/ravenscv/repositories/everydollar';

if (!is_dir($repoPath)) {
    die("CRITICAL ERROR: Repository directory not found at: $repoPath\n");
}

chdir($repoPath);
echo "Working Directory: " . getcwd() . "\n\n";

// 1. GIT COMMANDS
$commands = [
    "git fetch --all 2>&1",
    "git reset --hard origin/main 2>&1",
    "git log -n 1 --format='%h - %s (%ci)' 2>&1"
];

foreach ($commands as $cmd) {
    echo "[EXEC] $cmd\n";
    $output = [];
    $return_var = 0;
    exec($cmd, $output, $return_var);

    foreach ($output as $line) {
        echo "   > $line\n";
    }

    if ($return_var !== 0) {
        echo "   !!! COMMAND FAILED (Exit Code: $return_var) !!!\n";
        // Attempt fallback for permissions issues?
    }
    echo "\n";
}

// 2. CACHE CLEARING
echo "[CACHE] Clearing OPcache...\n";
if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "   > OPcache reset successfully.\n";
    } else {
        echo "   > OPcache reset returned false.\n";
    }
} else {
    echo "   > OPcache function not available (might be CLI mode or disabled).\n";
}

echo "\n---------------------------------\n";
echo "SYNC COMPLETE.\n";
echo "You can now run your migrations or debug scripts.";
