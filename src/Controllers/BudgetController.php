<?php
/**
 * Budget Controller
 * 
 * Handles monthly budget view, income items, and budget items.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class BudgetController extends BaseController
{
    public function __construct(Twig $twig, Database $db)
    {
        parent::__construct($twig, $db);
    }

    /**
     * Show monthly budget
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $month = $args['month'] ?? date('Y-m');

        // Validate month format
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->flash('error', 'Invalid month format.');
            return $this->redirect($response, '/budget/' . date('Y-m'));
        }

        $householdId = $this->householdId();

        // Get or create budget month
        $budgetMonth = $this->getOrCreateBudgetMonth($householdId, $month);

        // Get income items
        $incomeItems = $this->db->fetchAll(
            "SELECT * FROM income_items WHERE budget_month_id = ? ORDER BY id",
            [$budgetMonth['id']]
        );

        // Get categories with planned and actual amounts
        $categoryGroups = $this->getCategoriesWithAmounts($householdId, $budgetMonth['id']);

        // Calculate totals
        $totalIncome = array_sum(array_column($incomeItems, 'planned_cents'));
        $totalPlanned = 0;
        $totalActual = 0;

        foreach ($categoryGroups as $group) {
            foreach ($group['categories'] as $cat) {
                $totalPlanned += $cat['planned_cents'];
                $totalActual += $cat['actual_cents'];
            }
        }

        $unassigned = $totalIncome - $totalPlanned;

        // Get previous and next months
        $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
        $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

        return $this->render($response, 'budget/month.twig', [
            'month' => $month,
            'month_display' => date('F Y', strtotime($month . '-01')),
            'prev_month' => $prevMonth,
            'next_month' => $nextMonth,
            'income_items' => $incomeItems,
            'category_groups' => $categoryGroups,
            'total_income' => $totalIncome,
            'total_planned' => $totalPlanned,
            'total_actual' => $totalActual,
            'unassigned' => $unassigned,
            'budget_month_id' => $budgetMonth['id'],
        ]);
    }

    /**
     * Add income item
     */
    public function addIncome(Request $request, Response $response, array $args): Response
    {
        $month = $args['month'];
        $data = (array) $request->getParsedBody();

        $name = trim($data['name'] ?? '');
        $amount = $this->parseMoney($data['amount'] ?? '0');

        if (empty($name)) {
            $this->flash('error', 'Income name is required.');
            return $this->redirect($response, "/budget/{$month}");
        }

        $budgetMonth = $this->getOrCreateBudgetMonth($this->householdId(), $month);

        $this->db->insert('income_items', [
            'budget_month_id' => $budgetMonth['id'],
            'name' => $name,
            'planned_cents' => $amount,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'created_by_user_id' => $this->userId(),
        ]);

        return $this->redirect($response, "/budget/{$month}");
    }

    /**
     * Update income item
     */
    public function updateIncome(Request $request, Response $response, array $args): Response
    {
        $month = $args['month'];
        $incomeId = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        // Verify ownership
        $income = $this->db->fetch(
            "SELECT ii.* FROM income_items ii
             JOIN budget_months bm ON bm.id = ii.budget_month_id
             WHERE ii.id = ? AND bm.household_id = ?",
            [$incomeId, $this->householdId()]
        );

        if (!$income) {
            $this->flash('error', 'Income item not found.');
            return $this->redirect($response, "/budget/{$month}");
        }

        $name = trim($data['name'] ?? '');
        $amount = $this->parseMoney($data['amount'] ?? '0');

        $this->db->update('income_items', [
            'name' => $name ?: $income['name'],
            'planned_cents' => $amount,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$incomeId]);

        return $this->redirect($response, "/budget/{$month}");
    }

    /**
     * Delete income item
     */
    public function deleteIncome(Request $request, Response $response, array $args): Response
    {
        $month = $args['month'];
        $incomeId = (int) $args['id'];

        // Verify ownership
        $income = $this->db->fetch(
            "SELECT ii.id FROM income_items ii
             JOIN budget_months bm ON bm.id = ii.budget_month_id
             WHERE ii.id = ? AND bm.household_id = ?",
            [$incomeId, $this->householdId()]
        );

        if ($income) {
            $this->db->delete('income_items', 'id = ?', [$incomeId]);
        }

        return $this->redirect($response, "/budget/{$month}");
    }

    /**
     * Update budget item (planned amount for a category)
     */
    public function updateBudgetItem(Request $request, Response $response, array $args): Response
    {
        $month = $args['month'];
        $categoryId = (int) $args['id'];
        $data = (array) $request->getParsedBody();

        $householdId = $this->householdId();

        // Verify category belongs to household
        $category = $this->db->fetch(
            "SELECT c.id FROM categories c
             JOIN category_groups cg ON cg.id = c.category_group_id
             WHERE c.id = ? AND cg.household_id = ?",
            [$categoryId, $householdId]
        );

        if (!$category) {
            $this->flash('error', 'Category not found.');
            return $this->redirect($response, "/budget/{$month}");
        }

        $budgetMonth = $this->getOrCreateBudgetMonth($householdId, $month);
        $amount = $this->parseMoney($data['planned'] ?? '0');

        // Upsert budget item
        $existing = $this->db->fetch(
            "SELECT id FROM budget_items WHERE budget_month_id = ? AND category_id = ?",
            [$budgetMonth['id'], $categoryId]
        );

        if ($existing) {
            $this->db->update('budget_items', [
                'planned_cents' => $amount,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$existing['id']]);
        } else {
            $this->db->insert('budget_items', [
                'budget_month_id' => $budgetMonth['id'],
                'category_id' => $categoryId,
                'planned_cents' => $amount,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'created_by_user_id' => $this->userId(),
            ]);
        }

        return $this->redirect($response, "/budget/{$month}");
    }

    /**
     * Copy previous month's budget
     */
    public function copyPreviousMonth(Request $request, Response $response, array $args): Response
    {
        $month = $args['month'];
        $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));

        $householdId = $this->householdId();

        // Get previous month's budget
        $prevBudgetMonth = $this->db->fetch(
            "SELECT id FROM budget_months WHERE household_id = ? AND month_yyyymm = ?",
            [$householdId, $prevMonth]
        );

        if (!$prevBudgetMonth) {
            $this->flash('warning', 'No previous month budget found to copy.');
            return $this->redirect($response, "/budget/{$month}");
        }

        // Get or create current month
        $currentBudgetMonth = $this->getOrCreateBudgetMonth($householdId, $month);

        $this->db->beginTransaction();

        try {
            // Copy income items
            $prevIncomes = $this->db->fetchAll(
                "SELECT name, planned_cents FROM income_items WHERE budget_month_id = ?",
                [$prevBudgetMonth['id']]
            );

            foreach ($prevIncomes as $income) {
                // Check if already exists
                $exists = $this->db->fetch(
                    "SELECT id FROM income_items WHERE budget_month_id = ? AND name = ?",
                    [$currentBudgetMonth['id'], $income['name']]
                );

                if (!$exists) {
                    $this->db->insert('income_items', [
                        'budget_month_id' => $currentBudgetMonth['id'],
                        'name' => $income['name'],
                        'planned_cents' => $income['planned_cents'],
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                        'created_by_user_id' => $this->userId(),
                    ]);
                }
            }

            // Copy budget items
            $prevBudgetItems = $this->db->fetchAll(
                "SELECT category_id, planned_cents FROM budget_items WHERE budget_month_id = ?",
                [$prevBudgetMonth['id']]
            );

            foreach ($prevBudgetItems as $item) {
                // Check if already exists
                $exists = $this->db->fetch(
                    "SELECT id FROM budget_items WHERE budget_month_id = ? AND category_id = ?",
                    [$currentBudgetMonth['id'], $item['category_id']]
                );

                if (!$exists) {
                    $this->db->insert('budget_items', [
                        'budget_month_id' => $currentBudgetMonth['id'],
                        'category_id' => $item['category_id'],
                        'planned_cents' => $item['planned_cents'],
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                        'created_by_user_id' => $this->userId(),
                    ]);
                }
            }

            $this->db->commit();
            $this->flash('success', 'Previous month\'s budget copied successfully.');

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->flash('error', 'Failed to copy budget.');
        }

        return $this->redirect($response, "/budget/{$month}");
    }

    /**
     * Get or create a budget month record
     */
    private function getOrCreateBudgetMonth(int $householdId, string $month): array
    {
        $budgetMonth = $this->db->fetch(
            "SELECT * FROM budget_months WHERE household_id = ? AND month_yyyymm = ?",
            [$householdId, $month]
        );

        if (!$budgetMonth) {
            $id = $this->db->insert('budget_months', [
                'household_id' => $householdId,
                'month_yyyymm' => $month,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $budgetMonth = [
                'id' => $id,
                'household_id' => $householdId,
                'month_yyyymm' => $month,
            ];
        }

        return $budgetMonth;
    }

    /**
     * Get categories grouped with planned and actual amounts
     */
    private function getCategoriesWithAmounts(int $householdId, int $budgetMonthId): array
    {
        // Get all category groups with categories
        $groups = $this->db->fetchAll(
            "SELECT * FROM category_groups WHERE household_id = ? ORDER BY sort_order",
            [$householdId]
        );

        $result = [];

        foreach ($groups as $group) {
            $categories = $this->db->fetchAll(
                "SELECT c.*,
                    COALESCE(bi.planned_cents, 0) as planned_cents,
                    COALESCE((
                        SELECT SUM(ABS(t.amount_cents))
                        FROM transactions t
                        WHERE t.category_id = c.id
                        AND t.budget_month_id = ?
                        AND t.type = 'expense'
                    ), 0) as actual_cents
                 FROM categories c
                 LEFT JOIN budget_items bi ON bi.category_id = c.id AND bi.budget_month_id = ?
                 WHERE c.category_group_id = ? AND c.archived = 0
                 ORDER BY c.sort_order",
                [$budgetMonthId, $budgetMonthId, $group['id']]
            );

            // Calculate remaining for each category
            foreach ($categories as &$cat) {
                $cat['remaining_cents'] = $cat['planned_cents'] - $cat['actual_cents'];
            }

            $group['categories'] = $categories;
            $result[] = $group;
        }

        return $result;
    }

    /**
     * Parse money string to cents
     */
    private function parseMoney(string $value): int
    {
        // Remove currency symbols and commas
        $cleaned = preg_replace('/[^0-9.\-]/', '', $value);

        // Convert to cents
        return (int) round((float) $cleaned * 100);
    }
}
