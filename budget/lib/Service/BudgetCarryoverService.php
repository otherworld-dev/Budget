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
 * An envelope covers a whole BRANCH: the category plus every descendant,
 * because the Budget view has always shown a parent row as its own figures
 * plus its children's. Keyed on the category's own id alone, the chain saw
 * none of the spending filed under subcategories — budget the parent, file
 * under the children, and the untouched base carried forward every month
 * while the same row read "spent 30 of 100" (#341). Descending stops at a
 * descendant running its own envelope that month: it keeps its own chain, and
 * counting it twice would inflate the parent's.
 *
 * Chain recurrence, month by month from the category's anchor
 * (rollover_start, set when the flag was enabled), over the branch it owns:
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

    /** Guard against a parent cycle in corrupt data, as in BudgetScope */
    private const MAX_DEPTH = 64;

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
     * @param int[]|null $visibleAccountIds accounts in scope (own + shared);
     *        null falls back to the user's own accounts only
     * @return array<int, float> categoryId => carried amount
     */
    public function getCarryovers(string $userId, string $targetMonth, ?array $categories = null, ?array $visibleAccountIds = null): array {
        $categories ??= $this->categoryMapper->findAll($userId);

        // A branch the user doesn't budget against has no envelope to carry
        $notBudgeted = BudgetScope::excludedCategoryIds($categories);

        $eligible = [];
        $parents = [];
        $byId = [];
        foreach ($categories as $category) {
            $catId = $category->getId();
            $byId[$catId] = $category;
            $parents[$catId] = $category->getParentId();
            if (isset($notBudgeted[$catId])) {
                continue;
            }
            if ($this->isRolloverEligible($category, $targetMonth)) {
                $eligible[$catId] = $category;
            }
        }
        if (empty($eligible)) {
            return [];
        }

        // What may join a branch's envelope. Type and period are the chain's
        // own v1 scope, applied to members as well as owners: a quarterly or
        // yearly subcategory budget is an amount measured over a different
        // span, and folding it into a monthly chain would add it again every
        // month; an income subcategory holds a target, not a budget to spend
        // against. The reports flag is handled on the way up in
        // envelopeOwner() so it takes a whole subtree with it.
        $contributors = [];
        foreach ($categories as $category) {
            $catId = $category->getId();
            if (isset($notBudgeted[$catId])) {
                continue;
            }
            if ($category->getType() !== 'expense') {
                continue;
            }
            if (($category->getBudgetPeriod() ?? 'monthly') !== 'monthly') {
                continue;
            }
            $contributors[$catId] = $category;
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

        // Spending per category per chain month (direct + splits), batched.
        // Every contributor, not just the envelope categories: a branch's
        // spending usually sits on its subcategories (#341).
        $spentByMonth = $this->loadSpending($userId, array_keys($contributors), $months, $startDay, $visibleAccountIds);

        // Snapshot bases: all snapshots up to the last chain month, folded
        // per category into a sorted (effectiveFrom => [amount, period]) list
        $snapshotsByCategory = $this->loadSnapshots($userId, end($months));

        // Recurring fallback applies to current/future chain months only
        $recurring = null; // lazy — most chains are entirely in the past

        // Month-major, because which envelope owns a branch member can change
        // from month to month — a subcategory that switches its own envelope on
        // stops counting towards its parent's from that month.
        $carryovers = array_fill_keys(array_keys($eligible), 0.0);

        foreach ($months as $month) {
            $branchBase = [];
            $branchSpent = [];

            foreach ($contributors as $catId => $category) {
                $ownerId = $this->envelopeOwner($catId, $month, $byId, $parents);
                if ($ownerId === null || !isset($eligible[$ownerId])) {
                    continue;
                }

                $base = $this->resolveBase($category, $snapshotsByCategory[$catId] ?? [], $month);
                if ($base <= 0 && $month >= $currentMonth) {
                    if ($recurring === null) {
                        $recurring = $this->recurringBudgetService->getMonthlyBudgetsByCategory($userId);
                    }
                    $base = (float) ($recurring[$catId] ?? 0);
                }

                $branchBase[$ownerId] = ($branchBase[$ownerId] ?? 0.0) + $base;
                $branchSpent[$ownerId] = ($branchSpent[$ownerId] ?? 0.0) + ($spentByMonth[$catId][$month] ?? 0.0);
            }

            foreach ($eligible as $catId => $category) {
                if ($month < $category->getRolloverStart()) {
                    continue;
                }

                $base = $branchBase[$catId] ?? 0.0;
                if ($base > 0 || abs($carryovers[$catId]) >= 0.005) {
                    $carryovers[$catId] = round(
                        $base + $carryovers[$catId] - ($branchSpent[$catId] ?? 0.0),
                        2
                    );
                } else {
                    $carryovers[$catId] = 0.0;
                }
            }
        }

        return $carryovers;
    }

    /**
     * The envelope a category's budget and spending belong to in $month: the
     * nearest ancestor-or-self running one. Null when nothing in the ancestry
     * does, so the row simply belongs to no envelope.
     *
     * @param array<int, Category> $byId
     * @param array<int, int|null> $parents
     */
    private function envelopeOwner(int $catId, string $month, array $byId, array $parents): ?int {
        $cursor = $catId;
        for ($depth = 0; $cursor !== null && $depth < self::MAX_DEPTH; $depth++) {
            if (!isset($byId[$cursor])) {
                return null; // parent outside this list (e.g. shared)
            }

            // Tested on every ancestor, so a branch hidden from reports takes
            // its descendants with it — the Budget view drops an excluded node
            // and everything under it, and excluded_from_reports has no
            // cascade of its own the way BudgetScope gives the budget flag.
            if ($byId[$cursor]->getExcludedFromReports() ?? false) {
                return null;
            }

            if ($this->envelopeRunsIn($byId[$cursor], $month)) {
                return $cursor;
            }
            $cursor = $parents[$cursor] ?? null;
        }
        return null;
    }

    /**
     * Whether this category's own envelope is accruing in $month.
     *
     * Same conditions as isRolloverEligible(), except the anchor is compared
     * against a chain month it may equal: an envelope starts accruing IN its
     * anchor month, while eligibility is judged against a target month it has
     * to precede.
     */
    private function envelopeRunsIn(Category $category, string $month): bool {
        $anchor = $category->getRolloverStart();

        return ($category->getBudgetRollover() ?? false)
            && $anchor !== null
            && $anchor <= $month
            && ($category->getBudgetPeriod() ?? 'monthly') === 'monthly'
            && $category->getType() === 'expense'
            && !($category->getExcludedFromReports() ?? false)
            && !($category->getExcludedFromBudget() ?? false);
    }

    /**
     * Whether rollover applies to this category at all (v1: monthly-period
     * expense categories with the flag and an anchor before the target).
     *
     * Checks the category's own flags only; getCarryovers() additionally drops
     * the descendants of a category excluded from budgeting.
     */
    public function isRolloverEligible(Category $category, string $targetMonth): bool {
        return ($category->getBudgetRollover() ?? false)
            && $category->getRolloverStart() !== null
            && $category->getRolloverStart() < $targetMonth
            && ($category->getBudgetPeriod() ?? 'monthly') === 'monthly'
            && $category->getType() === 'expense'
            && !($category->getExcludedFromReports() ?? false)
            && !($category->getExcludedFromBudget() ?? false);
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
     * @param int[]|null $visibleAccountIds accounts in scope (own + shared)
     * @return array<int, array<string, float>> categoryId => month => spent
     */
    private function loadSpending(string $userId, array $categoryIds, array $months, int $startDay, ?array $visibleAccountIds = null): array {
        $firstMonth = $months[0];
        $lastMonth = end($months);

        if ($startDay === 1) {
            $startDate = $firstMonth . '-01';
            $endDate = date('Y-m-t', strtotime($lastMonth . '-01'));

            $direct = $this->transactionMapper->getCategorySpendingByBucketBatch($userId, $startDate, $endDate, false, $visibleAccountIds);
            $splits = $this->splitMapper->getCategoryTotalsByBucket($userId, $startDate, $endDate, false, $visibleAccountIds);

            return $this->mergeSpending($categoryIds, $direct, $splits);
        }

        // Custom start day: budget month m spans [startDay of m, startDay of m+1)
        $ranges = [];
        foreach ($months as $month) {
            $ranges[$month] = $this->periodRange($month, $startDay);
        }
        $startDate = $ranges[$firstMonth][0];
        $endDate = $ranges[$lastMonth][1];

        $direct = $this->transactionMapper->getCategorySpendingByBucketBatch($userId, $startDate, $endDate, true, $visibleAccountIds);
        $splits = $this->splitMapper->getCategoryTotalsByBucket($userId, $startDate, $endDate, true, $visibleAccountIds);

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
     * start day. Mirrors the app-wide convention (frontend
     * getPeriodDateRange with the 15th as reference, and
     * BudgetAlertService::calculateMonthlyRange): budget month M is the
     * period CONTAINING the 15th of M. With start day 25, "June" is
     * May 25 – Jun 24; with start day 10, "June" is Jun 10 – Jul 9.
     *
     * @return array{0: string, 1: string}
     */
    private function periodRange(string $month, int $startDay): array {
        $monthStart = \DateTime::createFromFormat('!Y-m-d', $month . '-01');
        $daysInMonth = (int) $monthStart->format('t');
        $effectiveStartDay = min($startDay, $daysInMonth);

        if ($effectiveStartDay <= 15) {
            // Period starts in $month, ends the day before next month's start day
            $start = sprintf('%s-%02d', $month, $effectiveStartDay);
            $next = (clone $monthStart)->modify('first day of next month');
            $end = $this->clampedDay($next, $startDay)->modify('-1 day')->format('Y-m-d');
        } else {
            // Period starts in the PREVIOUS month, ends the day before $month's start day
            $prev = (clone $monthStart)->modify('first day of last month');
            $start = $this->clampedDay($prev, $startDay)->format('Y-m-d');
            $end = sprintf('%s-%02d', $month, $effectiveStartDay);
            $end = \DateTime::createFromFormat('!Y-m-d', $end)->modify('-1 day')->format('Y-m-d');
        }

        return [$start, $end];
    }

    private function clampedDay(\DateTime $monthStart, int $startDay): \DateTime {
        $day = min($startDay, (int) $monthStart->format('t'));
        return (clone $monthStart)->setDate(
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('n'),
            $day
        );
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
