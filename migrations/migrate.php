<?php
/**
 * Database Migration Utility
 * 
 * Usage: php migrations/migrate.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "EveryDollar Database Migrator\n";
echo "=============================\n\n";

// Define paths
$rootDir = dirname(__DIR__); // public_html/everydollar is root for web, but repo root is one up
// Wait, if this file is in /migrations, then __DIR__ is /path/to/repo/migrations
// So $rootDir is /path/to/repo
$rootDir = dirname(__DIR__);

echo "Repo Root: {$rootDir}\n";

// Load Config
$configPaths = [
    '/home/ravenscv/config/everydollar/config.php',  // Production
    $rootDir . '/config/everydollar/config.php',     // Standard dev
    $rootDir . '/config.php',                        // Local dev fallback
];

$config = null;
$configPath = null;

foreach ($configPaths as $path) {
    if (file_exists($path)) {
        $configPath = $path;
        echo "Loading config from: {$path}\n";
        $config = require $path;
        break;
    }
}

if (!$config) {
    die("Error: Config file not found.\n");
}

// Connect to Database
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['db']['host'],
        $config['db']['port'],
        $config['db']['database'],
        $config['db']['charset']
    );

    $pdo = new PDO($dsn, $config['db']['username'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Connected to database: {$config['db']['database']}\n\n";
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage() . "\n");
}

// Migration Files
$migrationFile = __DIR__ . '/002_multi_entity.sql';

if (!file_exists($migrationFile)) {
    die("Error: Migration file not found at {$migrationFile}\n");
}

echo "Running migration: 002_multi_entity.sql\n... ";

try {
    // Read SQL
    $sql = file_get_contents($migrationFile);

    // Strip comments to avoid parsing issues with logic
    $lines = explode("\n", $sql);
    $cleanLines = array_filter($lines, fn($line) => !str_starts_with(trim($line), '--'));
    $cleanSql = implode("\n", $cleanLines);

    // Split into statements
    $statements = array_filter(
        array_map('trim', explode(';', $cleanSql)),
        fn($s) => !empty($s)
    );

    $pdo->beginTransaction();

    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // Check for duplicate/existing errors
            // 1060: Duplicate column name
            // 1050: Table already exists
            // 1061: Duplicate key name
            // 1005 (errno 121): Duplicate key on write or update

            $msg = $e->getMessage();
            $code = $e->getCode();

            if (
                $code == '42S21' || // Column already exists
                $code == '42S01' || // Table already exists
                str_contains($msg, 'Duplicate column name') ||
                str_contains($msg, 'already exists') ||
                str_contains($msg, 'Duplicate key') ||
                str_contains($msg, 'errno: 121')
            ) {
                echo "Warning: Skipped duplicate/existing item.\n";
                // Continue to next statement
                continue;
            }

            // Re-throw other errors
            throw $e;
        }
    }

    $pdo->commit();
    echo "Done!\n";
    echo "Success: Migration 002 applied successfully.\n";

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "FAILED!\n";
    echo "Error: " . $e->getMessage() . "\n";
}
