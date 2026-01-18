<?php
/**
 * Transaction Controller
 * 
 * Handles transaction CRUD and categorization.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class TransactionController extends BaseController
{
    private Database $db;

    public function __construct(Twig $twig, Database $db)
    {
        parent::__construct($twig);
        $this->db = $db;
    }

    /**
     * List transactions for a month
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $month = $args['month'] ?? date('Y-m');
        $queryParams = $request->getQueryParams();

        $householdId = $this->householdId();

        // Build query
        $where = ['t.household_id = ?'];
        $params = [$householdId];

        // Filter by month (use date range)
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        $where[] = 't.date >= ?';
        $where[] = 't.date <= ?';
        $params[] = $startDate;
        $params[] = $endDate;

        // Filter by category
        if (!empty($queryParams['category'])) {
            $where[] = 't.category_id = ?';
            $params[] = (int) $queryParams['category'];
        }

        // Filter by payee
        if (!empty($queryParams['payee'])) {
            $where[] = 't.payee LIKE ?';
            $params[] = '%' . $queryParams['payee'] . '%';
        }

        // Filter by type
        if (!empty($queryParams['type']) && in_array($queryParams['type'], ['income', 'expense'])) {
            $where[] = 't.type = ?';
            $params[] = $queryParams['type'];
        }

        $whereClause = implode(' AND ', $where);

        $transactions = $this->db->fetchAll(
            "SELECT t.*, c.name as category_name, cg.name as group_name
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN category_groups cg ON cg.id = c.category_group_id
             WHERE {$whereClause}
             ORDER BY t.date DESC, t.id DESC",
            $params
        );

        // Get categories for filter dropdown
        $categories = $this->getCategories($householdId);

        // Get previous and next months
        $prevMonth = date('Y-m', strtotime($month . '-01 -1 month'));
        $nextMonth = date('Y-m', strtotime($month . '-01 +1 month'));

        return $this->render($response, 'transactions/list.twig', [
            'month' => $month,
            'month_display' => date('F Y', strtotime($month . '-01')),
            'prev_month' => $prevMonth,
            'next_month' => $nextMonth,
            'transactions' => $transactions,
            'categories' => $categories,
            'filters' => $queryParams,
        ]);
    }

    /**
     * List uncategorized transactions
     */
    public function uncategorized(Request $request, Response $response, array $args): Response
    {
        $month = $args['month'] ?? date('Y-m');
        $householdId = $this->householdId();

        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        $transactions = $this->db->fetchAll(
            "SELECT * FROM transactions 
             WHERE household_id = ? 
             AND category_id IS NULL
             AND date >= ? AND date <= ?
             ORDER BY date DESC, id DESC",
            [$householdId, $startDate, $endDate]
        );

        $categories = $this->getCategories($householdId);

        return $this->render($response, 'transactions/uncategorized.twig', [
            'month' => $month,
            'month_display' => date('F Y', strtotime($month . '-01')),
            'transactions' => $transactions,
            'categories' => $categories,
        ]);
    }

    /**
     * Create a new transaction
     */
    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $householdId = $this->householdId();

        $date = $data['date'] ?? date('Y-m-d');
        $type = $data['type'] ?? 'expense';
        $amount = $this->parseMoney($data['amount'] ?? '0');
        $payee = trim($data['payee'] ?? '');
        $memo = trim($data['memo'] ?? '');
        $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;

        // Validate
        $errors = [];
        if (empty($payee)) {
            $errors[] = 'Payee is required.';
        }
        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than 0.';
        }

        $month = substr($date, 0, 7);

        if (!empty($errors)) {
            $this->flash('error', implode(' ', $errors));
            return $this->redirect($response, "/transactions/{$month}");
        }

        // Verify category belongs to household if provided
        if ($categoryId) {
            $category = $this->db->fetch(
                "SELECT c.id FROM categories c
                 JOIN category_groups cg ON cg.id = c.category_group_id
                 WHERE c.id = ? AND cg.household_id = ?",
                [$categoryId, $householdId]
            );
            if (!$category) {
                $categoryId = null;
            }
        }

        // Get or create budget month
        $budgetMonth = $this->db->fetch(
            "SELECT id FROM budget_months WHERE household_id = ? AND month_yyyymm = ?",
            [$householdId, $month]
        );

        if (!$budgetMonth) {
            $budgetMonthId = $this->db->insert('budget_months', [
                'household_id' => $householdId,
                'month_yyyymm' => $month,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $budgetMonthId = $budgetMonth['id'];
        }

        // Store as positive cents, type determines if income or expense
        $this->db->insert('transactions', [
            'household_id' => $householdId,
            'budget_month_id' => $budgetMonthId,
            'date' => $date,
            'amount_cents' => $amount,
            'type' => $type,
            'payee' => $payee,
            'memo' => $memo ?: null,
            'category_id' => $categoryId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'created_by_user_id' => $this->userId(),
        ]);

        $this->flash('success', 'Transaction added.');

        $returnTo = $data['return_to'] ?? "/transactions/{$month}";
        return $this->redirect($response, $returnTo);
    }

    /**
     * Show edit form
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $transactionId = (int) $args['id'];
        $householdId = $this->householdId();

        $transaction = $this->db->fetch(
            "SELECT * FROM transactions WHERE id = ? AND household_id = ?",
            [$transactionId, $householdId]
        );

        if (!$transaction) {
            $this->flash('error', 'Transaction not found.');
            return $this->redirect($response, '/transactions/' . date('Y-m'));
        }

        $categories = $this->getCategories($householdId);

        return $this->render($response, 'transactions/edit.twig', [
            'transaction' => $transaction,
            'categories' => $categories,
        ]);
    }

    /**
     * Update a transaction
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $transactionId = (int) $args['id'];
        $householdId = $this->householdId();

        $transaction = $this->db->fetch(
            "SELECT * FROM transactions WHERE id = ? AND household_id = ?",
            [$transactionId, $householdId]
        );

        if (!$transaction) {
            $this->flash('error', 'Transaction not found.');
            return $this->redirect($response, '/transactions/' . date('Y-m'));
        }

        $data = (array) $request->getParsedBody();

        $date = $data['date'] ?? $transaction['date'];
        $type = $data['type'] ?? $transaction['type'];
        $amount = $this->parseMoney($data['amount'] ?? '0');
        $payee = trim($data['payee'] ?? '');
        $memo = trim($data['memo'] ?? '');
        $categoryId = isset($data['category_id'])
            ? (!empty($data['category_id']) ? (int) $data['category_id'] : null)
            : $transaction['category_id'];

        $month = substr($date, 0, 7);

        // Get budget month
        $budgetMonth = $this->db->fetch(
            "SELECT id FROM budget_months WHERE household_id = ? AND month_yyyymm = ?",
            [$householdId, $month]
        );

        if (!$budgetMonth) {
            $budgetMonthId = $this->db->insert('budget_months', [
                'household_id' => $householdId,
                'month_yyyymm' => $month,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $budgetMonthId = $budgetMonth['id'];
        }

        $this->db->update('transactions', [
            'budget_month_id' => $budgetMonthId,
            'date' => $date,
            'amount_cents' => $amount,
            'type' => $type,
            'payee' => $payee,
            'memo' => $memo ?: null,
            'category_id' => $categoryId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$transactionId]);

        $this->flash('success', 'Transaction updated.');

        return $this->redirect($response, "/transactions/{$month}");
    }

    /**
     * Delete a transaction
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $transactionId = (int) $args['id'];
        $householdId = $this->householdId();

        $transaction = $this->db->fetch(
            "SELECT * FROM transactions WHERE id = ? AND household_id = ?",
            [$transactionId, $householdId]
        );

        if ($transaction) {
            $this->db->delete('transactions', 'id = ?', [$transactionId]);
            $this->flash('success', 'Transaction deleted.');
            $month = substr($transaction['date'], 0, 7);
        } else {
            $month = date('Y-m');
        }

        return $this->redirect($response, "/transactions/{$month}");
    }

    /**
     * Quick categorize a transaction
     */
    public function quickCategorize(Request $request, Response $response, array $args): Response
    {
        $transactionId = (int) $args['id'];
        $householdId = $this->householdId();
        $data = (array) $request->getParsedBody();

        $transaction = $this->db->fetch(
            "SELECT * FROM transactions WHERE id = ? AND household_id = ?",
            [$transactionId, $householdId]
        );

        if (!$transaction) {
            return $this->json($response, ['error' => 'Transaction not found'], 404);
        }

        $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;

        // Verify category if provided
        if ($categoryId) {
            $category = $this->db->fetch(
                "SELECT c.id FROM categories c
                 JOIN category_groups cg ON cg.id = c.category_group_id
                 WHERE c.id = ? AND cg.household_id = ?",
                [$categoryId, $householdId]
            );
            if (!$category) {
                return $this->json($response, ['error' => 'Category not found'], 404);
            }
        }

        $this->db->update('transactions', [
            'category_id' => $categoryId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$transactionId]);

        $month = substr($transaction['date'], 0, 7);

        // Check content type for AJAX vs form submission
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            return $this->json($response, ['success' => true]);
        }

        return $this->redirect($response, "/transactions/{$month}/uncategorized");
    }

    /**
     * Get categories grouped for dropdowns
     */
    private function getCategories(int $householdId): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.name, cg.name as group_name
             FROM categories c
             JOIN category_groups cg ON cg.id = c.category_group_id
             WHERE cg.household_id = ? AND c.archived = 0
             ORDER BY cg.sort_order, c.sort_order",
            [$householdId]
        );
    }

    /**
     * Parse money string to cents
     */
    private function parseMoney(string $value): int
    {
        $cleaned = preg_replace('/[^0-9.\-]/', '', $value);
        return (int) round((float) $cleaned * 100);
    }
}
