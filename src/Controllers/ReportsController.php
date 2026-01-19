<?php
/**
 * Reports Controller
 * 
 * Simple spending reports and summaries.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ReportsController extends BaseController
{
    public function __construct(Twig $twig, Database $db)
    {
        parent::__construct($twig, $db);
    }

    /**
     * Show reports dashboard
     */
    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $householdId = $this->householdId();

        // Default to last 3 months
        $endMonth = $queryParams['end_month'] ?? date('Y-m');
        $startMonth = $queryParams['start_month'] ?? date('Y-m', strtotime('-2 months'));

        // Get spending by category for date range
        $startDate = $startMonth . '-01';
        $endDate = date('Y-m-t', strtotime($endMonth . '-01'));

        $categorySpending = $this->db->fetchAll(
            "SELECT 
                cg.name as group_name,
                c.name as category_name,
                SUM(ABS(sub.amount)) as total_cents,
                COUNT(*) as transaction_count
             FROM (
                -- Regular Transactions
                SELECT t.category_id, t.amount_cents as amount
                FROM transactions t
                WHERE t.household_id = ? 
                AND t.type = 'expense'
                AND t.date >= ? AND t.date <= ?
                AND t.category_id IS NOT NULL

                UNION ALL

                -- Split Transactions
                SELECT ts.category_id, ts.amount_cents as amount
                FROM transaction_splits ts
                JOIN transactions t ON t.id = ts.transaction_id
                WHERE t.household_id = ?
                AND t.type = 'expense'
                AND t.date >= ? AND t.date <= ?
             ) as sub
             JOIN categories c ON c.id = sub.category_id
             JOIN category_groups cg ON cg.id = c.category_group_id
             GROUP BY cg.id, c.id
             ORDER BY total_cents DESC",
            [$householdId, $startDate, $endDate, $householdId, $startDate, $endDate]
        );

        // Get monthly totals
        $monthlyTotals = $this->db->fetchAll(
            "SELECT 
                DATE_FORMAT(date, '%Y-%m') as month,
                SUM(CASE WHEN type = 'income' THEN amount_cents ELSE 0 END) as income_cents,
                SUM(CASE WHEN type = 'expense' THEN ABS(amount_cents) ELSE 0 END) as expense_cents
             FROM transactions
             WHERE household_id = ?
             AND date >= ? AND date <= ?
             GROUP BY DATE_FORMAT(date, '%Y-%m')
             ORDER BY month",
            [$householdId, $startDate, $endDate]
        );

        // Get top payees
        $topPayees = $this->db->fetchAll(
            "SELECT 
                payee,
                SUM(ABS(amount_cents)) as total_cents,
                COUNT(*) as transaction_count
             FROM transactions
             WHERE household_id = ?
             AND type = 'expense'
             AND date >= ? AND date <= ?
             GROUP BY payee
             ORDER BY total_cents DESC
             LIMIT 10",
            [$householdId, $startDate, $endDate]
        );

        // Calculate totals
        $totalIncome = array_sum(array_column($monthlyTotals, 'income_cents'));
        $totalExpenses = array_sum(array_column($monthlyTotals, 'expense_cents'));
        $netSavings = $totalIncome - $totalExpenses;

        return $this->render($response, 'reports/index.twig', [
            'start_month' => $startMonth,
            'end_month' => $endMonth,
            'category_spending' => $categorySpending,
            'monthly_totals' => $monthlyTotals,
            'top_payees' => $topPayees,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net_savings' => $netSavings,
        ]);
    }
}
