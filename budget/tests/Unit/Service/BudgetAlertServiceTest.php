<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
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
    private TransactionSplitMapper $splitMapper;
    private SettingService $settingService;
    /** @var array<int, float> Recurring budgets returned by the mock */
    private array $recurringBudgets = [];
    private array $carryovers = [];
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
        $this->splitMapper = $this->createMock(TransactionSplitMapper::class);
        $this->settingService = $this->createMock(SettingService::class);

        $budgetSnapshotMapper = $this->createMock(BudgetSnapshotMapper::class);

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
            ->willReturnCallback(fn() => $this->carryovers);

        $this->service = new TestableBudgetAlertService(
            $this->categoryMapper,
            $budgetSnapshotMapper,
            $this->transactionMapper,
            $this->splitMapper,
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
        $transactionMapper->method('getCategorySpending')
            ->willReturnCallback(fn(...$args): float => ($args[6] ?? 'debit') === 'debit' ? $this->spend : 0.0);
        $transactionMapper->method('getSplitTransactionIds')->willReturn([]);

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
            $this->createMock(TransactionSplitMapper::class),
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

        $this->transactionMapper->method('getCategorySpending')
            ->willReturnCallback(
                static fn(...$args): float => ($args[6] ?? 'debit') === 'debit' ? 216.90 : 158.61
            );
        $this->transactionMapper->method('getSplitTransactionIds')
            ->willReturn([]);

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

        $this->transactionMapper->method('getCategorySpending')
            ->willReturnCallback(
                static fn(...$args): float => ($args[6] ?? 'debit') === 'debit' ? $spending : 0.0
            );

        $this->transactionMapper->method('getSplitTransactionIds')
            ->willReturn([]);
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

        // Spending is 90 out of 100 = 90% (warning threshold). Money out only:
        // the figure is net, so answering 90 to the credit direction too would
        // cancel it to zero (#361).
        $this->transactionMapper->method('getCategorySpending')
            ->willReturnCallback(
                static fn(...$args): float => ($args[6] ?? 'debit') === 'debit' ? 90.0 : 0.0
            );

        $this->transactionMapper->method('getSplitTransactionIds')
            ->willReturn([]);

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
}
