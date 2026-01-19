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
$rootDir = dirname(__DIR__);
$migrationsDir = __DIR__;

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

// Ensure migrations table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL,
        executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE INDEX idx_migration (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Get executed migrations
$executed = $pdo->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN);

// Scan for .sql files
$files = glob($migrationsDir . '/*.sql');
sort($files); // Ensure order (001, 002, 003...)

foreach ($files as $file) {
    $filename = basename($file, '.sql');

    // Skip if already executed
    if (in_array($filename, $executed)) {
        echo "[SKIP] {$filename} (already executed)\n";
        continue;
    }

    echo "[RUN ] {$filename}...\n";

    try {
        // Read SQL
        $sql = file_get_contents($file);

        // Strip comments simple approach
        $lines = explode("\n", $sql);
        $cleanLines = array_filter($lines, fn($line) => !str_starts_with(trim($line), '--'));
        $cleanSql = implode("\n", $cleanLines);

        // Helper to split by semicolon but respect basic SQL structure
        // ideally we'd use a parser, but simple split usually works for migrations
        $statements = array_filter(
            array_map('trim', explode(';', $cleanSql)),
            fn($s) => !empty($s)
        );

        // MySQL DDL statements cause implicit commits, so we cannot uses transactions reliably for schema changes.
        // We will execute statements one by one.

        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                // Ignore "Table already exists" or "Duplicate column" type errors if re-running
                // But generally clean scripts shouldn't error.
                // For now, let's catch critical ones.
                throw $e;
            }
        }

        // Log migration
        $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (?)");
        $stmt->execute([$filename]);

        echo "       -> Success!\n";

    } catch (Exception $e) {
        echo "       -> FAILED: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\nAll migrations up to date.\n";
