<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;

/**
 * Envelope-budget carryover: how much unspent (or overspent) budget a
 * category carries INTO a given month.
 *
 * The carried amount is always derived at read time from budgets and
 * spending — never stored or incremented (#274 lesson): editing a
 * transaction in a past month changes every downstream month's carryover
 * automatically, with nothing to invalidate.
 *
 * Chain recurrence, month by month from the category's anchor
 * (rollover_start, set when the flag was enabled):
 *
 *   carry(m+1) = base(m) + carry(m) − spent(m)   if base(m) > 0 or carry(m) ≠ 0
 *   carry(m+1) = 0                               otherwise (inactive month)
 *
 * Past months use the manual/snapshot base only — matching what those
 * months displayed (#269: the auto-derived fallback never applies to
 * history). Current and future months include the recurring fallback, so
 * a future month's carry is a projection. Negative carry (overspend)
 * flows through unclamped.
 *
 * v1 scope: monthly-period expense categories only.
 */
class BudgetCarryoverService {

    /** Hard cap on chain length — keeps multi-year anchors bounded */
    private const MAX_CHAIN_MONTHS = 60;

    public function __construct(
        private CategoryMapper $categoryMapper,
        private BudgetSnapshotMapper $budgetSnapshotMapper,
        private TransactionMapper $transactionMapper,
        private TransactionSplitMapper $splitMapper,
        private RecurringBudgetService $recurringBudgetService,
        private SettingService $settingService,
    ) {
    }

    /**
     * Carryover into $targetMonth (YYYY-MM) per rollover-enabled category.
     * Categories without rollover (or out of v1 scope) are simply absent —
     * callers treat missing keys as 0.
     *
     * @param Category[]|null $categories pass when already loaded (saves a query)
     * @return array<int, float> categoryId => carried amount
     */
    public function getCarryovers(string $userId, string $targetMonth, ?array $categories = null): array {
        $categories ??= $this->categoryMapper->findAll($userId);

        $eligible = [];
        foreach ($categories as $category) {
            if ($this->isRolloverEligible($category, $targetMonth)) {
                $eligible[$category->getId()] = $category;
            }
        }
        if (empty($eligible)) {
            return [];
        }

        // Chain start: earliest anchor across eligible categories, capped
        $chainStart = null;
        foreach ($eligible as $category) {
            $anchor = $category->getRolloverStart();
            if ($chainStart === null || $anchor < $chainStart) {
                $chainStart = $anchor;
            }
        }
        $months = $this->monthList($chainStart, $targetMonth);
        if (empty($months)) {
            return array_fill_keys(array_keys($eligible), 0.0);
        }

        $currentMonth = $this->getCurrentMonth();
        $startDay = $this->getBudgetStartDay($userId);

        // Spending per category per chain month (direct + splits), batched
        $spentByMonth = $this->loadSpending($userId, array_keys($eligible), $months, $startDay);

        // Snapshot bases: all snapshots up to the last chain month, folded
        // per category into a sorted (effectiveFrom => [amount, period]) list
        $snapshotsByCategory = $this->loadSnapshots($userId, end($months));

        // Recurring fallback applies to current/future chain months only
        $recurring = null; // lazy — most chains are entirely in the past

        $carryovers = [];
        foreach ($eligible as $catId => $category) {
            $anchor = $category->getRolloverStart();
            $carry = 0.0;

            foreach ($months as $month) {
                if ($month < $anchor) {
                    continue;
                }

                $base = $this->resolveBase($category, $snapshotsByCategory[$catId] ?? [], $month);
                if ($base <= 0 && $month >= $currentMonth) {
                    if ($recurring === null) {
                        $recurring = $this->recurringBudgetService->getMonthlyBudgetsByCategory($userId);
                    }
                    $base = (float) ($recurring[$catId] ?? 0);
                }

                if ($base > 0 || abs($carry) >= 0.005) {
                    $spent = $spentByMonth[$catId][$month] ?? 0.0;
                    $carry = round($base + $carry - $spent, 2);
                } else {
                    $carry = 0.0;
                }
            }

            $carryovers[$catId] = $carry;
        }

        return $carryovers;
    }

    /**
     * Whether rollover applies to this category at all (v1: monthly-period
     * expense categories with the flag and an anchor before the target).
     */
    public function isRolloverEligible(Category $category, string $targetMonth): bool {
        return ($category->getBudgetRollover() ?? false)
            && $category->getRolloverStart() !== null
            && $category->getRolloverStart() < $targetMonth
            && ($category->getBudgetPeriod() ?? 'monthly') === 'monthly'
            && $category->getType() === 'expense'
            && !($category->getExcludedFromReports() ?? false);
    }

    /**
     * Chain months: from $from (inclusive) through the month BEFORE $target,
     * capped at MAX_CHAIN_MONTHS counting back from the target.
     *
     * @return string[] YYYY-MM ascending
     */
    private function monthList(string $from, string $target): array {
        $start = \DateTime::createFromFormat('Y-m-d', $from . '-01');
        $end = \DateTime::createFromFormat('Y-m-d', $target . '-01');
        if ($start === false || $end === false || $start >= $end) {
            return [];
        }

        $floor = (clone $end)->modify('-' . self::MAX_CHAIN_MONTHS . ' months');
        if ($start < $floor) {
            $start = $floor;
        }

        $months = [];
        $cursor = clone $start;
        while ($cursor < $end) {
            $months[] = $cursor->format('Y-m');
            $cursor->modify('first day of next month');
        }
        return $months;
    }

    /**
     * Manual/snapshot base budget for a chain month. A snapshot or category
     * period other than monthly puts the month out of scope (base 0).
     */
    private function resolveBase(Category $category, array $snapshots, string $month): float {
        // Most recent snapshot with effectiveFrom <= month
        $picked = null;
        foreach ($snapshots as $effectiveFrom => $snapshot) {
            if ($effectiveFrom > $month) {
                break;
            }
            $picked = $snapshot;
        }

        if ($picked !== null) {
            if (($picked['period'] ?? 'monthly') !== 'monthly') {
                return 0.0;
            }
            return (float) ($picked['amount'] ?? 0);
        }

        return (float) ($category->getBudgetAmount() ?? 0);
    }

    /**
     * @return array<int, array<string, array{amount: float|null, period: string}>>
     *         categoryId => effectiveFrom => snapshot, ascending by effectiveFrom
     */
    private function loadSnapshots(string $userId, string $lastMonth): array {
        $byCategory = [];
        foreach ($this->budgetSnapshotMapper->findAll($userId) as $snapshot) {
            if ($snapshot->getEffectiveFrom() > $lastMonth) {
                continue;
            }
            $byCategory[$snapshot->getCategoryId()][$snapshot->getEffectiveFrom()] = [
                'amount' => $snapshot->getAmount(),
                'period' => $snapshot->getPeriod() ?? 'monthly',
            ];
        }
        foreach ($byCategory as &$snapshots) {
            ksort($snapshots);
        }
        return $byCategory;
    }

    /**
     * Spending (direct + split allocations) per category per chain month.
     * With the default start day this is two month-grouped queries; with a
     * custom start day the rows come back per-day and are folded into the
     * shifted period of each chain month.
     *
     * @param string[] $months ascending chain months
     * @return array<int, array<string, float>> categoryId => month => spent
     */
    private function loadSpending(string $userId, array $categoryIds, array $months, int $startDay): array {
        $firstMonth = $months[0];
        $lastMonth = end($months);

        if ($startDay === 1) {
            $startDate = $firstMonth . '-01';
            $endDate = date('Y-m-t', strtotime($lastMonth . '-01'));

            $direct = $this->transactionMapper->getCategorySpendingByBucketBatch($userId, $startDate, $endDate);
            $splits = $this->splitMapper->getCategoryTotalsByBucket($userId, $startDate, $endDate);

            return $this->mergeSpending($categoryIds, $direct, $splits);
        }

        // Custom start day: budget month m spans [startDay of m, startDay of m+1)
        $ranges = [];
        foreach ($months as $month) {
            $ranges[$month] = $this->periodRange($month, $startDay);
        }
        $startDate = $ranges[$firstMonth][0];
        $endDate = $ranges[$lastMonth][1];

        $direct = $this->transactionMapper->getCategorySpendingByBucketBatch($userId, $startDate, $endDate, true);
        $splits = $this->splitMapper->getCategoryTotalsByBucket($userId, $startDate, $endDate, true);

        $spent = [];
        foreach ([$direct, $splits] as $source) {
            foreach ($source as $catId => $byDay) {
                if (!in_array($catId, $categoryIds, true)) {
                    continue;
                }
                foreach ($byDay as $day => $amount) {
                    foreach ($ranges as $month => [$rangeStart, $rangeEnd]) {
                        if ($day >= $rangeStart && $day <= $rangeEnd) {
                            $spent[$catId][$month] = round(($spent[$catId][$month] ?? 0) + $amount, 2);
                            break;
                        }
                    }
                }
            }
        }
        return $spent;
    }

    /**
     * @return array<int, array<string, float>>
     */
    private function mergeSpending(array $categoryIds, array $direct, array $splits): array {
        $spent = [];
        foreach ([$direct, $splits] as $source) {
            foreach ($source as $catId => $byMonth) {
                if (!in_array($catId, $categoryIds, true)) {
                    continue;
                }
                foreach ($byMonth as $month => $amount) {
                    $spent[$catId][$month] = round(($spent[$catId][$month] ?? 0) + $amount, 2);
                }
            }
        }
        return $spent;
    }

    /**
     * Period range [start, end] (Y-m-d) of budget month $month with a custom
     * start day, clamped to each month's length — mirrors
     * BudgetAlertService::calculateMonthlyRange's bounds.
     *
     * @return array{0: string, 1: string}
     */
    private function periodRange(string $month, int $startDay): array {
        $monthStart = \DateTime::createFromFormat('Y-m-d', $month . '-01');
        $daysInMonth = (int) $monthStart->format('t');
        $start = sprintf('%s-%02d', $month, min($startDay, $daysInMonth));

        $next = (clone $monthStart)->modify('first day of next month');
        $daysInNext = (int) $next->format('t');
        $nextStart = (clone $next)->setDate(
            (int) $next->format('Y'),
            (int) $next->format('n'),
            min($startDay, $daysInNext)
        );
        $end = $nextStart->modify('-1 day')->format('Y-m-d');

        return [$start, $end];
    }

    private function getBudgetStartDay(string $userId): int {
        $value = $this->settingService->get($userId, 'budget_start_day');
        $startDay = $value !== null ? (int) $value : 1;
        return max(1, min(31, $startDay));
    }

    /**
     * Overridable in tests.
     */
    protected function getCurrentMonth(): string {
        return date('Y-m');
    }
}
