<?php
/**
 * Web-based Database Migrator
 * 
 * Usage: https://fuzzysolution.com/everydollar/migrate.php?key=everydollar2024install
 * 
 * DELETE THIS FILE AFTER USE!
 */

// Security Key
$filesKey = 'everydollar2024install';

if (($_GET['key'] ?? '') !== $filesKey) {
    die('Access denied. Add ?key=' . $filesKey . ' to the URL');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<html><head><title>EveryDollar Migrator</title>";
echo "<style>body{font-family:monospace;background:#1a1a2e;color:#eee;padding:20px;} .success{color:#4ade80;} .error{color:#f87171;}</style>";
echo "</head><body>";
echo "<h1>EveryDollar Database Migrator</h1>";

// Define paths
$repoRoot = '/home/ravenscv/repositories/everydollar';
echo "<p>Repo Root: {$repoRoot}</p>";

// Load Config - Reuse logic from install.php
$configPaths = [
    '/home/ravenscv/config/everydollar/config.php',
    dirname($repoRoot) . '/config/everydollar/config.php',
    $repoRoot . '/config.php',
];

$config = null;
$configPath = null;

foreach ($configPaths as $path) {
    if (file_exists($path)) {
        $configPath = $path;
        echo "<p class='success'>✓ Config found: {$path}</p>";
        $config = require $path;
        break;
    }
}

if (!$config) {
    die("<p class='error'>✗ Config not found!</p></body></html>");
}

// Connect to DB
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

    echo "<p class='success'>✓ Connected to database</p>";
} catch (PDOException $e) {
    die("<p class='error'>✗ DB Connection Error: " . htmlspecialchars($e->getMessage()) . "</p></body></html>");
}

// Migration File
$migrationFile = $repoRoot . '/migrations/002_multi_entity.sql';

if (!file_exists($migrationFile)) {
    die("<p class='error'>✗ Migration file not found: {$migrationFile}</p></body></html>");
}

echo "<h2>Running Migration: 002_multi_entity.sql</h2>";

try {
    $sql = file_get_contents($migrationFile);

    // Strip comments
    $lines = explode("\n", $sql);
    $cleanLines = array_filter($lines, fn($line) => !str_starts_with(trim($line), '--'));
    $cleanSql = implode("\n", $cleanLines);

    // Split statements
    $statements = array_filter(
        array_map('trim', explode(';', $cleanSql)),
        fn($s) => !empty($s)
    );

    $total = count($statements);
    echo "<p>Found {$total} SQL statements to execute...</p>";

    $pdo->beginTransaction();

    $i = 0;
    foreach ($statements as $stmt) {
        $i++;
        // Attempt execution
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // Ignore "Duplicate column" or "Table exists" errors if re-running
            if (str_contains($e->getMessage(), 'Duplicate column') || str_contains($e->getMessage(), 'already exists')) {
                echo "<p class='warning'>⚠ Statment {$i}: Already applied (Skipping)</p>";
                continue;
            }
            throw $e;
        }
    }

    // Check if migration is recorded
    $stmt = $pdo->query("SELECT migration FROM migrations WHERE migration = '002_multi_entity.sql'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("INSERT INTO migrations (migration) VALUES ('002_multi_entity.sql')");
    }

    $pdo->commit();
    echo "<h2 class='success'>✓ MIGRATION SUCCESSFUL!</h2>";
    echo "<p>You can now delete this file.</p>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<h2 class='error'>✗ MIGRATION FAILED</h2>";
    echo "<p class='error'>" . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";
