<?php
/**
 * Emergency Schema Fixer
 * Directly fixes the budget_months unique index issue.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting Schema Fix...<br>";

// 1. Load Config
$possiblePaths = [
    __DIR__ . '/../../config/everydollar/config.php', // Production structure usually
    __DIR__ . '/../config/everydollar/config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/../config/everydollar/config.php',
    '/home/ravenscv/config/everydollar/config.php' // Known path from previous logs
];

$config = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        echo "Found config at: $path<br>";
        $config = require $path;
        break;
    }
}

if (!$config) {
    die("CRITICAL: Config file not found.");
}

// 2. Connect
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
    echo "Connected to database.<br>";
} catch (PDOException $e) {
    die("Connection Failed: " . $e->getMessage());
}

// 3. execute Fixes
echo "Attempting to fix indices...<br>";

// FIX 0: Create replacement index for Foreign Key support first!
try {
    $pdo->exec("CREATE INDEX idx_household_fk ON budget_months (household_id)");
    echo "<span style='color:green'>SUCCESS: Created 'idx_household_fk' (FK Support)</span><br>";
} catch (PDOException $e) {
    echo "<span style='color:orange'>WARNING: Could not create 'idx_household_fk'. It might already exist. Error: " . $e->getMessage() . "</span><br>";
}

// FIX 1: Drop old idx_household_month
try {
    $pdo->exec("DROP INDEX idx_household_month ON budget_months");
    echo "<span style='color:green'>SUCCESS: Dropped 'idx_household_month'</span><br>";
} catch (PDOException $e) {
    echo "<span style='color:orange'>WARNING: Could not drop 'idx_household_month'. It might not exist. Error: " . $e->getMessage() . "</span><br>";
}

// FIX 2: Create new idx_entity_month
try {
    $pdo->exec("CREATE UNIQUE INDEX idx_entity_month ON budget_months (entity_id, month_yyyymm)");
    echo "<span style='color:green'>SUCCESS: Created 'idx_entity_month'</span><br>";
} catch (PDOException $e) {
    echo "<span style='color:orange'>WARNING: Could not create 'idx_entity_month'. It might already exist. Error: " . $e->getMessage() . "</span><br>";
}

echo "<br><strong>Fix Script Complete. Please try using the app now.</strong>";
