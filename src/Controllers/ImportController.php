<?php
/**
 * Import Controller
 * 
 * Handles CSV file upload and transaction import.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Database;
use App\Services\CsvParserService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

class ImportController extends BaseController
{
    private CsvParserService $csvParser;
    private \App\Services\AutoCategorizationService $autoCat;

    public function __construct(Twig $twig, Database $db, CsvParserService $csvParser, \App\Services\AutoCategorizationService $autoCat)
    {
        parent::__construct($twig, $db);
        $this->csvParser = $csvParser;
        $this->autoCat = $autoCat;
    }

    /**
     * Show import form
     */
    public function showForm(Request $request, Response $response): Response
    {
        return $this->render($response, 'import/upload.twig', [
            'formats' => $this->csvParser->getFormats(),
        ]);
    }

    /**
     * Process uploaded file and show preview
     */
    public function preview(Request $request, Response $response): Response
    {
        $uploadedFiles = $request->getUploadedFiles();
        $data = (array) $request->getParsedBody();

        /** @var UploadedFileInterface|null $uploadedFile */
        $uploadedFile = $uploadedFiles['csv_file'] ?? null;

        if (!$uploadedFile || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            $this->flash('error', 'Please upload a valid CSV file.');
            return $this->redirect($response, '/import');
        }

        // Validate file type
        $filename = $uploadedFile->getClientFilename();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            $this->flash('error', 'Only CSV files are allowed.');
            return $this->redirect($response, '/import');
        }

        // Save to temp file
        $tempPath = sys_get_temp_dir() . '/' . uniqid('import_', true) . '.csv';
        $uploadedFile->moveTo($tempPath);

        // Parse CSV
        $format = $data['format'] ?? 'auto';
        $result = $this->csvParser->parse($tempPath, $format);

        // Check for existing transactions (duplicates)
        $householdId = $this->householdId();
        $existingHashes = $this->getExistingHashes($householdId);

        $newTransactions = [];
        $duplicates = [];

        foreach ($result['transactions'] as $tx) {
            if (in_array($tx['hash'], $existingHashes)) {
                $duplicates[] = $tx;
            } else {
                $newTransactions[] = $tx;
            }
        }

        // Auto-categorize
        $householdId = $this->householdId();
        foreach ($newTransactions as &$tx) {
            $catId = $this->autoCat->match($householdId, $tx['payee']);
            if ($catId) {
                $tx['category_id'] = $catId;
            }
        }
        unset($tx); // Break reference

        // Store parsed data in session for final import
        $_SESSION['import_data'] = [
            'transactions' => $newTransactions,
            'temp_path' => $tempPath,
        ];

        // Get categories for assignment
        $categories = $this->getCategories($householdId);

        return $this->render($response, 'import/preview.twig', [
            'transactions' => $newTransactions,
            'duplicates' => $duplicates,
            'errors' => $result['errors'],
            'format_name' => $result['format_name'] ?? 'Unknown',
            'categories' => $categories,
            'total_new' => count($newTransactions),
            'total_duplicates' => count($duplicates),
        ]);
    }

    /**
     * Import the previewed transactions
     */
    public function import(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $importData = $_SESSION['import_data'] ?? null;

        if (!$importData || empty($importData['transactions'])) {
            $this->flash('error', 'No transactions to import. Please upload a file first.');
            return $this->redirect($response, '/import');
        }

        $householdId = $this->householdId();
        $userId = $this->userId();
        $transactions = $importData['transactions'];

        // Get selected transactions (checkboxes)
        $selectedIndices = $data['selected'] ?? [];

        // If none selected, import all
        if (empty($selectedIndices)) {
            $selectedIndices = array_keys($transactions);
        }

        $imported = 0;
        $errors = 0;

        $this->db->beginTransaction();

        try {
            foreach ($selectedIndices as $idx) {
                if (!isset($transactions[$idx])) {
                    continue;
                }

                $tx = $transactions[$idx];
                $date = $tx['date'];
                $month = substr($date, 0, 7);

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

                // Get category assignment from form (if provided)
                $categoryId = $data['category'][$idx] ?? null;
                if ($categoryId === '' || $categoryId === '0') {
                    $categoryId = null;
                }

                // Insert transaction
                $this->db->insert('transactions', [
                    'household_id' => $householdId,
                    'budget_month_id' => $budgetMonthId,
                    'date' => $date,
                    'amount_cents' => $tx['amount_cents'],
                    'type' => $tx['type'],
                    'payee' => $tx['payee'],
                    'memo' => 'Imported from CSV',
                    'category_id' => $categoryId,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_by_user_id' => $userId,
                ]);

                $imported++;
            }

            $this->db->commit();

            // Clean up
            unset($_SESSION['import_data']);
            if (isset($importData['temp_path']) && file_exists($importData['temp_path'])) {
                @unlink($importData['temp_path']);
            }

            $this->flash('success', "Successfully imported {$imported} transactions.");

            // Redirect to transactions page for the first transaction's month
            $firstTx = $transactions[array_key_first($selectedIndices)] ?? null;
            $month = $firstTx ? substr($firstTx['date'], 0, 7) : date('Y-m');

            return $this->redirect($response, "/transactions/{$month}");

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->flash('error', 'Import failed: ' . $e->getMessage());
            return $this->redirect($response, '/import');
        }
    }

    /**
     * Get hashes of existing transactions for duplicate detection
     */
    private function getExistingHashes(int $householdId): array
    {
        // Get recent transactions (last 90 days) for hash comparison
        $transactions = $this->db->fetchAll(
            "SELECT date, amount_cents, payee FROM transactions 
             WHERE household_id = ? AND date >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
            [$householdId]
        );

        $hashes = [];
        foreach ($transactions as $tx) {
            $data = $tx['date'] . '|' . $tx['amount_cents'] . '|' . strtolower(trim($tx['payee']));
            $hashes[] = hash('sha256', $data);
        }

        return $hashes;
    }

    /**
     * Get categories for dropdown
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
}
