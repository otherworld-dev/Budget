<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionReportQueries;
use OCA\Budget\Service\YearOverYearService;
use PHPUnit\Framework\TestCase;

class YearOverYearServiceTest extends TestCase {
	private YearOverYearService $service;
	private TransactionMapper $transactionMapper;
	private CategoryMapper $categoryMapper;
	private TransactionReportQueries $reportQueries;

	protected function setUp(): void {
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->reportQueries = $this->createMock(TransactionReportQueries::class);

		$this->service = new YearOverYearService(
			$this->transactionMapper,
			$this->categoryMapper,
			$this->reportQueries
		);
	}

	/** One month of report-scoped cash flow, as getCashFlowByMonth() returns it. */
	private function month(string $month, float $income, float $expenses, int $count = 1): array {
		return ['month' => $month, 'income' => $income, 'expenses' => $expenses, 'net' => $income - $expenses, 'count' => $count];
	}

	/**
	 * Stub getCashFlowByMonth() from a map of year => rows for that year.
	 *
	 * @param array<int, array[]> $rowsByYear
	 */
	private function cashFlowByYear(array $rowsByYear): void {
		$this->reportQueries->method('getCashFlowByMonth')
			->willReturnCallback(function (string $userId, ?int $accountId, string $start, string $end) use ($rowsByYear) {
				$rows = $rowsByYear[(int)substr($start, 0, 4)] ?? [];
				return array_values(array_filter(
					$rows,
					static fn (array $row) => $row['month'] >= substr($start, 0, 7) && $row['month'] <= substr($end, 0, 7)
				));
			});
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
		$this->cashFlowByYear([]);

		$result = $this->service->compareMonth('user1', 3, 3);

		$this->assertEquals('month', $result['type']);
		$this->assertEquals(3, $result['month']);
		$this->assertCount(3, $result['years']);
	}

	public function testCompareMonthCalculatesIncomeAndExpenses(): void {
		$currentYear = (int)date('Y');
		$this->cashFlowByYear([
			$currentYear => [$this->month("$currentYear-03", 5000.0, 2000.0, 2)],
			$currentYear - 1 => [$this->month(($currentYear - 1) . '-03', 4000.0, 1500.0, 2)],
		]);

		$result = $this->service->compareMonth('user1', 3, 2);

		$this->assertEquals(5000.0, $result['years'][0]['income']);
		$this->assertEquals(2000.0, $result['years'][0]['expenses']);
		$this->assertEquals(3000.0, $result['years'][0]['savings']);
		$this->assertSame(2, $result['years'][0]['transactionCount']);
	}

	public function testCompareMonthCalculatesPercentChanges(): void {
		$currentYear = (int)date('Y');
		$this->cashFlowByYear([
			$currentYear => [$this->month("$currentYear-03", 5000.0, 2200.0)],
			$currentYear - 1 => [$this->month(($currentYear - 1) . '-03', 4000.0, 2000.0)],
		]);

		$result = $this->service->compareMonth('user1', 3, 2);

		// Income: (5000-4000)/4000 * 100 = 25.0%
		$this->assertEquals(25.0, $result['years'][0]['incomeChange']);
		// Expenses: (2200-2000)/2000 * 100 = 10.0%
		$this->assertEquals(10.0, $result['years'][0]['expenseChange']);
	}

	/**
	 * The figures come from the report-scoped aggregate, not from summing
	 * every row in PHP: the all-accounts view asks it to drop transfers —
	 * both legs of a transfer used to count as income AND spending here —
	 * while a single account keeps its own legs.
	 */
	public function testTransfersAreLeftOutOfTheAllAccountsViewOnly(): void {
		$excludeTransfers = [];
		$this->reportQueries->method('getCashFlowByMonth')
			->willReturnCallback(function (string $u, ?int $acc, string $s, string $e, array $tags, bool $untagged, bool $exclude, ?array $visible) use (&$excludeTransfers) {
				$excludeTransfers[] = [$acc, $exclude, $visible];
				return [];
			});

		$this->service->compareMonth('user1', 3, 1, null, [4, 5]);
		$this->service->compareMonth('user1', 3, 1, 4, [4, 5]);

		$this->assertSame([[null, true, [4, 5]], [4, false, [4, 5]]], $excludeTransfers);
	}

	/**
	 * Money adds through MoneyCalculator (#274): 0.1 + 0.2 must not come back
	 * as 0.30000000000000004.
	 */
	public function testMonthlyTotalsAddWithoutFloatDrift(): void {
		$lastYear = (int)date('Y') - 1;
		$this->cashFlowByYear([
			$lastYear => [
				$this->month("$lastYear-01", 0.1, 0.1),
				$this->month("$lastYear-02", 0.2, 0.2),
			],
		]);

		$result = $this->service->compareYears('user1', 2);

		$this->assertSame(0.3, $result['years'][1]['income']);
		$this->assertSame(0.3, $result['years'][1]['expenses']);
		$this->assertSame(0.0, $result['years'][1]['savings']);
	}

	// ===== compareYears =====

	public function testCompareYearsReturnsYearData(): void {
		$this->cashFlowByYear([]);

		$result = $this->service->compareYears('user1', 2);

		$this->assertEquals('year', $result['type']);
		$this->assertCount(2, $result['years']);
		$this->assertTrue($result['years'][0]['isCurrent']);
		$this->assertFalse($result['years'][1]['isCurrent']);
	}

	public function testCompareYearsCalculatesAverages(): void {
		$lastYear = (int)date('Y') - 1;
		$this->cashFlowByYear([
			$lastYear => [
				$this->month("$lastYear-01", 3000.0, 1000.0, 2),
				$this->month("$lastYear-02", 3000.0, 1500.0, 2),
			],
		]);

		$result = $this->service->compareYears('user1', 2);

		$lastYearData = $result['years'][1];
		$this->assertEquals(6000.0, $lastYearData['income']);
		$this->assertEquals(2500.0, $lastYearData['expenses']);
		$this->assertEquals(2, $lastYearData['monthsWithData']);
		$this->assertEquals(3000.0, $lastYearData['avgMonthlyIncome']);
		$this->assertEquals(1250.0, $lastYearData['avgMonthlyExpenses']);
		$this->assertSame(4, $lastYearData['transactionCount']);
	}

	public function testCompareYearsAsksForTheYearInProgressUpToToday(): void {
		$windows = [];
		$this->reportQueries->method('getCashFlowByMonth')
			->willReturnCallback(function (string $u, ?int $acc, string $start, string $end) use (&$windows) {
				$windows[] = [$start, $end];
				return [];
			});

		$this->service->compareYears('user1', 2);

		$lastYear = (int)date('Y') - 1;
		$this->assertSame([
			[date('Y') . '-01-01', date('Y-m-d')],
			["$lastYear-01-01", "$lastYear-12-31"],
		], $windows);
	}

	// ===== compareCategorySpending =====

	public function testCompareCategorySpendingFiltersExpenseCategories(): void {
		$expense = $this->makeCategory(1, 'Food', 'expense');
		$income = $this->makeCategory(2, 'Salary', 'income');

		$this->categoryMapper->method('findAll')->willReturn([$expense, $income]);
		$this->transactionMapper->method('getCategorySpendingBatch')->willReturn([1 => 500.0, 2 => 900.0]);

		$result = $this->service->compareCategorySpending('user1', 2);

		$this->assertEquals('category', $result['type']);
		// Only expense category
		$this->assertCount(1, $result['categories']);
		$this->assertEquals('Food', $result['categories'][0]['name']);
	}

	public function testCompareCategorySpendingCalculatesChange(): void {
		$this->categoryMapper->method('findAll')->willReturn([$this->makeCategory(1, 'Food', 'expense')]);

		$currentYear = (int)date('Y');
		$this->transactionMapper->method('getCategorySpendingBatch')
			->willReturnCallback(fn (array $ids, string $start) => [1 => (int)substr($start, 0, 4) === $currentYear ? 600.0 : 500.0]);

		$result = $this->service->compareCategorySpending('user1', 2);

		// Change: (600-500)/500 * 100 = 20.0%
		$this->assertEquals(20.0, $result['categories'][0]['change']);
	}

	/**
	 * One batch per year for every category — not a query per category per
	 * year — asking for split allocations (built into the batch, #360), the
	 * transfer exclusion of the all-accounts view (#349) and the report
	 * choke point for categories kept out of reports (#219), over the
	 * year's window: the year in progress up to today, a past year whole.
	 */
	public function testCompareCategorySpendingRunsOneBatchPerYear(): void {
		$this->categoryMapper->method('findAll')->willReturn([
			$this->makeCategory(1, 'Food'),
			$this->makeCategory(2, 'Rent'),
			$this->makeCategory(3, 'Salary', 'income'),
		]);
		$calls = [];
		$this->transactionMapper->expects($this->exactly(2))->method('getCategorySpendingBatch')
			->willReturnCallback(function (...$args) use (&$calls) {
				$calls[] = $args;
				return [1 => 10.0];
			});

		$this->service->compareCategorySpending('user1', 2, null, [7, 8]);

		$lastYear = (int)date('Y') - 1;
		$this->assertSame(
			[[1, 2], date('Y') . '-01-01', date('Y-m-d'), 'debit', null, true, 'user1', [7, 8], true],
			$calls[0]
		);
		$this->assertSame(["$lastYear-01-01", "$lastYear-12-31"], [$calls[1][1], $calls[1][2]]);
	}

	public function testCompareCategorySpendingKeepsTransfersForASingleAccount(): void {
		$this->categoryMapper->method('findAll')->willReturn([$this->makeCategory(1, 'Food')]);
		$calls = [];
		$this->transactionMapper->method('getCategorySpendingBatch')
			->willReturnCallback(function (...$args) use (&$calls) {
				$calls[] = $args;
				return [];
			});

		$this->service->compareCategorySpending('user1', 1, 4);

		$this->assertSame(4, $calls[0][4]);
		$this->assertFalse($calls[0][5]);
	}

	/**
	 * A category with nothing in any compared year has nothing to compare —
	 * which is also how one kept out of reports comes back from the batch —
	 * so it gets no row.
	 */
	public function testCompareCategorySpendingLeavesOutCategoriesWithNothingToCompare(): void {
		$this->categoryMapper->method('findAll')->willReturn([
			$this->makeCategory(1, 'Food'),
			$this->makeCategory(2, 'Hidden'),
		]);
		$currentYear = (int)date('Y');
		$this->transactionMapper->method('getCategorySpendingBatch')
			->willReturnCallback(fn (array $ids, string $start) => (int)substr($start, 0, 4) === $currentYear ? [1 => 0.0] : [1 => 40.0]);

		$result = $this->service->compareCategorySpending('user1', 2);

		$this->assertSame(['Food'], array_column($result['categories'], 'name'));
		$this->assertSame([0.0, 40.0], array_column($result['categories'][0]['years'], 'spending'));
	}

	public function testCompareCategorySpendingRunsNoQueryWithoutExpenseCategories(): void {
		$this->categoryMapper->method('findAll')->willReturn([$this->makeCategory(3, 'Salary', 'income')]);
		$this->transactionMapper->expects($this->never())->method('getCategorySpendingBatch');

		$this->assertSame([], $this->service->compareCategorySpending('user1', 2)['categories']);
	}

	// ===== getMonthlyTrends =====

	public function testGetMonthlyTrendsReturnsTrendData(): void {
		$this->cashFlowByYear([]);

		$result = $this->service->getMonthlyTrends('user1', 1);

		$this->assertEquals('monthly_trends', $result['type']);
		$this->assertCount(1, $result['years']);
		$this->assertArrayHasKey('months', $result['years'][0]);
		$this->assertArrayHasKey('totalIncome', $result['years'][0]);
		$this->assertArrayHasKey('avgMonthlyIncome', $result['years'][0]);
	}

	/**
	 * One aggregate per year, spread over its months: a month without
	 * activity reads zero, the others their own figures.
	 */
	public function testGetMonthlyTrendsFillsEveryMonthFromOneQueryPerYear(): void {
		$lastYear = (int)date('Y') - 1;
		$this->cashFlowByYear([
			$lastYear => [
				$this->month("$lastYear-02", 100.0, 40.0),
				$this->month("$lastYear-11", 50.5, 10.25),
			],
		]);
		$this->reportQueries->expects($this->exactly(2))->method('getCashFlowByMonth');

		$result = $this->service->getMonthlyTrends('user1', 2);

		$past = $result['years'][1];
		$this->assertCount(12, $past['months']);
		$this->assertSame(0.0, $past['months'][0]['income']);
		$this->assertSame(100.0, $past['months'][1]['income']);
		$this->assertSame(10.25, $past['months'][10]['expenses']);
		$this->assertSame(150.5, $past['totalIncome']);
		$this->assertSame(50.25, $past['totalExpenses']);
		$this->assertSame(100.25, $past['totalSavings']);
	}

	// ===== calculatePercentChange edge cases =====

	public function testPercentChangeFromZeroPreviousReturns100(): void {
		$currentYear = (int)date('Y');
		$this->cashFlowByYear([
			$currentYear => [$this->month("$currentYear-03", 1000.0, 0.0)],
		]);

		$result = $this->service->compareMonth('user1', 3, 2);

		// From 0 to positive = 100.0
		$this->assertEquals(100.0, $result['years'][0]['incomeChange']);
	}

	public function testPercentChangeFromZeroToZeroReturnsNull(): void {
		$this->cashFlowByYear([]);

		$result = $this->service->compareMonth('user1', 3, 2);

		// 0 to 0 = null
		$this->assertNull($result['years'][0]['incomeChange']);
	}
}
