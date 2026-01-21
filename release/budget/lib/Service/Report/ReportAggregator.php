<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Report;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\CategoryMapper;

/**
 * Aggregates data to generate summary reports.
 */
class ReportAggregator {
    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;
    private CategoryMapper $categoryMapper;
    private ReportCalculator $calculator;

    public function __construct(
        AccountMapper $accountMapper,
        TransactionMapper $transactionMapper,
        CategoryMapper $categoryMapper,
        ReportCalculator $calculator
    ) {
        $this->accountMapper = $accountMapper;
        $this->transactionMapper = $transactionMapper;
        $this->categoryMapper = $categoryMapper;
        $this->calculator = $calculator;
    }

    /**
     * Generate a comprehensive financial summary.
     * OPTIMIZED: Uses single aggregated query instead of N+1 pattern.
     */
    public function generateSummary(
        string $userId,
        ?int $accountId,
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

        // Single aggregated query for all account summaries (replaces N+1 pattern)
        $accountSummaries = $this->transactionMapper->getAccountSummaries($userId, $startDate, $endDate);

        $totalIncome = 0;
        $totalExpenses = 0;

        foreach ($accounts as $account) {
            $accountId = $account->getId();
            $accountData = $accountSummaries[$accountId] ?? ['income' => 0, 'expenses' => 0, 'count' => 0];

            $accountIncome = $accountData['income'];
            $accountExpenses = $accountData['expenses'];

            $summary['accounts'][] = [
                'id' => $accountId,
                'name' => $account->getName(),
                'balance' => $account->getBalance(),
                'currency' => $account->getCurrency(),
                'income' => $accountIncome,
                'expenses' => $accountExpenses,
                'net' => $accountIncome - $accountExpenses,
                'transactionCount' => $accountData['count']
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

    /**
     * Generate summary with comparison to previous period.
     */
    public function generateSummaryWithComparison(
        string $userId,
        ?int $accountId,
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
                'income' => $this->calculator->calculatePercentChange(
                    $previous['totals']['totalIncome'] ?? 0,
                    $current['totals']['totalIncome'] ?? 0
                ),
                'expenses' => $this->calculator->calculatePercentChange(
                    $previous['totals']['totalExpenses'] ?? 0,
                    $current['totals']['totalExpenses'] ?? 0
                ),
                'netIncome' => $this->calculator->calculatePercentChange(
                    $previous['totals']['netIncome'] ?? 0,
                    $current['totals']['netIncome'] ?? 0
                )
            ],
            'previousTotals' => $previous['totals'] ?? []
        ];

        return $current;
    }

    /**
     * Generate budget report with category-by-category breakdown.
     * OPTIMIZED: Uses single batch query instead of N queries for N categories.
     */
    public function getBudgetReport(string $userId, string $startDate, string $endDate): array {
        $categories = $this->categoryMapper->findAll($userId);
        $budgetReport = [];
        $totals = [
            'budgeted' => 0,
            'spent' => 0,
            'remaining' => 0
        ];

        // Collect category IDs that have budgets
        $categoryIds = [];
        foreach ($categories as $category) {
            if ($category->getBudgetAmount() > 0) {
                $categoryIds[] = $category->getId();
            }
        }

        // Single batch query for all category spending (replaces N+1 pattern)
        $categorySpending = $this->transactionMapper->getCategorySpendingBatch($categoryIds, $startDate, $endDate);

        foreach ($categories as $category) {
            if ($category->getBudgetAmount() > 0) {
                $categoryId = $category->getId();
                $spent = $categorySpending[$categoryId] ?? 0;

                $budgeted = $category->getBudgetAmount();
                $remaining = $budgeted - $spent;
                $percentage = $budgeted > 0 ? ($spent / $budgeted) * 100 : 0;

                $budgetReport[] = [
                    'categoryId' => $categoryId,
                    'categoryName' => $category->getName(),
                    'budgeted' => $budgeted,
                    'spent' => $spent,
                    'remaining' => $remaining,
                    'percentage' => $percentage,
                    'status' => $this->calculator->getBudgetStatus($percentage),
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
            'overallStatus' => $this->calculator->getBudgetStatus(
                $totals['budgeted'] > 0 ? ($totals['spent'] / $totals['budgeted']) * 100 : 0
            )
        ];
    }

    /**
     * Generate cash flow report by month.
     */
    public function getCashFlowReport(
        string $userId,
        ?int $accountId,
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

    /**
     * Generate monthly trend data for charts.
     * OPTIMIZED: Uses single aggregated query instead of N×M queries (months × accounts).
     */
    public function generateTrendData(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate
    ): array {
        // Single query to get all monthly data at once
        $monthlyData = $this->transactionMapper->getMonthlyTrendData($userId, $accountId, $startDate, $endDate);

        // Index by month for quick lookup
        $dataByMonth = [];
        foreach ($monthlyData as $row) {
            $dataByMonth[$row['month']] = $row;
        }

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
            $month = $current->format('Y-m');
            $trends['labels'][] = $current->format('M Y');

            $monthData = $dataByMonth[$month] ?? ['income' => 0, 'expenses' => 0];
            $trends['income'][] = $monthData['income'];
            $trends['expenses'][] = $monthData['expenses'];

            $current->add($interval);
        }

        return $trends;
    }
}
