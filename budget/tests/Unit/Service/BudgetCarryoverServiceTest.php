<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\BudgetSnapshot;
use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Service\BudgetCarryoverService;
use OCA\Budget\Service\RecurringBudgetService;
use OCA\Budget\Service\SettingService;
use PHPUnit\Framework\TestCase;

class TestableBudgetCarryoverService extends BudgetCarryoverService {
    public string $currentMonth = '2026-06';

    protected function getCurrentMonth(): string {
        return $this->currentMonth;
    }
}

class BudgetCarryoverServiceTest extends TestCase {
    private TestableBudgetCarryoverService $service;
    private TransactionMapper $transactionMapper;
    private TransactionSplitMapper $splitMapper;
    private BudgetSnapshotMapper $snapshotMapper;
    private SettingService $settingService;
    private RecurringBudgetService $recurringBudgetService;

    /** @var array<int, array<string, float>> */
    private array $directSpending = [];
    /** @var array<int, array<string, float>> */
    private array $splitSpending = [];
    /** @var BudgetSnapshot[] */
    private array $snapshots = [];
    /** @var array<int, float> */
    private array $recurring = [];
    /** @var array<int, int[]|null> every visible-account scope the mappers were called with */
    private array $seenAccountScopes = [];

    protected function setUp(): void {
        $categoryMapper = $this->createMock(CategoryMapper::class);
        $this->snapshotMapper = $this->createMock(BudgetSnapshotMapper::class);
        $this->snapshotMapper->method('findAll')->willReturnCallback(fn() => $this->snapshots);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->transactionMapper->method('getCategorySpendingByBucketBatch')
            ->willReturnCallback(function (string $u, string $s, string $e, bool $byDay = false, ?array $visible = null) {
                $this->seenAccountScopes[] = $visible;
                return $this->directSpending;
            });
        $this->splitMapper = $this->createMock(TransactionSplitMapper::class);
        $this->splitMapper->method('getCategoryTotalsByBucket')
            ->willReturnCallback(function (string $u, string $s, string $e, bool $byDay = false, ?array $visible = null) {
                $this->seenAccountScopes[] = $visible;
                return $this->splitSpending;
            });
        $this->settingService = $this->createMock(SettingService::class);
        $this->settingService->method('get')->willReturn(null); // start day 1
        $this->recurringBudgetService = $this->createMock(RecurringBudgetService::class);
        $this->recurringBudgetService->method('getMonthlyBudgetsByCategory')
            ->willReturnCallback(fn() => $this->recurring);

        $this->service = new TestableBudgetCarryoverService(
            $categoryMapper,
            $this->snapshotMapper,
            $this->transactionMapper,
            $this->splitMapper,
            $this->recurringBudgetService,
            $this->settingService
        );
    }

    private function makeCategory(array $overrides = []): Category {
        $category = new Category();
        $defaults = [
            'id' => 1,
            'type' => 'expense',
            'budgetAmount' => 300.0,
            'budgetPeriod' => 'monthly',
            'budgetRollover' => true,
            'rolloverStart' => '2026-03',
            'excludedFromReports' => false,
        ];
        foreach (array_merge($defaults, $overrides) as $key => $value) {
            $category->{'set' . ucfirst($key)}($value);
        }
        return $category;
    }

    private function makeSnapshot(int $categoryId, string $effectiveFrom, ?float $amount, string $period = 'monthly'): BudgetSnapshot {
        $snapshot = new BudgetSnapshot();
        $snapshot->setCategoryId($categoryId);
        $snapshot->setEffectiveFrom($effectiveFrom);
        $snapshot->setAmount($amount);
        $snapshot->setPeriod($period);
        return $snapshot;
    }

    public function testPositiveChainAccumulates(): void {
        // 300/month, spent 250/280/300 in Mar/Apr/May → carry into June = 70
        $this->directSpending = [1 => ['2026-03' => 250.0, '2026-04' => 280.0, '2026-05' => 300.0]];

        $result = $this->service->getCarryovers('alice', '2026-06', [$this->makeCategory()]);

        $this->assertSame(70.0, $result[1]);
    }

    public function testOverspendCarriesNegative(): void {
        // Spent 400 in March against 300 → −100 into April, drained further
        $this->directSpending = [1 => ['2026-03' => 400.0, '2026-04' => 350.0]];

        $result = $this->service->getCarryovers('alice', '2026-05', [$this->makeCategory()]);

        // Mar: 300 − 400 = −100; Apr: 300 − 100 − 350 = −150
        $this->assertSame(-150.0, $result[1]);
    }

    public function testAnchorMonthHasZeroCarry(): void {
        $this->directSpending = [1 => ['2026-02' => 0.0]];

        $result = $this->service->getCarryovers('alice', '2026-03', [$this->makeCategory()]);

        // Carry into the anchor month itself is 0 (absent key = 0 by convention)
        $this->assertSame(0.0, $result[1] ?? 0.0);
    }

    public function testMonthsBeforeAnchorIgnored(): void {
        // Heavy overspend before the anchor must not leak into the chain
        $this->directSpending = [1 => ['2026-01' => 9999.0, '2026-03' => 100.0]];

        $result = $this->service->getCarryovers('alice', '2026-04', [$this->makeCategory()]);

        $this->assertSame(200.0, $result[1]);
    }

    public function testInactiveMonthResetsToZero(): void {
        // No base and no carry → the month contributes nothing
        $this->directSpending = [1 => ['2026-03' => 50.0]];

        $result = $this->service->getCarryovers('alice', '2026-05', [
            $this->makeCategory(['budgetAmount' => 0.0]),
        ]);

        $this->assertSame(0.0, $result[1]);
    }

    public function testCarryDrainsThroughZeroBudgetMonth(): void {
        // March builds 100; April has no base but spending drains the envelope
        $this->snapshots = [
            $this->makeSnapshot(1, '2026-04', 0.0),
        ];
        $this->directSpending = [1 => ['2026-03' => 200.0, '2026-04' => 40.0]];

        $result = $this->service->getCarryovers('alice', '2026-05', [$this->makeCategory()]);

        // Mar: 300 − 200 = 100; Apr: 0 + 100 − 40 = 60
        $this->assertSame(60.0, $result[1]);
    }

    public function testSnapshotChangesBaseMidChain(): void {
        $this->snapshots = [
            $this->makeSnapshot(1, '2026-04', 500.0),
        ];
        $this->directSpending = [1 => ['2026-03' => 300.0, '2026-04' => 300.0]];

        $result = $this->service->getCarryovers('alice', '2026-05', [$this->makeCategory()]);

        // Mar: 300 − 300 = 0... but carry 0 + base>0 keeps chain active; Apr: 500 + 0 − 300 = 200
        $this->assertSame(200.0, $result[1]);
    }

    public function testPastMonthEditRecomputes(): void {
        // The "derive, don't increment" proof: changing March's spending
        // changes June's carry with no invalidation step
        $category = $this->makeCategory();
        $this->directSpending = [1 => ['2026-03' => 250.0]];
        $before = $this->service->getCarryovers('alice', '2026-04', [$category]);

        $this->directSpending = [1 => ['2026-03' => 100.0]];
        $after = $this->service->getCarryovers('alice', '2026-04', [$category]);

        $this->assertSame(50.0, $before[1]);
        $this->assertSame(200.0, $after[1]);
    }

    public function testSplitSpendingIncluded(): void {
        $this->directSpending = [1 => ['2026-03' => 100.0]];
        $this->splitSpending = [1 => ['2026-03' => 50.0]];

        $result = $this->service->getCarryovers('alice', '2026-04', [$this->makeCategory()]);

        $this->assertSame(150.0, $result[1]);
    }

    public function testNonMonthlyPeriodExcluded(): void {
        $result = $this->service->getCarryovers('alice', '2026-06', [
            $this->makeCategory(['budgetPeriod' => 'weekly']),
        ]);

        $this->assertArrayNotHasKey(1, $result);
    }

    public function testIncomeCategoryExcluded(): void {
        $result = $this->service->getCarryovers('alice', '2026-06', [
            $this->makeCategory(['type' => 'income']),
        ]);

        $this->assertArrayNotHasKey(1, $result);
    }

    public function testFlagOffExcluded(): void {
        $result = $this->service->getCarryovers('alice', '2026-06', [
            $this->makeCategory(['budgetRollover' => false]),
        ]);

        $this->assertArrayNotHasKey(1, $result);
    }

    public function testExcludedFromReportsExcluded(): void {
        $result = $this->service->getCarryovers('alice', '2026-06', [
            $this->makeCategory(['excludedFromReports' => true]),
        ]);

        $this->assertArrayNotHasKey(1, $result);
    }

    public function testExcludedFromBudgetExcluded(): void {
        $this->directSpending = [1 => ['2026-03' => 100.0]];

        $result = $this->service->getCarryovers('alice', '2026-06', [
            $this->makeCategory(['excludedFromBudget' => true]),
        ]);

        $this->assertArrayNotHasKey(1, $result);
    }

    public function testChildOfExcludedFromBudgetParentExcluded(): void {
        // The flag applies to the whole subtree, so a rollover-enabled child of
        // an excluded parent carries nothing either
        $this->directSpending = [2 => ['2026-03' => 100.0]];

        $result = $this->service->getCarryovers('alice', '2026-06', [
            $this->makeCategory(['id' => 1, 'excludedFromBudget' => true, 'budgetRollover' => false]),
            $this->makeCategory(['id' => 2, 'parentId' => 1]),
        ]);

        $this->assertArrayNotHasKey(2, $result);
    }

    public function testRecurringFallbackOnlyForCurrentAndFutureMonths(): void {
        // Manual budget 0; recurring 200/month. Chain Mar..Jun targeting July.
        // Past months (Mar–May) get base 0 (inactive), current month (June)
        // gets the recurring base — so carry into July is June's remainder only.
        $this->service->currentMonth = '2026-06';
        $this->recurring = [1 => 200.0];
        $this->directSpending = [1 => ['2026-04' => 500.0, '2026-06' => 50.0]];

        $result = $this->service->getCarryovers('alice', '2026-07', [
            $this->makeCategory(['budgetAmount' => 0.0]),
        ]);

        $this->assertSame(150.0, $result[1]);
    }

    public function testFutureMonthsAccumulateProjectedBases(): void {
        // Targeting +2 months: current month June (spent 100 of 300) and
        // future July (no spending) both contribute
        $this->service->currentMonth = '2026-06';
        $this->directSpending = [1 => ['2026-06' => 100.0]];

        $result = $this->service->getCarryovers('alice', '2026-08', [
            $this->makeCategory(['rolloverStart' => '2026-06']),
        ]);

        // June: 300 − 100 = 200; July: 300 + 200 − 0 = 500
        $this->assertSame(500.0, $result[1]);
    }

    public function testNonMonthlySnapshotMonthIsInactive(): void {
        // A quarterly snapshot month is out of envelope scope → base 0
        $this->snapshots = [
            $this->makeSnapshot(1, '2026-04', 900.0, 'quarterly'),
        ];
        $this->directSpending = [1 => ['2026-03' => 200.0, '2026-04' => 40.0]];

        $result = $this->service->getCarryovers('alice', '2026-05', [$this->makeCategory()]);

        // Mar: 300 − 200 = 100; Apr: base 0 (non-monthly snapshot) + 100 − 40 = 60
        $this->assertSame(60.0, $result[1]);
    }

    public function testLateStartDayMapsPeriodToContainingMonth(): void {
        // Start day 25: budget month M is the period CONTAINING the 15th of M
        // (matches the Budget view): "May" = Apr 25 – May 24, "June" = May 25 – Jun 24.
        // Spending on Jun 10 belongs to JUNE's envelope month.
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturn('25');
        $service = new TestableBudgetCarryoverService(
            $this->createMock(CategoryMapper::class),
            $this->snapshotMapper,
            $this->transactionMapper,
            $this->splitMapper,
            $this->recurringBudgetService,
            $settingService
        );
        $service->currentMonth = '2026-06';

        $this->directSpending = [1 => ['2026-05-28' => 100.0, '2026-06-10' => 30.0]];

        $result = $service->getCarryovers('alice', '2026-07', [
            $this->makeCategory(['budgetAmount' => 50.0, 'rolloverStart' => '2026-06']),
        ]);

        // June period (May 25 – Jun 24) spent = 100 + 30; carry = 50 − 130 = −80
        $this->assertSame(-80.0, $result[1]);
    }

    public function testCustomStartDayBucketsByPeriod(): void {
        // Start day 15: "March" period is Mar 15 – Apr 14. Spending on Apr 10
        // belongs to March's envelope month, Apr 20 to April's.
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturn('15');
        $service = new TestableBudgetCarryoverService(
            $this->createMock(CategoryMapper::class),
            $this->snapshotMapper,
            $this->transactionMapper,
            $this->splitMapper,
            $this->recurringBudgetService,
            $settingService
        );
        $service->currentMonth = '2026-06';

        $this->directSpending = [1 => ['2026-04-10' => 100.0, '2026-04-20' => 50.0]];

        $result = $service->getCarryovers('alice', '2026-05', [$this->makeCategory()]);

        // Mar period: 300 − 100 = 200; Apr period: 300 + 200 − 50 = 450
        $this->assertSame(450.0, $result[1]);
    }

    // ── the envelope covers the whole branch (#341) ─────────────────

    /**
     * The reported bug: budget the parent, file the transactions under a
     * subcategory. Spending was looked up by the envelope category's own id
     * only, so it found nothing and the full base carried forward every month.
     */
    public function testSubcategorySpendingCountsAgainstTheParentEnvelope(): void {
        $parent = $this->makeCategory(['id' => 1]);
        $child = $this->makeCategory([
            'id' => 2,
            'parentId' => 1,
            'budgetAmount' => null,
            'budgetRollover' => false,
            'rolloverStart' => null,
        ]);
        $this->directSpending = [2 => ['2026-03' => 250.0]];

        $result = $this->service->getCarryovers('alice', '2026-04', [$parent, $child]);

        $this->assertSame(50.0, $result[1]);
    }

    public function testDeepDescendantSpendingCountsAgainstTheParentEnvelope(): void {
        $parent = $this->makeCategory(['id' => 1]);
        $child = $this->makeCategory(['id' => 2, 'parentId' => 1, 'budgetAmount' => null, 'budgetRollover' => false, 'rolloverStart' => null]);
        $grandchild = $this->makeCategory(['id' => 3, 'parentId' => 2, 'budgetAmount' => null, 'budgetRollover' => false, 'rolloverStart' => null]);
        $this->directSpending = [3 => ['2026-03' => 120.0]];

        $result = $this->service->getCarryovers('alice', '2026-04', [$parent, $child, $grandchild]);

        $this->assertSame(180.0, $result[1]);
    }

    /** A subcategory's own budget is part of the branch's envelope too. */
    public function testSubcategoryBudgetCountsTowardsTheParentEnvelope(): void {
        $parent = $this->makeCategory(['id' => 1, 'budgetAmount' => 300.0]);
        $child = $this->makeCategory(['id' => 2, 'parentId' => 1, 'budgetAmount' => 100.0, 'budgetRollover' => false, 'rolloverStart' => null]);
        $this->directSpending = [1 => ['2026-03' => 50.0], 2 => ['2026-03' => 250.0]];

        $result = $this->service->getCarryovers('alice', '2026-04', [$parent, $child]);

        // Branch base 400 − branch spend 300 = 100
        $this->assertSame(100.0, $result[1]);
    }

    /**
     * A subcategory running its own envelope keeps its own chain, and the
     * parent must not double-count it — the Budget view already shows the
     * child's figures on both rows.
     */
    public function testSubcategoryWithItsOwnEnvelopeIsNotCountedByTheParent(): void {
        $parent = $this->makeCategory(['id' => 1, 'budgetAmount' => 300.0]);
        $child = $this->makeCategory(['id' => 2, 'parentId' => 1, 'budgetAmount' => 100.0]);
        $this->directSpending = [1 => ['2026-03' => 200.0], 2 => ['2026-03' => 40.0]];

        $result = $this->service->getCarryovers('alice', '2026-04', [$parent, $child]);

        $this->assertSame(100.0, $result[1]);
        $this->assertSame(60.0, $result[2]);
    }

    /** Before the child's envelope starts, its spending belongs to the parent. */
    public function testSubcategorySpendingBelongsToTheParentBeforeItsOwnAnchor(): void {
        $parent = $this->makeCategory(['id' => 1, 'budgetAmount' => 300.0, 'rolloverStart' => '2026-03']);
        $child = $this->makeCategory(['id' => 2, 'parentId' => 1, 'budgetAmount' => null, 'rolloverStart' => '2026-04']);
        $this->directSpending = [2 => ['2026-03' => 250.0, '2026-04' => 30.0]];

        $result = $this->service->getCarryovers('alice', '2026-05', [$parent, $child]);

        // March: child has no envelope yet, so the parent absorbs it → 300 − 250 = 50.
        // April: the child runs its own, so the parent keeps its full 300 → 350.
        $this->assertSame(350.0, $result[1]);
    }

    /** A branch member no budget surface counts must not reach the envelope. */
    public function testExcludedSubcategoryDoesNotAffectTheParentEnvelope(): void {
        $parent = $this->makeCategory(['id' => 1, 'budgetAmount' => 300.0]);
        $excludedFromBudget = $this->makeCategory([
            'id' => 2, 'parentId' => 1, 'budgetAmount' => 500.0,
            'budgetRollover' => false, 'rolloverStart' => null, 'excludedFromBudget' => true,
        ]);
        $excludedFromReports = $this->makeCategory([
            'id' => 3, 'parentId' => 1, 'budgetAmount' => 500.0,
            'budgetRollover' => false, 'rolloverStart' => null, 'excludedFromReports' => true,
        ]);
        $this->directSpending = [
            1 => ['2026-03' => 100.0],
            2 => ['2026-03' => 400.0],
            3 => ['2026-03' => 400.0],
        ];

        $result = $this->service->getCarryovers('alice', '2026-04', [$parent, $excludedFromBudget, $excludedFromReports]);

        $this->assertSame(200.0, $result[1]);
    }

    /**
     * A subcategory budgeting over a different span is not part of a monthly
     * envelope: its amount covers a quarter, so adding it to every month of
     * the chain would invent three times the money.
     */
    public function testNonMonthlySubcategoryBudgetStaysOutOfTheEnvelope(): void {
        $parent = $this->makeCategory(['id' => 1, 'budgetAmount' => 300.0]);
        $quarterly = $this->makeCategory([
            'id' => 2, 'parentId' => 1, 'budgetAmount' => 900.0, 'budgetPeriod' => 'quarterly',
            'budgetRollover' => false, 'rolloverStart' => null,
        ]);

        $result = $this->service->getCarryovers('alice', '2026-04', [$parent, $quarterly]);

        $this->assertSame(300.0, $result[1]);
    }

    /** An income subcategory holds a target, not a budget to spend against. */
    public function testIncomeSubcategoryStaysOutOfTheEnvelope(): void {
        $parent = $this->makeCategory(['id' => 1, 'budgetAmount' => 300.0]);
        $income = $this->makeCategory([
            'id' => 2, 'parentId' => 1, 'type' => 'income', 'budgetAmount' => 2000.0,
            'budgetRollover' => false, 'rolloverStart' => null,
        ]);

        $result = $this->service->getCarryovers('alice', '2026-04', [$parent, $income]);

        $this->assertSame(300.0, $result[1]);
    }

    /**
     * excluded_from_reports has no cascade of its own (unlike the budget flag,
     * which BudgetScope inherits down the tree), so it has to be tested on
     * every ancestor or a grandchild slips back into the envelope.
     */
    public function testDescendantOfAReportExcludedSubcategoryStaysOutOfTheEnvelope(): void {
        $parent = $this->makeCategory(['id' => 1, 'budgetAmount' => 300.0]);
        $excluded = $this->makeCategory([
            'id' => 2, 'parentId' => 1, 'budgetAmount' => null,
            'budgetRollover' => false, 'rolloverStart' => null, 'excludedFromReports' => true,
        ]);
        $grandchild = $this->makeCategory([
            'id' => 3, 'parentId' => 2, 'budgetAmount' => 500.0,
            'budgetRollover' => false, 'rolloverStart' => null,
        ]);
        $this->directSpending = [3 => ['2026-03' => 400.0]];

        $result = $this->service->getCarryovers('alice', '2026-04', [$parent, $excluded, $grandchild]);

        $this->assertSame(300.0, $result[1]);
    }

    /** The same cascade for the budget flag, which BudgetScope inherits. */
    public function testDescendantOfABudgetExcludedSubcategoryStaysOutOfTheEnvelope(): void {
        $parent = $this->makeCategory(['id' => 1, 'budgetAmount' => 300.0]);
        $excluded = $this->makeCategory([
            'id' => 2, 'parentId' => 1, 'budgetAmount' => null,
            'budgetRollover' => false, 'rolloverStart' => null, 'excludedFromBudget' => true,
        ]);
        $grandchild = $this->makeCategory([
            'id' => 3, 'parentId' => 2, 'budgetAmount' => 500.0,
            'budgetRollover' => false, 'rolloverStart' => null,
        ]);
        $this->directSpending = [3 => ['2026-03' => 400.0]];

        $result = $this->service->getCarryovers('alice', '2026-04', [$parent, $excluded, $grandchild]);

        $this->assertSame(300.0, $result[1]);
    }

    /** Split allocations booked on a subcategory count for the branch too. */
    public function testSubcategorySplitSpendingCountsAgainstTheParentEnvelope(): void {
        $parent = $this->makeCategory(['id' => 1]);
        $child = $this->makeCategory(['id' => 2, 'parentId' => 1, 'budgetAmount' => null, 'budgetRollover' => false, 'rolloverStart' => null]);
        $this->splitSpending = [2 => ['2026-03' => 120.0]];

        $result = $this->service->getCarryovers('alice', '2026-04', [$parent, $child]);

        $this->assertSame(180.0, $result[1]);
    }

    // ── shared accounts are in scope (#341) ─────────────────────────

    /**
     * Spending in an account shared with the user counts towards their
     * envelope: the chain's two spending queries were scoped to the accounts
     * the user OWNS while every other budget surface scopes to the ones they
     * can SEE, so a category funded out of a shared account carried its whole
     * budget forward every month.
     */
    public function testVisibleAccountScopeReachesBothSpendingQueries(): void {
        $this->service->getCarryovers('alice', '2026-04', [$this->makeCategory()], [7, 9]);

        $this->assertNotEmpty($this->seenAccountScopes);
        foreach ($this->seenAccountScopes as $scope) {
            $this->assertSame([7, 9], $scope);
        }
    }

    /** Callers that pass no scope keep the own-accounts-only behaviour. */
    public function testOmittedAccountScopeLeavesTheQueriesUnscoped(): void {
        $this->service->getCarryovers('alice', '2026-04', [$this->makeCategory()]);

        $this->assertNotEmpty($this->seenAccountScopes);
        foreach ($this->seenAccountScopes as $scope) {
            $this->assertNull($scope);
        }
    }

    /** The custom-start-day path uses the same two queries, so it must too. */
    public function testVisibleAccountScopeReachesTheCustomStartDayPath(): void {
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturn('15');
        $service = new TestableBudgetCarryoverService(
            $this->createMock(CategoryMapper::class),
            $this->snapshotMapper,
            $this->transactionMapper,
            $this->splitMapper,
            $this->recurringBudgetService,
            $settingService
        );
        $service->currentMonth = '2026-06';

        $service->getCarryovers('alice', '2026-05', [$this->makeCategory()], [7, 9]);

        $this->assertNotEmpty($this->seenAccountScopes);
        foreach ($this->seenAccountScopes as $scope) {
            $this->assertSame([7, 9], $scope);
        }
    }
}
