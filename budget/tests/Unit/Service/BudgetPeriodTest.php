<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\BudgetPeriod;
use PHPUnit\Framework\TestCase;

class BudgetPeriodTest extends TestCase {

	// ── range ────────────────────────────────────────────────────────

	public function testRangeIsTheCalendarMonthForStartDayOne(): void {
		$this->assertSame(['2028-02-01', '2028-02-29'], BudgetPeriod::range('2028-02', 1));
	}

	public function testRangeStartsInTheMonthForAnEarlyStartDay(): void {
		$this->assertSame(['2026-09-10', '2026-10-09'], BudgetPeriod::range('2026-09', 10));
		$this->assertSame(['2026-09-15', '2026-10-14'], BudgetPeriod::range('2026-09', 15));
	}

	public function testRangeEndsInTheMonthForALateStartDay(): void {
		$this->assertSame(['2026-08-16', '2026-09-15'], BudgetPeriod::range('2026-09', 16));
		$this->assertSame(['2026-08-28', '2026-09-27'], BudgetPeriod::range('2026-09', 28));
	}

	public function testRangeCrossesTheYearEnd(): void {
		$this->assertSame(['2026-12-10', '2027-01-09'], BudgetPeriod::range('2026-12', 10));
		$this->assertSame(['2026-12-25', '2027-01-24'], BudgetPeriod::range('2027-01', 25));
	}

	public function testRangeClampsAStartDayToShortMonths(): void {
		$this->assertSame(['2026-01-31', '2026-02-27'], BudgetPeriod::range('2026-02', 31));
		$this->assertSame(['2026-02-28', '2026-03-30'], BudgetPeriod::range('2026-03', 31));
		$this->assertSame(['2028-01-30', '2028-02-28'], BudgetPeriod::range('2028-02', 30));
	}

	public function testConsecutiveRangesTileTheCalendar(): void {
		$gaps = [];
		foreach ([1, 2, 10, 15, 16, 25, 28, 29, 30, 31] as $startDay) {
			$month = new \DateTime('2025-11-01');
			for ($i = 0; $i < 30; $i++) {
				[, $end] = BudgetPeriod::range($month->format('Y-m'), $startDay);
				$next = (clone $month)->modify('+1 month');
				[$nextStart] = BudgetPeriod::range($next->format('Y-m'), $startDay);
				$dayAfter = (new \DateTime($end))->modify('+1 day')->format('Y-m-d');
				if ($dayAfter !== $nextStart) {
					$gaps[] = "start day $startDay: {$month->format('Y-m')} ends $end, next starts $nextStart";
				}
				$month = $next;
			}
		}

		$this->assertSame([], $gaps);
	}

	// ── monthOf ─────────────────────────────────────────────────────

	public function testMonthOfNamesAPeriodAfterTheMonthHoldingItsFifteenth(): void {
		$this->assertSame('2026-09', BudgetPeriod::monthOf('2026-09-01', '2026-09-30'));
		$this->assertSame('2026-09', BudgetPeriod::monthOf('2026-09-10', '2026-10-09'));
		$this->assertSame('2026-09', BudgetPeriod::monthOf('2026-09-15', '2026-10-14'));
		$this->assertSame('2026-10', BudgetPeriod::monthOf('2026-09-16', '2026-10-15'));
		$this->assertSame('2026-09', BudgetPeriod::monthOf('2026-08-28', '2026-09-27'));
	}

	// ── monthContaining ─────────────────────────────────────────────

	public function testMonthContainingForAnEarlyStartDay(): void {
		// Start day 10: 5 Sep is still in 10 Aug - 9 Sep, which is August
		$this->assertSame('2026-08', BudgetPeriod::monthContaining('2026-09-05', 10));
		$this->assertSame('2026-09', BudgetPeriod::monthContaining('2026-09-10', 10));
	}

	public function testMonthContainingForALateStartDay(): void {
		// Start day 28: 29 Sep is in 28 Sep - 27 Oct, which is October
		$this->assertSame('2026-09', BudgetPeriod::monthContaining('2026-09-27', 28));
		$this->assertSame('2026-10', BudgetPeriod::monthContaining('2026-09-29', 28));
	}

	public function testMonthContainingAcrossTheYearEnd(): void {
		$this->assertSame('2027-01', BudgetPeriod::monthContaining('2026-12-28', 25));
		$this->assertSame('2026-12', BudgetPeriod::monthContaining('2027-01-05', 10));
	}

	public function testMonthContainingIsTheCalendarMonthForStartDayOne(): void {
		$this->assertSame('2026-09', BudgetPeriod::monthContaining('2026-09-30', 1));
	}

	public function testEveryDayFallsInTheRangeOfItsMonth(): void {
		$misses = [];
		foreach ([1, 2, 14, 15, 16, 17, 28, 29, 30, 31] as $startDay) {
			$day = new \DateTime('2025-12-01');
			$stop = new \DateTime('2028-03-31');
			for (; $day <= $stop; $day->modify('+1 day')) {
				$date = $day->format('Y-m-d');
				$month = BudgetPeriod::monthContaining($date, $startDay);
				[$start, $end] = BudgetPeriod::range($month, $startDay);
				if ($date < $start || $date > $end || BudgetPeriod::monthOf($start, $end) !== $month) {
					$misses[] = "start day $startDay, $date: $month = $start..$end";
				}
			}
		}

		$this->assertSame([], $misses);
	}
}
