<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\ManualExchangeRate;
use OCA\Budget\Db\ManualExchangeRateMapper;
use OCA\Budget\Service\ExchangeRateService;
use OCA\Budget\Service\ManualExchangeRateService;
use OCA\Budget\Service\SettingService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class ManualExchangeRateServiceTest extends TestCase {
	private ManualExchangeRateService $service;
	private ManualExchangeRateMapper $mapper;
	private ExchangeRateService $exchangeRateService;
	private SettingService $settingService;

	protected function setUp(): void {
		$this->mapper = $this->createMock(ManualExchangeRateMapper::class);
		$this->exchangeRateService = $this->createMock(ExchangeRateService::class);
		$this->settingService = $this->createMock(SettingService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			foreach ($params as $i => $param) {
				$text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
			}
			return $text;
		});
		$this->service = new ManualExchangeRateService(
			$this->mapper,
			$this->exchangeRateService,
			$this->settingService,
			$l
		);
	}

	// ===== getAllForUser() =====

	public function testGetAllForUserDelegatesToMapper(): void {
		$rate1 = new ManualExchangeRate();
		$rate1->setCurrency('ARS');
		$rate2 = new ManualExchangeRate();
		$rate2->setCurrency('USD');

		$this->mapper->expects($this->once())
			->method('findAllByUser')
			->with('user1')
			->willReturn([$rate1, $rate2]);

		$result = $this->service->getAllForUser('user1');
		$this->assertCount(2, $result);
		$this->assertEquals('ARS', $result[0]->getCurrency());
		$this->assertEquals('USD', $result[1]->getCurrency());
	}

	public function testGetAllForUserReturnsEmptyArray(): void {
		$this->mapper->method('findAllByUser')->willReturn([]);
		$this->assertEmpty($this->service->getAllForUser('user1'));
	}

	// ===== setRate() =====

	public function testSetRateConvertsToEurAndCallsUpsert(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('GBP');

		// GBP rate per EUR = 0.85
		$this->exchangeRateService->method('getRateLocal')
			->with('GBP')
			->willReturn('0.8500000000');

		$expectedEntity = new ManualExchangeRate();
		$expectedEntity->setCurrency('ARS');

		// ratePerEur = 1200 * 0.85 = 1020
		$this->mapper->expects($this->once())
			->method('upsert')
			->with(
				'user1',
				'ARS',
				$this->callback(function ($rate) {
					return abs((float) $rate - 1020.0) < 0.01;
				})
			)
			->willReturn($expectedEntity);

		$result = $this->service->setRate('user1', 'ARS', '1200');
		$this->assertEquals('ARS', $result->getCurrency());
	}

	public function testSetRateWithEurBaseCurrencyStoresDirectly(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('EUR');

		$expectedEntity = new ManualExchangeRate();

		// When base is EUR, ratePerEur = ratePerBase directly
		$this->mapper->expects($this->once())
			->method('upsert')
			->with(
				'user1',
				'ARS',
				$this->callback(function ($rate) {
					return abs((float) $rate - 1200.0) < 0.01;
				})
			)
			->willReturn($expectedEntity);

		$this->service->setRate('user1', 'ARS', '1200');
	}

	public function testSetRateNormalizesCurrencyToUppercase(): void {
		$this->settingService->method('get')->willReturn('EUR');

		$expectedEntity = new ManualExchangeRate();
		$this->mapper->expects($this->once())
			->method('upsert')
			->with('user1', 'ARS', $this->anything())
			->willReturn($expectedEntity);

		$this->service->setRate('user1', 'ars', '1200');
	}

	// ===== setRate() validation =====

	public function testSetRateRejectsInvalidCurrency(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid currency code');

		$this->service->setRate('user1', 'INVALID', '100');
	}

	public function testSetRateRejectsEur(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot set a manual rate for EUR');

		$this->service->setRate('user1', 'EUR', '1.0');
	}

	public function testSetRateRejectsBaseCurrency(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('GBP');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot set a manual rate for your base currency');

		$this->service->setRate('user1', 'GBP', '1.0');
	}

	public function testSetRateRejectsNegativeRate(): void {
		$this->settingService->method('get')->willReturn('GBP');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Rate must be a positive number');

		$this->service->setRate('user1', 'USD', '-5');
	}

	public function testSetRateRejectsZeroRate(): void {
		$this->settingService->method('get')->willReturn('GBP');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Rate must be a positive number');

		$this->service->setRate('user1', 'USD', '0');
	}

	public function testSetRateRejectsNonNumericRate(): void {
		$this->settingService->method('get')->willReturn('GBP');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Rate must be a positive number');

		$this->service->setRate('user1', 'USD', 'abc');
	}

	public function testSetRateThrowsWhenBaseRateUnavailable(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('GBP');

		$this->exchangeRateService->method('getRateLocal')
			->with('GBP')
			->willReturn(null);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('no exchange rate available for base currency');

		$this->service->setRate('user1', 'ARS', '1200');
	}

	// ===== removeRate() =====

	public function testRemoveRateDelegatesToMapper(): void {
		$this->mapper->expects($this->once())
			->method('deleteByUserAndCurrency')
			->with('user1', 'ARS');

		$this->service->removeRate('user1', 'ARS');
	}

	public function testRemoveRateNormalizesToUppercase(): void {
		$this->mapper->expects($this->once())
			->method('deleteByUserAndCurrency')
			->with('user1', 'ARS');

		$this->service->removeRate('user1', 'ars');
	}
}
