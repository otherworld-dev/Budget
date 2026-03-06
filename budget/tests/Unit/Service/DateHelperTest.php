<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use DateTime;
use OCA\Budget\Service\DateHelper;
use PHPUnit\Framework\TestCase;

class DateHelperTest extends TestCase {
	private DateHelper $helper;

	protected function setUp(): void {
		$this->helper = new DateHelper();
	}

	// ── Constants ───────────────────────────────────────────────────

	public function testConstants(): void {
		$this->assertSame('Y-m-d H:i:s', DateHelper::DB_DATETIME_FORMAT);
		$this->assertSame('Y-m-d', DateHelper::DB_DATE_FORMAT);
		$this->assertSame('Y-m', DateHelper::MONTH_FORMAT);
	}

	// ── now / today ─────────────────────────────────────────────────

	public function testNowReturnsValidDatetime(): void {
		$result = $this->helper->now();
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
	}

	public function testTodayReturnsValidDate(): void {
		$result = $this->helper->today();
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result);
		$this->assertSame(date('Y-m-d'), $result);
	}

	// ── formatForDb / formatDateForDb / formatMonth ─────────────────

	public function testFormatForDb(): void {
		$date = new DateTime('2024-03-15 14:30:00');
		$this->assertSame('2024-03-15 14:30:00', $this->helper->formatForDb($date));
	}

	public function testFormatDateForDb(): void {
		$date = new DateTime('2024-03-15 14:30:00');
		$this->assertSame('2024-03-15', $this->helper->formatDateForDb($date));
	}

	public function testFormatMonth(): void {
		$date = new DateTime('2024-03-15');
		$this->assertSame('2024-03', $this->helper->formatMonth($date));
	}

	// ── parse / tryParse ────────────────────────────────────────────

	public function testParseDatetime(): void {
		$result = $this->helper->parse('2024-03-15 14:30:00');
		$this->assertSame('2024-03-15', $result->format('Y-m-d'));
		$this->assertSame('14:30:00', $result->format('H:i:s'));
	}

	public function testParseDateOnly(): void {
		$result = $this->helper->parse('2024-03-15');
		$this->assertSame('2024-03-15', $result->format('Y-m-d'));
	}

	public function testParseNaturalLanguage(): void {
		$result = $this->helper->parse('2024-01-01');
		$this->assertSame('2024-01-01', $result->format('Y-m-d'));
	}

	public function testTryParseValid(): void {
		$result = $this->helper->tryParse('2024-03-15');
		$this->assertNotNull($result);
		$this->assertSame('2024-03-15', $result->format('Y-m-d'));
	}

	public function testTryParseInvalid(): void {
		$result = $this->helper->tryParse('not-a-date');
		$this->assertNull($result);
	}

	// ── getMonthStart / getMonthEnd ─────────────────────────────────

	public function testGetMonthStart(): void {
		$date = new DateTime('2024-03-15');
		$result = $this->helper->getMonthStart($date);
		$this->assertSame('2024-03-01 00:00:00', $result->format('Y-m-d H:i:s'));
	}

	public function testGetMonthEnd(): void {
		$date = new DateTime('2024-03-15');
		$result = $this->helper->getMonthEnd($date);
		$this->assertSame('2024-03-31 23:59:59', $result->format('Y-m-d H:i:s'));
	}

	public function testGetMonthEndFebruary(): void {
		$date = new DateTime('2024-02-15');
		$result = $this->helper->getMonthEnd($date);
		$this->assertSame('2024-02-29 23:59:59', $result->format('Y-m-d H:i:s'));
	}

	public function testGetMonthEndFebruaryNonLeap(): void {
		$date = new DateTime('2023-02-15');
		$result = $this->helper->getMonthEnd($date);
		$this->assertSame('2023-02-28 23:59:59', $result->format('Y-m-d H:i:s'));
	}

	public function testGetMonthStartDefaultsToNow(): void {
		$result = $this->helper->getMonthStart();
		$this->assertSame(date('Y-m') . '-01 00:00:00', $result->format('Y-m-d H:i:s'));
	}

	// ── getYearStart / getYearEnd ───────────────────────────────────

	public function testGetYearStart(): void {
		$date = new DateTime('2024-06-15');
		$result = $this->helper->getYearStart($date);
		$this->assertSame('2024-01-01 00:00:00', $result->format('Y-m-d H:i:s'));
	}

	public function testGetYearEnd(): void {
		$date = new DateTime('2024-06-15');
		$result = $this->helper->getYearEnd($date);
		$this->assertSame('2024-12-31 23:59:59', $result->format('Y-m-d H:i:s'));
	}

	// ── getDateRange ────────────────────────────────────────────────

	public function testGetDateRangePositiveMonths(): void {
		$from = new DateTime('2024-01-15');
		$result = $this->helper->getDateRange(3, $from);
		$this->assertSame('2024-01-15', $result['start']);
		$this->assertSame('2024-04-15', $result['end']);
	}

	public function testGetDateRangeNegativeMonths(): void {
		$from = new DateTime('2024-06-15');
		$result = $this->helper->getDateRange(-3, $from);
		$this->assertSame('2024-03-15', $result['start']);
		$this->assertSame('2024-06-15', $result['end']);
	}

	public function testGetDateRangeZeroMonths(): void {
		$from = new DateTime('2024-03-15');
		$result = $this->helper->getDateRange(0, $from);
		$this->assertSame('2024-03-15', $result['start']);
		$this->assertSame('2024-03-15', $result['end']);
	}

	// ── getMonthsBetween / getDaysBetween ───────────────────────────

	public function testGetMonthsBetween(): void {
		$start = new DateTime('2024-01-01');
		$end = new DateTime('2024-06-01');
		$this->assertSame(5, $this->helper->getMonthsBetween($start, $end));
	}

	public function testGetMonthsBetweenAcrossYears(): void {
		$start = new DateTime('2023-11-01');
		$end = new DateTime('2024-02-01');
		$this->assertSame(3, $this->helper->getMonthsBetween($start, $end));
	}

	public function testGetDaysBetween(): void {
		$start = new DateTime('2024-03-01');
		$end = new DateTime('2024-03-15');
		$this->assertSame(14, $this->helper->getDaysBetween($start, $end));
	}

	public function testGetDaysBetweenSameDate(): void {
		$date = new DateTime('2024-03-15');
		$this->assertSame(0, $this->helper->getDaysBetween($date, $date));
	}

	// ── isPast / isFuture / isToday ─────────────────────────────────

	public function testIsPast(): void {
		$past = new DateTime('2000-01-01');
		$this->assertTrue($this->helper->isPast($past));
	}

	public function testIsPastFuture(): void {
		$future = new DateTime('2099-01-01');
		$this->assertFalse($this->helper->isPast($future));
	}

	public function testIsFuture(): void {
		$future = new DateTime('2099-01-01');
		$this->assertTrue($this->helper->isFuture($future));
	}

	public function testIsFuturePast(): void {
		$past = new DateTime('2000-01-01');
		$this->assertFalse($this->helper->isFuture($past));
	}

	public function testIsToday(): void {
		$today = new DateTime();
		$this->assertTrue($this->helper->isToday($today));
	}

	public function testIsTodayYesterday(): void {
		$yesterday = new DateTime('yesterday');
		$this->assertFalse($this->helper->isToday($yesterday));
	}

	// ── isWithinRange ───────────────────────────────────────────────

	public function testIsWithinRangeInside(): void {
		$date = new DateTime('2024-03-15');
		$start = new DateTime('2024-03-01');
		$end = new DateTime('2024-03-31');
		$this->assertTrue($this->helper->isWithinRange($date, $start, $end));
	}

	public function testIsWithinRangeOnBoundary(): void {
		$date = new DateTime('2024-03-01');
		$start = new DateTime('2024-03-01');
		$end = new DateTime('2024-03-31');
		$this->assertTrue($this->helper->isWithinRange($date, $start, $end));
	}

	public function testIsWithinRangeOutside(): void {
		$date = new DateTime('2024-04-01');
		$start = new DateTime('2024-03-01');
		$end = new DateTime('2024-03-31');
		$this->assertFalse($this->helper->isWithinRange($date, $start, $end));
	}

	// ── isCurrentMonth ──────────────────────────────────────────────

	public function testIsCurrentMonth(): void {
		$now = new DateTime();
		$this->assertTrue($this->helper->isCurrentMonth($now));
	}

	public function testIsCurrentMonthPast(): void {
		$past = new DateTime('2000-01-01');
		$this->assertFalse($this->helper->isCurrentMonth($past));
	}

	// ── addMonths / subMonths / addDays ─────────────────────────────

	public function testAddMonths(): void {
		$date = new DateTime('2024-01-15');
		$result = $this->helper->addMonths($date, 3);
		$this->assertSame('2024-04-15', $result->format('Y-m-d'));
	}

	public function testAddMonthsAcrossYear(): void {
		$date = new DateTime('2024-11-15');
		$result = $this->helper->addMonths($date, 3);
		$this->assertSame('2025-02-15', $result->format('Y-m-d'));
	}

	public function testSubMonths(): void {
		$date = new DateTime('2024-06-15');
		$result = $this->helper->subMonths($date, 3);
		$this->assertSame('2024-03-15', $result->format('Y-m-d'));
	}

	public function testSubMonthsAcrossYear(): void {
		$date = new DateTime('2024-02-15');
		$result = $this->helper->subMonths($date, 3);
		$this->assertSame('2023-11-15', $result->format('Y-m-d'));
	}

	public function testAddDays(): void {
		$date = new DateTime('2024-03-15');
		$result = $this->helper->addDays($date, 10);
		$this->assertSame('2024-03-25', $result->format('Y-m-d'));
	}

	public function testAddDaysAcrossMonth(): void {
		$date = new DateTime('2024-03-25');
		$result = $this->helper->addDays($date, 10);
		$this->assertSame('2024-04-04', $result->format('Y-m-d'));
	}

	// ── getMonthLabels / getMonthKeys ───────────────────────────────

	public function testGetMonthLabels(): void {
		$from = new DateTime('2024-01-01');
		$labels = $this->helper->getMonthLabels(3, $from);
		$this->assertCount(3, $labels);
		$this->assertSame('Jan 2024', $labels[0]);
		$this->assertSame('Feb 2024', $labels[1]);
		$this->assertSame('Mar 2024', $labels[2]);
	}

	public function testGetMonthLabelsAcrossYear(): void {
		$from = new DateTime('2024-11-01');
		$labels = $this->helper->getMonthLabels(4, $from);
		$this->assertSame('Nov 2024', $labels[0]);
		$this->assertSame('Feb 2025', $labels[3]);
	}

	public function testGetMonthKeys(): void {
		$from = new DateTime('2024-01-01');
		$keys = $this->helper->getMonthKeys(3, $from);
		$this->assertCount(3, $keys);
		$this->assertSame('2024-01', $keys[0]);
		$this->assertSame('2024-02', $keys[1]);
		$this->assertSame('2024-03', $keys[2]);
	}

	public function testGetMonthKeysZero(): void {
		$from = new DateTime('2024-01-01');
		$keys = $this->helper->getMonthKeys(0, $from);
		$this->assertEmpty($keys);
	}

	// ── startOfDay / endOfDay ───────────────────────────────────────

	public function testStartOfDay(): void {
		$date = new DateTime('2024-03-15 14:30:45');
		$result = $this->helper->startOfDay($date);
		$this->assertSame('2024-03-15 00:00:00', $result->format('Y-m-d H:i:s'));
	}

	public function testEndOfDay(): void {
		$date = new DateTime('2024-03-15 14:30:45');
		$result = $this->helper->endOfDay($date);
		$this->assertSame('2024-03-15 23:59:59', $result->format('Y-m-d H:i:s'));
	}

	// ── getNextDayOfMonth ───────────────────────────────────────────

	public function testGetNextDayOfMonthFutureDayThisMonth(): void {
		$from = new DateTime('2024-03-10');
		$result = $this->helper->getNextDayOfMonth(25, $from);
		$this->assertSame('2024-03-25', $result->format('Y-m-d'));
	}

	public function testGetNextDayOfMonthPastDayNextMonth(): void {
		$from = new DateTime('2024-03-25');
		$result = $this->helper->getNextDayOfMonth(10, $from);
		$this->assertSame('2024-04-10', $result->format('Y-m-d'));
	}

	public function testGetNextDayOfMonthSameDayNextMonth(): void {
		// When currentDay >= targetDay, moves to next month
		$from = new DateTime('2024-03-15');
		$result = $this->helper->getNextDayOfMonth(15, $from);
		$this->assertSame('2024-04-15', $result->format('Y-m-d'));
	}

	public function testGetNextDayOfMonthDecemberRollsToJanuary(): void {
		$from = new DateTime('2024-12-25');
		$result = $this->helper->getNextDayOfMonth(10, $from);
		$this->assertSame('2025-01-10', $result->format('Y-m-d'));
	}

	public function testGetNextDayOfMonthClampsTo28(): void {
		// Day 31 gets clamped to 28 for safety
		$from = new DateTime('2024-03-01');
		$result = $this->helper->getNextDayOfMonth(31, $from);
		$this->assertSame('2024-03-28', $result->format('Y-m-d'));
	}

	// ── getQuarter / getQuarterStart / getQuarterEnd ────────────────

	public function testGetQuarter(): void {
		$this->assertSame(1, $this->helper->getQuarter(new DateTime('2024-01-15')));
		$this->assertSame(1, $this->helper->getQuarter(new DateTime('2024-03-31')));
		$this->assertSame(2, $this->helper->getQuarter(new DateTime('2024-04-01')));
		$this->assertSame(2, $this->helper->getQuarter(new DateTime('2024-06-30')));
		$this->assertSame(3, $this->helper->getQuarter(new DateTime('2024-07-01')));
		$this->assertSame(3, $this->helper->getQuarter(new DateTime('2024-09-30')));
		$this->assertSame(4, $this->helper->getQuarter(new DateTime('2024-10-01')));
		$this->assertSame(4, $this->helper->getQuarter(new DateTime('2024-12-31')));
	}

	public function testGetQuarterStart(): void {
		$date = new DateTime('2024-05-15');
		$result = $this->helper->getQuarterStart($date);
		$this->assertSame('2024-04-01 00:00:00', $result->format('Y-m-d H:i:s'));
	}

	public function testGetQuarterStartQ1(): void {
		$date = new DateTime('2024-02-15');
		$result = $this->helper->getQuarterStart($date);
		$this->assertSame('2024-01-01 00:00:00', $result->format('Y-m-d H:i:s'));
	}

	public function testGetQuarterEnd(): void {
		$date = new DateTime('2024-05-15');
		$result = $this->helper->getQuarterEnd($date);
		$this->assertSame('2024-06-30 23:59:59', $result->format('Y-m-d H:i:s'));
	}

	public function testGetQuarterEndQ4(): void {
		$date = new DateTime('2024-11-15');
		$result = $this->helper->getQuarterEnd($date);
		$this->assertSame('2024-12-31 23:59:59', $result->format('Y-m-d H:i:s'));
	}

	// ── Immutability: original date should not be mutated ───────────

	public function testAddMonthsDoesNotMutateOriginal(): void {
		$original = new DateTime('2024-01-15');
		$this->helper->addMonths($original, 3);
		$this->assertSame('2024-01-15', $original->format('Y-m-d'));
	}

	public function testSubMonthsDoesNotMutateOriginal(): void {
		$original = new DateTime('2024-06-15');
		$this->helper->subMonths($original, 3);
		$this->assertSame('2024-06-15', $original->format('Y-m-d'));
	}

	public function testAddDaysDoesNotMutateOriginal(): void {
		$original = new DateTime('2024-03-15');
		$this->helper->addDays($original, 10);
		$this->assertSame('2024-03-15', $original->format('Y-m-d'));
	}
}
