<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Bill;

use OCA\Budget\Service\Bill\FrequencyCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The schedule itself, with no "today" in it: which dates a bill, income or
 * transfer falls on. Every surface that lists, pays, projects or exports an
 * occurrence asks these, so they all agree.
 */
class FrequencyOccurrencesTest extends TestCase {
	private FrequencyCalculator $calc;

	protected function setUp(): void {
		$this->calc = new FrequencyCalculator();
	}

	/** @return array<string, array{0: string, 1: ?int, 2: ?int, 3: string, 4: string, 5: ?string, 6: ?string, 7: string[]}> */
	public static function schedules(): array {
		return [
			// A day past the end of a short month lands on its last day, and
			// the next month goes back to the 31st (F4: Feb was skipped)
			'monthly on the 31st' => ['monthly', 31, null, '2026-01-01', '2026-05-31', null, null,
				['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31']],
			'monthly on the 29th in a leap year' => ['monthly', 29, null, '2028-01-01', '2028-03-31', null, null,
				['2028-01-29', '2028-02-29', '2028-03-29']],
			// The due month repeats every three months round the year (F5)
			'quarterly from November' => ['quarterly', 15, 11, '2026-01-01', '2026-12-31', null, null,
				['2026-02-15', '2026-05-15', '2026-08-15', '2026-11-15']],
			'quarterly with no month follows the start date' => ['quarterly', 10, null, '2026-01-01', '2026-12-31', null, '2026-02-10',
				['2026-02-10', '2026-05-10', '2026-08-10', '2026-11-10']],
			'half-yearly from September' => ['semi-annually', 1, 9, '2026-01-01', '2026-12-31', null, null,
				['2026-03-01', '2026-09-01']],
			'yearly on 29 February' => ['yearly', 29, 2, '2027-01-01', '2028-12-31', null, null,
				['2027-02-28', '2028-02-29']],
			// Twice a month, fifteen days apart, the later one clamped to the
			// month's end; February is never skipped (F6)
			'semi-monthly from the 1st' => ['semi-monthly', 1, null, '2026-02-01', '2026-03-31', null, null,
				['2026-02-01', '2026-02-16', '2026-03-01', '2026-03-16']],
			'semi-monthly from the 15th' => ['semi-monthly', 15, null, '2026-02-01', '2026-03-31', null, null,
				['2026-02-15', '2026-02-28', '2026-03-15', '2026-03-30']],
			'semi-monthly from the 20th' => ['semi-monthly', 20, null, '2026-02-01', '2026-02-28', null, null,
				['2026-02-05', '2026-02-20']],
			'semi-monthly from the 31st' => ['semi-monthly', 31, null, '2026-02-01', '2026-03-31', null, null,
				['2026-02-16', '2026-02-28', '2026-03-16', '2026-03-31']],
			'weekly from a start date' => ['weekly', null, null, '2026-09-01', '2026-09-30', null, '2026-09-04',
				['2026-09-04', '2026-09-11', '2026-09-18', '2026-09-25']],
			'bi-weekly from a start date' => ['biweekly', null, null, '2026-09-01', '2026-10-05', null, '2026-09-04',
				['2026-09-04', '2026-09-18', '2026-10-02']],
			'weekly on a weekday' => ['weekly', 5, null, '2026-09-01', '2026-09-20', null, null,
				['2026-09-04', '2026-09-11', '2026-09-18']],
			'daily' => ['daily', null, null, '2026-09-29', '2026-10-02', null, null,
				['2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02']],
			'custom months' => ['custom', 10, null, '2026-01-01', '2026-12-31', '{"months":[9,3]}', null,
				['2026-03-10', '2026-09-10']],
			'custom dates' => ['custom', null, null, '2026-01-01', '2026-12-31', '{"dates":[{"month":7,"day":31},{"month":1,"day":15}]}', null,
				['2026-01-15', '2026-07-31']],
			// No pattern at all: monthly on the due day, never every day (F83)
			'custom with no pattern' => ['custom', 5, null, '2026-01-01', '2026-03-31', null, null,
				['2026-01-05', '2026-02-05', '2026-03-05']],
			'one-time inside the range' => ['one-time', null, null, '2026-08-01', '2026-08-31', null, '2026-08-20',
				['2026-08-20']],
			'one-time outside the range' => ['one-time', null, null, '2026-09-01', '2026-09-30', null, '2026-08-20',
				[]],
			// Nothing occurs before the start date (#268)
			'monthly starting mid-year' => ['monthly', 1, null, '2026-01-01', '2026-06-30', null, '2026-04-15',
				['2026-05-01', '2026-06-01']],
		];
	}

	#[DataProvider('schedules')]
	public function testOccurrencesBetween(string $frequency, ?int $day, ?int $month, string $from, string $to,
		?string $pattern, ?string $anchor, array $expected): void {
		$this->assertSame($expected, $this->calc->occurrencesBetween($frequency, $day, $month, $from, $to, $pattern, $anchor));
	}

	public function testOccurrenceAfterStepsOneOccurrence(): void {
		$this->assertSame('2026-02-28', $this->calc->occurrenceAfter('monthly', 31, null, '2026-01-31'));
		$this->assertSame('2026-03-31', $this->calc->occurrenceAfter('monthly', 31, null, '2026-02-28'));
		$this->assertSame('2026-02-15', $this->calc->occurrenceAfter('quarterly', 15, 11, '2025-11-15'));
		$this->assertSame('2026-09-18', $this->calc->occurrenceAfter('biweekly', null, null, '2026-09-04', null, '2026-09-04'));
		// Paying an occurrence early still moves on exactly one
		$this->assertSame('2026-11-15', $this->calc->occurrenceAfter('monthly', 15, null, '2026-10-15'));
	}

	public function testAOneTimeItemHasNothingAfterItsDate(): void {
		$this->assertNull($this->calc->occurrenceAfter('one-time', null, null, '2026-08-20', null, '2026-08-20'));
		$this->assertSame('2026-08-20', $this->calc->occurrenceAfter('one-time', null, null, '2026-08-01', null, '2026-08-20'));
	}

	public function testOccurrenceOnOrAfterIncludesTheDayItself(): void {
		// Due today is due today, not next month (F21)
		$this->assertSame('2026-10-02', $this->calc->occurrenceOnOrAfter('monthly', 2, null, '2026-10-02'));
		$this->assertSame('2026-11-02', $this->calc->occurrenceOnOrAfter('monthly', 2, null, '2026-10-03'));
		$this->assertSame('2026-12-01', $this->calc->occurrenceOnOrAfter('monthly', 1, null, '2026-10-02', null, '2026-11-15'));
	}

	public function testPeriodStartIsWhereAPendingOccurrencesPeriodBegins(): void {
		// A schedule change moves the pending occurrence within its period:
		// the month for calendar schedules, the nearest day for weekly ones
		$this->assertSame('2026-10-01', $this->calc->periodStart('monthly', '2026-10-03'));
		$this->assertSame('2026-11-01', $this->calc->periodStart('quarterly', '2026-11-15'));
		$this->assertSame('2026-10-09', $this->calc->periodStart('semi-monthly', '2026-10-16'));
		$this->assertSame('2026-10-01', $this->calc->periodStart('semi-monthly', '2026-10-03'));
		$this->assertSame('2026-10-06', $this->calc->periodStart('weekly', '2026-10-09'));
		$this->assertSame('2026-10-02', $this->calc->periodStart('biweekly', '2026-10-09'));
		$this->assertSame('2026-10-09', $this->calc->periodStart('daily', '2026-10-09'));
	}

	public function testOccurrenceBeforeIsTheLastOneEarlier(): void {
		$this->assertSame('2026-10-15', $this->calc->occurrenceBefore('monthly', 15, null, '2026-11-15'));
		$this->assertSame('2026-02-28', $this->calc->occurrenceBefore('monthly', 31, null, '2026-03-31'));
		$this->assertNull($this->calc->occurrenceBefore('monthly', 15, null, '2026-05-15', null, '2026-05-01'));
	}

	/** @return array<string, array{0: array, 1: string, 2: array, 3: string}> */
	public static function reschedules(): array {
		return [
			// The pending payment moves within its month
			'monthly day later' => [['monthly', 15], '2026-11-15', ['monthly', 25], '2026-11-25'],
			'monthly day earlier' => [['monthly', 15], '2026-11-15', ['monthly', 1], '2026-11-01'],
			// ...but never back onto one the old schedule already settled: the
			// weekly payment a week ago was paid, so the fortnight is the next one
			'weekly to bi-weekly' => [['weekly', null, null, '2026-09-14'], '2026-10-05', ['biweekly', null, null, '2026-09-14'], '2026-10-12'],
			// A legacy bi-weekly schedule without a start date counts from its
			// own pending date, whatever week it fell in
			'anchoring a legacy bi-weekly' => [['biweekly', 1], '2026-10-19', ['biweekly', null, null, '2026-09-21'], '2026-10-19'],
			'quarterly month' => [['quarterly', 1, 1], '2027-01-01', ['quarterly', 1, 2], '2027-02-01'],
		];
	}

	#[DataProvider('reschedules')]
	public function testRescheduleMovesThePendingOccurrence(array $old, string $pending, array $new, string $expected): void {
		$schedule = fn (array $s) => ['frequency' => $s[0], 'dueDay' => $s[1] ?? null, 'dueMonth' => $s[2] ?? null,
			'pattern' => null, 'anchor' => $s[3] ?? null];

		$this->assertSame($expected, $this->calc->reschedule($schedule($old), $pending, $schedule($new)));
	}

	public function testAYearlyItemTwoYearsOutIsStillFound(): void {
		$this->assertSame('2028-02-29', $this->calc->occurrenceAfter('yearly', 29, 2, '2027-02-28'));
	}
}
