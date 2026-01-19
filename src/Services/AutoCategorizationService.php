<?php
/**
 * Auto Categorization Service
 * 
 * Matches transactions to categories based on rules.
 */

declare(strict_types=1);

namespace App\Services;

use App\Services\Database;

class AutoCategorizationService
{
    private Database $db;
    private array $rulesCache = [];

    private const DEFAULT_RULES = [
        // Food
        'Walmart' => 'Groceries',
        'Kroger' => 'Groceries',
        'Aldi' => 'Groceries',
        'Whole Foods' => 'Groceries',
        'Publix' => 'Groceries',
        'Costco' => 'Groceries',
        'McDonald\'s' => 'Restaurants',
        'Chick-fil-A' => 'Restaurants',
        'Chipotle' => 'Restaurants',
        'Starbucks' => 'Coffee Shops',
        'Dunkin' => 'Coffee Shops',

        // Transportation
        'Shell' => 'Gas',
        'Exxon' => 'Gas',
        'BP' => 'Gas',
        'Chevron' => 'Gas',
        'Wawa' => 'Gas',
        'Uber' => 'Public Transit',
        'Lyft' => 'Public Transit',

        // Utilities
        'AT&T' => 'Phone',
        'Verizon' => 'Phone',
        'T-Mobile' => 'Phone',
        'Comcast' => 'Internet',
        'Xfinity' => 'Internet',
        'Spectrum' => 'Internet',

        // Personal
        'Netflix' => 'Subscriptions',
        'Spotify' => 'Subscriptions',
        'Hulu' => 'Subscriptions',
        'Disney+' => 'Subscriptions',
        'Amazon Prime' => 'Subscriptions',
        'Apple.com' => 'Subscriptions',
        'Target' => 'Clothing',
        'T.J. Maxx' => 'Clothing',

        // Home
        'Home Depot' => 'Home Improvement',
        'Lowe\'s' => 'Home Improvement',
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Seed default rules for a household
     */
    public function seedDefaultRules(int $householdId): int
    {
        $count = 0;

        // 1. Get all category IDs by name for this household
        $categories = $this->db->fetchAll(
            "SELECT id, name FROM categories 
             WHERE category_group_id IN (
                SELECT id FROM category_groups WHERE household_id = ?
             )",
            [$householdId]
        );

        $catMap = []; // Name -> ID
        foreach ($categories as $cat) {
            $catMap[$cat['name']] = $cat['id'];
        }

        // 2. Get existing rules to avoid duplicates
        $existingRules = $this->getRules($householdId);
        $existingTerms = array_map(fn($r) => strtolower($r['search_term']), $existingRules);

        // 3. Insert defaults if category exists and rule doesn't
        foreach (self::DEFAULT_RULES as $term => $catName) {
            if (isset($catMap[$catName]) && !in_array(strtolower($term), $existingTerms)) {
                $this->createRule($householdId, $term, $catMap[$catName], 'contains');
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get all rules for a household
     */
    public function getRules(int $householdId): array
    {
        if (isset($this->rulesCache[$householdId])) {
            return $this->rulesCache[$householdId];
        }

        $rules = $this->db->fetchAll(
            "SELECT r.*, c.name as category_name 
             FROM transaction_rules r
             JOIN categories c ON r.category_id = c.id
             WHERE r.household_id = ?
             ORDER BY r.created_at DESC",
            [$householdId]
        );

        $this->rulesCache[$householdId] = $rules;
        return $rules;
    }

    /**
     * Find a matching category for a payee description
     */
    public function match(int $householdId, string $payee): ?int
    {
        $rules = $this->getRules($householdId);
        $payeeLower = strtolower($payee);

        foreach ($rules as $rule) {
            $term = strtolower($rule['search_term']);

            if ($rule['match_type'] === 'exact') {
                if ($payeeLower === $term) {
                    return (int) $rule['category_id'];
                }
            } else { // contains
                if (str_contains($payeeLower, $term)) {
                    return (int) $rule['category_id'];
                }
            }
        }

        return null;
    }

    /**
     * Create a new rule
     */
    public function createRule(int $householdId, string $searchTerm, int $categoryId, string $matchType = 'contains'): int
    {
        $id = $this->db->insert('transaction_rules', [
            'household_id' => $householdId,
            'search_term' => trim($searchTerm),
            'category_id' => $categoryId,
            'match_type' => $matchType,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        unset($this->rulesCache[$householdId]); // Invalidate cache
        return (int) $id;
    }

    /**
     * Delete a rule
     */
    public function deleteRule(int $householdId, int $ruleId): bool
    {
        // Use delete() method: table, where clause, params
        $this->db->delete(
            'transaction_rules',
            'id = ? AND household_id = ?',
            [$ruleId, $householdId]
        );

        unset($this->rulesCache[$householdId]);
        return true;
    }
}
