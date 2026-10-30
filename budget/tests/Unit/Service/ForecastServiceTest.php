<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\BudgetCarryoverService;
use OCA\Budget\Service\Forecast\ForecastProjector;
use OCA\Budget\Service\Forecast\PatternAnalyzer;
use OCA\Budget\Service\Forecast\ScenarioBuilder;
use OCA\Budget\Service\Forecast\TrendCalculator;
use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\UserClock;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;

class ForecastServiceTest extends TestCase {
	private ForecastService $service;
	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;
	private PatternAnalyzer $patternAnalyzer;
	private TrendCalculator $trendCalculator;
	private ScenarioBuilder $scenarioBuilder;
	private ForecastProjector $projector;
	private ICache $cache;

	protected function setUp(): void {
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->patternAnalyzer = $this->createMock(PatternAnalyzer::class);
		$this->trendCalculator = $this->createMock(TrendCalculator::class);
		$this->scenarioBuilder = $this->createMock(ScenarioBuilder::class);
		$this->projector = $this->createMock(ForecastProjector::class);

		$this->cache = $this->createMock(ICache::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);

		$this->service = new ForecastService(
			$this->accountMapper,
			$this->transactionMapper,
			$this->patternAnalyzer,
			$this->trendCalculator,
			$this->scenarioBuilder,
			$this->projector,
			$cacheFactory
		);
	}

	private function makeAccount(int $id, float $balance, string $currency = 'GBP'): Account {
		$account = new Account();
		$account->setId($id);
		$account->setName("Account {$id}");
		$account->setBalance($balance);
		$account->setCurrency($currency);
		return $account;
	}

	private function makeTransaction(bool $excludedFromForecast = false, string $type = 'debit', float $amount = 100.0): Transaction {
		$transaction = new Transaction();
		$transaction->setDate('2026-01-01');
		$transaction->setAmount($amount);
		$transaction->setType($type);
		$transaction->setExcludedFromForecast($excludedFromForecast);
		return $transaction;
	}

	// ===== invalidateCache =====

	public function testInvalidateCacheRemovesEntries(): void {
		// Live keys carry horizon/visibility suffixes — invalidation must
		// prefix-clear them (a bare remove() never matched any stored key)
		$this->cache->expects($this->once())->method('clear')->with('live_user1_');
		$this->cache->expects($this->once())->method('remove')->with('forecast_user1_all');

		$this->service->invalidateCache('user1');
	}

	// ===== getLiveForecast - cache hit =====

	public function testGetLiveForecastReturnsCachedResult(): void {
		$cached = ['currentBalance' => 5000.0, 'projectedBalance' => 6000.0];
		$this->cache->method('get')->willReturn($cached);

		// Should NOT hit database
		$this->accountMapper->expects($this->never())->method('findAll');

		$result = $this->service->getLiveForecast('user1');
		$this->assertSame($cached, $result);
	}

	// ===== getLiveForecast - cache miss =====

	public function testGetLiveForecastComputesWhenCacheMiss(): void {
		$this->cache->method('get')->willReturn(null);

		$account = $this->makeAccount(1, 10000.0, 'GBP');
		$this->accountMapper->method('findAll')->willReturn([$account]);
		// Looked up for the accounts forecast, not by user: a shared account's
		// future rows must come off its balance too
		$this->transactionMapper->method('getNetChangeAfterDateForAccounts')->with([1])->willReturn([1 => 500.0]);
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn([]);

		// Pattern analysis returns empty monthly data
		$this->patternAnalyzer->method('aggregateMonthlyData')->willReturn([]);
		$this->patternAnalyzer->method('getCategoryBreakdown')->willReturn([]);
		$this->trendCalculator->method('calculateTrend')->willReturn(0.0);
		$this->trendCalculator->method('getTrendDirection')->willReturn('stable');
		$this->projector->method('calculateDataConfidence')->willReturn(50.0);

		// Should cache the result
		$this->cache->expects($this->once())->method('set');

		$result = $this->service->getLiveForecast('user1');

		// Balance = 10000 - 500 (future changes) = 9500
		$this->assertEquals(9500.0, $result['currentBalance']);
		$this->assertEquals('GBP', $result['currency']);
		$this->assertArrayHasKey('monthlyProjections', $result);
		$this->assertArrayHasKey('trends', $result);
		$this->assertArrayHasKey('savingsProjection', $result);
		$this->assertArrayHasKey('dataQuality', $result);
	}

	/**
	 * A transfer between two accounts the forecast covers is neither income
	 * nor spending: its credit leg was counted as income and its debit leg
	 * as an expense, so a 1000 monthly transfer to savings added 1000 to both
	 * averages and showed as spending in the category list. A transfer to
	 * an account outside the forecast is still money out.
	 */
	public function testTransfersBetweenForecastAccountsAreLeftOutOfIncomeAndSpending(): void {
		$this->cache->method('get')->willReturn(null);
		$this->accountMapper->method('findAll')->willReturn([$this->makeAccount(1, 100.0)]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
		$tx = function (int $id, string $type, ?int $linked) {
			$t = $this->makeTransaction(false, $type);
			$t->setId($id);
			$t->setLinkedTransactionId($linked);
			return $t;
		};
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn([
			$tx(1, 'credit', null),   // salary
			$tx(2, 'debit', 3),       // to savings ...
			$tx(3, 'credit', 2),      // ... and arriving there
			$tx(4, 'debit', 99),      // to an account the forecast doesn't cover
		]);
		$seen = [];
		$this->patternAnalyzer->method('aggregateMonthlyData')->willReturnCallback(function (array $transactions) use (&$seen) {
			$seen['monthly'] = array_map(fn ($t) => $t->getId(), $transactions);
			return [];
		});
		$this->patternAnalyzer->method('getCategoryBreakdown')->willReturnCallback(function ($user, array $transactions) use (&$seen) {
			$seen['categories'] = array_map(fn ($t) => $t->getId(), $transactions);
			return [];
		});
		$this->trendCalculator->method('calculateTrend')->willReturn(0.0);
		$this->trendCalculator->method('getTrendDirection')->willReturn('stable');
		$this->projector->method('calculateDataConfidence')->willReturn(50.0);

		$this->service->getLiveForecast('user1');

		$this->assertSame([1, 4], $seen['monthly']);
		$this->assertSame([1, 4], $seen['categories']);
	}

	public function testLiveForecastProjectionsCarryAYearMonthForTheUi(): void {
		$this->cache->method('get')->willReturn(null);
		$this->accountMapper->method('findAll')->willReturn([$this->makeAccount(1, 100.0, 'GBP')]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn([]);
		$this->patternAnalyzer->method('aggregateMonthlyData')->willReturn([]);
		$this->patternAnalyzer->method('getCategoryBreakdown')->willReturn([]);
		$this->trendCalculator->method('calculateTrend')->willReturn(0.0);
		$this->trendCalculator->method('getTrendDirection')->willReturn('stable');
		$this->projector->method('calculateDataConfidence')->willReturn(50.0);

		$result = $this->service->getLiveForecast('user1', 3);

		// The web UI formats `yearMonth` in the user's language; `month` stays
		// the English label the forecast-warning notification reads.
		$this->assertCount(3, $result['monthlyProjections']);
		foreach ($result['monthlyProjections'] as $i => $projection) {
			// Stepped from the 1st like the service: from the 31st, strtotime('+1 months')
			// skips any shorter month and the test failed on every month end.
			$expected = strtotime('first day of +' . ($i + 1) . ' months');
			$this->assertSame(date('Y-m', $expected), $projection['yearMonth']);
			$this->assertSame(date('M Y', $expected), $projection['month']);
		}
	}

	public function testGetLiveForecastDeterminesPrimaryCurrency(): void {
		$this->cache->method('get')->willReturn(null);

		$gbpAccount = $this->makeAccount(1, 10000.0, 'GBP');
		$usdAccount = $this->makeAccount(2, 500.0, 'USD');
		$this->accountMapper->method('findAll')->willReturn([$gbpAccount, $usdAccount]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn([]);
		$this->patternAnalyzer->method('aggregateMonthlyData')->willReturn([]);
		$this->patternAnalyzer->method('getCategoryBreakdown')->willReturn([]);
		$this->trendCalculator->method('calculateTrend')->willReturn(0.0);
		$this->trendCalculator->method('getTrendDirection')->willReturn('stable');
		$this->projector->method('calculateDataConfidence')->willReturn(50.0);

		$result = $this->service->getLiveForecast('user1');

		// GBP has higher absolute balance → primary currency
		$this->assertEquals('GBP', $result['currency']);
	}

	public function testGetLiveForecastDataQualityReliable(): void {
		$this->cache->method('get')->willReturn(null);

		$account = $this->makeAccount(1, 5000.0);
		$this->accountMapper->method('findAll')->willReturn([$account]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		// 4 months of data with 15 transactions
		$monthlyData = [
			['income' => 5000, 'expenses' => 3000],
			['income' => 5200, 'expenses' => 3100],
			['income' => 4800, 'expenses' => 2900],
			['income' => 5100, 'expenses' => 3200],
		];
		$transactions = array_map(fn () => $this->makeTransaction(), range(1, 15)); // 15 dummy transactions

		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);
		$this->patternAnalyzer->method('aggregateMonthlyData')->willReturn($monthlyData);
		$this->patternAnalyzer->method('getCategoryBreakdown')->willReturn([]);
		$this->trendCalculator->method('calculateTrend')->willReturn(50.0);
		$this->trendCalculator->method('getTrendDirection')->willReturn('up');
		$this->projector->method('calculateDataConfidence')->willReturn(80.0);

		$result = $this->service->getLiveForecast('user1');

		$this->assertEquals(4, $result['dataQuality']['monthsOfData']);
		$this->assertEquals(15, $result['dataQuality']['transactionCount']);
		$this->assertTrue($result['dataQuality']['isReliable']); // >= 3 months && >= 10 txns
	}

	public function testGetLiveForecastExcludesFlaggedTransactions(): void {
		// #270: transactions flagged excludedFromForecast are dropped from the
		// pattern analysis (so extraordinary one-offs don't skew projections).
		$this->cache->method('get')->willReturn(null);

		$account = $this->makeAccount(1, 5000.0);
		$this->accountMapper->method('findAll')->willReturn([$account]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		$transactions = array_merge(
			array_map(fn () => $this->makeTransaction(false), range(1, 7)),
			array_map(fn () => $this->makeTransaction(true), range(1, 3)) // 3 excluded
		);
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);

		// Only the 7 non-excluded transactions should reach pattern analysis
		$analysed = [];
		$this->patternAnalyzer->method('aggregateMonthlyData')
			->willReturnCallback(function (array $txns) use (&$analysed) {
				$analysed[] = count($txns);
				return [];
			});
		$this->patternAnalyzer->method('getCategoryBreakdown')->willReturn([]);
		$this->trendCalculator->method('calculateTrend')->willReturn(0.0);
		$this->trendCalculator->method('getTrendDirection')->willReturn('stable');
		$this->projector->method('calculateDataConfidence')->willReturn(50.0);

		$result = $this->service->getLiveForecast('user1');

		$this->assertEquals(7, $result['dataQuality']['transactionCount']);
		$this->assertSame([7], $analysed);
	}

	// ===== generateForecast =====

	public function testGenerateForecastAllAccounts(): void {
		$accounts = [
			$this->makeAccount(1, 5000.0),
			$this->makeAccount(2, 3000.0),
		];
		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
		$this->transactionMapper->method('findByDateRange')->willReturn([]);
		$this->patternAnalyzer->method('analyzeTransactionPatterns')->willReturn([
			'monthly' => ['income' => [], 'expenses' => []],
		]);
		$this->projector->method('generateMonthlyProjections')->willReturn([
			['endingBalance' => 6000.0],
		]);
		$this->projector->method('generateCategoryForecasts')->willReturn([]);
		$this->projector->method('calculateOverallConfidence')->willReturn(70.0);
		$this->scenarioBuilder->method('generateScenarios')->willReturn([]);

		$result = $this->service->generateForecast('user1');

		$this->assertCount(2, $result['summary']);
		$this->assertEquals(5000.0, $result['summary'][0]['currentBalance']);
		$this->assertEquals(3000.0, $result['summary'][1]['currentBalance']);
	}

	public function testGenerateForecastSingleAccount(): void {
		$account = $this->makeAccount(1, 5000.0);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
		$this->transactionMapper->method('findByDateRange')->willReturn([]);
		$this->patternAnalyzer->method('analyzeTransactionPatterns')->willReturn([]);
		$this->projector->method('generateMonthlyProjections')->willReturn([]);
		$this->projector->method('generateCategoryForecasts')->willReturn([]);
		$this->projector->method('calculateOverallConfidence')->willReturn(60.0);
		$this->scenarioBuilder->method('generateScenarios')->willReturn([]);

		$result = $this->service->generateForecast('user1', 1);

		$this->assertCount(1, $result['summary']);
	}

	// ===== stub methods =====

	public function testGetCashFlowForecastReturnsStructure(): void {
		$result = $this->service->getCashFlowForecast('user1', '2025-01-01', '2025-12-31');

		$this->assertArrayHasKey('periods', $result);
		$this->assertArrayHasKey('cumulativeFlow', $result);
		$this->assertArrayHasKey('insights', $result);
	}

	public function testGetSpendingTrendsReturnsStructure(): void {
		$result = $this->service->getSpendingTrends('user1');

		$this->assertArrayHasKey('monthlyTrends', $result);
		$this->assertArrayHasKey('categoryTrends', $result);
	}

	public function testRunScenariosDelegates(): void {
		$this->scenarioBuilder->expects($this->once())->method('runScenarios')
			->with('user1', null, []);

		$this->service->runScenarios('user1');
	}

	public function testExportForecastReturnsWrappedData(): void {
		$forecastData = ['summary' => []];
		$result = $this->service->exportForecast('user1', $forecastData);

		$this->assertEquals('user1', $result['userId']);
		$this->assertEquals('json', $result['format']);
		$this->assertSame($forecastData, $result['data']);
	}

	// ===== no cache factory =====

	public function testServiceWorksWithoutCacheFactory(): void {
		$serviceNoCache = new ForecastService(
			$this->accountMapper,
			$this->transactionMapper,
			$this->patternAnalyzer,
			$this->trendCalculator,
			$this->scenarioBuilder,
			$this->projector,
			null // no cache factory
		);

		// invalidateCache should be a no-op
		$serviceNoCache->invalidateCache('user1');
		$this->assertTrue(true); // No exception thrown
	}

	// ===== Budget months and complete history (#457) =====

	/**
	 * A forecast on 30 October 2026, counting in months that start on
	 * $monthStartDay, with what each step was asked recorded in $seen.
	 *
	 * @param Account[] $accounts
	 * @param array<int, string> $firstDates
	 */
	private function forecastOn30October(int $monthStartDay, array $accounts, array $firstDates, ?array &$seen): array {
		$seen = ['range' => null, 'firstDatesFor' => null, 'aggregatedWith' => null];
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn('2026-10-30');
		$carryover = $this->createMock(BudgetCarryoverService::class);
		$carryover->method('reportMonthStartDay')->willReturn($monthStartDay);
		$this->cache->method('get')->willReturn(null);
		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getFirstTransactionDates')->willReturnCallback(function (array $ids) use (&$seen, $firstDates) {
			$seen['firstDatesFor'] = $ids;
			return array_intersect_key($firstDates, array_flip($ids));
		});
		$this->transactionMapper->method('findAllByUserAndDateRange')->willReturnCallback(function ($u, $start, $end) use (&$seen) {
			// The history first, then the rows dated after today
			$seen[$start > '2026-10-30' ? 'futureRange' : 'range'] = [$start, $end];
			return [];
		});
		$this->patternAnalyzer->method('aggregateMonthlyData')->willReturnCallback(function ($t, $rates, $day) use (&$seen) {
			$seen['aggregatedWith'] = $day;
			return [];
		});
		$this->patternAnalyzer->method('getCategoryBreakdown')->willReturn([]);
		$this->trendCalculator->method('getTrendDirection')->willReturn('stable');

		$service = new ForecastService(
			$this->accountMapper, $this->transactionMapper, $this->patternAnalyzer, $this->trendCalculator,
			$this->scenarioBuilder, $this->projector, null, $clock, null, null, null, $carryover
		);
		return $service->getLiveForecast('user1', 3);
	}

	private function accountWithOpeningBalance(int $id, float $opening): Account {
		$account = $this->makeAccount($id, 1000.0, 'EUR');
		$account->setOpeningBalance($opening);
		return $account;
	}

	public function testLiveForecastLearnsFromCalendarMonthsByDefault(): void {
		$result = $this->forecastOn30October(1, [$this->accountWithOpeningBalance(1, 0.0)], [], $seen);

		$this->assertSame(['2025-10-01', '2026-09-30'], $seen['range']);
		$this->assertSame(1, $seen['aggregatedWith']);
		$this->assertSame(['2026-11', '2026-12', '2027-01'], array_column($result['monthlyProjections'], 'yearMonth'));
		$this->assertSame(1, $result['monthStartDay']);
	}

	public function testLiveForecastLearnsFromBudgetMonthsWhenChosen(): void {
		// 30 October with start day 26 is in November's budget month
		$result = $this->forecastOn30October(26, [$this->accountWithOpeningBalance(1, 0.0)], [], $seen);

		$this->assertSame(['2025-10-26', '2026-10-25'], $seen['range']);
		$this->assertSame(26, $seen['aggregatedWith']);
		$this->assertSame(['2026-12', '2027-01', '2027-02'], array_column($result['monthlyProjections'], 'yearMonth'));
		$this->assertSame(26, $result['monthStartDay']);
	}

	public function testLiveForecastLeavesOutAMonthTheHistoryOnlyPartlyHolds(): void {
		// Synced from 6 September: September holds only part of its month
		$this->forecastOn30October(26, [$this->accountWithOpeningBalance(1, 2500.0)], [1 => '2026-09-06'], $seen);

		// Budget months: September (26 Aug - 25 Sep) is partial, October whole
		$this->assertSame(['2026-09-26', '2026-10-25'], $seen['range']);
	}

	public function testLiveForecastHasNoHistoryWhenNoMonthIsComplete(): void {
		$result = $this->forecastOn30October(1, [$this->accountWithOpeningBalance(1, 2500.0)], [1 => '2026-09-06'], $seen);

		// Calendar months: September is partial and October still running
		$this->assertNull($seen['range']);
		$this->assertSame(0, $result['dataQuality']['monthsOfData']);
	}

	public function testLiveForecastKeepsAMonthTheHistoryStartsOnTheFirstOf(): void {
		$this->forecastOn30October(1, [$this->accountWithOpeningBalance(1, 2500.0)], [1 => '2026-08-01'], $seen);

		$this->assertSame(['2026-08-01', '2026-09-30'], $seen['range']);
	}

	public function testLiveForecastKeepsAMonthWhoseFirstDaysJustHadNoBookings(): void {
		// History from 28 September, two days into October's budget month
		$this->forecastOn30October(26, [$this->accountWithOpeningBalance(1, 2500.0)], [1 => '2026-09-28'], $seen);

		$this->assertSame(['2026-09-26', '2026-10-25'], $seen['range']);
	}

	public function testLiveForecastIsBoundedByTheLatestHistoryOfAnyAccount(): void {
		$this->forecastOn30October(1, [
			$this->accountWithOpeningBalance(1, 2500.0),
			$this->accountWithOpeningBalance(2, 300.0),
		], [1 => '2026-07-30', 2 => '2026-08-10'], $seen);

		$this->assertSame(['2026-09-01', '2026-09-30'], $seen['range']);
	}

	public function testLiveForecastIgnoresAccountsOpenedFromNothing(): void {
		// No opening balance: nothing before its first transaction is missing
		$this->forecastOn30October(1, [
			$this->accountWithOpeningBalance(1, 0.0),
			$this->accountWithOpeningBalance(2, 2500.0),
		], [1 => '2026-09-06', 2 => '2025-01-01'], $seen);

		$this->assertSame([2], $seen['firstDatesFor']);
		$this->assertSame(['2025-10-01', '2026-09-30'], $seen['range']);
	}

	public function testLiveForecastIgnoresAnAccountAddedAfterTheHistory(): void {
		// Added this month: it would otherwise leave no month at all
		$this->forecastOn30October(1, [
			$this->accountWithOpeningBalance(1, 2500.0),
			$this->accountWithOpeningBalance(2, 300.0),
		], [1 => '2025-01-01', 2 => '2026-10-12'], $seen);

		$this->assertSame(['2025-10-01', '2026-09-30'], $seen['range']);
	}
}
