<?php
/**
 * Web Migration Runner (Path-Aware)
 * 
 * Attempts to locate the migration script in common locations.
 */
header('Content-Type: text/plain');
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "=== DIRECT MIGRATION RUNNER v2 ===\n";

// List of possible locations for the migration script
$candidates = [
    // 1. If run from repo root
    __DIR__ . '/migrations/migrate.php',

    // 2. If run from public_html/everydollar (repo is 2 levels up)
    dirname(__DIR__, 2) . '/migrations/migrate.php',

    // 3. Absolute path to repository (standard cPanel setup)
    '/home/ravenscv/repositories/everydollar/migrations/migrate.php'
];

$migrateScript = null;

foreach ($candidates as $candidate) {
    if (file_exists($candidate)) {
        $migrateScript = $candidate;
        break;
    }
}

if (!$migrateScript) {
    echo "CRITICAL: Could not find migrations/migrate.php.\n";
    echo "Checked locations:\n";
    foreach ($candidates as $c) {
        echo " - $c\n";
    }
    die();
}

echo "Found migration script at: $migrateScript\n";
echo "Executing...\n";
echo "---------------------------------------------------\n\n";

try {
    // IMPORTANT: migrate.php expects to be run from CLI or at least have assumptions about CWD
    // We'll require it.
    require $migrateScript;

} catch (Throwable $e) {
    echo "\n\n!!! EXCEPTION !!!\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}

echo "\n\n---------------------------------------------------\n";
echo "Done.";
