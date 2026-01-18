<?php
/**
 * Database Seeder
 * 
 * Seeds the database with default category groups and categories.
 * Run from command line: php migrations/seed.php
 * 
 * Usage:
 *   php migrations/seed.php               # Seed for specific household
 *   php migrations/seed.php --household=1 # Seed household ID 1
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
    exit(1);
}

$config = require $configPath;

// Parse command line arguments
$householdId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--household=')) {
        $householdId = (int) substr($arg, 12);
    }
}

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
} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Default category structure
$defaultCategories = [
    'Income' => [
        'Paycheck 1',
        'Paycheck 2',
        'Side Income',
        'Bonus',
    ],
    'Housing' => [
        'Mortgage/Rent',
        'Property Taxes',
        'Home Insurance',
        'HOA Fees',
        'Home Maintenance',
        'Home Improvement',
    ],
    'Transportation' => [
        'Car Payment',
        'Car Insurance',
        'Gas',
        'Car Maintenance',
        'Parking',
        'Public Transit',
    ],
    'Food' => [
        'Groceries',
        'Restaurants',
        'Coffee Shops',
    ],
    'Utilities' => [
        'Electric',
        'Gas/Heating',
        'Water',
        'Trash',
        'Internet',
        'Phone',
    ],
    'Insurance' => [
        'Health Insurance',
        'Life Insurance',
        'Disability Insurance',
    ],
    'Health' => [
        'Doctor',
        'Dentist',
        'Vision',
        'Prescriptions',
        'Gym',
    ],
    'Personal' => [
        'Clothing',
        'Personal Care',
        'Subscriptions',
        'Entertainment',
        'Hobbies',
    ],
    'Giving' => [
        'Tithe/Charity',
        'Gifts',
    ],
    'Savings' => [
        'Emergency Fund',
        'Retirement',
        'Investments',
        'Vacation',
        'Other Savings',
    ],
    'Debt' => [
        'Credit Card',
        'Student Loans',
        'Personal Loan',
    ],
    'Miscellaneous' => [
        'Miscellaneous',
        'Pet Care',
        'Childcare',
        'Education',
    ],
];

/**
 * Seed categories for a household
 */
function seedHousehold(PDO $pdo, int $householdId, array $categories): void
{
    // Check if household has any categories already
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM category_groups WHERE household_id = ?");
    $stmt->execute([$householdId]);
    $existingCount = $stmt->fetchColumn();

    if ($existingCount > 0) {
        echo "Household {$householdId} already has {$existingCount} category groups. Skipping.\n";
        return;
    }

    $pdo->beginTransaction();

    try {
        $sortOrder = 0;

        foreach ($categories as $groupName => $categoryNames) {
            // Insert category group
            $stmt = $pdo->prepare(
                "INSERT INTO category_groups (household_id, name, sort_order) VALUES (?, ?, ?)"
            );
            $stmt->execute([$householdId, $groupName, $sortOrder++]);
            $groupId = $pdo->lastInsertId();

            // Insert categories
            $catSort = 0;
            foreach ($categoryNames as $catName) {
                $stmt = $pdo->prepare(
                    "INSERT INTO categories (category_group_id, name, sort_order) VALUES (?, ?, ?)"
                );
                $stmt->execute([$groupId, $catName, $catSort++]);
            }

            echo "Created: {$groupName} with " . count($categoryNames) . " categories\n";
        }

        $pdo->commit();
        echo "\nSeeded household {$householdId} with default categories.\n";

    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Main execution
if ($householdId) {
    // Seed specific household
    seedHousehold($pdo, $householdId, $defaultCategories);
} else {
    // Seed all households without categories
    $stmt = $pdo->query(
        "SELECT h.id, h.name FROM households h 
         LEFT JOIN category_groups cg ON cg.household_id = h.id 
         GROUP BY h.id HAVING COUNT(cg.id) = 0"
    );
    $households = $stmt->fetchAll();

    if (empty($households)) {
        echo "No households found that need seeding.\n";
        echo "Use --household=ID to seed a specific household.\n";
        exit(0);
    }

    echo "Found " . count($households) . " households to seed.\n\n";

    foreach ($households as $household) {
        echo "=== Seeding: {$household['name']} (ID: {$household['id']}) ===\n";
        seedHousehold($pdo, (int) $household['id'], $defaultCategories);
        echo "\n";
    }
}

echo "Done.\n";
