<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Service\ManualExchangeRateService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * CurrencyConversionService memoizes the base currency and manual rates
 * (T6-7). With the real services, in one process (a request, or a cron run),
 * a change made through the app is seen by the next conversion.
 */
class CurrencyMemoTest extends IntegrationTestCase {
	public function testAChangedBaseCurrencyOrManualRateIsUsedRightAway(): void {
		$conversion = $this->service(CurrencyConversionService::class);
		$settings = $this->service(SettingService::class);
		$rates = $this->service(ManualExchangeRateService::class);

		$settings->set($this->userId, 'default_currency', 'EUR');
		$this->assertSame('EUR', $conversion->getBaseCurrency($this->userId));

		// 1 EUR = 2 USD by the user's own rate: 10 USD is 5 EUR
		$rates->setRate($this->userId, 'USD', '2');
		$this->assertEqualsWithDelta(5.0, $conversion->convertToBaseFloat('10', 'USD', $this->userId), 0.0001);

		$rates->setRate($this->userId, 'USD', '4');
		$this->assertEqualsWithDelta(2.5, $conversion->convertToBaseFloat('10', 'USD', $this->userId), 0.0001);

		$settings->set($this->userId, 'default_currency', 'USD');
		$this->assertSame('USD', $conversion->getBaseCurrency($this->userId));
		$this->assertSame('10', $conversion->convertToBase('10', 'usd', $this->userId));

		// A reset clears both through other paths than the services above
		$this->service(FactoryResetService::class)->executeFactoryReset($this->userId);
		$this->assertSame('GBP', $conversion->getBaseCurrency($this->userId));
		$this->assertSame([], $rates->getAllForUser($this->userId));
	}
}
