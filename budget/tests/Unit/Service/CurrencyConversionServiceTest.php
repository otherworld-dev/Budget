<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\ExchangeRateService;
use OCA\Budget\Service\SettingService;
use PHPUnit\Framework\TestCase;

class CurrencyConversionServiceTest extends TestCase {
	private CurrencyConversionService $service;
	private ExchangeRateService $exchangeRateService;
	private SettingService $settingService;

	protected function setUp(): void {
		$this->exchangeRateService = $this->createMock(ExchangeRateService::class);
		$this->settingService = $this->createMock(SettingService::class);
		$this->service = new CurrencyConversionService(
			$this->exchangeRateService,
			$this->settingService
		);
	}

	// ===== convert() =====

	public function testConvertSameCurrencyReturnsSameAmount(): void {
		$this->exchangeRateService->expects($this->never())->method('getRate');

		$result = $this->service->convert('100.00', 'USD', 'USD');
		$this->assertEquals('100.00', $result);
	}

	public function testConvertSameCurrencyCaseInsensitive(): void {
		$this->exchangeRateService->expects($this->never())->method('getRate');

		$result = $this->service->convert('50.00', 'usd', 'USD');
		$this->assertEquals('50.00', $result);
	}

	public function testConvertUsdToGbp(): void {
		$this->exchangeRateService->method('getRate')
			->willReturnMap([
				['USD', null, '1.0800000000'],
				['GBP', null, '0.8500000000'],
			]);

		$result = $this->service->convert('100', 'USD', 'GBP');
		$resultFloat = round((float) $result, 2);
		$this->assertEqualsWithDelta(78.70, $resultFloat, 0.01);
	}

	public function testConvertGbpToEur(): void {
		$this->exchangeRateService->method('getRate')
			->willReturnMap([
				['GBP', null, '0.8500000000'],
				['EUR', null, '1.0000000000'],
			]);

		$result = $this->service->convert('100', 'GBP', 'EUR');
		$resultFloat = round((float) $result, 2);
		$this->assertEqualsWithDelta(117.65, $resultFloat, 0.01);
	}

	public function testConvertWithHistoricalDate(): void {
		$date = '2025-06-15';
		$this->exchangeRateService->method('getRate')
			->willReturnMap([
				['USD', $date, '1.1000000000'],
				['GBP', $date, '0.8600000000'],
			]);

		$result = $this->service->convert('200', 'USD', 'GBP', $date);
		$resultFloat = round((float) $result, 2);
		$this->assertEqualsWithDelta(156.36, $resultFloat, 0.01);
	}

	public function testConvertReturnsUnchangedWhenSourceRateUnavailable(): void {
		$this->exchangeRateService->method('getRate')
			->willReturnMap([
				['XYZ', null, null],
				['GBP', null, '0.8500000000'],
			]);

		$result = $this->service->convert('100', 'XYZ', 'GBP');
		$this->assertEquals('100', $result);
	}

	public function testConvertReturnsUnchangedWhenTargetRateUnavailable(): void {
		$this->exchangeRateService->method('getRate')
			->willReturnMap([
				['USD', null, '1.0800000000'],
				['XYZ', null, null],
			]);

		$result = $this->service->convert('100', 'USD', 'XYZ');
		$this->assertEquals('100', $result);
	}

	public function testConvertAcceptsFloatInput(): void {
		$this->exchangeRateService->method('getRate')
			->willReturnMap([
				['USD', null, '1.0800000000'],
				['GBP', null, '0.8500000000'],
			]);

		$result = $this->service->convert(100.50, 'USD', 'GBP');
		$resultFloat = (float) $result;
		$this->assertGreaterThan(0, $resultFloat);
	}

	// ===== convertToBase() =====

	public function testConvertToBaseUsesUserDefaultCurrency(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('GBP');

		$this->exchangeRateService->method('getRateLocal')
			->willReturnMap([
				['USD', null, '1.0800000000'],
				['GBP', null, '0.8500000000'],
			]);

		$result = $this->service->convertToBase('100', 'USD', 'user1');
		$resultFloat = round((float) $result, 2);
		$this->assertEqualsWithDelta(78.70, $resultFloat, 0.01);
	}

	public function testConvertToBaseDefaultsToGbpWhenNoSetting(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn(null);

		$this->exchangeRateService->method('getRateLocal')
			->willReturnMap([
				['USD', null, '1.0800000000'],
				['GBP', null, '0.8500000000'],
			]);

		$result = $this->service->convertToBase('100', 'USD', 'user1');
		$resultFloat = round((float) $result, 2);
		// USD→GBP: 100 * (0.85/1.08) ≈ 78.70
		$this->assertEqualsWithDelta(78.70, $resultFloat, 0.01);
	}

	// ===== convertToBaseFloat() =====

	public function testConvertToBaseFloatReturnsFloat(): void {
		$this->settingService->method('get')
			->willReturn('GBP');

		$this->exchangeRateService->method('getRateLocal')
			->willReturnMap([
				['USD', null, '1.0800000000'],
				['GBP', null, '0.8500000000'],
			]);

		$result = $this->service->convertToBaseFloat('100', 'USD', 'user1');
		$this->assertIsFloat($result);
		$this->assertEqualsWithDelta(78.70, $result, 0.01);
	}

	// ===== getBaseCurrency() =====

	public function testGetBaseCurrencyReturnsSetting(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('EUR');

		$this->assertEquals('EUR', $this->service->getBaseCurrency('user1'));
	}

	public function testGetBaseCurrencyDefaultsToGbp(): void {
		$this->settingService->method('get')
			->willReturn(null);

		$this->assertEquals('GBP', $this->service->getBaseCurrency('user1'));
	}

	// ===== needsConversion() =====

	public function testNeedsConversionReturnsFalseForSingleCurrency(): void {
		$this->assertFalse($this->service->needsConversion([
			$this->makeAccount('USD', 1),
			$this->makeAccount('USD', 2),
		]));
	}

	public function testNeedsConversionReturnsTrueForMixedCurrencies(): void {
		$this->assertTrue($this->service->needsConversion([
			$this->makeAccount('USD', 1),
			$this->makeAccount('GBP', 2),
		]));
	}

	public function testNeedsConversionReturnsFalseForEmptyArray(): void {
		$this->assertFalse($this->service->needsConversion([]));
	}

	public function testNeedsConversionReturnsFalseForSingleAccount(): void {
		$this->assertFalse($this->service->needsConversion([
			$this->makeAccount('EUR', 1),
		]));
	}

	public function testNeedsConversionDefaultsNullCurrencyToUsd(): void {
		$this->assertFalse($this->service->needsConversion([
			$this->makeAccount(null, 1),
			$this->makeAccount('USD', 2),
		]));
	}

	public function testNeedsConversionNullAndDifferentCurrency(): void {
		$this->assertTrue($this->service->needsConversion([
			$this->makeAccount(null, 1),
			$this->makeAccount('EUR', 2),
		]));
	}

	// ===== getAccountCurrencyMap() =====

	public function testGetAccountCurrencyMap(): void {
		$map = $this->service->getAccountCurrencyMap([
			$this->makeAccount('USD', 1),
			$this->makeAccount('GBP', 2),
			$this->makeAccount(null, 3),
		]);

		$this->assertEquals('USD', $map[1]);
		$this->assertEquals('GBP', $map[2]);
		$this->assertEquals('USD', $map[3]);
	}

	// ===== accountNeedsConversion() =====

	public function testAccountNeedsConversionReturnsTrueForDifferentCurrency(): void {
		$this->settingService->method('get')->willReturn('GBP');
		$this->assertTrue($this->service->accountNeedsConversion('USD', 'user1'));
	}

	public function testAccountNeedsConversionReturnsFalseForSameCurrency(): void {
		$this->settingService->method('get')->willReturn('GBP');
		$this->assertFalse($this->service->accountNeedsConversion('GBP', 'user1'));
	}

	public function testAccountNeedsConversionCaseInsensitive(): void {
		$this->settingService->method('get')->willReturn('GBP');
		$this->assertFalse($this->service->accountNeedsConversion('gbp', 'user1'));
	}

	// ===== Helpers =====

	private function makeAccount(?string $currency, int $id): Account {
		$account = new Account();
		$account->setId($id);
		if ($currency !== null) {
			$account->setCurrency($currency);
		}
		return $account;
	}
}
