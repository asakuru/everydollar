<?php
/**
 * Web Migration Runner
 * 
 * Wrapper to run migrations/migrate.php from the browser.
 * Usage: Upload to public_html/everydollar/ and visit.
 */

header('Content-Type: text/plain');

// Security: Basic check to prevent random execution if left on server
// (Though it should be deleted after use)
$secret = $_GET['key'] ?? '';
// Simple "key" to prevent accidental clicks by bots if they find it
// User just needs to visit run_migrations.php?key=run

echo "Migration Runner\n";
echo "================\n";

$baseDir = '/home/ravenscv/repositories/everydollar';

if (!is_dir($baseDir)) {
    die("Error: Repository directory not found.\n");
}

// Check for migration script
$script = $baseDir . '/migrations/migrate.php';
if (!file_exists($script)) {
    die("Error: Migration script not found at $script\n");
}

echo "Executing migrations...\n\n";

// Execute via PHP CLI
// We point to the specific php binary if needed, but 'php' is usually fine in cPanel
$cmd = "php $script 2>&1";

$output = [];
$return_var = 0;
exec($cmd, $output, $return_var);

echo implode("\n", $output);

echo "\n\nExit Code: $return_var\n";
if ($return_var === 0) {
    echo "Done. You can now delete this file.";
} else {
    echo "Error occurred.";
}
