<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Forecast\ForecastProjector;
use OCA\Budget\Service\Forecast\PatternAnalyzer;
use OCA\Budget\Service\Forecast\ScenarioBuilder;
use OCA\Budget\Service\Forecast\TrendCalculator;
use OCA\Budget\Service\ForecastService;
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

    // ===== invalidateCache =====

    public function testInvalidateCacheRemovesEntries(): void {
        $this->cache->expects($this->exactly(2))->method('remove');

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
        $this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([1 => 500.0]);
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
        $transactions = array_fill(0, 15, null); // 15 dummy transactions

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
}
