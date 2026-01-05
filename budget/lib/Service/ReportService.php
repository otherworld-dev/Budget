<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\CategoryMapper;

class ReportService {
    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;
    private CategoryMapper $categoryMapper;

    public function __construct(
        AccountMapper $accountMapper,
        TransactionMapper $transactionMapper,
        CategoryMapper $categoryMapper
    ) {
        $this->accountMapper = $accountMapper;
        $this->transactionMapper = $transactionMapper;
        $this->categoryMapper = $categoryMapper;
    }

    public function generateSummary(
        string $userId,
        ?int $accountId = null,
        string $startDate,
        string $endDate
    ): array {
        $accounts = $accountId 
            ? [$this->accountMapper->find($accountId, $userId)]
            : $this->accountMapper->findAll($userId);

        $summary = [
            'period' => [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'days' => (strtotime($endDate) - strtotime($startDate)) / (24 * 60 * 60)
            ],
            'accounts' => [],
            'totals' => [
                'currentBalance' => 0,
                'totalIncome' => 0,
                'totalExpenses' => 0,
                'netIncome' => 0,
                'averageDaily' => [
                    'income' => 0,
                    'expenses' => 0
                ]
            ],
            'spending' => [],
            'trends' => []
        ];

        $totalIncome = 0;
        $totalExpenses = 0;

        foreach ($accounts as $account) {
            $accountTransactions = $this->transactionMapper->findByDateRange(
                $account->getId(),
                $startDate,
                $endDate
            );

            $accountIncome = 0;
            $accountExpenses = 0;

            foreach ($accountTransactions as $transaction) {
                if ($transaction->getType() === 'credit') {
                    $accountIncome += $transaction->getAmount();
                } else {
                    $accountExpenses += $transaction->getAmount();
                }
            }

            $summary['accounts'][] = [
                'id' => $account->getId(),
                'name' => $account->getName(),
                'balance' => $account->getBalance(),
                'currency' => $account->getCurrency(),
                'income' => $accountIncome,
                'expenses' => $accountExpenses,
                'net' => $accountIncome - $accountExpenses,
                'transactionCount' => count($accountTransactions)
            ];

            $summary['totals']['currentBalance'] += $account->getBalance();
            $totalIncome += $accountIncome;
            $totalExpenses += $accountExpenses;
        }

        $summary['totals']['totalIncome'] = $totalIncome;
        $summary['totals']['totalExpenses'] = $totalExpenses;
        $summary['totals']['netIncome'] = $totalIncome - $totalExpenses;
        
        $days = $summary['period']['days'];
        if ($days > 0) {
            $summary['totals']['averageDaily']['income'] = $totalIncome / $days;
            $summary['totals']['averageDaily']['expenses'] = $totalExpenses / $days;
        }

        // Get spending breakdown
        $summary['spending'] = $this->transactionMapper->getSpendingSummary(
            $userId, 
            $startDate, 
            $endDate
        );

        // Generate trend data
        $summary['trends'] = $this->generateTrendData($userId, $accountId, $startDate, $endDate);

        return $summary;
    }

    public function getSpendingReport(
        string $userId,
        ?int $accountId = null,
        string $startDate,
        string $endDate,
        string $groupBy = 'category'
    ): array {
        $report = [
            'period' => [
                'startDate' => $startDate,
                'endDate' => $endDate
            ],
            'groupBy' => $groupBy,
            'data' => [],
            'totals' => [
                'amount' => 0,
                'transactions' => 0
            ]
        ];

        switch ($groupBy) {
            case 'category':
                $report['data'] = $this->getSpendingByCategory($userId, $accountId, $startDate, $endDate);
                break;
            case 'month':
                $report['data'] = $this->getSpendingByMonth($userId, $accountId, $startDate, $endDate);
                break;
            case 'vendor':
                $report['data'] = $this->getSpendingByVendor($userId, $accountId, $startDate, $endDate);
                break;
            case 'account':
                $report['data'] = $this->getSpendingByAccount($userId, $startDate, $endDate);
                break;
        }

        // Calculate totals
        foreach ($report['data'] as $item) {
            $report['totals']['amount'] += $item['total'];
            $report['totals']['transactions'] += $item['count'];
        }

        return $report;
    }

    public function getIncomeReport(
        string $userId,
        ?int $accountId = null,
        string $startDate,
        string $endDate,
        string $groupBy = 'month'
    ): array {
        $report = [
            'period' => [
                'startDate' => $startDate,
                'endDate' => $endDate
            ],
            'groupBy' => $groupBy,
            'data' => [],
            'totals' => [
                'amount' => 0,
                'transactions' => 0
            ]
        ];

        switch ($groupBy) {
            case 'category':
                $report['data'] = $this->getIncomeByCategory($userId, $accountId, $startDate, $endDate);
                break;
            case 'month':
                $report['data'] = $this->getIncomeByMonth($userId, $accountId, $startDate, $endDate);
                break;
            case 'source':
                $report['data'] = $this->getIncomeBySource($userId, $accountId, $startDate, $endDate);
                break;
        }

        // Calculate totals
        foreach ($report['data'] as $item) {
            $report['totals']['amount'] += $item['total'];
            $report['totals']['transactions'] += $item['count'];
        }

        return $report;
    }

    public function getBudgetReport(string $userId, string $startDate, string $endDate): array {
        $categories = $this->categoryMapper->findAll($userId);
        $budgetReport = [];
        $totals = [
            'budgeted' => 0,
            'spent' => 0,
            'remaining' => 0
        ];

        foreach ($categories as $category) {
            if ($category->getBudgetAmount() > 0) {
                $spent = $this->categoryMapper->getCategorySpending(
                    $category->getId(),
                    $startDate,
                    $endDate
                );

                $budgeted = $category->getBudgetAmount();
                $remaining = $budgeted - $spent;
                $percentage = $budgeted > 0 ? ($spent / $budgeted) * 100 : 0;

                $budgetReport[] = [
                    'categoryId' => $category->getId(),
                    'categoryName' => $category->getName(),
                    'budgeted' => $budgeted,
                    'spent' => $spent,
                    'remaining' => $remaining,
                    'percentage' => $percentage,
                    'status' => $this->getBudgetStatus($percentage),
                    'color' => $category->getColor()
                ];

                $totals['budgeted'] += $budgeted;
                $totals['spent'] += $spent;
                $totals['remaining'] += $remaining;
            }
        }

        return [
            'period' => [
                'startDate' => $startDate,
                'endDate' => $endDate
            ],
            'categories' => $budgetReport,
            'totals' => $totals,
            'overallStatus' => $this->getBudgetStatus(
                $totals['budgeted'] > 0 ? ($totals['spent'] / $totals['budgeted']) * 100 : 0
            )
        ];
    }

    public function generateSummaryWithComparison(
        string $userId,
        ?int $accountId = null,
        string $startDate,
        string $endDate
    ): array {
        // Current period
        $current = $this->generateSummary($userId, $accountId, $startDate, $endDate);

        // Calculate previous period (same duration)
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $interval = $start->diff($end);

        $prevEnd = clone $start;
        $prevEnd->modify('-1 day');
        $prevStart = clone $prevEnd;
        $prevStart->sub($interval);

        $previous = $this->generateSummary(
            $userId,
            $accountId,
            $prevStart->format('Y-m-d'),
            $prevEnd->format('Y-m-d')
        );

        // Calculate changes
        $current['comparison'] = [
            'previousPeriod' => [
                'startDate' => $prevStart->format('Y-m-d'),
                'endDate' => $prevEnd->format('Y-m-d')
            ],
            'changes' => [
                'income' => $this->calculatePercentChange(
                    $previous['totals']['totalIncome'] ?? 0,
                    $current['totals']['totalIncome'] ?? 0
                ),
                'expenses' => $this->calculatePercentChange(
                    $previous['totals']['totalExpenses'] ?? 0,
                    $current['totals']['totalExpenses'] ?? 0
                ),
                'netIncome' => $this->calculatePercentChange(
                    $previous['totals']['netIncome'] ?? 0,
                    $current['totals']['netIncome'] ?? 0
                )
            ],
            'previousTotals' => $previous['totals'] ?? []
        ];

        return $current;
    }

    public function getCashFlowReport(
        string $userId,
        ?int $accountId = null,
        string $startDate,
        string $endDate
    ): array {
        $cashFlow = $this->transactionMapper->getCashFlowByMonth($userId, $accountId, $startDate, $endDate);

        $totals = ['income' => 0, 'expenses' => 0, 'net' => 0];
        foreach ($cashFlow as $month) {
            $totals['income'] += $month['income'];
            $totals['expenses'] += $month['expenses'];
            $totals['net'] += $month['net'];
        }

        $monthCount = count($cashFlow);

        return [
            'period' => ['startDate' => $startDate, 'endDate' => $endDate],
            'data' => $cashFlow,
            'totals' => $totals,
            'averageMonthly' => [
                'income' => $monthCount > 0 ? $totals['income'] / $monthCount : 0,
                'expenses' => $monthCount > 0 ? $totals['expenses'] / $monthCount : 0,
                'net' => $monthCount > 0 ? $totals['net'] / $monthCount : 0
            ]
        ];
    }

    private function calculatePercentChange(float $previous, float $current): array {
        if ($previous == 0) {
            return [
                'percentage' => $current > 0 ? 100 : 0,
                'direction' => $current > 0 ? 'up' : ($current < 0 ? 'down' : 'none'),
                'absolute' => $current - $previous
            ];
        }

        $change = (($current - $previous) / abs($previous)) * 100;
        return [
            'percentage' => round(abs($change), 1),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'none'),
            'absolute' => $current - $previous
        ];
    }

    public function exportReport(
        string $userId,
        string $type,
        string $format,
        ?int $accountId = null,
        string $startDate,
        string $endDate
    ): array {
        // Generate the report data
        switch ($type) {
            case 'summary':
                $data = $this->generateSummaryWithComparison($userId, $accountId, $startDate, $endDate);
                break;
            case 'spending':
                $data = $this->getSpendingReport($userId, $accountId, $startDate, $endDate);
                break;
            case 'income':
                $data = $this->getIncomeReport($userId, $accountId, $startDate, $endDate);
                break;
            case 'cashflow':
                $data = $this->getCashFlowReport($userId, $accountId, $startDate, $endDate);
                break;
            case 'budget':
                $data = $this->getBudgetReport($userId, $startDate, $endDate);
                break;
            default:
                throw new \InvalidArgumentException('Unknown report type: ' . $type);
        }

        // Export in requested format
        switch ($format) {
            case 'csv':
                return $this->exportToCsv($data, $type);
            case 'json':
                return $this->exportToJson($data, $type);
            case 'pdf':
                return $this->exportToPdf($data, $type);
            default:
                throw new \InvalidArgumentException('Unknown format: ' . $format);
        }
    }

    private function getSpendingByCategory(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        return $this->transactionMapper->getSpendingSummary($userId, $startDate, $endDate);
    }

    private function getSpendingByMonth(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        $data = $this->transactionMapper->getSpendingByMonth($userId, $accountId, $startDate, $endDate);
        return array_map(fn($row) => [
            'name' => $this->formatMonthLabel($row['month']),
            'month' => $row['month'],
            'total' => (float)$row['total'],
            'count' => (int)$row['count']
        ], $data);
    }

    private function getSpendingByVendor(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        return $this->transactionMapper->getSpendingByVendor($userId, $accountId, $startDate, $endDate);
    }

    private function getSpendingByAccount(string $userId, string $startDate, string $endDate): array {
        $accounts = $this->accountMapper->findAll($userId);
        $data = [];

        foreach ($accounts as $account) {
            $transactions = $this->transactionMapper->findByDateRange(
                $account->getId(),
                $startDate,
                $endDate
            );

            $total = 0;
            $count = 0;

            foreach ($transactions as $transaction) {
                if ($transaction->getType() === 'debit') {
                    $total += $transaction->getAmount();
                    $count++;
                }
            }

            if ($count > 0) {
                $data[] = [
                    'name' => $account->getName(),
                    'total' => $total,
                    'count' => $count,
                    'average' => $total / $count
                ];
            }
        }

        return $data;
    }

    private function getIncomeByCategory(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        // For income by category, we'd need a similar query to spending
        // For now, return income grouped by source as a proxy
        return $this->getIncomeBySource($userId, $accountId, $startDate, $endDate);
    }

    private function getIncomeByMonth(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        $data = $this->transactionMapper->getIncomeByMonth($userId, $accountId, $startDate, $endDate);
        return array_map(fn($row) => [
            'name' => $this->formatMonthLabel($row['month']),
            'month' => $row['month'],
            'total' => (float)$row['total'],
            'count' => (int)$row['count']
        ], $data);
    }

    private function getIncomeBySource(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        return $this->transactionMapper->getIncomeBySource($userId, $accountId, $startDate, $endDate);
    }

    private function formatMonthLabel(string $yearMonth): string {
        $date = \DateTime::createFromFormat('Y-m', $yearMonth);
        return $date ? $date->format('M Y') : $yearMonth;
    }

    private function generateTrendData(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        // Generate monthly trend data for the chart
        $trends = [
            'labels' => [],
            'income' => [],
            'expenses' => []
        ];

        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $interval = new \DateInterval('P1M');

        $current = clone $start;
        while ($current <= $end) {
            $monthStart = $current->format('Y-m-01');
            $monthEnd = $current->format('Y-m-t');
            
            $trends['labels'][] = $current->format('M Y');
            
            // Get transactions for this month
            $monthIncome = 0;
            $monthExpenses = 0;
            
            $accounts = $accountId 
                ? [$this->accountMapper->find($accountId, $userId)]
                : $this->accountMapper->findAll($userId);
            
            foreach ($accounts as $account) {
                $transactions = $this->transactionMapper->findByDateRange(
                    $account->getId(),
                    $monthStart,
                    $monthEnd
                );
                
                foreach ($transactions as $transaction) {
                    if ($transaction->getType() === 'credit') {
                        $monthIncome += $transaction->getAmount();
                    } else {
                        $monthExpenses += $transaction->getAmount();
                    }
                }
            }
            
            $trends['income'][] = $monthIncome;
            $trends['expenses'][] = $monthExpenses;
            
            $current->add($interval);
        }

        return $trends;
    }

    private function getBudgetStatus(float $percentage): string {
        if ($percentage <= 50) {
            return 'good';
        } elseif ($percentage <= 80) {
            return 'warning';
        } elseif ($percentage <= 100) {
            return 'danger';
        } else {
            return 'over';
        }
    }

    private function exportToCsv(array $data, string $type): array {
        $csv = fopen('php://memory', 'w');

        switch ($type) {
            case 'summary':
                $this->writeSummaryCsv($csv, $data);
                break;
            case 'spending':
                $this->writeSpendingCsv($csv, $data);
                break;
            case 'cashflow':
                $this->writeCashFlowCsv($csv, $data);
                break;
            case 'income':
                $this->writeIncomeCsv($csv, $data);
                break;
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return [
            'stream' => $content,
            'contentType' => 'text/csv',
            'filename' => $type . '_report_' . date('Y-m-d') . '.csv'
        ];
    }

    private function exportToJson(array $data, string $type): array {
        return [
            'stream' => json_encode($data, JSON_PRETTY_PRINT),
            'contentType' => 'application/json',
            'filename' => $type . '_report_' . date('Y-m-d') . '.json'
        ];
    }

    private function exportToPdf(array $data, string $type): array {
        // Check if TCPDF is available
        if (!class_exists('TCPDF')) {
            // Fallback: generate a simple text-based PDF-like format
            return $this->exportToPdfFallback($data, $type);
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
        switch ($type) {
            case 'summary':
                $this->renderSummaryPdf($pdf, $data);
                break;
            case 'spending':
                $this->renderSpendingPdf($pdf, $data);
                break;
            case 'cashflow':
                $this->renderCashFlowPdf($pdf, $data);
                break;
            case 'income':
                $this->renderIncomePdf($pdf, $data);
                break;
        }

        return [
            'stream' => $pdf->Output('', 'S'),
            'contentType' => 'application/pdf',
            'filename' => $type . '_report_' . date('Y-m-d') . '.pdf'
        ];
    }

    private function exportToPdfFallback(array $data, string $type): array {
        // Simple fallback when TCPDF is not available
        // Return JSON as a workaround
        return $this->exportToJson($data, $type);
    }

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
            $monthLabel = $this->formatMonthLabel($month['month']);
            $pdf->Cell(35, 6, $monthLabel, 1, 0, 'L');
            $pdf->Cell(35, 6, $this->formatNumber($month['income']), 1, 0, 'R');
            $pdf->Cell(35, 6, $this->formatNumber($month['expenses']), 1, 0, 'R');
            $pdf->Cell(35, 6, $this->formatNumber($month['net']), 1, 0, 'R');
            $pdf->Cell(35, 6, $this->formatNumber($cumulative), 1, 1, 'R');
        }
    }

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

    private function formatNumber(float $value): string {
        return number_format($value, 2);
    }

    private function writeSummaryCsv($handle, array $data): void {
        // Write header
        fputcsv($handle, ['Type', 'Value']);

        // Write summary data
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
}