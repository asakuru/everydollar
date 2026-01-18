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
    public function __construct(Twig $twig, Database $db)
    {
        parent::__construct($twig, $db);
    }

    /**
     * List transactions for a month
     */
    public function index(Request $request, Response $response, array $args): Response
    {
        $month = $args['month'] ?? date('Y-m');
        $queryParams = $request->getQueryParams();
        $householdId = $this->householdId();
        $entityId = EntityController::getCurrentEntityId();

        // If no entity selected, default to first personal one (handled in BaseController usually, but fallback here)
        if (!$entityId) {
            $entity = $this->db->fetch("SELECT id FROM entities WHERE household_id = ? AND type = 'personal' LIMIT 1", [$householdId]);
            if ($entity) {
                EntityController::setCurrentEntityId((int) $entity['id']);
                $entityId = (int) $entity['id'];
            }
        }

        // Build query
        $where = ['t.household_id = ?'];
        $params = [$householdId];

        // Filter by Entity
        if ($entityId) {
            $where[] = 't.entity_id = ?';
            $params[] = $entityId;
        }

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

        // Filter by account
        if (!empty($queryParams['account'])) {
            $where[] = 't.account_id = ?';
            $params[] = (int) $queryParams['account'];
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
            "SELECT t.*, c.name as category_name, cg.name as group_name, a.name as account_name
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN category_groups cg ON cg.id = c.category_group_id
             LEFT JOIN accounts a ON a.id = t.account_id
             WHERE {$whereClause}
             ORDER BY t.date DESC, t.id DESC",
            $params
        );

        // Get categories for filter dropdown
        $categories = $this->getCategories($householdId, $entityId);

        // Get accounts for filter dropdown
        $accounts = $this->db->fetchAll(
            "SELECT id, name FROM accounts WHERE entity_id = ? AND archived = 0 ORDER BY type, name",
            [$entityId]
        );

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
            'accounts' => $accounts,
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
        $entityId = EntityController::getCurrentEntityId();

        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        $where = ['household_id = ?', 'category_id IS NULL', 'date >= ?', 'date <= ?'];
        $params = [$householdId, $startDate, $endDate];

        if ($entityId) {
            $where[] = 'entity_id = ?';
            $params[] = $entityId;
        }

        $whereStr = implode(' AND ', $where);

        $transactions = $this->db->fetchAll(
            "SELECT * FROM transactions 
             WHERE {$whereStr}
             ORDER BY date DESC, id DESC",
            $params
        );

        $categories = $this->getCategories($householdId, $entityId);

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
        $entityId = EntityController::getCurrentEntityId();
        $userId = $this->userId();

        $date = $data['date'] ?? date('Y-m-d');
        $type = $data['type'] ?? 'expense';
        $amount = $this->parseMoney($data['amount'] ?? '0');
        $payee = trim($data['payee'] ?? '');
        $memo = trim($data['memo'] ?? '');
        $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;
        $accountId = !empty($data['account_id']) ? (int) $data['account_id'] : null;

        // Validate
        $errors = [];
        if (empty($payee)) {
            $errors[] = 'Payee is required.';
        }
        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than 0.';
        }
        if (!$entityId && isset($data['entity_id'])) {
            $entityId = (int) $data['entity_id'];
        }

        $month = substr($date, 0, 7);

        if (!empty($errors)) {
            $this->flash('error', implode(' ', $errors));
            return $this->redirect($response, "/transactions/{$month}");
        }

        // Verify category belongs to household/entity if provided
        if ($categoryId) {
            $category = $this->db->fetch(
                "SELECT c.id, c.name FROM categories c
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
            "SELECT id FROM budget_months WHERE household_id = ? AND entity_id = ? AND month_yyyymm = ?",
            [$householdId, $entityId, $month]
        );

        if (!$budgetMonth) {
            $budgetMonthId = $this->db->insert('budget_months', [
                'household_id' => $householdId,
                'entity_id' => $entityId,
                'month_yyyymm' => $month,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $budgetMonthId = $budgetMonth['id'];
        }

        // Check for Owner Draw intent
        $isOwnerDraw = false;
        if ($categoryId && isset($category['name'])) {
            if (stripos($category['name'], 'Owner Draw') !== false) {
                $isOwnerDraw = true;
            }
        }

        try {
            // INSERT TRANSACTION
            $transactionId = $this->db->insert('transactions', [
                'household_id' => $householdId,
                'entity_id' => $entityId,
                'account_id' => $accountId,
                'budget_month_id' => $budgetMonthId,
                'date' => $date,
                'amount_cents' => $amount,
                'type' => $type,
                'payee' => $payee,
                'memo' => $memo ?: null,
                'category_id' => $categoryId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'created_by_user_id' => $userId,
                'is_transfer' => $isOwnerDraw ? 1 : 0,
            ]);

            // Update Account Balance
            if ($accountId) {
                AccountController::updateBalanceForTransaction($this->db, $accountId, $amount, $type);
            }

            // HANDLE OWNER DRAW LINKING
            // If this is an LLC expense categorised as "Owner Draw", create matching Personal Income
            if ($isOwnerDraw && $type === 'expense') {
                // Find Personal Entity
                $personalEntity = $this->db->fetch(
                    "SELECT id FROM entities WHERE household_id = ? AND type = 'personal' LIMIT 1",
                    [$householdId]
                );

                if ($personalEntity) {
                    // Find "Paycheck" or "Owner Draw" income category in Personal
                    $personalCat = $this->db->fetch(
                        "SELECT c.id FROM categories c 
                         JOIN category_groups cg ON cg.id = c.category_group_id
                         WHERE cg.entity_id = ? AND (c.name LIKE '%Owner Draw%' OR c.name LIKE '%Paycheck%')
                         LIMIT 1",
                        [$personalEntity['id']]
                    );

                    // Ensure budget month exists for personal
                    $personalBudgetMonth = $this->db->fetch(
                        "SELECT id FROM budget_months WHERE entity_id = ? AND month_yyyymm = ?",
                        [$personalEntity['id'], $month]
                    );

                    if (!$personalBudgetMonth) {
                        $personalBudgetMonthId = $this->db->insert('budget_months', [
                            'household_id' => $householdId,
                            'entity_id' => $personalEntity['id'],
                            'month_yyyymm' => $month,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                    } else {
                        $personalBudgetMonthId = $personalBudgetMonth['id'];
                    }

                    // Create Linked Personal Income Transaction
                    $personalTxId = $this->db->insert('transactions', [
                        'household_id' => $householdId,
                        'entity_id' => $personalEntity['id'],
                        'account_id' => null,
                        'budget_month_id' => $personalBudgetMonthId,
                        'date' => $date,
                        'amount_cents' => $amount,
                        'type' => 'income',
                        'payee' => $payee . ' (Draw)',
                        'memo' => 'Linked from LLC Owner Draw',
                        'category_id' => $personalCat ? $personalCat['id'] : null,
                        'is_transfer' => 1,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                        'created_by_user_id' => $userId,
                    ]);

                    // Create Link Record
                    $this->db->insert('linked_transfers', [
                        'from_transaction_id' => $transactionId,
                        'to_transaction_id' => $personalTxId,
                        'transfer_type' => 'owner_draw',
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);

                    $this->flash('success', 'Transaction added and Owner Draw linked to Personal Budget.');
                    $returnTo = $data['return_to'] ?? "/transactions/{$month}";
                    return $this->redirect($response, $returnTo);
                }
            }
        } catch (\Exception $e) {
            error_log("Transaction Create Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->flash('error', 'Unexpected error: ' . $e->getMessage());
            return $this->redirect($response, "/transactions/{$month}");
        }

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
        // Allow editing across entities, as long as household owns it
        $transaction = $this->db->fetch(
            "SELECT * FROM transactions WHERE id = ? AND household_id = ?",
            [$transactionId, $householdId]
        );

        if (!$transaction) {
            $this->flash('error', 'Transaction not found.');
            return $this->redirect($response, '/transactions/' . date('Y-m'));
        }

        $categories = $this->getCategories($householdId, $transaction['entity_id']);

        $accounts = $this->db->fetchAll(
            "SELECT id, name FROM accounts WHERE entity_id = ? AND archived = 0 ORDER BY type, name",
            [$transaction['entity_id']]
        );

        return $this->render($response, 'transactions/edit.twig', [
            'transaction' => $transaction,
            'categories' => $categories,
            'accounts' => $accounts,
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
        $accountId = isset($data['account_id'])
            ? (!empty($data['account_id']) ? (int) $data['account_id'] : null)
            : $transaction['account_id'];

        // Balance Adjustment if amount or account changed
        $amountDiff = $amount - $transaction['amount_cents'];
        $oldAccountId = $transaction['account_id'];

        // Revert old transaction effect
        if ($oldAccountId) {
            AccountController::updateBalanceForTransaction($this->db, $oldAccountId, -$transaction['amount_cents'], $transaction['type']); // Reverse
        }

        // Apply new transaction effect (will happen after update or we can calculate net)
        // Easier to just revert old and apply new to keep logic simple

        $month = substr($date, 0, 7);

        // Get budget month
        $budgetMonth = $this->db->fetch(
            "SELECT id FROM budget_months WHERE household_id = ? AND entity_id = ? AND month_yyyymm = ?",
            [$householdId, $transaction['entity_id'], $month]
        );

        if (!$budgetMonth) {
            // ... (create if needed, usually exists)
            $budgetMonthId = $this->db->insert('budget_months', [
                'household_id' => $householdId,
                'entity_id' => $transaction['entity_id'],
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
            'account_id' => $accountId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$transactionId]);

        // Apply new balance effect
        if ($accountId) {
            AccountController::updateBalanceForTransaction($this->db, $accountId, $amount, $type);
        }

        // UPDATE LINKED TRANSACTION IF EXISTS
        $link = $this->db->fetch("SELECT to_transaction_id FROM linked_transfers WHERE from_transaction_id = ?", [$transactionId]);
        if ($link) {
            $this->db->update('transactions', [
                'date' => $date,
                'amount_cents' => $amount, // Sync amount
                'payee' => $payee . ' (Draw)',
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$link['to_transaction_id']]);
        }

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
            // Revert balance
            if ($transaction['account_id']) {
                AccountController::updateBalanceForTransaction($this->db, $transaction['account_id'], -$transaction['amount_cents'], $transaction['type']);
            }

            $this->db->delete('transactions', 'id = ?', [$transactionId]);
            // Linked transactions cascade delete via FK

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
    private function getCategories(int $householdId, ?int $entityId = null): array
    {
        $params = [$householdId];
        $sql = "SELECT c.id, c.name, cg.name as group_name
             FROM categories c
             JOIN category_groups cg ON cg.id = c.category_group_id
             WHERE cg.household_id = ? AND c.archived = 0";

        if ($entityId) {
            $sql .= " AND cg.entity_id = ?";
            $params[] = $entityId;
        }

        $sql .= " ORDER BY cg.sort_order, c.sort_order";

        return $this->db->fetchAll($sql, $params);
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
