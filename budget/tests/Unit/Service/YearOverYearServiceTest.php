<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\YearOverYearService;
use PHPUnit\Framework\TestCase;

class YearOverYearServiceTest extends TestCase {
    private YearOverYearService $service;
    private TransactionMapper $transactionMapper;
    private CategoryMapper $categoryMapper;

    protected function setUp(): void {
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->categoryMapper = $this->createMock(CategoryMapper::class);

        $this->service = new YearOverYearService(
            $this->transactionMapper,
            $this->categoryMapper
        );
    }

    private function makeTransaction(string $date, float $amount, string $type = 'debit'): Transaction {
        $tx = new Transaction();
        $tx->setDate($date);
        $tx->setAmount($amount);
        $tx->setType($type);
        return $tx;
    }

    private function makeCategory(int $id, string $name, string $type = 'expense'): Category {
        $cat = new Category();
        $cat->setId($id);
        $cat->setName($name);
        $cat->setType($type);
        return $cat;
    }

    // ===== compareMonth =====

    public function testCompareMonthReturnsMultipleYears(): void {
        $this->transactionMapper->method('findAllByUserAndDateRange')
            ->willReturn([]);

        $result = $this->service->compareMonth('user1', 3, 3);

        $this->assertEquals('month', $result['type']);
        $this->assertEquals(3, $result['month']);
        $this->assertCount(3, $result['years']);
    }

    public function testCompareMonthCalculatesIncomeAndExpenses(): void {
        $currentYear = (int) date('Y');

        $this->transactionMapper->method('findAllByUserAndDateRange')
            ->willReturnCallback(function ($userId, $start, $end) use ($currentYear) {
                $year = (int) substr($start, 0, 4);
                if ($year === $currentYear) {
                    return [
                        $this->makeTransaction("$currentYear-03-01", 5000.0, 'credit'),
                        $this->makeTransaction("$currentYear-03-15", 2000.0, 'debit'),
                    ];
                }
                return [
                    $this->makeTransaction(($currentYear - 1) . '-03-01', 4000.0, 'credit'),
                    $this->makeTransaction(($currentYear - 1) . '-03-15', 1500.0, 'debit'),
                ];
            });

        $result = $this->service->compareMonth('user1', 3, 2);

        $this->assertEquals(5000.0, $result['years'][0]['income']);
        $this->assertEquals(2000.0, $result['years'][0]['expenses']);
        $this->assertEquals(3000.0, $result['years'][0]['savings']);
    }

    public function testCompareMonthCalculatesPercentChanges(): void {
        $currentYear = (int) date('Y');

        $this->transactionMapper->method('findAllByUserAndDateRange')
            ->willReturnCallback(function ($userId, $start) use ($currentYear) {
                $year = (int) substr($start, 0, 4);
                if ($year === $currentYear) {
                    return [
                        $this->makeTransaction("$currentYear-03-01", 5000.0, 'credit'),
                        $this->makeTransaction("$currentYear-03-15", 2200.0, 'debit'),
                    ];
                }
                return [
                    $this->makeTransaction(($currentYear - 1) . '-03-01', 4000.0, 'credit'),
                    $this->makeTransaction(($currentYear - 1) . '-03-15', 2000.0, 'debit'),
                ];
            });

        $result = $this->service->compareMonth('user1', 3, 2);

        // Income: (5000-4000)/4000 * 100 = 25.0%
        $this->assertEquals(25.0, $result['years'][0]['incomeChange']);
        // Expenses: (2200-2000)/2000 * 100 = 10.0%
        $this->assertEquals(10.0, $result['years'][0]['expenseChange']);
    }

    // ===== compareYears =====

    public function testCompareYearsReturnsYearData(): void {
        $this->transactionMapper->method('findAllByUserAndDateRange')
            ->willReturn([]);

        $result = $this->service->compareYears('user1', 2);

        $this->assertEquals('year', $result['type']);
        $this->assertCount(2, $result['years']);
        $this->assertTrue($result['years'][0]['isCurrent']);
        $this->assertFalse($result['years'][1]['isCurrent']);
    }

    public function testCompareYearsCalculatesAverages(): void {
        $currentYear = (int) date('Y');
        $lastYear = $currentYear - 1;

        // For last year, return transactions across 2 months
        $this->transactionMapper->method('findAllByUserAndDateRange')
            ->willReturnCallback(function ($userId, $start) use ($lastYear) {
                $year = (int) substr($start, 0, 4);
                if ($year === $lastYear) {
                    return [
                        $this->makeTransaction("$lastYear-01-15", 3000.0, 'credit'),
                        $this->makeTransaction("$lastYear-01-20", 1000.0, 'debit'),
                        $this->makeTransaction("$lastYear-02-15", 3000.0, 'credit'),
                        $this->makeTransaction("$lastYear-02-20", 1500.0, 'debit'),
                    ];
                }
                return [];
            });

        $result = $this->service->compareYears('user1', 2);

        $lastYearData = $result['years'][1];
        $this->assertEquals(6000.0, $lastYearData['income']);
        $this->assertEquals(2500.0, $lastYearData['expenses']);
        $this->assertEquals(2, $lastYearData['monthsWithData']);
        $this->assertEquals(3000.0, $lastYearData['avgMonthlyIncome']);
        $this->assertEquals(1250.0, $lastYearData['avgMonthlyExpenses']);
    }

    // ===== compareCategorySpending =====

    public function testCompareCategorySpendingFiltersExpenseCategories(): void {
        $expense = $this->makeCategory(1, 'Food', 'expense');
        $income = $this->makeCategory(2, 'Salary', 'income');

        $this->categoryMapper->method('findAll')->willReturn([$expense, $income]);
        $this->transactionMapper->method('getCategorySpending')->willReturn(500.0);

        $result = $this->service->compareCategorySpending('user1', 2);

        $this->assertEquals('category', $result['type']);
        // Only expense category
        $this->assertCount(1, $result['categories']);
        $this->assertEquals('Food', $result['categories'][0]['name']);
    }

    public function testCompareCategorySpendingCalculatesChange(): void {
        $expense = $this->makeCategory(1, 'Food', 'expense');

        $this->categoryMapper->method('findAll')->willReturn([$expense]);

        $currentYear = (int) date('Y');
        $this->transactionMapper->method('getCategorySpending')
            ->willReturnCallback(function ($userId, $catId, $start) use ($currentYear) {
                $year = (int) substr($start, 0, 4);
                return $year === $currentYear ? 600.0 : 500.0;
            });

        $result = $this->service->compareCategorySpending('user1', 2);

        // Change: (600-500)/500 * 100 = 20.0%
        $this->assertEquals(20.0, $result['categories'][0]['change']);
    }

    // ===== getMonthlyTrends =====

    public function testGetMonthlyTrendsReturnsTrendData(): void {
        $this->transactionMapper->method('findAllByUserAndDateRange')
            ->willReturn([]);

        $result = $this->service->getMonthlyTrends('user1', 1);

        $this->assertEquals('monthly_trends', $result['type']);
        $this->assertCount(1, $result['years']);
        $this->assertArrayHasKey('months', $result['years'][0]);
        $this->assertArrayHasKey('totalIncome', $result['years'][0]);
        $this->assertArrayHasKey('avgMonthlyIncome', $result['years'][0]);
    }

    // ===== calculatePercentChange edge cases =====

    public function testPercentChangeFromZeroPreviousReturns100(): void {
        $currentYear = (int) date('Y');
        $lastYear = $currentYear - 1;

        $this->transactionMapper->method('findAllByUserAndDateRange')
            ->willReturnCallback(function ($userId, $start) use ($currentYear) {
                $year = (int) substr($start, 0, 4);
                if ($year === $currentYear) {
                    return [$this->makeTransaction("$currentYear-03-01", 1000.0, 'credit')];
                }
                return []; // Zero income previous year
            });

        $result = $this->service->compareMonth('user1', 3, 2);

        // From 0 to positive = 100.0
        $this->assertEquals(100.0, $result['years'][0]['incomeChange']);
    }

    public function testPercentChangeFromZeroToZeroReturnsNull(): void {
        $this->transactionMapper->method('findAllByUserAndDateRange')
            ->willReturn([]);

        $result = $this->service->compareMonth('user1', 3, 2);

        // 0 to 0 = null
        $this->assertNull($result['years'][0]['incomeChange']);
    }
}
