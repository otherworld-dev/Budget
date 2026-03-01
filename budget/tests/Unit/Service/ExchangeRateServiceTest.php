<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\ExchangeRate;
use OCA\Budget\Db\ExchangeRateMapper;
use OCA\Budget\Service\ExchangeRateService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExchangeRateServiceTest extends TestCase {
	private ExchangeRateService $service;
	private ExchangeRateMapper $mapper;
	private IClientService $clientService;
	private IClient $client;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->mapper = $this->createMock(ExchangeRateMapper::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->clientService->method('newClient')->willReturn($this->client);

		$this->service = new ExchangeRateService(
			$this->mapper,
			$this->clientService,
			$this->logger
		);
	}

	// ===== getRate() =====

	public function testGetRateForEurAlwaysReturnsOne(): void {
		$this->mapper->expects($this->never())->method('findByDate');

		$rate = $this->service->getRate('EUR');
		$this->assertEquals('1.0000000000', $rate);
	}

	public function testGetRateForEurCaseInsensitive(): void {
		$rate = $this->service->getRate('eur');
		$this->assertEquals('1.0000000000', $rate);
	}

	public function testGetRateReturnsFromDbExactDate(): void {
		$entity = $this->makeRateEntity('USD', '1.0800000000', '2025-06-15');

		$this->mapper->method('findByDate')
			->with('USD', '2025-06-15')
			->willReturn($entity);

		$rate = $this->service->getRate('USD', '2025-06-15');
		$this->assertEquals('1.0800000000', $rate);
	}

	public function testGetRateFallsBackToClosest(): void {
		$this->mapper->method('findByDate')->willReturn(null);

		$closestEntity = $this->makeRateEntity('USD', '1.0900000000', '2025-06-14');
		$this->mapper->method('findClosest')
			->with('USD', '2025-06-15')
			->willReturn($closestEntity);

		$rate = $this->service->getRate('USD', '2025-06-15');
		$this->assertEquals('1.0900000000', $rate);
	}

	public function testGetRateFallsBackToLatest(): void {
		$this->mapper->method('findByDate')->willReturn(null);
		$this->mapper->method('findClosest')->willReturn(null);

		$latestEntity = $this->makeRateEntity('USD', '1.1000000000', '2025-01-01');
		$this->mapper->method('findLatest')
			->with('USD')
			->willReturn($latestEntity);

		$rate = $this->service->getRate('USD', '2025-06-15');
		$this->assertEquals('1.1000000000', $rate);
	}

	public function testGetRateReturnsNullWhenNoRateExists(): void {
		$this->mapper->method('findByDate')->willReturn(null);
		$this->mapper->method('findClosest')->willReturn(null);
		$this->mapper->method('findLatest')->willReturn(null);

		$rate = $this->service->getRate('USD', '2025-06-15');
		$this->assertNull($rate);
	}

	public function testGetRateUsesInMemoryCache(): void {
		$entity = $this->makeRateEntity('USD', '1.0800000000', '2025-06-15');

		// findByDate should only be called once due to caching
		$this->mapper->expects($this->once())
			->method('findByDate')
			->with('USD', '2025-06-15')
			->willReturn($entity);

		$rate1 = $this->service->getRate('USD', '2025-06-15');
		$rate2 = $this->service->getRate('USD', '2025-06-15');

		$this->assertEquals($rate1, $rate2);
	}

	// ===== getLatestRate() =====

	public function testGetLatestRateForEur(): void {
		$rate = $this->service->getLatestRate('EUR');
		$this->assertEquals('1.0000000000', $rate);
	}

	public function testGetLatestRateReturnsFromMapper(): void {
		$entity = $this->makeRateEntity('GBP', '0.8500000000', '2025-06-15');
		$this->mapper->method('findLatest')
			->with('GBP')
			->willReturn($entity);

		$rate = $this->service->getLatestRate('GBP');
		$this->assertEquals('0.8500000000', $rate);
	}

	public function testGetLatestRateReturnsNullWhenNotFound(): void {
		$this->mapper->method('findLatest')->willReturn(null);
		$this->assertNull($this->service->getLatestRate('XYZ'));
	}

	// ===== getRates() =====

	public function testGetRatesBulkLoad(): void {
		$usdEntity = $this->makeRateEntity('USD', '1.0800000000', '2025-06-15');
		$gbpEntity = $this->makeRateEntity('GBP', '0.8500000000', '2025-06-15');

		$this->mapper->method('findByDate')
			->willReturnCallback(function ($currency, $date) use ($usdEntity, $gbpEntity) {
				if ($currency === 'USD') {
					return $usdEntity;
				}
				if ($currency === 'GBP') {
					return $gbpEntity;
				}
				return null;
			});

		$rates = $this->service->getRates(['USD', 'GBP', 'EUR'], '2025-06-15');

		$this->assertEquals('1.0000000000', $rates['EUR']);
		$this->assertEquals('1.0800000000', $rates['USD']);
		$this->assertEquals('0.8500000000', $rates['GBP']);
	}

	// ===== fetchEcbRates() =====

	public function testFetchEcbRatesParsesXml(): void {
		$ecbXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"
                 xmlns="http://www.ecb.int/vocabulary/2002-08-01/euref">
    <gesmes:subject>Reference rates</gesmes:subject>
    <Cube>
        <Cube time="2025-06-15">
            <Cube currency="USD" rate="1.0800"/>
            <Cube currency="GBP" rate="0.8500"/>
            <Cube currency="JPY" rate="162.50"/>
        </Cube>
    </Cube>
</gesmes:Envelope>
XML;

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($ecbXml);
		$this->client->method('get')->willReturn($response);

		$upsertedRates = [];
		$this->mapper->method('upsert')
			->willReturnCallback(function ($currency, $rate, $date, $source) use (&$upsertedRates) {
				$upsertedRates[$currency] = [
					'rate' => $rate,
					'date' => $date,
					'source' => $source,
				];
				return $this->makeRateEntity($currency, $rate, $date);
			});

		$this->service->fetchEcbRates();

		$this->assertArrayHasKey('USD', $upsertedRates);
		$this->assertArrayHasKey('GBP', $upsertedRates);
		$this->assertArrayHasKey('JPY', $upsertedRates);
		$this->assertEquals('1.0800', $upsertedRates['USD']['rate']);
		$this->assertEquals('0.8500', $upsertedRates['GBP']['rate']);
		$this->assertEquals('2025-06-15', $upsertedRates['USD']['date']);
		$this->assertEquals(ExchangeRate::SOURCE_ECB, $upsertedRates['USD']['source']);
	}

	public function testFetchEcbRatesHandlesNetworkError(): void {
		$this->client->method('get')
			->willThrowException(new \Exception('Network error'));

		$this->logger->expects($this->once())->method('error');
		$this->service->fetchEcbRates();
	}

	// ===== fetchCoinGeckoRates() =====

	public function testFetchCoinGeckoRatesParsesResponse(): void {
		$coinGeckoResponse = json_encode([
			'bitcoin' => ['eur' => 50000.00],
			'ethereum' => ['eur' => 2500.00],
		]);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($coinGeckoResponse);
		$this->client->method('get')->willReturn($response);

		$upsertedRates = [];
		$this->mapper->method('upsert')
			->willReturnCallback(function ($currency, $rate, $date, $source) use (&$upsertedRates) {
				$upsertedRates[$currency] = [
					'rate' => $rate,
					'source' => $source,
				];
				return $this->makeRateEntity($currency, $rate, $date);
			});

		$this->service->fetchCoinGeckoRates();

		$this->assertArrayHasKey('BTC', $upsertedRates);
		$this->assertArrayHasKey('ETH', $upsertedRates);
		$this->assertEquals(ExchangeRate::SOURCE_COINGECKO, $upsertedRates['BTC']['source']);

		// BTC: 1 / 50000 = 0.00002 BTC per 1 EUR
		$btcRate = (float) $upsertedRates['BTC']['rate'];
		$this->assertEqualsWithDelta(0.00002, $btcRate, 0.000001);

		// ETH: 1 / 2500 = 0.0004 ETH per 1 EUR
		$ethRate = (float) $upsertedRates['ETH']['rate'];
		$this->assertEqualsWithDelta(0.0004, $ethRate, 0.00001);
	}

	public function testFetchCoinGeckoRatesSkipsZeroPrice(): void {
		$coinGeckoResponse = json_encode([
			'bitcoin' => ['eur' => 0],
			'ethereum' => ['eur' => 2500.00],
		]);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($coinGeckoResponse);
		$this->client->method('get')->willReturn($response);

		$this->mapper->expects($this->once())
			->method('upsert')
			->with('ETH', $this->anything(), $this->anything(), ExchangeRate::SOURCE_COINGECKO)
			->willReturnCallback(function ($currency, $rate, $date) {
				return $this->makeRateEntity($currency, $rate, $date);
			});

		$this->service->fetchCoinGeckoRates();
	}

	public function testFetchCoinGeckoRatesHandlesNetworkError(): void {
		$this->client->method('get')
			->willThrowException(new \Exception('Network error'));

		$this->logger->expects($this->once())->method('error');
		$this->service->fetchCoinGeckoRates();
	}

	// ===== getCoinGeckoId() =====

	public function testGetCoinGeckoIdForKnownCurrencies(): void {
		$this->assertEquals('bitcoin', ExchangeRateService::getCoinGeckoId('BTC'));
		$this->assertEquals('ethereum', ExchangeRateService::getCoinGeckoId('ETH'));
		$this->assertEquals('ripple', ExchangeRateService::getCoinGeckoId('XRP'));
		$this->assertEquals('tether', ExchangeRateService::getCoinGeckoId('USDT'));
	}

	public function testGetCoinGeckoIdCaseInsensitive(): void {
		$this->assertEquals('bitcoin', ExchangeRateService::getCoinGeckoId('btc'));
	}

	public function testGetCoinGeckoIdReturnsNullForUnknown(): void {
		$this->assertNull(ExchangeRateService::getCoinGeckoId('NOTACOIN'));
	}

	// ===== Helpers =====

	private function makeRateEntity(string $currency, string $rate, string $date): ExchangeRate {
		$entity = new ExchangeRate();
		$entity->setCurrency($currency);
		$entity->setRatePerEur($rate);
		$entity->setDate($date);
		return $entity;
	}
}
