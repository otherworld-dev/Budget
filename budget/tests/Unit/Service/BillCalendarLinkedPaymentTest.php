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
 * Bills by Month for payments made before their bill was set up (#399).
 *
 * The reporter deleted the Alimente bill and set it up again on 27 Sep, so
 * February to August were payments of no bill. Link to bill files them
 * under the new one, whose schedule starts the day it was created (it has
 * no start date). The August payment, 33 days before the first occurrence
 * (29 Sep), was then added to September as if it paid that occurrence a
 * second time: September read 2,768.35 and August stayed empty. A payment
 * from before the bill existed can't be a second payment of one of its
 * occurrences, so it keeps its own month.
 */
class BillCalendarLinkedPaymentTest extends TestCase {
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

	/** Alimente (the reporter's bill 4621): monthly on the 29th, set up again on 27 Sep with no start date. */
	private function alimente(array $fields = []): Bill {
		$bill = new Bill();
		$bill->setId(4621);
		$bill->setUserId('t399');
		$bill->setName('Alimente');
		$bill->setAmount(1365.0);
		$bill->setFrequency('monthly');
		$bill->setDueDay(29);
		$bill->setDueMonth(null);
		$bill->setStartDate($fields['startDate'] ?? null);
		$bill->setEndDate(null);
		$bill->setNextDueDate('2026-10-29');
		$bill->setLastPaidDate($fields['lastPaidDate'] ?? '2026-09-27');
		$bill->setIsActive(true);
		$bill->setIsTransfer(false);
		$bill->setAccountId(1);
		$bill->setDestinationAccountId(null);
		$bill->setRemainingPayments(null);
		$bill->setCustomRecurrencePattern(null);
		$bill->setCreatedAt('2026-09-27 19:12:40');
		return $bill;
	}

	/** @param array<string, float> $payments date => amount, in date order */
	private function payments(array $payments): array {
		$rows = [];
		$id = 1349750;
		foreach ($payments as $date => $amount) {
			$tx = new Transaction();
			$tx->setId($id++);
			$tx->setBillId(4621);
			$tx->setDate($date);
			$tx->setAmount($amount);
			$tx->setType('debit');
			$tx->setStatus('cleared');
			$rows[] = $tx;
		}
		return $rows;
	}

	private function row(Bill $bill, array $payments): array {
		$this->bills->method('findByType')->willReturn([$bill]);
		$this->transactions->method('findBillPaymentsInYear')->willReturn($payments);
		$overview = $this->service->getAnnualOverview('t399', 2026, false, 'active');
		$this->assertCount(1, $overview['bills']);
		return $overview['bills'][0];
	}

	private function rounded(array $amounts): array {
		return array_map(fn ($amount) => round($amount, 2), $amounts);
	}

	/** The reporter's Alimente payments, February to September */
	private const ALIMENTE = [
		'2026-02-27' => 1180.0,
		'2026-03-27' => 1027.75,
		'2026-04-27' => 1330.6,
		'2026-05-25' => 1400.0,
		'2026-06-25' => 1400.0,
		'2026-07-25' => 1400.0,
		'2026-08-27' => 1368.35,
		'2026-09-27' => 1400.0,
	];

	public function testAPaymentFromBeforeTheBillWasSetUpKeepsItsOwnMonth(): void {
		$row = $this->row($this->alimente(), $this->payments(self::ALIMENTE));

		$this->assertSame(
			[2 => 1180.0, 3 => 1027.75, 4 => 1330.6, 5 => 1400.0, 6 => 1400.0, 7 => 1400.0, 8 => 1368.35, 9 => 1400.0],
			$this->rounded($row['paidAmounts'])
		);
		// August's payment pays no date of the bill (it predates it); September's pays the 29th
		$this->assertSame([['date' => '2026-08-27', 'amount' => 1368.35, 'due' => null]], $row['paidDetails'][8]);
		$this->assertSame([['date' => '2026-09-27', 'amount' => 1400.0, 'due' => '2026-09-29']], $row['paidDetails'][9]);
		$this->assertSame([], $row['unrecordedMonths']);
		// ...so neither month reads as paid twice
		$this->assertLessThanOrEqual(1, $row['paidMoreThanOnce'][9] ?? 1);
	}

	/** A payment made once the bill was there still adds to the occurrence it doubles */
	public function testADoublePaymentAfterTheBillWasSetUpStillAddsToItsOccurrence(): void {
		$row = $this->row($this->alimente(['lastPaidDate' => '2026-09-28']), $this->payments([
			'2026-09-27' => 1400.0,
			'2026-09-28' => 1400.0,
		]));

		$this->assertSame([9 => 2800.0], $this->rounded($row['paidAmounts']));
	}

	/** With a start date the bill begins there, created later or not */
	public function testAPaymentFromBeforeTheStartDateKeepsItsOwnMonth(): void {
		$row = $this->row($this->alimente(['startDate' => '2026-03-29']), $this->payments([
			'2026-02-27' => 1180.0,
			'2026-03-27' => 1027.75,
		]));

		$this->assertSame([2 => 1180.0, 3 => 1027.75], $this->rounded($row['paidAmounts']));
	}
}
