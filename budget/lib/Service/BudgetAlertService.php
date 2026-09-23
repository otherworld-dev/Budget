<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\TransactionMapper;
use OCP\Notification\IManager as INotificationManager;

class BudgetAlertService {
    private CategoryMapper $categoryMapper;
    private BudgetSnapshotMapper $budgetSnapshotMapper;
    private TransactionMapper $transactionMapper;
    private SettingService $settingService;

    // Alert thresholds
    private const WARNING_THRESHOLD = 0.80;  // 80%
    private const DANGER_THRESHOLD = 1.00;   // 100%
    // Spending must exceed the budget by more than this (half a cent) to count
    // as over budget — spending that exactly meets the budget is "fully used",
    // not exceeded (issue #293).
    private const OVER_BUDGET_EPSILON = 0.005;
    // categoryId => "<severity>:<periodStart>" of the last alert sent
    private const NOTIFIED_KEY = 'budget_alert_notified';
    // Which budgets may raise an alert (#389): everything, or only budgets the
    // user set themselves — leaving out the ones derived from bills and
    // recurring income.
    private const SCOPE_ALL = 'all';
    private const SCOPE_OWN_BUDGETS = 'manual';
    private const SCOPE_KEY = 'budget_alert_scope';
    // Category ids the user has silenced on the alerts tile, as a JSON list
    private const MUTED_KEY = 'budget_alert_muted_categories';

    public function __construct(
        CategoryMapper $categoryMapper,
        BudgetSnapshotMapper $budgetSnapshotMapper,
        TransactionMapper $transactionMapper,
        SettingService $settingService,
        private RecurringBudgetService $recurringBudgetService,
        private BudgetCarryoverService $carryoverService,
        private INotificationManager $notificationManager,
        private AmountFormatter $amountFormatter
    ) {
        $this->categoryMapper = $categoryMapper;
        $this->budgetSnapshotMapper = $budgetSnapshotMapper;
        $this->transactionMapper = $transactionMapper;
        $this->settingService = $settingService;
    }

    /**
     * Resolve a category's effective budget for "now": snapshot override,
     * else the category's own budget, else the auto-derived recurring budget
     * (#269) converted to the category's period — plus envelope carryover —
     * so alerts and budget status agree with what the Budget view shows.
     *
     * 'amount' is the spendable total (base + carried); 'carried' may be
     * negative (overspend pulled forward), so 'amount' can be <= 0 for a
     * depleted envelope — those must still alert, not vanish.
     *
     * 'source' says where the base came from: 'snapshot' or 'manual' for a
     * figure the user set, 'recurring' for the #269 fallback, 'none' for a
     * carryover-only envelope. The alerts scope filters on it (#389).
     *
     * @return array{amount: float, period: string, base: float, carried: float, source: string}
     */
    private function resolveEffectiveBudget($category, array $snapshotOverrides, array $recurringBudgets, array $carryovers = []): array {
        $catId = $category->getId();
        $period = isset($snapshotOverrides[$catId])
            ? ($snapshotOverrides[$catId]['period'] ?? 'monthly')
            : ($category->getBudgetPeriod() ?? 'monthly');
        $amount = isset($snapshotOverrides[$catId])
            ? (float) ($snapshotOverrides[$catId]['amount'] ?? 0)
            : (float) ($category->getBudgetAmount() ?? 0);
        $source = $amount > 0
            ? (isset($snapshotOverrides[$catId]) ? 'snapshot' : 'manual')
            : 'none';

        if ($amount <= 0 && isset($recurringBudgets[$catId])) {
            $amount = $this->recurringBudgetService->convertMonthlyToPeriod(
                (float) $recurringBudgets[$catId],
                $period
            );
            if ($amount > 0) {
                $source = 'recurring';
            }
        }

        $carried = (float) ($carryovers[$catId] ?? 0);

        return [
            'amount' => round($amount + $carried, 2),
            'period' => $period,
            'base' => $amount,
            'carried' => $carried,
            'source' => $source,
        ];
    }

    /**
     * Classify spending against a resolved budget amount.
     *
     * Spending that strictly exceeds a positive budget is 'danger' (over
     * budget); a depleted envelope (no positive budget remaining) with any
     * spending is likewise over budget. Spending that merely meets the budget
     * (100% used) is 'warning', not exceeded — a full budget hasn't been
     * overspent (issue #293). Spending at or above the warning threshold but
     * within budget is 'warning'; anything below it is 'ok'.
     *
     * @return array{percentage: float, severity: string} percentage is a ratio (1.0 = 100%)
     */
    private function classifySpending(float $spent, float $budget, float $warningThreshold = self::WARNING_THRESHOLD): array {
        if ($budget > 0) {
            $percentage = $spent / $budget;
            $overBudget = $spent > $budget + self::OVER_BUDGET_EPSILON;
        } else {
            // Depleted envelope: any spending is over the (zero/negative) budget.
            $overBudget = $spent > 0;
            $percentage = $overBudget ? self::DANGER_THRESHOLD : 0.0;
        }

        // At or above the threshold (but not over budget) = "approaching"
        // warning. A threshold of 100% collapses the warning band entirely, so a
        // fully-used budget stays 'ok' and only an actual overspend alerts (#293).
        if ($overBudget) {
            $severity = 'danger';
        } elseif ($warningThreshold < 1.0 && $percentage >= $warningThreshold) {
            $severity = 'warning';
        } else {
            $severity = 'ok';
        }

        return ['percentage' => $percentage, 'severity' => $severity];
    }

    /**
     * The budget-usage percentage (0–1) above which a category starts showing on
     * the alerts tile, from the budget_alert_threshold setting (default 80%).
     * Set to 100% to alert only when a category actually exceeds its budget.
     */
    private function getAlertThreshold(string $userId): float {
        $value = $this->settingService->get($userId, 'budget_alert_threshold');
        $pct = $value !== null ? (int) $value : 80;
        return max(1, min(100, $pct)) / 100;
    }

    /**
     * Which budgets the alerts tile covers: every budget in play (default), or
     * only the ones the user set themselves — leaving out budgets derived from
     * bills and recurring income (#269), which is what the tile showed before
     * that fallback existed (#389).
     */
    private function getAlertScope(string $userId): string {
        return $this->settingService->get($userId, self::SCOPE_KEY) === self::SCOPE_OWN_BUDGETS
            ? self::SCOPE_OWN_BUDGETS
            : self::SCOPE_ALL;
    }

    /**
     * Category ids the user has silenced on the alerts tile (#389), as a
     * lookup. The value is free-form JSON straight off the settings API, so
     * anything that isn't a list of ids means "nothing is muted" rather than
     * an error the dashboard can do nothing about.
     *
     * @return array<int, true>
     */
    private function getMutedCategoryIds(string $userId): array {
        $raw = $this->settingService->get($userId, self::MUTED_KEY);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $muted = [];
        foreach ($decoded as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $muted[(int) $id] = true;
            }
        }

        return $muted;
    }

    /**
     * Whether a category may raise an alert under the user's scope and muted
     * list (#389).
     *
     * Only the alerts path asks: getBudgetStatus() and getSummary() are the
     * budget totals the Budget view, the Nextcloud dashboard panel and the
     * digest add up, and silencing a tile row must not move a total.
     *
     * A carryover-only envelope has no budget source of its own — it holds
     * whatever a previous period left behind — so it keeps alerting either way.
     *
     * @param array{source: string} $resolved
     * @param array<int, true> $muted
     */
    private function mayAlert(int $categoryId, array $resolved, string $scope, array $muted): bool {
        if (isset($muted[$categoryId])) {
            return false;
        }

        return $scope !== self::SCOPE_OWN_BUDGETS || $resolved['source'] !== 'recurring';
    }

    /**
     * Get all budget alerts for a user.
     *
     * @return array Array of alerts with category info, spent, budget, percentage, and severity
     */
    public function getAlerts(string $userId, ?array $visibleAccountIds = null): array {
        $alerts = [];

        // Get all categories and resolve effective budgets for current month
        $categories = $this->categoryMapper->findAll($userId);
        $currentMonth = $this->currentBudgetMonth($userId);
        $snapshotOverrides = $this->budgetSnapshotMapper->findEffectiveBatch($userId, $currentMonth);
        $recurringBudgets = $this->recurringBudgetService->getMonthlyBudgetsByCategory($userId);
        $carryovers = $this->carryoverService->getCarryovers($userId, $currentMonth, $categories, $visibleAccountIds);
        $notBudgeted = BudgetScope::excludedCategoryIds($categories);
        $alertScope = $this->getAlertScope($userId);
        $mutedCategories = $this->getMutedCategoryIds($userId);

        // Categories with a budget in play: base > 0, or a non-zero envelope
        // carryover (a fully depleted envelope must still alert), minus the
        // ones the user's alert scope or muted list rules out (#389)
        $categoriesWithBudgets = [];
        $resolvedBudgets = [];
        $budgetedIds = [];
        foreach ($categories as $category) {
            if ($category->getExcludedFromReports() || isset($notBudgeted[$category->getId()])) {
                continue;
            }
            $resolved = $this->resolveEffectiveBudget($category, $snapshotOverrides, $recurringBudgets, $carryovers);
            if (!($resolved['base'] > 0 || abs($resolved['carried']) >= 0.005)) {
                continue;
            }
            // A muted budget is still a budget: it ends the parent's branch
            // all the same, or muting it would move its spending onto the
            // parent's alert
            $budgetedIds[$category->getId()] = true;
            if ($this->mayAlert($category->getId(), $resolved, $alertScope, $mutedCategories)) {
                $categoriesWithBudgets[] = $category;
                $resolvedBudgets[$category->getId()] = $resolved;
            }
        }

        if (empty($categoriesWithBudgets)) {
            return [];
        }
        $branches = BudgetScope::spendingBranches($categories, $budgetedIds);

        // Calculate date ranges for each period type
        $startDay = $this->getBudgetStartDay($userId);
        $periodRanges = $this->calculatePeriodRanges($startDay);
        $alertThreshold = $this->getAlertThreshold($userId);

        // Spending for every branch in its current period, one batch per period
        $branchSpending = $this->getBranchSpending($userId, $resolvedBudgets, $branches, $periodRanges, $visibleAccountIds);

        foreach ($categoriesWithBudgets as $category) {
            $resolved = $resolvedBudgets[$category->getId()];
            $period = $resolved['period'];
            $budget = $resolved['amount'];

            if (!isset($periodRanges[$period])) {
                continue;
            }

            $range = $periodRanges[$period];
            $spent = $branchSpending[$category->getId()] ?? 0.0;

            // Classify: exactly meeting the budget is "fully used", not over (#293).
            $classified = $this->classifySpending($spent, $budget, $alertThreshold);
            $percentage = $classified['percentage'];

            // Only create alert if at warning threshold or above
            if ($classified['severity'] !== 'ok') {
                $severity = $classified['severity'];

                $alerts[] = [
                    'categoryId' => $category->getId(),
                    'categoryName' => $category->getName(),
                    'categoryIcon' => $category->getIcon(),
                    'categoryColor' => $category->getColor(),
                    'budgetAmount' => $budget,
                    'budgetPeriod' => $period,
                    'carried' => round($resolved['carried'], 2),
                    'spent' => round($spent, 2),
                    'remaining' => round(max(0, $budget - $spent), 2),
                    'percentage' => round($percentage * 100, 1),
                    'severity' => $severity,
                    'periodStart' => $range['start'],
                    'periodEnd' => $range['end'],
                    'periodLabel' => $range['label'],
                ];
            }
        }

        // Sort by severity (danger first) then by percentage (highest first)
        usort($alerts, function($a, $b) {
            if ($a['severity'] !== $b['severity']) {
                return $a['severity'] === 'danger' ? -1 : 1;
            }
            return $b['percentage'] <=> $a['percentage'];
        });

        return $alerts;
    }

    /**
     * Notify about categories that have NEWLY crossed their warning or
     * danger threshold, honouring the same alerts the dashboard shows.
     *
     * Suppression is a high-water mark per category per budget period: one
     * notification at 'warning', a second only if the category goes on to
     * exceed the budget, and nothing more until the period rolls over. A
     * category that drops back under its threshold is forgotten, so it can
     * alert afresh if it climbs again.
     *
     * The user's `notification_budget_alert` opt-out is checked by the
     * caller (DigestJob), matching how anomaly alerts are gated.
     *
     * @return int notifications sent
     */
    public function notifyAlerts(string $userId): int {
        $alerts = $this->getAlerts($userId);
        $notified = $this->getNotifiedMap($userId);

        $current = [];
        $sent = 0;

        foreach ($alerts as $alert) {
            $categoryKey = (string) $alert['categoryId'];
            $rank = $alert['severity'] === 'danger' ? 2 : 1;
            $notifiedRank = $this->notifiedRank($notified[$categoryKey] ?? null, $alert['periodStart']);

            if ($rank > $notifiedRank) {
                $this->sendAlertNotification($userId, $alert);
                $sent++;
                $current[$categoryKey] = $alert['severity'] . ':' . $alert['periodStart'];
            } else {
                // Keep the stored high-water mark rather than lowering it
                $current[$categoryKey] = $notified[$categoryKey];
            }
        }

        if ($current !== $notified) {
            $this->settingService->set($userId, self::NOTIFIED_KEY, json_encode($current));
        }

        return $sent;
    }

    /**
     * Severity already notified for this category IN THIS PERIOD: 0 when
     * nothing was, or when the stored entry belongs to an earlier period.
     */
    private function notifiedRank(?string $stored, string $periodStart): int {
        if ($stored === null) {
            return 0;
        }

        [$severity, $period] = array_pad(explode(':', $stored, 2), 2, '');
        if ($period !== $periodStart) {
            return 0;
        }

        return match ($severity) {
            'danger' => 2,
            'warning' => 1,
            default => 0,
        };
    }

    private function sendAlertNotification(string $userId, array $alert): void {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp(Application::APP_ID)
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('budget_alert', (string) $alert['categoryId'])
            ->setSubject('budget_alert', [
                'categoryName' => $alert['categoryName'],
                'severity' => $alert['severity'],
                'percentage' => (string) $alert['percentage'],
                'spent' => $this->amountFormatter->formatForUser($userId, (float) $alert['spent']),
                'budget' => $this->amountFormatter->formatForUser($userId, (float) $alert['budgetAmount']),
            ]);
        $this->notificationManager->notify($notification);
    }

    /**
     * @return array<string, string> categoryId => "<severity>:<periodStart>"
     */
    private function getNotifiedMap(string $userId): array {
        try {
            $raw = $this->settingService->get($userId, self::NOTIFIED_KEY);
            $map = $raw !== null ? json_decode($raw, true) : null;
            return is_array($map) ? $map : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get budget status for all categories (not just alerts).
     *
     * @return array Array of all categories with budget status
     */
    public function getBudgetStatus(string $userId, ?array $visibleAccountIds = null): array {
        $statuses = [];

        $categories = $this->categoryMapper->findAll($userId);
        $currentMonth = $this->currentBudgetMonth($userId);
        $snapshotOverrides = $this->budgetSnapshotMapper->findEffectiveBatch($userId, $currentMonth);
        $recurringBudgets = $this->recurringBudgetService->getMonthlyBudgetsByCategory($userId);
        $carryovers = $this->carryoverService->getCarryovers($userId, $currentMonth, $categories, $visibleAccountIds);
        $notBudgeted = BudgetScope::excludedCategoryIds($categories);

        // Base budget > 0, or a non-zero envelope carryover (see getAlerts)
        $categoriesWithBudgets = [];
        $resolvedBudgets = [];
        $budgetedIds = [];
        foreach ($categories as $category) {
            if ($category->getExcludedFromReports() || isset($notBudgeted[$category->getId()])) {
                continue;
            }
            $resolved = $this->resolveEffectiveBudget($category, $snapshotOverrides, $recurringBudgets, $carryovers);
            if ($resolved['base'] > 0 || abs($resolved['carried']) >= 0.005) {
                $categoriesWithBudgets[] = $category;
                $resolvedBudgets[$category->getId()] = $resolved;
                $budgetedIds[$category->getId()] = true;
            }
        }

        if (empty($categoriesWithBudgets)) {
            return [];
        }
        $branches = BudgetScope::spendingBranches($categories, $budgetedIds);

        $startDay = $this->getBudgetStartDay($userId);
        $periodRanges = $this->calculatePeriodRanges($startDay);
        $alertThreshold = $this->getAlertThreshold($userId);

        // Spending for every branch in its current period, one batch per period
        $branchSpending = $this->getBranchSpending($userId, $resolvedBudgets, $branches, $periodRanges, $visibleAccountIds);

        foreach ($categoriesWithBudgets as $category) {
            $resolved = $resolvedBudgets[$category->getId()];
            $period = $resolved['period'];
            $budget = $resolved['amount'];

            if (!isset($periodRanges[$period])) {
                continue;
            }

            $range = $periodRanges[$period];
            $spent = $branchSpending[$category->getId()] ?? 0.0;

            // Classify: exactly meeting the budget is "fully used", not over (#293).
            $classified = $this->classifySpending($spent, $budget, $alertThreshold);
            $percentage = $classified['percentage'];
            $status = $classified['severity'];

            $statuses[] = [
                'categoryId' => $category->getId(),
                'categoryName' => $category->getName(),
                'categoryIcon' => $category->getIcon(),
                'categoryColor' => $category->getColor(),
                'budgetAmount' => $budget,
                'budgetPeriod' => $period,
                // Where the budget came from, so the alerts tile's category
                // picker can say which rows are derived from bills (#389)
                'budgetSource' => $resolved['source'],
                'carried' => round($resolved['carried'], 2),
                'spent' => round($spent, 2),
                'remaining' => round($budget - $spent, 2),
                'percentage' => round($percentage * 100, 1),
                'status' => $status,
                'periodLabel' => $range['label'],
            ];
        }

        // Sort by percentage descending
        usort($statuses, fn($a, $b) => $b['percentage'] <=> $a['percentage']);

        return $statuses;
    }

    /**
     * Get summary statistics for budget alerts.
     */
    public function getSummary(string $userId, ?array $visibleAccountIds = null): array {
        $statuses = $this->getBudgetStatus($userId, $visibleAccountIds);

        // Running totals through MoneyCalculator, never float += (#274)
        $budgetSum = '0';
        $spentSum = '0';
        $overBudgetCount = 0;
        $warningCount = 0;
        $onTrackCount = 0;

        foreach ($statuses as $s) {
            $budgetSum = MoneyCalculator::add($budgetSum, (float) $s['budgetAmount']);
            $spentSum = MoneyCalculator::add($spentSum, (float) $s['spent']);

            if ($s['status'] === 'danger') {
                $overBudgetCount++;
            } elseif ($s['status'] === 'warning') {
                $warningCount++;
            } else {
                $onTrackCount++;
            }
        }

        $totalBudget = MoneyCalculator::toFloat($budgetSum);
        $totalSpent = MoneyCalculator::toFloat($spentSum);

        return [
            'totalCategories' => count($statuses),
            'totalBudget' => $totalBudget,
            'totalSpent' => $totalSpent,
            'totalRemaining' => MoneyCalculator::toFloat(MoneyCalculator::subtract($budgetSum, $spentSum)),
            'overallPercentage' => $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100, 1) : 0,
            'overBudgetCount' => $overBudgetCount,
            'warningCount' => $warningCount,
            'onTrackCount' => $onTrackCount,
        ];
    }

    /**
     * Get the user's configured budget start day (1-31).
     */
    private function getBudgetStartDay(string $userId): int {
        $value = $this->settingService->get($userId, 'budget_start_day');
        $startDay = $value !== null ? (int) $value : 1;
        return max(1, min(31, $startDay));
    }

    /**
     * The budget month today falls in. With a custom start day the current
     * period can be named after last or next calendar month, and its
     * snapshot and carryover are that month's, as on the Budget page.
     */
    private function currentBudgetMonth(string $userId): string {
        return BudgetPeriod::monthContaining($this->getNow()->format('Y-m-d'), $this->getBudgetStartDay($userId));
    }

    /**
     * Get the current date. Overridable in tests.
     */
    protected function getNow(): \DateTime {
        return new \DateTime();
    }

    /**
     * Calculate period date ranges.
     */
    private function calculatePeriodRanges(int $startDay = 1): array {
        $now = $this->getNow();
        $ranges = [];

        // Monthly: custom start day support
        $monthlyRange = $this->calculateMonthlyRange($now, $startDay);
        $ranges['monthly'] = $monthlyRange;

        // Weekly: Monday to Sunday of current week
        $weekStart = clone $now;
        $weekStart->modify('monday this week');
        $weekEnd = clone $now;
        $weekEnd->modify('sunday this week');
        $ranges['weekly'] = [
            'start' => $weekStart->format('Y-m-d'),
            'end' => $weekEnd->format('Y-m-d'),
            'label' => 'Week of ' . $weekStart->format('M j'),
        ];

        // Quarterly: First day of quarter to last day of quarter
        $quarter = ceil((int)$now->format('n') / 3);
        $quarterStart = new \DateTime($now->format('Y') . '-' . (($quarter - 1) * 3 + 1) . '-01');
        $quarterEnd = clone $quarterStart;
        $quarterEnd->modify('+3 months -1 day');
        $ranges['quarterly'] = [
            'start' => $quarterStart->format('Y-m-d'),
            'end' => $quarterEnd->format('Y-m-d'),
            'label' => 'Q' . $quarter . ' ' . $now->format('Y'),
        ];

        // Yearly: Jan 1 to Dec 31
        $yearStart = new \DateTime($now->format('Y-01-01'));
        $yearEnd = new \DateTime($now->format('Y-12-31'));
        $ranges['yearly'] = [
            'start' => $yearStart->format('Y-m-d'),
            'end' => $yearEnd->format('Y-m-d'),
            'label' => $now->format('Y'),
        ];

        return $ranges;
    }

    /**
     * The monthly budget period containing $now, the same period the Budget
     * page shows for the current budget month (see BudgetPeriod).
     */
    private function calculateMonthlyRange(\DateTime $now, int $startDay): array {
        [$start, $end] = BudgetPeriod::range(
            BudgetPeriod::monthContaining($now->format('Y-m-d'), $startDay),
            $startDay
        );
        $label = $startDay === 1
            ? $now->format('F Y')
            : \DateTime::createFromFormat('!Y-m-d', $start)->format('M j')
                . ' – ' . \DateTime::createFromFormat('!Y-m-d', $end)->format('M j');

        return [
            'start' => $start,
            'end' => $end,
            'label' => $label,
        ];
    }

    /**
     * NET spending for each budget's branch in its current period: the
     * category and the subcategories BudgetScope::spendingBranches() puts
     * under it (#551).
     *
     * Direct transactions and split allocations, with money that came back
     * subtracted: a refunded purchase has not been spent. The figure comes
     * from TransactionMapper::getCategorySpendingBatch(), the one definition
     * of "net spent" the Budget page and the budget report use too (#360,
     * #361) — an alert that disagreed with the bar next to it would be worse
     * than no alert.
     *
     * One batch per distinct period covers every branch measured over it,
     * instead of two queries per category per branch member plus four split
     * queries per budget (#551's N+1).
     *
     * @param array<int, array{period: string, ...}> $resolvedBudgets categoryId => resolved budget
     * @param array<int, int[]> $branches
     * @param array<string, array{start: string, end: string}> $periodRanges
     * @param int[]|null $visibleAccountIds
     * @return array<int, float> categoryId => net spent over its branch
     */
    private function getBranchSpending(string $userId, array $resolvedBudgets, array $branches, array $periodRanges, ?array $visibleAccountIds): array {
        $membersByPeriod = [];
        foreach ($resolvedBudgets as $categoryId => $resolved) {
            if (!isset($periodRanges[$resolved['period']])) {
                continue;
            }
            foreach ($branches[$categoryId] ?? [$categoryId] as $memberId) {
                $membersByPeriod[$resolved['period']][$memberId] = true;
            }
        }

        $spendingByPeriod = [];
        foreach ($membersByPeriod as $period => $members) {
            $spendingByPeriod[$period] = $this->transactionMapper->getCategorySpendingBatch(
                array_keys($members),
                $periodRanges[$period]['start'],
                $periodRanges[$period]['end'],
                'debit',
                null,
                false,
                $userId,
                $visibleAccountIds
            );
        }

        $spent = [];
        foreach ($resolvedBudgets as $categoryId => $resolved) {
            $memberSpending = $spendingByPeriod[$resolved['period']] ?? [];
            $total = '0';
            foreach ($branches[$categoryId] ?? [$categoryId] as $memberId) {
                // Through MoneyCalculator, never float += (#274)
                $total = MoneyCalculator::add($total, (float) ($memberSpending[$memberId] ?? 0.0), 8);
            }
            $spent[$categoryId] = MoneyCalculator::toFloat($total);
        }

        return $spent;
    }
}
