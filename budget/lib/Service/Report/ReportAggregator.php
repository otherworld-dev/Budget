<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Report;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\BudgetCarryoverService;
use OCA\Budget\Service\RecurringBudgetService;

/**
 * Aggregates data to generate summary reports.
 * Converts multi-currency accounts to the user's base currency for accurate totals.
 */
class ReportAggregator {
    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;
    private CategoryMapper $categoryMapper;
    private BudgetSnapshotMapper $budgetSnapshotMapper;
    private ReportCalculator $calculator;
    private CurrencyConversionService $conversionService;

    public function __construct(
        AccountMapper $accountMapper,
        TransactionMapper $transactionMapper,
        CategoryMapper $categoryMapper,
        BudgetSnapshotMapper $budgetSnapshotMapper,
        ReportCalculator $calculator,
        CurrencyConversionService $conversionService,
        private RecurringBudgetService $recurringBudgetService,
        private BudgetCarryoverService $carryoverService,
        private TransactionSplitMapper $splitMapper
    ) {
        $this->accountMapper = $accountMapper;
        $this->transactionMapper = $transactionMapper;
        $this->categoryMapper = $categoryMapper;
        $this->budgetSnapshotMapper = $budgetSnapshotMapper;
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
        bool $includeUntagged = true,
        array $visibleAccountIds = []
    ): array {
        if ($accountId) {
            $accounts = [$this->accountMapper->find($accountId, $userId)];
        } elseif (!empty($visibleAccountIds)) {
            $accounts = $this->accountMapper->findByIds($visibleAccountIds);
        } else {
            $accounts = $this->accountMapper->findAll($userId);
        }

        // In the "all accounts" summary, drop accounts the user flagged out of
        // reports (#286). When a specific account is explicitly selected we keep
        // it — the transaction aggregates are already filtered at the query layer.
        if ($accountId === null) {
            $accounts = array_values(array_filter($accounts, static fn($a) => !$a->getExcludedFromReports()));
        }

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

        // Single aggregated query for all account summaries (replaces N+1 pattern).
        // When a specific account is selected — even one flagged out of reports —
        // include it so its summary isn't blank (#309).
        $accountSummaries = $this->transactionMapper->getAccountSummaries(
            $userId,
            $startDate,
            $endDate,
            $tagIds,
            $includeUntagged,
            !empty($visibleAccountIds) ? $visibleAccountIds : null,
            $accountId !== null
        );

        // Build excluded category ID set (used for totals and spending breakdown)
        $allCategories = $this->categoryMapper->findAll($userId);
        $excludedCategoryIds = [];
        foreach ($allCategories as $cat) {
            if ($cat->getExcludedFromReports()) {
                $excludedCategoryIds[$cat->getId()] = true;
            }
        }

        // Get future transaction adjustments to calculate balance as of today
        $today = date('Y-m-d');
        $futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

        $totalIncome = 0;
        $totalExpenses = 0;
        $totalAssets = 0;
        $totalLiabilities = 0;
        $liabilityTypes = ['credit_card', 'loan', 'mortgage', 'line_of_credit'];

        foreach ($accounts as $account) {
            $currentAccountId = $account->getId();
            $accountData = $accountSummaries[$currentAccountId] ?? ['income' => 0, 'expenses' => 0, 'count' => 0];

            $accountIncome = $accountData['income'];
            $accountExpenses = $accountData['expenses'];

            // Calculate balance as of today (stored balance minus future transactions)
            $storedBalance = $account->getBalance();
            $futureChange = $futureChanges[$currentAccountId] ?? 0;
            $currentBalance = $storedBalance - $futureChange;

            $accountEntry = [
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
                    // Check if conversion is possible before attempting it
                    if (!$this->conversionService->canConvert($accountCurrency, $userId)) {
                        $unconvertedCurrencies[] = $accountCurrency;
                        $summary['accounts'][] = $accountEntry;
                        continue;
                    }

                    $currentBalance = $this->conversionService->convertToBaseFloat($currentBalance, $accountCurrency, $userId);
                    $accountIncome = $this->conversionService->convertToBaseFloat($accountIncome, $accountCurrency, $userId);
                    $accountExpenses = $this->conversionService->convertToBaseFloat($accountExpenses, $accountCurrency, $userId);

                    // Include fiat equivalent for frontend display
                    $accountEntry['convertedBalance'] = $currentBalance;
                    $accountEntry['baseCurrency'] = $baseCurrency;
                }
            }

            $summary['accounts'][] = $accountEntry;

            $summary['totals']['currentBalance'] += $currentBalance;
            if (in_array($account->getType(), $liabilityTypes, true)) {
                $totalLiabilities += $currentBalance; // Liability balances are already negative
            } else {
                $totalAssets += $currentBalance;
            }
            $totalIncome += $accountIncome;
            $totalExpenses += $accountExpenses;
        }

        // Exclude transfers from aggregate totals (all-accounts view only)
        // Transfers are zero-sum across accounts and should not inflate income/expenses
        if ($accountId === null) {
            if ($needsConversion) {
                // Per-account transfer totals so we can convert each account's transfers
                $transfersByAccount = $this->transactionMapper->getTransferTotalsByAccount(
                    $userId, $startDate, $endDate, $tagIds, $includeUntagged,
                    !empty($visibleAccountIds) ? $visibleAccountIds : null
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
                    $userId, $startDate, $endDate, $tagIds, $includeUntagged,
                    !empty($visibleAccountIds) ? $visibleAccountIds : null
                );
                $totalIncome -= $transferTotals['income'];
                $totalExpenses -= $transferTotals['expenses'];
            }
        }

        // Exclude transactions in excluded-from-reports categories from totals.
        // In all-accounts view, transfers were already subtracted above, so skip
        // all linked transfers here to avoid double-subtraction.
        if (!empty($excludedCategoryIds)) {
            $excludedIds = array_keys($excludedCategoryIds);
            $skipDeducted = ($accountId === null);
            $excludedExpenses = $this->transactionMapper->getCategorySpendingBatch($excludedIds, $startDate, $endDate, 'debit', null, $skipDeducted);
            $excludedIncome = $this->transactionMapper->getCategorySpendingBatch($excludedIds, $startDate, $endDate, 'credit', null, $skipDeducted);
            $totalExpenses -= array_sum($excludedExpenses);
            $totalIncome -= array_sum($excludedIncome);
        }

        $summary['totals']['totalIncome'] = $totalIncome;
        $summary['totals']['totalExpenses'] = $totalExpenses;
        $summary['totals']['netIncome'] = $totalIncome - $totalExpenses;
        $summary['totals']['totalAssets'] = round($totalAssets, 2);
        $summary['totals']['totalLiabilities'] = round(abs($totalLiabilities), 2);
        $summary['unconvertedCurrencies'] = array_values(array_unique($unconvertedCurrencies));

        $days = $summary['period']['days'];
        if ($days > 0) {
            $summary['totals']['averageDaily']['income'] = $totalIncome / $days;
            $summary['totals']['averageDaily']['expenses'] = $totalExpenses / $days;
        }

        $excludeTransfers = $accountId === null;

        // Get spending breakdown (filter out excluded categories)
        $spending = $this->transactionMapper->getSpendingSummary(
            $userId,
            $startDate,
            $endDate,
            $accountId,
            $tagIds,
            $includeUntagged,
            $excludeTransfers,
            !empty($visibleAccountIds) ? $visibleAccountIds : null
        );

        if (!empty($excludedCategoryIds)) {
            $spending = array_values(array_filter($spending, function ($item) use ($excludedCategoryIds) {
                return !isset($excludedCategoryIds[$item['categoryId'] ?? 0]);
            }));
        }
        $summary['spending'] = $spending;

        // Generate trend data (with currency conversion for multi-account view)
        $summary['trends'] = $this->generateTrendData($userId, $accountId, $startDate, $endDate, $tagIds, $includeUntagged, !empty($visibleAccountIds) ? $visibleAccountIds : null);

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
        bool $includeUntagged = true,
        array $visibleAccountIds = []
    ): array {
        // Current period
        $current = $this->generateSummary($userId, $accountId, $startDate, $endDate, $tagIds, $includeUntagged, $visibleAccountIds);

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
            $includeUntagged,
            $visibleAccountIds
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
    public function getBudgetReport(string $userId, string $startDate, string $endDate, ?int $accountId = null): array {
        $categories = $this->categoryMapper->findAll($userId);
        $budgetReport = [];
        $totals = [
            'budgeted' => 0,
            'spent' => 0,
            'remaining' => 0
        ];

        // Resolve effective budgets for the report month (snapshot-aware)
        $reportMonth = substr($startDate, 0, 7); // YYYY-MM from startDate
        $snapshotOverrides = $this->budgetSnapshotMapper->findEffectiveBatch($userId, $reportMonth);

        // Auto-derived recurring budgets (#269) apply to current/future months
        // only — they reflect today's bills and must not rewrite history.
        // Mirrors the Budget view's rule so both surfaces agree.
        $recurringBudgets = $reportMonth >= date('Y-m')
            ? $this->recurringBudgetService->getMonthlyBudgetsByCategory($userId)
            : [];

        // Envelope carryover is a monthly concept: apply it only when the
        // requested range is exactly one calendar month (the budget surfaces
        // always request whole months); arbitrary ranges get base budgets.
        $isSingleMonth = $startDate === $reportMonth . '-01'
            && $endDate === date('Y-m-t', strtotime($startDate));
        $carryovers = $isSingleMonth
            ? $this->carryoverService->getCarryovers($userId, $reportMonth, $categories)
            : [];

        // Collect category IDs that have budgets (considering snapshots and
        // envelope carryover, excluding excluded). A non-zero carryover keeps
        // the category in the report even when its base is 0 — a depleted
        // envelope must show as over budget, not vanish.
        $categoryIds = [];
        $resolvedBudgets = [];
        $resolvedBases = [];
        foreach ($categories as $category) {
            if ($category->getExcludedFromReports()) {
                continue;
            }
            $catId = $category->getId();
            $budgeted = isset($snapshotOverrides[$catId])
                ? (float) ($snapshotOverrides[$catId]['amount'] ?? 0)
                : (float) ($category->getBudgetAmount() ?? 0);
            if ($budgeted <= 0 && isset($recurringBudgets[$catId])) {
                $budgeted = $this->recurringBudgetService->convertMonthlyToPeriod(
                    (float) $recurringBudgets[$catId],
                    $category->getBudgetPeriod() ?? 'monthly'
                );
            }
            $carried = (float) ($carryovers[$catId] ?? 0);
            if ($budgeted > 0 || abs($carried) >= 0.005) {
                $categoryIds[] = $catId;
                $resolvedBases[$catId] = $budgeted;
                $resolvedBudgets[$catId] = round($budgeted + $carried, 2);
            }
        }

        // Single batch query for all category spending (replaces N+1 pattern)
        $categorySpending = $this->transactionMapper->getCategorySpendingBatch($categoryIds, $startDate, $endDate, 'debit', $accountId);

        foreach ($categories as $category) {
            $categoryId = $category->getId();
            if (isset($resolvedBudgets[$categoryId])) {
                $spent = $categorySpending[$categoryId] ?? 0;

                $budgeted = $resolvedBudgets[$categoryId];
                $remaining = $budgeted - $spent;
                // Depleted envelope (available <= 0): any spending is over budget
                $percentage = $budgeted > 0 ? ($spent / $budgeted) * 100 : ($spent > 0 ? 100 : 0);

                $budgetReport[] = [
                    'categoryId' => $categoryId,
                    'categoryName' => $category->getName(),
                    'budgeted' => $budgeted,
                    'baseBudget' => $resolvedBases[$categoryId],
                    'carried' => round($budgeted - $resolvedBases[$categoryId], 2),
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
        bool $includeUntagged = true,
        ?array $visibleAccountIds = null
    ): array {
        $excludeTransfers = $accountId === null;

        // Check if multi-currency conversion is needed
        if ($accountId === null) {
            $accounts = !empty($visibleAccountIds)
                ? $this->accountMapper->findByIds($visibleAccountIds)
                : $this->accountMapper->findAll($userId);
            // Excluded accounts contribute no transactions, so keep them out of
            // the currency-conversion setup too (#286)
            $accounts = array_values(array_filter($accounts, static fn($a) => !$a->getExcludedFromReports()));
            $needsConversion = $this->conversionService->needsConversion($accounts);

            if ($needsConversion) {
                $currencyMap = $this->conversionService->getAccountCurrencyMap($accounts);
                $cashFlow = $this->convertCashFlowByAccount(
                    $userId, $startDate, $endDate, $currencyMap, $tagIds, $includeUntagged, $excludeTransfers, $visibleAccountIds
                );
            } else {
                $cashFlow = $this->transactionMapper->getCashFlowByMonth(
                    $userId, $accountId, $startDate, $endDate, $tagIds, $includeUntagged, $excludeTransfers, $visibleAccountIds
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
        bool $excludeTransfers,
        ?array $visibleAccountIds = null
    ): array {
        $perAccountData = $this->transactionMapper->getCashFlowByMonthByAccount(
            $userId, $startDate, $endDate, $tagIds, $includeUntagged, $excludeTransfers, $visibleAccountIds
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
        bool $includeUntagged = true,
        ?array $visibleAccountIds = null
    ): array {
        $excludeTransfers = $accountId === null;

        // Check if multi-currency conversion is needed
        if ($accountId === null) {
            $accounts = !empty($visibleAccountIds)
                ? $this->accountMapper->findByIds($visibleAccountIds)
                : $this->accountMapper->findAll($userId);
            // Keep excluded accounts out of the conversion setup (#286)
            $accounts = array_values(array_filter($accounts, static fn($a) => !$a->getExcludedFromReports()));
            $needsConversion = $this->conversionService->needsConversion($accounts);

            if ($needsConversion) {
                $currencyMap = $this->conversionService->getAccountCurrencyMap($accounts);
                $dataByMonth = $this->convertTrendDataByAccount(
                    $userId, $startDate, $endDate, $currencyMap, $tagIds, $includeUntagged, $excludeTransfers, $visibleAccountIds
                );
            } else {
                $monthlyData = $this->transactionMapper->getMonthlyTrendData(
                    $userId, $accountId, $startDate, $endDate, $tagIds, $includeUntagged, $excludeTransfers, $visibleAccountIds
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
     * Category-by-month matrix report (#288). One row per category, ordered
     * alphabetically (or by total) with children indented under their parents;
     * columns are each calendar month in the range. Cell value is the signed net
     * for that category and month — income positive, expense negative — including
     * split allocations. Parent rows include the totals of all their descendants.
     * Accounts flagged out of reports (#286) are excluded.
     *
     * Amounts are summed in their stored currency; for a single-currency budget
     * this is exact. (Multi-currency conversion is not applied here.)
     *
     * @param string $sort 'alpha' (default) or 'total'
     * @param int[] $visibleAccountIds
     * @return array{period: array, sort: string, baseCurrency: string, rows: array, totals: array}
     */
    public function getCategoryMonthlyReport(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        string $sort = 'alpha',
        array $visibleAccountIds = []
    ): array {
        $sort = $sort === 'total' ? 'total' : 'alpha';
        $vis = !empty($visibleAccountIds) ? $visibleAccountIds : null;
        $months = $this->buildMonthList($startDate, $endDate);

        // Signed net per category per month: each category's OWN amounts (direct + splits)
        $direct = $this->transactionMapper->getCategoryNetByMonthBatch($userId, $startDate, $endDate, $accountId, $vis);
        $splits = $this->splitMapper->getCategoryNetByMonthBatch($userId, $startDate, $endDate, $accountId, $vis);
        $own = [];
        foreach ([$direct, $splits] as $src) {
            foreach ($src as $catId => $monthMap) {
                foreach ($monthMap as $m => $v) {
                    $own[(int) $catId][$m] = ($own[(int) $catId][$m] ?? 0.0) + (float) $v;
                }
            }
        }

        // Categories, honoring the category-level exclude-from-reports flag that
        // the other reports respect: excluded categories contribute nothing and
        // are not shown; any non-excluded children are promoted to the nearest
        // non-excluded ancestor (or to the root). Orphans (parent missing) are
        // treated as roots.
        $categories = $this->categoryMapper->findAll($userId);
        $excluded = [];
        $parentOf = [];
        foreach ($categories as $c) {
            $parentOf[$c->getId()] = $c->getParentId();
            if ($c->getExcludedFromReports()) {
                $excluded[$c->getId()] = true;
            }
        }
        // Drop excluded categories' own amounts so they affect neither rows nor totals
        foreach (array_keys($own) as $catId) {
            if (isset($excluded[$catId])) {
                unset($own[$catId]);
            }
        }
        // Walk up to the nearest non-excluded ancestor (null => becomes a root)
        $effectiveParent = function (?int $pid) use ($parentOf, $excluded): ?int {
            $guard = 0;
            while ($pid !== null && isset($excluded[$pid]) && $guard < 100) {
                $pid = $parentOf[$pid] ?? null;
                $guard++;
            }
            return $pid;
        };

        $byId = [];
        foreach ($categories as $c) {
            if (!isset($excluded[$c->getId()])) {
                $byId[$c->getId()] = $c;
            }
        }
        $childrenOf = [];
        $rootIds = [];
        foreach ($categories as $c) {
            if (isset($excluded[$c->getId()])) {
                continue;
            }
            $pid = $effectiveParent($c->getParentId());
            if ($pid !== null && isset($byId[$pid])) {
                $childrenOf[$pid][] = $c->getId();
            } else {
                $rootIds[] = $c->getId();
            }
        }

        // Roll each category's descendants up into it (post-order)
        $rolled = [];
        $rollup = function (int $catId) use (&$rollup, &$rolled, $own, $childrenOf): array {
            $acc = $own[$catId] ?? [];
            foreach ($childrenOf[$catId] ?? [] as $childId) {
                foreach ($rollup($childId) as $m => $v) {
                    $acc[$m] = ($acc[$m] ?? 0.0) + $v;
                }
            }
            $rolled[$catId] = $acc;
            return $acc;
        };
        foreach ($rootIds as $rid) {
            $rollup($rid);
        }

        // Emit rows depth-first: each node, then its (sorted) children
        $rows = [];
        $emit = function (int $catId, int $depth) use (&$emit, &$rows, $byId, $childrenOf, $rolled, $months, $sort): void {
            $c = $byId[$catId];
            $monthly = [];
            $total = 0.0;
            foreach ($months as $m) {
                $val = round($rolled[$catId][$m] ?? 0.0, 2);
                $monthly[$m] = $val;
                $total += $val;
            }
            $kids = $childrenOf[$catId] ?? [];
            $rows[] = [
                'categoryId' => $catId,
                'name' => $c->getName(),
                'type' => $c->getType(),
                'color' => $c->getColor(),
                'depth' => $depth,
                'isParent' => !empty($kids),
                'monthly' => $monthly,
                'total' => round($total, 2),
            ];
            foreach ($this->sortCategoryIds($kids, $byId, $rolled, $sort) as $kid) {
                $emit($kid, $depth + 1);
            }
        };
        foreach ($this->sortCategoryIds($rootIds, $byId, $rolled, $sort) as $rid) {
            $emit($rid, 0);
        }

        // Grand totals per month + overall: sum each category's OWN net (avoids
        // double-counting the rolled-up parent rows).
        $grandMonthly = [];
        $grandTotal = 0.0;
        foreach ($months as $m) {
            $s = 0.0;
            foreach ($own as $monthMap) {
                $s += $monthMap[$m] ?? 0.0;
            }
            $grandMonthly[$m] = round($s, 2);
            $grandTotal += $grandMonthly[$m];
        }

        // Amounts are summed in their stored currency (no conversion here); flag
        // when the user has accounts in more than one currency so the UI can warn.
        $reportAccounts = !empty($visibleAccountIds)
            ? $this->accountMapper->findByIds($visibleAccountIds)
            : $this->accountMapper->findAll($userId);
        $reportAccounts = array_values(array_filter($reportAccounts, static fn($a) => !$a->getExcludedFromReports()));
        $mixedCurrency = $this->conversionService->needsConversion($reportAccounts);

        return [
            'period' => ['startDate' => $startDate, 'endDate' => $endDate, 'months' => $months],
            'sort' => $sort,
            'baseCurrency' => $this->conversionService->getBaseCurrency($userId),
            'mixedCurrency' => $mixedCurrency,
            'rows' => $rows,
            'totals' => ['monthly' => $grandMonthly, 'total' => round($grandTotal, 2)],
        ];
    }

    /**
     * List of 'YYYY-MM' month keys from startDate to endDate inclusive.
     *
     * @return string[]
     */
    private function buildMonthList(string $startDate, string $endDate): array {
        $months = [];
        $cur = strtotime(substr($startDate, 0, 7) . '-01');
        $end = strtotime(substr($endDate, 0, 7) . '-01');
        // Guard against pathological ranges
        $guard = 0;
        while ($cur !== false && $cur <= $end && $guard < 600) {
            $months[] = date('Y-m', $cur);
            $cur = strtotime('+1 month', $cur);
            $guard++;
        }
        return $months;
    }

    /**
     * Sort category IDs alphabetically by name, or by absolute rolled-up total
     * (largest magnitude first) when $sort === 'total'.
     *
     * @param int[] $ids
     * @param array<int, \OCA\Budget\Db\Category> $byId
     * @param array<int, array<string, float>> $rolled
     * @return int[]
     */
    private function sortCategoryIds(array $ids, array $byId, array $rolled, string $sort): array {
        usort($ids, function (int $a, int $b) use ($byId, $rolled, $sort) {
            if ($sort === 'total') {
                $ta = array_sum($rolled[$a] ?? []);
                $tb = array_sum($rolled[$b] ?? []);
                $cmp = abs($tb) <=> abs($ta);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcasecmp($byId[$a]->getName(), $byId[$b]->getName());
        });
        return $ids;
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
        bool $excludeTransfers,
        ?array $visibleAccountIds = null
    ): array {
        $perAccountData = $this->transactionMapper->getMonthlyTrendDataByAccount(
            $userId, $startDate, $endDate, $tagIds, $includeUntagged, $excludeTransfers, $visibleAccountIds
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
        ?int $categoryId = null,
        ?array $visibleAccountIds = null
    ): array {
        if ($categoryId !== null) {
            // Single category
            $dimensions = $this->transactionMapper->getTagDimensionsForCategory(
                $userId,
                $categoryId,
                $startDate,
                $endDate,
                $accountId,
                $visibleAccountIds
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
        $spending = $this->transactionMapper->getSpendingSummary($userId, $startDate, $endDate, visibleAccountIds: $visibleAccountIds);
        $result = [];

        foreach ($spending as $categoryData) {
            $catId = (int)$categoryData['id'];
            $dimensions = $this->transactionMapper->getTagDimensionsForCategory(
                $userId,
                $catId,
                $startDate,
                $endDate,
                $accountId,
                $visibleAccountIds
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
