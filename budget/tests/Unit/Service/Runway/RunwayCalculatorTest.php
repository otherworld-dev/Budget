<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Runway;

use OCA\Budget\Service\Runway\RunwayBill;
use OCA\Budget\Service\Runway\RunwayCalculator;
use OCA\Budget\Service\Runway\RunwayRow;
use PHPUnit\Framework\TestCase;

class RunwayCalculatorTest extends TestCase {
	private RunwayCalculator $calculator;
	private int $nextId = 1;

	/** The run-out date of 12000 at 1000/month from 2026-11-10: day 366 */
	private const BASE_RUN_OUT = '2027-11-11';

	protected function setUp(): void {
		$this->calculator = new RunwayCalculator();
		$this->nextId = 1;
	}

	private function row(
		string $date,
		string $amount,
		?int $categoryId = null,
		string $status = 'cleared',
		bool $excluded = false,
		bool $pensionLeg = false,
		?int $linkedAccountId = null,
		?int $billId = null,
		array $splits = [],
	): RunwayRow {
		return new RunwayRow(
			$this->nextId++, $date, $amount, $categoryId, $status,
			$excluded, $pensionLeg, $linkedAccountId, $billId, $splits,
		);
	}

	/**
	 * One row on the 15th of each window month (Aug, Sep, Oct 2026).
	 *
	 * @return RunwayRow[]
	 */
	private function monthly(string $amount, ?int $categoryId = null, ?int $billId = null): array {
		return array_map(
			fn (string $date) => $this->row($date, $amount, $categoryId, billId: $billId),
			['2026-08-15', '2026-09-15', '2026-10-15'],
		);
	}

	private function input(array $overrides = []): array {
		return array_merge([
			'today' => '2026-11-10',
			'total' => '12000',
			'chosenAccountIds' => [1, 2, 3],
			'windowMonths' => 3,
			'firstActivityDate' => '2025-01-01',
			'rows' => [],
			'historyRows' => [],
			'futureRows' => [],
			'includeScheduled' => true,
			'bills' => [],
			'oneOffs' => [],
			'budgets' => null,
		], $overrides);
	}

	private function calc(array $overrides = []): array {
		return $this->calculator->calculate($this->input($overrides));
	}

	public function testWindowIsLastFullMonths(): void {
		$result = $this->calc(['rows' => [
			...$this->monthly('-1000'),
			$this->row('2026-11-02', '-5000'),
		]]);

		$this->assertSame(3, $result['window']['months']);
		$this->assertSame(3, $result['window']['effectiveMonths']);
		$this->assertSame('2026-08-01', $result['window']['from']);
		$this->assertSame('2026-10-31', $result['window']['to']);
		$this->assertSame('1000.00', $result['monthlyOut']);
	}

	public function testSimpleRunway(): void {
		$result = $this->calc(['rows' => $this->monthly('-1000')]);

		$this->assertSame('1000.00', $result['monthlyOut']);
		$this->assertSame('0.00', $result['monthlyIn']);
		$current = $result['scenarios']['current'];
		$this->assertSame('running_out', $current['state']);
		$this->assertSame('1000.00', $current['monthlyDrain']);
		$this->assertEqualsWithDelta(12.0, $current['months'], 0.1);
		$this->assertStringStartsWith('2027-11', $current['runsOutOn']);
		$this->assertSame(self::BASE_RUN_OUT, $current['runsOutOn']);
	}

	public function testDrainIsOutMinusIn(): void {
		$result = $this->calc(['rows' => [...$this->monthly('-2147'), ...$this->monthly('797')]]);

		$this->assertSame('2147.00', $result['monthlyOut']);
		$this->assertSame('797.00', $result['monthlyIn']);
		$this->assertSame('1350.00', $result['scenarios']['current']['monthlyDrain']);
	}

	public function testScheduledRowsAreNotActual(): void {
		$base = $this->calc(['rows' => $this->monthly('-1000')]);
		$withScheduled = $this->calc(['rows' => [
			...$this->monthly('-1000'),
			$this->row('2026-09-10', '-500', status: 'scheduled'),
		]]);

		$this->assertSame($base['monthlyOut'], $withScheduled['monthlyOut']);
		$this->assertSame($base['scenarios'], $withScheduled['scenarios']);
		$this->assertSame(0, $withScheduled['excluded']['count']);
	}

	public function testExcludedRowsGoToExcluded(): void {
		$excluded = $this->row('2026-09-20', '-1500', excluded: true);
		$result = $this->calc(['rows' => [...$this->monthly('-1000'), $excluded]]);

		$this->assertSame(['count' => 1, 'total' => '1500.00', 'ids' => [$excluded->id]], $result['excluded']);
		$this->assertSame('1000.00', $result['monthlyOut']);
		$this->assertSame('1000.00', $result['scenarios']['current']['monthlyDrain']);
	}

	public function testPensionLegIgnored(): void {
		$result = $this->calc(['rows' => [
			...$this->monthly('-1000'),
			$this->row('2026-09-01', '-400', pensionLeg: true),
		]]);

		$this->assertSame('1000.00', $result['monthlyOut']);
		$this->assertSame(0, $result['excluded']['count']);
	}

	public function testTransferBetweenChosenAccountsIgnoredEvenAcrossWindowEdge(): void {
		// The partner leg is dated in November, outside the window, so it isn't in rows
		$result = $this->calc(['rows' => [
			...$this->monthly('-1000'),
			$this->row('2026-10-31', '-500', linkedAccountId: 2),
		]]);

		$this->assertSame('1000.00', $result['monthlyOut']);
	}

	public function testTransferToUnchosenAccountCountsAsOut(): void {
		$result = $this->calc(['rows' => [
			...$this->monthly('-1000'),
			$this->row('2026-10-01', '-300', linkedAccountId: 9),
		]]);

		$this->assertSame('1100.00', $result['monthlyOut']);
	}

	public function testUnlinkedIncomingTransferCountsAsIn(): void {
		$result = $this->calc(['rows' => [
			...$this->monthly('-1000'),
			...$this->monthly('300'),
		]]);

		$this->assertSame('300.00', $result['monthlyIn']);
		$this->assertSame('700.00', $result['scenarios']['current']['monthlyDrain']);
	}

	public function testCardPurchaseAndPaymentNet(): void {
		$result = $this->calc(['rows' => [
			...$this->monthly('-1000'),
			// Purchase on the card (account 3)
			$this->row('2026-09-05', '-100'),
			// Payment from current (1) to the card, and the card's side of it
			$this->row('2026-09-25', '-100', linkedAccountId: 3),
			$this->row('2026-09-25', '100', linkedAccountId: 1),
		]]);

		$this->assertSame('1033.33', $result['monthlyOut']);
		$this->assertSame('0.00', $result['monthlyIn']);
	}

	public function testOneOffsMoveRunOut(): void {
		$rows = $this->monthly('-1000');
		$oneOff = fn (int $id, string $amount, string $date) => [
			'id' => $id, 'description' => 'One-off ' . $id, 'amount' => $amount, 'date' => $date,
		];

		// 12000 - 864.27 at 1000 * 12 / 365.25 a day reaches zero on day 339, 27 days early
		$out = $this->calc(['rows' => $rows, 'oneOffs' => [$oneOff(1, '-864.27', '2027-01-15')]]);
		$this->assertSame('2027-10-15', $out['scenarios']['current']['runsOutOn']);
		$this->assertSame(
			[['date' => '2027-01-15', 'amount' => '-864.27', 'description' => 'One-off 1', 'source' => 'oneOff']],
			$out['events'],
		);

		// 13000 lasts until day 396
		$in = $this->calc(['rows' => $rows, 'oneOffs' => [$oneOff(2, '1000', '2027-01-15')]]);
		$this->assertSame('2027-12-11', $in['scenarios']['current']['runsOutOn']);

		$after = $this->calc(['rows' => $rows, 'oneOffs' => [$oneOff(3, '-5000', '2028-01-01')]]);
		$this->assertSame(self::BASE_RUN_OUT, $after['scenarios']['current']['runsOutOn']);

		$past = $this->calc(['rows' => $rows, 'oneOffs' => [$oneOff(4, '-5000', '2026-11-01')]]);
		$this->assertSame(self::BASE_RUN_OUT, $past['scenarios']['current']['runsOutOn']);
		$this->assertSame([], $past['events']);
	}

	public function testScheduledFutureRows(): void {
		$bills = [
			7 => new RunwayBill(7, 'monthly', '800.00', 20),
			8 => new RunwayBill(8, 'one-time', '100.00', 21),
		];
		$noBill = $this->row('2027-01-01', '-2000', status: 'scheduled');
		$recurringBill = $this->row('2026-12-01', '-800', status: 'scheduled', billId: 7);
		$oneTimeBill = $this->row('2026-12-05', '-1200', status: 'scheduled', billId: 8);
		$future = [$noBill, $recurringBill, $oneTimeBill];
		$rows = $this->monthly('-1000');

		$with = $this->calc(['rows' => $rows, 'futureRows' => $future, 'bills' => $bills]);
		$this->assertSame([
			['date' => '2026-12-05', 'amount' => '-1200.00', 'description' => '', 'source' => 'scheduled'],
			['date' => '2027-01-01', 'amount' => '-2000.00', 'description' => '', 'source' => 'scheduled'],
		], $with['events']);
		// 12000 - 3200 at 1000/month: day 268
		$this->assertSame('2027-08-05', $with['scenarios']['current']['runsOutOn']);

		$without = $this->calc([
			'rows' => $rows, 'futureRows' => $future, 'bills' => $bills, 'includeScheduled' => false,
		]);
		$this->assertSame([], $without['events']);
		$this->assertSame(self::BASE_RUN_OUT, $without['scenarios']['current']['runsOutOn']);
	}

	public function testYearlyBillSmoothed(): void {
		$yearly = [5 => new RunwayBill(5, 'yearly', '50.00', 30)];

		$paid = $this->calc(['rows' => [$this->row('2026-09-01', '-600', 30, billId: 5)], 'bills' => $yearly]);
		$this->assertSame('50.00', $paid['monthlyOut']);
		// The smoothed amount lands on the bill's category: budgeting it at 20 saves 30 a month
		$budgeted = $this->calc([
			'rows' => [$this->row('2026-09-01', '-600', 30, billId: 5)],
			'bills' => $yearly,
			'budgets' => [30 => '20.00'],
		]);
		$this->assertSame('20.00', $budgeted['scenarios']['onBudget']['monthlyDrain']);

		$unpaid = $this->calc(['rows' => [], 'bills' => $yearly]);
		$this->assertSame('50.00', $unpaid['monthlyOut']);

		$monthly = [6 => new RunwayBill(6, 'monthly', '80.00', 31)];
		$kept = $this->calc(['rows' => $this->monthly('-80', 31, 6), 'bills' => $monthly]);
		$this->assertSame('80.00', $kept['monthlyOut']);
	}

	public function testNotRunningOut(): void {
		$result = $this->calc(['rows' => [...$this->monthly('-500'), ...$this->monthly('800')]]);

		$current = $result['scenarios']['current'];
		$this->assertSame('not_running_out', $current['state']);
		$this->assertSame('-300.00', $current['monthlyDrain']);
		$this->assertNull($current['months']);
		$this->assertNull($current['runsOutOn']);
		$this->assertNull($result['differenceMonths']);
		$this->assertNotEmpty($result['series']['current']);
		foreach ($result['series']['current'] as $point) {
			$this->assertGreaterThan(0, (float)$point['total']);
		}
	}

	public function testAtZero(): void {
		$result = $this->calc(['total' => '-5', 'rows' => $this->monthly('-1000'), 'budgets' => [10 => '100']]);

		foreach (['current', 'onBudget'] as $scenario) {
			$this->assertSame('at_zero', $result['scenarios'][$scenario]['state']);
			$this->assertNull($result['scenarios'][$scenario]['months']);
			$this->assertNull($result['scenarios'][$scenario]['runsOutOn']);
			$this->assertSame([], $result['series'][$scenario]);
		}
		$this->assertNull($result['differenceMonths']);
	}

	public function testCapAt120Months(): void {
		$result = $this->calc(['total' => '1000000', 'rows' => $this->monthly('-1')]);

		$current = $result['scenarios']['current'];
		$this->assertSame('1.00', $current['monthlyDrain']);
		$this->assertSame('not_running_out', $current['state']);
		$this->assertNull($current['months']);
		$this->assertSame('2036-11-10', end($result['series']['current'])['date']);
	}

	public function testInsufficientData(): void {
		$none = $this->calc(['rows' => $this->monthly('-1000'), 'firstActivityDate' => '2026-11-03', 'budgets' => [10 => '100']]);
		$this->assertSame(0, $none['window']['effectiveMonths']);
		foreach (['current', 'onBudget'] as $scenario) {
			$this->assertSame('insufficient_data', $none['scenarios'][$scenario]['state']);
			$this->assertNull($none['scenarios'][$scenario]['monthlyDrain']);
			$this->assertNull($none['scenarios'][$scenario]['months']);
			$this->assertNull($none['scenarios'][$scenario]['runsOutOn']);
		}

		$noActivity = $this->calc(['firstActivityDate' => null]);
		$this->assertSame('insufficient_data', $noActivity['scenarios']['current']['state']);

		$oneMonth = $this->calc([
			'rows' => [$this->row('2026-10-15', '-1000')],
			'firstActivityDate' => '2026-10-05',
		]);
		$this->assertSame(1, $oneMonth['window']['effectiveMonths']);
		$this->assertSame('1000.00', $oneMonth['monthlyOut']);
		$this->assertSame('running_out', $oneMonth['scenarios']['current']['state']);
	}

	public function testOnBudgetSwap(): void {
		$under = $this->calc([
			'rows' => [...$this->monthly('-300', 10), ...$this->monthly('-100', 11), ...$this->monthly('-50')],
			'budgets' => [10 => '200'],
		]);
		$this->assertSame('450.00', $under['scenarios']['current']['monthlyDrain']);
		$this->assertSame('350.00', $under['scenarios']['onBudget']['monthlyDrain']);
		$this->assertSame('running_out', $under['scenarios']['onBudget']['state']);
		$this->assertGreaterThan(0, $under['differenceMonths']);
		$this->assertEqualsWithDelta(
			$under['scenarios']['onBudget']['months'] - $under['scenarios']['current']['months'],
			$under['differenceMonths'],
			0.05,
		);

		// Spending under budget is swapped up to the budget
		$swappedUp = $this->calc([
			'rows' => [...$this->monthly('-150', 10), ...$this->monthly('-100', 11), ...$this->monthly('-50')],
			'budgets' => [10 => '200'],
		]);
		$this->assertSame('300.00', $swappedUp['scenarios']['current']['monthlyDrain']);
		$this->assertSame('350.00', $swappedUp['scenarios']['onBudget']['monthlyDrain']);
		$this->assertLessThan(0, $swappedUp['differenceMonths']);
	}

	public function testOnBudgetUsesSplitParts(): void {
		$split = $this->row('2026-09-12', '-300', splits: [
			['categoryId' => 10, 'amount' => '-200'],
			['categoryId' => 11, 'amount' => '-100'],
		]);
		$result = $this->calc(['rows' => [$split], 'budgets' => [10 => '50']]);

		// Current 300 / 3 = 100; on budget 100 - 200 / 3 + 50
		$this->assertSame('100.00', $result['scenarios']['current']['monthlyDrain']);
		$this->assertSame('83.33', $result['scenarios']['onBudget']['monthlyDrain']);
	}

	public function testOnBudgetUnavailableWithoutBudgets(): void {
		foreach ([null, []] as $budgets) {
			$result = $this->calc(['rows' => $this->monthly('-1000'), 'budgets' => $budgets]);
			$onBudget = $result['scenarios']['onBudget'];
			$this->assertSame('unavailable', $onBudget['state']);
			$this->assertNull($onBudget['monthlyDrain']);
			$this->assertNull($onBudget['months']);
			$this->assertNull($onBudget['runsOutOn']);
			$this->assertSame([], $result['series']['onBudget']);
			$this->assertNull($result['differenceMonths']);
			$this->assertSame('running_out', $result['scenarios']['current']['state']);
		}
	}

	public function testHistoryRebuiltBackwards(): void {
		$result = $this->calc([
			'total' => '1000',
			'historyRows' => [
				$this->row('2026-10-20', '-100'),
				$this->row('2026-11-05', '50'),
				$this->row('2026-10-25', '-999', status: 'scheduled'),
				// Excluded rows and transfers are part of the true balance
				$this->row('2026-09-10', '-20', excluded: true, linkedAccountId: 2),
			],
		]);

		$history = $result['series']['history'];
		$this->assertCount(13, $history);
		$this->assertSame(['date' => '2025-11-30', 'total' => '1070.00'], $history[0]);
		$this->assertSame(['date' => '2026-08-31', 'total' => '1070.00'], $history[9]);
		$this->assertSame(['date' => '2026-09-30', 'total' => '1050.00'], $history[10]);
		$this->assertSame(['date' => '2026-10-31', 'total' => '950.00'], $history[11]);
		$this->assertSame(['date' => '2026-11-10', 'total' => '1000.00'], $history[12]);
	}

	public function testMoneyHasNoFloatDrift(): void {
		$rows = [];
		for ($i = 0; $i < 3; $i++) {
			array_push($rows, ...$this->monthly('-0.10'));
		}
		$result = $this->calc(['rows' => $rows]);

		$this->assertSame('0.30', $result['monthlyOut']);
		$this->assertSame('0.30', $result['scenarios']['current']['monthlyDrain']);
	}
}
