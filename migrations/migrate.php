<?php
/**
 * Migration Runner
 * 
 * Simple migration system that tracks which migrations have been applied.
 * Run from command line: php migrations/migrate.php
 */

declare(strict_types=1);

// Ensure running from CLI
if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

define('ROOT_DIR', dirname(__DIR__));

// Load configuration
$configPath = dirname(ROOT_DIR) . '/config/everydollar/config.php';
if (!file_exists($configPath)) {
    $configPath = ROOT_DIR . '/config.php';
}

if (!file_exists($configPath)) {
    echo "Error: config.php not found.\n";
    echo "Please copy config.sample.php to config.php and configure your database.\n";
    exit(1);
}

$config = require $configPath;

// Connect to database
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

    echo "Connected to database: {$config['db']['database']}\n";
} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Get list of migration files
$migrationsDir = __DIR__;
$migrationFiles = glob($migrationsDir . '/*.sql');
natsort($migrationFiles);

if (empty($migrationFiles)) {
    echo "No migration files found in {$migrationsDir}\n";
    exit(0);
}

// Get applied migrations
$appliedMigrations = [];
try {
    // Check if migrations table exists
    $result = $pdo->query("SHOW TABLES LIKE 'migrations'");
    if ($result->rowCount() > 0) {
        $stmt = $pdo->query("SELECT migration FROM migrations ORDER BY id");
        $appliedMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    // migrations table doesn't exist yet, will be created by first migration
}

$applied = 0;
$skipped = 0;

foreach ($migrationFiles as $file) {
    $filename = basename($file);

    if (in_array($filename, $appliedMigrations)) {
        echo "SKIP: {$filename} (already applied)\n";
        $skipped++;
        continue;
    }

    echo "APPLYING: {$filename}...\n";

    try {
        // Read and execute migration
        $sql = file_get_contents($file);

        // Split by semicolon and execute each statement
        // (PDO doesn't support multiple statements in one execute)
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s) && !str_starts_with($s, '--')
        );

        $pdo->beginTransaction();

        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                $pdo->exec($statement);
            }
        }

        // Record migration as applied
        $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (?)");
        $stmt->execute([$filename]);

        $pdo->commit();

        echo "SUCCESS: {$filename}\n";
        $applied++;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "FAILED: {$filename}\n";
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n";
echo "Migration complete.\n";
echo "Applied: {$applied}, Skipped: {$skipped}\n";
