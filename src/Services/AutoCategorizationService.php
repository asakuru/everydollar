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

    public function __construct(Database $db)
    {
        $this->db = $db;
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
        $this->db->query(
            "DELETE FROM transaction_rules WHERE id = ? AND household_id = ?",
            [$ruleId, $householdId]
        );

        unset($this->rulesCache[$householdId]);
        return true;
    }
}
