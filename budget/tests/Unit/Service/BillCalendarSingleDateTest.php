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
 * Bills by Month for a bill with a single date in the year (#399).
 *
 * A bill with one date took every payment it had (#375), so a monthly bill
 * switched to One-Time put its whole history into the one-time month
 * ("9× 292.70" in September, February to August blank), and an invoice
 * paid twice weeks apart read as paid twice in its own month. Its date now
 * takes the payments made within the due window (dueDateToleranceDays());
 * with none that close, the nearest one that can have paid it (for a
 * one-time bill still due, one made since it was last marked paid). Every
 * other payment is a paid month of its own, the month it was made, and
 * never a second payment of the date.
 */
class BillCalendarSingleDateTest extends TestCase {
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
		$bill->setFrequency($fields['frequency'] ?? 'one-time');
		$bill->setDueDay($fields['dueDay'] ?? null);
		$bill->setDueMonth($fields['dueMonth'] ?? null);
		$bill->setStartDate($fields['startDate'] ?? null);
		$bill->setEndDate(null);
		$bill->setNextDueDate($fields['nextDueDate'] ?? null);
		$bill->setLastPaidDate($fields['lastPaidDate'] ?? null);
		$bill->setIsActive($fields['isActive'] ?? false);
		$bill->setIsTransfer(false);
		$bill->setAccountId(1);
		$bill->setDestinationAccountId(null);
		$bill->setRemainingPayments(null);
		$bill->setCustomRecurrencePattern(null);
		$bill->setCreatedAt($fields['createdAt'] ?? '2026-01-15 10:00:00');
		return $bill;
	}

	/** A one-time bill due on $date, with its day and month taken from it as the app stores them. */
	private function oneTime(int $id, string $name, float $amount, string $date, array $fields = []): Bill {
		return $this->bill($fields + [
			'id' => $id, 'name' => $name, 'amount' => $amount, 'frequency' => 'one-time', 'startDate' => $date,
			'dueDay' => (int)substr($date, 8, 2), 'dueMonth' => (int)substr($date, 5, 2),
		]);
	}

	/**
	 * @param array<string, float>|list<array{0: string, 1: float}> $payments date => amount, or [date, amount] pairs, in date order
	 * @return Transaction[]
	 */
	private function payments(int $billId, array $payments): array {
		$rows = [];
		foreach ($payments as $key => $payment) {
			[$date, $amount] = is_array($payment) ? $payment : [$key, $payment];
			$tx = new Transaction();
			$tx->setId($billId * 100 + count($rows));
			$tx->setBillId($billId);
			$tx->setDate($date);
			$tx->setAmount($amount);
			$tx->setType('debit');
			$tx->setStatus('cleared');
			$rows[] = $tx;
		}
		return $rows;
	}

	/**
	 * @param Bill[] $bills
	 * @param Transaction[] $payments in date order, as the mapper returns them
	 */
	private function overview(array $bills, array $payments): array {
		$this->bills->method('findByType')->willReturn($bills);
		$this->transactions->method('findBillPaymentsInYear')->willReturn($payments);
		return $this->service->getAnnualOverview('t399', 2026, false, 'active');
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

	/**
	 * The reporter's 0795700582: monthly, due at the end of the month, paid
	 * twice at the end of August, then switched to One-Time on 30 September,
	 * the date its 27 September payment paid. Its payments paid the old
	 * monthly schedule's dates: each stays in the month it was made, and
	 * September has only its own.
	 */
	private const SWITCHED_MONTHLY = [
		['2026-02-28', 29.90], ['2026-03-29', 29.90], ['2026-04-29', 29.90], ['2026-05-31', 29.90], ['2026-06-28', 29.90],
		['2026-07-25', 29.90], ['2026-08-29', 29.90], ['2026-08-30', 41.70], ['2026-09-27', 41.70],
	];

	public function testABillSwitchedToOneTimeKeepsItsPaymentsInTheMonthsTheyWereMade(): void {
		$bill = $this->oneTime(14074, '0795700582', 29.90, '2026-09-30', ['lastPaidDate' => '2026-09-27']);

		$overview = $this->overview([$bill], $this->payments(14074, self::SWITCHED_MONTHLY));
		$row = $this->row($overview, '0795700582');

		$this->assertSame(
			[2 => 29.9, 3 => 29.9, 4 => 29.9, 5 => 29.9, 6 => 29.9, 7 => 29.9, 8 => 71.6, 9 => 41.7],
			$this->rounded($row['paidAmounts'])
		);
		$this->assertSame([['date' => '2026-09-27', 'amount' => 41.7, 'due' => '2026-09-30']], $row['paidDetails'][9]);
		// The earlier payments paid no date the bill still has
		$this->assertSame([['date' => '2026-02-28', 'amount' => 29.9, 'due' => null]], $row['paidDetails'][2]);
		$this->assertSame([null, null], array_column($row['paidDetails'][8], 'due'));
		$this->assertSame([], $row['paidMoreThanOnce'], 'no month reads as paid twice, August included');
		$this->assertSame([], $row['unrecordedMonths']);
		$this->assertEqualsWithDelta(41.7, $overview['monthlyTotals'][9], 0.001);
		$this->assertEqualsWithDelta(71.6, $overview['monthlyTotals'][8], 0.001);
		$this->assertEqualsWithDelta(29.9, $overview['monthlyTotals'][2], 0.001);
	}

	/** Re-opened by the switch (as v3.0 did), it is the same: September's payment is September's. */
	public function testASwitchedBillStillOpenShowsTheSameMonths(): void {
		$bill = $this->oneTime(14074, '0795700582', 29.90, '2026-09-30', [
			'isActive' => true, 'nextDueDate' => '2026-09-30', 'lastPaidDate' => '2026-09-27',
		]);

		$row = $this->row($this->overview([$bill], $this->payments(14074, self::SWITCHED_MONTHLY)), '0795700582');

		$this->assertSame([2, 3, 4, 5, 6, 7, 8, 9], $row['paidMonths']);
		$this->assertEqualsWithDelta(41.7, $row['paidAmounts'][9], 0.001);
		$this->assertSame([], $row['paidMoreThanOnce']);
	}

	/**
	 * The reporter's 0794880254, switched to One-Time on 29 November for its
	 * final invoice, which is still due. None of its payments is anywhere
	 * near that date, and all were made by the time it was last marked paid:
	 * they paid the monthly schedule, so they keep their months, and
	 * November is still owed. Taking the nearest payment would have moved
	 * September's onto the unpaid invoice.
	 */
	public function testAFinalInvoiceSwitchedToOneTimeStaysDue(): void {
		$bill = $this->oneTime(14068, '0794880254', 35.90, '2026-11-29', [
			'isActive' => true, 'nextDueDate' => '2026-11-29', 'lastPaidDate' => '2026-09-27',
		]);
		$payments = $this->payments(14068, [
			['2026-02-28', 34.95], ['2026-03-30', 34.95], ['2026-04-29', 34.95], ['2026-05-31', 35.90], ['2026-06-29', 35.90],
			['2026-07-25', 35.90], ['2026-08-29', 35.90], ['2026-08-30', 35.90], ['2026-09-27', 35.90],
		]);

		$overview = $this->overview([$bill], $payments);
		$row = $this->row($overview, '0794880254');

		$this->assertSame(
			[2 => 34.95, 3 => 34.95, 4 => 34.95, 5 => 35.9, 6 => 35.9, 7 => 35.9, 8 => 71.8, 9 => 35.9],
			$this->rounded($row['paidAmounts'])
		);
		$this->assertSame([11 => 35.9], $this->rounded($row['owedAmounts']), 'the final invoice is still owed');
		$this->assertSame([], $row['paidMoreThanOnce']);
		$this->assertEqualsWithDelta(35.9, $overview['monthlyTotals'][11], 0.001);
		$this->assertEqualsWithDelta(35.9, $overview['monthlyTotals'][9], 0.001);
	}

	/**
	 * The reporter's two CembraPay invoices (one row, one name), due on
	 * 28 September, paid on 27 September and again on 15 October. The second
	 * payments are 17 days after the date: October's, not a second payment
	 * for September.
	 */
	public function testEachCembraPayPaymentStaysInTheMonthItWasMade(): void {
		$fields = ['lastPaidDate' => '2026-10-15', 'createdAt' => '2026-09-27 18:33:03'];
		$overview = $this->overview([
			$this->oneTime(93, 'CembraPay Temu', 151.67, '2026-09-28', $fields),
			$this->oneTime(94, 'CembraPay Temu', 4.72, '2026-09-28', $fields),
		], array_merge(
			$this->payments(94, [['2026-09-27', 4.72]]),
			$this->payments(93, [['2026-09-27', 151.67]]),
			[$this->payment(9401, 94, '2026-10-15', 4.72), $this->payment(9301, 93, '2026-10-15', 151.67)],
		));

		$this->assertCount(1, $overview['bills']);
		$row = $overview['bills'][0];
		$this->assertSame([9 => 156.39, 10 => 156.39], $this->rounded($row['paidAmounts']));
		$this->assertSame(['2026-09-27', '2026-09-27'], array_column($row['paidDetails'][9], 'date'));
		$this->assertSame(['2026-09-28', '2026-09-28'], array_column($row['paidDetails'][9], 'due'));
		$this->assertSame(['2026-10-15', '2026-10-15'], array_column($row['paidDetails'][10], 'date'));
		$this->assertSame([null, null], array_column($row['paidDetails'][10], 'due'));
		$this->assertSame([], $row['paidMoreThanOnce'], 'each invoice was paid once in each month');
		$this->assertTrue($row['occurrences'][10]);
		$this->assertEqualsWithDelta(156.39, $overview['monthlyTotals'][10], 0.001);
	}

	/** No payment near its date: a paid bill's date takes the nearest one. */
	public function testWithNoPaymentNearItsDateThePaidBillTakesTheNearest(): void {
		$bill = $this->oneTime(70, 'Garage', 321.60, '2026-09-30', ['lastPaidDate' => '2026-08-30']);

		$row = $this->row($this->overview([$bill], $this->payments(70, ['2026-08-30' => 321.60])), 'Garage');

		$this->assertSame([9 => 321.6], $this->rounded($row['paidAmounts']));
		$this->assertSame([['date' => '2026-08-30', 'amount' => 321.6, 'due' => '2026-09-30']], $row['paidDetails'][9]);
		$this->assertFalse($row['occurrences'][8], 'no August cell is made up for it');
	}

	/** Paid once, two months late: still its own date's payment. */
	public function testAOneTimeBillPaidSixtyDaysLateIsPaidOnItsOwnDate(): void {
		$bill = $this->oneTime(71, 'Dentist', 166.70, '2026-06-15', ['lastPaidDate' => '2026-08-14']);

		$row = $this->row($this->overview([$bill], $this->payments(71, ['2026-08-14' => 166.70])), 'Dentist');

		$this->assertSame([6 => 166.7], $this->rounded($row['paidAmounts']));
		$this->assertSame([], $row['unrecordedMonths']);
		$this->assertFalse($row['occurrences'][8]);
	}

	/** Two payments within the due window of the date are still a double payment of it. */
	public function testTwoPaymentsNearTheDateAreStillADoublePayment(): void {
		$bill = $this->oneTime(72, 'Swisscom', 59.0, '2026-09-28', ['lastPaidDate' => '2026-10-05']);

		$row = $this->row($this->overview([$bill], $this->payments(72, ['2026-09-27' => 59.0, '2026-10-05' => 59.0])), 'Swisscom');

		$this->assertSame([9 => 118.0], $this->rounded($row['paidAmounts']));
		$this->assertSame([9 => 2], $row['paidMoreThanOnce']);
		$this->assertFalse($row['occurrences'][10]);
	}

	/** A yearly bill has one date a year too: a payment months from it is a month of its own, not a second payment. */
	public function testAYearlyBillsPaymentFarFromItsDateIsAMonthOfItsOwn(): void {
		$bill = $this->bill(['id' => 73, 'name' => 'Haftpflicht', 'amount' => 240.0, 'frequency' => 'yearly',
			'dueDay' => 15, 'dueMonth' => 3, 'isActive' => true, 'nextDueDate' => '2027-03-15', 'lastPaidDate' => '2026-03-10',
			'createdAt' => '2024-01-01 00:00:00']);

		$row = $this->row($this->overview([$bill], $this->payments(73, ['2026-03-10' => 240.0, '2026-07-01' => 240.0])), 'Haftpflicht');

		$this->assertSame([3 => 240.0, 7 => 240.0], $this->rounded($row['paidAmounts']));
		$this->assertSame([['date' => '2026-07-01', 'amount' => 240.0, 'due' => null]], $row['paidDetails'][7]);
		$this->assertSame([], $row['paidMoreThanOnce']);
	}

	private function payment(int $id, int $billId, string $date, float $amount): Transaction {
		$tx = new Transaction();
		$tx->setId($id);
		$tx->setBillId($billId);
		$tx->setDate($date);
		$tx->setAmount($amount);
		$tx->setType('debit');
		$tx->setStatus('cleared');
		return $tx;
	}
}
