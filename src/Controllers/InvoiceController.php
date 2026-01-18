<?php
/**
 * Invoice Controller
 * 
 * Handles invoice creation, tracking, and payments.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class InvoiceController extends BaseController
{
    public function __construct(Twig $twig, Database $db)
    {
        parent::__construct($twig, $db);
    }

    /**
     * List invoices for current entity
     */
    public function index(Request $request, Response $response): Response
    {
        $entityId = EntityController::getCurrentEntityId();

        if (!$entityId) {
            $this->flash('error', 'Please select a business entity first.');
            return $this->redirect($response, '/entities');
        }

        // Verify entity is business type
        $entity = $this->db->fetch("SELECT * FROM entities WHERE id = ?", [$entityId]);
        if ($entity['type'] !== 'business') {
            $this->flash('error', 'Invoices are only available for business entities.');
            return $this->redirect($response, '/');
        }

        $params = $request->getQueryParams();
        $status = $params['status'] ?? 'all';

        $sql = "SELECT i.* FROM invoices i WHERE i.entity_id = ?";
        $queryArgs = [$entityId];

        if ($status !== 'all' && in_array($status, ['draft', 'sent', 'paid', 'overdue'])) {
            $sql .= " AND i.status = ?";
            $queryArgs[] = $status;
        }

        $sql .= " ORDER BY i.due_date ASC, i.created_at DESC";

        $invoices = $this->db->fetchAll($sql, $queryArgs);

        // Calculate totals
        $totalOverdue = 0;
        $totalOutstanding = 0;

        foreach ($invoices as $invoice) {
            if ($invoice['status'] === 'overdue') {
                $totalOverdue += $invoice['amount_cents'];
            }
            if (in_array($invoice['status'], ['sent', 'overdue'])) {
                $totalOutstanding += $invoice['amount_cents'];
            }
        }

        return $this->render($response, 'invoices/index.twig', [
            'invoices' => $invoices,
            'entity' => $entity,
            'status' => $status,
            'total_overdue_cents' => $totalOverdue,
            'total_outstanding_cents' => $totalOutstanding,
        ]);
    }

    /**
     * Show create invoice form
     */
    public function showCreate(Request $request, Response $response): Response
    {
        $entityId = EntityController::getCurrentEntityId();
        $entity = $this->db->fetch("SELECT * FROM entities WHERE id = ?", [$entityId]);

        // Generate next invoice number
        $lastInvoice = $this->db->fetch(
            "SELECT invoice_number FROM invoices WHERE entity_id = ? ORDER BY id DESC LIMIT 1",
            [$entityId]
        );

        $nextNum = 'INV-001';
        if ($lastInvoice) {
            // Try to increment number
            if (preg_match('/(\d+)$/', $lastInvoice['invoice_number'], $matches)) {
                $num = (int) $matches[1] + 1;
                $prefix = preg_replace('/\d+$/', '', $lastInvoice['invoice_number']);
                $nextNum = $prefix . str_pad((string) $num, strlen($matches[1]), '0', STR_PAD_LEFT);
            }
        }

        return $this->render($response, 'invoices/create.twig', [
            'entity' => $entity,
            'next_number' => $nextNum,
        ]);
    }

    /**
     * Create a new invoice
     */
    public function create(Request $request, Response $response): Response
    {
        $entityId = EntityController::getCurrentEntityId();
        $data = (array) $request->getParsedBody();

        $number = trim($data['invoice_number'] ?? '');
        $client = trim($data['client_name'] ?? '');
        $amount = (float) ($data['amount'] ?? 0);
        $dueDate = $data['due_date'] ?? date('Y-m-d', strtotime('+30 days'));

        if (empty($client) || empty($number)) {
            $this->flash('error', 'Client name and invoice number are required.');
            return $this->redirect($response, '/invoices/create');
        }

        $this->db->insert('invoices', [
            'entity_id' => $entityId,
            'invoice_number' => $number,
            'client_name' => $client,
            'client_email' => $data['client_email'] ?? null,
            'description' => $data['description'] ?? null,
            'amount_cents' => (int) round($amount * 100),
            'status' => 'draft',
            'issue_date' => $data['issue_date'] ?? date('Y-m-d'),
            'due_date' => $dueDate,
            'created_by_user_id' => $this->userId(),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->flash('success', "Invoice {$number} created.");
        return $this->redirect($response, '/invoices');
    }

    /**
     * Mark invoice as sent
     */
    public function markSent(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $entityId = EntityController::getCurrentEntityId();

        $this->db->update('invoices', [
            'status' => 'sent',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ? AND entity_id = ?', [$id, $entityId]);

        $this->flash('success', 'Invoice marked as sent.');
        return $this->redirect($response, '/invoices');
    }

    /**
     * Mark invoice as paid (and create income transaction)
     */
    public function markPaid(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $entityId = EntityController::getCurrentEntityId();
        $data = (array) $request->getParsedBody();

        $invoice = $this->db->fetch(
            "SELECT * FROM invoices WHERE id = ? AND entity_id = ?",
            [$id, $entityId]
        );

        if (!$invoice) {
            $this->flash('error', 'Invoice not found.');
            return $this->redirect($response, '/invoices');
        }

        $paidDate = $data['paid_date'] ?? date('Y-m-d');
        $accountId = !empty($data['account_id']) ? (int) $data['account_id'] : null;

        // Create transaction if requested
        $transactionId = null;
        if ($accountId) {
            // Find "Client Income" category
            $category = $this->db->fetch(
                "SELECT c.id FROM categories c 
                 JOIN category_groups cg ON cg.id = c.category_group_id
                 WHERE cg.entity_id = ? AND c.name LIKE '%Client Income%'
                 LIMIT 1",
                [$entityId]
            );

            // Get Month ID
            $month = substr($paidDate, 0, 7);
            $budgetMonth = $this->db->fetch(
                "SELECT id FROM budget_months WHERE entity_id = ? AND month_yyyymm = ?",
                [$entityId, $month]
            );

            if (!$budgetMonth) {
                // Determine household ID from entity
                $entity = $this->db->fetch("SELECT household_id FROM entities WHERE id = ?", [$entityId]);
                $budgetMonthId = $this->db->insert('budget_months', [
                    'household_id' => $entity['household_id'],
                    'entity_id' => $entityId,
                    'month_yyyymm' => $month,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $budgetMonthId = $budgetMonth['id'];
            }

            // Create Transaction
            $transactionId = $this->db->insert('transactions', [
                'household_id' => $this->householdId(),
                'entity_id' => $entityId,
                'account_id' => $accountId,
                'budget_month_id' => $budgetMonthId,
                'date' => $paidDate,
                'amount_cents' => $invoice['amount_cents'],
                'type' => 'income',
                'payee' => $invoice['client_name'],
                'memo' => "Invoice #{$invoice['invoice_number']}",
                'category_id' => $category ? $category['id'] : null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'created_by_user_id' => $this->userId(),
            ]);

            // Update account balance
            AccountController::updateBalanceForTransaction(
                $this->db,
                $accountId,
                $invoice['amount_cents'],
                'income'
            );
        }

        // Update Invoice
        $this->db->update('invoices', [
            'status' => 'paid',
            'paid_date' => $paidDate,
            'paid_transaction_id' => $transactionId,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$id]);

        $this->flash('success', 'Invoice marked as paid.');
        return $this->redirect($response, '/invoices');
    }
}
