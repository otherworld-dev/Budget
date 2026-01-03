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
                $data = $this->generateSummary($userId, $accountId, $startDate, $endDate);
                break;
            case 'spending':
                $data = $this->getSpendingReport($userId, $accountId, $startDate, $endDate);
                break;
            case 'income':
                $data = $this->getIncomeReport($userId, $accountId, $startDate, $endDate);
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
        // Implementation would query transactions grouped by month
        return [];
    }

    private function getSpendingByVendor(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        // Implementation would query transactions grouped by vendor
        return [];
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
        // Similar to spending by category but for income transactions
        return [];
    }

    private function getIncomeByMonth(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        // Implementation would query income transactions grouped by month
        return [];
    }

    private function getIncomeBySource(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        // Implementation would query income transactions grouped by source/vendor
        return [];
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
            // Add other types as needed
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
        // PDF export would require a PDF library like TCPDF or FPDF
        // For now, return a placeholder
        return [
            'stream' => '',
            'contentType' => 'application/pdf',
            'filename' => $type . '_report_' . date('Y-m-d') . '.pdf'
        ];
    }

    private function writeSummaryCsv($handle, array $data): void {
        // Write header
        fputcsv($handle, ['Type', 'Value']);
        
        // Write summary data
        fputcsv($handle, ['Total Income', $data['totals']['totalIncome']]);
        fputcsv($handle, ['Total Expenses', $data['totals']['totalExpenses']]);
        fputcsv($handle, ['Net Income', $data['totals']['netIncome']]);
        fputcsv($handle, ['Current Balance', $data['totals']['currentBalance']]);
        
        // Write account details
        fputcsv($handle, ['']);
        fputcsv($handle, ['Account', 'Balance', 'Income', 'Expenses', 'Net']);
        
        foreach ($data['accounts'] as $account) {
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
        fputcsv($handle, ['Category', 'Amount', 'Transactions', 'Average']);
        
        foreach ($data['data'] as $item) {
            fputcsv($handle, [
                $item['name'],
                $item['total'],
                $item['count'],
                $item['total'] / $item['count']
            ]);
        }
    }
}