<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\SettingService;
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

    private const USER_ID = 'testuser';

    protected function setUp(): void {
        $this->categoryMapper = $this->createMock(CategoryMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->splitMapper = $this->createMock(TransactionSplitMapper::class);
        $this->settingService = $this->createMock(SettingService::class);

        $this->service = new TestableBudgetAlertService(
            $this->categoryMapper,
            $this->transactionMapper,
            $this->splitMapper,
            $this->settingService
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

        return $cat;
    }

    private function setupMocksForBudgetStatus(array $categories, float $spending = 0.0): void {
        $this->categoryMapper->method('findAll')
            ->with(self::USER_ID)
            ->willReturn($categories);

        $this->transactionMapper->method('getCategorySpending')
            ->willReturn($spending);

        $this->transactionMapper->method('getSplitTransactionIds')
            ->willReturn([]);
    }

    /**
     * Helper to get the monthly period range from getBudgetStatus response.
     */
    private function getMonthlyPeriod(string $startDaySetting, string $fakeDate): array {
        $this->service->setNow(new \DateTime($fakeDate));

        $this->settingService->method('get')
            ->with(self::USER_ID, 'budget_start_day')
            ->willReturn($startDaySetting);

        $category = $this->makeCategory();
        $this->setupMocksForBudgetStatus([$category]);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);
        $this->assertCount(1, $statuses);

        return $statuses[0];
    }

    // ===== Default behavior (start_day=1) =====

    public function testDefaultStartDayProducesCalendarMonth(): void {
        $status = $this->getMonthlyPeriod('1', '2026-03-15');

        $this->assertEquals('March 2026', $status['periodLabel']);
    }

    public function testDefaultStartDayNullSetting(): void {
        $this->service->setNow(new \DateTime('2026-03-15'));

        $this->settingService->method('get')
            ->with(self::USER_ID, 'budget_start_day')
            ->willReturn(null);

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
            ->with(self::USER_ID, 'budget_start_day')
            ->willReturn('25');

        $category = $this->makeCategory(['budgetPeriod' => 'weekly']);
        $this->setupMocksForBudgetStatus([$category]);

        $statuses = $this->service->getBudgetStatus(self::USER_ID);
        $this->assertCount(1, $statuses);
        $this->assertStringContainsString('Week of', $statuses[0]['periodLabel']);
    }

    public function testYearlyPeriodUnaffectedByStartDay(): void {
        $this->service->setNow(new \DateTime('2026-06-15'));

        $this->settingService->method('get')
            ->with(self::USER_ID, 'budget_start_day')
            ->willReturn('25');

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
            ->with(self::USER_ID, 'budget_start_day')
            ->willReturn('25');

        $category = $this->makeCategory(['budgetAmount' => 100.0]);

        $this->categoryMapper->method('findAll')
            ->with(self::USER_ID)
            ->willReturn([$category]);

        // Spending is 90 out of 100 = 90% (warning threshold)
        $this->transactionMapper->method('getCategorySpending')
            ->willReturn(90.0);

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
}
