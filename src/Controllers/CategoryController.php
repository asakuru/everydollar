<?php
/**
 * Category Controller
 * 
 * Handles category and category group management.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CategoryController extends BaseController
{
    public function __construct(Twig $twig, Database $db)
    {
        parent::__construct($twig, $db);
    }

    /**
     * Show category detail with transactions
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        try {
            $categoryId = (int) $args['id'];
            $queryParams = $request->getQueryParams();
            $month = $queryParams['month'] ?? date('Y-m');

            $householdId = $this->householdId();

            // Get category with group
            $category = $this->db->fetch(
                "SELECT c.*, cg.name as group_name
                 FROM categories c
                 JOIN category_groups cg ON cg.id = c.category_group_id
                 WHERE c.id = ? AND cg.household_id = ?",
                [$categoryId, $householdId]
            );

            if (!$category) {
                $this->flash('error', 'Category not found.');
                return $this->redirect($response, '/budget/' . $month);
            }

            // Get transactions for this category and month
            $startDate = $month . '-01';
            $endDate = date('Y-m-t', strtotime($startDate));

            $transactions = $this->db->fetchAll(
                "SELECT * FROM transactions 
                 WHERE household_id = ? AND category_id = ?
                 AND date >= ? AND date <= ?
                 ORDER BY date DESC",
                [$householdId, $categoryId, $startDate, $endDate]
            );

            // Get budget item
            $budgetMonth = $this->db->fetch(
                "SELECT id FROM budget_months WHERE household_id = ? AND month_yyyymm = ?",
                [$householdId, $month]
            );

            $budgetItem = null;
            $actualCents = 0;

            if ($budgetMonth) {
                $budgetItem = $this->db->fetch(
                    "SELECT * FROM budget_items WHERE budget_month_id = ? AND category_id = ?",
                    [$budgetMonth['id'], $categoryId]
                );

                $actualCents = $this->db->fetchColumn(
                    "SELECT COALESCE(SUM(ABS(amount_cents)), 0) 
                     FROM transactions 
                     WHERE category_id = ? AND budget_month_id = ? AND type = 'expense'",
                    [$categoryId, $budgetMonth['id']]
                );
            }

            $plannedCents = $budgetItem['planned_cents'] ?? 0;
            $remainingCents = $plannedCents - $actualCents;

            return $this->render($response, 'categories/show.twig', [
                'category' => $category,
                'month' => $month,
                'month_display' => date('F Y', strtotime($startDate)),
                'transactions' => $transactions,
                'planned_cents' => $plannedCents,
                'actual_cents' => $actualCents,
                'remaining_cents' => $remainingCents,
            ]);
        } catch (\Throwable $e) {
            $this->flash('error', 'Error loading category: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return $this->redirect($response, '/budget/' . ($month ?? date('Y-m')));
        }
    }

    /**
     * Create a new category group
     */
    public function createGroup(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $name = trim($data['name'] ?? '');
        $month = $data['month'] ?? date('Y-m');

        if (empty($name)) {
            $this->flash('error', 'Group name is required.');
            return $this->redirect($response, "/budget/{$month}");
        }

        $householdId = $this->householdId();

        // Get max sort order
        $maxSort = $this->db->fetchColumn(
            "SELECT MAX(sort_order) FROM category_groups WHERE household_id = ?",
            [$householdId]
        );

        $this->db->insert('category_groups', [
            'household_id' => $householdId,
            'name' => $name,
            'sort_order' => ($maxSort ?? -1) + 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->flash('success', 'Category group created.');
        return $this->redirect($response, "/budget/{$month}");
    }

    /**
     * Update a category group
     */
    public function updateGroup(Request $request, Response $response, array $args): Response
    {
        $groupId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $month = $data['month'] ?? date('Y-m');

        $householdId = $this->householdId();

        // Verify ownership
        $group = $this->db->fetch(
            "SELECT id FROM category_groups WHERE id = ? AND household_id = ?",
            [$groupId, $householdId]
        );

        if (!$group) {
            $this->flash('error', 'Category group not found.');
            return $this->redirect($response, "/budget/{$month}");
        }

        $name = trim($data['name'] ?? '');

        if (!empty($name)) {
            $this->db->update('category_groups', [
                'name' => $name,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$groupId]);

            $this->flash('success', 'Category group updated.');
        }

        return $this->redirect($response, "/budget/{$month}");
    }

    /**
     * Create a new category
     */
    public function createCategory(Request $request, Response $response, array $args): Response
    {
        $groupId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $month = $data['month'] ?? date('Y-m');

        $householdId = $this->householdId();

        // Verify group belongs to household
        $group = $this->db->fetch(
            "SELECT id FROM category_groups WHERE id = ? AND household_id = ?",
            [$groupId, $householdId]
        );

        if (!$group) {
            $this->flash('error', 'Category group not found.');
            return $this->redirect($response, "/budget/{$month}");
        }

        $name = trim($data['name'] ?? '');

        if (empty($name)) {
            $this->flash('error', 'Category name is required.');
            return $this->redirect($response, "/budget/{$month}");
        }

        // Get max sort order
        $maxSort = $this->db->fetchColumn(
            "SELECT MAX(sort_order) FROM categories WHERE category_group_id = ?",
            [$groupId]
        );

        $this->db->insert('categories', [
            'category_group_id' => $groupId,
            'name' => $name,
            'sort_order' => ($maxSort ?? -1) + 1,
            'archived' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->flash('success', 'Category created.');
        return $this->redirect($response, "/budget/{$month}");
    }

    /**
     * Update a category
     */
    public function updateCategory(Request $request, Response $response, array $args): Response
    {
        $categoryId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $month = $data['month'] ?? date('Y-m');

        $householdId = $this->householdId();

        // Verify ownership
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

        $name = trim($data['name'] ?? '');

        if (!empty($name)) {
            $this->db->update('categories', [
                'name' => $name,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$categoryId]);

            $this->flash('success', 'Category updated.');
        }

        return $this->redirect($response, "/budget/{$month}");
    }

    /**
     * Archive a category
     */
    public function archiveCategory(Request $request, Response $response, array $args): Response
    {
        $categoryId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $month = $data['month'] ?? date('Y-m');

        $householdId = $this->householdId();

        // Verify ownership
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

        $this->db->update('categories', [
            'archived' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$categoryId]);

        $this->flash('success', 'Category archived.');
        return $this->redirect($response, "/budget/{$month}");
    }
}
