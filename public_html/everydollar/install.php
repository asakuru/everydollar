<?php
/**
 * Web-based Installer
 * 
 * Access this file at: https://fuzzysolution.com/everydollar/install.php
 * DELETE THIS FILE after installation is complete!
 */

// Security: Only allow running once, and require a secret key
$installKey = 'everydollar2024install';

if (($_GET['key'] ?? '') !== $installKey) {
    die('Access denied. Add ?key=' . $installKey . ' to the URL');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<html><head><title>EveryDollar Installer</title>";
echo "<style>body{font-family:Arial,sans-serif;max-width:800px;margin:50px auto;padding:20px;background:#1a1a2e;color:#eee;}";
echo ".success{color:#4ade80;}.error{color:#f87171;}.warning{color:#facc15;}pre{background:#0d0d1a;padding:15px;border-radius:8px;overflow-x:auto;}</style>";
echo "</head><body>";
echo "<h1>EveryDollar Installer</h1>";

// Define paths
$repoRoot = dirname(__DIR__, 2); // Go up from public_html/everydollar to repo root
echo "<p>Repository root: <code>{$repoRoot}</code></p>";

// Step 1: Check PHP version
echo "<h2>Step 1: Check PHP Version</h2>";
$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '8.1.0', '>=')) {
    echo "<p class='success'>✓ PHP {$phpVersion} - OK</p>";
} else {
    echo "<p class='error'>✗ PHP {$phpVersion} - Need 8.1+</p>";
}

// Step 2: Check for config file
echo "<h2>Step 2: Check Configuration</h2>";
$configPaths = [
    dirname($repoRoot) . '/config/everydollar/config.php',
    $repoRoot . '/config.php',
];

$configFound = false;
$configPath = null;
foreach ($configPaths as $path) {
    if (file_exists($path)) {
        $configFound = true;
        $configPath = $path;
        echo "<p class='success'>✓ Config found at: {$path}</p>";
        break;
    }
}

if (!$configFound) {
    echo "<p class='error'>✗ No config.php found!</p>";
    echo "<p>Please create config.php at one of these locations:</p>";
    echo "<ul>";
    foreach ($configPaths as $path) {
        echo "<li><code>{$path}</code></li>";
    }
    echo "</ul>";
    echo "<p>Copy from <code>{$repoRoot}/config.sample.php</code></p>";
    echo "</body></html>";
    exit;
}

// Load config
$config = require $configPath;

// Step 3: Check vendor directory
echo "<h2>Step 3: Check Composer Dependencies</h2>";
$vendorPath = $repoRoot . '/vendor/autoload.php';
if (file_exists($vendorPath)) {
    echo "<p class='success'>✓ Vendor directory exists</p>";
    require $vendorPath;
} else {
    echo "<p class='error'>✗ Vendor directory missing!</p>";
    echo "<p>You need to run <code>composer install</code> via SSH or cPanel Terminal:</p>";
    echo "<pre>cd ~/repositories/everydollar\ncomposer install --no-dev --optimize-autoloader</pre>";
    echo "<p class='warning'>If you don't have terminal access, contact your host about running composer.</p>";
    echo "</body></html>";
    exit;
}

// Step 4: Check/create storage directories
echo "<h2>Step 4: Storage Directories</h2>";
$storageDirs = [
    $repoRoot . '/storage',
    $repoRoot . '/storage/logs',
    $repoRoot . '/storage/cache',
    $repoRoot . '/storage/cache/twig',
];

foreach ($storageDirs as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0755, true)) {
            echo "<p class='success'>✓ Created: {$dir}</p>";
        } else {
            echo "<p class='error'>✗ Failed to create: {$dir}</p>";
        }
    } else {
        echo "<p class='success'>✓ Exists: {$dir}</p>";
    }
}

// Step 5: Database connection
echo "<h2>Step 5: Database Connection</h2>";
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

    echo "<p class='success'>✓ Connected to database: {$config['db']['database']}</p>";
} catch (PDOException $e) {
    echo "<p class='error'>✗ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Check your database credentials in config.php</p>";
    echo "</body></html>";
    exit;
}

// Step 6: Run migrations
echo "<h2>Step 6: Run Migrations</h2>";

$migrationsDir = $repoRoot . '/migrations';
$migrationFiles = glob($migrationsDir . '/*.sql');
natsort($migrationFiles);

// Check if migrations table exists
$tablesResult = $pdo->query("SHOW TABLES LIKE 'migrations'");
$migrationsTableExists = $tablesResult->rowCount() > 0;

$appliedMigrations = [];
if ($migrationsTableExists) {
    $stmt = $pdo->query("SELECT migration FROM migrations ORDER BY id");
    $appliedMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$applied = 0;
$skipped = 0;
$errors = [];

foreach ($migrationFiles as $file) {
    $filename = basename($file);

    if (in_array($filename, $appliedMigrations)) {
        echo "<p class='success'>✓ SKIP: {$filename} (already applied)</p>";
        $skipped++;
        continue;
    }

    echo "<p>Applying: {$filename}...</p>";

    try {
        $sql = file_get_contents($file);
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s) && !str_starts_with(trim($s), '--')
        );

        $pdo->beginTransaction();

        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                $pdo->exec($statement);
            }
        }

        // Record migration
        $stmt = $pdo->prepare("INSERT INTO migrations (migration) VALUES (?)");
        $stmt->execute([$filename]);

        $pdo->commit();

        echo "<p class='success'>✓ SUCCESS: {$filename}</p>";
        $applied++;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "<p class='error'>✗ FAILED: {$filename} - " . htmlspecialchars($e->getMessage()) . "</p>";
        $errors[] = $filename;
    }
}

echo "<p><strong>Migrations: {$applied} applied, {$skipped} skipped, " . count($errors) . " errors</strong></p>";

// Step 7: Summary
echo "<h2>Installation Complete!</h2>";

if (empty($errors)) {
    echo "<p class='success'>✓ All steps completed successfully!</p>";
    echo "<p><strong>Next Steps:</strong></p>";
    echo "<ol>";
    echo "<li>Visit <a href='/everydollar/setup' style='color:#4ade80;'>/everydollar/setup</a> to create your household</li>";
    echo "<li><strong class='error'>DELETE this install.php file immediately!</strong></li>";
    echo "</ol>";
} else {
    echo "<p class='error'>Some errors occurred. Please fix them and refresh this page.</p>";
}

echo "<hr>";
echo "<p class='warning'>⚠️ Security Warning: Delete this file after installation:<br>";
echo "<code>" . __FILE__ . "</code></p>";

echo "</body></html>";
