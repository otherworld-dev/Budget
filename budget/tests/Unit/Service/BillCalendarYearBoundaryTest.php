<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

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
 * Bills by Month for a recurring bill due near the turn of the year (#399).
 *
 * The calendar chose the payment of the date a bill is due on from the
 * viewed year's rows only, while Mark Paid looks at all of them. A bill due
 * on 5 January 2027 and paid on 28 December showed that payment in 2026 and
 * the date still owed in 2027, so the premium counted in both years until
 * Mark Paid; and with a second row on 3 January, 2026 took 28 December
 * while Mark Paid takes 3 January. A bill whose due date is that close to
 * the year's start or end now has its payment chosen from every row, as
 * Mark Paid does, and a date paid in the other year is neither owed nor
 * counted again: its cell names the payment instead (paidElsewhere).
 */
class BillCalendarYearBoundaryTest extends TestCase {
	private const TODAY = '2026-10-20';

	private BillService $service;
	/** @var array<int, Bill> */
	private array $stored = [];
	/** @var Transaction[] */
	private array $rows = [];
	/** @var list<int[]> the bill ids of each findRecordedBillTransactions() call */
	private array $allRowQueries = [];

	protected function setUp(): void {
		$bills = $this->createMock(BillMapper::class);
		$bills->method('find')->willReturnCallback(fn (int $id) => $this->stored[$id]);
		$bills->method('findByType')->willReturnCallback(fn () => array_values($this->stored));

		// As the mapper reads them: no placeholders; the year's in date order.
		// Each query hydrates its own entities, so the calendar can't match
		// rows by identity.
		$recorded = fn (array $ids): array => array_map(fn (Transaction $tx) => clone $tx, array_values(array_filter(
			$this->rows,
			fn (Transaction $tx): bool => in_array($tx->getBillId(), $ids, true) && $tx->getStatus() !== 'scheduled'
		)));
		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('findRecordedBillTransactions')->willReturnCallback(function (array $ids) use ($recorded): array {
			$this->allRowQueries[] = $ids;
			return $recorded($ids);
		});
		$transactions->method('findBillPaymentsInYear')->willReturnCallback(function (array $ids, int $year) use ($recorded): array {
			$rows = array_values(array_filter($recorded($ids), fn (Transaction $tx): bool => (int)substr($tx->getDate(), 0, 4) === $year));
			usort($rows, fn (Transaction $a, Transaction $b): int => strcmp($a->getDate(), $b->getDate()));
			return $rows;
		});

		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([]);
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
		$bill->setDueMonth(null);
		$bill->setStartDate(null);
		$bill->setEndDate(null);
		$bill->setNextDueDate($fields['nextDueDate'] ?? null);
		$bill->setLastPaidDate($fields['lastPaidDate'] ?? null);
		$bill->setIsActive(true);
		$bill->setIsTransfer(false);
		$bill->setAccountId(1);
		$bill->setDestinationAccountId(null);
		$bill->setRemainingPayments(null);
		$bill->setCustomRecurrencePattern(null);
		$bill->setPaidUndoState(null);
		$bill->setCreatedAt($fields['createdAt']);
		$this->stored[$fields['id']] = $bill;
		return $bill;
	}

	private function payment(int $id, int $billId, string $date, float $amount): void {
		$tx = new Transaction();
		$tx->setId($id);
		$tx->setBillId($billId);
		$tx->setAccountId(1);
		$tx->setDate($date);
		$tx->setAmount($amount);
		$tx->setType('debit');
		$tx->setStatus('cleared');
		$this->rows[] = $tx;
	}

	private function overview(int $year): array {
		return $this->service->getAnnualOverview('t399', $year, false, 'active');
	}

	private function calendarRow(array $overview, string $name): array {
		foreach ($overview['bills'] as $row) {
			if ($row['name'] === $name) {
				return $row;
			}
		}
		$this->fail("no row for $name");
	}

	private function rounded(array $amounts): array {
		return array_map(fn ($amount) => round($amount, 2), $amounts);
	}

	private function markPaidWouldUse(int $billId): ?int {
		return $this->service->recordedPaymentForPendingOccurrence($billId, 't399')['id'] ?? null;
	}

	/** Garage Miete: due on the 5th, paid monthly from June, last paid 4 December, next due 5 January 2027 */
	private function garage(): void {
		$this->bill(['id' => 8, 'name' => 'Garage Miete', 'amount' => 150.0, 'dueDay' => 5,
			'nextDueDate' => '2027-01-05', 'lastPaidDate' => '2026-12-04', 'createdAt' => '2026-06-01 10:00:00']);
		foreach (['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-04', '2026-10-05', '2026-11-05', '2026-12-04'] as $i => $date) {
			$this->payment(800 + $i, 8, $date, 150.0);
		}
	}

	/**
	 * Paid on 28 December for 5 January. 2026 shows the payment in December
	 * as January's; 2027 shows 5 January paid in 2026, not owed and not in
	 * its totals. The premium counts once across the two years.
	 */
	public function testADecemberPaymentForAJanuaryDateCountsOnceAcrossBothYears(): void {
		$this->garage();
		$this->payment(899, 8, '2026-12-28', 150.0);

		$this->assertSame(899, $this->markPaidWouldUse(8));

		$this->allRowQueries = [];
		$view2026 = $this->overview(2026);
		$row2026 = $this->calendarRow($view2026, 'Garage Miete');
		$this->assertSame([[8]], $this->allRowQueries, 'one query for the bills near the turn of the year');
		$this->assertSame(['2026-12-05', '2027-01-05'], array_column($row2026['paidDetails'][12], 'due'));
		$this->assertSame([], $row2026['owedAmounts']);
		$this->assertSame([], $row2026['paidElsewhere']);
		$this->assertEqualsWithDelta(300.0, $view2026['monthlyTotals'][12], 0.001);

		$view2027 = $this->overview(2027);
		$row2027 = $this->calendarRow($view2027, 'Garage Miete');
		$this->assertSame([1 => [['date' => '2026-12-28', 'amount' => 150.0, 'due' => '2027-01-05']]], $row2027['paidElsewhere']);
		$this->assertTrue($row2027['occurrences'][1]);
		$this->assertArrayNotHasKey(1, $row2027['paidDetails']);
		$this->assertArrayNotHasKey(1, $row2027['owedAmounts'], '5 January was paid in 2026');
		$this->assertSame([], $row2027['unrecordedMonths']);
		$this->assertEqualsWithDelta(150.0, $row2027['owedAmounts'][2], 0.001);
		$this->assertEqualsWithDelta(0.0, $view2027['monthlyTotals'][1], 0.001, 'counted in 2026 already');
		$this->assertEqualsWithDelta(150.0, $view2027['monthlyTotals'][2], 0.001);

		// 5 December and 5 January, once each
		$this->assertEqualsWithDelta(300.0, $view2026['monthlyTotals'][12] + $view2027['monthlyTotals'][1], 0.001);
	}

	/**
	 * Rows on 28 December and 3 January: Mark Paid takes 3 January, the
	 * nearer, and so do both years. In 2026, 28 December stays a second
	 * payment for 5 December, the nearest date the bill has moved past.
	 */
	public function testBothYearsTakeThePaymentMarkPaidWould(): void {
		$this->garage();
		$this->payment(899, 8, '2026-12-28', 150.0);
		$this->payment(900, 8, '2027-01-03', 150.0);

		$this->assertSame(900, $this->markPaidWouldUse(8));

		$view2026 = $this->overview(2026);
		$row2026 = $this->calendarRow($view2026, 'Garage Miete');
		$this->assertSame(['2026-12-04', '2026-12-28'], array_column($row2026['paidDetails'][12], 'date'));
		$this->assertSame(['2026-12-05', '2026-12-05'], array_column($row2026['paidDetails'][12], 'due'), 'not 5 January');
		$this->assertSame([12 => 2], $row2026['paidMoreThanOnce']);
		$this->assertSame([], $row2026['paidElsewhere'], '5 January is no date of 2026');
		$this->assertEqualsWithDelta(300.0, $view2026['monthlyTotals'][12], 0.001);

		$view2027 = $this->overview(2027);
		$row2027 = $this->calendarRow($view2027, 'Garage Miete');
		$this->assertSame([['date' => '2027-01-03', 'amount' => 150.0, 'due' => '2027-01-05']], $row2027['paidDetails'][1]);
		$this->assertArrayNotHasKey(1, $row2027['owedAmounts']);
		$this->assertSame([], $row2027['paidElsewhere']);
		$this->assertEqualsWithDelta(150.0, $view2027['monthlyTotals'][1], 0.001);
	}

	/**
	 * Due on 30 December and paid on 2 January: 2026 shows 30 December paid
	 * in 2027, not owed and not in its totals, and 2027 shows the payment in
	 * January as the one for 30 December, beside January's own date.
	 */
	public function testALateJanuaryPaymentForADecemberDate(): void {
		$this->bill(['id' => 9, 'name' => 'Parkplatz', 'amount' => 80.0, 'dueDay' => 30,
			'nextDueDate' => '2026-12-30', 'lastPaidDate' => '2026-11-29', 'createdAt' => '2026-09-01 10:00:00']);
		$this->payment(901, 9, '2026-09-30', 80.0);
		$this->payment(902, 9, '2026-10-30', 80.0);
		$this->payment(903, 9, '2026-11-29', 80.0);
		$this->payment(904, 9, '2027-01-02', 80.0);

		$this->assertSame(904, $this->markPaidWouldUse(9));

		$view2026 = $this->overview(2026);
		$row2026 = $this->calendarRow($view2026, 'Parkplatz');
		$this->assertSame([12 => [['date' => '2027-01-02', 'amount' => 80.0, 'due' => '2026-12-30']]], $row2026['paidElsewhere']);
		$this->assertSame([], $row2026['owedAmounts']);
		$this->assertArrayNotHasKey(12, $row2026['paidDetails']);
		$this->assertEqualsWithDelta(0.0, $view2026['monthlyTotals'][12], 0.001);
		$this->assertEqualsWithDelta(80.0, $view2026['monthlyTotals'][11], 0.001);

		$view2027 = $this->overview(2027);
		$row2027 = $this->calendarRow($view2027, 'Parkplatz');
		$this->assertSame([['date' => '2027-01-02', 'amount' => 80.0, 'due' => '2026-12-30']], $row2027['paidDetails'][1]);
		$this->assertSame([], $row2027['paidElsewhere']);
		$this->assertEqualsWithDelta(80.0, $row2027['owedAmounts'][1], 0.001, '30 January is still owed');
		$this->assertEqualsWithDelta(160.0, $view2027['monthlyTotals'][1], 0.001);
	}

	/**
	 * A December row the 2026 view used to take for 30 December stays a
	 * second payment of the nearest date the bill has moved past, as for any
	 * payment Mark Paid wouldn't use: Mark Paid takes 2 January.
	 */
	public function testAnInYearRowMarkPaidWouldNotUseIsASecondPayment(): void {
		$this->bill(['id' => 9, 'name' => 'Parkplatz', 'amount' => 80.0, 'dueDay' => 30,
			'nextDueDate' => '2026-12-30', 'lastPaidDate' => '2026-11-29', 'createdAt' => '2026-09-01 10:00:00']);
		$this->payment(901, 9, '2026-09-30', 80.0);
		$this->payment(902, 9, '2026-10-30', 80.0);
		$this->payment(903, 9, '2026-11-29', 80.0);
		$this->payment(905, 9, '2026-12-20', 80.0);
		$this->payment(904, 9, '2027-01-02', 80.0);

		$this->assertSame(904, $this->markPaidWouldUse(9));

		$view2026 = $this->overview(2026);
		$row2026 = $this->calendarRow($view2026, 'Parkplatz');
		$this->assertSame(['2026-11-30', '2026-11-30'], array_column($row2026['paidDetails'][11], 'due'));
		$this->assertSame([12 => [['date' => '2027-01-02', 'amount' => 80.0, 'due' => '2026-12-30']]], $row2026['paidElsewhere']);
		$this->assertSame([], $row2026['owedAmounts']);
		$this->assertEqualsWithDelta(160.0, $view2026['monthlyTotals'][11], 0.001);
		$this->assertEqualsWithDelta(0.0, $view2026['monthlyTotals'][12], 0.001);
	}

	/** An ordinary bill due mid-year is drawn as before, with no query beyond the year's rows. */
	public function testAMidYearBillNeedsNoExtraQuery(): void {
		$this->bill(['id' => 4, 'name' => 'KPT Prämienrechnung', 'amount' => 484.75, 'dueDay' => 29,
			'nextDueDate' => '2026-10-29', 'lastPaidDate' => '2026-09-27', 'createdAt' => '2026-02-01 17:26:05']);
		foreach ([394 => '2026-03-28', 558 => '2026-04-28', 776 => '2026-05-31', 902 => '2026-06-28', 903 => '2026-07-25',
			1007 => '2026-08-29', 1100 => '2026-08-30', 1101 => '2026-09-27', 1600 => '2026-10-15'] as $id => $date) {
			$this->payment($id, 4, $date, 484.75);
		}

		$overview = $this->overview(2026);
		$kpt = $this->calendarRow($overview, 'KPT Prämienrechnung');

		$this->assertSame([], $this->allRowQueries);
		$this->assertSame([['date' => '2026-10-15', 'amount' => 484.75, 'due' => '2026-10-29']], $kpt['paidDetails'][10]);
		$this->assertSame([11 => 484.75, 12 => 484.75], $this->rounded($kpt['owedAmounts']));
		$this->assertSame([], $kpt['paidElsewhere']);
		$this->assertSame(1600, $this->markPaidWouldUse(4));
	}
}
