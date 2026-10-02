<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\RecurringBudgetService;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\SettingService;
use PHPUnit\Framework\TestCase;

/**
 * Which bills the automatic budget (#269) counts: the ones whose payments
 * the same budget counts as spent, by whoever files them into the category,
 * in the month being budgeted.
 */
class RecurringBudgetScopeTest extends TestCase {
	/** @var array<string, Bill[]> active bills by owner */
	private array $active = [];
	/** @var array<string, Bill[]> inactive bills by owner */
	private array $inactive = [];
	/** @var array<string, int[]> visible account ids by user */
	private array $visible = [];
	/** @var array<string, array<string, int[]>> owner => recipient => shared category ids */
	private array $recipients = [];
	/** @var array<string, array[]> recipient => shared category rows */
	private array $sharedCategories = [];
	private RecurringBudgetService $service;

	protected function setUp(): void {
		$bills = $this->createMock(BillService::class);
		$bills->method('findActive')->willReturnCallback(fn (string $user) => $this->active[$user] ?? []);
		$bills->method('findByType')->willReturnCallback(
			fn (string $user, ?bool $isTransfer, ?bool $isActive) => $isActive === false ? ($this->inactive[$user] ?? []) : []
		);
		$income = $this->createMock(RecurringIncomeService::class);
		$income->method('findActive')->willReturn([]);

		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findByIds')->willReturnCallback(fn (array $ids) => array_values(array_filter([
			$this->account(1, false),
			$this->account(2, false),
			$this->account(3, true),
			$this->account(4, false),
			$this->account(5, false),
		], fn (Account $a) => in_array($a->getId(), $ids, true))));

		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getVisibleAccountIds')->willReturnCallback(fn (string $user) => $this->visible[$user] ?? []);
		$shares->method('getCategoryShareRecipients')->willReturnCallback(fn (string $owner) => $this->recipients[$owner] ?? []);
		$shares->method('getSharedCategories')->willReturnCallback(fn (string $user) => $this->sharedCategories[$user] ?? []);

		$settings = $this->createMock(SettingService::class);
		$settings->method('get')->willReturn(null);

		$this->service = new RecurringBudgetService(
			$bills,
			$income,
			new FrequencyCalculator(),
			$accounts,
			$shares,
			$settings,
		);
		$this->visible = ['alice' => [1, 2, 3, 4], 'bob' => [4, 5]];
	}

	private function account(int $id, bool $excluded): Account {
		$account = new Account();
		$account->setId($id);
		$account->setExcludedFromReports($excluded);
		return $account;
	}

	private function bill(array $o): Bill {
		$bill = new Bill();
		$bill->setUserId($o['userId'] ?? 'alice');
		$bill->setAmount($o['amount']);
		$bill->setFrequency($o['frequency'] ?? 'monthly');
		$bill->setCategoryId($o['categoryId'] ?? 10);
		$bill->setAccountId($o['accountId'] ?? 1);
		$bill->setIsTransfer($o['isTransfer'] ?? false);
		$bill->setDestinationAccountId($o['destinationAccountId'] ?? null);
		$bill->setStartDate($o['startDate'] ?? null);
		$bill->setEndDate($o['endDate'] ?? null);
		$bill->setLastPaidDate($o['lastPaidDate'] ?? null);
		$bill->setIsActive($o['isActive'] ?? true);
		return $bill;
	}

	/**
	 * A bill paid from an account left out of reports is never counted as
	 * spent, so budgeting it kept the category fully "remaining" for ever.
	 */
	public function testABillPaidFromAnAccountLeftOutOfReportsIsNotBudgeted(): void {
		$this->active['alice'] = [
			$this->bill(['amount' => 40.0, 'accountId' => 3]),
			$this->bill(['amount' => 25.0, 'accountId' => 1]),
		];

		$this->assertSame([10 => 25.0], $this->service->getMonthlyBudgetsByCategory('alice', '2026-10'));
	}

	/**
	 * Both legs of a transfer carry its category, so between two accounts
	 * that count they net to nothing spent. Into an account left out of
	 * reports only the debit counts, and that is spent.
	 */
	public function testATransferIsBudgetedOnlyWhenItsMoneyLeavesTheBudget(): void {
		$this->active['alice'] = [
			$this->bill(['amount' => 100.0, 'categoryId' => 10, 'isTransfer' => true, 'accountId' => 1, 'destinationAccountId' => 2]),
			$this->bill(['amount' => 60.0, 'categoryId' => 11, 'isTransfer' => true, 'accountId' => 1, 'destinationAccountId' => 3]),
		];

		$this->assertSame([11 => 60.0], $this->service->getMonthlyBudgetsByCategory('alice', '2026-10'));
	}

	/**
	 * A category shared with someone who files their own bills into it:
	 * its auto budget counted the owner's bills only while its spending
	 * counted both, so a month of bills paid exactly showed as overspent.
	 */
	public function testTheOwnersBudgetCountsBillsFiledIntoTheCategoryByThoseItIsSharedWith(): void {
		$this->recipients['alice'] = ['bob' => [10]];
		$this->active['alice'] = [$this->bill(['amount' => 30.0, 'categoryId' => 10])];
		$this->active['bob'] = [
			// paid from a joint account alice can see: counts
			$this->bill(['userId' => 'bob', 'amount' => 20.0, 'categoryId' => 10, 'accountId' => 4]),
			// paid from bob's own account, which alice can't see: its spending never reaches her
			$this->bill(['userId' => 'bob', 'amount' => 7.0, 'categoryId' => 10, 'accountId' => 5]),
			// a category of alice's that isn't shared with bob
			$this->bill(['userId' => 'bob', 'amount' => 9.0, 'categoryId' => 12, 'accountId' => 4]),
		];

		$this->assertSame([10 => 50.0], $this->service->getMonthlyBudgetsByCategory('alice', '2026-10'));
	}

	/** The recipient's "auto" hint is the owner's figure, the one their Remaining is measured against. */
	public function testARecipientSeesTheOwnersFigureForASharedCategory(): void {
		$this->recipients['alice'] = ['bob' => [10]];
		$this->sharedCategories['bob'] = [['id' => 10, 'userId' => 'alice']];
		$this->active['alice'] = [$this->bill(['amount' => 30.0, 'categoryId' => 10])];
		$this->active['bob'] = [
			$this->bill(['userId' => 'bob', 'amount' => 20.0, 'categoryId' => 10, 'accountId' => 4]),
			$this->bill(['userId' => 'bob', 'amount' => 15.0, 'categoryId' => 20, 'accountId' => 5]),
		];

		$budgets = $this->service->getMonthlyBudgetsForViewer('bob', '2026-10');
		ksort($budgets);
		$this->assertSame([10 => 50.0, 20 => 15.0], $budgets);
	}

	/**
	 * A month is budgeted from the bills running in it, not today's: a
	 * subscription ending in September and its replacement starting on
	 * 15 October gave October onwards the old amount.
	 */
	public function testEachMonthIsBudgetedFromTheBillsRunningInIt(): void {
		$this->active['alice'] = [
			$this->bill(['amount' => 10.0, 'endDate' => '2026-09-30']),
			$this->bill(['amount' => 15.0, 'startDate' => '2026-10-15']),
		];

		$this->assertSame([10 => 10.0], $this->service->getMonthlyBudgetsByCategory('alice', '2026-09'));
		$this->assertSame([10 => 15.0], $this->service->getMonthlyBudgetsByCategory('alice', '2026-10'));
		$this->assertSame([10 => 15.0], $this->service->getMonthlyBudgetsByCategory('alice', '2026-12'));
	}

	/**
	 * Paying an ending bill's last occurrence switches it off, which took
	 * its amount out of the very month it was paid in.
	 */
	public function testABillThatEndedWithAPaymentThisMonthStillCountsThisMonth(): void {
		$this->inactive['alice'] = [
			$this->bill(['amount' => 10.0, 'isActive' => false, 'endDate' => '2026-09-30', 'lastPaidDate' => '2026-09-05']),
			// switched off by hand, never paid this month
			$this->bill(['amount' => 99.0, 'isActive' => false, 'endDate' => '2026-12-31', 'lastPaidDate' => '2026-06-05']),
		];

		$this->assertSame([10 => 10.0], $this->service->getMonthlyBudgetsByCategory('alice', '2026-09'));
		$this->assertSame([], $this->service->getMonthlyBudgetsByCategory('alice', '2026-10'));
	}
}
