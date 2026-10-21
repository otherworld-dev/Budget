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
 * A single-date bill's payments from before its date, made within a few
 * days of each other in one month, read as one date paid twice (#399).
 *
 * Switched from monthly to One-Time, 0795700582 lost the "2×" on August,
 * paid on 29 and 30 August for 31 August: payments before a single date
 * paid no date the bill still has, so each was filed on its own and none
 * added to the marker. The old schedule isn't stored, so payments that
 * close together (OneTimeSettlement::BESIDE_DAYS) are taken as paying one
 * date. Instalments a week or more apart stay separate, and nothing about
 * payments on or after the date changes.
 */
class BillCalendarBesideDaysTest extends TestCase {
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
	 * @param list<array{0: string, 1: float}> $payments [date, amount] pairs, in date order
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

	/** The one row the overview draws for the bill */
	private function row(Bill $bill, array $payments): array {
		$this->bills->method('findByType')->willReturn([$bill]);
		$this->transactions->method('findBillPaymentsInYear')->willReturn($this->payments((int)$bill->getId(), $payments));
		$overview = $this->service->getAnnualOverview('t399', 2026, false, 'active');
		$this->assertCount(1, $overview['bills']);
		return $overview['bills'][0];
	}

	private function rounded(array $amounts): array {
		return array_map(fn ($amount) => round($amount, 2), $amounts);
	}

	/** 0795700582's payments: monthly at the end of the month, twice at the end of August */
	private const SWITCHED_MONTHLY = [
		['2026-02-28', 29.90], ['2026-03-29', 29.90], ['2026-04-29', 29.90], ['2026-05-31', 29.90], ['2026-06-28', 29.90],
		['2026-07-25', 29.90], ['2026-08-29', 29.90], ['2026-08-30', 41.70], ['2026-09-27', 41.70],
	];

	/**
	 * The reporter's 0795700582, switched to One-Time on 30 September:
	 * August shows "2×" again. The payments still name no date, and every
	 * amount stays where it was.
	 */
	public function testABillSwitchedToOneTimeKeepsItsAugustDouble(): void {
		$row = $this->row($this->oneTime(14074, '0795700582', 29.90, '2026-09-30', ['lastPaidDate' => '2026-09-27']), self::SWITCHED_MONTHLY);

		$this->assertSame([8 => 2], $row['paidMoreThanOnce']);
		$this->assertSame([['date' => '2026-08-29', 'amount' => 29.9, 'due' => null], ['date' => '2026-08-30', 'amount' => 41.7, 'due' => null]],
			$row['paidDetails'][8]);
		$this->assertSame(
			[2 => 29.9, 3 => 29.9, 4 => 29.9, 5 => 29.9, 6 => 29.9, 7 => 29.9, 8 => 71.6, 9 => 41.7],
			$this->rounded($row['paidAmounts'])
		);
		$this->assertSame([['date' => '2026-09-27', 'amount' => 41.7, 'due' => '2026-09-30']], $row['paidDetails'][9]);
	}

	/** Still due (0794880254's final invoice): the August double shows all the same, and November is owed. */
	public function testAStillDueSwitchedBillKeepsItsAugustDouble(): void {
		$row = $this->row($this->oneTime(14068, '0794880254', 35.90, '2026-11-29', [
			'isActive' => true, 'nextDueDate' => '2026-11-29', 'lastPaidDate' => '2026-09-27',
		]), [
			['2026-02-28', 34.95], ['2026-03-30', 34.95], ['2026-04-29', 34.95], ['2026-05-31', 35.90], ['2026-06-29', 35.90],
			['2026-07-25', 35.90], ['2026-08-29', 35.90], ['2026-08-30', 35.90], ['2026-09-27', 35.90],
		]);

		$this->assertSame([8 => 2], $row['paidMoreThanOnce']);
		$this->assertSame([null, null], array_column($row['paidDetails'][8], 'due'));
		$this->assertSame([11 => 35.9], $this->rounded($row['owedAmounts']));
	}

	/** Three payments within three days of the first: 3× */
	public function testThreePaymentsCloseTogetherReadAsPaidThreeTimes(): void {
		$row = $this->row($this->oneTime(80, 'Swisscom', 59.0, '2026-09-30', ['lastPaidDate' => '2026-09-29']), [
			['2026-06-27', 59.0], ['2026-06-28', 59.0], ['2026-06-30', 59.0], ['2026-09-29', 59.0],
		]);

		$this->assertSame([6 => 3], $row['paidMoreThanOnce']);
		$this->assertEqualsWithDelta(177.0, $row['paidAmounts'][6], 0.001);
	}

	/** Dentakont's instalments, a month apart: no marker anywhere. */
	public function testInstalmentsAMonthApartHaveNoMarker(): void {
		$row = $this->row($this->oneTime(81, 'Dentakont', 210.0, '2026-06-27', ['lastPaidDate' => '2026-06-28']), [
			['2026-02-26', 210.0], ['2026-03-26', 210.0], ['2026-04-27', 210.0], ['2026-05-26', 210.0], ['2026-06-28', 210.0],
		]);

		$this->assertSame([], $row['paidMoreThanOnce']);
		$this->assertSame([null], array_column($row['paidDetails'][2], 'due'));
	}

	/** Two payments in one month five days apart are two instalments, not one date paid twice. */
	public function testTwoPaymentsFiveDaysApartHaveNoMarker(): void {
		$row = $this->row($this->oneTime(82, 'Dentakont', 210.0, '2026-09-27', ['lastPaidDate' => '2026-09-27']), [
			['2026-05-03', 105.0], ['2026-05-08', 105.0], ['2026-09-27', 210.0],
		]);

		$this->assertSame([], $row['paidMoreThanOnce']);
		$this->assertEqualsWithDelta(210.0, $row['paidAmounts'][5], 0.001);
		$this->assertSame([null, null], array_column($row['paidDetails'][5], 'due'));
	}

	/**
	 * Payments after a one-time date are unchanged: two days apart in
	 * October, each still names the invoice it paid again, with no marker.
	 */
	public function testPaymentsAfterAOneTimeDateAreUnchanged(): void {
		$row = $this->row($this->oneTime(83, 'Garage', 220.0, '2026-09-05', ['lastPaidDate' => '2026-10-16']), [
			['2026-09-04', 220.0], ['2026-10-15', 220.0], ['2026-10-16', 220.0],
		]);

		$this->assertSame(['2026-09-05', '2026-09-05'], array_column($row['paidDetails'][10], 'due'));
		$this->assertSame([], $row['paidMoreThanOnce']);
	}

	/** Nor after a yearly bill's date: two payments a day apart in July name no date and carry no marker. */
	public function testPaymentsAfterAYearlyDateAreUnchanged(): void {
		$row = $this->row($this->bill(['id' => 84, 'name' => 'Haftpflicht', 'amount' => 240.0, 'frequency' => 'yearly',
			'dueDay' => 15, 'dueMonth' => 3, 'isActive' => true, 'nextDueDate' => '2027-03-15', 'lastPaidDate' => '2026-03-10',
			'createdAt' => '2024-01-01 00:00:00']), [
				['2026-03-10', 240.0], ['2026-07-01', 240.0], ['2026-07-02', 240.0],
			]);

		$this->assertSame([null, null], array_column($row['paidDetails'][7], 'due'));
		$this->assertSame([], $row['paidMoreThanOnce']);
	}
}
