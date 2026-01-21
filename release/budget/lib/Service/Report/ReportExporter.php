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

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

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
                fputcsv($handle, [ucfirst($key) . ' Change', $change['percentage'] . '% ' . $change['direction']]);
            }
        }

        // Write account details
        fputcsv($handle, ['']);
        fputcsv($handle, ['Account', 'Balance', 'Income', 'Expenses', 'Net']);

        foreach ($data['accounts'] ?? [] as $account) {
            fputcsv($handle, [
                $account['name'],
                $account['balance'],
                $account['income'],
                $account['expenses'],
                $account['net']
            ]);
        }
    }

    /**
     * Write spending report to CSV.
     */
    private function writeSpendingCsv($handle, array $data): void {
        fputcsv($handle, ['Category', 'Amount', 'Transactions', 'Percentage']);

        $total = $data['totals']['amount'] ?? 0;

        foreach ($data['data'] as $item) {
            $pct = $total > 0 ? round(($item['total'] / $total) * 100, 1) : 0;
            fputcsv($handle, [
                $item['name'] ?? 'Unknown',
                $item['total'],
                $item['count'],
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
        foreach ($data['data'] as $month) {
            $cumulative += $month['net'];
            fputcsv($handle, [
                $month['month'],
                $month['income'],
                $month['expenses'],
                $month['net'],
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

        foreach ($data['data'] as $item) {
            fputcsv($handle, [
                $item['name'] ?? 'Unknown',
                $item['total'],
                $item['count']
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
                $category['categoryName'],
                $category['budgeted'],
                $category['spent'],
                $category['remaining'],
                round($category['percentage'], 1) . '%',
                $category['status']
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
                $arrow = $change['direction'] === 'up' ? '+' : ($change['direction'] === 'down' ? '-' : '');
                $pdf->Cell(80, 6, ucfirst($key) . ':', 0, 0);
                $pdf->Cell(60, 6, $arrow . $change['percentage'] . '%', 0, 1, 'R');
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
                $pdf->Cell(50, 6, $account['name'], 1, 0, 'L');
                $pdf->Cell(30, 6, $this->formatNumber($account['income']), 1, 0, 'R');
                $pdf->Cell(30, 6, $this->formatNumber($account['expenses']), 1, 0, 'R');
                $pdf->Cell(30, 6, $this->formatNumber($account['net']), 1, 0, 'R');
                $pdf->Cell(30, 6, $this->formatNumber($account['balance']), 1, 1, 'R');
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

        foreach ($data['data'] as $item) {
            $pct = $total > 0 ? round(($item['total'] / $total) * 100, 1) : 0;
            $pdf->Cell(60, 6, $item['name'] ?? 'Unknown', 1, 0, 'L');
            $pdf->Cell(40, 6, $this->formatNumber($item['total']), 1, 0, 'R');
            $pdf->Cell(40, 6, $item['count'], 1, 0, 'R');
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

        foreach ($data['data'] as $month) {
            $cumulative += $month['net'];
            $monthLabel = $this->calculator->formatMonthLabel($month['month']);
            $pdf->Cell(35, 6, $monthLabel, 1, 0, 'L');
            $pdf->Cell(35, 6, $this->formatNumber($month['income']), 1, 0, 'R');
            $pdf->Cell(35, 6, $this->formatNumber($month['expenses']), 1, 0, 'R');
            $pdf->Cell(35, 6, $this->formatNumber($month['net']), 1, 0, 'R');
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
        foreach ($data['data'] as $item) {
            $pdf->Cell(70, 6, $item['name'] ?? 'Unknown', 1, 0, 'L');
            $pdf->Cell(50, 6, $this->formatNumber($item['total']), 1, 0, 'R');
            $pdf->Cell(50, 6, $item['count'], 1, 1, 'R');
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
            $pdf->Cell(40, 6, $category['categoryName'], 1, 0, 'L');
            $pdf->Cell(30, 6, $this->formatNumber($category['budgeted']), 1, 0, 'R');
            $pdf->Cell(30, 6, $this->formatNumber($category['spent']), 1, 0, 'R');
            $pdf->Cell(30, 6, $this->formatNumber($category['remaining']), 1, 0, 'R');
            $pdf->Cell(25, 6, round($category['percentage'], 1) . '%', 1, 0, 'R');
            $pdf->Cell(25, 6, ucfirst($category['status']), 1, 1, 'C');
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
     * Format number for display.
     */
    private function formatNumber(float $value): string {
        return number_format($value, 2);
    }
}
