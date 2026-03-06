<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Forecast;

use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\Forecast\PatternAnalyzer;
use OCA\Budget\Service\Forecast\TrendCalculator;
use PHPUnit\Framework\TestCase;

class PatternAnalyzerTest extends TestCase {
	private TrendCalculator $trendCalculator;
	private CategoryMapper $categoryMapper;
	private PatternAnalyzer $analyzer;

	protected function setUp(): void {
		$this->trendCalculator = $this->createMock(TrendCalculator::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->analyzer = new PatternAnalyzer($this->trendCalculator, $this->categoryMapper);
	}

	private function makeTransaction(string $date, float $amount, string $type, ?int $categoryId = null, string $description = 'Test'): Transaction {
		$t = new Transaction();
		$t->setDate($date);
		$t->setAmount($amount);
		$t->setType($type);
		$t->setCategoryId($categoryId);
		$t->setDescription($description);
		return $t;
	}

	// ── aggregateMonthlyData ────────────────────────────────────────

	public function testAggregateMonthlyDataGroupsByMonth(): void {
		$transactions = [
			$this->makeTransaction('2025-01-05', 3000, 'credit'),
			$this->makeTransaction('2025-01-15', 500, 'debit'),
			$this->makeTransaction('2025-02-01', 3000, 'credit'),
			$this->makeTransaction('2025-02-10', 800, 'debit'),
		];

		$result = $this->analyzer->aggregateMonthlyData($transactions);

		$this->assertCount(2, $result);
		$this->assertEquals(3000.0, $result[0]['income']);
		$this->assertEquals(500.0, $result[0]['expenses']);
		$this->assertEquals(3000.0, $result[1]['income']);
		$this->assertEquals(800.0, $result[1]['expenses']);
	}

	public function testAggregateMonthlyDataSortsByMonth(): void {
		$transactions = [
			$this->makeTransaction('2025-03-01', 100, 'debit'),
			$this->makeTransaction('2025-01-01', 200, 'debit'),
		];

		$result = $this->analyzer->aggregateMonthlyData($transactions);

		$this->assertCount(2, $result);
		// First entry should be January (sorted)
		$this->assertEquals(200.0, $result[0]['expenses']);
		$this->assertEquals(100.0, $result[1]['expenses']);
	}

	public function testAggregateMonthlyDataEmpty(): void {
		$result = $this->analyzer->aggregateMonthlyData([]);
		$this->assertEmpty($result);
	}

	// ── detectRecurringTransactions ─────────────────────────────────

	public function testDetectRecurringMonthly(): void {
		$transactions = [
			$this->makeTransaction('2025-01-01', 50, 'debit', null, 'Netflix'),
			$this->makeTransaction('2025-02-01', 50, 'debit', null, 'Netflix'),
			$this->makeTransaction('2025-03-01', 50, 'debit', null, 'Netflix'),
			$this->makeTransaction('2025-04-01', 50, 'debit', null, 'Netflix'),
		];

		$result = $this->analyzer->detectRecurringTransactions($transactions);

		$this->assertCount(1, $result);
		$this->assertSame('Netflix', $result[0]['description']);
		$this->assertSame('monthly', $result[0]['frequency']);
		$this->assertEquals(50, $result[0]['amount']);
	}

	public function testDetectRecurringWeekly(): void {
		$transactions = [
			$this->makeTransaction('2025-01-06', 25, 'debit', null, 'Gym'),
			$this->makeTransaction('2025-01-13', 25, 'debit', null, 'Gym'),
			$this->makeTransaction('2025-01-20', 25, 'debit', null, 'Gym'),
		];

		$result = $this->analyzer->detectRecurringTransactions($transactions);

		$this->assertCount(1, $result);
		$this->assertSame('weekly', $result[0]['frequency']);
	}

	public function testDetectRecurringRequiresThreeOccurrences(): void {
		$transactions = [
			$this->makeTransaction('2025-01-01', 50, 'debit', null, 'Netflix'),
			$this->makeTransaction('2025-02-01', 50, 'debit', null, 'Netflix'),
		];

		$result = $this->analyzer->detectRecurringTransactions($transactions);

		$this->assertEmpty($result);
	}

	public function testDetectRecurringIgnoresIrregularIntervals(): void {
		$transactions = [
			$this->makeTransaction('2025-01-01', 50, 'debit', null, 'Random'),
			$this->makeTransaction('2025-01-10', 50, 'debit', null, 'Random'),
			$this->makeTransaction('2025-03-25', 50, 'debit', null, 'Random'),
		];

		$result = $this->analyzer->detectRecurringTransactions($transactions);

		$this->assertEmpty($result); // ~40 day avg, not monthly or weekly
	}

	public function testDetectRecurringGroupsByDescriptionAndAmount(): void {
		$transactions = [
			$this->makeTransaction('2025-01-01', 50, 'debit', null, 'Netflix'),
			$this->makeTransaction('2025-02-01', 50, 'debit', null, 'Netflix'),
			$this->makeTransaction('2025-03-01', 50, 'debit', null, 'Netflix'),
			$this->makeTransaction('2025-01-01', 100, 'debit', null, 'Netflix'), // Different amount
		];

		$result = $this->analyzer->detectRecurringTransactions($transactions);

		$this->assertCount(1, $result); // Only the 50 group has 3+ entries
	}

	public function testDetectRecurringConfidenceIncreasesWithOccurrences(): void {
		$transactions = [];
		for ($i = 1; $i <= 6; $i++) {
			$transactions[] = $this->makeTransaction("2025-0{$i}-01", 50, 'debit', null, 'Sub');
		}

		$result = $this->analyzer->detectRecurringTransactions($transactions);

		$this->assertCount(1, $result);
		$this->assertEquals(1.0, $result[0]['confidence']); // 6/6 = 1.0
	}

	// ── calculateSeasonality ────────────────────────────────────────

	public function testCalculateSeasonalityBasic(): void {
		$monthlyData = [];
		for ($m = 1; $m <= 12; $m++) {
			$key = sprintf('2024-%02d', $m);
			$monthlyData[$key] = ['income' => 3000, 'expenses' => $m === 12 ? 2000 : 1000];
		}

		$result = $this->analyzer->calculateSeasonality($monthlyData);

		$this->assertCount(12, $result);
		// December should have higher factor than other months
		$this->assertGreaterThan($result[1], $result[12]);
	}

	public function testCalculateSeasonalityUniformExpenses(): void {
		$monthlyData = [];
		for ($m = 1; $m <= 12; $m++) {
			$key = sprintf('2024-%02d', $m);
			$monthlyData[$key] = ['income' => 3000, 'expenses' => 1000];
		}

		$result = $this->analyzer->calculateSeasonality($monthlyData);

		// All months should be approximately 1.0
		foreach ($result as $factor) {
			$this->assertEqualsWithDelta(1.0, $factor, 0.01);
		}
	}

	public function testCalculateSeasonalityZeroExpenses(): void {
		$monthlyData = [];
		for ($m = 1; $m <= 12; $m++) {
			$key = sprintf('2024-%02d', $m);
			$monthlyData[$key] = ['income' => 3000, 'expenses' => 0];
		}

		$result = $this->analyzer->calculateSeasonality($monthlyData);

		// With zero total, should return all 1.0
		foreach ($result as $factor) {
			$this->assertEqualsWithDelta(1.0, $factor, 0.01);
		}
	}

	public function testCalculateSeasonalityHandlesMissingMonths(): void {
		$monthlyData = [
			'2024-01' => ['income' => 3000, 'expenses' => 1000],
			'2024-06' => ['income' => 3000, 'expenses' => 2000],
		];

		$result = $this->analyzer->calculateSeasonality($monthlyData);

		$this->assertCount(12, $result);
		// Missing months should default to 1.0
		$this->assertEquals(1.0, $result[3]); // March not present
	}

	// ── analyzeTransactionPatterns ──────────────────────────────────

	public function testAnalyzeTransactionPatternsStructure(): void {
		$this->trendCalculator->method('calculateTrend')->willReturn(0.05);
		$this->trendCalculator->method('calculateVolatility')->willReturn(0.1);

		$transactions = [
			$this->makeTransaction('2025-01-05', 3000, 'credit', 1),
			$this->makeTransaction('2025-01-15', 500, 'debit', 2),
			$this->makeTransaction('2025-02-05', 3000, 'credit', 1),
			$this->makeTransaction('2025-02-15', 600, 'debit', 2),
		];

		$result = $this->analyzer->analyzeTransactionPatterns($transactions, 2);

		$this->assertArrayHasKey('monthly', $result);
		$this->assertArrayHasKey('categories', $result);
		$this->assertArrayHasKey('recurring', $result);
		$this->assertArrayHasKey('seasonality', $result);
		$this->assertArrayHasKey('average', $result['monthly']['income']);
		$this->assertArrayHasKey('trend', $result['monthly']['income']);
		$this->assertArrayHasKey('volatility', $result['monthly']['income']);
	}

	public function testAnalyzeTransactionPatternsEmpty(): void {
		$result = $this->analyzer->analyzeTransactionPatterns([], 1);

		$this->assertEmpty($result['monthly']['net']);
		$this->assertEmpty($result['categories']);
		$this->assertEmpty($result['recurring']);
	}

	public function testAnalyzeTransactionPatternsSeasonalityRequires12Months(): void {
		$transactions = [
			$this->makeTransaction('2025-01-01', 100, 'debit'),
			$this->makeTransaction('2025-02-01', 100, 'debit'),
		];

		$this->trendCalculator->method('calculateTrend')->willReturn(0.0);
		$this->trendCalculator->method('calculateVolatility')->willReturn(0.0);

		$result = $this->analyzer->analyzeTransactionPatterns($transactions, 2);

		$this->assertEmpty($result['seasonality']); // Less than 12 months
	}

	// ── getCategoryBreakdown ────────────────────────────────────────

	public function testGetCategoryBreakdownOnlyDebits(): void {
		$this->trendCalculator->method('calculateTrend')->willReturn(0.0);
		$this->trendCalculator->method('getTrendDirection')->willReturn('stable');
		$this->categoryMapper->method('findByIds')->willReturn([]);

		$transactions = [
			$this->makeTransaction('2025-01-01', 3000, 'credit', 1), // Income, skipped
			$this->makeTransaction('2025-01-15', 500, 'debit', 2),
			$this->makeTransaction('2025-01-20', 300, 'debit', 3),
		];

		$result = $this->analyzer->getCategoryBreakdown('user1', $transactions);

		// Only debit transactions included
		$this->assertCount(2, $result);
	}

	public function testGetCategoryBreakdownSortsByHighest(): void {
		$this->trendCalculator->method('calculateTrend')->willReturn(0.0);
		$this->trendCalculator->method('getTrendDirection')->willReturn('stable');
		$this->categoryMapper->method('findByIds')->willReturn([]);

		$transactions = [
			$this->makeTransaction('2025-01-01', 100, 'debit', 1),
			$this->makeTransaction('2025-01-01', 500, 'debit', 2),
		];

		$result = $this->analyzer->getCategoryBreakdown('user1', $transactions);

		$this->assertEquals(500.0, $result[0]['avgMonthly']); // Highest first
		$this->assertEquals(100.0, $result[1]['avgMonthly']);
	}

	public function testGetCategoryBreakdownUncategorized(): void {
		$this->trendCalculator->method('calculateTrend')->willReturn(0.0);
		$this->trendCalculator->method('getTrendDirection')->willReturn('stable');

		$transactions = [
			$this->makeTransaction('2025-01-01', 100, 'debit', null),
		];

		$result = $this->analyzer->getCategoryBreakdown('user1', $transactions);

		$this->assertCount(1, $result);
		$this->assertSame('Uncategorized', $result[0]['name']);
		$this->assertSame(0, $result[0]['categoryId']);
	}
}
