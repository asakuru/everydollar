<?php
/**
 * Account Controller
 * 
 * Manages bank accounts and balance tracking.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AccountController extends BaseController
{
    public function __construct(Twig $twig, Database $db)
    {
        parent::__construct($twig, $db);
    }

    /**
     * List accounts for current entity
     */
    public function index(Request $request, Response $response): Response
    {
        $entityId = EntityController::getCurrentEntityId();

        if (!$entityId) {
            $this->flash('error', 'Please select an entity first.');
            return $this->redirect($response, '/entities');
        }

        $accounts = $this->db->fetchAll(
            "SELECT a.*, 
                    (SELECT COUNT(*) FROM transactions t WHERE t.account_id = a.id) as transaction_count
             FROM accounts a
             WHERE a.entity_id = ? AND a.archived = 0
             ORDER BY a.type ASC, a.name ASC",
            [$entityId]
        );

        // Get entity info
        $entity = $this->db->fetch("SELECT * FROM entities WHERE id = ?", [$entityId]);

        // Calculate totals
        $totalBalance = array_sum(array_column($accounts, 'balance_cents'));

        return $this->render($response, 'accounts/index.twig', [
            'accounts' => $accounts,
            'entity' => $entity,
            'total_balance_cents' => $totalBalance,
        ]);
    }

    /**
     * Show create account form
     */
    public function showCreate(Request $request, Response $response): Response
    {
        $entityId = EntityController::getCurrentEntityId();
        $entity = $this->db->fetch("SELECT * FROM entities WHERE id = ?", [$entityId]);

        return $this->render($response, 'accounts/create.twig', [
            'entity' => $entity,
        ]);
    }

    /**
     * Create a new account
     */
    public function create(Request $request, Response $response): Response
    {
        $entityId = EntityController::getCurrentEntityId();
        $data = (array) $request->getParsedBody();

        if (!$entityId) {
            $this->flash('error', 'Please select an entity first.');
            return $this->redirect($response, '/entities');
        }

        $name = trim($data['name'] ?? '');
        $type = $data['type'] ?? 'checking';
        $balance = (float) ($data['initial_balance'] ?? 0);
        $balanceCents = (int) round($balance * 100);

        if (empty($name)) {
            $this->flash('error', 'Account name is required.');
            return $this->redirect($response, '/accounts/create');
        }

        if (!in_array($type, ['checking', 'savings', 'credit', 'cash'])) {
            $type = 'checking';
        }

        $this->db->insert('accounts', [
            'entity_id' => $entityId,
            'name' => $name,
            'type' => $type,
            'balance_cents' => $balanceCents,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->flash('success', "Account '{$name}' created with balance \$" . number_format($balance, 2));
        return $this->redirect($response, '/accounts');
    }

    /**
     * Show edit account form
     */
    public function showEdit(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $entityId = EntityController::getCurrentEntityId();

        $account = $this->db->fetch(
            "SELECT a.* FROM accounts a WHERE a.id = ? AND a.entity_id = ?",
            [$accountId, $entityId]
        );

        if (!$account) {
            $this->flash('error', 'Account not found.');
            return $this->redirect($response, '/accounts');
        }

        return $this->render($response, 'accounts/edit.twig', [
            'account' => $account,
        ]);
    }

    /**
     * Update account
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $entityId = EntityController::getCurrentEntityId();
        $data = (array) $request->getParsedBody();

        $account = $this->db->fetch(
            "SELECT * FROM accounts WHERE id = ? AND entity_id = ?",
            [$accountId, $entityId]
        );

        if (!$account) {
            $this->flash('error', 'Account not found.');
            return $this->redirect($response, '/accounts');
        }

        $name = trim($data['name'] ?? '');

        if (empty($name)) {
            $this->flash('error', 'Account name is required.');
            return $this->redirect($response, "/accounts/{$accountId}/edit");
        }

        $this->db->update('accounts', $accountId, [
            'name' => $name,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->flash('success', 'Account updated.');
        return $this->redirect($response, '/accounts');
    }

    /**
     * Adjust account balance (reconciliation)
     */
    public function adjustBalance(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $entityId = EntityController::getCurrentEntityId();
        $data = (array) $request->getParsedBody();

        $account = $this->db->fetch(
            "SELECT * FROM accounts WHERE id = ? AND entity_id = ?",
            [$accountId, $entityId]
        );

        if (!$account) {
            $this->flash('error', 'Account not found.');
            return $this->redirect($response, '/accounts');
        }

        $newBalance = (float) ($data['balance'] ?? 0);
        $newBalanceCents = (int) round($newBalance * 100);

        $this->db->update('accounts', $accountId, [
            'balance_cents' => $newBalanceCents,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->flash('success', 'Balance adjusted to $' . number_format($newBalance, 2));
        return $this->redirect($response, '/accounts');
    }

    /**
     * Archive an account
     */
    public function archive(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $entityId = EntityController::getCurrentEntityId();

        $account = $this->db->fetch(
            "SELECT * FROM accounts WHERE id = ? AND entity_id = ?",
            [$accountId, $entityId]
        );

        if (!$account) {
            $this->flash('error', 'Account not found.');
            return $this->redirect($response, '/accounts');
        }

        $this->db->update('accounts', $accountId, [
            'archived' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->flash('success', 'Account archived.');
        return $this->redirect($response, '/accounts');
    }

    /**
     * Delete an account
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $accountId = (int) $args['id'];
        $entityId = EntityController::getCurrentEntityId();

        $account = $this->db->fetch(
            "SELECT * FROM accounts WHERE id = ? AND entity_id = ?",
            [$accountId, $entityId]
        );

        if (!$account) {
            $this->flash('error', 'Account not found.');
            return $this->redirect($response, '/accounts');
        }

        // Check for transactions
        $transactionCount = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM transactions WHERE account_id = ?",
            [$accountId]
        );

        if ($transactionCount > 0) {
            $this->flash('error', 'Cannot delete account with existing transactions. Please archive it instead.');
            return $this->redirect($response, '/accounts');
        }

        $this->db->execute("DELETE FROM accounts WHERE id = ?", [$accountId]);

        $this->flash('success', 'Account deleted permanently.');
        return $this->redirect($response, '/accounts');
    }

    /**
     * Update account balance when a transaction is created/updated
     * Called from TransactionController
     */
    public static function updateBalanceForTransaction(Database $db, int $accountId, int $amountCents, string $type): void
    {
        if (!$accountId) {
            return;
        }

        // For expenses, subtract from balance. For income, add to balance.
        // But amount_cents is already stored as absolute value with type indicator
        $adjustment = ($type === 'income') ? $amountCents : -$amountCents;

        $db->execute(
            "UPDATE accounts SET balance_cents = balance_cents + ?, updated_at = NOW() WHERE id = ?",
            [$adjustment, $accountId]
        );
    }
}
