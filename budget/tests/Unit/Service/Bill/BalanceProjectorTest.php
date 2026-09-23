<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Bill;

use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Service\Bill\BalanceProjector;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The Bills Calendar's projected balance (#393). Far-future dates keep the
 * real FrequencyCalculator deterministic, as in FrequencyCalculatorTest.
 */
class BalanceProjectorTest extends TestCase {
	private BalanceProjector $projector;

	protected function setUp(): void {
		$this->projector = new BalanceProjector(new FrequencyCalculator());
	}

	private function row(array $overrides): array {
		return $overrides + [
			'accountId' => 5,
			'isTransfer' => false,
			'destinationAccountId' => null,
			'paidMonths' => [],
			'unrecordedMonths' => [],
			'expectedAmounts' => [],
		];
	}

	private function income(array $overrides = []): RecurringIncome {
		$income = new RecurringIncome();
		$income->setAccountId($overrides['accountId'] ?? 5);
		$income->setAmount($overrides['amount'] ?? 2000.0);
		$income->setFrequency($overrides['frequency'] ?? 'monthly');
		$income->setExpectedDay($overrides['expectedDay'] ?? 25);
		$income->setNextExpectedDate($overrides['nextExpectedDate'] ?? '2099-03-25');
		$income->setStartDate($overrides['startDate'] ?? null);
		$income->setIsActive($overrides['isActive'] ?? true);
		return $income;
	}

	// ── project ─────────────────────────────────────────────────────

	/**
	 * Each month starts where the one before ended: bills out of the account
	 * come off, transfers and income into it go on. A bill still owed from
	 * an earlier month has not gone out yet, so it comes off in the current
	 * month; anything paid or moved past is already settled.
	 */
	public function testCarriesTheBalanceFromMonthToMonth(): void {
		$rows = [
			// Paid for January, February still owed
			$this->row(['expectedAmounts' => [1 => 500.0, 2 => 500.0, 3 => 500.0, 4 => 500.0], 'paidMonths' => [1]]),
			// Moved past in February without a payment: not owed
			$this->row(['expectedAmounts' => [2 => 100.0], 'unrecordedMonths' => [2]]),
			// A transfer out of the account is a payment out
			$this->row(['isTransfer' => true, 'destinationAccountId' => 7, 'expectedAmounts' => [3 => 200.0, 4 => 200.0]]),
			// A transfer into it, already arrived for March
			$this->row(['accountId' => 7, 'isTransfer' => true, 'destinationAccountId' => 5, 'expectedAmounts' => [3 => 300.0, 4 => 300.0], 'paidMonths' => [3]]),
			// Another account's bill is not this account's business
			$this->row(['accountId' => 8, 'expectedAmounts' => [3 => 40.0, 4 => 40.0]]),
		];

		$result = $this->projector->project($rows, 5, 1000.0, [3 => '2000', 4 => '2000'], 3, 2);

		$this->assertNull($result['balance'][1]);
		$this->assertNull($result['balance'][2]);
		$this->assertNull($result['flows'][2]);
		// 1000 - (500 overdue + 500 + 200) + 2000
		$this->assertSame(['bills' => 1200.0, 'transfersIn' => 0.0, 'income' => 2000.0], $result['flows'][3]);
		$this->assertEqualsWithDelta(1800.0, $result['balance'][3], 0.001);
		// 1800 - (500 + 200) + 300 + 2000
		$this->assertSame(['bills' => 700.0, 'transfersIn' => 300.0, 'income' => 2000.0], $result['flows'][4]);
		$this->assertEqualsWithDelta(3400.0, $result['balance'][4], 0.001);
		// Nothing more due: the balance carries on unchanged
		$this->assertEqualsWithDelta(3400.0, $result['balance'][12], 0.001);
	}

	/** A shortfall carries forward too, rather than every month starting afresh. */
	public function testAShortfallCarriesForward(): void {
		$rows = [$this->row(['expectedAmounts' => [10 => 600.0, 11 => 600.0]])];

		$result = $this->projector->project($rows, 5, 1000.0, [], 10, 2);

		$this->assertEqualsWithDelta(400.0, $result['balance'][10], 0.001);
		$this->assertEqualsWithDelta(-200.0, $result['balance'][11], 0.001);
		$this->assertEqualsWithDelta(-200.0, $result['balance'][12], 0.001);
	}

	// ── incomeByMonth ───────────────────────────────────────────────

	/** Every occurrence from today to December counts; one expected before today does not. */
	public function testIncomeCountsWhatIsStillToArriveThisYear(): void {
		$due = $this->projector->incomeByMonth([$this->income(['nextExpectedDate' => '2099-02-25'])], 5, '2099-03-10', 2);

		$this->assertSame(range(3, 12), array_keys($due));
		$this->assertSame('2000.00', $due[3]);
		$this->assertSame('2000.00', $due[12]);
	}

	/** Weekly income lands four or five times a month, not once. */
	public function testWeeklyIncomeCountsEveryOccurrence(): void {
		$weekly = $this->income(['frequency' => 'weekly', 'amount' => 100.0, 'nextExpectedDate' => '2099-03-02', 'startDate' => '2099-03-02']);

		$due = $this->projector->incomeByMonth([$weekly], 5, '2099-03-01', 2);

		// 2, 9, 16, 23, 30 March; 6, 13, 20, 27 April
		$this->assertSame('500.00', $due[3]);
		$this->assertSame('400.00', $due[4]);
	}

	public function testOneTimeIncomeCountsOnce(): void {
		$once = $this->income(['frequency' => 'one-time', 'nextExpectedDate' => '2099-06-01', 'startDate' => '2099-06-01']);

		$this->assertSame([6 => '2000.00'], $this->projector->incomeByMonth([$once], 5, '2099-03-01', 2));
	}

	public function testIncomeIntoAnotherAccountOrInactiveIsLeftOut(): void {
		$due = $this->projector->incomeByMonth([
			$this->income(['accountId' => 7]),
			$this->income(['isActive' => false]),
		], 5, '2099-03-01', 2);

		$this->assertSame([], $due);
	}
}
