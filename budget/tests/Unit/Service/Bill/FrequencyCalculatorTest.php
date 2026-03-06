<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Bill;

use OCA\Budget\Db\Bill;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use PHPUnit\Framework\TestCase;

class FrequencyCalculatorTest extends TestCase {
	private FrequencyCalculator $calculator;

	protected function setUp(): void {
		$this->calculator = new FrequencyCalculator();
	}

	// ── calculateNextDueDate ────────────────────────────────────────
	// Note: These tests use far-future dates so `$next <= $today` is never true,
	// making the tests deterministic regardless of when they run.

	public function testCalculateNextDueDateDailyFutureDate(): void {
		$result = $this->calculator->calculateNextDueDate('daily', null, null, '2099-06-15');
		$this->assertSame('2099-06-15', $result);
	}

	public function testCalculateNextDueDateWeeklyFutureMonday(): void {
		// 2099-01-05 is a Monday (day 1)
		$result = $this->calculator->calculateNextDueDate('weekly', 1, null, '2099-01-05');
		$this->assertSame('2099-01-05', $result);
	}

	public function testCalculateNextDueDateWeeklyDifferentDay(): void {
		// 2099-01-05 is Monday, asking for Friday (day 5)
		$result = $this->calculator->calculateNextDueDate('weekly', 5, null, '2099-01-05');
		$this->assertSame('2099-01-09', $result);
	}

	public function testCalculateNextDueDateBiweeklyDefaultsToMonday(): void {
		$result = $this->calculator->calculateNextDueDate('biweekly', null, null, '2099-01-05');
		// null dueDay defaults to 1 (Monday), and 2099-01-05 is Monday
		$this->assertSame('2099-01-05', $result);
	}

	public function testCalculateNextDueDateMonthlySpecificDay(): void {
		$result = $this->calculator->calculateNextDueDate('monthly', 15, null, '2099-06-01');
		$this->assertSame('2099-06-15', $result);
	}

	public function testCalculateNextDueDateMonthlyDayClamping(): void {
		// Feb doesn't have 31 days → clamp to 28
		$result = $this->calculator->calculateNextDueDate('monthly', 31, null, '2099-02-01');
		$this->assertSame('2099-02-28', $result);
	}

	public function testCalculateNextDueDateMonthlyDefaultDay(): void {
		// null dueDay → day 1
		$result = $this->calculator->calculateNextDueDate('monthly', null, null, '2099-06-01');
		$this->assertSame('2099-06-01', $result);
	}

	public function testCalculateNextDueDateQuarterly(): void {
		$result = $this->calculator->calculateNextDueDate('quarterly', 15, 3, '2099-01-01');
		$this->assertSame('2099-03-15', $result);
	}

	public function testCalculateNextDueDateYearly(): void {
		$result = $this->calculator->calculateNextDueDate('yearly', 25, 12, '2099-01-01');
		$this->assertSame('2099-12-25', $result);
	}

	public function testCalculateNextDueDateYearlyDefaults(): void {
		// No dueDay or dueMonth → January 1st
		$result = $this->calculator->calculateNextDueDate('yearly', null, null, '2099-01-01');
		$this->assertSame('2099-01-01', $result);
	}

	public function testCalculateNextDueDateOneTime(): void {
		$result = $this->calculator->calculateNextDueDate('one-time', 15, 6, '2099-01-01');
		$this->assertSame('2099-06-15', $result);
	}

	public function testCalculateNextDueDateUnknownFrequency(): void {
		$result = $this->calculator->calculateNextDueDate('unknown', null, null, '2099-06-15');
		$this->assertSame('2099-06-15', $result);
	}

	public function testCalculateNextDueDateCustomMonthsPattern(): void {
		// Custom patterns use $today internally, so assert on current-year future month
		$currentYear = (int) date('Y');
		$pattern = json_encode(['months' => [12]]);
		$result = $this->calculator->calculateNextDueDate('custom', 25, null, '2099-01-01', $pattern);
		// Should find December 25th of the current year (or next year if past)
		$expected = new \DateTime();
		$expected->setDate($currentYear, 12, 25);
		if ($expected <= new \DateTime()) {
			$expected->setDate($currentYear + 1, 12, 25);
		}
		$this->assertSame($expected->format('Y-m-d'), $result);
	}

	public function testCalculateNextDueDateCustomDatesPattern(): void {
		$currentYear = (int) date('Y');
		$pattern = json_encode(['dates' => [['month' => 12, 'day' => 25]]]);
		$result = $this->calculator->calculateNextDueDate('custom', null, null, '2099-01-01', $pattern);
		$expected = new \DateTime();
		$expected->setDate($currentYear, 12, 25);
		if ($expected <= new \DateTime()) {
			$expected->setDate($currentYear + 1, 12, 25);
		}
		$this->assertSame($expected->format('Y-m-d'), $result);
	}

	public function testCalculateNextDueDateCustomEmptyPattern(): void {
		$result = $this->calculator->calculateNextDueDate('custom', null, null, '2099-06-15', '');
		$this->assertSame('2099-06-15', $result);
	}

	public function testCalculateNextDueDateCustomInvalidJson(): void {
		$result = $this->calculator->calculateNextDueDate('custom', null, null, '2099-06-15', 'not-json');
		$this->assertSame('2099-06-15', $result);
	}

	// ── getMonthlyEquivalentFromValues ──────────────────────────────

	public function testMonthlyEquivalentDaily(): void {
		$this->assertEqualsWithDelta(300.0, $this->calculator->getMonthlyEquivalentFromValues(10.0, 'daily'), 0.001);
	}

	public function testMonthlyEquivalentWeekly(): void {
		// 100 * 52 / 12 = 433.33
		$this->assertEqualsWithDelta(433.333, $this->calculator->getMonthlyEquivalentFromValues(100.0, 'weekly'), 0.01);
	}

	public function testMonthlyEquivalentBiweekly(): void {
		// 100 * 26 / 12 = 216.67
		$this->assertEqualsWithDelta(216.667, $this->calculator->getMonthlyEquivalentFromValues(100.0, 'biweekly'), 0.01);
	}

	public function testMonthlyEquivalentMonthly(): void {
		$this->assertEqualsWithDelta(100.0, $this->calculator->getMonthlyEquivalentFromValues(100.0, 'monthly'), 0.001);
	}

	public function testMonthlyEquivalentQuarterly(): void {
		$this->assertEqualsWithDelta(33.333, $this->calculator->getMonthlyEquivalentFromValues(100.0, 'quarterly'), 0.01);
	}

	public function testMonthlyEquivalentYearly(): void {
		$this->assertEqualsWithDelta(100.0, $this->calculator->getMonthlyEquivalentFromValues(1200.0, 'yearly'), 0.001);
	}

	public function testMonthlyEquivalentOneTime(): void {
		$this->assertEqualsWithDelta(100.0, $this->calculator->getMonthlyEquivalentFromValues(1200.0, 'one-time'), 0.001);
	}

	public function testMonthlyEquivalentUnknownDefaultsToAmount(): void {
		$this->assertEqualsWithDelta(100.0, $this->calculator->getMonthlyEquivalentFromValues(100.0, 'unknown'), 0.001);
	}

	// ── getMonthlyEquivalent (with Bill entity) ─────────────────────

	public function testGetMonthlyEquivalentWithBill(): void {
		$bill = new Bill();
		$bill->setFrequency('monthly');
		$bill->setAmount(150.0);

		$this->assertEqualsWithDelta(150.0, $this->calculator->getMonthlyEquivalent($bill), 0.001);
	}

	public function testGetMonthlyEquivalentWithCustomBill(): void {
		$bill = new Bill();
		$bill->setFrequency('custom');
		$bill->setAmount(100.0);
		$bill->setCustomRecurrencePattern(json_encode(['months' => [1, 4, 7, 10]]));

		// 4 occurrences per year → 100 * 4 / 12 = 33.33
		$this->assertEqualsWithDelta(33.333, $this->calculator->getMonthlyEquivalent($bill), 0.01);
	}

	public function testGetMonthlyEquivalentCustomNoPattern(): void {
		$bill = new Bill();
		$bill->setFrequency('custom');
		$bill->setAmount(100.0);

		$this->assertEqualsWithDelta(0.0, $this->calculator->getMonthlyEquivalent($bill), 0.001);
	}

	// ── detectFrequency ─────────────────────────────────────────────

	public function testDetectFrequencyDaily(): void {
		$this->assertSame('daily', $this->calculator->detectFrequency(1.0));
	}

	public function testDetectFrequencyWeekly(): void {
		$this->assertSame('weekly', $this->calculator->detectFrequency(7.0));
	}

	public function testDetectFrequencyBiweekly(): void {
		$this->assertSame('biweekly', $this->calculator->detectFrequency(14.0));
	}

	public function testDetectFrequencyMonthly(): void {
		$this->assertSame('monthly', $this->calculator->detectFrequency(30.0));
	}

	public function testDetectFrequencyMonthly28Days(): void {
		// 28-day cycle (4-week payments) should also detect as monthly
		$this->assertSame('monthly', $this->calculator->detectFrequency(28.0));
	}

	public function testDetectFrequencyQuarterly(): void {
		$this->assertSame('quarterly', $this->calculator->detectFrequency(91.0));
	}

	public function testDetectFrequencyYearly(): void {
		$this->assertSame('yearly', $this->calculator->detectFrequency(365.0));
	}

	public function testDetectFrequencyNull(): void {
		// Intervals that don't match any pattern
		$this->assertNull($this->calculator->detectFrequency(45.0));
		$this->assertNull($this->calculator->detectFrequency(200.0));
	}

	public function testDetectFrequencyBoundaryValues(): void {
		// Lower boundary of weekly
		$this->assertSame('weekly', $this->calculator->detectFrequency(6.0));
		// Upper boundary of weekly
		$this->assertSame('weekly', $this->calculator->detectFrequency(8.0));
		// Just outside weekly
		$this->assertNull($this->calculator->detectFrequency(5.9));
		$this->assertNull($this->calculator->detectFrequency(8.1));
	}

	// ── getOccurrencesPerYear ───────────────────────────────────────

	public function testGetOccurrencesPerYear(): void {
		$this->assertSame(365, $this->calculator->getOccurrencesPerYear('daily'));
		$this->assertSame(52, $this->calculator->getOccurrencesPerYear('weekly'));
		$this->assertSame(26, $this->calculator->getOccurrencesPerYear('biweekly'));
		$this->assertSame(12, $this->calculator->getOccurrencesPerYear('monthly'));
		$this->assertSame(4, $this->calculator->getOccurrencesPerYear('quarterly'));
		$this->assertSame(1, $this->calculator->getOccurrencesPerYear('yearly'));
		$this->assertSame(1, $this->calculator->getOccurrencesPerYear('one-time'));
		$this->assertSame(12, $this->calculator->getOccurrencesPerYear('unknown'));
	}

	// ── getYearlyTotal ──────────────────────────────────────────────

	public function testGetYearlyTotal(): void {
		$this->assertEqualsWithDelta(1200.0, $this->calculator->getYearlyTotal(100.0, 'monthly'), 0.001);
		$this->assertEqualsWithDelta(5200.0, $this->calculator->getYearlyTotal(100.0, 'weekly'), 0.001);
		$this->assertEqualsWithDelta(400.0, $this->calculator->getYearlyTotal(100.0, 'quarterly'), 0.001);
		$this->assertEqualsWithDelta(100.0, $this->calculator->getYearlyTotal(100.0, 'yearly'), 0.001);
	}

	// ── getCustomOccurrencesPerYear ─────────────────────────────────

	public function testGetCustomOccurrencesPerYearMonths(): void {
		$pattern = json_encode(['months' => [1, 4, 7, 10]]);
		$this->assertSame(4, $this->calculator->getCustomOccurrencesPerYear($pattern));
	}

	public function testGetCustomOccurrencesPerYearDates(): void {
		$pattern = json_encode(['dates' => [
			['month' => 1, 'day' => 15],
			['month' => 7, 'day' => 15],
		]]);
		$this->assertSame(2, $this->calculator->getCustomOccurrencesPerYear($pattern));
	}

	public function testGetCustomOccurrencesPerYearDuplicateMonths(): void {
		$pattern = json_encode(['months' => [1, 1, 4, 4]]);
		$this->assertSame(2, $this->calculator->getCustomOccurrencesPerYear($pattern));
	}

	public function testGetCustomOccurrencesPerYearNull(): void {
		$this->assertSame(0, $this->calculator->getCustomOccurrencesPerYear(null));
	}

	public function testGetCustomOccurrencesPerYearEmpty(): void {
		$this->assertSame(0, $this->calculator->getCustomOccurrencesPerYear(''));
	}

	public function testGetCustomOccurrencesPerYearInvalidJson(): void {
		$this->assertSame(0, $this->calculator->getCustomOccurrencesPerYear('not-json'));
	}

	public function testGetCustomOccurrencesPerYearNoRecognizedKeys(): void {
		$pattern = json_encode(['intervals' => [30, 60]]);
		$this->assertSame(0, $this->calculator->getCustomOccurrencesPerYear($pattern));
	}
}
