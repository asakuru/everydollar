<?php
/**
 * LLC Dashboard Controller
 * 
 * Provides business-specific overview, profit/loss, and tax estimates.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class LLCDashboardController extends BaseController
{
    public function __construct(Twig $twig, Database $db)
    {
        parent::__construct($twig, $db);
    }

    /**
     * Show dashboard
     */
    public function index(Request $request, Response $response): Response
    {
        $entityId = EntityController::getCurrentEntityId();

        if (!$entityId) {
            $this->flash('error', 'Please select a business entity first.');
            return $this->redirect($response, '/entities');
        }

        $entity = $this->db->fetch("SELECT * FROM entities WHERE id = ?", [$entityId]);
        if ($entity['type'] !== 'business') {
            return $this->redirect($response, '/');
        }

        // Determine quarter
        $currentMonth = (int) date('n');
        $currentYear = (int) date('Y');
        $quarter = ceil($currentMonth / 3);

        // Fetch quarterly data
        $taxEstimate = $this->getTaxEstimate($entityId, $currentYear, $quarter);

        // Calculate Profit/Loss for this Year
        $yearStart = "{$currentYear}-01-01";
        $yearEnd = "{$currentYear}-12-31";

        $totals = $this->db->fetch(
            "SELECT 
                SUM(CASE WHEN type = 'income' THEN amount_cents ELSE 0 END) as total_income,
                SUM(CASE WHEN type = 'expense' THEN amount_cents ELSE 0 END) as total_expense
             FROM transactions 
             WHERE entity_id = ? AND date >= ? AND date <= ?",
            [$entityId, $yearStart, $yearEnd]
        );

        $revenue = (int) ($totals['total_income'] ?? 0);
        $expenses = (int) ($totals['total_expense'] ?? 0);
        $profit = $revenue - $expenses;

        // Fetch recent invoices
        $recentInvoices = $this->db->fetchAll(
            "SELECT * FROM invoices WHERE entity_id = ? ORDER BY created_at DESC LIMIT 5",
            [$entityId]
        );

        // Calculate estimated tax due based on profit (YTD)
        // Using entity tax rate
        $taxRate = $entity['tax_rate_percent'] / 100;
        $estimatedTaxYTD = max(0, $profit * $taxRate);

        return $this->render($response, 'llc-dashboard/index.twig', [
            'entity' => $entity,
            'quarter' => $quarter,
            'year' => $currentYear,
            'revenue_cents' => $revenue,
            'expenses_cents' => $expenses,
            'profit_cents' => $profit,
            'estimated_tax_ytd_cents' => (int) $estimatedTaxYTD,
            'tax_estimate' => $taxEstimate,
            'recent_invoices' => $recentInvoices,
        ]);
    }

    /**
     * Get or calculate tax estimate for a quarter
     */
    private function getTaxEstimate(int $entityId, int $year, int $quarter)
    {
        // Try to find existing record
        $record = $this->db->fetch(
            "SELECT * FROM tax_estimates WHERE entity_id = ? AND year = ? AND quarter = ?",
            [$entityId, $year, $quarter]
        );

        if ($record) {
            return $record;
        }

        // If not found, calculate it live (but don't save yet)
        $startMonth = ($quarter - 1) * 3 + 1;
        $endMonth = $quarter * 3;

        $startDate = sprintf("%d-%02d-01", $year, $startMonth);
        $endDate = date('Y-m-t', strtotime(sprintf("%d-%02d-01", $year, $endMonth)));

        // Get entity tax rate
        $entity = $this->db->fetch("SELECT tax_rate_percent FROM entities WHERE id = ?", [$entityId]);
        $taxRate = $entity['tax_rate_percent'] / 100;

        $totals = $this->db->fetch(
            "SELECT 
                SUM(CASE WHEN type = 'income' THEN amount_cents ELSE 0 END) as income,
                SUM(CASE WHEN type = 'expense' THEN amount_cents ELSE 0 END) as expense
             FROM transactions 
             WHERE entity_id = ? AND date >= ? AND date <= ?",
            [$entityId, $startDate, $endDate]
        );

        $income = (int) ($totals['income'] ?? 0);
        $expenses = (int) ($totals['expense'] ?? 0);
        $profit = max(0, $income - $expenses);
        $estimatedTax = (int) round($profit * $taxRate);

        return [
            'year' => $year,
            'quarter' => $quarter,
            'income_cents' => $income,
            'expenses_cents' => $expenses,
            'estimated_tax_cents' => $estimatedTax,
            'paid_cents' => 0,
            'due_date' => $this->getQuarterDueDate($year, $quarter),
        ];
    }

    private function getQuarterDueDate(int $year, int $quarter): string
    {
        // Q1 (Jan-Mar) -> Apr 15
        // Q2 (Apr-Jun) -> Jun 15
        // Q3 (Jul-Sep) -> Sep 15
        // Q4 (Oct-Dec) -> Jan 15 (Next Year)

        switch ($quarter) {
            case 1:
                return "{$year}-04-15";
            case 2:
                return "{$year}-06-15";
            case 3:
                return "{$year}-09-15";
            case 4:
                return ($year + 1) . "-01-15";
            default:
                return "{$year}-12-31";
        }
    }

    /**
     * Record a tax payment
     */
    public function recordTaxPayment(Request $request, Response $response): Response
    {
        $entityId = EntityController::getCurrentEntityId();
        $data = (array) $request->getParsedBody();

        $year = (int) $data['year'];
        $quarter = (int) $data['quarter'];
        $amount = (float) ($data['amount'] ?? 0);
        $amountCents = (int) round($amount * 100);
        $paidDate = $data['date'] ?? date('Y-m-d');

        // Check if record exists
        $record = $this->db->fetch(
            "SELECT id FROM tax_estimates WHERE entity_id = ? AND year = ? AND quarter = ?",
            [$entityId, $year, $quarter]
        );

        if ($record) {
            $this->db->execute(
                "UPDATE tax_estimates SET paid_cents = paid_cents + ?, paid_date = ? WHERE id = ?",
                [$amountCents, $paidDate, $record['id']]
            );
        } else {
            // Need to insert full record
            // Recalculate totals first
            $estimate = $this->getTaxEstimate($entityId, $year, $quarter);

            $this->db->insert('tax_estimates', [
                'entity_id' => $entityId,
                'year' => $year,
                'quarter' => $quarter,
                'income_cents' => $estimate['income_cents'],
                'expenses_cents' => $estimate['expenses_cents'],
                'estimated_tax_cents' => $estimate['estimated_tax_cents'],
                'paid_cents' => $amountCents,
                'due_date' => $estimate['due_date'],
                'paid_date' => $paidDate,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // Also create an expense transaction for this payment
        // Find "Taxes & Fees" category
        $category = $this->db->fetch(
            "SELECT c.id FROM categories c 
             JOIN category_groups cg ON cg.id = c.category_group_id
             WHERE cg.entity_id = ? AND (c.name LIKE '%Federal Tax%' OR c.name LIKE '%Tax Payment%')
             LIMIT 1",
            [$entityId]
        );

        // Create transaction logic could be added here or user can do it manually
        // For now, we just record the tax payment tracking

        $this->flash('success', 'Tax payment recorded.');
        return $this->redirect($response, '/llc-dashboard');
    }
}
