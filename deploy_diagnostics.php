<?php
/**
 * Deployment Diagnostic Script
 * Purpose: Run git commands to debug why "Update from remote" is failing.
 * Usage: Upload to public_html/everydollar/ and visit in browser.
 */

// Set content type
header('Content-Type: text/plain');

// Determined from your previous error logs
$repoPath = '/home/ravenscv/repositories/everydollar';

echo "=== EVERYDOLLAR DEPLOYMENT DIAGNOSTICS ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "Target Repo: $repoPath\n\n";

if (!is_dir($repoPath)) {
    die("ERROR: Repository directory not found at $repoPath\n");
}

// Change to repo dir
chdir($repoPath);

// Helper to run commands
function run($cmd)
{
    echo "[$cmd]\n";
    $output = [];
    $return_var = 0;
    exec($cmd . ' 2>&1', $output, $return_var);
    echo implode("\n", $output) . "\n";
    echo "Exit Code: $return_var\n";
    echo str_repeat("-", 40) . "\n";
}

// 1. Check Status (Are there modified files blocking pull?)
run('git status');

// 2. Check Remote (Is it pointing to the right place?)
run('git remote -v');

// 3. Check Current Hash (What commit are we on?)
run('git log -n 1 --format="%h - %s (%ci)"');

// 4. Test Fetch (Can we talk to GitHub?)
run('git fetch origin main --verbose');

// 5. Dry Run Pull (What would happen?)
run('git merge --no-commit --no-ff origin/main --dry-run');

echo "\nDone.";
