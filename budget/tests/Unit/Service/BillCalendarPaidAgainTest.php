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
 * Bills by Month for a one-time invoice paid again after its date (#399).
 *
 * The reporter's CembraPay invoices, due 28 September, were paid on
 * 27 September and again on 15 October once the bill had been re-opened.
 * The second payment stays in October, the month it was made, but the
 * tooltip read "Paid 151.67 CHF on 2026-10-15" with no date, so nothing on
 * the page said it paid the 28 September invoice a second time. It now
 * names that date. Payments made before the date paid something else (a
 * monthly bill switched to One-Time keeps its old schedule's history), and
 * a yearly or Custom bill's date has its own year to be paid in: theirs
 * still name no date.
 */
class BillCalendarPaidAgainTest extends TestCase {
	private const TODAY = '2026-10-20';

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
		$bill->setCustomRecurrencePattern($fields['customRecurrencePattern'] ?? null);
		$bill->setCreatedAt($fields['createdAt'] ?? '2026-01-15 10:00:00');
		return $bill;
	}

	/** A one-time bill due on $date, its day and month taken from it as the app stores them. */
	private function oneTime(int $id, string $name, float $amount, string $date, array $fields = []): Bill {
		return $this->bill($fields + [
			'id' => $id, 'name' => $name, 'amount' => $amount, 'frequency' => 'one-time', 'startDate' => $date,
			'dueDay' => (int)substr($date, 8, 2), 'dueMonth' => (int)substr($date, 5, 2),
		]);
	}

	/**
	 * @param list<array{0: string, 1: float}> $payments [date, amount], in date order
	 * @return Transaction[]
	 */
	private function payments(int $billId, array $payments): array {
		$rows = [];
		foreach ($payments as [$date, $amount]) {
			$tx = new Transaction();
			$tx->setId($billId * 100 + count($rows));
			$tx->setBillId($billId);
			$tx->setAccountId(1);
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

	private function rounded(array $amounts): array {
		return array_map(fn ($amount) => round($amount, 2), $amounts);
	}

	/**
	 * Both CembraPay invoices share a row. Each 15 October payment stays in
	 * October and names 28 September, the invoice it paid again; neither
	 * month reads as paid twice, as each holds one payment of each invoice.
	 */
	public function testCembraPaysSecondPaymentsNameTheInvoiceTheyPaidAgain(): void {
		$fields = ['lastPaidDate' => '2026-10-15', 'createdAt' => '2026-09-27 18:33:03'];
		$overview = $this->overview([
			$this->oneTime(93, 'CembraPay Temu', 151.67, '2026-09-28', $fields),
			$this->oneTime(94, 'CembraPay Temu', 4.72, '2026-09-28', $fields),
		], array_merge(
			$this->payments(94, [['2026-09-27', 4.72], ['2026-10-15', 4.72]]),
			$this->payments(93, [['2026-09-27', 151.67], ['2026-10-15', 151.67]]),
		));

		$this->assertCount(1, $overview['bills']);
		$row = $overview['bills'][0];
		$this->assertSame([9 => 156.39, 10 => 156.39], $this->rounded($row['paidAmounts']));
		$this->assertSame(['2026-09-27', '2026-09-27'], array_column($row['paidDetails'][9], 'date'));
		$this->assertSame(['2026-09-28', '2026-09-28'], array_column($row['paidDetails'][9], 'due'));
		$this->assertSame(['2026-10-15', '2026-10-15'], array_column($row['paidDetails'][10], 'date'));
		$this->assertSame(['2026-09-28', '2026-09-28'], array_column($row['paidDetails'][10], 'due'), 'paid again for 28 September');
		$this->assertSame([], $row['paidMoreThanOnce'], 'no marker: each month holds one payment of each invoice');
		$this->assertTrue($row['occurrences'][10]);
		$this->assertEqualsWithDelta(156.39, $overview['monthlyTotals'][9], 0.001);
		$this->assertEqualsWithDelta(156.39, $overview['monthlyTotals'][10], 0.001);
	}

	/** One invoice on its own: the same, with the amounts the tooltip prints */
	public function testAOneTimeInvoicePaidAgainNamesTheDateItPaid(): void {
		$row = $this->overview(
			[$this->oneTime(93, 'CembraPay Temu', 151.67, '2026-09-28', ['lastPaidDate' => '2026-10-15'])],
			$this->payments(93, [['2026-09-27', 151.67], ['2026-10-15', 151.67]]),
		)['bills'][0];

		$this->assertSame([['date' => '2026-09-27', 'amount' => 151.67, 'due' => '2026-09-28']], $row['paidDetails'][9]);
		$this->assertSame([['date' => '2026-10-15', 'amount' => 151.67, 'due' => '2026-09-28']], $row['paidDetails'][10]);
		$this->assertSame([], $row['paidMoreThanOnce']);
	}

	/**
	 * The reporter's 0795700582, monthly until it was switched to One-Time
	 * on 30 September: its earlier payments paid the old schedule, not that
	 * date, and still name no date.
	 */
	public function testABillSwitchedToOneTimeKeepsItsEarlierPaymentsUndated(): void {
		$row = $this->overview([$this->oneTime(14074, '0795700582', 29.90, '2026-09-30', ['lastPaidDate' => '2026-09-27'])],
			$this->payments(14074, [
				['2026-02-28', 29.90], ['2026-03-29', 29.90], ['2026-04-29', 29.90], ['2026-05-31', 29.90], ['2026-06-28', 29.90],
				['2026-07-25', 29.90], ['2026-08-29', 29.90], ['2026-08-30', 41.70], ['2026-09-27', 41.70],
			]))['bills'][0];

		foreach ([2, 3, 4, 5, 6, 7] as $month) {
			$this->assertSame([null], array_column($row['paidDetails'][$month], 'due'), "month $month");
		}
		$this->assertSame([null, null], array_column($row['paidDetails'][8], 'due'));
		$this->assertSame([['date' => '2026-09-27', 'amount' => 41.7, 'due' => '2026-09-30']], $row['paidDetails'][9]);
		$this->assertSame([], $row['paidMoreThanOnce']);
	}

	/** A yearly bill's payment months after its date is a month of its own, and names no date. */
	public function testAYearlyBillsLaterPaymentStillNamesNoDate(): void {
		$bill = $this->bill(['id' => 73, 'name' => 'Haftpflicht', 'amount' => 240.0, 'frequency' => 'yearly',
			'dueDay' => 15, 'dueMonth' => 3, 'isActive' => true, 'nextDueDate' => '2027-03-15', 'lastPaidDate' => '2026-03-10',
			'createdAt' => '2024-01-01 00:00:00']);

		$row = $this->overview([$bill], $this->payments(73, [['2026-03-10', 240.0], ['2026-07-01', 240.0]]))['bills'][0];

		$this->assertSame([['date' => '2026-03-10', 'amount' => 240.0, 'due' => '2026-03-15']], $row['paidDetails'][3]);
		$this->assertSame([['date' => '2026-07-01', 'amount' => 240.0, 'due' => null]], $row['paidDetails'][7]);
		$this->assertSame([], $row['paidMoreThanOnce']);
	}

	/** Nor does a Custom one-month bill's, paid the reporter's way: once at the date, once weeks later. */
	public function testACustomBillsLaterPaymentStillNamesNoDate(): void {
		$bill = $this->bill(['id' => 92, 'name' => 'EKZ', 'amount' => 190.0, 'frequency' => 'custom', 'dueDay' => 28,
			'customRecurrencePattern' => '{"months":[9]}', 'isActive' => true, 'nextDueDate' => '2027-09-28',
			'lastPaidDate' => '2026-09-27', 'createdAt' => '2024-01-01 00:00:00']);

		$row = $this->overview([$bill], $this->payments(92, [['2026-09-27', 190.0], ['2026-10-15', 190.0]]))['bills'][0];

		$this->assertSame([['date' => '2026-09-27', 'amount' => 190.0, 'due' => '2026-09-28']], $row['paidDetails'][9]);
		$this->assertSame([['date' => '2026-10-15', 'amount' => 190.0, 'due' => null]], $row['paidDetails'][10]);
		$this->assertSame([], $row['paidMoreThanOnce']);
	}
}
