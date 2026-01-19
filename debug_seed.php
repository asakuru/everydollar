<?php
/**
 * Debug Seed Functionality
 * 
 * Manually attempts to seed rules for the first household found
 * and prints verbose error information.
 */
header('Content-Type: text/plain');
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "=== DEBUG SEED DEFAULTS ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Load Config
$configPath = '/home/ravenscv/config/everydollar/config.php';
if (!file_exists($configPath)) {
    die("Config not found at $configPath");
}
$config = require $configPath;

// 2. Connect DB
try {
    $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['database']};charset={$config['db']['charset']}";
    $pdo = new PDO($dsn, $config['db']['username'], $config['db']['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "[OK] Connected to database.\n";
} catch (Exception $e) {
    die("[FAIL] DB Connection: " . $e->getMessage());
}

// 3. Get Household
$stmt = $pdo->query("SELECT id, name FROM households LIMIT 1");
$household = $stmt->fetch();
if (!$household) {
    die("[FAIL] No households found to test with.");
}
$householdId = (int) $household['id'];
echo "[OK] Testing with Household ID: $householdId ({$household['name']})\n";

// 4. Test Logic
try {
    echo "\n--- Starting Seed Logic ---\n";

    // Get Categories
    echo "Fetching categories...";
    $stmt = $pdo->prepare("SELECT id, name FROM categories WHERE category_group_id IN (SELECT id FROM category_groups WHERE household_id = ?)");
    $stmt->execute([$householdId]);
    $categories = $stmt->fetchAll();
    echo " Found " . count($categories) . " categories.\n";

    $catMap = [];
    foreach ($categories as $cat) {
        $catMap[$cat['name']] = $cat['id'];
        echo "   - {$cat['name']} (ID: {$cat['id']})\n";
    }

    // Test Rule Insertion (Dry Run of one known rule)
    $testRule = ['term' => 'Walmart', 'cat' => 'Groceries'];

    if (isset($catMap[$testRule['cat']])) {
        echo "\nAttempting to insert test rule ('{$testRule['term']}' -> '{$testRule['cat']}')...\n";

        // Check if exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transaction_rules WHERE household_id = ? AND search_term = ?");
        $stmt->execute([$householdId, $testRule['term']]);
        if ($stmt->fetchColumn() > 0) {
            echo "[INFO] Rule already exists. Skipping insert test.\n";
        } else {
            $catId = $catMap[$testRule['cat']];
            $sql = "INSERT INTO transaction_rules 
                    (household_id, search_term, category_id, match_type, created_at, updated_at) 
                    VALUES (?, ?, ?, 'contains', NOW(), NOW())";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$householdId, $testRule['term'], $catId]);
            echo "[SUCCESS] Inserted test rule! ID: " . $pdo->lastInsertId() . "\n";
        }
    } else {
        echo "[INFO] Category '{$testRule['cat']}' not found in specific household. Skipping insert test.\n";
    }

    echo "\n[OK] Script finished without crashing.\n";

} catch (Throwable $e) {
    echo "\n\n!!! EXCEPTION CAUGHT !!!\n";
    echo "Type: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString();
}
