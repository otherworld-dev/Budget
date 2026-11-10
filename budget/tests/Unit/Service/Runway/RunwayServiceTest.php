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
	/** @var Bill[] */
	private array $bills = [];
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

		$oneOffs = $this->createMock(RunwayOneOffMapper::class);
		$oneOffs->method('findAll')->willReturnCallback(fn () => $this->oneOffs);

		$budgetStatus = $this->createMock(BudgetStatusService::class);
		$budgetStatus->method('monthlyBaseBudgets')->willReturnCallback(fn () => $this->budgets);

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
		$this->bills[] = $bill;
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
		$this->account(11, 'pension');
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
