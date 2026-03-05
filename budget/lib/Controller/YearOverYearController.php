<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\YearOverYearService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class YearOverYearController extends Controller {
    private YearOverYearService $service;
    private string $userId;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        YearOverYearService $service,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userId;
        $this->logger = $logger;
    }

    /**
     * Compare the same month across multiple years.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function compareMonth(int $month = 0, int $years = 3, ?int $accountId = null): DataResponse {
        try {
            // Default to current month if not specified
            if ($month <= 0 || $month > 12) {
                $month = (int) date('n');
            }

            // Limit years to reasonable range
            $years = max(1, min(10, $years));

            $comparison = $this->service->compareMonth($this->userId, $month, $years, $accountId);
            return new DataResponse($comparison);
        } catch (\Exception $e) {
            $this->logger->error('Failed to compare month', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to compare month data'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Compare full years.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function compareYears(int $years = 3, ?int $accountId = null): DataResponse {
        try {
            // Limit years to reasonable range
            $years = max(1, min(10, $years));

            $comparison = $this->service->compareYears($this->userId, $years, $accountId);
            return new DataResponse($comparison);
        } catch (\Exception $e) {
            $this->logger->error('Failed to compare years', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to compare year data'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Compare spending by category across years.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function compareCategories(int $years = 2, ?int $accountId = null): DataResponse {
        try {
            // Limit years to reasonable range
            $years = max(1, min(5, $years));

            $comparison = $this->service->compareCategorySpending($this->userId, $years, $accountId);
            return new DataResponse($comparison);
        } catch (\Exception $e) {
            $this->logger->error('Failed to compare categories', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to compare category data'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get monthly trends for year comparison.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function monthlyTrends(int $years = 2, ?int $accountId = null): DataResponse {
        try {
            // Limit years to reasonable range
            $years = max(1, min(5, $years));

            $trends = $this->service->getMonthlyTrends($this->userId, $years, $accountId);
            return new DataResponse($trends);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get monthly trends', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to get monthly trends'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Export YoY comparison data as CSV or PDF.
     *
     * @NoAdminRequired
     */
    public function export(
        string $comparisonType = 'years',
        string $format = 'csv',
        int $years = 3,
        int $month = 0,
        ?int $accountId = null
    ): DataDownloadResponse|DataResponse {
        try {
            $years = max(1, min(10, $years));

            $data = match ($comparisonType) {
                'month' => $this->service->compareMonth(
                    $this->userId,
                    ($month > 0 && $month <= 12) ? $month : (int) date('n'),
                    $years,
                    $accountId
                ),
                'categories' => $this->service->compareCategorySpending($this->userId, min($years, 5), $accountId),
                default => $this->service->compareYears($this->userId, $years, $accountId),
            };

            if ($format === 'pdf') {
                $result = $this->exportYoYToPdf($data, $comparisonType);
            } else {
                $result = $this->exportYoYToCsv($data, $comparisonType);
            }

            return new DataDownloadResponse(
                $result['stream'],
                $result['filename'],
                $result['contentType']
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to export YoY data', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to export YoY data'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function exportYoYToCsv(array $data, string $comparisonType): array {
        $csv = fopen('php://memory', 'w');

        if ($comparisonType === 'categories') {
            $this->writeYoYCategoriesCsv($csv, $data);
        } else {
            $this->writeYoYComparisonCsv($csv, $data, $comparisonType);
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return [
            'stream' => $content,
            'contentType' => 'text/csv',
            'filename' => "yoy_{$comparisonType}_" . date('Y-m-d') . '.csv',
        ];
    }

    private function writeYoYComparisonCsv($handle, array $data, string $comparisonType): void {
        $label = $comparisonType === 'month'
            ? ($data['monthName'] ?? 'Month') . ' Comparison'
            : 'Year Comparison';

        fputcsv($handle, [$label]);
        fputcsv($handle, ['Year', 'Income', 'Expenses', 'Savings', 'Transactions', 'Income Change %', 'Expense Change %']);

        foreach ($data['years'] ?? [] as $year) {
            fputcsv($handle, [
                $year['year'] ?? '',
                $year['income'] ?? 0,
                $year['expenses'] ?? 0,
                $year['savings'] ?? 0,
                $year['transactionCount'] ?? 0,
                isset($year['incomeChange']) ? round($year['incomeChange'], 1) . '%' : '-',
                isset($year['expenseChange']) ? round($year['expenseChange'], 1) . '%' : '-',
            ]);
        }
    }

    private function writeYoYCategoriesCsv($handle, array $data): void {
        // Build header: Category, Year1, Year2, ..., Change %
        $years = [];
        foreach ($data['categories'] ?? [] as $cat) {
            foreach ($cat['years'] ?? [] as $y) {
                if (!in_array($y['year'], $years)) {
                    $years[] = $y['year'];
                }
            }
            break;
        }
        sort($years);

        $header = ['Category'];
        foreach ($years as $y) {
            $header[] = (string) $y;
        }
        $header[] = 'Change %';
        fputcsv($handle, $header);

        foreach ($data['categories'] ?? [] as $cat) {
            $row = [$cat['name'] ?? 'Unknown'];
            // Build year lookup for this category
            $yearLookup = [];
            foreach ($cat['years'] ?? [] as $y) {
                $yearLookup[$y['year']] = $y['spending'] ?? 0;
            }
            foreach ($years as $y) {
                $row[] = $yearLookup[$y] ?? 0;
            }
            $row[] = $cat['change'] !== null ? round($cat['change'], 1) . '%' : '-';
            fputcsv($handle, $row);
        }
    }

    private function exportYoYToPdf(array $data, string $comparisonType): array {
        if (!class_exists('TCPDF')) {
            // Fallback to CSV
            return $this->exportYoYToCsv($data, $comparisonType);
        }

        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Nextcloud Budget');
        $pdf->SetTitle('Year-over-Year Report');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Year-over-Year Report', 0, 1, 'C');
        $pdf->Ln(5);

        if ($comparisonType === 'categories') {
            $this->renderYoYCategoriesPdf($pdf, $data);
        } else {
            $this->renderYoYComparisonPdf($pdf, $data, $comparisonType);
        }

        return [
            'stream' => $pdf->Output('', 'S'),
            'contentType' => 'application/pdf',
            'filename' => "yoy_{$comparisonType}_" . date('Y-m-d') . '.pdf',
        ];
    }

    private function renderYoYComparisonPdf($pdf, array $data, string $comparisonType): void {
        $label = $comparisonType === 'month'
            ? ($data['monthName'] ?? 'Month') . ' Comparison'
            : 'Year Comparison';

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, $label, 0, 1);

        $pdf->SetFont('helvetica', 'B', 9);
        $colWidths = [25, 35, 35, 35, 30, 35, 35];
        $headers = ['Year', 'Income', 'Expenses', 'Savings', 'Txns', 'Income Chg', 'Expense Chg'];
        foreach ($headers as $i => $h) {
            $pdf->Cell($colWidths[$i], 6, $h, 1, 0, $i === 0 ? 'L' : 'R');
        }
        $pdf->Ln();

        $pdf->SetFont('helvetica', '', 9);
        foreach ($data['years'] ?? [] as $year) {
            $pdf->Cell($colWidths[0], 6, $year['year'] ?? '', 1, 0, 'L');
            $pdf->Cell($colWidths[1], 6, number_format($year['income'] ?? 0, 2), 1, 0, 'R');
            $pdf->Cell($colWidths[2], 6, number_format($year['expenses'] ?? 0, 2), 1, 0, 'R');
            $pdf->Cell($colWidths[3], 6, number_format($year['savings'] ?? 0, 2), 1, 0, 'R');
            $pdf->Cell($colWidths[4], 6, $year['transactionCount'] ?? 0, 1, 0, 'R');
            $pdf->Cell($colWidths[5], 6, isset($year['incomeChange']) ? round($year['incomeChange'], 1) . '%' : '-', 1, 0, 'R');
            $pdf->Cell($colWidths[6], 6, isset($year['expenseChange']) ? round($year['expenseChange'], 1) . '%' : '-', 1, 0, 'R');
            $pdf->Ln();
        }
    }

    private function renderYoYCategoriesPdf($pdf, array $data): void {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Category Spending Comparison', 0, 1);

        // Determine year columns
        $years = [];
        foreach ($data['categories'] ?? [] as $cat) {
            foreach ($cat['years'] ?? [] as $y) {
                if (!in_array($y['year'], $years)) {
                    $years[] = $y['year'];
                }
            }
            break;
        }
        sort($years);

        // Header
        $pdf->SetFont('helvetica', 'B', 9);
        $catWidth = 60;
        $yearWidth = count($years) > 0 ? min(40, (210 - $catWidth - 30) / count($years)) : 40;
        $changeWidth = 30;

        $pdf->Cell($catWidth, 6, 'Category', 1, 0, 'L');
        foreach ($years as $y) {
            $pdf->Cell($yearWidth, 6, (string) $y, 1, 0, 'R');
        }
        $pdf->Cell($changeWidth, 6, 'Change %', 1, 1, 'R');

        // Data rows
        $pdf->SetFont('helvetica', '', 9);
        foreach ($data['categories'] ?? [] as $cat) {
            $yearLookup = [];
            foreach ($cat['years'] ?? [] as $y) {
                $yearLookup[$y['year']] = $y['spending'] ?? 0;
            }

            $pdf->Cell($catWidth, 6, $cat['name'] ?? 'Unknown', 1, 0, 'L');
            foreach ($years as $y) {
                $pdf->Cell($yearWidth, 6, number_format($yearLookup[$y] ?? 0, 2), 1, 0, 'R');
            }
            $pdf->Cell($changeWidth, 6, $cat['change'] !== null ? round($cat['change'], 1) . '%' : '-', 1, 1, 'R');
        }
    }
}
