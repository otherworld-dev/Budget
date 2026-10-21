<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\UserClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Bills by Month counts every payment of a bill once across the years,
 * checked on a few hundred seeded random bills (#399).
 *
 * The review of the year-boundary fixes generated bills of six
 * frequencies due around the turn of 2026, paid with doubles, forgotten
 * last payments and late rows, and compared the 2025, 2026 and 2027
 * totals with every payment plus every date still owed. 17 of 400 were
 * off by one payment: a second payment of a due date whose first, Mark
 * Paid's, was in the other year found no date in its own year and was
 * dropped. The named cases are the ones it reported.
 */
class BillCalendarCrossYearRandomTest extends TestCase {
	private const TODAY = '2026-10-20';

	private BillService $service;
	/** @var array<int, Bill> */
	private array $stored = [];
	/** @var Transaction[] */
	private array $rows = [];
	/** @var list<array{0: int[], 1: ?string}> bill ids and lower date bound of each findRecordedBillTransactions() call */
	private array $allRowQueries = [];
	/** Whether the mock ignores the lower bound, to show it changes nothing */
	private bool $ignoreBound = false;
	/** @var Account[] */
	private array $accounts = [];

	protected function setUp(): void {
		$bills = $this->createMock(BillMapper::class);
		$bills->method('find')->willReturnCallback(fn (int $id) => $this->stored[$id]);
		$bills->method('findByType')->willReturnCallback(fn () => array_values($this->stored));

		// As the mapper reads them: no placeholders; each query hydrates its
		// own entities
		$recorded = fn (array $ids): array => array_map(fn (Transaction $tx) => clone $tx, array_values(array_filter(
			$this->rows,
			fn (Transaction $tx): bool => in_array($tx->getBillId(), $ids, true) && $tx->getStatus() !== 'scheduled'
		)));
		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('findRecordedBillTransactions')->willReturnCallback(function (array $ids, ?string $from = null) use ($recorded): array {
			$this->allRowQueries[] = [$ids, $from];
			$rows = $recorded($ids);
			if ($from !== null && !$this->ignoreBound) {
				$rows = array_values(array_filter($rows, fn (Transaction $tx): bool => substr($tx->getDate(), 0, 10) >= $from));
			}
			return $rows;
		});
		$transactions->method('findBillPaymentsInYear')->willReturnCallback(function (array $ids, int $year) use ($recorded): array {
			$rows = array_values(array_filter($recorded($ids), fn (Transaction $tx): bool => (int)substr($tx->getDate(), 0, 4) === $year));
			usort($rows, fn (Transaction $a, Transaction $b): int => strcmp($a->getDate(), $b->getDate()));
			return $rows;
		});
		$transactions->method('getBalanceAsOf')->willReturn(1000.0);

		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturnCallback(fn () => $this->accounts);
		$accounts->method('findByIds')->willReturn([]);
		$currency = $this->createMock(CurrencyConversionService::class);
		$currency->method('getBaseCurrency')->willReturn('CHF');
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);

		$this->service = new BillService(
			$bills, new FrequencyCalculator(), $this->createMock(RecurringBillDetector::class),
			$transactions, $this->createMock(IL10N::class), $accounts, $currency,
			$this->createMock(TransactionSplitService::class), $this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class), null, $this->createMock(RecurringIncomeMapper::class),
			null, $clock,
		);
	}

	private function bill(array $fields): Bill {
		$bill = new Bill();
		$bill->setId($fields['id']);
		$bill->setUserId('t399');
		$bill->setName($fields['name']);
		$bill->setAmount($fields['amount']);
		$bill->setFrequency($fields['frequency'] ?? 'monthly');
		$bill->setDueDay($fields['dueDay'] ?? null);
		$bill->setDueMonth($fields['dueMonth'] ?? null);
		$bill->setStartDate(null);
		$bill->setEndDate(null);
		$bill->setNextDueDate($fields['nextDueDate'] ?? null);
		$bill->setLastPaidDate($fields['lastPaidDate'] ?? null);
		$bill->setIsActive(true);
		$bill->setIsTransfer($fields['isTransfer'] ?? false);
		$bill->setAccountId(1);
		$bill->setDestinationAccountId($fields['destinationAccountId'] ?? null);
		$bill->setRemainingPayments(null);
		$bill->setCustomRecurrencePattern(null);
		$bill->setPaidUndoState(null);
		$bill->setCreatedAt($fields['createdAt']);
		$this->stored[$fields['id']] = $bill;
		return $bill;
	}

	private function payment(int $id, int $billId, string $date, float $amount, int $accountId = 1, string $type = 'debit'): void {
		$tx = new Transaction();
		$tx->setId($id);
		$tx->setBillId($billId);
		$tx->setAccountId($accountId);
		$tx->setDate($date);
		$tx->setAmount($amount);
		$tx->setType($type);
		$tx->setStatus('cleared');
		$this->rows[] = $tx;
	}

	private function overview(int $year, bool $transfers = false): array {
		return $this->service->getAnnualOverview('t399', $year, $transfers, 'active');
	}

	/** The bill's only row in the year's view, or null when it has none */
	private function rowOf(array $overview): ?array {
		return $overview['bills'][0] ?? null;
	}

	private function markPaidWouldUse(int $billId): ?int {
		return $this->service->recordedPaymentForPendingOccurrence($billId, 't399')['id'] ?? null;
	}

	/** @return string[] every date the bill falls on in 2024 to 2027, in order */
	private function schedule(Bill $bill): array {
		$occurrences = new \ReflectionMethod(BillService::class, 'occurrencesInYear');
		$occurrences->setAccessible(true);
		$dates = [];
		foreach ([2024, 2025, 2026, 2027] as $year) {
			foreach ($occurrences->invoke($this->service, $bill, $year) as $inMonth) {
				foreach ($inMonth as $date) {
					$dates[] = $date;
				}
			}
		}
		return $dates;
	}

	private function shift(string $date, int $days): string {
		return (new \DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
	}

	/**
	 * What the bill's 2025, 2026 and 2027 totals should add up to: every
	 * payment in those years, and 100 for each date from the one it is due
	 * on, but the one Mark Paid's payment pays
	 *
	 * @param string[] $dates the bill's schedule
	 */
	private function expectedSum(Bill $bill, array $dates, ?int $markPaid): float {
		$paid = array_sum(array_map(fn (Transaction $tx) => $tx->getDate() >= '2025-01-01' && $tx->getDate() <= '2027-12-31' ? $tx->getAmount() : 0.0, $this->rows));
		$nextDue = (string)$bill->getNextDueDate();
		$owed = count(array_filter($dates, fn (string $d): bool => $d >= $nextDue && $d >= '2025-01-01' && $d <= '2027-12-31'));
		if ($markPaid !== null && $nextDue >= '2025-01-01' && $nextDue <= '2027-12-31') {
			$owed--;
		}
		return $paid + 100.0 * $owed;
	}

	/** @return array<int, float> year => what its Monthly Totals add up to */
	private function totalsByYear(): array {
		$totals = [];
		foreach ([2025, 2026, 2027] as $year) {
			$totals[$year] = array_sum($this->overview($year)['monthlyTotals']);
		}
		return $totals;
	}

	/**
	 * A bill paid on every date it falls on in 2025 up to the one it is due
	 * on (from June 2024's creation), with $late rows after that
	 *
	 * @param string[] $late
	 * @return string[] its schedule
	 */
	private function scenario(string $frequency, string $nextDue, ?string $lastPaid, array $late): array {
		$bill = $this->bill(['id' => 1, 'name' => 'F', 'amount' => 100.0, 'frequency' => $frequency, 'dueDay' => (int)substr($nextDue, 8, 2),
			'nextDueDate' => $nextDue, 'lastPaidDate' => $lastPaid, 'createdAt' => '2024-06-01 00:00:00']);
		$dates = $this->schedule($bill);
		$this->assertContains($nextDue, $dates, 'the fixture is due on one of its dates');
		$id = 1;
		foreach ($dates as $date) {
			if ($date >= '2025-01-01' && $date < $nextDue) {
				$this->payment($id++, 1, $date, 100.0);
			}
		}
		foreach ($late as $k => $date) {
			$this->payment(500 + $k, 1, $date, 100.0);
		}
		return $dates;
	}

	/** Every payment and every date still owed counted once in the three years */
	private function assertCountedOnce(array $dates, ?int $markPaid): array {
		$totals = $this->totalsByYear();
		$this->assertEqualsWithDelta($this->expectedSum($this->stored[1], $dates, $markPaid), array_sum($totals), 0.001, json_encode($totals));
		return $totals;
	}

	/**
	 * Case 139: monthly, due 21 December 2025, paid on 22 December (Mark
	 * Paid's) and again on 3 January. 2026 found no date for 3 January: the
	 * date it paid is last year's, and its payment out of sight. It is that
	 * date's second payment, counted in January 2026.
	 */
	public function testCase139ASecondPaymentInTheNewYearOfADecemberDate(): void {
		$dates = $this->scenario('monthly', '2025-12-21', '2025-11-22', ['2025-12-22', '2026-01-03']);

		$this->assertSame(500, $this->markPaidWouldUse(1));
		$row = $this->rowOf($this->overview(2026));
		$this->assertSame([['date' => '2026-01-03', 'amount' => 100.0, 'due' => '2025-12-21']], $row['paidDetails'][1]);
		$totals = $this->assertCountedOnce($dates, 500);
		$this->assertEqualsWithDelta(1300.0, $totals[2026], 0.001, '3 January, then every date of 2026 owed');
	}

	/** Case 110: quarterly, due 28 December 2026, paid on 21 December and again on 2 February */
	public function testCase110AQuarterlyBillPaidAgainInFebruary(): void {
		$dates = $this->scenario('quarterly', '2026-12-28', '2026-09-30', ['2026-12-21', '2027-02-02']);

		$this->assertSame(500, $this->markPaidWouldUse(1));
		$row = $this->rowOf($this->overview(2027));
		$this->assertSame([['date' => '2027-02-02', 'amount' => 100.0, 'due' => '2026-12-28']], $row['paidDetails'][2]);
		$this->assertCountedOnce($dates, 500);
	}

	/** Case 187: monthly, never marked paid, due 20 November 2026, paid on 29 December and 1 January */
	public function testCase187ABillNeverMarkedPaidPaidAcrossTheYear(): void {
		$dates = $this->scenario('monthly', '2026-11-20', null, ['2026-12-29', '2027-01-01']);

		$this->assertSame(500, $this->markPaidWouldUse(1));
		$row = $this->rowOf($this->overview(2027));
		$this->assertSame([['date' => '2027-01-01', 'amount' => 100.0, 'due' => '2026-11-20']], $row['paidDetails'][1]);
		$this->assertCountedOnce($dates, 500);
	}

	/** Case 128: bi-weekly, due 24 December 2025, paid on 11 January (Mark Paid's) and 14 January */
	public function testCase128ABiweeklyBillPaidTwiceInJanuary(): void {
		$dates = $this->scenario('biweekly', '2025-12-24', '2025-12-10', ['2026-01-14', '2026-01-11']);

		$this->assertSame(501, $this->markPaidWouldUse(1));
		$this->assertCountedOnce($dates, 501);
	}

	/** Case 352: daily, due 12 December 2025, paid on 7 January (Mark Paid's) and 25 January */
	public function testCase352ADailyBillPaidLateTwice(): void {
		$dates = $this->scenario('daily', '2025-12-12', '2025-12-11', ['2026-01-07', '2026-01-25']);

		$this->assertSame(500, $this->markPaidWouldUse(1));
		$this->assertCountedOnce($dates, 500);
	}

	/** @return array<string, array{int}> */
	public static function lateRows(): array {
		return ['one late row' => [1], 'up to two late rows' => [2]];
	}

	/**
	 * A seeded random bill: one of six frequencies, due between November
	 * 2025 and January 2027, paid on each earlier date of 2025 and 2026
	 * give or take two days (sometimes not at all, sometimes twice), its
	 * last payment forgotten one time in five, and up to two rows from the
	 * due window before its date to 45 days after it. Null when the date
	 * drawn is off its schedule.
	 *
	 * @return string[]|null its schedule
	 */
	private function randomBill(int $seed, int $maxLate): ?array {
		mt_srand($seed);
		$this->stored = [];
		$this->rows = [];
		$frequencies = ['monthly', 'weekly', 'biweekly', 'quarterly', 'yearly', 'monthly', 'weekly', 'daily'];
		$frequency = $frequencies[mt_rand(0, count($frequencies) - 1)];
		$nextDue = $this->shift('2025-11-15', mt_rand(0, 440));
		if ((int)substr($nextDue, 8, 2) > 28) {
			$nextDue = substr($nextDue, 0, 8) . '28';
		}
		$bill = $this->bill(['id' => 1, 'name' => 'F', 'amount' => 100.0, 'frequency' => $frequency, 'dueDay' => (int)substr($nextDue, 8, 2),
			'nextDueDate' => $nextDue, 'lastPaidDate' => null, 'createdAt' => '2024-06-01 00:00:00']);
		$dates = $this->schedule($bill);
		if (!in_array($nextDue, $dates, true)) {
			return null;
		}
		$window = ['weekly' => 3, 'biweekly' => 6, 'daily' => 1][$frequency] ?? 15;
		$id = 1;
		$lastPaid = null;
		foreach ($dates as $date) {
			if ($date < '2025-01-01' || $date >= $nextDue) {
				continue;
			}
			$draw = mt_rand(0, 11);
			if ($draw === 0) {
				continue;
			}
			$paidOn = max('2025-01-01', $this->shift($date, $frequency === 'daily' ? 0 : mt_rand(-2, 2)));
			if ($paidOn >= $nextDue) {
				$paidOn = $date;
			}
			$this->payment($id++, 1, $paidOn, 100.0);
			$lastPaid = max($lastPaid ?? '', $paidOn);
			if ($draw === 1 && $frequency !== 'daily') {
				$again = $this->shift($paidOn, mt_rand(1, 3));
				if ($again < $nextDue) {
					$this->payment($id++, 1, $again, 100.0);
					$lastPaid = max($lastPaid, $again);
				}
			}
		}
		if (mt_rand(0, 4) === 0) {
			$lastPaid = null;
		}
		$bill->setLastPaidDate($lastPaid);
		$late = mt_rand(0, $maxLate);
		for ($k = 0; $k < $late; $k++) {
			$paidOn = $this->shift($nextDue, mt_rand(-$window, 45));
			if ($paidOn < '2025-01-01' || $paidOn > '2027-12-31' || ($lastPaid !== null && $paidOn <= $lastPaid)) {
				continue;
			}
			$this->payment(500 + $k, 1, $paidOn, 100.0);
		}
		return $dates;
	}

	private function describe(int $seed): string {
		$bill = $this->stored[1];
		return sprintf('seed %d: %s due %s, last paid %s, rows %s', $seed, $bill->getFrequency(), $bill->getNextDueDate(),
			$bill->getLastPaidDate() ?? 'never', implode(' ', array_map(fn (Transaction $tx) => $tx->getDate(), array_filter($this->rows, fn (Transaction $tx) => $tx->getId() >= 500))));
	}

	/**
	 * Across 200 seeded bills per variant, the three years' totals add up
	 * to every payment plus every date owed but the one Mark Paid's payment
	 * pays: none counted twice, none lost.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('lateRows')]
	public function testRandomBillsCountEveryPaymentOnce(int $maxLate): void {
		$off = [];
		for ($case = 0; $case < 200; $case++) {
			$dates = $this->randomBill(7000 + $case, $maxLate);
			if ($dates === null) {
				continue;
			}
			$markPaid = $this->markPaidWouldUse(1);
			$expected = $this->expectedSum($this->stored[1], $dates, $markPaid);
			$totals = $this->totalsByYear();
			if (abs(array_sum($totals) - $expected) > 0.001) {
				$off[] = $this->describe(7000 + $case) . sprintf(': expected %.0f, got %s', $expected, json_encode($totals));
			}
		}
		$this->assertSame([], $off);
	}

	/**
	 * And the year of the date the bill is due on shows it paid exactly
	 * when Mark Paid has a payment for it.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('lateRows')]
	public function testRandomBillsShowTheDueDatePaidWhenMarkPaidWould(int $maxLate): void {
		$off = [];
		for ($case = 0; $case < 200; $case++) {
			$dates = $this->randomBill(17000 + $case, $maxLate);
			if ($dates === null) {
				continue;
			}
			$nextDue = (string)$this->stored[1]->getNextDueDate();
			$markPaid = $this->markPaidWouldUse(1);
			$month = (int)substr($nextDue, 5, 2);
			$row = $this->rowOf($this->overview((int)substr($nextDue, 0, 4)));
			$scheduled = count(array_filter($dates, fn (string $d): bool => substr($d, 0, 7) === substr($nextDue, 0, 7) && $d >= $nextDue));
			$owed = (float)($row['owedAmounts'][$month] ?? 0.0);
			if ((abs($owed - 100.0 * $scheduled) > 0.001) !== ($markPaid !== null)) {
				$off[] = $this->describe(17000 + $case) . sprintf(': Mark Paid %s, %.0f owed of %d dates', $markPaid ?? 'none', $owed, $scheduled);
			}
		}
		$this->assertSame([], $off);
	}
}
