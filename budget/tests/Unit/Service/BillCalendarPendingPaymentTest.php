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
 * Bills by Month for a payment linked to a recurring bill for the date it
 * is due on, before Mark Paid (#399). The reporter typed in October's KPT
 * premium, dated 15 October, and linked it to the bill. The bill was still
 * due on 29 October, a date the calendar never put a payment on, so the
 * payment became a second one for 29 September ("2× 969.50") while October
 * stayed owed: Monthly Totals counted the 484.75 twice until Mark Paid >
 * Use that payment. The calendar now takes the payment Mark Paid would use
 * for that date as the date's payment, and a date a payment paid is no
 * longer owed as well.
 *
 * Mark Paid's lookup and the calendar read the same rows here, so each test
 * also checks the two pick the same payment.
 */
class BillCalendarPendingPaymentTest extends TestCase {
	private const TODAY = '2026-10-20';

	private BillService $service;
	/** @var array<int, Bill> */
	private array $stored = [];
	/** @var Transaction[] every row of the bills, both legs of a transfer, placeholders included */
	private array $rows = [];

	protected function setUp(): void {
		$bills = $this->createMock(BillMapper::class);
		$bills->method('find')->willReturnCallback(fn (int $id) => $this->stored[$id]);
		$bills->method('findByType')->willReturnCallback(fn () => array_values($this->stored));

		// As the mapper reads them: no placeholders; the year's in date order
		$recorded = fn (array $ids): array => array_values(array_filter(
			$this->rows,
			fn (Transaction $tx): bool => in_array($tx->getBillId(), $ids, true) && $tx->getStatus() !== 'scheduled'
		));
		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('findRecordedBillTransactions')->willReturnCallback($recorded);
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
		$bill->setDueMonth($fields['dueMonth'] ?? null);
		$bill->setStartDate($fields['startDate'] ?? null);
		$bill->setEndDate(null);
		$bill->setNextDueDate($fields['nextDueDate'] ?? null);
		$bill->setLastPaidDate($fields['lastPaidDate'] ?? null);
		$bill->setIsActive($fields['isActive'] ?? true);
		$bill->setIsTransfer($fields['isTransfer'] ?? false);
		$bill->setAccountId($fields['accountId'] ?? 1);
		$bill->setDestinationAccountId($fields['destinationAccountId'] ?? null);
		$bill->setRemainingPayments(null);
		$bill->setCustomRecurrencePattern(null);
		$bill->setPaidUndoState($fields['paidUndoState'] ?? null);
		$bill->setCreatedAt($fields['createdAt']);
		$this->stored[$fields['id']] = $bill;
		return $bill;
	}

	/** A cleared row of the bill on Lohnkonto (account 1) unless $fields say otherwise */
	private function payment(int $id, int $billId, string $date, float $amount, array $fields = []): Transaction {
		$tx = new Transaction();
		$tx->setId($id);
		$tx->setBillId($billId);
		$tx->setAccountId($fields['accountId'] ?? 1);
		$tx->setDate($date);
		$tx->setAmount($amount);
		$tx->setType($fields['type'] ?? 'debit');
		$tx->setStatus($fields['status'] ?? 'cleared');
		$this->rows[] = $tx;
		return $tx;
	}

	private function overview(bool $includeTransfers = false): array {
		return $this->service->getAnnualOverview('t399', 2026, $includeTransfers, 'active');
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

	/** KPT Prämienrechnung (the reporter's bill 4): due on the 29th, last paid 27 September, next due 29 October. */
	private function kpt(array $fields = []): Bill {
		return $this->bill($fields + ['id' => 4, 'name' => 'KPT Prämienrechnung', 'amount' => 484.75, 'dueDay' => 29,
			'nextDueDate' => '2026-10-29', 'lastPaidDate' => '2026-09-27', 'createdAt' => '2026-02-01 17:26:05']);
	}

	/** Its payments up to September, paid twice at the end of August; September's row dated $september */
	private function kptPayments(string $september = '2026-09-27'): void {
		foreach ([394 => '2026-03-28', 558 => '2026-04-28', 776 => '2026-05-31', 902 => '2026-06-28', 903 => '2026-07-25',
			1007 => '2026-08-29', 1100 => '2026-08-30', 1101 => $september] as $id => $date) {
			$this->payment($id, 4, $date, 484.75);
		}
	}

	/** The one Mark Paid's lookup reports for the date the bill is due on */
	private function markPaidWouldUse(int $billId): ?int {
		return $this->service->recordedPaymentForPendingOccurrence($billId, 't399')['id'] ?? null;
	}

	/**
	 * The reporter's case: October's premium typed in on 15 October and
	 * linked to the bill. It pays 29 October, the date the bill is due on:
	 * September keeps its own payment, October is paid and no longer owed,
	 * and Monthly Totals count the 484.75 once.
	 */
	public function testAPaymentLinkedForTheDateTheBillIsDueOnPaysThatDate(): void {
		$this->kpt();
		$this->kptPayments();
		$this->payment(1600, 4, '2026-10-15', 484.75);

		$overview = $this->overview();
		$kpt = $this->calendarRow($overview, 'KPT Prämienrechnung');

		$this->assertSame(1600, $this->markPaidWouldUse(4));
		$this->assertSame([['date' => '2026-09-27', 'amount' => 484.75, 'due' => '2026-09-29']], $kpt['paidDetails'][9]);
		$this->assertSame([['date' => '2026-10-15', 'amount' => 484.75, 'due' => '2026-10-29']], $kpt['paidDetails'][10]);
		$this->assertSame(
			[3 => 484.75, 4 => 484.75, 5 => 484.75, 6 => 484.75, 7 => 484.75, 8 => 969.5, 9 => 484.75, 10 => 484.75],
			$this->rounded($kpt['paidAmounts'])
		);
		$this->assertSame([8 => 2], $kpt['paidMoreThanOnce'], 'September has no marker; the August double keeps its own');
		$this->assertSame([11 => 484.75, 12 => 484.75], $this->rounded($kpt['owedAmounts']), 'October is paid, not owed as well');
		$this->assertEqualsWithDelta(484.75, $overview['monthlyTotals'][9], 0.001);
		$this->assertEqualsWithDelta(484.75, $overview['monthlyTotals'][10], 0.001, 'counted once');
		$this->assertEqualsWithDelta(484.75, $overview['monthlyTotals'][11], 0.001);
	}

	/**
	 * Mark Paid doesn't take a payment from before the date's due window
	 * (15 days for a monthly bill) as its payment, and nor does the
	 * calendar: it stays where it was, a second payment for September, and
	 * October is still owed.
	 */
	public function testAPaymentFromBeforeTheDueWindowIsNotTaken(): void {
		$this->kpt();
		$this->kptPayments();
		$this->payment(1600, 4, '2026-10-05', 484.75);

		$overview = $this->overview();
		$kpt = $this->calendarRow($overview, 'KPT Prämienrechnung');

		$this->assertNull($this->markPaidWouldUse(4));
		$this->assertSame(['2026-09-27', '2026-10-05'], array_column($kpt['paidDetails'][9], 'date'));
		$this->assertSame(['2026-09-29', '2026-09-29'], array_column($kpt['paidDetails'][9], 'due'));
		$this->assertArrayNotHasKey(10, $kpt['paidDetails']);
		$this->assertSame([10 => 484.75, 11 => 484.75, 12 => 484.75], $this->rounded($kpt['owedAmounts']));
		$this->assertEqualsWithDelta(484.75, $overview['monthlyTotals'][10], 0.001);
	}

	/**
	 * September's payment, booked by Mark Paid and moved to the bank's date
	 * afterwards, lands in October's due window. It is still the last
	 * payment's row, which Mark Paid never offers for the next date, so it
	 * stays September's and October is still owed.
	 */
	public function testARowOfTheLastPaymentIsNotTaken(): void {
		$this->kpt(['paidUndoState' => json_encode([
			'previousState' => ['nextDueDate' => '2026-09-29', 'lastPaidDate' => '2026-08-30'],
			'createdTransactionIds' => [1101], 'scheduledTransactionIds' => [], 'linkedTransactionId' => null,
			'paidDate' => '2026-09-27',
		])]);
		$this->kptPayments('2026-10-16');

		$kpt = $this->calendarRow($this->overview(), 'KPT Prämienrechnung');

		$this->assertNull($this->markPaidWouldUse(4));
		$this->assertSame([['date' => '2026-10-16', 'amount' => 484.75, 'due' => '2026-09-29']], $kpt['paidDetails'][9]);
		$this->assertArrayNotHasKey(10, $kpt['paidDetails']);
		$this->assertSame([10 => 484.75, 11 => 484.75, 12 => 484.75], $this->rounded($kpt['owedAmounts']));
	}

	/**
	 * A weekly bill's payment made two days before the Friday it is due on
	 * pays that Friday: it was a second payment for the Friday before, and
	 * the month still owed both of its last Fridays.
	 */
	public function testAWeeklyBillsPaymentForTheWeekItIsDuePaysThatWeek(): void {
		$this->bill(['id' => 7, 'name' => 'Putzfrau', 'amount' => 40.0, 'frequency' => 'weekly', 'dueDay' => 5,
			'startDate' => '2026-09-04', 'nextDueDate' => '2026-10-23', 'lastPaidDate' => '2026-10-16',
			'createdAt' => '2026-09-01 10:00:00']);
		foreach (['2026-09-04', '2026-09-11', '2026-09-18', '2026-09-25', '2026-10-02', '2026-10-09', '2026-10-16'] as $i => $date) {
			$this->payment(700 + $i, 7, $date, 40.0);
		}
		$this->payment(790, 7, '2026-10-21', 40.0);

		$overview = $this->overview();
		$row = $this->calendarRow($overview, 'Putzfrau');

		$this->assertSame(790, $this->markPaidWouldUse(7));
		$this->assertSame(['2026-10-02', '2026-10-09', '2026-10-16', '2026-10-21'], array_column($row['paidDetails'][10], 'date'));
		$this->assertSame(['2026-10-02', '2026-10-09', '2026-10-16', '2026-10-23'], array_column($row['paidDetails'][10], 'due'));
		$this->assertSame([], $row['paidMoreThanOnce']);
		$this->assertEqualsWithDelta(40.0, $row['owedAmounts'][10], 0.001, 'only 30 October is still owed');
		$this->assertEqualsWithDelta(200.0, $overview['monthlyTotals'][10], 0.001, 'four paid, one to come');
	}

	/**
	 * Paid on 28 December for 5 January, the date the bill is due on next
	 * year: it shows in December, where the money went out, as January's
	 * payment, not as a second one for 5 December. Nothing this year is owed.
	 */
	public function testADecemberPaymentForAJanuaryDateShowsInDecember(): void {
		$this->bill(['id' => 8, 'name' => 'Garage Miete', 'amount' => 150.0, 'dueDay' => 5,
			'nextDueDate' => '2027-01-05', 'lastPaidDate' => '2026-12-04', 'createdAt' => '2026-06-01 10:00:00']);
		foreach (['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-04', '2026-10-05', '2026-11-05', '2026-12-04'] as $i => $date) {
			$this->payment(800 + $i, 8, $date, 150.0);
		}
		$this->payment(899, 8, '2026-12-28', 150.0);

		$overview = $this->overview();
		$row = $this->calendarRow($overview, 'Garage Miete');

		$this->assertSame(899, $this->markPaidWouldUse(8));
		$this->assertSame([
			['date' => '2026-12-04', 'amount' => 150.0, 'due' => '2026-12-05'],
			['date' => '2026-12-28', 'amount' => 150.0, 'due' => '2027-01-05'],
		], $row['paidDetails'][12]);
		$this->assertSame([], $row['paidMoreThanOnce']);
		$this->assertSame([], $row['owedAmounts']);
		$this->assertSame([], $row['unrecordedMonths']);
		$this->assertEqualsWithDelta(300.0, $overview['monthlyTotals'][12], 0.001, 'both payments went out in December');
	}

	/**
	 * Two payments for October: Mark Paid takes the one nearer the date, and
	 * so does the calendar. The other, as near 29 September as 29 October,
	 * stays a second payment for September.
	 */
	public function testMarkPaidAndTheCalendarTakeTheSameOfTwoPayments(): void {
		$this->kpt();
		$this->kptPayments();
		$this->payment(1601, 4, '2026-10-14', 484.75);
		$this->payment(1602, 4, '2026-10-20', 484.75);

		$kpt = $this->calendarRow($this->overview(), 'KPT Prämienrechnung');

		$this->assertSame(1602, $this->markPaidWouldUse(4));
		$this->assertSame([['date' => '2026-10-20', 'amount' => 484.75, 'due' => '2026-10-29']], $kpt['paidDetails'][10]);
		$this->assertSame(['2026-09-27', '2026-10-14'], array_column($kpt['paidDetails'][9], 'date'));
		$this->assertArrayNotHasKey(10, $kpt['owedAmounts']);
	}

	/**
	 * Both legs of a transfer carry the bill, and Mark Paid takes the one
	 * in the bill's own account: the withdrawal, though its deposit has the
	 * lower id. So does the calendar, where only the withdrawal is a
	 * payment: picked by date and id alone, the deposit left October owed
	 * and the withdrawal on no date at all.
	 */
	public function testMarkPaidAndTheCalendarTakeATransfersWithdrawal(): void {
		$this->bill(['id' => 20, 'name' => 'Sparkonto', 'amount' => 200.0, 'dueDay' => 25, 'isTransfer' => true,
			'destinationAccountId' => 13, 'nextDueDate' => '2026-10-25', 'createdAt' => '2026-10-01 10:00:00']);
		$this->payment(5001, 20, '2026-10-22', 200.0, ['accountId' => 13, 'type' => 'credit']);
		$this->payment(5002, 20, '2026-10-22', 200.0);

		$overview = $this->overview(true);
		$row = $this->calendarRow($overview, 'Sparkonto');

		$this->assertSame(5002, $this->markPaidWouldUse(20));
		$this->assertSame([['date' => '2026-10-22', 'amount' => 200.0, 'due' => '2026-10-25']], $row['paidDetails'][10]);
		$this->assertSame([11 => 200.0, 12 => 200.0], $this->rounded($row['owedAmounts']));
		$this->assertEqualsWithDelta(200.0, $overview['monthlyTotals'][10], 0.001);
	}

	/**
	 * A yearly bill has one date in the year. While it is due, a payment
	 * linked to it from before its due window is not its payment, for Mark
	 * Paid or the calendar: it keeps its own month, and the date is still
	 * owed. It used to take the date ("paid on 20 September for 29 October")
	 * and October showed it beside the amount still owed, counted twice.
	 */
	public function testAYearlyBillsPaymentFromBeforeItsDueWindowKeepsItsMonth(): void {
		$this->bill(['id' => 30, 'name' => 'Haftpflicht', 'amount' => 240.0, 'frequency' => 'yearly', 'dueDay' => 29,
			'dueMonth' => 10, 'nextDueDate' => '2026-10-29', 'lastPaidDate' => '2025-10-27', 'createdAt' => '2024-01-01 00:00:00']);
		$this->payment(3001, 30, '2026-09-20', 240.0);

		$overview = $this->overview();
		$row = $this->calendarRow($overview, 'Haftpflicht');

		$this->assertNull($this->markPaidWouldUse(30));
		$this->assertSame([['date' => '2026-09-20', 'amount' => 240.0, 'due' => null]], $row['paidDetails'][9]);
		$this->assertArrayNotHasKey(10, $row['paidDetails']);
		$this->assertSame([10 => 240.0], $this->rounded($row['owedAmounts']));
		$this->assertEqualsWithDelta(240.0, $overview['monthlyTotals'][9], 0.001);
		$this->assertEqualsWithDelta(240.0, $overview['monthlyTotals'][10], 0.001);
	}

	/** Its payment inside the due window pays the date, which is then no longer owed; the earlier one keeps its month. */
	public function testAYearlyBillsPaymentForItsDueDatePaysIt(): void {
		$this->bill(['id' => 30, 'name' => 'Haftpflicht', 'amount' => 240.0, 'frequency' => 'yearly', 'dueDay' => 29,
			'dueMonth' => 10, 'nextDueDate' => '2026-10-29', 'lastPaidDate' => '2025-10-27', 'createdAt' => '2024-01-01 00:00:00']);
		$this->payment(3001, 30, '2026-09-20', 240.0);
		$this->payment(3002, 30, '2026-10-20', 240.0);

		$overview = $this->overview();
		$row = $this->calendarRow($overview, 'Haftpflicht');

		$this->assertSame(3002, $this->markPaidWouldUse(30));
		$this->assertSame([['date' => '2026-09-20', 'amount' => 240.0, 'due' => null]], $row['paidDetails'][9]);
		$this->assertSame([['date' => '2026-10-20', 'amount' => 240.0, 'due' => '2026-10-29']], $row['paidDetails'][10]);
		$this->assertSame([], $row['owedAmounts']);
		$this->assertSame([], $row['paidMoreThanOnce']);
		$this->assertEqualsWithDelta(240.0, $overview['monthlyTotals'][10], 0.001);
	}
}
