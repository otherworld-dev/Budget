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

    protected function setUp(): void {
        $categoryMapper = $this->createMock(CategoryMapper::class);
        $this->snapshotMapper = $this->createMock(BudgetSnapshotMapper::class);
        $this->snapshotMapper->method('findAll')->willReturnCallback(fn() => $this->snapshots);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->transactionMapper->method('getCategorySpendingByBucketBatch')
            ->willReturnCallback(fn() => $this->directSpending);
        $this->splitMapper = $this->createMock(TransactionSplitMapper::class);
        $this->splitMapper->method('getCategoryTotalsByBucket')
            ->willReturnCallback(fn() => $this->splitSpending);
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
}
