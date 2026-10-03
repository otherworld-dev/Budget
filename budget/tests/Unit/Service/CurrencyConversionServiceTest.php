<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\ManualExchangeRate;
use OCA\Budget\Db\ManualExchangeRateMapper;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\ExchangeRateService;
use OCA\Budget\Service\SettingService;
use PHPUnit\Framework\TestCase;

class CurrencyConversionServiceTest extends TestCase {
	private CurrencyConversionService $service;
	private ExchangeRateService $exchangeRateService;
	private SettingService $settingService;
	private ManualExchangeRateMapper $manualRateMapper;

	protected function setUp(): void {
		$this->exchangeRateService = $this->createMock(ExchangeRateService::class);
		$this->settingService = $this->createMock(SettingService::class);
		$this->manualRateMapper = $this->createMock(ManualExchangeRateMapper::class);
		$this->service = new CurrencyConversionService(
			$this->exchangeRateService,
			$this->settingService,
			$this->manualRateMapper
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
		$resultFloat = round((float)$result, 2);
		$this->assertEqualsWithDelta(78.70, $resultFloat, 0.01);
	}

	public function testConvertGbpToEur(): void {
		$this->exchangeRateService->method('getRate')
			->willReturnMap([
				['GBP', null, '0.8500000000'],
				['EUR', null, '1.0000000000'],
			]);

		$result = $this->service->convert('100', 'GBP', 'EUR');
		$resultFloat = round((float)$result, 2);
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
		$resultFloat = round((float)$result, 2);
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
		$resultFloat = (float)$result;
		$this->assertGreaterThan(0, $resultFloat);
	}

	// ===== convertLocal() does NOT use manual rates =====

	public function testConvertLocalDoesNotCheckManualRates(): void {
		$this->manualRateMapper->expects($this->never())->method('findByUserAndCurrency');

		$this->exchangeRateService->method('getRateLocal')
			->willReturnMap([
				['USD', null, '1.0800000000'],
				['GBP', null, '0.8500000000'],
			]);

		$result = $this->service->convertLocal('100', 'USD', 'GBP');
		$this->assertGreaterThan(0, (float)$result);
	}

	// ===== convertToBase() with manual rates =====

	public function testConvertToBaseUsesManualRateWhenAvailable(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('GBP');

		// Manual rate set for ARS
		$manualRate = new ManualExchangeRate();
		$manualRate->setRatePerEur('1048.9000000000');

		$this->manualRateMapper->method('findByUserAndCurrency')
			->willReturnCallback(function ($userId, $currency) use ($manualRate) {
				if ($userId === 'user1' && $currency === 'ARS') {
					return $manualRate;
				}
				return null;
			});

		// Auto rate for GBP (base currency)
		$this->exchangeRateService->method('getRateLocal')
			->willReturnMap([
				['GBP', null, '0.8500000000'],
			]);

		$result = $this->service->convertToBase('1000', 'ARS', 'user1');
		$resultFloat = round((float)$result, 2);
		// ARS→GBP: 1000 * (0.85 / 1048.9) ≈ 0.81
		$this->assertEqualsWithDelta(0.81, $resultFloat, 0.01);
	}

	/**
	 * A crypto balance holding dust reaches here as a float; (string) wrote it
	 * as "1.0E-5" and bcmul() threw a ValueError, so the account page and the
	 * accounts list failed for a BTC account worth almost nothing.
	 */
	public function testConvertToBaseAcceptsDust(): void {
		$this->settingService->method('get')->with('user1', 'default_currency')->willReturn('GBP');
		$this->manualRateMapper->method('findByUserAndCurrency')->willReturn(null);
		$this->exchangeRateService->method('getRateLocal')
			->willReturnMap([
				['BTC', null, '0.0000100000'],
				['GBP', null, '0.8500000000'],
			]);

		$this->assertEqualsWithDelta(0.85, (float)$this->service->convertToBase(0.00001, 'BTC', 'user1'), 0.0001);
		$this->assertSame('0.00001', $this->service->convert(0.00001, 'BTC', 'BTC'));
	}

	public function testConvertToBaseFallsBackToAutoWhenNoManualRate(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('GBP');

		// No manual rates
		$this->manualRateMapper->method('findByUserAndCurrency')->willReturn(null);

		$this->exchangeRateService->method('getRateLocal')
			->willReturnMap([
				['USD', null, '1.0800000000'],
				['GBP', null, '0.8500000000'],
			]);

		$result = $this->service->convertToBase('100', 'USD', 'user1');
		$resultFloat = round((float)$result, 2);
		$this->assertEqualsWithDelta(78.70, $resultFloat, 0.01);
	}

	public function testConvertToBaseManualRateForBaseCurrency(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('GBP');

		// Manual rate for GBP (base currency) and auto for USD
		$manualGbp = new ManualExchangeRate();
		$manualGbp->setRatePerEur('0.9000000000');

		$this->manualRateMapper->method('findByUserAndCurrency')
			->willReturnCallback(function ($userId, $currency) use ($manualGbp) {
				if ($currency === 'GBP') {
					return $manualGbp;
				}
				return null;
			});

		$this->exchangeRateService->method('getRateLocal')
			->willReturnMap([
				['USD', null, '1.0800000000'],
			]);

		$result = $this->service->convertToBase('100', 'USD', 'user1');
		$resultFloat = round((float)$result, 2);
		// USD→GBP: 100 * (0.90 / 1.08) ≈ 83.33
		$this->assertEqualsWithDelta(83.33, $resultFloat, 0.01);
	}

	public function testConvertToBaseGracefulDegradationNoRates(): void {
		$this->settingService->method('get')->willReturn('GBP');
		$this->manualRateMapper->method('findByUserAndCurrency')->willReturn(null);
		$this->exchangeRateService->method('getRateLocal')->willReturn(null);

		$result = $this->service->convertToBase('100', 'XYZ', 'user1');
		$this->assertEquals('100', $result);
	}

	public function testConvertToBaseUsesUserDefaultCurrency(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('GBP');

		$this->manualRateMapper->method('findByUserAndCurrency')->willReturn(null);

		$this->exchangeRateService->method('getRateLocal')
			->willReturnMap([
				['USD', null, '1.0800000000'],
				['GBP', null, '0.8500000000'],
			]);

		$result = $this->service->convertToBase('100', 'USD', 'user1');
		$resultFloat = round((float)$result, 2);
		$this->assertEqualsWithDelta(78.70, $resultFloat, 0.01);
	}

	public function testConvertToBaseDefaultsToGbpWhenNoSetting(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn(null);

		$this->manualRateMapper->method('findByUserAndCurrency')->willReturn(null);

		$this->exchangeRateService->method('getRateLocal')
			->willReturnMap([
				['USD', null, '1.0800000000'],
				['GBP', null, '0.8500000000'],
			]);

		$result = $this->service->convertToBase('100', 'USD', 'user1');
		$resultFloat = round((float)$result, 2);
		// USD→GBP: 100 * (0.85/1.08) ≈ 78.70
		$this->assertEqualsWithDelta(78.70, $resultFloat, 0.01);
	}

	// ===== convertToBaseFloat() =====

	public function testConvertToBaseFloatReturnsFloat(): void {
		$this->settingService->method('get')
			->willReturn('GBP');

		$this->manualRateMapper->method('findByUserAndCurrency')->willReturn(null);

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

	/**
	 * Between two currencies neither of which is the base one, at the
	 * user's own rate where they set one. Unlike convert(), a missing rate
	 * is no answer rather than the amount unchanged.
	 */
	public function testConvertBetweenUsesTheUsersRatesAndSaysWhenItCannot(): void {
		$manual = new ManualExchangeRate();
		$manual->setRatePerEur('1.2000000000');
		$this->manualRateMapper->method('findByUserAndCurrency')
			->willReturnCallback(fn ($userId, $currency) => $currency === 'USD' ? $manual : null);
		$this->exchangeRateService->method('getRateLocal')
			->willReturnCallback(fn (string $currency) => $currency === 'GBP' ? '0.8000000000' : null);

		// 100 GBP = 125 EUR = 150 USD at the user's rate
		$this->assertEqualsWithDelta(150.0, (float)$this->service->convertBetween(100.0, 'GBP', 'USD', 'user1'), 0.0001);
		$this->assertSame('100', $this->service->convertBetween(100.0, 'GBP', 'gbp', 'user1'));
		$this->assertNull($this->service->convertBetween(100.0, 'GBP', 'JPY', 'user1'));
	}

	private function makeAccount(?string $currency, int $id): Account {
		$account = new Account();
		$account->setId($id);
		if ($currency !== null) {
			$account->setCurrency($currency);
		}
		return $account;
	}
}
