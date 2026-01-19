<?php
/**
 * Web Migration Runner (Direct Include Version)
 * 
 * Bypasses shell_exec/exec which might be disabled or failing silently.
 * Directly includes the PHP migration logic.
 */
header('Content-Type: text/plain');
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "=== DIRECT MIGRATION RUNNER ===\n";
echo "Mode: In-Process Include\n\n";

// Ensure we are in the project root
chdir(__DIR__);

$migrateScript = __DIR__ . '/migrations/migrate.php';

if (!file_exists($migrateScript)) {
    die("CRITICAL: Migration script missing at $migrateScript");
}

echo "Including $migrateScript ...\n";
echo "---------------------------------------------------\n";

try {
    // Run the script directly in this process
    require $migrateScript;
} catch (Throwable $e) {
    echo "\n\n!!! EXCEPTION !!!\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}

echo "\n---------------------------------------------------\n";
echo "End of execution.";
