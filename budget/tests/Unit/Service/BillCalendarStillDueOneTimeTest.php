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
 * Bills by Month for a payment linked to a One-Time bill that is still due
 * (#399). The reporter added a One-Time "Corner Card SA" bill due
 * 28 September and linked the 'Monatsrechnung' of 28 September to it with
 * Link to bill, without pressing Mark Paid. September read 200.00 in the
 * due style ("100.00 CHF still due" plus the payment) and Monthly Totals
 * rose by 200 for one 100 invoice. The payment paid that date: the month is
 * paid, not owed as well.
 */
class BillCalendarStillDueOneTimeTest extends TestCase {
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

	/** A one-time Corner Card SA invoice on Lohnkonto, due on $date; paid unless $fields say otherwise. */
	private function cornerCard(int $id, float $amount, string $date, array $fields = []): Bill {
		$bill = new Bill();
		$bill->setId($id);
		$bill->setUserId('t399');
		$bill->setName('Corner Card SA');
		$bill->setAmount($amount);
		$bill->setFrequency('one-time');
		$bill->setDueDay((int)substr($date, 8, 2));
		$bill->setDueMonth((int)substr($date, 5, 2));
		$bill->setStartDate($date);
		$bill->setEndDate(null);
		$bill->setNextDueDate($fields['nextDueDate'] ?? null);
		$bill->setLastPaidDate($fields['lastPaidDate'] ?? null);
		$bill->setIsActive($fields['isActive'] ?? false);
		$bill->setIsTransfer(false);
		$bill->setAccountId(1);
		$bill->setDestinationAccountId(null);
		$bill->setRemainingPayments(null);
		$bill->setCustomRecurrencePattern(null);
		$bill->setCreatedAt($fields['createdAt'] ?? '2026-05-01 10:00:00');
		return $bill;
	}

	/** The bill the reporter added on 15 October: still due, nothing marked paid. */
	private function stillDue(): Bill {
		return $this->cornerCard(14219, 100.0, '2026-09-28', [
			'isActive' => true, 'nextDueDate' => '2026-09-28', 'createdAt' => '2026-10-15 20:10:00',
		]);
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

	public function testALinkedPaymentOnAStillDueOneTimeBillIsPaidNotAlsoOwed(): void {
		// 1586, the Monatsrechnung typed in on 15 October and dated 28 September
		$overview = $this->overview([$this->stillDue()], [$this->payment(1586, 14219, '2026-09-28', 100.0)]);

		$this->assertCount(1, $overview['bills']);
		$row = $overview['bills'][0];
		$this->assertSame([9 => 100.0], $this->rounded($row['paidAmounts']));
		$this->assertSame([], $row['owedAmounts'], 'not still owed as well');
		$this->assertEqualsWithDelta(100.0, $overview['monthlyTotals'][9], 0.001, 'one 100 invoice adds 100');
	}

	/** In the row the reporter's other Corner Card SA invoices share with it, September is the 100 paid. */
	public function testTheLinkedPaymentCountsOnceInTheSharedRow(): void {
		$overview = $this->overview([
			$this->cornerCard(14029, 0.20, '2026-05-28', ['lastPaidDate' => '2026-05-31']),
			$this->cornerCard(14030, 50.0, '2026-06-29', ['lastPaidDate' => '2026-06-28']),
			$this->cornerCard(14031, 1013.10, '2026-07-27', ['lastPaidDate' => '2026-07-25']),
			$this->cornerCard(14058, 500.0, '2026-08-31', ['lastPaidDate' => '2026-09-05']),
			$this->stillDue(),
		], [
			$this->payment(1, 14029, '2026-05-31', 0.20),
			$this->payment(2, 14030, '2026-06-28', 50.0),
			$this->payment(3, 14031, '2026-07-25', 1013.10),
			$this->payment(4, 14058, '2026-09-05', 500.0),
			$this->payment(1586, 14219, '2026-09-28', 100.0),
		]);

		$this->assertCount(1, $overview['bills']);
		$row = $overview['bills'][0];
		$this->assertSame([5 => 0.2, 6 => 50.0, 7 => 1013.1, 8 => 500.0, 9 => 100.0], $this->rounded($row['paidAmounts']));
		$this->assertSame([], $row['owedAmounts'] ?? []);
		$this->assertEqualsWithDelta(100.0, $overview['monthlyTotals'][9], 0.001);
		$this->assertEqualsWithDelta(500.0, $overview['monthlyTotals'][8], 0.001);
	}

	/** With nothing linked it is owed, as before. */
	public function testAStillDueOneTimeBillWithoutAPaymentIsOwed(): void {
		$overview = $this->overview([$this->stillDue()], []);

		$row = $overview['bills'][0];
		$this->assertSame([9 => 100.0], $this->rounded($row['owedAmounts']));
		$this->assertSame([], $row['paidAmounts']);
		$this->assertEqualsWithDelta(100.0, $overview['monthlyTotals'][9], 0.001);
	}

	/**
	 * A payment nowhere near the date paid something else: it shows in the
	 * month it was made and the invoice is still owed, as the bill says.
	 */
	public function testAPaymentFarFromTheDateLeavesItOwed(): void {
		$overview = $this->overview([$this->stillDue()], [$this->payment(1500, 14219, '2026-07-20', 100.0)]);

		$row = $overview['bills'][0];
		$this->assertSame([7 => 100.0], $this->rounded($row['paidAmounts']));
		$this->assertSame([9 => 100.0], $this->rounded($row['owedAmounts']));
		$this->assertSame([], $row['paidMoreThanOnce']);
	}
}
