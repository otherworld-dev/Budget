<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Bill;

use OCA\Budget\Db\Bill;
use OCA\Budget\Db\Category;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\Bill\BillIcsService;
use OCA\Budget\Service\Bill\BudgetCalendarEstimates;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\SettingService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class BillIcsServiceTest extends TestCase {
	private BillIcsService $service;
	private BillService $billService;
	private FrequencyCalculator $frequencyCalculator;

	protected function setUp(): void {
		$this->billService = $this->createMock(BillService::class);
		$this->billService->method('enrichBillsWithCurrency')->willReturnArgument(0);
		$this->frequencyCalculator = new FrequencyCalculator();

		$this->service = $this->buildService($this->frequencyCalculator);
	}

	private function buildService(FrequencyCalculator $calculator, ?BudgetCalendarEstimates $estimates = null): BillIcsService {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $params = []) => vsprintf(str_replace('%1$s', '%s', $text), $params));
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('getUserLanguage')->willReturn('en');
		$l10nFactory->method('get')->willReturn($l);
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('en');
		$config->method('getSystemValueString')->willReturn('octest');
		$amountFormatter = new AmountFormatter($this->createMock(SettingService::class));

		return new BillIcsService(
			$this->billService,
			$calculator,
			$amountFormatter,
			$l10nFactory,
			$config,
			$estimates
		);
	}

	private function makeBill(array $overrides = []): Bill {
		$bill = new Bill();
		$bill->setId(1);
		$bill->setName('Rent');
		$bill->setAmount(900.0);
		$bill->setFrequency('monthly');
		$bill->setDueDay(15);
		$bill->setNextDueDate(date('Y-m-d', strtotime('+1 day')));
		$bill->setCurrency('USD');
		foreach ($overrides as $key => $value) {
			$bill->{'set' . ucfirst($key)}($value);
		}
		return $bill;
	}

	public function testEmptyFeedIsValidCalendar(): void {
		$this->billService->method('findActive')->willReturn([]);

		$ics = $this->service->generateBillsFeed('alice');

		$this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
		$this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
		$this->assertStringContainsString('X-WR-CALNAME:Bills', $ics);
		$this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
	}

	public function testOneTimeBillEmitsSingleEvent(): void {
		$due = date('Y-m-d', strtotime('+10 days'));
		$this->billService->method('findActive')->willReturn([
			$this->makeBill(['frequency' => 'one-time', 'nextDueDate' => $due]),
		]);

		$ics = $this->service->generateBillsFeed('alice');

		$this->assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
		$compact = str_replace('-', '', $due);
		$this->assertStringContainsString("DTSTART;VALUE=DATE:{$compact}", $ics);
		$this->assertStringContainsString("UID:budget-bill-1-{$compact}@octest", $ics);
		$this->assertStringContainsString('SUMMARY:Rent ($900.00)', $ics);
	}

	public function testOneTimeBillBeyondHorizonExcluded(): void {
		$this->billService->method('findActive')->willReturn([
			$this->makeBill(['frequency' => 'one-time', 'nextDueDate' => date('Y-m-d', strtotime('+14 months'))]),
		]);

		$ics = $this->service->generateBillsFeed('alice');

		$this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
	}

	public function testMonthlyBillMaterializedAcrossHorizon(): void {
		$this->billService->method('findActive')->willReturn([$this->makeBill()]);

		$ics = $this->service->generateBillsFeed('alice');

		$count = substr_count($ics, 'BEGIN:VEVENT');
		$this->assertGreaterThanOrEqual(11, $count);
		$this->assertLessThanOrEqual(14, $count);
	}

	public function testRemainingPaymentsClipsOccurrences(): void {
		$this->billService->method('findActive')->willReturn([
			$this->makeBill(['remainingPayments' => 3]),
		]);

		$ics = $this->service->generateBillsFeed('alice');

		$this->assertSame(3, substr_count($ics, 'BEGIN:VEVENT'));
	}

	public function testEndDateClipsOccurrences(): void {
		$endDate = date('Y-m-d', strtotime('+70 days'));
		$this->billService->method('findActive')->willReturn([
			$this->makeBill(['endDate' => $endDate]),
		]);

		$ics = $this->service->generateBillsFeed('alice');

		$count = substr_count($ics, 'BEGIN:VEVENT');
		$this->assertGreaterThanOrEqual(1, $count);
		$this->assertLessThanOrEqual(4, $count);
		// No DTSTART beyond the end date
		preg_match_all('/DTSTART;VALUE=DATE:(\d{8})/', $ics, $matches);
		$endCompact = str_replace('-', '', $endDate);
		foreach ($matches[1] as $start) {
			$this->assertLessThanOrEqual($endCompact, $start);
		}
	}

	public function testNonAdvancingFrequencyDoesNotLoopForever(): void {
		$calculator = $this->createMock(FrequencyCalculator::class);
		$calculator->method('calculateNextDueDate')->willReturnArgument(3); // returns fromDate unchanged
		$service = $this->buildService($calculator);
		$this->billService->method('findActive')->willReturn([$this->makeBill()]);

		$ics = $service->generateBillsFeed('alice');

		$this->assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
	}

	public function testTextIsRfc5545Escaped(): void {
		$this->billService->method('findActive')->willReturn([
			$this->makeBill([
				'frequency' => 'one-time',
				'name' => 'Net; flix, sub',
				'notes' => "Line1\nLine2",
			]),
		]);

		$ics = $this->service->generateBillsFeed('alice');

		$this->assertStringContainsString('SUMMARY:Net\\; flix\\, sub', $ics);
		$this->assertStringContainsString('DESCRIPTION:Line1\\nLine2', $ics);
	}

	public function testLongLinesAreFoldedTo75Octets(): void {
		$this->billService->method('findActive')->willReturn([
			$this->makeBill([
				'frequency' => 'one-time',
				'name' => str_repeat('Very long bill name ', 10),
			]),
		]);

		$ics = $this->service->generateBillsFeed('alice');

		foreach (explode("\r\n", $ics) as $line) {
			$this->assertLessThanOrEqual(75, strlen($line), "Line exceeds 75 octets: {$line}");
		}
		// Folded continuation lines start with a space
		$this->assertMatchesRegularExpression('/\r\n [^\r\n]/', $ics);
	}

	public function testReminderDaysProduceAlarm(): void {
		$this->billService->method('findActive')->willReturn([
			$this->makeBill(['frequency' => 'one-time', 'reminderDays' => 3]),
		]);

		$ics = $this->service->generateBillsFeed('alice');

		$this->assertStringContainsString('BEGIN:VALARM', $ics);
		$this->assertStringContainsString('TRIGGER:-P3D', $ics);
		$this->assertStringContainsString('END:VALARM', $ics);
	}

	public function testNoAlarmWithoutReminderDays(): void {
		$this->billService->method('findActive')->willReturn([
			$this->makeBill(['frequency' => 'one-time']),
		]);

		$ics = $this->service->generateBillsFeed('alice');

		$this->assertStringNotContainsString('BEGIN:VALARM', $ics);
	}

	/**
	 * A budget put in the Bills Calendar (#459) is an all-day event on the
	 * first of each month to the horizon, with its month's share; the UID
	 * is the category and the month, so a refresh never duplicates it.
	 *
	 * @param string[] $asked set to the months forMonths() was asked for
	 */
	private function feedWithBudget(array &$asked): string {
		$estimates = $this->createMock(BudgetCalendarEstimates::class);
		$estimates->method('forMonths')->willReturnCallback(function (string $userId, array $months) use (&$asked): array {
			$asked = $months;
			$food = new Category();
			$food->setId(9);
			$food->setName('Lebensmittel, Getränke');
			return ['currency' => 'CHF', 'rows' => [
				['category' => $food, 'amounts' => array_fill_keys($months, '700.00'), 'budgets' => []],
			]];
		});
		$this->billService->method('findActive')->willReturn([]);
		return $this->buildService($this->frequencyCalculator, $estimates)->generateBillsFeed('alice');
	}

	public function testBudgetsAreAnEventOnTheFirstOfEachMonth(): void {
		$asked = [];
		$ics = $this->feedWithBudget($asked);

		// This month to the horizon's, twelve months on: thirteen months
		$this->assertCount(13, $asked);
		$this->assertSame(date('Y-m'), $asked[0]);
		$this->assertSame(date('Y-m', strtotime('first day of this month +12 months')), $asked[12]);
		$this->assertSame(13, substr_count($ics, 'BEGIN:VEVENT'));

		$first = date('Ym') . '01';
		$this->assertStringContainsString("UID:budget-estimate-9-{$first}@octest\r\n", $ics);
		$this->assertStringContainsString("DTSTART;VALUE=DATE:{$first}\r\n", $ics);
		// Escaped as the bills' titles are
		$this->assertMatchesRegularExpression('/SUMMARY:Lebensmittel\\\\, Getränke budget: [^\r]*700/u', $ics);
		$this->assertStringContainsString('TRANSP:TRANSPARENT', $ics);
	}

	public function testBudgetEventUidsAreStableAcrossRefreshes(): void {
		$asked = [];
		$first = $this->feedWithBudget($asked);
		preg_match_all('/^UID:(.*)$/m', $first, $uids);
		$second = $this->buildService($this->frequencyCalculator, $this->createConfiguredMock(BudgetCalendarEstimates::class, [
			'forMonths' => ['currency' => 'CHF', 'rows' => [[
				'category' => (function () {
					$c = new Category();
					$c->setId(9);
					$c->setName('Lebensmittel, Getränke');
					return $c;
				})(),
				// The budget changed since: the same events, new titles
				'amounts' => array_fill_keys($asked, '750.00'),
				'budgets' => [],
			]]],
		]))->generateBillsFeed('alice');
		preg_match_all('/^UID:(.*)$/m', $second, $uidsAgain);

		$this->assertCount(13, $uids[1]);
		$this->assertSame($uids[1], $uidsAgain[1]);
		$this->assertStringContainsString('750', $second);
	}

	public function testNoBudgetsWithoutTheService(): void {
		$this->billService->method('findActive')->willReturn([]);

		$this->assertStringNotContainsString('budget-estimate', $this->service->generateBillsFeed('alice'));
	}
}
