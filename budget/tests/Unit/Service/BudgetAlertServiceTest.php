<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\SettingService;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * Testable subclass that allows overriding the current date.
 */
class TestableBudgetAlertService extends BudgetAlertService {
    private ?\DateTime $fakeNow = null;

    public function setNow(\DateTime $now): void {
        $this->fakeNow = $now;
    }

    protected function getNow(): \DateTime {
        return $this->fakeNow ? clone $this->fakeNow : parent::getNow();
    }
}

class BudgetAlertServiceTest extends TestCase {
    private TestableBudgetAlertService $service;
    private CategoryMapper $categoryMapper;
    private TransactionMapper $transactionMapper;
    private SettingService $settingService;
    /** @var array<int, float> Recurring budgets returned by the mock */
    private array $recurringBudgets = [];
    private array $carryovers = [];
    /** @var array<int, array{amount: float, period?: string}> per-month budget overrides */
    private array $snapshots = [];
    /** @var string[] months the snapshot and carryover lookups were asked for */
    private array $budgetMonthsAsked = [];
    /** @var array<string, string> persisted budget_settings */
    private array $settings = [];
    /** @var array[] subject parameters of each notification sent */
    private array $sent = [];
    /** Spending answered by the mocked mapper; mutable so a run can escalate. */
    private float $spend = 0.0;

    private const USER_ID = 'testuser';

    protected function setUp(): void {
        $this->categoryMapper = $this->createMock(CategoryMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->settingService = $this->createMock(SettingService::class);

        $budgetSnapshotMapper = $this->createMock(BudgetSnapshotMapper::class);
        $budgetSnapshotMapper->method('findEffectiveBatch')
            ->willReturnCallback(function (string $userId, string $month): array {
                $this->budgetMonthsAsked[] = "snapshot $month";
                return $this->snapshots;
            });

        // Per-test recurring budgets via $this->recurringBudgets; conversion
        // uses the real (pure) math
        $recurringBudgetService = $this->createMock(\OCA\Budget\Service\RecurringBudgetService::class);
        $recurringBudgetService->method('getMonthlyBudgetsByCategory')
            ->willReturnCallback(fn() => $this->recurringBudgets);
        $recurringBudgetService->method('convertMonthlyToPeriod')
            ->willReturnCallback(fn(float $monthly, string $period) => match ($period) {
                'weekly' => $monthly * 12 / 52,
                'quarterly' => $monthly * 3,
                'yearly' => $monthly * 12,
                default => $monthly,
            });

        $carryoverService = $this->createMock(\OCA\Budget\Service\BudgetCarryoverService::class);
        $carryoverService->method('getCarryovers')
            ->willReturnCallback(function (string $userId, string $month): array {
                $this->budgetMonthsAsked[] = "carryover $month";
                return $this->carryovers;
            });

        $this->service = new TestableBudgetAlertService(
            $this->categoryMapper,
            $budgetSnapshotMapper,
            $this->transactionMapper,
            $this->settingService,
            $recurringBudgetService,
            $carryoverService,
            $this->createMock(INotificationManager::class),
            $this->createMock(AmountFormatter::class)
        );
    }

    /**
     * A service wired for the notification tests: one budgeted category, a
     * mutable spend figure, and a SettingService backed by $this->settings so
     * suppression state survives between runs.
     */
    private function makeNotifyingService(float $budget): TestableBudgetAlertService {
        $categoryMapper = $this->createMock(CategoryMapper::class);
        $categoryMapper->method('findAll')->willReturn([
            $this->makeCategory(['id' => 1, 'name' => 'Groceries', 'budgetAmount' => $budget]),
        ]);

        $transactionMapper = $this->createMock(TransactionMapper::class);
        $transactionMapper->method('getCategorySpendingBatch')
            ->willReturnCallback(fn(array $ids): array => array_fill_keys($ids, $this->spend));

        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')
            ->willReturnCallback(fn(string $userId, string $key) => $this->settings[$key] ?? null);
        $settingService->method('set')
            ->willReturnCallback(function (string $userId, string $key, string $value) {
                $this->settings[$key] = $value;
                return new \OCA\Budget\Db\Setting();
            });

        $recurringBudgetService = $this->createMock(\OCA\Budget\Service\RecurringBudgetService::class);
        $recurringBudgetService->method('getMonthlyBudgetsByCategory')->willReturn([]);
        $recurringBudgetService->method('convertMonthlyToPeriod')
            ->willReturnCallback(fn(float $monthly, string $period) => $monthly);

        $carryoverService = $this->createMock(\OCA\Budget\Service\BudgetCarryoverService::class);
        $carryoverService->method('getCarryovers')->willReturn([]);

        $notification = $this->createMock(INotification::class);
        $notification->method('setSubject')
            ->willReturnCallback(function (string $subject, array $params) use ($notification) {
                $this->sent[] = $params;
                return $notification;
            });
        $notification->method($this->anything())->willReturnSelf();
        $notificationManager = $this->createMock(INotificationManager::class);
        $notificationManager->method('createNotification')->willReturn($notification);

        $amountFormatter = $this->createMock(AmountFormatter::class);
        $amountFormatter->method('formatForUser')->willReturnCallback(fn($u, float $a) => '$' . number_format($a, 2));

        return new TestableBudgetAlertService(
            $categoryMapper,
            $this->createMock(BudgetSnapshotMapper::class),
            $transactionMapper,
            $settingService,
            $recurringBudgetService,
            $carryoverService,
            $notificationManager,
            $amountFormatter
        );
    }

    private function makeCategory(array $overrides = []): Category {
        $cat = new Category();
        $defaults = [
            'id' => 1,
            'userId' => self::USER_ID,
            'name' => 'Groceries',
            'type' => 'expense',
            'budgetAmount' => 500.0,
            'budgetPeriod' => 'monthly',
        ];
        $data = array_merge($defaults, $overrides);

        $cat->setId($data['id']);
        $cat->setUserId($data['userId']);
        $cat->setName($data['name']);
        $cat->setType($data['type']);
        $cat->setBudgetAmount($data['budgetAmount']);
        $cat->setBudgetPeriod($data['budgetPeriod']);
        $cat->setParentId($data['parentId'] ?? null);
        $cat->setExcludedFromBudget($data['excludedFromBudget'] ?? false);

        return $cat;
    }

    /**
     * Money that came back is not money spent (#361). A phone bill of 216.90
     * with 158.61 credited back is 58.29 against the budget -- and the budget
     * page, the budget report and the dashboard tiles all report that figure,
     * so an alert firing on the gross 216.90 would contradict the bar sitting
     * next to it.
     */
    public function testSpendingIsNetOfMoneyThatCameBack(): void {
        $category = $this->makeCategory(['id' => 1, 'name' => 'Phone', 'budgetAmount' => 120.00]);

        $this->categoryMapper->method('findAll')
            ->with(self::USER_ID)
            ->willReturn([$category]);

        // The batch nets in SQL: 216.90 out, 158.61 back. It is asked for the
        // money-out direction, which is what makes a refund come off.
        $this->transactionMapper->method('getCategorySpendingBatch')
            ->willReturnCallback(
                static fn(array $ids, string $s, string $e, string $type): array => $type === 'debit' ? [1 => 58.29] : []
            );

        $statuses = $this->service->getBudgetStatus(self::USER_ID);

        $this->assertCount(1, $statuses);
        $this->assertEqualsWithDelta(58.29, $statuses[0]['spent'], 0.005);
        // 58.29 of 120 is comfortably under, so nothing should be raised.
        $this->assertSame('ok', $statuses[0]['status']);
        $this->assertEmpty($this->service->getAlerts(self::USER_ID));
    }

    private function setupMocksForBudgetStatus(array $categories, float $spending = 0.0): void {
        $this->categoryMapper->method('findAll')
            ->with(self::USER_ID)
            ->willReturn($categories);

        $this->transactionMapper->method('getCategorySpendingBatch')
            ->willReturnCallback(static fn(array $ids): array => array_fill_keys($ids, $spending));
    }

    /**
     * Helper to get the monthly period range from getBudgetStatus response.
     */
    private function getMonthlyPeriod(string $startDaySetting, string $fakeDate): array {
        $this->service->setNow(new \DateTime($fakeDate));

        $this->settingService->method('get')
            ->willReturnCallback(fn($u, $key) => $key === 'budget_start_day' ? $startDaySetting : null);

        $category = $this->makeCategory();
        $this->setupMocksForBudgetStatus([$category]);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);
        $this->assertCount(1, $statuses);

        return $statuses[0];
    }

    // ===== Envelope carryover (rollover budgets) =====

    public function testBudgetStatusIncludesCarryover(): void {
        // 100 base + 50 carried = 150 available; 120 spent = 80% -> warning
        $this->carryovers = [1 => 50.0];
        $category = $this->makeCategory(['budgetAmount' => 100.0]);
        $this->setupMocksForBudgetStatus([$category], 120.0);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);

        $this->assertCount(1, $statuses);
        $this->assertSame(150.0, $statuses[0]['budgetAmount']);
        $this->assertSame(50.0, $statuses[0]['carried']);
        $this->assertSame(80.0, $statuses[0]['percentage']);
        $this->assertSame('warning', $statuses[0]['status']);
    }

    public function testDepletedEnvelopeStillAlerts(): void {
        // Base 0 with negative carry: any spending is over budget, and the
        // category must not vanish from alerts
        $this->carryovers = [1 => -50.0];
        $category = $this->makeCategory(['budgetAmount' => 0.0]);
        $this->setupMocksForBudgetStatus([$category], 30.0);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);

        $this->assertCount(1, $statuses);
        $this->assertSame('danger', $statuses[0]['status']);

        $alerts = $this->service->getAlerts(self::USER_ID);
        $this->assertCount(1, $alerts);
        $this->assertSame('danger', $alerts[0]['severity']);
    }

    // ===== Categories excluded from budgeting =====

    public function testExcludedFromBudgetCategoryHasNoStatusOrAlert(): void {
        // Over budget, but the user doesn't budget against it: no status, no alert
        $category = $this->makeCategory(['budgetAmount' => 100.0, 'excludedFromBudget' => true]);
        $this->setupMocksForBudgetStatus([$category], 500.0);

        $this->assertSame([], $this->service->getBudgetStatus(self::USER_ID));
        $this->assertSame([], $this->service->getAlerts(self::USER_ID));
    }

    public function testExcludedFromBudgetParentAlsoSilencesItsChildren(): void {
        // Flagging a parent takes the whole subtree out of budgeting
        $parent = $this->makeCategory(['id' => 1, 'excludedFromBudget' => true]);
        $child = $this->makeCategory(['id' => 2, 'name' => 'Presents', 'parentId' => 1]);
        $other = $this->makeCategory(['id' => 3, 'name' => 'Fuel']);
        $this->setupMocksForBudgetStatus([$parent, $child, $other], 500.0);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);

        $this->assertCount(1, $statuses);
        $this->assertSame(3, $statuses[0]['categoryId']);
    }

    // ===== A budget measures its branch (#551) =====

    /**
     * @param array<int, float> $debitByCategory
     */
    private function setupSpendingByCategory(array $categories, array $debitByCategory): void {
        $this->categoryMapper->method('findAll')->willReturn($categories);
        $this->transactionMapper->method('getCategorySpendingBatch')
            ->willReturnCallback(static fn(array $ids): array => array_intersect_key($debitByCategory, array_flip($ids)));
    }

    public function testParentBudgetCountsSpendingFiledUnderItsChildren(): void {
        // Housing is budgeted, the spending is all under Rent, which has no
        // budget of its own: it used to read 0 spent and never alert
        $parent = $this->makeCategory(['id' => 1, 'name' => 'Housing', 'budgetAmount' => 100.0]);
        $child = $this->makeCategory(['id' => 2, 'name' => 'Rent', 'parentId' => 1, 'budgetAmount' => 0.0]);
        $this->setupSpendingByCategory([$parent, $child], [2 => 120.0]);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);
        $alerts = $this->service->getAlerts(self::USER_ID);

        $this->assertCount(1, $statuses);
        $this->assertSame(120.0, $statuses[0]['spent']);
        $this->assertCount(1, $alerts);
        $this->assertSame(1, $alerts[0]['categoryId']);
        $this->assertSame('danger', $alerts[0]['severity']);
    }

    public function testChildWithItsOwnBudgetKeepsItsSpending(): void {
        // Rent has a budget of its own, so it measures itself and Housing
        // does not count it a second time
        $parent = $this->makeCategory(['id' => 1, 'name' => 'Housing', 'budgetAmount' => 100.0]);
        $child = $this->makeCategory(['id' => 2, 'name' => 'Rent', 'parentId' => 1, 'budgetAmount' => 50.0]);
        $this->setupSpendingByCategory([$parent, $child], [1 => 10.0, 2 => 40.0]);

        $spent = array_column($this->service->getBudgetStatus(self::USER_ID), 'spent', 'categoryId');

        $this->assertSame([1 => 10.0, 2 => 40.0], [1 => $spent[1], 2 => $spent[2]]);
    }

    public function testMutedChildBudgetDoesNotMoveOntoItsParent(): void {
        // Muting Rent silences Rent; its spending must not turn up on
        // Housing's alert instead
        $this->seedSettings(['budget_alert_muted_categories' => '[2]']);
        $parent = $this->makeCategory(['id' => 1, 'name' => 'Housing', 'budgetAmount' => 100.0]);
        $child = $this->makeCategory(['id' => 2, 'name' => 'Rent', 'parentId' => 1, 'budgetAmount' => 50.0]);
        $this->setupSpendingByCategory([$parent, $child], [2 => 150.0]);

        $this->assertSame([], $this->service->getAlerts(self::USER_ID));
    }

    // ===== One batch per period (N+1) =====

    /**
     * Spending comes from one getCategorySpendingBatch() per distinct period
     * covering every branch measured over it — not two queries per branch
     * member per budget plus four split queries — scoped to the viewer's
     * accounts and asked for the money-out direction.
     */
    public function testSpendingIsFetchedOncePerPeriodForEveryBranch(): void {
        $this->service->setNow(new \DateTime('2026-03-18'));
        $categories = [
            $this->makeCategory(['id' => 1, 'name' => 'Food', 'budgetAmount' => 100.0]),
            $this->makeCategory(['id' => 2, 'name' => 'Groceries', 'parentId' => 1, 'budgetAmount' => 0.0]),
            $this->makeCategory(['id' => 3, 'name' => 'Rent', 'budgetAmount' => 900.0]),
            $this->makeCategory(['id' => 4, 'name' => 'Fun', 'budgetAmount' => 50.0, 'budgetPeriod' => 'weekly']),
        ];
        $this->categoryMapper->method('findAll')->willReturn($categories);
        $calls = [];
        $this->transactionMapper->method('getCategorySpendingBatch')
            ->willReturnCallback(function (...$args) use (&$calls): array {
                $calls[] = $args;
                return [1 => 10.0, 2 => 20.5, 3 => 800.0, 4 => 12.25];
            });

        $statuses = $this->service->getBudgetStatus(self::USER_ID, [7, 8]);

        $this->assertCount(2, $calls);
        [$monthly, $weekly] = $calls;
        $this->assertSame([[1, 2, 3], '2026-03-01', '2026-03-31', 'debit', null, false, self::USER_ID, [7, 8], false], $monthly);
        $this->assertSame([[4], '2026-03-16', '2026-03-22', 'debit', null, false, self::USER_ID, [7, 8], false], $weekly);

        $spent = array_column($statuses, 'spent', 'categoryName');
        $this->assertSame(30.5, $spent['Food']);   // its own 10 + Groceries 20.50
        $this->assertSame(800.0, $spent['Rent']);
        $this->assertSame(12.25, $spent['Fun']);
    }

    public function testAlertsFetchSpendingOncePerPeriodToo(): void {
        $this->categoryMapper->method('findAll')->willReturn([
            $this->makeCategory(['id' => 1, 'budgetAmount' => 100.0]),
            $this->makeCategory(['id' => 2, 'name' => 'Rent', 'budgetAmount' => 100.0]),
        ]);
        $this->transactionMapper->expects($this->once())->method('getCategorySpendingBatch')
            ->willReturn([1 => 95.0, 2 => 120.0]);

        $alerts = $this->service->getAlerts(self::USER_ID);

        $this->assertSame(['danger', 'warning'], array_column($alerts, 'severity'));
    }

    // ===== Over-budget boundary (#293) =====

    public function testSpendingExactlyAtBudgetIsWarningNotDanger(): void {
        // Budget fully used (spent == budget) is "100% used", not exceeded.
        $category = $this->makeCategory(['budgetAmount' => 54.94]);
        $this->setupMocksForBudgetStatus([$category], 54.94);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);

        $this->assertCount(1, $statuses);
        $this->assertSame(100.0, $statuses[0]['percentage']);
        $this->assertSame('warning', $statuses[0]['status']);
    }

    public function testAlertExactlyAtBudgetIsWarningNotDanger(): void {
        $category = $this->makeCategory(['budgetAmount' => 54.94]);
        $this->setupMocksForBudgetStatus([$category], 54.94);

        $alerts = $this->service->getAlerts(self::USER_ID);

        $this->assertCount(1, $alerts);
        $this->assertSame(100.0, $alerts[0]['percentage']);
        $this->assertSame('warning', $alerts[0]['severity']);
    }

    public function testSpendingOverBudgetIsDanger(): void {
        $category = $this->makeCategory(['budgetAmount' => 100.0]);
        $this->setupMocksForBudgetStatus([$category], 120.0);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);

        $this->assertCount(1, $statuses);
        $this->assertSame(120.0, $statuses[0]['percentage']);
        $this->assertSame('danger', $statuses[0]['status']);
    }

    public function testSpendingJustOverBudgetIsDanger(): void {
        // A cent over the budget exceeds the half-cent epsilon -> over budget.
        $category = $this->makeCategory(['budgetAmount' => 100.0]);
        $this->setupMocksForBudgetStatus([$category], 100.01);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);

        $this->assertCount(1, $statuses);
        $this->assertSame('danger', $statuses[0]['status']);
    }

    // ===== Configurable alert threshold (#293) =====

    public function testAlertThreshold100SuppressesFullyUsedButNotOver(): void {
        // With the threshold at 100%, a category that is exactly at its budget
        // (fully used, not over) is "ok" and absent from the alerts tile.
        $this->settingService->method('get')->willReturnCallback(
            fn($u, $key) => $key === 'budget_alert_threshold' ? '100' : null
        );
        $category = $this->makeCategory(['budgetAmount' => 100.0]);
        $this->setupMocksForBudgetStatus([$category], 100.0);

        $this->assertSame('ok', $this->service->getBudgetStatus(self::USER_ID)[0]['status']);
        $this->assertCount(0, $this->service->getAlerts(self::USER_ID));
    }

    public function testAlertThreshold100StillAlertsWhenOverBudget(): void {
        $this->settingService->method('get')->willReturnCallback(
            fn($u, $key) => $key === 'budget_alert_threshold' ? '100' : null
        );
        $category = $this->makeCategory(['budgetAmount' => 100.0]);
        $this->setupMocksForBudgetStatus([$category], 120.0);

        $alerts = $this->service->getAlerts(self::USER_ID);
        $this->assertCount(1, $alerts);
        $this->assertSame('danger', $alerts[0]['severity']);
    }

    public function testDefaultThresholdStillWarnsBelowBudget(): void {
        // Default threshold (80%): a category at 90% is a warning.
        $category = $this->makeCategory(['budgetAmount' => 100.0]);
        $this->setupMocksForBudgetStatus([$category], 90.0);

        $this->assertSame('warning', $this->service->getBudgetStatus(self::USER_ID)[0]['status']);
    }

    // ===== Auto-derived recurring budgets (#269) =====

    public function testBudgetStatusFallsBackToRecurringBudget(): void {
        // A category with no manual budget but a recurring commitment must be
        // tracked, so alerts agree with the Budget view's auto-derived limits
        $this->service->setNow(new \DateTime('2026-03-15'));
        $this->settingService->method('get')->willReturn('1');

        $category = $this->makeCategory(['budgetAmount' => 0.0]);
        $this->recurringBudgets = [1 => 100.0];
        $this->setupMocksForBudgetStatus([$category], 90.0);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);

        $this->assertCount(1, $statuses);
        $this->assertEquals(100.0, $statuses[0]['budgetAmount']);
        $this->assertEquals('warning', $statuses[0]['status']); // 90%
    }

    public function testBudgetStatusIgnoresCategoryWithoutAnyBudget(): void {
        $this->service->setNow(new \DateTime('2026-03-15'));
        $this->settingService->method('get')->willReturn('1');

        $category = $this->makeCategory(['budgetAmount' => 0.0]);
        $this->recurringBudgets = []; // no recurring commitment either
        $this->setupMocksForBudgetStatus([$category], 90.0);

        $this->assertCount(0, $this->service->getBudgetStatus(self::USER_ID));
    }

    // ===== Default behavior (start_day=1) =====

    public function testDefaultStartDayProducesCalendarMonth(): void {
        $status = $this->getMonthlyPeriod('1', '2026-03-15');

        $this->assertEquals('March 2026', $status['periodLabel']);
    }

    public function testDefaultStartDayNullSetting(): void {
        $this->service->setNow(new \DateTime('2026-03-15'));

        $this->settingService->method('get')
            ->willReturnCallback(fn($u, $key) => null);

        $category = $this->makeCategory();
        $this->setupMocksForBudgetStatus([$category]);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);
        $this->assertCount(1, $statuses);
        $this->assertEquals('March 2026', $statuses[0]['periodLabel']);
    }

    // ===== Which month's budgets apply (custom start day) =====

    /**
     * The current period's budgets are the ones the Budget page shows for
     * it: the month holding the period's 15th, not today's calendar month.
     */
    public function testEarlyStartDayUsesLastMonthsBudgetsBeforeTheStartDay(): void {
        // Start day 10, 5 Sep: the period is 10 Aug - 9 Sep, i.e. August
        $status = $this->getMonthlyPeriod('10', '2026-09-05');

        $this->assertStringContainsString('Aug 10', $status['periodLabel']);
        $this->assertSame(['snapshot 2026-08', 'carryover 2026-08'], $this->budgetMonthsAsked);
    }

    public function testLateStartDayUsesNextMonthsBudgetsAfterTheStartDay(): void {
        // Start day 28, 29 Sep: the period is 28 Sep - 27 Oct, i.e. October
        $status = $this->getMonthlyPeriod('28', '2026-09-29');

        $this->assertStringContainsString('Sep 28', $status['periodLabel']);
        $this->assertSame(['snapshot 2026-10', 'carryover 2026-10'], $this->budgetMonthsAsked);
    }

    public function testAlertsUseTheCurrentBudgetMonthsBudgets(): void {
        $this->service->setNow(new \DateTime('2026-09-05'));
        $this->settingService->method('get')
            ->willReturnCallback(fn($u, $key) => $key === 'budget_start_day' ? '10' : null);
        $this->setupMocksForBudgetStatus([$this->makeCategory()], 0.0);

        $this->service->getAlerts(self::USER_ID);

        $this->assertSame(['snapshot 2026-08', 'carryover 2026-08'], $this->budgetMonthsAsked);
    }

    public function testStartDayOneUsesTheCalendarMonthsBudgets(): void {
        $this->getMonthlyPeriod('1', '2026-09-30');

        $this->assertSame(['snapshot 2026-09', 'carryover 2026-09'], $this->budgetMonthsAsked);
    }

    // ===== Mid-month start day =====

    public function testStartDay15AfterStartDay(): void {
        $status = $this->getMonthlyPeriod('15', '2026-03-20');

        // On March 20 with start_day=15: period is Mar 15 – Apr 14
        $this->assertStringContainsString('Mar 15', $status['periodLabel']);
        $this->assertStringContainsString('Apr 14', $status['periodLabel']);
    }

    public function testStartDay15BeforeStartDay(): void {
        $status = $this->getMonthlyPeriod('15', '2026-03-10');

        // On March 10 with start_day=15: period is Feb 15 – Mar 14
        $this->assertStringContainsString('Feb 15', $status['periodLabel']);
        $this->assertStringContainsString('Mar 14', $status['periodLabel']);
    }

    public function testStartDay25OnExactStartDay(): void {
        $status = $this->getMonthlyPeriod('25', '2026-03-25');

        // On March 25 with start_day=25: period starts Mar 25
        $this->assertStringContainsString('Mar 25', $status['periodLabel']);
        $this->assertStringContainsString('Apr 24', $status['periodLabel']);
    }

    // ===== End-of-month clamping (start_day=31) =====

    public function testStartDay31InMarch(): void {
        $status = $this->getMonthlyPeriod('31', '2026-03-31');

        // March has 31 days, so start is Mar 31. Next month (April) has 30 days, clamp to 30.
        // Period: Mar 31 – Apr 29
        $this->assertStringContainsString('Mar 31', $status['periodLabel']);
        $this->assertStringContainsString('Apr 29', $status['periodLabel']);
    }

    public function testStartDay31InFebruary(): void {
        $status = $this->getMonthlyPeriod('31', '2026-02-15');

        // Feb 2026 has 28 days. On Feb 15 (before 28), period started last month.
        // Jan has 31 days, so start is Jan 31. End is Feb 27 (day before Feb 28).
        $this->assertStringContainsString('Jan 31', $status['periodLabel']);
        $this->assertStringContainsString('Feb 27', $status['periodLabel']);
    }

    public function testStartDay31InFebruaryAfterClampedDay(): void {
        $status = $this->getMonthlyPeriod('31', '2026-02-28');

        // Feb 28 >= clamped start (28), so period starts Feb 28.
        // Next month March has 31 days, so next start is Mar 31. End = Mar 30.
        $this->assertStringContainsString('Feb 28', $status['periodLabel']);
        $this->assertStringContainsString('Mar 30', $status['periodLabel']);
    }

    // ===== start_day=30 clamping in February =====

    public function testStartDay30InFebruary(): void {
        $status = $this->getMonthlyPeriod('30', '2026-02-15');

        // Feb has 28 days. On Feb 15 (before 28), period started last month.
        // Jan has 31 days, start clamps to 30. End = Feb 27.
        $this->assertStringContainsString('Jan 30', $status['periodLabel']);
        $this->assertStringContainsString('Feb 27', $status['periodLabel']);
    }

    // ===== Leap year =====

    public function testStartDay29InLeapYearFebruary(): void {
        $status = $this->getMonthlyPeriod('29', '2028-02-29');

        // 2028 is a leap year, Feb has 29 days. Feb 29 >= 29, so period starts Feb 29.
        // March has 31 days, next start = Mar 29. End = Mar 28.
        $this->assertStringContainsString('Feb 29', $status['periodLabel']);
        $this->assertStringContainsString('Mar 28', $status['periodLabel']);
    }

    public function testStartDay29InNonLeapYearFebruary(): void {
        $status = $this->getMonthlyPeriod('29', '2026-02-28');

        // 2026 is not a leap year, Feb has 28 days. 28 >= clamped 28, so period starts Feb 28.
        // March has 31 days, next start = Mar 29. End = Mar 28.
        $this->assertStringContainsString('Feb 28', $status['periodLabel']);
        $this->assertStringContainsString('Mar 28', $status['periodLabel']);
    }

    // ===== Year boundary =====

    public function testStartDay25DecemberToJanuary(): void {
        $status = $this->getMonthlyPeriod('25', '2026-01-10');

        // Jan 10 < 25, so period started last month (Dec).
        // Dec has 31 days, start = Dec 25. End = Jan 24.
        $this->assertStringContainsString('Dec 25', $status['periodLabel']);
        $this->assertStringContainsString('Jan 24', $status['periodLabel']);
    }

    public function testStartDay25InDecember(): void {
        $status = $this->getMonthlyPeriod('25', '2026-12-28');

        // Dec 28 >= 25, so period starts Dec 25.
        // Next month is Jan (next year). End = Jan 24.
        $this->assertStringContainsString('Dec 25', $status['periodLabel']);
        $this->assertStringContainsString('Jan 24', $status['periodLabel']);
    }

    // ===== Non-monthly periods unaffected =====

    public function testWeeklyPeriodUnaffectedByStartDay(): void {
        $this->service->setNow(new \DateTime('2026-03-04')); // Wednesday

        $this->settingService->method('get')
            ->willReturnCallback(fn($u, $key) => $key === 'budget_start_day' ? '25' : null);

        $category = $this->makeCategory(['budgetPeriod' => 'weekly']);
        $this->setupMocksForBudgetStatus([$category]);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);
        $this->assertCount(1, $statuses);
        $this->assertStringContainsString('Week of', $statuses[0]['periodLabel']);
    }

    public function testYearlyPeriodUnaffectedByStartDay(): void {
        $this->service->setNow(new \DateTime('2026-06-15'));

        $this->settingService->method('get')
            ->willReturnCallback(fn($u, $key) => $key === 'budget_start_day' ? '25' : null);

        $category = $this->makeCategory(['budgetPeriod' => 'yearly']);
        $this->setupMocksForBudgetStatus([$category]);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);
        $this->assertCount(1, $statuses);
        $this->assertEquals('2026', $statuses[0]['periodLabel']);
    }

    // ===== Alert threshold integration =====

    public function testAlertsRespectStartDay(): void {
        $this->service->setNow(new \DateTime('2026-03-04'));

        $this->settingService->method('get')
            ->willReturnCallback(fn($u, $key) => $key === 'budget_start_day' ? '25' : null);

        $category = $this->makeCategory(['budgetAmount' => 100.0]);

        $this->categoryMapper->method('findAll')
            ->with(self::USER_ID)
            ->willReturn([$category]);

        // Spending is 90 out of 100 = 90% (warning threshold)
        $this->transactionMapper->method('getCategorySpendingBatch')->willReturn([1 => 90.0]);

        $alerts = $this->service->getAlerts(self::USER_ID);
        $this->assertCount(1, $alerts);
        $this->assertEquals('warning', $alerts[0]['severity']);
        $this->assertStringContainsString('Feb 25', $alerts[0]['periodLabel']);
        $this->assertStringContainsString('Mar 24', $alerts[0]['periodLabel']);
        $this->assertEquals('2026-02-25', $alerts[0]['periodStart']);
        $this->assertEquals('2026-03-24', $alerts[0]['periodEnd']);
    }

    public function testNotifiesWhenACategoryCrossesTheWarningThreshold(): void {
        $service = $this->makeNotifyingService(100.0);
        $this->spend = 90.0; // 90% — warning

        $sent = $service->notifyAlerts(self::USER_ID);

        $this->assertSame(1, $sent);
        $this->assertSame('Groceries', $this->sent[0]['categoryName']);
        $this->assertSame('warning', $this->sent[0]['severity']);
    }

    public function testDoesNotNotifyForACategoryUnderTheThreshold(): void {
        $service = $this->makeNotifyingService(100.0);
        $this->spend = 50.0;

        $this->assertSame(0, $service->notifyAlerts(self::USER_ID));
        $this->assertSame([], $this->sent);
    }

    public function testDoesNotNotifyTwiceForTheSameCategoryAndPeriod(): void {
        $service = $this->makeNotifyingService(100.0);
        $this->spend = 90.0;

        $service->notifyAlerts(self::USER_ID);
        $second = $service->notifyAlerts(self::USER_ID);

        $this->assertSame(0, $second);
        $this->assertCount(1, $this->sent);
    }

    /**
     * Crossing the budget outright is news even after a warning was already
     * sent for the same category in the same period.
     */
    public function testNotifiesAgainWhenAWarningEscalatesToDanger(): void {
        $service = $this->makeNotifyingService(100.0);
        $this->spend = 90.0;
        $service->notifyAlerts(self::USER_ID);

        $this->spend = 130.0; // now over budget
        $second = $service->notifyAlerts(self::USER_ID);

        $this->assertSame(1, $second);
        $this->assertCount(2, $this->sent);
        $this->assertSame('danger', $this->sent[1]['severity']);
    }

    public function testSuppressionStateIsPersistedPerCategoryAndPeriod(): void {
        $service = $this->makeNotifyingService(100.0);
        $this->spend = 90.0;

        $service->notifyAlerts(self::USER_ID);

        $this->assertArrayHasKey('budget_alert_notified', $this->settings);
        $stored = json_decode($this->settings['budget_alert_notified'], true);
        $this->assertStringStartsWith('warning:', $stored['1']);
    }

    // ===== Which categories may alert (#389) =====

    /**
     * Seed the settings the service reads; anything left out falls back to its
     * default, the way an unset setting does in production.
     */
    private function seedSettings(array $map): void {
        $this->settingService->method('get')
            ->willReturnCallback(fn(string $userId, string $key) => $map[$key] ?? null);
    }

    /**
     * Since #269 a category with no budget of its own falls back to the amount
     * its bills and recurring income commit it to. That default stands: alerts
     * cover derived budgets unless the user asks for something narrower.
     */
    public function testDerivedBudgetAlertsByDefault(): void {
        $category = $this->makeCategory(['budgetAmount' => 0.0]);
        $this->recurringBudgets = [1 => 56.03];
        $this->setupMocksForBudgetStatus([$category], 190.0);

        $alerts = $this->service->getAlerts(self::USER_ID);

        $this->assertCount(1, $alerts);
        $this->assertSame('danger', $alerts[0]['severity']);
    }

    public function testDerivedBudgetDoesNotAlertWhenOnlyOwnBudgetsWanted(): void {
        $this->seedSettings(['budget_alert_scope' => 'manual']);
        $category = $this->makeCategory(['budgetAmount' => 0.0]);
        $this->recurringBudgets = [1 => 56.03];
        $this->setupMocksForBudgetStatus([$category], 190.0);

        $this->assertSame([], $this->service->getAlerts(self::USER_ID));
    }

    public function testOwnBudgetStillAlertsWhenOnlyOwnBudgetsWanted(): void {
        $this->seedSettings(['budget_alert_scope' => 'manual']);
        $category = $this->makeCategory(['budgetAmount' => 100.0]);
        $this->setupMocksForBudgetStatus([$category], 120.0);

        $alerts = $this->service->getAlerts(self::USER_ID);

        $this->assertCount(1, $alerts);
        $this->assertSame('danger', $alerts[0]['severity']);
    }

    /**
     * A figure typed into the Budget view for this month lands in a snapshot
     * rather than on the category, and is still a budget the user set.
     */
    public function testSnapshotBudgetCountsAsOneTheUserSet(): void {
        $this->seedSettings(['budget_alert_scope' => 'manual']);
        $category = $this->makeCategory(['budgetAmount' => 0.0]);
        $this->snapshots = [1 => ['amount' => 100.0, 'period' => 'monthly']];
        $this->setupMocksForBudgetStatus([$category], 120.0);

        $this->assertCount(1, $this->service->getAlerts(self::USER_ID));
    }

    public function testMutedCategoryIsDroppedFromAlerts(): void {
        $this->seedSettings(['budget_alert_muted_categories' => '[1]']);
        $muted = $this->makeCategory(['id' => 1, 'name' => 'EKZ', 'budgetAmount' => 100.0]);
        $other = $this->makeCategory(['id' => 2, 'name' => 'Groceries', 'budgetAmount' => 100.0]);
        $this->setupMocksForBudgetStatus([$muted, $other], 120.0);

        $alerts = $this->service->getAlerts(self::USER_ID);

        $this->assertCount(1, $alerts);
        $this->assertSame(2, $alerts[0]['categoryId']);
    }

    /**
     * Muting governs the alerts tile and the notifications it drives. The
     * budget figures the Budget view, the Nextcloud dashboard panel and the
     * digest add up must not move because a row was silenced.
     */
    public function testMutingAndScopeLeaveBudgetStatusAlone(): void {
        $this->seedSettings([
            'budget_alert_scope' => 'manual',
            'budget_alert_muted_categories' => '[1]',
        ]);
        $muted = $this->makeCategory(['id' => 1, 'name' => 'EKZ', 'budgetAmount' => 100.0]);
        $derived = $this->makeCategory(['id' => 2, 'name' => 'Taxes', 'budgetAmount' => 0.0]);
        $this->recurringBudgets = [2 => 345.17];
        $this->setupMocksForBudgetStatus([$muted, $derived], 120.0);

        $this->assertCount(2, $this->service->getBudgetStatus(self::USER_ID));
    }

    public function testBudgetStatusReportsWhereEachBudgetCameFrom(): void {
        $own = $this->makeCategory(['id' => 1, 'name' => 'Groceries', 'budgetAmount' => 100.0]);
        $derived = $this->makeCategory(['id' => 2, 'name' => 'EKZ', 'budgetAmount' => 0.0]);
        $this->recurringBudgets = [2 => 56.03];
        $this->setupMocksForBudgetStatus([$own, $derived], 10.0);

        $sources = [];
        foreach ($this->service->getBudgetStatus(self::USER_ID) as $status) {
            $sources[$status['categoryId']] = $status['budgetSource'];
        }

        $this->assertSame(['manual', 'recurring'], [$sources[1], $sources[2]]);
    }

    public function testMalformedMutedCategorySettingIsIgnored(): void {
        $this->seedSettings(['budget_alert_muted_categories' => 'not json']);
        $category = $this->makeCategory(['budgetAmount' => 100.0]);
        $this->setupMocksForBudgetStatus([$category], 120.0);

        $this->assertCount(1, $this->service->getAlerts(self::USER_ID));
    }

    public function testMutedCategoryIsNotNotified(): void {
        $this->settings['budget_alert_muted_categories'] = '[1]';
        $service = $this->makeNotifyingService(100.0);
        $this->spend = 130.0;

        $this->assertSame(0, $service->notifyAlerts(self::USER_ID));
        $this->assertSame([], $this->sent);
    }
}
