<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Report;

/**
 * Handles exporting reports to various formats (CSV, JSON, PDF).
 */
class ReportExporter {
    private ReportCalculator $calculator;

    public function __construct(ReportCalculator $calculator) {
        $this->calculator = $calculator;
    }

    /**
     * Export report data to the specified format.
     *
     * @param array $data Report data to export
     * @param string $type Report type (summary, spending, income, cashflow, budget)
     * @param string $format Export format (csv, json, pdf)
     * @return array{stream: string, contentType: string, filename: string}
     */
    public function export(array $data, string $type, string $format): array {
        return match ($format) {
            'csv' => $this->exportToCsv($data, $type),
            'json' => $this->exportToJson($data, $type),
            'pdf' => $this->exportToPdf($data, $type),
            default => throw new \InvalidArgumentException('Unknown format: ' . $format),
        };
    }

    /**
     * Export to CSV format.
     */
    private function exportToCsv(array $data, string $type): array {
        $csv = fopen('php://memory', 'w');

        match ($type) {
            'summary' => $this->writeSummaryCsv($csv, $data),
            'spending' => $this->writeSpendingCsv($csv, $data),
            'cashflow' => $this->writeCashFlowCsv($csv, $data),
            'income' => $this->writeIncomeCsv($csv, $data),
            'budget' => $this->writeBudgetCsv($csv, $data),
            'category-monthly' => $this->writeCategoryMonthlyCsv($csv, $data),
            default => null,
        };

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return [
            'stream' => $content,
            'contentType' => 'text/csv',
            'filename' => $type . '_report_' . date('Y-m-d') . '.csv'
        ];
    }

    /**
     * Export to JSON format.
     */
    private function exportToJson(array $data, string $type): array {
        return [
            'stream' => json_encode($data, JSON_PRETTY_PRINT),
            'contentType' => 'application/json',
            'filename' => $type . '_report_' . date('Y-m-d') . '.json'
        ];
    }

    /**
     * Export to PDF format.
     */
    private function exportToPdf(array $data, string $type): array {
        // Check if TCPDF is available
        if (!class_exists('TCPDF')) {
            // Fallback to JSON when TCPDF is not available
            return $this->exportToJson($data, $type);
        }

        // The category-by-month matrix is wide (a column per month), so render it landscape
        $orientation = $type === 'category-monthly' ? 'L' : 'P';
        $pdf = new \TCPDF($orientation, 'mm', 'A4', true, 'UTF-8', false);

        // Set document information
        $pdf->SetCreator('Nextcloud Budget');
        $pdf->SetAuthor('Nextcloud Budget App');
        $pdf->SetTitle(ucfirst($type) . ' Report');

        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->setFooterData([0, 0, 0], [0, 0, 0]);

        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 25);

        // Add a page
        $pdf->AddPage();

        // Title
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->Cell(0, 10, ucfirst($type) . ' Report', 0, 1, 'C');
        $pdf->Ln(5);

        // Period
        if (isset($data['period'])) {
            $pdf->SetFont('helvetica', '', 10);
            $periodText = 'Period: ' . ($data['period']['startDate'] ?? '') . ' to ' . ($data['period']['endDate'] ?? '');
            $pdf->Cell(0, 6, $periodText, 0, 1, 'C');
            $pdf->Ln(10);
        }

        // Render content based on type
        match ($type) {
            'summary' => $this->renderSummaryPdf($pdf, $data),
            'spending' => $this->renderSpendingPdf($pdf, $data),
            'cashflow' => $this->renderCashFlowPdf($pdf, $data),
            'income' => $this->renderIncomePdf($pdf, $data),
            'budget' => $this->renderBudgetPdf($pdf, $data),
            'category-monthly' => $this->renderCategoryMonthlyPdf($pdf, $data),
            default => null,
        };

        return [
            'stream' => $pdf->Output('', 'S'),
            'contentType' => 'application/pdf',
            'filename' => $type . '_report_' . date('Y-m-d') . '.pdf'
        ];
    }

    /**
     * Write summary report to CSV.
     */
    private function writeSummaryCsv($handle, array $data): void {
        fputcsv($handle, ['Type', 'Value']);

        fputcsv($handle, ['Total Income', $data['totals']['totalIncome'] ?? 0]);
        fputcsv($handle, ['Total Expenses', $data['totals']['totalExpenses'] ?? 0]);
        fputcsv($handle, ['Net Income', $data['totals']['netIncome'] ?? 0]);
        fputcsv($handle, ['Current Balance', $data['totals']['currentBalance'] ?? 0]);

        // Comparison if available
        if (isset($data['comparison']['changes'])) {
            fputcsv($handle, ['']);
            fputcsv($handle, ['Comparison vs Previous Period']);
            foreach ($data['comparison']['changes'] as $key => $change) {
                fputcsv($handle, [ucfirst($key) . ' Change', ($change['percentage'] ?? 0) . '% ' . ($change['direction'] ?? '')]);
            }
        }

        // Write account details
        fputcsv($handle, ['']);
        fputcsv($handle, ['Account', 'Balance', 'Income', 'Expenses', 'Net']);

        foreach ($data['accounts'] ?? [] as $account) {
            fputcsv($handle, [
                $account['name'] ?? '',
                $account['balance'] ?? 0,
                $account['income'] ?? 0,
                $account['expenses'] ?? 0,
                $account['net'] ?? 0
            ]);
        }
    }

    /**
     * Write spending report to CSV.
     */
    private function writeSpendingCsv($handle, array $data): void {
        fputcsv($handle, ['Category', 'Amount', 'Transactions', 'Percentage']);

        $total = $data['totals']['amount'] ?? 0;

        foreach ($data['data'] ?? [] as $item) {
            $itemTotal = (float)($item['total'] ?? 0);
            $pct = $total > 0 ? round(($itemTotal / $total) * 100, 1) : 0;
            fputcsv($handle, [
                $item['name'] ?? 'Unknown',
                $itemTotal,
                $item['count'] ?? 0,
                $pct . '%'
            ]);
        }

        fputcsv($handle, ['']);
        fputcsv($handle, ['Total', $total, $data['totals']['transactions'] ?? 0, '100%']);
    }

    /**
     * Write cash flow report to CSV.
     */
    private function writeCashFlowCsv($handle, array $data): void {
        fputcsv($handle, ['Month', 'Income', 'Expenses', 'Net', 'Cumulative']);

        $cumulative = 0;
        foreach ($data['data'] ?? [] as $month) {
            $cumulative += (float)($month['net'] ?? 0);
            fputcsv($handle, [
                $month['month'] ?? '',
                $month['income'] ?? 0,
                $month['expenses'] ?? 0,
                $month['net'] ?? 0,
                $cumulative
            ]);
        }

        fputcsv($handle, ['']);
        fputcsv($handle, ['Averages']);
        $averages = $data['averageMonthly'] ?? [];
        fputcsv($handle, ['Average Monthly Income', $averages['income'] ?? 0]);
        fputcsv($handle, ['Average Monthly Expenses', $averages['expenses'] ?? 0]);
        fputcsv($handle, ['Average Monthly Net', $averages['net'] ?? 0]);
    }

    /**
     * Write income report to CSV.
     */
    private function writeIncomeCsv($handle, array $data): void {
        fputcsv($handle, ['Source', 'Amount', 'Transactions']);

        foreach ($data['data'] ?? [] as $item) {
            fputcsv($handle, [
                $item['name'] ?? 'Unknown',
                $item['total'] ?? 0,
                $item['count'] ?? 0
            ]);
        }

        fputcsv($handle, ['']);
        fputcsv($handle, ['Total', $data['totals']['amount'] ?? 0, $data['totals']['transactions'] ?? 0]);
    }

    /**
     * Write budget report to CSV.
     */
    private function writeBudgetCsv($handle, array $data): void {
        fputcsv($handle, ['Category', 'Budgeted', 'Spent', 'Remaining', 'Percentage', 'Status']);

        foreach ($data['categories'] ?? [] as $category) {
            fputcsv($handle, [
                $category['categoryName'] ?? '',
                $category['budgeted'] ?? 0,
                $category['spent'] ?? 0,
                $category['remaining'] ?? 0,
                round($category['percentage'] ?? 0, 1) . '%',
                $category['status'] ?? ''
            ]);
        }

        $totals = $data['totals'] ?? [];
        fputcsv($handle, ['']);
        fputcsv($handle, ['Total', $totals['budgeted'] ?? 0, $totals['spent'] ?? 0, $totals['remaining'] ?? 0]);
    }

    /**
     * Render summary report to PDF.
     */
    private function renderSummaryPdf($pdf, array $data): void {
        $totals = $data['totals'] ?? [];

        // Summary section
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Financial Summary', 0, 1);
        $pdf->SetFont('helvetica', '', 10);

        $summaryItems = [
            ['Total Income', $this->formatNumber($totals['totalIncome'] ?? 0)],
            ['Total Expenses', $this->formatNumber($totals['totalExpenses'] ?? 0)],
            ['Net Income', $this->formatNumber($totals['netIncome'] ?? 0)],
            ['Current Balance', $this->formatNumber($totals['currentBalance'] ?? 0)],
        ];

        foreach ($summaryItems as $item) {
            $pdf->Cell(80, 6, $item[0] . ':', 0, 0);
            $pdf->Cell(60, 6, $item[1], 0, 1, 'R');
        }

        // Comparison section
        if (isset($data['comparison']['changes'])) {
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 8, 'vs Previous Period', 0, 1);
            $pdf->SetFont('helvetica', '', 10);

            foreach ($data['comparison']['changes'] as $key => $change) {
                $direction = $change['direction'] ?? '';
                $arrow = $direction === 'up' ? '+' : ($direction === 'down' ? '-' : '');
                $pdf->Cell(80, 6, ucfirst($key) . ':', 0, 0);
                $pdf->Cell(60, 6, $arrow . ($change['percentage'] ?? 0) . '%', 0, 1, 'R');
            }
        }

        // Account breakdown
        if (!empty($data['accounts'])) {
            $pdf->Ln(10);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 8, 'Account Breakdown', 0, 1);

            // Table header
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(50, 6, 'Account', 1, 0, 'L');
            $pdf->Cell(30, 6, 'Income', 1, 0, 'R');
            $pdf->Cell(30, 6, 'Expenses', 1, 0, 'R');
            $pdf->Cell(30, 6, 'Net', 1, 0, 'R');
            $pdf->Cell(30, 6, 'Balance', 1, 1, 'R');

            $pdf->SetFont('helvetica', '', 9);
            foreach ($data['accounts'] as $account) {
                $pdf->Cell(50, 6, $account['name'] ?? '', 1, 0, 'L');
                $pdf->Cell(30, 6, $this->formatNumber($account['income'] ?? 0), 1, 0, 'R');
                $pdf->Cell(30, 6, $this->formatNumber($account['expenses'] ?? 0), 1, 0, 'R');
                $pdf->Cell(30, 6, $this->formatNumber($account['net'] ?? 0), 1, 0, 'R');
                $pdf->Cell(30, 6, $this->formatNumber($account['balance'] ?? 0), 1, 1, 'R');
            }
        }
    }

    /**
     * Render spending report to PDF.
     */
    private function renderSpendingPdf($pdf, array $data): void {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Spending by Category', 0, 1);

        // Table header
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(60, 6, 'Category', 1, 0, 'L');
        $pdf->Cell(40, 6, 'Amount', 1, 0, 'R');
        $pdf->Cell(40, 6, 'Transactions', 1, 0, 'R');
        $pdf->Cell(40, 6, '% of Total', 1, 1, 'R');

        $pdf->SetFont('helvetica', '', 9);
        $total = $data['totals']['amount'] ?? 0;

        foreach ($data['data'] ?? [] as $item) {
            $itemTotal = (float)($item['total'] ?? 0);
            $pct = $total > 0 ? round(($itemTotal / $total) * 100, 1) : 0;
            $pdf->Cell(60, 6, $item['name'] ?? 'Unknown', 1, 0, 'L');
            $pdf->Cell(40, 6, $this->formatNumber($itemTotal), 1, 0, 'R');
            $pdf->Cell(40, 6, $item['count'] ?? 0, 1, 0, 'R');
            $pdf->Cell(40, 6, $pct . '%', 1, 1, 'R');
        }

        // Totals
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(60, 6, 'Total', 1, 0, 'L');
        $pdf->Cell(40, 6, $this->formatNumber($total), 1, 0, 'R');
        $pdf->Cell(40, 6, $data['totals']['transactions'] ?? 0, 1, 0, 'R');
        $pdf->Cell(40, 6, '100%', 1, 1, 'R');
    }

    /**
     * Render cash flow report to PDF.
     */
    private function renderCashFlowPdf($pdf, array $data): void {
        // Averages section
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Monthly Averages', 0, 1);
        $pdf->SetFont('helvetica', '', 10);

        $averages = $data['averageMonthly'] ?? [];
        $pdf->Cell(60, 6, 'Average Monthly Income:', 0, 0);
        $pdf->Cell(40, 6, $this->formatNumber($averages['income'] ?? 0), 0, 1, 'R');
        $pdf->Cell(60, 6, 'Average Monthly Expenses:', 0, 0);
        $pdf->Cell(40, 6, $this->formatNumber($averages['expenses'] ?? 0), 0, 1, 'R');
        $pdf->Cell(60, 6, 'Average Monthly Net:', 0, 0);
        $pdf->Cell(40, 6, $this->formatNumber($averages['net'] ?? 0), 0, 1, 'R');

        $pdf->Ln(10);

        // Monthly breakdown
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Monthly Breakdown', 0, 1);

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(35, 6, 'Month', 1, 0, 'L');
        $pdf->Cell(35, 6, 'Income', 1, 0, 'R');
        $pdf->Cell(35, 6, 'Expenses', 1, 0, 'R');
        $pdf->Cell(35, 6, 'Net', 1, 0, 'R');
        $pdf->Cell(35, 6, 'Cumulative', 1, 1, 'R');

        $pdf->SetFont('helvetica', '', 9);
        $cumulative = 0;

        foreach ($data['data'] ?? [] as $month) {
            $cumulative += (float)($month['net'] ?? 0);
            $monthLabel = $this->calculator->formatMonthLabel($month['month'] ?? '');
            $pdf->Cell(35, 6, $monthLabel, 1, 0, 'L');
            $pdf->Cell(35, 6, $this->formatNumber($month['income'] ?? 0), 1, 0, 'R');
            $pdf->Cell(35, 6, $this->formatNumber($month['expenses'] ?? 0), 1, 0, 'R');
            $pdf->Cell(35, 6, $this->formatNumber($month['net'] ?? 0), 1, 0, 'R');
            $pdf->Cell(35, 6, $this->formatNumber($cumulative), 1, 1, 'R');
        }
    }

    /**
     * Render income report to PDF.
     */
    private function renderIncomePdf($pdf, array $data): void {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Income Report', 0, 1);

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(70, 6, 'Source', 1, 0, 'L');
        $pdf->Cell(50, 6, 'Amount', 1, 0, 'R');
        $pdf->Cell(50, 6, 'Transactions', 1, 1, 'R');

        $pdf->SetFont('helvetica', '', 9);
        foreach ($data['data'] ?? [] as $item) {
            $pdf->Cell(70, 6, $item['name'] ?? 'Unknown', 1, 0, 'L');
            $pdf->Cell(50, 6, $this->formatNumber($item['total'] ?? 0), 1, 0, 'R');
            $pdf->Cell(50, 6, $item['count'] ?? 0, 1, 1, 'R');
        }

        // Totals
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(70, 6, 'Total', 1, 0, 'L');
        $pdf->Cell(50, 6, $this->formatNumber($data['totals']['amount'] ?? 0), 1, 0, 'R');
        $pdf->Cell(50, 6, $data['totals']['transactions'] ?? 0, 1, 1, 'R');
    }

    /**
     * Render budget report to PDF.
     */
    private function renderBudgetPdf($pdf, array $data): void {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Budget Report', 0, 1);

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(40, 6, 'Category', 1, 0, 'L');
        $pdf->Cell(30, 6, 'Budgeted', 1, 0, 'R');
        $pdf->Cell(30, 6, 'Spent', 1, 0, 'R');
        $pdf->Cell(30, 6, 'Remaining', 1, 0, 'R');
        $pdf->Cell(25, 6, '%', 1, 0, 'R');
        $pdf->Cell(25, 6, 'Status', 1, 1, 'C');

        $pdf->SetFont('helvetica', '', 9);
        foreach ($data['categories'] ?? [] as $category) {
            $pdf->Cell(40, 6, $category['categoryName'] ?? '', 1, 0, 'L');
            $pdf->Cell(30, 6, $this->formatNumber($category['budgeted'] ?? 0), 1, 0, 'R');
            $pdf->Cell(30, 6, $this->formatNumber($category['spent'] ?? 0), 1, 0, 'R');
            $pdf->Cell(30, 6, $this->formatNumber($category['remaining'] ?? 0), 1, 0, 'R');
            $pdf->Cell(25, 6, round($category['percentage'] ?? 0, 1) . '%', 1, 0, 'R');
            $pdf->Cell(25, 6, ucfirst($category['status'] ?? ''), 1, 1, 'C');
        }

        // Totals
        $totals = $data['totals'] ?? [];
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(40, 6, 'Total', 1, 0, 'L');
        $pdf->Cell(30, 6, $this->formatNumber($totals['budgeted'] ?? 0), 1, 0, 'R');
        $pdf->Cell(30, 6, $this->formatNumber($totals['spent'] ?? 0), 1, 0, 'R');
        $pdf->Cell(30, 6, $this->formatNumber($totals['remaining'] ?? 0), 1, 0, 'R');
        $pdf->Cell(25, 6, '', 1, 0, 'R');
        $pdf->Cell(25, 6, ucfirst($data['overallStatus'] ?? ''), 1, 1, 'C');
    }

    /**
     * Write the category-by-month matrix to CSV (#288). One row per category
     * (children indented), a column per month, then an Overall total, with a
     * Net total row at the end. Values use a plain decimal (no thousands
     * separator) so the columns stay intact.
     */
    private function writeCategoryMonthlyCsv($handle, array $data): void {
        $months = $data['period']['months'] ?? [];

        $header = ['Category'];
        foreach ($months as $m) {
            $header[] = date('M Y', strtotime($m . '-01'));
        }
        $header[] = 'Overall';
        fputcsv($handle, $header);

        foreach ($data['rows'] ?? [] as $row) {
            $line = [str_repeat('    ', (int) ($row['depth'] ?? 0)) . ($row['name'] ?? '')];
            foreach ($months as $m) {
                $line[] = number_format((float) ($row['monthly'][$m] ?? 0), 2, '.', '');
            }
            $line[] = number_format((float) ($row['total'] ?? 0), 2, '.', '');
            fputcsv($handle, $line);
        }

        fputcsv($handle, ['']);
        $totalLine = ['Net total'];
        foreach ($months as $m) {
            $totalLine[] = number_format((float) ($data['totals']['monthly'][$m] ?? 0), 2, '.', '');
        }
        $totalLine[] = number_format((float) ($data['totals']['total'] ?? 0), 2, '.', '');
        fputcsv($handle, $totalLine);
    }

    /**
     * Render the category-by-month matrix to PDF (#288), landscape with one
     * column per month. Column widths scale to the number of months; negative
     * (expense) values are shown in red.
     */
    private function renderCategoryMonthlyPdf($pdf, array $data): void {
        $months = $data['period']['months'] ?? [];

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Category Income & Expenses by Month', 0, 1);
        $pdf->Ln(1);

        // A4 landscape usable width is ~267mm (297 - 2x15mm margins). Divide the
        // remaining width evenly so the table always fits the page, even for long
        // custom ranges (a narrower category column when there are many months).
        $numCols = count($months) + 1; // months + Overall
        $catW = $numCols > 13 ? 42.0 : 55.0;
        $colW = $numCols > 0 ? (267.0 - $catW) / $numCols : 20.0;
        $fontSize = $numCols > 13 ? 6.0 : ($numCols > 9 ? 6.5 : 8.0);

        $pdf->SetFont('helvetica', 'B', $fontSize);
        $pdf->Cell($catW, 6, 'Category', 1, 0, 'L');
        foreach ($months as $m) {
            $pdf->Cell($colW, 6, date('M y', strtotime($m . '-01')), 1, 0, 'R');
        }
        $pdf->Cell($colW, 6, 'Overall', 1, 1, 'R');

        foreach ($data['rows'] ?? [] as $row) {
            $name = str_repeat('   ', (int) ($row['depth'] ?? 0)) . ($row['name'] ?? '');
            $pdf->SetFont('helvetica', !empty($row['isParent']) ? 'B' : '', $fontSize);
            $pdf->Cell($catW, 5, $this->truncateText($name, 40), 1, 0, 'L');
            foreach ($months as $m) {
                $this->amountCell($pdf, $colW, (float) ($row['monthly'][$m] ?? 0), false);
            }
            $this->amountCell($pdf, $colW, (float) ($row['total'] ?? 0), true);
        }

        $pdf->SetFont('helvetica', 'B', $fontSize);
        $pdf->Cell($catW, 6, 'Net total', 1, 0, 'L');
        foreach ($months as $m) {
            $this->amountCell($pdf, $colW, (float) ($data['totals']['monthly'][$m] ?? 0), false);
        }
        $this->amountCell($pdf, $colW, (float) ($data['totals']['total'] ?? 0), true);
        $pdf->Ln();
    }

    /**
     * Render one right-aligned amount cell, in red when negative.
     */
    private function amountCell($pdf, float $width, float $value, bool $newline): void {
        if ($value < 0) {
            $pdf->SetTextColor(200, 0, 0);
        }
        $pdf->Cell($width, 5, $this->formatNumber($value), 1, $newline ? 1 : 0, 'R');
        if ($value < 0) {
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    /**
     * Truncate a string to a maximum length (ASCII ellipsis for PDF core fonts).
     */
    private function truncateText(string $text, int $max): string {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '...' : $text;
    }

    /**
     * Format number for display.
     */
    private function formatNumber(float $value): string {
        return number_format($value, 2);
    }
}
