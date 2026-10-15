<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the Bills and Transfers page cards and the Bills Calendar totals add
 * up, and which currency each bill is priced in.
 */
class BillSummaryCurrencyTest extends TestCase {
	private BillMapper $mapper;
	private AccountMapper $accounts;
	private CurrencyConversionService $currency;
	private TransactionService $transactions;
	private BillService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(BillMapper::class);
		$this->accounts = $this->createMock(AccountMapper::class);
		$this->currency = $this->createMock(CurrencyConversionService::class);
		$this->currency->method('getBaseCurrency')->willReturn('GBP');
		// 1 EUR = 0.5 GBP keeps the arithmetic obvious
		$this->currency->method('convertToBaseFloat')->willReturnCallback(
			fn (float $amount, string $from) => $from === 'EUR' ? $amount / 2 : $amount
		);
		$this->transactions = $this->createMock(TransactionService::class);
		$frequency = new FrequencyCalculator();

		$this->service = new BillService(
			$this->mapper,
			$frequency,
			$this->createMock(RecurringBillDetector::class),
			$this->transactions,
			$this->createMock(IL10N::class),
			$this->accounts,
			$this->currency,
			$this->createMock(TransactionSplitService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class),
			null,
			$this->createMock(RecurringIncomeMapper::class),
		);
	}

	private function account(int $id, string $userId, string $currency): Account {
		$account = new Account();
		$account->setId($id);
		$account->setUserId($userId);
		$account->setName('Account ' . $id);
		$account->setCurrency($currency);
		return $account;
	}

	private function bill(array $o): Bill {
		$bill = new Bill();
		$bill->setId($o['id'] ?? 1);
		$bill->setUserId($o['userId'] ?? 'alice');
		$bill->setName($o['name'] ?? 'Bill');
		$bill->setAmount($o['amount'] ?? 10.0);
		$bill->setFrequency($o['frequency'] ?? 'monthly');
		$bill->setDueDay($o['dueDay'] ?? 15);
		$bill->setDueMonth($o['dueMonth'] ?? null);
		$bill->setIsActive($o['isActive'] ?? true);
		$bill->setAccountId($o['accountId'] ?? null);
		$bill->setNextDueDate($o['nextDueDate'] ?? '2099-06-15');
		$bill->setIsTransfer($o['isTransfer'] ?? false);
		$bill->setDestinationAccountId($o['destinationAccountId'] ?? null);
		$bill->setCategoryId($o['categoryId'] ?? null);
		$bill->setStartDate($o['startDate'] ?? null);
		$bill->setCreatedAt('2024-01-01 00:00:00');
		return $bill;
	}

	/**
	 * A bill on an account another user shared with the bill's owner was
	 * priced from the owner's own accounts only, so an 80 EUR bill on a
	 * partner's euro account read as £80 everywhere.
	 */
	public function testABillOnASharedAccountIsPricedInThatAccountsCurrency(): void {
		$this->accounts->method('findAll')->with('alice')->willReturn([$this->account(1, 'alice', 'GBP')]);
		$this->accounts->method('findByIds')->with([9])->willReturn([$this->account(9, 'bob', 'EUR')]);

		$bills = $this->service->enrichBillsWithCurrency([
			$this->bill(['id' => 1, 'accountId' => 1]),
			$this->bill(['id' => 2, 'accountId' => 9]),
			$this->bill(['id' => 3, 'accountId' => null]),
		], 'alice');

		$this->assertSame(['GBP', 'EUR', 'GBP'], array_map(fn (Bill $b) => $b->getCurrency(), $bills));
	}

	public function testASharedBillOnAThirdPersonsAccountIsPricedInThatAccountsCurrency(): void {
		$this->accounts->method('findAll')->willReturn([]);
		$this->accounts->method('findByIds')->with([9])->willReturn([$this->account(9, 'carol', 'EUR')]);

		$bills = $this->service->enrichSharedBillsWithCurrency([
			['id' => 1, 'userId' => 'bob', 'accountId' => 9],
		]);

		$this->assertSame('EUR', $bills[0]['currency']);
	}

	public function testTheSummaryConvertsABillOnASharedForeignAccount(): void {
		$this->mapper->method('findActive')->with('alice')->willReturn([
			$this->bill(['id' => 1, 'amount' => 100.0, 'accountId' => 1]),
			$this->bill(['id' => 2, 'amount' => 80.0, 'accountId' => 9]),
		]);
		$this->accounts->method('findAll')->willReturn([$this->account(1, 'alice', 'GBP')]);
		$this->accounts->method('findByIds')->willReturn([$this->account(9, 'bob', 'EUR')]);

		$summary = $this->service->getMonthlySummary('alice');

		$this->assertEqualsWithDelta(140.0, $summary['monthlyTotal'], 0.001);
	}

	/**
	 * The Bills page lists bills only, but its cards counted every active
	 * transfer as well: a savings transfer inflated Monthly Total, Due This
	 * Month and Overdue for a list that never showed it.
	 */
	public function testTheBillsSummaryLeavesTransfersOut(): void {
		$due = date('Y-m-d', strtotime('-3 days'));
		$this->mapper->method('findActive')->willReturn([
			$this->bill(['id' => 1, 'amount' => 30.0, 'nextDueDate' => $due]),
			$this->bill(['id' => 2, 'amount' => 500.0, 'isTransfer' => true, 'destinationAccountId' => 3, 'nextDueDate' => $due]),
		]);
		$this->accounts->method('findAll')->willReturn([]);

		$bills = $this->service->getMonthlySummary('alice');
		$transfers = $this->service->getMonthlySummary('alice', [], true);

		$this->assertSame(1, $bills['billCount']);
		$this->assertSame(1, $bills['overdue']);
		$this->assertEqualsWithDelta(30.0, $bills['monthlyTotal'], 0.001);
		$this->assertSame(1, $transfers['billCount']);
		$this->assertEqualsWithDelta(500.0, $transfers['monthlyTotal'], 0.001);
	}

	public function testSharedTransfersStayOffTheBillsSummary(): void {
		$this->mapper->method('findActive')->willReturn([]);
		$this->accounts->method('findAll')->willReturn([]);
		$shared = $this->bill(['id' => 5, 'userId' => 'bob', 'amount' => 70.0, 'isTransfer' => true, 'destinationAccountId' => 3]);

		$this->assertSame(0, $this->service->getMonthlySummary('alice', [$shared])['billCount']);
		$this->assertSame(1, $this->service->getMonthlySummary('alice', [$shared], true)['billCount']);
	}

	/**
	 * A one-time invoice is not a monthly commitment: it added a twelfth of
	 * itself to Monthly Total until paid. Recurring income and budgets count
	 * one-off items as nothing, so the bills total does too. It is still due.
	 */
	public function testAOneTimeBillAddsNothingToTheMonthlyTotal(): void {
		$this->mapper->method('findActive')->willReturn([
			$this->bill(['id' => 1, 'amount' => 30.0]),
			$this->bill(['id' => 2, 'amount' => 1200.0, 'frequency' => 'one-time', 'nextDueDate' => date('Y-m-t')]),
		]);
		$this->accounts->method('findAll')->willReturn([]);

		$summary = $this->service->getMonthlySummary('alice');

		$this->assertEqualsWithDelta(30.0, $summary['monthlyTotal'], 0.001);
		$this->assertEqualsWithDelta(360.0, $summary['totalYearly'], 0.001);
		$this->assertSame(1, $summary['dueThisMonth']);
		$this->assertSame(2, $summary['billCount']);
	}

	/**
	 * With a transfer's destination picked, the Bills Calendar added the
	 * incoming transfer to that account's monthly bill totals, so savings
	 * looked like it had £1000 a month of bills. The row is still listed.
	 */
	public function testTheCalendarTotalsLeaveOutTransfersIntoThePickedAccount(): void {
		$year = (int)date('Y') + 1;
		$fee = $this->bill(['id' => 1, 'name' => 'Fee', 'amount' => 50.0, 'accountId' => 7, 'nextDueDate' => "$year-01-15", 'startDate' => "$year-01-01"]);
		$transfer = $this->bill(['id' => 2, 'name' => 'Saving', 'amount' => 1000.0, 'accountId' => 5, 'isTransfer' => true, 'destinationAccountId' => 7, 'nextDueDate' => "$year-01-15", 'startDate' => "$year-01-01"]);
		$this->mapper->method('findByType')->willReturn([$fee, $transfer]);
		$this->accounts->method('findAll')->willReturn([$this->account(5, 'alice', 'GBP'), $this->account(7, 'alice', 'GBP')]);
		$this->transactions->method('findBillPaymentsInYear')->willReturn([]);

		$intoSavings = $this->service->getAnnualOverview('alice', $year, true, 'active', 7);
		$fromCurrent = $this->service->getAnnualOverview('alice', $year, true, 'active', 5);

		$this->assertCount(2, $intoSavings['bills'], 'the incoming transfer is still listed');
		$this->assertEqualsWithDelta(50.0, $intoSavings['monthlyTotals'][3], 0.001);
		$this->assertEqualsWithDelta(1000.0, $fromCurrent['monthlyTotals'][3], 0.001);
	}

	public function testTheCalendarPricesABillOnASharedForeignAccount(): void {
		$year = (int)date('Y') + 1;
		$bill = $this->bill(['id' => 2, 'amount' => 80.0, 'accountId' => 9, 'nextDueDate' => "$year-01-15", 'startDate' => "$year-01-01"]);
		$this->mapper->method('findByType')->willReturn([$bill]);
		$this->accounts->method('findAll')->willReturn([]);
		$this->accounts->method('findByIds')->willReturn([$this->account(9, 'bob', 'EUR')]);
		$this->transactions->method('findBillPaymentsInYear')->willReturn([]);

		$result = $this->service->getAnnualOverview('alice', $year);

		$this->assertSame('EUR', $result['bills'][0]['currency']);
		$this->assertEqualsWithDelta(40.0, $result['monthlyTotals'][3], 0.001);
	}
}
