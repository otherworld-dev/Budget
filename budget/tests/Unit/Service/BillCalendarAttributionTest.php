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
 * Bills by Month drawn from the reporter's own ledger (#399). The bills and
 * payments below are theirs, ids included: a double payment in August was
 * pushed into September and September's into October, months the bill had
 * moved past with nothing recorded were added to Monthly Totals, and an
 * invoice marked paid without a transaction vanished under Active Only.
 */
class BillCalendarAttributionTest extends TestCase {
	private const TODAY = '2026-10-19';

	private BillMapper $bills;
	private TransactionService $transactions;
	private BillService $service;

	protected function setUp(): void {
		$this->bills = $this->createMock(BillMapper::class);
		$this->transactions = $this->createMock(TransactionService::class);
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([]);
		$accounts->method('findByIds')->willReturn([]);
		$currency = $this->createMock(CurrencyConversionService::class);
		$currency->method('getBaseCurrency')->willReturn('CHF');
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);

		$this->service = new BillService(
			$this->bills, new FrequencyCalculator(), $this->createMock(RecurringBillDetector::class),
			$this->transactions, $this->createMock(IL10N::class), $accounts, $currency,
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
		$bill->setEndDate($fields['endDate'] ?? null);
		$bill->setNextDueDate($fields['nextDueDate'] ?? null);
		$bill->setLastPaidDate($fields['lastPaidDate'] ?? null);
		$bill->setIsActive($fields['isActive'] ?? true);
		$bill->setIsTransfer($fields['isTransfer'] ?? false);
		$bill->setAccountId($fields['accountId'] ?? 1);
		$bill->setDestinationAccountId($fields['destinationAccountId'] ?? null);
		$bill->setRemainingPayments($fields['remainingPayments'] ?? null);
		$bill->setCustomRecurrencePattern(null);
		$bill->setCreatedAt($fields['createdAt']);
		return $bill;
	}

	private function payment(int $id, int $billId, string $date, float $amount, string $type = 'debit'): Transaction {
		$tx = new Transaction();
		$tx->setId($id);
		$tx->setBillId($billId);
		$tx->setDate($date);
		$tx->setAmount($amount);
		$tx->setType($type);
		$tx->setStatus('cleared');
		return $tx;
	}

	/**
	 * @param Bill[] $bills
	 * @param Transaction[] $payments in date order, as the mapper returns them
	 */
	private function overview(array $bills, array $payments, bool $includeTransfers = false, string $status = 'active', int $year = 2026, ?int $accountId = null): array {
		$this->bills->method('findByType')->willReturn($bills);
		$this->transactions->method('findBillPaymentsInYear')->willReturn($payments);
		return $this->service->getAnnualOverview('t399', $year, $includeTransfers, $status, $accountId);
	}

	/** @return Transaction[] one payment of $amount on each date */
	private function paymentsOn(int $billId, array $dates, float $amount): array {
		return array_map(fn (int $i, string $date) => $this->payment($billId * 100 + $i, $billId, $date, $amount), array_keys($dates), $dates);
	}

	private function row(array $overview, string $name): array {
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

	/** CSX Pro Credit Cards (bill 47): a monthly transfer, paid twice at the end of August and twice on 30 September. */
	private function csx(): Bill {
		return $this->bill(['id' => 47, 'name' => 'CSX Pro Credit Cards', 'amount' => 100.0, 'dueDay' => 28,
			'nextDueDate' => '2026-11-28', 'lastPaidDate' => '2026-09-30', 'isTransfer' => true, 'destinationAccountId' => 13,
			'createdAt' => '2026-04-15 19:36:17']);
	}

	/** Both legs of each transfer, as the mapper returns them; only the withdrawals are payments. 911's deposit is long gone. */
	private function csxPayments(): array {
		return [
			$this->payment(909, 47, '2026-06-28', 50.0),
			$this->payment(910, 47, '2026-06-28', 50.0, 'credit'),
			$this->payment(911, 47, '2026-07-25', 50.0),
			$this->payment(1014, 47, '2026-08-29', 50.0),
			$this->payment(1015, 47, '2026-08-29', 50.0, 'credit'),
			$this->payment(1104, 47, '2026-08-30', 50.0),
			$this->payment(1105, 47, '2026-08-30', 50.0, 'credit'),
			$this->payment(1298, 47, '2026-09-30', 100.0),
			$this->payment(1299, 47, '2026-09-30', 100.0, 'credit'),
			$this->payment(1300, 47, '2026-09-30', 100.0),
			$this->payment(1301, 47, '2026-09-30', 100.0, 'credit'),
		];
	}

	/** KPT Prämienrechnung (bill 4): due on the 29th, paid twice at the end of August. */
	private function kpt(): Bill {
		return $this->bill(['id' => 4, 'name' => 'KPT Prämienrechnung', 'amount' => 484.75, 'dueDay' => 29,
			'nextDueDate' => '2026-10-29', 'lastPaidDate' => '2026-09-27', 'createdAt' => '2026-02-01 17:26:05']);
	}

	private function kptPayments(): array {
		$payments = [];
		foreach ([394 => '2026-03-28', 558 => '2026-04-28', 776 => '2026-05-31', 902 => '2026-06-28', 903 => '2026-07-25',
			1007 => '2026-08-29', 1100 => '2026-08-30', 1101 => '2026-09-27'] as $id => $date) {
			$payments[] = $this->payment($id, 4, $date, 484.75);
		}
		return $payments;
	}

	/** Zahlung Corner Card (bill 49): a one-time bill marked paid on 26 April without a transaction. */
	private function zahlungCornerCard(): Bill {
		return $this->bill(['id' => 49, 'name' => 'Zahlung Corner Card', 'amount' => 2841.15, 'frequency' => 'one-time',
			'dueDay' => 29, 'dueMonth' => 4, 'startDate' => '2026-04-29', 'lastPaidDate' => '2026-04-26', 'isActive' => false,
			'createdAt' => '2026-04-15 19:47:50']);
	}

	/**
	 * Each closed occurrence takes its own nearest payment before any
	 * payment is matched further away, so the second August payment stays
	 * in August instead of taking September's place, and September's
	 * second one no longer becomes October's (#399).
	 */
	public function testEachOccurrenceTakesItsNearestPaymentFirst(): void {
		$csx = $this->row($this->overview([$this->csx()], $this->csxPayments(), true), 'CSX Pro Credit Cards');

		$this->assertSame([6 => 50.0, 7 => 50.0, 8 => 100.0, 9 => 100.0, 10 => 100.0], $this->rounded($csx['paidAmounts']));
		$this->assertSame([6, 7, 8, 9, 10], $csx['paidMonths']);
		// April and May: the bill was set up mid-April and moved past both with nothing recorded
		$this->assertSame([4, 5], $csx['unrecordedMonths']);
	}

	public function testADoublePaymentStaysInTheMonthItPaid(): void {
		$kpt = $this->row($this->overview([$this->kpt()], $this->kptPayments()), 'KPT Prämienrechnung');

		$this->assertSame(
			[3 => 484.75, 4 => 484.75, 5 => 484.75, 6 => 484.75, 7 => 484.75, 8 => 969.5, 9 => 484.75],
			$this->rounded($kpt['paidAmounts'])
		);
		$this->assertSame([2], $kpt['unrecordedMonths'], 'February: nothing recorded');
	}

	/**
	 * The payments behind each cell, in date order, each with the date it
	 * paid, so the calendar can say what a cell holds, when it was paid and
	 * whether a date was paid twice.
	 */
	public function testPaidDetailsListEachPaymentBehindACell(): void {
		$kpt = $this->row($this->overview([$this->kpt()], $this->kptPayments()), 'KPT Prämienrechnung');

		$this->assertSame([3, 4, 5, 6, 7, 8, 9], array_keys($kpt['paidDetails']));
		$this->assertSame([
			['date' => '2026-08-29', 'amount' => 484.75, 'due' => '2026-08-29'],
			['date' => '2026-08-30', 'amount' => 484.75, 'due' => '2026-08-29'],
		], $kpt['paidDetails'][8]);
		$this->assertSame([['date' => '2026-09-27', 'amount' => 484.75, 'due' => '2026-09-29']], $kpt['paidDetails'][9]);
		foreach ($kpt['paidDetails'] as $month => $payments) {
			$this->assertEqualsWithDelta($kpt['paidAmounts'][$month], array_sum(array_column($payments, 'amount')), 0.001, "month $month");
		}
		$this->assertSame([8 => 2], $kpt['paidMoreThanOnce'], '29 August was paid twice');
	}

	/**
	 * A one-time bill has one occurrence, and it takes every payment made
	 * for it whenever it was made (#375): both CembraPay payments, the one
	 * of 27 September and the second of 15 October, are September's. The
	 * cell lists both, so it can show it holds two payments for one invoice.
	 */
	public function testASingleOccurrenceTakesEveryPaymentAndListsThem(): void {
		$cembra = $this->bill(['id' => 93, 'name' => 'CembraPay Temu', 'amount' => 151.67, 'frequency' => 'one-time',
			'dueDay' => 28, 'dueMonth' => 9, 'startDate' => '2026-09-28', 'lastPaidDate' => '2026-10-15', 'isActive' => false,
			'createdAt' => '2026-09-27 18:33:03']);

		$row = $this->row($this->overview([$cembra], [
			$this->payment(1277, 93, '2026-09-27', 151.67),
			$this->payment(1585, 93, '2026-10-15', 151.67),
		]), 'CembraPay Temu');

		$this->assertSame([9], $row['paidMonths']);
		$this->assertSame([9 => 303.34], $this->rounded($row['paidAmounts']));
		$this->assertSame([
			['date' => '2026-09-27', 'amount' => 151.67, 'due' => '2026-09-28'],
			['date' => '2026-10-15', 'amount' => 151.67, 'due' => '2026-09-28'],
		], $row['paidDetails'][9]);
		$this->assertSame([9 => 2], $row['paidMoreThanOnce']);
		$this->assertFalse($row['occurrences'][10], 'no October cell is made up for the second payment');
	}

	/** Two one-time invoices of one name share a row; the cell lists both invoices' payments. */
	public function testOneTimeBillsSharingANameMergeTheirPayments(): void {
		$fields = ['name' => 'CembraPay Temu', 'frequency' => 'one-time', 'dueDay' => 28, 'dueMonth' => 9,
			'startDate' => '2026-09-28', 'lastPaidDate' => '2026-10-15', 'isActive' => false];
		$overview = $this->overview([
			$this->bill(['id' => 93, 'amount' => 151.67, 'createdAt' => '2026-09-27 18:33:03'] + $fields),
			$this->bill(['id' => 94, 'amount' => 4.72, 'createdAt' => '2026-09-27 18:33:42'] + $fields),
		], [
			$this->payment(1276, 94, '2026-09-27', 4.72),
			$this->payment(1277, 93, '2026-09-27', 151.67),
			$this->payment(1584, 94, '2026-10-15', 4.72),
			$this->payment(1585, 93, '2026-10-15', 151.67),
		]);

		$this->assertCount(1, $overview['bills']);
		$row = $overview['bills'][0];
		$this->assertSame([93, 94], $row['billIds']);
		$this->assertSame([9 => 312.78], $this->rounded($row['paidAmounts']));
		$this->assertSame(['2026-09-27', '2026-09-27', '2026-10-15', '2026-10-15'], array_column($row['paidDetails'][9], 'date'));
		$this->assertEqualsWithDelta(312.78, array_sum(array_column($row['paidDetails'][9], 'amount')), 0.001);
		$this->assertSame([9 => 2], $row['paidMoreThanOnce'], 'each invoice was paid twice');
	}

	/** Two invoices due in one month, each paid once, are no double payment. */
	public function testTwoInvoicesPaidOnceEachAreNoDoublePayment(): void {
		$fields = ['name' => 'EKZ Ratenrechnung', 'frequency' => 'one-time', 'dueDay' => 28, 'dueMonth' => 4,
			'startDate' => '2026-04-28', 'lastPaidDate' => '2026-04-26', 'isActive' => false];
		$overview = $this->overview([
			$this->bill(['id' => 51, 'amount' => 166.70, 'createdAt' => '2026-03-14 20:04:30'] + $fields),
			$this->bill(['id' => 52, 'amount' => 166.70, 'createdAt' => '2026-03-14 20:05:17'] + $fields),
		], [
			$this->payment(510, 51, '2026-04-26', 166.70),
			$this->payment(520, 52, '2026-04-26', 166.70),
		]);

		$this->assertSame([], $overview['bills'][0]['paidMoreThanOnce']);
	}

	/**
	 * A month counts what was paid in it plus what is still owed. A month
	 * the bill moved past with nothing recorded is neither, so it adds
	 * nothing: April and May came to 100.00 each for CSX, and the Corner
	 * Card invoice marked paid without a transaction added 2,841.15 to
	 * April, money that never left the account.
	 */
	public function testMonthlyTotalsLeaveOutUnrecordedMonths(): void {
		$overview = $this->overview([$this->csx(), $this->zahlungCornerCard()], $this->csxPayments(), true);

		$this->assertSame(
			[1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0, 6 => 50.0, 7 => 50.0, 8 => 100.0,
				9 => 100.0, 10 => 100.0, 11 => 100.0, 12 => 100.0],
			$this->rounded($overview['monthlyTotals'])
		);
		$csx = $this->row($overview, 'CSX Pro Credit Cards');
		$this->assertSame([11 => 100.0, 12 => 100.0], $this->rounded($csx['owedAmounts']), 'November and December are still owed');
	}

	/**
	 * An invoice marked paid without a transaction left nothing for the
	 * calendar to find, so Active Only dropped it although it was settled
	 * this year. It stays, its month drawn as having no payment recorded.
	 */
	public function testActiveOnlyKeepsAnInactiveBillSettledWithoutATransaction(): void {
		$overview = $this->overview([$this->zahlungCornerCard()], []);

		$row = $this->row($overview, 'Zahlung Corner Card');
		$this->assertSame([4], $row['unrecordedMonths']);
		$this->assertSame([], $row['paidMonths']);
		$this->assertTrue($row['occurrences'][4]);
	}

	/**
	 * Only a bill that was settled this year: one deactivated without ever
	 * being paid, or last paid in an earlier year, has no occurrence this
	 * year it went through, and its schedule alone would fill the year with
	 * months that never happened.
	 */
	public function testActiveOnlyStillLeavesOutBillsNotSettledThisYear(): void {
		$neverPaid = $this->bill(['id' => 1, 'name' => 'Old gym', 'amount' => 30.0, 'dueDay' => 15, 'isActive' => false,
			'createdAt' => '2024-01-01 00:00:00']);
		$endedLastYear = $this->bill(['id' => 2, 'name' => 'Old phone', 'amount' => 20.0, 'dueDay' => 15, 'isActive' => false,
			'lastPaidDate' => '2025-06-15', 'createdAt' => '2024-01-01 00:00:00']);
		// Paid ahead, but not due until December: not yet part of the year
		$paidAhead = $this->bill(['id' => 3, 'name' => 'Chimney sweep', 'amount' => 427.80, 'frequency' => 'one-time',
			'dueDay' => 15, 'dueMonth' => 12, 'startDate' => '2026-12-15', 'lastPaidDate' => '2026-10-01', 'isActive' => false,
			'createdAt' => '2026-09-30 10:00:00']);

		$overview = $this->overview([$neverPaid, $endedLastYear, $paidAhead, $this->zahlungCornerCard()], []);

		$this->assertSame(['Zahlung Corner Card'], array_column($overview['bills'], 'name'));
	}

	/**
	 * A weekly bill's payments belong to the weeks they paid. Matched month
	 * by month, the payments at the end of January were nearer February's
	 * first date than January's and moved into February. The month the
	 * bill is part way through counts its payments plus the dates still to
	 * come.
	 */
	public function testAWeeklyBillsPaymentsStayInTheirOwnMonth(): void {
		$weekly = $this->bill(['id' => 7, 'name' => 'Cleaner', 'amount' => 10.0, 'frequency' => 'weekly', 'dueDay' => 5,
			'startDate' => '2026-01-02', 'nextDueDate' => '2026-02-20', 'lastPaidDate' => '2026-02-13',
			'createdAt' => '2026-01-01 09:00:00']);
		$payments = [];
		foreach (['2026-01-02', '2026-01-09', '2026-01-16', '2026-01-23', '2026-01-30', '2026-02-06', '2026-02-13'] as $i => $date) {
			$payments[] = $this->payment(100 + $i, 7, $date, 10.0);
		}

		$overview = $this->overview([$weekly], $payments);
		$row = $this->row($overview, 'Cleaner');

		$this->assertSame([1 => 50.0, 2 => 20.0], $this->rounded($row['paidAmounts']));
		$this->assertSame(['2026-01-02', '2026-01-09', '2026-01-16', '2026-01-23', '2026-01-30'], array_column($row['paidDetails'][1], 'date'));
		$this->assertSame(array_column($row['paidDetails'][1], 'date'), array_column($row['paidDetails'][1], 'due'), 'each paid its own week');
		$this->assertSame([], $row['paidMoreThanOnce'], 'five payments for five dates');
		$this->assertSame([], $row['unrecordedMonths']);
		// February: two paid, two still to come
		$this->assertEqualsWithDelta(40.0, $overview['monthlyTotals'][2], 0.001);
		$this->assertEqualsWithDelta(50.0, $overview['monthlyTotals'][1], 0.001);
	}

	/**
	 * Due on the 28th and always paid a few days late, so December's payment
	 * goes out on 3 January. That payment is for last year's date: it stays
	 * in January, where the money left the account, but it is not a second
	 * payment for January's date. With no date before 1 January to pair with,
	 * it joined January's and the "paid twice" marker fired every January.
	 */
	public function testAPaymentForLastYearsDateIsNoSecondPayment(): void {
		$bill = $this->bill(['id' => 1, 'name' => 'Phone', 'amount' => 80.0, 'dueDay' => 28,
			'nextDueDate' => '2026-10-28', 'lastPaidDate' => '2026-10-03', 'createdAt' => '2025-01-01 00:00:00']);
		$payments = $this->paymentsOn(1, ['2026-01-03', '2026-02-02', '2026-03-03', '2026-04-02', '2026-05-03',
			'2026-06-02', '2026-07-03', '2026-08-02', '2026-09-03', '2026-10-03'], 80.0);

		$row = $this->row($this->overview([$bill], $payments), 'Phone');

		$this->assertSame([], $row['paidMoreThanOnce'], 'no date was paid twice');
		$this->assertSame([
			['date' => '2026-01-03', 'amount' => 80.0, 'due' => '2025-12-28'],
			['date' => '2026-02-02', 'amount' => 80.0, 'due' => '2026-01-28'],
		], $row['paidDetails'][1]);
		$this->assertSame([['date' => '2026-10-03', 'amount' => 80.0, 'due' => '2026-09-28']], $row['paidDetails'][9]);
		$this->assertSame([1 => 160.0, 2 => 80.0, 3 => 80.0, 4 => 80.0, 5 => 80.0, 6 => 80.0, 7 => 80.0, 8 => 80.0, 9 => 80.0],
			$this->rounded($row['paidAmounts']));
		$this->assertSame([], $row['unrecordedMonths']);
	}

	/** Paid a few days early: the 29 December payment is for 1 January, next year's date. */
	public function testAPaymentForNextYearsDateIsNoSecondPayment(): void {
		$bill = $this->bill(['id' => 2, 'name' => 'Gym', 'amount' => 60.0, 'dueDay' => 1,
			'nextDueDate' => '2026-11-01', 'lastPaidDate' => '2026-10-29', 'createdAt' => '2024-06-01 00:00:00']);
		$payments = $this->paymentsOn(2, ['2025-01-29', '2025-02-26', '2025-03-29', '2025-04-28', '2025-05-29', '2025-06-28',
			'2025-07-29', '2025-08-29', '2025-09-28', '2025-10-29', '2025-11-28', '2025-12-29'], 60.0);

		$row = $this->row($this->overview([$bill], $payments, year: 2025), 'Gym');

		$this->assertSame([], $row['paidMoreThanOnce']);
		$this->assertSame([
			['date' => '2025-11-28', 'amount' => 60.0, 'due' => '2025-12-01'],
			['date' => '2025-12-29', 'amount' => 60.0, 'due' => '2026-01-01'],
		], $row['paidDetails'][12]);
		$this->assertEqualsWithDelta(120.0, $row['paidAmounts'][12], 0.001);
	}

	/**
	 * The reporter's Alimente, set up again on 27 September (bill 4621, due
	 * on the 29th, no start date), with the old bill's payments linked to it
	 * as "Link to bill" allows. Nothing of it was due before 29 September,
	 * so each earlier payment keeps its own month. August's, 33 days before
	 * the first date, was taken as a second payment for September (#399).
	 */
	public function testPaymentsFromBeforeTheScheduleBeganKeepTheirOwnMonth(): void {
		$alimente = $this->bill(['id' => 4621, 'name' => 'Alimente', 'amount' => 1365.0, 'dueDay' => 29,
			'nextDueDate' => '2026-10-29', 'lastPaidDate' => '2026-09-27', 'createdAt' => '2026-09-27 18:17:38']);
		$payments = [];
		foreach (['2026-02-27' => 1180.0, '2026-03-27' => 1027.75, '2026-04-27' => 1330.6, '2026-05-25' => 1400.0,
			'2026-06-25' => 1400.0, '2026-07-25' => 1400.0, '2026-08-27' => 1368.35, '2026-09-27' => 1400.0] as $date => $amount) {
			$payments[] = $this->payment(700 + count($payments), 4621, $date, $amount);
		}

		$overview = $this->overview([$alimente], $payments);
		$row = $this->row($overview, 'Alimente');

		$this->assertSame(
			[2 => 1180.0, 3 => 1027.75, 4 => 1330.6, 5 => 1400.0, 6 => 1400.0, 7 => 1400.0, 8 => 1368.35, 9 => 1400.0],
			$this->rounded($row['paidAmounts'])
		);
		$this->assertSame([], $row['paidMoreThanOnce']);
		$this->assertSame([['date' => '2026-08-27', 'amount' => 1368.35, 'due' => null]], $row['paidDetails'][8]);
		$this->assertSame([['date' => '2026-09-27', 'amount' => 1400.0, 'due' => '2026-09-29']], $row['paidDetails'][9]);
		$this->assertEqualsWithDelta(1368.35, $overview['monthlyTotals'][8], 0.001);
		$this->assertEqualsWithDelta(1400.0, $overview['monthlyTotals'][9], 0.001);
	}

	/** A payment a few days before the first date is still the first date's. */
	public function testAnEarlyPaymentForTheFirstDateStillPaysIt(): void {
		$alimente = $this->bill(['id' => 4621, 'name' => 'Alimente', 'amount' => 1365.0, 'dueDay' => 29,
			'nextDueDate' => '2026-10-29', 'lastPaidDate' => '2026-09-27', 'createdAt' => '2026-09-27 18:17:38']);

		$row = $this->row($this->overview([$alimente], [$this->payment(711, 4621, '2026-09-27', 1400.0)]), 'Alimente');

		$this->assertSame([9 => 1400.0], $this->rounded($row['paidAmounts']));
		$this->assertSame([], $row['unrecordedMonths']);
	}

	/** A date of last year paid twice is still a double payment, shown in the month the money went out. */
	public function testLastYearsDatePaidTwiceIsStillADoublePayment(): void {
		$bill = $this->bill(['id' => 1, 'name' => 'Phone', 'amount' => 80.0, 'dueDay' => 28,
			'nextDueDate' => '2026-03-28', 'lastPaidDate' => '2026-03-02', 'createdAt' => '2025-01-01 00:00:00']);
		$payments = $this->paymentsOn(1, ['2026-01-02', '2026-01-03', '2026-02-02', '2026-03-02'], 80.0);

		$row = $this->row($this->overview([$bill], $payments), 'Phone');

		$this->assertSame([1 => 2], $row['paidMoreThanOnce']);
		$this->assertSame(['2025-12-28', '2025-12-28', '2026-01-28'], array_column($row['paidDetails'][1], 'due'));
	}
}
