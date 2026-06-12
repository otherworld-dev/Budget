<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Bill;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\RecurringBudgetService;
use OCA\Budget\Service\RecurringIncomeService;
use PHPUnit\Framework\TestCase;

class RecurringBudgetServiceTest extends TestCase {
    private RecurringBudgetService $service;
    private BillService $billService;
    private RecurringIncomeService $recurringIncomeService;

    protected function setUp(): void {
        $this->billService = $this->createMock(BillService::class);
        $this->recurringIncomeService = $this->createMock(RecurringIncomeService::class);
        // Real instance: pure math, and the point is shared frequency handling
        $this->service = new RecurringBudgetService(
            $this->billService,
            $this->recurringIncomeService,
            new FrequencyCalculator()
        );
    }

    private function makeBill(float $amount, string $frequency, ?int $categoryId, ?array $split = null, ?string $customPattern = null): Bill {
        $bill = new Bill();
        $bill->setAmount($amount);
        $bill->setFrequency($frequency);
        $bill->setCategoryId($categoryId);
        $bill->setCustomRecurrencePattern($customPattern);
        if ($split !== null) {
            $bill->setSplitTemplateArray($split);
        }
        return $bill;
    }

    private function makeIncome(float $amount, string $frequency, ?int $categoryId): RecurringIncome {
        $income = new RecurringIncome();
        $income->setAmount($amount);
        $income->setFrequency($frequency);
        $income->setCategoryId($categoryId);
        return $income;
    }

    public function testMonthlyBillSummedIntoCategory(): void {
        $this->billService->method('findActive')->willReturn([
            $this->makeBill(100.0, 'monthly', 5),
        ]);
        $this->recurringIncomeService->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertSame(100.0, $result[5]);
    }

    public function testYearlyAndWeeklyNormalizedToMonthly(): void {
        $this->billService->method('findActive')->willReturn([
            $this->makeBill(1200.0, 'yearly', 5),  // 1200/12 = 100/mo
            $this->makeBill(10.0, 'weekly', 6),    // 10 * 52/12 ≈ 43.33/mo
        ]);
        $this->recurringIncomeService->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertSame(100.0, $result[5]);
        $this->assertSame(round(10.0 * 52 / 12, 2), $result[6]);
    }

    public function testSplitBillDistributesToSplitCategories(): void {
        $bill = $this->makeBill(0.0, 'monthly', null, [
            ['categoryId' => 7, 'amount' => 30.0],
            ['categoryId' => 8, 'amount' => 20.0],
        ]);
        $this->billService->method('findActive')->willReturn([$bill]);
        $this->recurringIncomeService->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertSame(30.0, $result[7]);
        $this->assertSame(20.0, $result[8]);
    }

    public function testIncomeSummedAndCombinedWithBills(): void {
        $this->billService->method('findActive')->willReturn([
            $this->makeBill(50.0, 'monthly', 5),
        ]);
        $this->recurringIncomeService->method('findActive')->willReturn([
            $this->makeIncome(2000.0, 'monthly', 9),
            $this->makeIncome(50.0, 'monthly', 5), // same category as the bill -> combined
        ]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertSame(100.0, $result[5]);
        $this->assertSame(2000.0, $result[9]);
    }

    public function testSemiAnnualAndSemiMonthlyNormalized(): void {
        $this->billService->method('findActive')->willReturn([
            $this->makeBill(600.0, 'semi-annually', 5),  // 600/6 = 100/mo
            $this->makeBill(50.0, 'semi-monthly', 6),    // 50*2 = 100/mo
        ]);
        $this->recurringIncomeService->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertSame(100.0, $result[5]);
        $this->assertSame(100.0, $result[6]);
    }

    public function testOneTimeBillsExcluded(): void {
        // A one-off is not a recurring commitment — counting it monthly until
        // marked paid would inflate the derived budget.
        $this->billService->method('findActive')->willReturn([
            $this->makeBill(3000.0, 'one-time', 5),
        ]);
        $this->recurringIncomeService->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertArrayNotHasKey(5, $result);
    }

    public function testCustomPatternUsesOccurrencesPerYear(): void {
        // Custom bill due in 2 specific months: 300 * 2 / 12 = 50/mo
        $this->billService->method('findActive')->willReturn([
            $this->makeBill(300.0, 'custom', 5, null, json_encode(['months' => [3, 9]])),
        ]);
        $this->recurringIncomeService->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertSame(50.0, $result[5]);
    }

    public function testFutureStartAndEndedBillsExcluded(): void {
        $future = $this->makeBill(100.0, 'monthly', 5);
        $future->setStartDate(date('Y-m-d', strtotime('+2 months')));
        $ended = $this->makeBill(100.0, 'monthly', 6);
        $ended->setEndDate(date('Y-m-d', strtotime('-1 month')));
        $current = $this->makeBill(100.0, 'monthly', 7);

        $this->billService->method('findActive')->willReturn([$future, $ended, $current]);
        $this->recurringIncomeService->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertArrayNotHasKey(5, $result);
        $this->assertArrayNotHasKey(6, $result);
        $this->assertSame(100.0, $result[7]);
    }

    public function testConvertMonthlyToPeriod(): void {
        $this->assertSame(100.0, $this->service->convertMonthlyToPeriod(100.0, 'monthly'));
        $this->assertEqualsWithDelta(23.08, $this->service->convertMonthlyToPeriod(100.0, 'weekly'), 0.01);
        $this->assertSame(300.0, $this->service->convertMonthlyToPeriod(100.0, 'quarterly'));
        $this->assertSame(1200.0, $this->service->convertMonthlyToPeriod(100.0, 'yearly'));
    }

    public function testBillWithoutCategoryIgnored(): void {
        $this->billService->method('findActive')->willReturn([
            $this->makeBill(100.0, 'monthly', null),
        ]);
        $this->recurringIncomeService->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlyBudgetsByCategory('user1');

        $this->assertSame([], $result);
    }
}
