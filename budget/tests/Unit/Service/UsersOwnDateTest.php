<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Db\InterestRateMapper;
use OCA\Budget\Db\NetWorthSnapshotMapper;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\AssetService;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\DebtPayoffService;
use OCA\Budget\Service\Forecast\ForecastProjector;
use OCA\Budget\Service\Forecast\PatternAnalyzer;
use OCA\Budget\Service\Forecast\ScenarioBuilder;
use OCA\Budget\Service\Forecast\TrendCalculator;
use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\Income\RecurringIncomeDetector;
use OCA\Budget\Service\NetWorthService;
use OCA\Budget\Service\PensionService;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\UserClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "Today" is the user's date, not the server's (#399 review, F88/F89).
 *
 * Nextcloud runs PHP in UTC, so from local midnight until UTC midnight a
 * user in Auckland or Sydney is a day ahead of the server, and a user in
 * Los Angeles is a day behind it every evening. A purchase dated the
 * user's today is stored cleared, then every "balance as of today" took it
 * off again; a bill due today was counted overdue while its row said due
 * soon. Each case here sets the user's calendar years away from the
 * server's, so no server date can pass by accident.
 */
class UsersOwnDateTest extends TestCase {
	private const TODAY = '2030-06-15';

	/** Rows the ledger holds after the stored balance was last computed: account => [[date, signed amount]] */
	private array $ledger = [];
	/** @var string[] dates every "balance as of" query was asked for */
	private array $asOf = [];

	private function clock(): UserClock {
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);
		return $clock;
	}

	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		return $l;
	}

	/** Net change of the ledger's rows dated after $after, per account */
	private function netAfter(string $after): array {
		$this->asOf[] = $after;
		$out = [];
		foreach ($this->ledger as $accountId => $rows) {
			foreach ($rows as [$date, $amount]) {
				if ($date > $after) {
					$out[$accountId] = ($out[$accountId] ?? 0.0) + $amount;
				}
			}
		}
		return $out;
	}

	/** A transaction mapper answering "balance as of" from $this->ledger */
	private function transactions(): TransactionMapper {
		$tx = $this->createMock(TransactionMapper::class);
		$tx->method('getNetChangeAfterDateBatch')->willReturnCallback(fn (string $u, string $after) => $this->netAfter($after));
		$tx->method('getNetChangeAfterDateForAccounts')->willReturnCallback(
			fn (array $ids, string $after) => array_intersect_key($this->netAfter($after), array_flip($ids))
		);
		$tx->method('getNetChangeAfterDate')->willReturnCallback(fn (int $id, string $after) => $this->netAfter($after)[$id] ?? 0.0);
		return $tx;
	}

	private function account(int $id, float $balance, string $type = 'checking'): Account {
		$account = new Account();
		$account->setId($id);
		$account->setUserId('alice');
		$account->setName("Account $id");
		$account->setType($type);
		$account->setCurrency('GBP');
		$account->setBalance($balance);
		$account->setOpeningBalance(0.0);
		$account->setExcludedFromReports(false);
		return $account;
	}

	// ==================== balances ====================

	private function accountService(AccountMapper $accounts): AccountService {
		$conversion = $this->createMock(CurrencyConversionService::class);
		$conversion->method('getBaseCurrency')->willReturn('GBP');
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getSharedAccountIds')->willReturn([]);
		return new AccountService(
			$accounts, $this->transactions(), $this->createMock(InterestRateMapper::class), $conversion, $shares,
			$this->createMock(TransactionService::class), $this->l10n(),
			null, null, null, null, null, $this->clock(),
		);
	}

	public function testTodaysPurchaseStaysInTheAccountsBalance(): void {
		// 500 in, then 20 spent today: stored 480
		$this->ledger = [1 => [[self::TODAY, -20.0]]];
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([$this->account(1, 480.0)]);
		$accounts->method('find')->willReturn($this->account(1, 480.0));

		$service = $this->accountService($accounts);

		$this->assertSame(480.0, $service->findAllWithCurrentBalances('alice')[0]['balance']);
		$this->assertSame(480.0, $service->findWithCurrentBalance(1, 'alice')['balance']);
		$this->assertSame(480.0, $service->getSummary('alice')['accounts'][0]['balance']);
	}

	public function testReconcilingWithoutADateReconcilesAsOfTheUsersToday(): void {
		$this->ledger = [1 => [[self::TODAY, -20.0]]];
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('find')->willReturn($this->account(1, 480.0));

		$this->accountService($accounts)->reconcile(1, 'alice', 480.0);

		$this->assertSame([self::TODAY], $this->asOf);
	}

	public function testBalanceHistoryRunsToTheUsersToday(): void {
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('find')->willReturn($this->account(1, 480.0));
		$tx = $this->createMock(TransactionMapper::class);
		$ends = [];
		$tx->method('getDailyBalanceChanges')->willReturnCallback(function (int $id, string $start, string $end) use (&$ends) {
			$ends[] = $end;
			return [];
		});
		$tx->method('getNetChangeAfterDate')->willReturn(0.0);
		$conversion = $this->createMock(CurrencyConversionService::class);
		$service = new AccountService(
			$accounts, $tx, $this->createMock(InterestRateMapper::class), $conversion,
			$this->createMock(GranularShareService::class), $this->createMock(TransactionService::class), $this->l10n(),
			null, null, null, null, null, $this->clock(),
		);

		$history = $service->getBalanceHistory(1, 'alice', 7);

		$this->assertSame([self::TODAY], $ends);
		$this->assertSame(self::TODAY, end($history)['date']);
		$this->assertSame('2030-06-09', $history[0]['date']);
	}

	public function testForecastsStartFromTodaysBalance(): void {
		$this->ledger = [1 => [[self::TODAY, -20.0]]];
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([$this->account(1, 480.0)]);
		$tx = $this->transactions();
		$tx->method('findAllByUserAndDateRange')->willReturn([]);
		$forecast = new ForecastService(
			$accounts, $tx, $this->createMock(PatternAnalyzer::class), $this->createMock(TrendCalculator::class),
			$this->createMock(ScenarioBuilder::class), $this->createMock(ForecastProjector::class), null, $this->clock(),
		);

		$live = $forecast->getLiveForecast('alice', 2);
		$this->assertSame(480.0, (float)$live['currentBalance']);
		$this->assertSame(480.0, (float)$forecast->generateForecast('alice')['summary'][0]['currentBalance']);
		// The months ahead of the user's own month
		$this->assertSame(['2030-07', '2030-08'], array_column($live['monthlyProjections'], 'yearMonth'));
	}

	public function testScenariosNetWorthAndDebtsStartFromTodaysBalance(): void {
		$this->ledger = [1 => [[self::TODAY, -20.0]]];
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([$this->account(1, 480.0)]);
		$accounts->method('find')->willReturn($this->account(1, 480.0));
		$tx = $this->transactions();
		$tx->method('findAllByUserAndDateRange')->willReturn([]);

		$scenarios = new ScenarioBuilder($accounts, $tx, $this->clock());
		$scenarios->calculateScenarioBalance('alice', null, 0.0, 0.0);
		$scenarios->getHistoricalBalances('alice', null, 3);
		(new DebtPayoffService($accounts, $tx, null, $this->clock()))->getSummary('alice');
		$conversion = $this->createMock(CurrencyConversionService::class);
		$conversion->method('getBaseCurrency')->willReturn('GBP');
		$conversion->method('convertToBaseFloat')->willReturnArgument(0);
		(new NetWorthService(
			$this->createMock(NetWorthSnapshotMapper::class), $accounts, $tx, $conversion,
			$this->createMock(AssetService::class), $this->createMock(PensionService::class), $this->clock(),
		))->calculateNetWorth('alice');

		$this->assertNotEmpty($this->asOf);
		$this->assertSame([self::TODAY], array_values(array_unique($this->asOf)));
	}

	// ==================== bills ====================

	private function billService(BillMapper $bills, ?TransactionService $transactions = null, ?AccountMapper $accounts = null): BillService {
		$conversion = $this->createMock(CurrencyConversionService::class);
		$conversion->method('getBaseCurrency')->willReturn('GBP');
		return new BillService(
			$bills, new FrequencyCalculator(), $this->createMock(RecurringBillDetector::class),
			$transactions ?? $this->createMock(TransactionService::class), $this->l10n(),
			$accounts ?? $this->createMock(AccountMapper::class), $conversion,
			$this->createMock(TransactionSplitService::class), $this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class), null, $this->createMock(RecurringIncomeMapper::class),
			null, $this->clock(),
		);
	}

	private function bill(int $id, string $due): Bill {
		$bill = new Bill();
		$bill->setId($id);
		$bill->setUserId('alice');
		$bill->setName("Bill $id");
		$bill->setAmount(10.0);
		$bill->setFrequency('monthly');
		$bill->setIsActive(true);
		$bill->setNextDueDate($due);
		return $bill;
	}

	/** A bill mapper answering the date finders over $bills, defaulting to the server's date as the real one does */
	private function billMapper(array $bills): BillMapper {
		$mapper = $this->createMock(BillMapper::class);
		$mapper->method('findOverdue')->willReturnCallback(fn (string $u, ?string $today = null)
			=> array_values(array_filter($bills, fn (Bill $b) => $b->getNextDueDate() < ($today ?? date('Y-m-d')))));
		$mapper->method('findDueInRange')->willReturnCallback(fn (string $u, string $from, string $to)
			=> array_values(array_filter($bills, fn (Bill $b) => $b->getNextDueDate() >= $from && $b->getNextDueDate() <= $to)));
		return $mapper;
	}

	public function testABillDueTodayIsNotOverdue(): void {
		$service = $this->billService($this->billMapper([
			$this->bill(1, '2030-06-14'), $this->bill(2, self::TODAY), $this->bill(3, '2030-07-01'),
		]));

		$ids = static fn (array $bills) => array_map(static fn (Bill $b) => $b->getId(), $bills);
		$this->assertSame([1], $ids($service->findOverdue('alice')));
		$this->assertSame([1, 2], $ids($service->findDueThisMonth('alice')));
		$this->assertSame([1, 2], $ids($service->findUpcoming('alice', 10)));
	}

	public function testTheCalendarProjectsFromTheUsersTodayAndMonth(): void {
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([$this->account(3, 900.0)]);
		$transactions = $this->createMock(TransactionService::class);
		$asOf = [];
		$transactions->method('getBalanceAsOf')->willReturnCallback(function (int $id, string $date) use (&$asOf) {
			$asOf[] = $date;
			return 900.0;
		});
		$service = $this->billService($this->billMapper([]), $transactions, $accounts);

		$overview = $service->getAnnualOverview('alice', 2030, true, 'active', 3);

		$this->assertSame([self::TODAY], $asOf);
		$this->assertNotNull($overview['projectedBalance'], 'the user\'s year gets a projection');
		$this->assertNull($overview['projectedBalance'][5]);
		$this->assertSame(900.0, $overview['projectedBalance'][6]);
	}

	// ==================== income ====================

	private function income(int $id, string $expected, ?string $received = null): RecurringIncome {
		$income = new RecurringIncome();
		$income->setId($id);
		$income->setUserId('alice');
		$income->setName("Income $id");
		$income->setAmount(100.0);
		$income->setFrequency('monthly');
		$income->setIsActive(true);
		$income->setNextExpectedDate($expected);
		$income->setLastReceivedDate($received);
		return $income;
	}

	private function incomeService(RecurringIncomeMapper $mapper): RecurringIncomeService {
		return new RecurringIncomeService(
			$mapper, new FrequencyCalculator(), $this->createMock(RecurringIncomeDetector::class),
			$this->createMock(TransactionService::class), $this->createMock(LoggerInterface::class), $this->l10n(),
			null, $this->clock(),
		);
	}

	public function testIncomeCountsTheUsersMonth(): void {
		$incomes = [
			$this->income(1, '2030-06-25', '2030-05-25'),
			$this->income(2, '2030-07-01', '2030-06-01'),
		];
		$mapper = $this->createMock(RecurringIncomeMapper::class);
		$mapper->method('findActive')->willReturn($incomes);
		$mapper->method('findAll')->willReturn($incomes);
		$ranges = [];
		$mapper->method('findExpectedInRange')->willReturnCallback(function (string $u, string $from, string $to) use (&$ranges) {
			$ranges[] = [$from, $to];
			return [];
		});
		$upcoming = [];
		$mapper->method('findUpcoming')->willReturnCallback(function (string $u, int $days, ?string $today = null) use (&$upcoming) {
			$upcoming[] = $today;
			return [];
		});
		$service = $this->incomeService($mapper);

		$summary = $service->getMonthlySummary('alice');
		$service->findExpectedThisMonth('alice');
		$service->findUpcoming('alice', 30);

		$this->assertSame(1, $summary['expectedThisMonth']);
		$this->assertSame(1, $summary['receivedThisMonth']);
		$this->assertSame([['2030-06-01', '2030-06-30']], $ranges);
		$this->assertSame([self::TODAY], $upcoming);
	}
}
