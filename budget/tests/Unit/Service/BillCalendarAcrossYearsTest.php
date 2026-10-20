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
 * One bill seen in Bills by Month for three years in a row adds up to the
 * money it actually paid plus what it still owes: no payment counted in
 * two years, none in no year (#399).
 *
 * The payment Mark Paid would use for the date a bill is due on can be
 * dated in another year than the date: early (28 December for 5 January),
 * late (2 January for 26 December), or more than one date late (2 January
 * for 12 December, with 19 and 26 December still owed). Each case here is
 * one the review of the year-boundary fix probed, B1 to B11: every one
 * checks each year's totals and the sum of 2025, 2026 and 2027.
 */
class BillCalendarAcrossYearsTest extends TestCase {
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

	/**
	 * Each year's Monthly Totals summed, and the three of them together
	 * equal to what was paid in those years plus what is still owed.
	 *
	 * @param array<int, float> $byYear year => what its totals add up to
	 */
	private function assertYears(array $byYear, float $paid, float $owed, bool $transfers = false): void {
		$sum = 0.0;
		foreach ($byYear as $year => $expected) {
			$total = array_sum($this->overview($year, $transfers)['monthlyTotals']);
			$this->assertEqualsWithDelta($expected, $total, 0.001, "$year's totals");
			$sum += $total;
		}
		$this->assertEqualsWithDelta($paid + $owed, $sum, 0.001, 'paid plus owed, each once across the years');
	}

	/** Garage Miete: monthly on the 5th from June, last paid 4 December, next due 5 January 2027 */
	private function garage(): void {
		$this->bill(['id' => 8, 'name' => 'Garage Miete', 'amount' => 150.0, 'dueDay' => 5,
			'nextDueDate' => '2027-01-05', 'lastPaidDate' => '2026-12-04', 'createdAt' => '2026-06-01 10:00:00']);
		foreach (['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-04', '2026-10-05', '2026-11-05', '2026-12-04'] as $i => $date) {
			$this->payment(800 + $i, 8, $date, 150.0);
		}
	}

	/** B1: 28 December pays 5 January, 14 January pays it again; February to December 2027 owed */
	public function testB1PaidInDecemberAndAgainInJanuary(): void {
		$this->garage();
		$this->payment(899, 8, '2026-12-28', 150.0);
		$this->payment(900, 8, '2027-01-14', 150.0);

		$this->assertSame(899, $this->markPaidWouldUse(8));
		$this->assertSame([1 => 2], $this->rowOf($this->overview(2027))['paidMoreThanOnce']);
		$this->assertYears([2025 => 0.0, 2026 => 1200.0, 2027 => 1800.0], 9 * 150.0, 11 * 150.0);
	}

	/** B2: due 10 December, paid 26 days late on 5 January; all of 2027 owed */
	public function testB2MonthlyPaidLateInJanuary(): void {
		$this->bill(['id' => 10, 'name' => 'Strom', 'amount' => 100.0, 'dueDay' => 10,
			'nextDueDate' => '2026-12-10', 'lastPaidDate' => '2026-11-10', 'createdAt' => '2026-09-01 10:00:00']);
		foreach ([1001 => '2026-09-10', 1002 => '2026-10-10', 1003 => '2026-11-10', 1004 => '2027-01-05'] as $id => $date) {
			$this->payment($id, 10, $date, 100.0);
		}

		$this->assertSame(1004, $this->markPaidWouldUse(10));
		$this->assertYears([2025 => 0.0, 2026 => 300.0, 2027 => 1300.0], 400.0, 1200.0);
	}

	/** B3: weekly, due Friday 1 January, paid 30 December; 2027's other 52 Fridays owed */
	public function testB3WeeklyPaidInDecemberForNewYearsDay(): void {
		$this->bill(['id' => 11, 'name' => 'Putzfrau', 'amount' => 40.0, 'frequency' => 'weekly',
			'nextDueDate' => '2027-01-01', 'lastPaidDate' => '2026-12-25', 'createdAt' => '2026-12-01 10:00:00']);
		foreach (['2026-12-04', '2026-12-11', '2026-12-18', '2026-12-25', '2026-12-30'] as $i => $date) {
			$this->payment(1100 + $i, 11, $date, 40.0);
		}

		$this->assertSame(1104, $this->markPaidWouldUse(11));
		$this->assertYears([2025 => 0.0, 2026 => 200.0, 2027 => 2080.0], 200.0, 52 * 40.0);
	}

	/** B4: weekly, due Saturday 26 December, paid a week late on 2 January; 2027's 52 Saturdays owed */
	public function testB4WeeklyPaidAWeekLate(): void {
		$this->bill(['id' => 12, 'name' => 'Gärtner', 'amount' => 40.0, 'frequency' => 'weekly',
			'nextDueDate' => '2026-12-26', 'lastPaidDate' => '2026-12-19', 'createdAt' => '2026-12-01 10:00:00']);
		foreach (['2026-12-05', '2026-12-12', '2026-12-19', '2027-01-02'] as $i => $date) {
			$this->payment(1200 + $i, 12, $date, 40.0);
		}

		$this->assertSame(1203, $this->markPaidWouldUse(12));
		$this->assertYears([2025 => 0.0, 2026 => 120.0, 2027 => 2120.0], 160.0, 52 * 40.0);
	}

	/**
	 * B5: weekly, due Saturday 12 December and paid three weeks late, on
	 * 2 January. 19 and 26 December are still owed in 2026, and 2027 shows
	 * the payment in January as the one for 12 December. It was in neither
	 * year.
	 */
	public function testB5WeeklyPaidThreeWeeksLate(): void {
		$this->bill(['id' => 13, 'name' => 'Hund', 'amount' => 40.0, 'frequency' => 'weekly',
			'nextDueDate' => '2026-12-12', 'lastPaidDate' => '2026-12-05', 'createdAt' => '2026-11-01 10:00:00']);
		foreach (['2026-11-07', '2026-11-14', '2026-11-21', '2026-11-28', '2026-12-05', '2027-01-02'] as $i => $date) {
			$this->payment(1300 + $i, 13, $date, 40.0);
		}

		$this->assertSame(1305, $this->markPaidWouldUse(13));
		$row2026 = $this->rowOf($this->overview(2026));
		$this->assertSame([12 => [['date' => '2027-01-02', 'amount' => 40.0, 'due' => '2026-12-12']]], $row2026['paidElsewhere']);
		$this->assertEqualsWithDelta(80.0, $row2026['owedAmounts'][12], 0.001);
		$row2027 = $this->rowOf($this->overview(2027));
		$this->assertSame([['date' => '2027-01-02', 'amount' => 40.0, 'due' => '2026-12-12']], $row2027['paidDetails'][1]);
		$this->assertYears([2025 => 0.0, 2026 => 280.0, 2027 => 2120.0], 240.0, 54 * 40.0);
	}

	/**
	 * B6: monthly, due 10 November and paid two months late, on 5 January.
	 * 10 December is still owed; 2027 shows the payment as November's.
	 */
	public function testB6MonthlyPaidTwoMonthsLate(): void {
		$this->bill(['id' => 14, 'name' => 'Wasser', 'amount' => 100.0, 'dueDay' => 10,
			'nextDueDate' => '2026-11-10', 'lastPaidDate' => '2026-10-10', 'createdAt' => '2026-09-01 10:00:00']);
		foreach ([1401 => '2026-09-10', 1402 => '2026-10-10', 1403 => '2027-01-05'] as $id => $date) {
			$this->payment($id, 14, $date, 100.0);
		}

		$this->assertSame(1403, $this->markPaidWouldUse(14));
		$row2027 = $this->rowOf($this->overview(2027));
		$this->assertSame([['date' => '2027-01-05', 'amount' => 100.0, 'due' => '2026-11-10']], $row2027['paidDetails'][1]);
		$this->assertEqualsWithDelta(100.0, $row2027['owedAmounts'][1], 0.001, '10 January');
		$this->assertYears([2025 => 0.0, 2026 => 300.0, 2027 => 1300.0], 300.0, 13 * 100.0);
	}

	/** B7: as B3, with the next Friday paid early on 7 January: a second payment for 1 January, counted once */
	public function testB7WeeklyWithAnEarlyPaymentInJanuary(): void {
		$this->bill(['id' => 11, 'name' => 'Putzfrau', 'amount' => 40.0, 'frequency' => 'weekly',
			'nextDueDate' => '2027-01-01', 'lastPaidDate' => '2026-12-25', 'createdAt' => '2026-12-01 10:00:00']);
		foreach (['2026-12-04', '2026-12-11', '2026-12-18', '2026-12-25', '2026-12-30', '2027-01-07'] as $i => $date) {
			$this->payment(1100 + $i, 11, $date, 40.0);
		}

		$this->assertSame(1104, $this->markPaidWouldUse(11));
		$this->assertSame([1 => 2], $this->rowOf($this->overview(2027))['paidMoreThanOnce']);
		$this->assertYears([2025 => 0.0, 2026 => 200.0, 2027 => 2120.0], 240.0, 52 * 40.0);
	}

	/**
	 * B8: yearly, due 5 January 2027 and paid on 28 December; 120 paid in
	 * March. March's payment is a month of its own, not a second payment of
	 * 5 January: only one within the due window would be.
	 */
	public function testB8AYearlyBillsStrayPaymentKeepsItsMonth(): void {
		$this->bill(['id' => 15, 'name' => 'Versicherung', 'amount' => 500.0, 'frequency' => 'yearly', 'dueDay' => 5, 'dueMonth' => 1,
			'nextDueDate' => '2027-01-05', 'lastPaidDate' => '2026-01-04', 'createdAt' => '2025-06-01 10:00:00']);
		$this->payment(1501, 15, '2026-01-04', 500.0);
		$this->payment(1502, 15, '2026-12-28', 500.0);
		$this->payment(1503, 15, '2027-03-10', 120.0);

		$this->assertSame(1502, $this->markPaidWouldUse(15));
		$row2027 = $this->rowOf($this->overview(2027));
		$this->assertSame([3 => [['date' => '2027-03-10', 'amount' => 120.0, 'due' => null]]], $row2027['paidDetails']);
		$this->assertSame([], $row2027['paidMoreThanOnce']);
		$this->assertYears([2025 => 0.0, 2026 => 1000.0, 2027 => 120.0], 1120.0, 0.0);
	}

	/** Its payment within the due window of 5 January is that date's second payment, still counted once. */
	public function testB8AYearlyBillsPaymentNearItsDateIsADouble(): void {
		$this->bill(['id' => 15, 'name' => 'Versicherung', 'amount' => 500.0, 'frequency' => 'yearly', 'dueDay' => 5, 'dueMonth' => 1,
			'nextDueDate' => '2027-01-05', 'lastPaidDate' => '2026-01-04', 'createdAt' => '2025-06-01 10:00:00']);
		$this->payment(1501, 15, '2026-01-04', 500.0);
		$this->payment(1502, 15, '2026-12-28', 500.0);
		$this->payment(1503, 15, '2027-01-15', 500.0);

		$this->assertSame(1502, $this->markPaidWouldUse(15), '28 December is the nearer');
		$row2027 = $this->rowOf($this->overview(2027));
		$this->assertSame([1 => [['date' => '2027-01-15', 'amount' => 500.0, 'due' => '2027-01-05']]], $row2027['paidDetails']);
		$this->assertSame([1 => 2], $row2027['paidMoreThanOnce']);
		$this->assertYears([2025 => 0.0, 2026 => 1000.0, 2027 => 500.0], 1500.0, 0.0);
	}

	/** B10: due 5 January 2026, paid on 28 December 2025 and again on 20 January */
	public function testB10ThreeYears(): void {
		$this->bill(['id' => 16, 'name' => 'Miete', 'amount' => 1000.0, 'dueDay' => 5,
			'nextDueDate' => '2026-01-05', 'lastPaidDate' => '2025-12-04', 'createdAt' => '2025-10-01 10:00:00']);
		foreach ([1601 => '2025-10-05', 1602 => '2025-11-05', 1603 => '2025-12-04', 1604 => '2025-12-28', 1605 => '2026-01-20'] as $id => $date) {
			$this->payment($id, 16, $date, 1000.0);
		}

		$this->assertSame(1604, $this->markPaidWouldUse(16));
		$this->assertYears([2025 => 4000.0, 2026 => 12000.0, 2027 => 12000.0], 5000.0, 23 * 1000.0);
	}

	/** B11: a transfer whose withdrawal left on 28 December for 5 January and arrived on 2 January */
	public function testB11ATransferPaidAcrossTheYear(): void {
		$this->bill(['id' => 17, 'name' => 'Sparen', 'amount' => 200.0, 'dueDay' => 5, 'isTransfer' => true, 'destinationAccountId' => 2,
			'nextDueDate' => '2027-01-05', 'lastPaidDate' => '2026-12-04', 'createdAt' => '2026-10-01 10:00:00']);
		foreach ([1700 => '2026-10-05', 1701 => '2026-11-05', 1702 => '2026-12-04', 1798 => '2026-12-28'] as $id => $date) {
			$this->payment($id, 17, $date, 200.0);
		}
		$this->payment(1799, 17, '2027-01-02', 200.0, 2, 'credit');

		$this->assertSame(1798, $this->markPaidWouldUse(17));
		$this->assertYears([2025 => 0.0, 2026 => 800.0, 2027 => 2200.0], 800.0, 11 * 200.0, true);
	}

	/**
	 * B9: the rows read beyond the year start where Mark Paid starts
	 * looking, at the earliest of the bills': the due window before the
	 * date each is due on, or the day after it was last paid. What the view
	 * shows is the same as with every row.
	 */
	public function testB9TheRowsReadStartWhereMarkPaidLooks(): void {
		$this->bill(['id' => 14, 'name' => 'Wasser', 'amount' => 100.0, 'dueDay' => 10,
			'nextDueDate' => '2026-11-10', 'lastPaidDate' => '2026-10-10', 'createdAt' => '2016-01-01 10:00:00']);
		$this->bill(['id' => 13, 'name' => 'Hund', 'amount' => 40.0, 'frequency' => 'weekly',
			'nextDueDate' => '2026-12-12', 'lastPaidDate' => '2026-12-05', 'createdAt' => '2016-01-01 10:00:00']);
		$this->bill(['id' => 18, 'name' => 'Früh', 'amount' => 70.0, 'dueDay' => 20,
			'nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-11-12', 'createdAt' => '2016-01-01 10:00:00']);
		for ($year = 2016; $year <= 2026; $year++) {
			foreach ([1 => 10, 2 => 10, 3 => 10, 4 => 10, 5 => 10, 6 => 10, 7 => 10, 8 => 10, 9 => 10, 10 => 10] as $month => $day) {
				$this->payment($year * 100 + $month, 14, sprintf('%04d-%02d-%02d', $year, $month, $day), 100.0);
			}
		}
		$this->payment(9001, 13, '2026-12-05', 40.0);
		$this->payment(9002, 13, '2027-01-02', 40.0);
		$this->payment(9003, 14, '2026-11-08', 100.0);

		$bounded = $this->overview(2026);
		$this->assertSame([[[14, 13, 18], '2026-10-26']], $this->allRowQueries, 'Wasser from 26 October, Hund from 9 December, Früh from 13 November');

		$this->ignoreBound = true;
		$this->assertEquals($this->overview(2026), $bounded, 'every row shows the same');
	}

	/** The projected balance's own pass over the bills reads no row a second time. */
	public function testTheProjectionReusesTheRowsRead(): void {
		$account = new Account();
		$account->setId(1);
		$account->setName('Lohnkonto');
		$account->setCurrency('CHF');
		$this->accounts = [$account];
		$this->garage();
		$this->payment(899, 8, '2026-12-28', 150.0);

		$overview = $this->service->getAnnualOverview('t399', 2026, false, 'active', 1);

		$this->assertNotNull($overview['projectedBalance']);
		$this->assertCount(1, $this->allRowQueries);
	}
}
