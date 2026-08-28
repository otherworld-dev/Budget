<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Service\YearOverYearService;
use PHPUnit\Framework\TestCase;

class YearOverYearServiceTest extends TestCase {
    private YearOverYearService $service;
    private TransactionMapper $transactionMapper;
    private CategoryMapper $categoryMapper;
    private TransactionSplitMapper $splitMapper;

    protected function setUp(): void {
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->categoryMapper = $this->createMock(CategoryMapper::class);
        $this->splitMapper = $this->createMock(TransactionSplitMapper::class);

        // No splits unless a test says otherwise.
        $this->transactionMapper->method('getSplitTransactionIds')->willReturn([]);
        $this->splitMapper->method('getCategoryTotals')->willReturn([]);

        $this->service = new YearOverYearService(
            $this->transactionMapper,
            $this->categoryMapper,
            $this->splitMapper
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

    /**
     * Year over Year read a category's own transactions only, so a year whose
     * groceries came off split receipts compared as a collapse against a year
     * that predated splitting. Everything else has counted split allocations
     * since #359 (#360).
     */
    public function testCompareCategorySpendingCountsSplitAllocations(): void {
        $expense = $this->makeCategory(1, 'Food', 'expense');
        $this->categoryMapper->method('findAll')->willReturn([$expense]);
        $this->transactionMapper->method('getCategorySpending')->willReturn(100.0);

        $currentYear = (int) date('Y');
        $splitMapper = $this->createMock(TransactionSplitMapper::class);
        $transactionMapper = $this->createMock(TransactionMapper::class);
        $transactionMapper->method('getCategorySpending')->willReturn(100.0);
        $transactionMapper->method('getSplitTransactionIds')
            ->willReturnCallback(static fn(string $u, string $start): array =>
                (int)substr($start, 0, 4) === $currentYear ? [7, 8] : []);
        $splitMapper->method('getCategoryTotals')->willReturn([1 => 40.0]);

        $service = new YearOverYearService($transactionMapper, $this->categoryMapper, $splitMapper);
        $result = $service->compareCategorySpending('user1', 2);

        $years = $result['categories'][0]['years'];
        // This year: 100 on the transactions themselves plus 40 through splits.
        $this->assertEqualsWithDelta(140.0, $years[0]['spending'], 0.005);
        // Last year had no splits, so it is untouched.
        $this->assertEqualsWithDelta(100.0, $years[1]['spending'], 0.005);
    }

    /**
     * A split parent whose is_split flag predates the column (NULL) must not
     * have its own amount counted on top of its parts. getCategorySpending()
     * now partitions on the same is_split=false OR NOT hasSplitPartsExpr rule
     * as getCategorySpendingBatch(), so a NULL-flagged row with parts is left
     * out of the direct total entirely and answered only by the split query
     * below — before that partition existed, the direct query took every
     * NULL-flagged row whether or not it had parts, double-counting it (#360).
     */
    public function testCompareCategorySpendingExcludesNullFlagSplitParentOwnAmount(): void {
        $expense = $this->makeCategory(1, 'Food', 'expense');
        $this->categoryMapper->method('findAll')->willReturn([$expense]);

        $currentYear = (int) date('Y');

        $transactionMapper = $this->createMock(TransactionMapper::class);
        // The (now-partitioned) direct total: just the plain £60 transaction.
        // A NULL-flagged split parent with parts is excluded here — it is
        // left entirely to the split query below.
        $transactionMapper->method('getCategorySpending')
            ->willReturnCallback(static fn(string $u, int $catId, string $start): float =>
                (int)substr($start, 0, 4) === $currentYear ? 60.0 : 0.0);
        $transactionMapper->method('getSplitTransactionIds')
            ->willReturnCallback(static fn(string $u, string $start): array =>
                (int)substr($start, 0, 4) === $currentYear ? [7] : []);

        $splitMapper = $this->createMock(TransactionSplitMapper::class);
        $splitMapper->method('getCategoryTotals')->willReturn([1 => 25.0]);

        $service = new YearOverYearService($transactionMapper, $this->categoryMapper, $splitMapper);
        $result = $service->compareCategorySpending('user1', 2);

        $years = $result['categories'][0]['years'];
        // 60 direct + 25 of the split parent's parts. If the direct query
        // still counted the NULL-flagged parent's own amount (its bug
        // pre-fix), this would read 60 + 40 + 25 = 125 instead.
        $this->assertEqualsWithDelta(85.0, $years[0]['spending'], 0.005);
        $this->assertEqualsWithDelta(0.0, $years[1]['spending'], 0.005);
    }

    /**
     * yearRange() is the single authority for a comparison year's window.
     * The split-totals pass and the per-category direct pass must cover the
     * same dates — the current year capped at today, past years whole — or
     * a category's split allocations are counted over a different window
     * than its direct spending.
     */
    public function testCompareCategorySpendingUsesOneWindowForDirectAndSplitPasses(): void {
        $expense = $this->makeCategory(1, 'Food', 'expense');
        $this->categoryMapper->method('findAll')->willReturn([$expense]);

        $splitWindows = [];
        $directWindows = [];

        $transactionMapper = $this->createMock(TransactionMapper::class);
        $transactionMapper->method('getSplitTransactionIds')
            ->willReturnCallback(static function (string $u, string $start, string $end, ...$rest) use (&$splitWindows): array {
                $splitWindows[] = [$start, $end];
                return [];
            });
        $transactionMapper->method('getCategorySpending')
            ->willReturnCallback(static function (string $u, int $catId, string $start, string $end, ...$rest) use (&$directWindows): float {
                $directWindows[] = [$start, $end];
                return 0.0;
            });

        $splitMapper = $this->createMock(TransactionSplitMapper::class);
        $splitMapper->method('getCategoryTotals')->willReturn([]);

        $service = new YearOverYearService($transactionMapper, $this->categoryMapper, $splitMapper);
        $service->compareCategorySpending('user1', 2);

        $this->assertSame($splitWindows, $directWindows);
        // The year in progress ends today, not on Dec 31 …
        $this->assertSame([date('Y') . '-01-01', date('Y-m-d')], $splitWindows[0]);
        // … and the completed year is whole.
        $lastYear = (int) date('Y') - 1;
        $this->assertSame(["$lastYear-01-01", "$lastYear-12-31"], $splitWindows[1]);
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
