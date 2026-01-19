<?php
/**
 * Git Fix Script
 * 
 * executing a hard reset to force the server to match the GitHub repository.
 * WARNING: This will discard any changes made directly to files on the server.
 */

header('Content-Type: text/plain');

// Path derived from your error logs
$repoPath = '/home/ravenscv/repositories/everydollar';

echo "=== GIT FIX INITIATED ===\n";
echo "Target: $repoPath\n";

if (!is_dir($repoPath)) {
    die("Error: Repository path not found.");
}

chdir($repoPath);

// Set environment for cPanel git
putenv("HOME=/home/ravenscv");

function run($cmd)
{
    echo "\n> $cmd\n";
    $output = [];
    $return_var = 0;
    exec($cmd . ' 2>&1', $output, $return_var);
    echo implode("\n", $output);
}

// 1. Fetch latest details from GitHub
run('git fetch origin main');

// 2. FORCE reset to match GitHub exactly
// This fixes "Update from remote" errors caused by conflicts
run('git reset --hard origin/main');

echo "\n\n=== DONE ===\n";
echo "Please try accessing the Auto-Categorization page again.";
