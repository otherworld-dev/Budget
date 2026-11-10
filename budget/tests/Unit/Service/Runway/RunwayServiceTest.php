<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Runway;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\RunwayOneOff;
use OCA\Budget\Db\RunwayOneOffMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Service\BudgetStatusService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\CurrencyTotals;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\Runway\RunwayBill;
use OCA\Budget\Service\Runway\RunwayCalculator;
use OCA\Budget\Service\Runway\RunwayRow;
use OCA\Budget\Service\Runway\RunwayService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Service\UserClock;
use PHPUnit\Framework\TestCase;

class RunwayServiceTest extends TestCase {
	private const TODAY = '2026-11-10';

	/** @var Account[] */
	private array $accounts = [];
	/** @var int[] */
	private array $visible = [];
	/** @var array<string, string> */
	private array $settings = [];
	/** @var array{rates: array<int, string>, currency: ?string, unconverted: string[]}|null null = every account at '1' */
	private ?array $conversion = null;
	/** @var array<int, float> */
	private array $future = [];
	private array $rows = [];
	/** @var array<int, list<array>> */
	private array $splits = [];
	/** @var Bill[] The user's active bills */
	private array $bills = [];
	/** @var Bill[] Bills findActive() doesn't return: another user's, or inactive */
	private array $otherBills = [];
	/** @var array<int, ?int> category id => parent id, as the Budget page's tree */
	private array $parentMap = [];
	/** @var RunwayOneOff[] */
	private array $oneOffs = [];
	/** @var array<int, string> */
	private array $budgets = [];
	private ?string $firstDate = '2024-01-15';
	/** @var array|null The calculator's input on the last call */
	private ?array $in = null;
	/** @var list<array{0: int[], 1: ?string, 2: ?string}> */
	private array $rowQueries = [];
	private RunwayService $service;

	protected function setUp(): void {
		$accountMapper = $this->createMock(AccountMapper::class);
		$accountMapper->method('findByIds')->willReturnCallback(fn (array $ids) => array_values(array_filter(
			$this->accounts,
			static fn (Account $a) => in_array($a->getId(), array_map('intval', $ids), true)
		)));

		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->method('findRunwayRows')->willReturnCallback(function (array $ids, ?string $from, ?string $to) {
			$this->rowQueries[] = [$ids, $from, $to];
			return array_values(array_filter($this->rows, static fn (array $r) => in_array($r['account_id'], $ids, true)));
		});
		$transactions->method('firstTransactionDate')->willReturnCallback(fn () => $this->firstDate);
		$transactions->method('getNetChangeAfterDateForAccounts')->willReturnCallback(
			fn (array $ids, string $after) => $after === self::TODAY ? array_intersect_key($this->future, array_flip($ids)) : []
		);

		$splits = $this->createMock(TransactionSplitMapper::class);
		$splits->method('findByTransactionIds')->willReturnCallback(
			fn (array $ids) => array_intersect_key($this->splits, array_flip($ids))
		);

		$bills = $this->createMock(BillMapper::class);
		$bills->method('findActive')->willReturnCallback(fn () => $this->bills);
		$bills->method('findByIds')->willReturnCallback(fn (array $ids) => array_values(array_filter(
			[...$this->bills, ...$this->otherBills],
			static fn (Bill $b) => in_array($b->getId(), array_map('intval', $ids), true)
		)));

		$oneOffs = $this->createMock(RunwayOneOffMapper::class);
		$oneOffs->method('findAll')->willReturnCallback(fn () => $this->oneOffs);

		$budgetStatus = $this->createMock(BudgetStatusService::class);
		$budgetStatus->method('monthlyBaseBudgets')->willReturnCallback(fn () => $this->budgets);
		$budgetStatus->method('budgetParentMap')->willReturnCallback(fn () => $this->parentMap);

		$currencyTotals = $this->createMock(CurrencyTotals::class);
		$currencyTotals->method('accountRates')->willReturnCallback(function (array $accounts) {
			if ($this->conversion !== null) {
				return $this->conversion;
			}
			$rates = [];
			foreach ($accounts as $account) {
				$rates[(int)$account->getId()] = '1';
			}
			return ['rates' => $rates, 'currency' => null, 'unconverted' => []];
		});

		$conversionService = $this->createMock(CurrencyConversionService::class);
		$conversionService->method('getBaseCurrency')->willReturn('GBP');

		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getVisibleAccountIds')->willReturnCallback(fn () => $this->visible);

		$settingService = $this->createMock(SettingService::class);
		$settingService->method('get')->willReturnCallback(fn (string $u, string $key) => $this->settings[$key] ?? null);

		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);

		$real = new RunwayCalculator();
		$calculator = $this->createMock(RunwayCalculator::class);
		$calculator->method('calculate')->willReturnCallback(function (array $in) use ($real) {
			$this->in = $in;
			return $real->calculate($in);
		});

		$this->service = new RunwayService(
			$calculator, $accountMapper, $transactions, $splits, $bills, $oneOffs, $budgetStatus,
			$currencyTotals, $conversionService, $shares, $settingService, $clock
		);
	}

	private function account(int $id, string $type, float $balance = 0.0, array $extra = []): Account {
		$account = new Account();
		$account->setId($id);
		$account->setName($extra['name'] ?? "Account $id");
		$account->setType($type);
		$account->setBalance($balance);
		$account->setCurrency($extra['currency'] ?? 'GBP');
		$account->setClosed($extra['closed'] ?? false);
		$account->setExcludedFromReports($extra['excluded'] ?? false);
		$account->setUserId($extra['owner'] ?? 'user1');
		$this->accounts[] = $account;
		$this->visible[] = $id;
		return $account;
	}

	private function row(int $id, int $accountId, string $date, string $amount, array $extra = []): array {
		return $extra + [
			'id' => $id, 'account_id' => $accountId, 'date' => $date, 'amount' => $amount,
			'description' => "Row $id", 'category_id' => null, 'status' => 'cleared',
			'excluded_from_forecast' => false, 'pension_contrib_id' => null, 'linked_transaction_id' => null,
			'linked_account_id' => null, 'bill_id' => null, 'is_split' => false,
		];
	}

	private function bill(int $id, int $accountId, float $amount, string $frequency, array $extra = []): Bill {
		$bill = new Bill();
		$bill->setId($id);
		$bill->setAccountId($accountId);
		$bill->setAmount($amount);
		$bill->setFrequency($frequency);
		$bill->setAmountType(array_key_exists('amountType', $extra) ? $extra['amountType'] : 'fixed');
		$bill->setIsTransfer($extra['isTransfer'] ?? false);
		$bill->setCategoryId($extra['categoryId'] ?? null);
		if ($extra['other'] ?? false) {
			$this->otherBills[] = $bill;
		} else {
			$this->bills[] = $bill;
		}
		return $bill;
	}

	private function oneOff(int $id, string $date, string $amount): void {
		$oneOff = new RunwayOneOff();
		$oneOff->setId($id);
		$oneOff->setDescription("One-off $id");
		$oneOff->setAmount($amount);
		$oneOff->setDate($date);
		$this->oneOffs[] = $oneOff;
	}

	private static function ids(array $accounts): array {
		$ids = array_map(static fn (Account $a) => (int)$a->getId(), $accounts);
		sort($ids);
		return $ids;
	}

	// ── accounts ────────────────────────────────────────────────────

	public function testUnsetAccountsUseDefaultSet(): void {
		$this->account(1, 'checking');
		$this->account(2, 'savings');
		$this->account(3, 'credit_card');
		$this->account(4, 'cash');
		$this->account(5, 'money_market');
		$this->account(6, 'investment');
		$this->account(7, 'loan');
		$this->account(8, 'mortgage');
		$this->account(9, 'cryptocurrency');
		$this->account(10, 'line_of_credit');
		$this->account(11, 'loan', 0.0, ['name' => 'Second loan']);
		$this->account(12, 'checking', 0.0, ['closed' => true]);
		$this->account(13, 'savings', 0.0, ['excluded' => true]);

		foreach ([null, '', '[]'] as $unset) {
			if ($unset === null) {
				unset($this->settings['runway_account_ids']);
			} else {
				$this->settings['runway_account_ids'] = $unset;
			}
			$this->assertSame([1, 2, 3, 4, 5], self::ids($this->service->chosenAccounts('user1')), var_export($unset, true));
		}

		$result = $this->service->get('user1');
		$this->assertTrue($result['settings']['accountIdsAreDefault']);
		$this->assertSame([1, 2, 3, 4, 5], $result['settings']['accountIds']);
	}

	public function testTheDefaultSetOnlyHoldsAccountsTheUserCanSee(): void {
		$this->account(1, 'checking');
		$this->account(2, 'savings');
		$this->visible = [2];

		$this->assertSame([2], self::ids($this->service->chosenAccounts('user1')));
	}

	public function testTheDefaultSetLeavesOutAccountsSharedWithTheUser(): void {
		$this->account(1, 'checking');
		$this->account(2, 'savings', 0.0, ['owner' => 'partner']);
		$this->account(3, 'credit_card', 0.0, ['owner' => 'partner']);

		$this->assertSame([1], self::ids($this->service->chosenAccounts('user1')));

		// but one can still be ticked
		$this->settings['runway_account_ids'] = '[1,2]';
		$this->assertSame([1, 2], self::ids($this->service->chosenAccounts('user1')));
	}

	public function testWhenEveryChosenAccountIsGoneTheDefaultSetApplies(): void {
		$this->account(1, 'checking');
		$this->account(2, 'savings');
		$this->account(3, 'investment');
		$this->visible = [1, 2];
		// 3 is no longer shared, 99 was deleted
		$this->settings['runway_account_ids'] = '[3,99]';

		$this->assertSame([1, 2], self::ids($this->service->chosenAccounts('user1')));

		$result = $this->service->get('user1');
		$this->assertTrue($result['settings']['accountIdsAreDefault']);
		$this->assertSame([1, 2], $result['settings']['accountIds']);
	}

	public function testChosenAccountsSkipGoneIds(): void {
		$this->account(1, 'checking');
		$this->account(2, 'savings');
		// Chosen on purpose, so taken even though the default set leaves it out
		$this->account(3, 'investment');
		$this->account(4, 'checking');
		$this->visible = [1, 2, 3];
		$this->settings['runway_account_ids'] = '[1,3,4,99]';

		$this->assertSame([1, 3], self::ids($this->service->chosenAccounts('user1')));

		$result = $this->service->get('user1');
		$this->assertFalse($result['settings']['accountIdsAreDefault']);
		$this->assertSame([1, 3], $result['settings']['accountIds']);
	}

	public function testAccountWithoutRateIsSkippedAndReported(): void {
		$this->account(1, 'checking', 100.0);
		$this->account(2, 'savings', 500.0, ['name' => 'Old dollars', 'currency' => 'XYZ']);
		$this->conversion = ['rates' => [1 => '1'], 'currency' => 'GBP', 'unconverted' => ['XYZ']];
		$this->rows = [$this->row(1, 1, '2026-10-05', '-10'), $this->row(2, 2, '2026-10-05', '-20')];

		$result = $this->service->get('user1');

		$this->assertSame([['id' => 2, 'name' => 'Old dollars', 'reason' => 'no_rate']], $result['skippedAccounts']);
		$this->assertSame([['id' => 1, 'name' => 'Account 1', 'balance' => '100.00']], $result['accounts']);
		$this->assertSame('100.00', $result['total']);
		$this->assertSame([1], $this->rowQueries[0][0]);
		$this->assertSame([1], $this->in['chosenAccountIds']);
		// Still chosen: the picker shows it ticked
		$this->assertSame([1, 2], $result['settings']['accountIds']);
	}

	public function testTotalIsBalanceAsOfTodayInBaseCurrency(): void {
		$this->account(1, 'checking', 1000.0, ['currency' => 'EUR']);
		$this->account(2, 'credit_card', -150.5);
		// 200 more arrives after today, so 800 of the 1000 is there today
		$this->future = [1 => 200.0];
		$this->conversion = ['rates' => [1 => '0.85', 2 => '1'], 'currency' => 'GBP', 'unconverted' => []];

		$result = $this->service->get('user1');

		$this->assertSame('GBP', $result['currency']);
		$this->assertSame([
			['id' => 1, 'name' => 'Account 1', 'balance' => '680.00'],
			['id' => 2, 'name' => 'Account 2', 'balance' => '-150.50'],
		], $result['accounts']);
		$this->assertSame('529.50', $result['total']);
		$this->assertSame('529.50', $this->in['total']);
		$this->assertSame(self::TODAY, $this->in['today']);
	}

	public function testOneSharedCurrencyIsTheCurrency(): void {
		$this->account(1, 'checking', 10.0, ['currency' => 'eur']);

		$this->assertSame('EUR', $this->service->get('user1')['currency']);
	}

	public function testNoAccountsAtAllIsTheBaseCurrencyAndZero(): void {
		$result = $this->service->get('user1');

		$this->assertSame('GBP', $result['currency']);
		$this->assertSame('0.00', $result['total']);
		$this->assertSame([], $result['accounts']);
		$this->assertSame([], $this->rowQueries);
	}

	// ── rows ────────────────────────────────────────────────────────

	public function testRowsAreConvertedAndSplitByDate(): void {
		$this->account(1, 'checking', 0.0, ['currency' => 'EUR']);
		$this->account(2, 'savings');
		$this->conversion = ['rates' => [1 => '0.5', 2 => '1'], 'currency' => 'GBP', 'unconverted' => []];
		$this->rows = [
			// As sqlite returns them
			$this->row(1, 1, '2026-10-05', '-12.5', ['category_id' => 7, 'bill_id' => 3]),
			$this->row(2, 2, '2026-10-06', '-0.0', ['status' => null, 'pension_contrib_id' => 4]),
			$this->row(3, 2, '2026-11-02', '40.10', ['linked_transaction_id' => 9, 'linked_account_id' => 1, 'excluded_from_forecast' => true]),
			$this->row(4, 2, '2026-12-01', '-75', ['status' => 'scheduled', 'description' => 'Rent']),
		];

		$this->service->get('user1');

		// From the start of the month a year back, open-ended for future rows
		$this->assertSame([[1, 2], '2025-11-01', null], $this->rowQueries[0]);
		$this->assertEquals([
			new RunwayRow(1, '2026-10-05', '-6.25', 7, 'cleared', false, false, null, 3, [], 'Row 1'),
			new RunwayRow(2, '2026-10-06', '0.00', null, 'cleared', false, true, null, null, [], 'Row 2'),
		], $this->in['rows']);
		$this->assertEquals([
			...$this->in['rows'],
			new RunwayRow(3, '2026-11-02', '40.10', null, 'cleared', true, false, 1, null, [], 'Row 3'),
		], $this->in['historyRows']);
		$this->assertEquals([
			new RunwayRow(4, '2026-12-01', '-75.00', null, 'scheduled', false, false, null, null, [], 'Rent'),
		], $this->in['futureRows']);
		$this->assertSame('2024-01-15', $this->in['firstActivityDate']);
	}

	public function testSplitPartsTakeTheirParentsSignAndRate(): void {
		$this->account(1, 'checking', 0.0, ['currency' => 'EUR']);
		$this->account(2, 'savings');
		$this->conversion = ['rates' => [1 => '0.5', 2 => '1'], 'currency' => 'GBP', 'unconverted' => []];
		$this->rows = [
			$this->row(1, 1, '2026-10-05', '-100', ['is_split' => true]),
			$this->row(2, 2, '2026-10-06', '50', ['is_split' => true]),
		];
		// Parts are stored the way the parent's amount is, unsigned
		$this->splits = [
			1 => [['categoryId' => 5, 'amount' => 60.0], ['categoryId' => 6, 'amount' => 40.0]],
			2 => [['categoryId' => 8, 'amount' => 55.0], ['categoryId' => null, 'amount' => -5.0]],
		];

		$this->service->get('user1');

		$rows = $this->in['rows'];
		$this->assertSame([['categoryId' => 5, 'amount' => '-30.00'], ['categoryId' => 6, 'amount' => '-20.00']], $rows[0]->splits);
		$this->assertSame([['categoryId' => 8, 'amount' => '55.00'], ['categoryId' => null, 'amount' => '-5.00']], $rows[1]->splits);
	}

	// ── bills, one-offs, budgets ────────────────────────────────────

	public function testBillsFilteredToChosenFixedNonTransfer(): void {
		$this->account(1, 'checking');
		$this->account(2, 'savings', 0.0, ['currency' => 'EUR']);
		$this->account(3, 'investment');
		$this->conversion = ['rates' => [1 => '1', 2 => '0.5'], 'currency' => 'GBP', 'unconverted' => []];

		$this->bill(10, 1, 50.0, 'monthly', ['categoryId' => 4]);
		$this->bill(11, 1, 300.0, 'monthly', ['isTransfer' => true]);
		$this->bill(12, 1, 80.0, 'monthly', ['amountType' => 'statement']);
		$this->bill(13, 3, 20.0, 'monthly');
		$this->bill(14, 1, 1200.0, 'yearly', ['amountType' => null]);
		$this->bill(15, 1, 9.0, 'fortnightly-ish');
		$this->bill(16, 2, 60.0, 'monthly');
		$this->bill(17, 1, 30.0, 'Quarterly');

		$this->service->get('user1');

		$this->assertEquals([
			10 => new RunwayBill(10, 'monthly', '50.00', 4),
			14 => new RunwayBill(14, 'yearly', '100.00', null),
			16 => new RunwayBill(16, 'monthly', '30.00', null),
			17 => new RunwayBill(17, 'quarterly', '10.00', null),
		], $this->in['bills']);
	}

	/**
	 * A recurring bill is in the average (as actual rows or smoothed), so
	 * its pre-booked rows are never applied on top, whether or not it is
	 * one the calculator smooths: a transfer, a statement-amount card bill
	 * or another user's bill on a shared account leaked through as events.
	 */
	public function testPreBookingsOfAnyRecurringBillAreNotApplied(): void {
		$this->account(1, 'checking', 5000.0);
		// A monthly transfer to an account outside the runway
		$this->bill(20, 1, 300.0, 'monthly', ['isTransfer' => true]);
		// The card's statement, paid from the current account
		$this->bill(21, 1, 0.0, 'monthly', ['amountType' => 'statement']);
		// Another user's monthly bill on this shared account
		$this->bill(22, 1, 40.0, 'monthly', ['other' => true]);
		// A frequency that doesn't parse is taken as recurring
		$this->bill(23, 1, 10.0, 'fortnightly-ish');
		// One-time: in no average, so its row is applied
		$this->bill(24, 1, 900.0, 'one-time', ['other' => true]);
		$this->rows = [
			$this->row(1, 1, '2026-12-01', '-300', ['status' => 'scheduled', 'bill_id' => 20]),
			$this->row(2, 1, '2026-12-02', '-412.30', ['status' => 'scheduled', 'bill_id' => 21]),
			$this->row(3, 1, '2026-12-03', '-40', ['status' => 'scheduled', 'bill_id' => 22]),
			$this->row(4, 1, '2026-12-04', '-10', ['status' => 'scheduled', 'bill_id' => 23]),
			$this->row(5, 1, '2026-12-05', '-900', ['status' => 'scheduled', 'bill_id' => 24, 'description' => 'Boiler']),
			// A bill since deleted: nothing says it recurs, so it's applied
			$this->row(6, 1, '2026-12-06', '-25', ['status' => 'scheduled', 'bill_id' => 99, 'description' => 'Gone']),
			$this->row(7, 1, '2026-12-07', '-60', ['status' => 'scheduled', 'description' => 'Dentist']),
		];

		$result = $this->service->get('user1');

		$this->assertSame(
			[['2026-12-05', '-900.00', 'Boiler'], ['2026-12-06', '-25.00', 'Gone'], ['2026-12-07', '-60.00', 'Dentist']],
			array_map(static fn (array $e) => [$e['date'], $e['amount'], $e['description']], $result['events'])
		);
	}

	/**
	 * A budget on a parent covers its unbudgeted children, as on the Budget
	 * page: Food's 400 stands for Groceries and Takeaway too, so on budget
	 * swaps the whole branch's 450 a month for 400, not adds 400 to it.
	 */
	public function testAParentsBudgetCoversItsUnbudgetedChildren(): void {
		$this->account(1, 'checking', 100000.0);
		$this->parentMap = [1 => null, 2 => 1, 3 => 1, 4 => null];
		$this->budgets = [1 => '400.00'];
		foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $i => $date) {
			$this->rows[] = $this->row(10 + $i, 1, $date, '-300', ['category_id' => 2]);
			$this->rows[] = $this->row(20 + $i, 1, $date, '-150', ['category_id' => 3]);
		}

		$result = $this->service->get('user1');

		$this->assertSame('450.00', $result['scenarios']['current']['monthlyDrain']);
		$this->assertSame('400.00', $result['scenarios']['onBudget']['monthlyDrain']);
	}

	/**
	 * A budgeted child stays its own group: Groceries' spending swaps for
	 * its 250, and Food's own spending with Takeaway's swaps for Food's 400.
	 * Rows, split parts and smoothed bills all move to their group.
	 */
	public function testABudgetedChildIsItsOwnGroup(): void {
		$this->account(1, 'checking', 100000.0);
		$this->parentMap = [1 => null, 2 => 1, 3 => 1, 5 => 3, 6 => null];
		$this->budgets = [1 => '400.00', 2 => '250.00'];
		foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $i => $date) {
			$this->rows[] = $this->row(10 + $i, 1, $date, '-300', ['category_id' => 2]);
			$this->rows[] = $this->row(20 + $i, 1, $date, '-150', ['category_id' => 3]);
			$this->rows[] = $this->row(30 + $i, 1, $date, '-50', ['category_id' => 1]);
		}
		$this->rows[] = $this->row(40, 1, '2026-10-20', '-30', ['is_split' => true]);
		$this->splits = [40 => [['categoryId' => 5, 'amount' => 20.0], ['categoryId' => 6, 'amount' => 10.0]]];
		$this->bill(50, 1, 120.0, 'yearly', ['categoryId' => 5]);

		$result = $this->service->get('user1');

		$categories = [];
		foreach ($this->in['rows'] as $row) {
			$categories[$row->id] = $row->categoryId;
		}
		$this->assertSame([10 => 2, 20 => 1, 30 => 1, 11 => 2, 21 => 1, 31 => 1, 12 => 2, 22 => 1, 32 => 1, 40 => null], $categories);
		// A grandchild goes to its nearest budgeted ancestor; an unbudgeted branch is left alone
		$this->assertSame([['categoryId' => 1, 'amount' => '-20.00'], ['categoryId' => 6, 'amount' => '-10.00']], end($this->in['rows'])->splits);
		$this->assertSame(1, $this->in['bills'][50]->categoryId);

		// Out a month: 500 + 10 split + 10 smoothed bill = 520. Swapped: Groceries'
		// 300 for 250, and Food's group (50 + 150 + 6.67 split + 10 bill) for 400.
		$this->assertSame('520.00', $result['scenarios']['current']['monthlyDrain']);
		$this->assertSame('653.33', $result['scenarios']['onBudget']['monthlyDrain']);
	}

	public function testOneOffsFlagPast(): void {
		$this->oneOff(1, '2026-11-09', '-12.5');
		$this->oneOff(2, self::TODAY, '-200');
		$this->oneOff(3, '2027-01-15', '1500.00');

		$result = $this->service->get('user1');

		$this->assertSame([
			['id' => 1, 'description' => 'One-off 1', 'amount' => '-12.50', 'date' => '2026-11-09', 'past' => true],
			['id' => 2, 'description' => 'One-off 2', 'amount' => '-200.00', 'date' => self::TODAY, 'past' => false],
			['id' => 3, 'description' => 'One-off 3', 'amount' => '1500.00', 'date' => '2027-01-15', 'past' => false],
		], $result['oneOffs']);
		$this->assertSame([
			['id' => 1, 'description' => 'One-off 1', 'amount' => '-12.50', 'date' => '2026-11-09'],
			['id' => 2, 'description' => 'One-off 2', 'amount' => '-200.00', 'date' => self::TODAY],
			['id' => 3, 'description' => 'One-off 3', 'amount' => '1500.00', 'date' => '2027-01-15'],
		], $this->in['oneOffs']);
	}

	public function testTheBudgetsAreTheMonthlyBaseBudgets(): void {
		$this->budgets = [4 => '100.00'];

		$this->service->get('user1');

		$this->assertSame([4 => '100.00'], $this->in['budgets']);
	}

	// ── settings ────────────────────────────────────────────────────

	public function testSettingsFallBackToDefaultsOnGarbage(): void {
		$this->account(1, 'checking');
		$this->settings = ['runway_window_months' => 'abc', 'runway_include_scheduled' => 'maybe', 'runway_account_ids' => 'xyz'];

		$result = $this->service->get('user1');

		$this->assertSame(['accountIds' => [1], 'windowMonths' => 3, 'includeScheduled' => true, 'accountIdsAreDefault' => true], $result['settings']);
		$this->assertSame([3, true], [$this->in['windowMonths'], $this->in['includeScheduled']]);
	}

	public function testSettingsOutOfRangeFallBackToTheDefaultWindow(): void {
		foreach (['0', '13', '2.5', '-1'] as $window) {
			$this->settings = ['runway_window_months' => $window];
			$this->assertSame(3, $this->service->get('user1')['settings']['windowMonths'], $window);
		}
	}

	public function testStoredSettingsAreUsed(): void {
		$this->settings = ['runway_window_months' => '6', 'runway_include_scheduled' => 'false'];

		$result = $this->service->get('user1');

		$this->assertSame([6, false], [$result['settings']['windowMonths'], $result['settings']['includeScheduled']]);
		$this->assertSame([6, false], [$this->in['windowMonths'], $this->in['includeScheduled']]);
	}

	// ── shape ───────────────────────────────────────────────────────

	public function testTheResultIsTheCalculatorsWithTheSetupAround(): void {
		$this->account(1, 'checking', 1000.0);
		$this->rows = [$this->row(1, 1, '2026-10-05', '-250')];

		$result = $this->service->get('user1');

		$this->assertSame(
			['window', 'monthlyOut', 'monthlyIn', 'excluded', 'scenarios', 'differenceMonths', 'series', 'events',
				'currency', 'total', 'accounts', 'skippedAccounts', 'oneOffs', 'settings'],
			array_keys($result)
		);
		$this->assertSame('83.33', $result['monthlyOut']);
		$this->assertSame('running_out', $result['scenarios']['current']['state']);
	}
}
