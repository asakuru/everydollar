<?php
/**
 * System Health Check
 * Verifies Code, Database, and Routing to diagnose 500/404 errors.
 */
header('Content-Type: text/plain');

echo "=== EVERYDOLLAR HEALTH CHECK ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

$repoPath = '/home/ravenscv/repositories/everydollar';
chdir($repoPath);

// 1. CHECK GIT VERSION
echo "[1] Checking Code Version and Features...\n";
exec('git log -n 1 --format="%h - %s"', $git_output);
echo "    Commit: " . implod($git_output) . "\n";
echo "    PHP Version: " . phpversion() . "\n";

// Check key files
$files = [
    'src/routes.php' => 'Rules Route',
    'src/Services/AutoCategorizationService.php' => 'Service Class',
    'templates/settings/rules.twig' => 'Rules Template'
];
foreach ($files as $file => $label) {
    if (file_exists($repoPath . '/' . $file)) {
        $content = file_get_contents($repoPath . '/' . $file);

        if ($file === 'src/routes.php') {
            if (strpos($content, '/settings/rules/seed') !== false) {
                echo "    [OK] Route '/settings/rules/seed' found.\n";
            } else {
                echo "    [FAIL] Route '/settings/rules/seed' MISSING.\n";
            }
        } elseif ($file === 'src/Services/AutoCategorizationService.php') {
            if (strpos($content, 'seedDefaultRules') !== false) {
                echo "    [OK] Method 'seedDefaultRules' found.\n";
            } else {
                echo "    [FAIL] Method 'seedDefaultRules' MISSING.\n";
            }
        } else {
            echo "    [OK] $label file exists.\n";
        }
    } else {
        echo "    [FAIL] $file is MISSING.\n";
    }
}

// 2. CHECK DATABASE
echo "\n[2] Checking Database...\n";
$configPath = '/home/ravenscv/config/everydollar/config.php';
if (!file_exists($configPath)) {
    die("    [FAIL] Config file not found at $configPath\n");
}
$config = require $configPath;

try {
    $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['database']};charset={$config['db']['charset']}";
    $pdo = new PDO($dsn, $config['db']['username'], $config['db']['password']);
    echo "    [OK] Connected to database.\n";

    // Check tables
    $tables = ['migrations', 'transaction_rules', 'categories'];
    foreach ($tables as $table) {
        try {
            $result = $pdo->query("SELECT 1 FROM $table LIMIT 1");
            echo "    [OK] Table '$table' exists.\n";
        } catch (PDOException $e) {
            echo "    [FAIL] Table '$table' DOES NOT EXIST.\n";
        }
    }

} catch (PDOException $e) {
    echo "    [FAIL] Database Connection Error: " . $e->getMessage() . "\n";
}

// 3. CHECK OPCACHE
echo "\n[3] Checking Cache...\n";
if (function_exists('opcache_invalidate')) {
    echo "    Attempting to bust cache...\n";
    opcache_reset();
    echo "    [OK] Cache reset.\n";
} else {
    echo "    [INFO] OPcache functions not available.\n";
}

echo "\nDone.";

function implod($arr)
{
    return is_array($arr) ? implode("\n", $arr) : $arr;
}
