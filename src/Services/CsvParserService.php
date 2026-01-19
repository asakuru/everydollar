<?php
/**
 * CSV Parser Service
 * 
 * Parses CSV files from different financial institutions.
 * Currently supports:
 * - Corning Credit Union
 * - Visions Credit Union
 * - Generic CSV format
 */

declare(strict_types=1);

namespace App\Services;

class CsvParserService
{
    /**
     * Supported bank formats
     */
    private const FORMATS = [
        'corning_cu' => [
            'name' => 'Corning Credit Union',
            'date_column' => 'Date',
            'alt_date_columns' => ['Transaction Date', 'Posted Date', 'Posting Date'],
            'amount_column' => 'Amount',
            'alt_amount_columns' => ['Transaction Amount'],
            'description_column' => 'Description',
            'alt_description_columns' => ['Memo', 'Transaction Description'],
            'date_format' => 'm/d/Y',
        ],
        'visions_cu' => [
            'name' => 'Visions Credit Union',
            'date_column' => 'Date',
            'alt_date_columns' => ['Trans Date', 'Posted Date', 'Transaction Date'],
            'amount_column' => 'Amount',
            'alt_amount_columns' => ['Transaction Amount', 'Debit', 'Credit'],
            'description_column' => 'Description',
            'alt_description_columns' => ['Memo', 'Payee', 'Transaction Description'],
            'date_format' => 'm/d/Y',
        ],
        'generic' => [
            'name' => 'Generic CSV',
            'date_column' => 'Date',
            'alt_date_columns' => ['Transaction Date', 'Posted Date', 'Trans Date', 'Posting Date'],
            'amount_column' => 'Amount',
            'alt_amount_columns' => ['Transaction Amount', 'Debit', 'Credit'],
            'description_column' => 'Description',
            'alt_description_columns' => ['Memo', 'Payee', 'Name'],
            'date_format' => 'Y-m-d',
        ],
    ];

    /**
     * Parse a CSV file and return normalized transactions
     */
    public function parse(string $filePath, string $format = 'auto'): array
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        $lines = $this->parseCsvContent($content);

        if (count($lines) < 2) {
            return ['transactions' => [], 'detected_format' => null, 'errors' => ['File is empty or has no data rows']];
        }

        $headers = array_shift($lines);
        $headers = array_map('trim', $headers);

        // Auto-detect format if needed
        if ($format === 'auto') {
            $format = $this->detectFormat($headers);
        }

        $formatConfig = self::FORMATS[$format] ?? self::FORMATS['generic'];

        // Find column indices
        $dateCol = $this->findColumn($headers, $formatConfig['date_column'], $formatConfig['alt_date_columns']);
        $amountCol = $this->findColumn($headers, $formatConfig['amount_column'], $formatConfig['alt_amount_columns']);
        $descCol = $this->findColumn($headers, $formatConfig['description_column'], $formatConfig['alt_description_columns']);

        $errors = [];
        if ($dateCol === null) {
            $errors[] = 'Could not find date column';
        }
        if ($amountCol === null) {
            $errors[] = 'Could not find amount column';
        }
        if ($descCol === null) {
            $errors[] = 'Could not find description column';
        }

        if (!empty($errors)) {
            return ['transactions' => [], 'detected_format' => $format, 'errors' => $errors, 'headers' => $headers];
        }

        // Parse transactions
        $transactions = [];
        $rowNum = 1;

        foreach ($lines as $row) {
            $rowNum++;

            if (count($row) < max($dateCol, $amountCol, $descCol) + 1) {
                continue; // Skip incomplete rows
            }

            $dateStr = trim($row[$dateCol] ?? '');
            $amountStr = trim($row[$amountCol] ?? '');
            $description = trim($row[$descCol] ?? '');

            if (empty($dateStr) || empty($amountStr)) {
                continue; // Skip empty rows
            }

            // Parse date
            $date = $this->parseDate($dateStr, $formatConfig['date_format']);
            if (!$date) {
                $errors[] = "Row {$rowNum}: Invalid date format '{$dateStr}'";
                continue;
            }

            // Parse amount
            $amountCents = $this->parseAmount($amountStr);

            // Determine transaction type
            $type = $amountCents >= 0 ? 'income' : 'expense';
            $amountCents = abs($amountCents);

            // Generate hash for duplicate detection
            $hash = $this->generateHash($date, $amountCents, $description);

            $transactions[] = [
                'date' => $date,
                'amount_cents' => $amountCents,
                'type' => $type,
                'payee' => $this->cleanDescription($description),
                'memo' => null,
                'hash' => $hash,
                'raw_row' => $row,
            ];
        }

        return [
            'transactions' => $transactions,
            'detected_format' => $format,
            'format_name' => $formatConfig['name'],
            'errors' => $errors,
            'headers' => $headers,
        ];
    }

    /**
     * Get available formats for dropdown
     */
    public function getFormats(): array
    {
        $formats = ['auto' => 'Auto-detect'];
        foreach (self::FORMATS as $key => $config) {
            $formats[$key] = $config['name'];
        }
        return $formats;
    }

    /**
     * Parse CSV content handling different line endings and quoted fields
     */
    private function parseCsvContent(string $content): array
    {
        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $lines = [];
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        while (($row = fgetcsv($handle)) !== false) {
            $lines[] = $row;
        }

        fclose($handle);
        return $lines;
    }

    /**
     * Detect format based on headers
     */
    private function detectFormat(array $headers): string
    {
        $headersLower = array_map('strtolower', $headers);

        // Check for Corning CU specific patterns
        if (in_array('check number', $headersLower) || in_array('share id', $headersLower)) {
            return 'corning_cu';
        }

        // Check for Visions CU specific patterns  
        if (in_array('account', $headersLower) && in_array('balance', $headersLower)) {
            return 'visions_cu';
        }

        return 'generic';
    }

    /**
     * Find a column by name, checking alternates
     */
    private function findColumn(array $headers, string $primary, array $alternates): ?int
    {
        $headersLower = array_map('strtolower', $headers);

        // Check primary
        $idx = array_search(strtolower($primary), $headersLower);
        if ($idx !== false) {
            return $idx;
        }

        // Check alternates
        foreach ($alternates as $alt) {
            $idx = array_search(strtolower($alt), $headersLower);
            if ($idx !== false) {
                return $idx;
            }
        }

        return null;
    }

    /**
     * Parse date string to Y-m-d format
     */
    private function parseDate(string $dateStr, string $format): ?string
    {
        // Try configured format first
        $date = \DateTime::createFromFormat($format, $dateStr);
        if ($date) {
            return $date->format('Y-m-d');
        }

        // Try common formats
        $formats = ['m/d/Y', 'm/d/y', 'Y-m-d', 'd/m/Y', 'M d, Y', 'n/j/Y', 'n/j/y'];
        foreach ($formats as $fmt) {
            $date = \DateTime::createFromFormat($fmt, $dateStr);
            if ($date) {
                return $date->format('Y-m-d');
            }
        }

        // Try strtotime as fallback
        $timestamp = strtotime($dateStr);
        if ($timestamp) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }

    /**
     * Parse amount string to cents
     */
    private function parseAmount(string $amountStr): int
    {
        // Remove currency symbols and whitespace
        $cleaned = preg_replace('/[^0-9.\-\(\)]/', '', $amountStr);

        // Handle parentheses as negative (accounting format)
        if (str_starts_with($cleaned, '(') && str_ends_with($cleaned, ')')) {
            $cleaned = '-' . trim($cleaned, '()');
        }

        // Convert to cents
        return (int) round((float) $cleaned * 100);
    }

    /**
     * Clean up description/payee text
     */
    private function cleanDescription(string $description): string
    {
        // Remove excessive whitespace
        $cleaned = preg_replace('/\s+/', ' ', $description);

        // Trim
        $cleaned = trim($cleaned);

        // Truncate if too long
        if (strlen($cleaned) > 200) {
            $cleaned = substr($cleaned, 0, 197) . '...';
        }

        return $cleaned;
    }

    /**
     * Generate hash for duplicate detection
     */
    private function generateHash(string $date, int $amountCents, string $payee): string
    {
        $data = $date . '|' . $amountCents . '|' . strtolower(trim($payee));
        return hash('sha256', $data);
    }
}
