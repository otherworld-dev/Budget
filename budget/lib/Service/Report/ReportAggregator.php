<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Report;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Service\CurrencyConversionService;

/**
 * Aggregates data to generate summary reports.
 * Converts multi-currency accounts to the user's base currency for accurate totals.
 */
class ReportAggregator {
    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;
    private CategoryMapper $categoryMapper;
    private ReportCalculator $calculator;
    private CurrencyConversionService $conversionService;

    public function __construct(
        AccountMapper $accountMapper,
        TransactionMapper $transactionMapper,
        CategoryMapper $categoryMapper,
        ReportCalculator $calculator,
        CurrencyConversionService $conversionService
    ) {
        $this->accountMapper = $accountMapper;
        $this->transactionMapper = $transactionMapper;
        $this->categoryMapper = $categoryMapper;
        $this->calculator = $calculator;
        $this->conversionService = $conversionService;
    }

    /**
     * Generate a comprehensive financial summary.
     * OPTIMIZED: Uses single aggregated query instead of N+1 pattern.
     * Multi-currency accounts are converted to the user's base currency for totals.
     * @param int[] $tagIds Optional tag filter (OR logic)
     * @param bool $includeUntagged Include untagged transactions when filtering by tags
     */
    public function generateSummary(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate,
        array $tagIds = [],
        bool $includeUntagged = true
    ): array {
        $accounts = $accountId
            ? [$this->accountMapper->find($accountId, $userId)]
            : $this->accountMapper->findAll($userId);

        $baseCurrency = $this->conversionService->getBaseCurrency($userId);
        $needsConversion = $accountId === null && $this->conversionService->needsConversion($accounts);
        $unconvertedCurrencies = [];

        // Build account → currency map for conversion
        $currencyMap = [];
        if ($needsConversion) {
            $currencyMap = $this->conversionService->getAccountCurrencyMap($accounts);
        }

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
            'trends' => [],
            'baseCurrency' => $baseCurrency,
            'currencyConverted' => $needsConversion,
            'unconvertedCurrencies' => []
        ];

        // Single aggregated query for all account summaries (replaces N+1 pattern)
        $accountSummaries = $this->transactionMapper->getAccountSummaries(
            $userId,
            $startDate,
            $endDate,
            $tagIds,
            $includeUntagged
        );

        // Get future transaction adjustments to calculate balance as of today
        $today = date('Y-m-d');
        $futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

        $totalIncome = 0;
        $totalExpenses = 0;

        foreach ($accounts as $account) {
            $currentAccountId = $account->getId();
            $accountData = $accountSummaries[$currentAccountId] ?? ['income' => 0, 'expenses' => 0, 'count' => 0];

            $accountIncome = $accountData['income'];
            $accountExpenses = $accountData['expenses'];

            // Calculate balance as of today (stored balance minus future transactions)
            $storedBalance = $account->getBalance();
            $futureChange = $futureChanges[$currentAccountId] ?? 0;
            $currentBalance = $storedBalance - $futureChange;

            $summary['accounts'][] = [
                'id' => $currentAccountId,
                'name' => $account->getName(),
                'balance' => $currentBalance,
                'currency' => $account->getCurrency(),
                'income' => $accountIncome,
                'expenses' => $accountExpenses,
                'net' => $accountIncome - $accountExpenses,
                'transactionCount' => $accountData['count']
            ];

            // Convert to base currency for aggregation if needed
            if ($needsConversion) {
                $accountCurrency = $account->getCurrency() ?: 'USD';
                if ($accountCurrency !== $baseCurrency) {
                    $convertedBalance = $this->conversionService->convertToBaseFloat($currentBalance, $accountCurrency, $userId);
                    $convertedIncome = $this->conversionService->convertToBaseFloat($accountIncome, $accountCurrency, $userId);
                    $convertedExpenses = $this->conversionService->convertToBaseFloat($accountExpenses, $accountCurrency, $userId);

                    // Detect if conversion failed (amount unchanged for non-zero value)
                    if ($currentBalance != 0 && (float)$convertedBalance === (float)$currentBalance) {
                        $unconvertedCurrencies[] = $accountCurrency;
                    }

                    $currentBalance = $convertedBalance;
                    $accountIncome = $convertedIncome;
                    $accountExpenses = $convertedExpenses;
                }
            }

            $summary['totals']['currentBalance'] += $currentBalance;
            $totalIncome += $accountIncome;
            $totalExpenses += $accountExpenses;
        }

        // Exclude transfers from aggregate totals (all-accounts view only)
        // Transfers are zero-sum across accounts and should not inflate income/expenses
        if ($accountId === null) {
            if ($needsConversion) {
                // Per-account transfer totals so we can convert each account's transfers
                $transfersByAccount = $this->transactionMapper->getTransferTotalsByAccount(
                    $userId, $startDate, $endDate, $tagIds, $includeUntagged
                );
                $transferIncome = 0;
                $transferExpenses = 0;
                foreach ($transfersByAccount as $accId => $transfers) {
                    $accCurrency = $currencyMap[$accId] ?? $baseCurrency;
                    $transferIncome += $this->conversionService->convertToBaseFloat($transfers['income'], $accCurrency, $userId);
                    $transferExpenses += $this->conversionService->convertToBaseFloat($transfers['expenses'], $accCurrency, $userId);
                }
                $totalIncome -= $transferIncome;
                $totalExpenses -= $transferExpenses;
            } else {
                $transferTotals = $this->transactionMapper->getTransferTotals(
                    $userId, $startDate, $endDate, $tagIds, $includeUntagged
                );
                $totalIncome -= $transferTotals['income'];
                $totalExpenses -= $transferTotals['expenses'];
            }
        }

        $summary['totals']['totalIncome'] = $totalIncome;
        $summary['totals']['totalExpenses'] = $totalExpenses;
        $summary['totals']['netIncome'] = $totalIncome - $totalExpenses;
        $summary['unconvertedCurrencies'] = array_values(array_unique($unconvertedCurrencies));

        $days = $summary['period']['days'];
        if ($days > 0) {
            $summary['totals']['averageDaily']['income'] = $totalIncome / $days;
            $summary['totals']['averageDaily']['expenses'] = $totalExpenses / $days;
        }

        $excludeTransfers = $accountId === null;

        // Get spending breakdown
        $summary['spending'] = $this->transactionMapper->getSpendingSummary(
            $userId,
            $startDate,
            $endDate,
            $accountId,
            $tagIds,
            $includeUntagged,
            $excludeTransfers
        );

        // Generate trend data (with currency conversion for multi-account view)
        $summary['trends'] = $this->generateTrendData($userId, $accountId, $startDate, $endDate, $tagIds, $includeUntagged);

        return $summary;
    }

    /**
     * Generate summary with comparison to previous period.
     * @param int[] $tagIds Optional tag filter (OR logic)
     * @param bool $includeUntagged Include untagged transactions when filtering by tags
     */
    public function generateSummaryWithComparison(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate,
        array $tagIds = [],
        bool $includeUntagged = true
    ): array {
        // Current period
        $current = $this->generateSummary($userId, $accountId, $startDate, $endDate, $tagIds, $includeUntagged);

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
            $prevEnd->format('Y-m-d'),
            $tagIds,
            $includeUntagged
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
     * Multi-currency accounts are converted to base currency in all-accounts view.
     * @param int[] $tagIds Optional tag filter (OR logic)
     * @param bool $includeUntagged Include untagged transactions when filtering by tags
     */
    public function getCashFlowReport(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate,
        array $tagIds = [],
        bool $includeUntagged = true
    ): array {
        $excludeTransfers = $accountId === null;

        // Check if multi-currency conversion is needed
        if ($accountId === null) {
            $accounts = $this->accountMapper->findAll($userId);
            $needsConversion = $this->conversionService->needsConversion($accounts);

            if ($needsConversion) {
                $currencyMap = $this->conversionService->getAccountCurrencyMap($accounts);
                $cashFlow = $this->convertCashFlowByAccount(
                    $userId, $startDate, $endDate, $currencyMap, $tagIds, $includeUntagged, $excludeTransfers
                );
            } else {
                $cashFlow = $this->transactionMapper->getCashFlowByMonth(
                    $userId, $accountId, $startDate, $endDate, $tagIds, $includeUntagged, $excludeTransfers
                );
            }
        } else {
            $cashFlow = $this->transactionMapper->getCashFlowByMonth(
                $userId, $accountId, $startDate, $endDate, $tagIds, $includeUntagged, $excludeTransfers
            );
        }

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
     * Convert per-account-per-month cash flow data to base currency and aggregate by month.
     *
     * @param array<int, string> $currencyMap accountId → currency code
     */
    private function convertCashFlowByAccount(
        string $userId,
        string $startDate,
        string $endDate,
        array $currencyMap,
        array $tagIds,
        bool $includeUntagged,
        bool $excludeTransfers
    ): array {
        $perAccountData = $this->transactionMapper->getCashFlowByMonthByAccount(
            $userId, $startDate, $endDate, $tagIds, $includeUntagged, $excludeTransfers
        );

        $baseCurrency = $this->conversionService->getBaseCurrency($userId);
        $byMonth = [];

        foreach ($perAccountData as $row) {
            $month = $row['month'];
            $accCurrency = $currencyMap[$row['account_id']] ?? $baseCurrency;

            $income = $this->conversionService->convertToBaseFloat($row['income'], $accCurrency, $userId);
            $expenses = $this->conversionService->convertToBaseFloat($row['expenses'], $accCurrency, $userId);

            if (!isset($byMonth[$month])) {
                $byMonth[$month] = ['month' => $month, 'income' => 0, 'expenses' => 0, 'net' => 0];
            }
            $byMonth[$month]['income'] += $income;
            $byMonth[$month]['expenses'] += $expenses;
        }

        // Recalculate net after aggregation
        foreach ($byMonth as &$monthData) {
            $monthData['net'] = $monthData['income'] - $monthData['expenses'];
        }
        unset($monthData);

        ksort($byMonth);
        return array_values($byMonth);
    }

    /**
     * Generate monthly trend data for charts.
     * Multi-currency accounts are converted to base currency in all-accounts view.
     * @param int[] $tagIds Optional tag filter (OR logic)
     * @param bool $includeUntagged Include untagged transactions when filtering by tags
     */
    public function generateTrendData(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate,
        array $tagIds = [],
        bool $includeUntagged = true
    ): array {
        $excludeTransfers = $accountId === null;

        // Check if multi-currency conversion is needed
        if ($accountId === null) {
            $accounts = $this->accountMapper->findAll($userId);
            $needsConversion = $this->conversionService->needsConversion($accounts);

            if ($needsConversion) {
                $currencyMap = $this->conversionService->getAccountCurrencyMap($accounts);
                $dataByMonth = $this->convertTrendDataByAccount(
                    $userId, $startDate, $endDate, $currencyMap, $tagIds, $includeUntagged, $excludeTransfers
                );
            } else {
                $monthlyData = $this->transactionMapper->getMonthlyTrendData(
                    $userId, $accountId, $startDate, $endDate, $tagIds, $includeUntagged, $excludeTransfers
                );
                $dataByMonth = [];
                foreach ($monthlyData as $row) {
                    $dataByMonth[$row['month']] = $row;
                }
            }
        } else {
            $monthlyData = $this->transactionMapper->getMonthlyTrendData(
                $userId, $accountId, $startDate, $endDate, $tagIds, $includeUntagged, false
            );
            $dataByMonth = [];
            foreach ($monthlyData as $row) {
                $dataByMonth[$row['month']] = $row;
            }
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

    /**
     * Convert per-account-per-month trend data to base currency and aggregate by month.
     *
     * @param array<int, string> $currencyMap accountId → currency code
     * @return array<string, array{income: float, expenses: float}> month → totals
     */
    private function convertTrendDataByAccount(
        string $userId,
        string $startDate,
        string $endDate,
        array $currencyMap,
        array $tagIds,
        bool $includeUntagged,
        bool $excludeTransfers
    ): array {
        $perAccountData = $this->transactionMapper->getMonthlyTrendDataByAccount(
            $userId, $startDate, $endDate, $tagIds, $includeUntagged, $excludeTransfers
        );

        $baseCurrency = $this->conversionService->getBaseCurrency($userId);
        $byMonth = [];

        foreach ($perAccountData as $row) {
            $month = $row['month'];
            $accCurrency = $currencyMap[$row['account_id']] ?? $baseCurrency;

            $income = $this->conversionService->convertToBaseFloat($row['income'], $accCurrency, $userId);
            $expenses = $this->conversionService->convertToBaseFloat($row['expenses'], $accCurrency, $userId);

            if (!isset($byMonth[$month])) {
                $byMonth[$month] = ['income' => 0, 'expenses' => 0];
            }
            $byMonth[$month]['income'] += $income;
            $byMonth[$month]['expenses'] += $expenses;
        }

        return $byMonth;
    }

    /**
     * Get tag dimensions for spending across categories.
     * Returns tag breakdown for each category that has tag sets.
     *
     * @param string $userId
     * @param string $startDate
     * @param string $endDate
     * @param int|null $accountId Optional account filter
     * @param int|null $categoryId Optional single category filter
     * @return array Array of category data with tag dimensions
     */
    public function getTagDimensions(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        ?int $categoryId = null
    ): array {
        if ($categoryId !== null) {
            // Single category
            $dimensions = $this->transactionMapper->getTagDimensionsForCategory(
                $userId,
                $categoryId,
                $startDate,
                $endDate,
                $accountId
            );

            $category = $this->categoryMapper->find($categoryId, $userId);

            return [
                'categories' => [[
                    'categoryId' => $categoryId,
                    'categoryName' => $category->getName(),
                    'categoryColor' => $category->getColor(),
                    'tagDimensions' => $dimensions
                ]]
            ];
        }

        // All categories with spending
        $spending = $this->transactionMapper->getSpendingSummary($userId, $startDate, $endDate);
        $result = [];

        foreach ($spending as $categoryData) {
            $catId = (int)$categoryData['id'];
            $dimensions = $this->transactionMapper->getTagDimensionsForCategory(
                $userId,
                $catId,
                $startDate,
                $endDate,
                $accountId
            );

            if (!empty($dimensions)) {
                $result[] = [
                    'categoryId' => $catId,
                    'categoryName' => $categoryData['name'],
                    'categoryColor' => $categoryData['color'],
                    'categoryTotal' => (float)$categoryData['total'],
                    'tagDimensions' => $dimensions
                ];
            }
        }

        return ['categories' => $result];
    }
}
