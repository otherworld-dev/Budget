<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Report;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Report\ReportCalculator;
use PHPUnit\Framework\TestCase;

class ReportCalculatorTest extends TestCase {
	private ReportCalculator $calculator;
	private TransactionMapper $transactionMapper;
	private AccountMapper $accountMapper;

	protected function setUp(): void {
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->calculator = new ReportCalculator(
			$this->accountMapper,
			$this->transactionMapper
		);
	}

	// ── calculatePercentChange ──────────────────────────────────────

	public function testCalculatePercentChangeIncrease(): void {
		$result = $this->calculator->calculatePercentChange(100.0, 150.0);
		$this->assertEqualsWithDelta(50.0, $result['percentage'], 0.1);
		$this->assertSame('up', $result['direction']);
		$this->assertEqualsWithDelta(50.0, $result['absolute'], 0.001);
	}

	public function testCalculatePercentChangeDecrease(): void {
		$result = $this->calculator->calculatePercentChange(200.0, 150.0);
		$this->assertEqualsWithDelta(25.0, $result['percentage'], 0.1);
		$this->assertSame('down', $result['direction']);
		$this->assertEqualsWithDelta(-50.0, $result['absolute'], 0.001);
	}

	public function testCalculatePercentChangeNoChange(): void {
		$result = $this->calculator->calculatePercentChange(100.0, 100.0);
		$this->assertEqualsWithDelta(0.0, $result['percentage'], 0.1);
		$this->assertSame('none', $result['direction']);
		$this->assertEqualsWithDelta(0.0, $result['absolute'], 0.001);
	}

	public function testCalculatePercentChangeFromZero(): void {
		$result = $this->calculator->calculatePercentChange(0.0, 50.0);
		$this->assertSame(100, $result['percentage']);
		$this->assertSame('up', $result['direction']);
	}

	public function testCalculatePercentChangeFromZeroToZero(): void {
		$result = $this->calculator->calculatePercentChange(0.0, 0.0);
		$this->assertSame(0, $result['percentage']);
		$this->assertSame('none', $result['direction']);
	}

	public function testCalculatePercentChangeFromZeroToNegative(): void {
		$result = $this->calculator->calculatePercentChange(0.0, -50.0);
		// Code only returns 100 for positive current; negative gets 0 percentage
		$this->assertSame(0, $result['percentage']);
		$this->assertSame('down', $result['direction']);
	}

	public function testCalculatePercentChangeDoubling(): void {
		$result = $this->calculator->calculatePercentChange(100.0, 200.0);
		$this->assertEqualsWithDelta(100.0, $result['percentage'], 0.1);
		$this->assertSame('up', $result['direction']);
	}

	public function testCalculatePercentChangeNegativePrevious(): void {
		// From -100 to -50: change = (-50 - (-100)) / |-100| * 100 = 50%
		$result = $this->calculator->calculatePercentChange(-100.0, -50.0);
		$this->assertEqualsWithDelta(50.0, $result['percentage'], 0.1);
		$this->assertSame('up', $result['direction']);
		$this->assertEqualsWithDelta(50.0, $result['absolute'], 0.001);
	}

	// ── formatMonthLabel ────────────────────────────────────────────

	public function testFormatMonthLabel(): void {
		$this->assertSame('Jan 2024', $this->calculator->formatMonthLabel('2024-01'));
		$this->assertSame('Dec 2023', $this->calculator->formatMonthLabel('2023-12'));
		$this->assertSame('Jun 2025', $this->calculator->formatMonthLabel('2025-06'));
	}

	public function testFormatMonthLabelInvalidReturnsInput(): void {
		$this->assertSame('invalid', $this->calculator->formatMonthLabel('invalid'));
	}

	// ── getBudgetStatus ─────────────────────────────────────────────

	public function testGetBudgetStatusGood(): void {
		$this->assertSame('good', $this->calculator->getBudgetStatus(0.0));
		$this->assertSame('good', $this->calculator->getBudgetStatus(25.0));
		$this->assertSame('good', $this->calculator->getBudgetStatus(50.0));
	}

	public function testGetBudgetStatusWarning(): void {
		$this->assertSame('warning', $this->calculator->getBudgetStatus(50.1));
		$this->assertSame('warning', $this->calculator->getBudgetStatus(75.0));
		$this->assertSame('warning', $this->calculator->getBudgetStatus(80.0));
	}

	public function testGetBudgetStatusDanger(): void {
		$this->assertSame('danger', $this->calculator->getBudgetStatus(80.1));
		$this->assertSame('danger', $this->calculator->getBudgetStatus(95.0));
		$this->assertSame('danger', $this->calculator->getBudgetStatus(100.0));
	}

	public function testGetBudgetStatusOver(): void {
		$this->assertSame('over', $this->calculator->getBudgetStatus(100.1));
		$this->assertSame('over', $this->calculator->getBudgetStatus(200.0));
	}

	// ── calculateTotals ─────────────────────────────────────────────

	public function testCalculateTotals(): void {
		$data = [
			['total' => 100.0, 'count' => 5],
			['total' => 200.0, 'count' => 10],
			['total' => 50.5, 'count' => 3],
		];
		$result = $this->calculator->calculateTotals($data);
		$this->assertEqualsWithDelta(350.5, $result['amount'], 0.001);
		$this->assertSame(18, $result['transactions']);
	}

	public function testCalculateTotalsEmpty(): void {
		$result = $this->calculator->calculateTotals([]);
		$this->assertEqualsWithDelta(0.0, $result['amount'], 0.001);
		$this->assertSame(0, $result['transactions']);
	}

	public function testCalculateTotalsSingleItem(): void {
		$data = [['total' => 42.50, 'count' => 1]];
		$result = $this->calculator->calculateTotals($data);
		$this->assertEqualsWithDelta(42.50, $result['amount'], 0.001);
		$this->assertSame(1, $result['transactions']);
	}

	// ── Mapper delegation tests ─────────────────────────────────────

	public function testGetSpendingByCategoryDelegatesToMapper(): void {
		$expected = [['category' => 'Food', 'total' => 500.0]];
		$this->transactionMapper->expects($this->once())
			->method('getSpendingSummary')
			->with('user1', '2024-01-01', '2024-03-31', 5)
			->willReturn($expected);

		$result = $this->calculator->getSpendingByCategory('user1', 5, '2024-01-01', '2024-03-31');
		$this->assertSame($expected, $result);
	}

	public function testGetSpendingByMonthFormatsLabels(): void {
		$this->transactionMapper->expects($this->once())
			->method('getSpendingByMonth')
			->willReturn([
				['month' => '2024-01', 'total' => '500.00', 'count' => '15'],
				['month' => '2024-02', 'total' => '300.00', 'count' => '10'],
			]);

		$result = $this->calculator->getSpendingByMonth('user1', null, '2024-01-01', '2024-02-28');

		$this->assertSame('Jan 2024', $result[0]['name']);
		$this->assertSame('2024-01', $result[0]['month']);
		$this->assertEqualsWithDelta(500.0, $result[0]['total'], 0.001);
		$this->assertSame(15, $result[0]['count']);

		$this->assertSame('Feb 2024', $result[1]['name']);
	}

	public function testGetIncomeByMonthFormatsLabels(): void {
		$this->transactionMapper->expects($this->once())
			->method('getIncomeByMonth')
			->willReturn([
				['month' => '2024-03', 'total' => '3000.00', 'count' => '2'],
			]);

		$result = $this->calculator->getIncomeByMonth('user1', null, '2024-03-01', '2024-03-31');

		$this->assertSame('Mar 2024', $result[0]['name']);
		$this->assertEqualsWithDelta(3000.0, $result[0]['total'], 0.001);
		$this->assertSame(2, $result[0]['count']);
	}
}
