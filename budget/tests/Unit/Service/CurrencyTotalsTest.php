<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\CurrencyTotals;
use PHPUnit\Framework\TestCase;

class CurrencyTotalsTest extends TestCase {
	private CurrencyConversionService $conversion;
	private CurrencyTotals $totals;

	protected function setUp(): void {
		$this->conversion = $this->createMock(CurrencyConversionService::class);
		$this->conversion->method('getBaseCurrency')->willReturn('GBP');
		$this->conversion->method('needsConversion')->willReturnCallback(
			static fn (array $accounts) => count(array_unique(array_map(static fn (Account $a) => $a->getCurrency(), $accounts))) > 1
		);
		$this->conversion->method('canConvert')->willReturnCallback(static fn (string $currency) => $currency !== 'XAU');
		$this->conversion->method('convertToBase')->willReturnCallback(
			static fn ($amount, string $currency) => ['EUR' => '0.8500000000', 'BTC' => '50000.0000000000'][$currency]
		);
		$this->totals = new CurrencyTotals($this->conversion);
	}

	private function account(int $id, string $currency): Account {
		$account = new Account();
		$account->setId($id);
		$account->setCurrency($currency);
		return $account;
	}

	public function testAccountsInOneCurrencyAreLeftAsTheyAre(): void {
		$result = $this->totals->accountRates([$this->account(1, 'EUR'), $this->account(2, 'EUR')], 'alice');

		$this->assertSame([1 => '1', 2 => '1'], $result['rates']);
		$this->assertNull($result['currency']);
		$this->assertSame([], $result['unconverted']);
	}

	public function testMixedCurrenciesConvertIntoTheBaseCurrency(): void {
		$result = $this->totals->accountRates([
			$this->account(1, 'GBP'),
			$this->account(2, 'EUR'),
			$this->account(3, 'BTC'),
			$this->account(4, 'XAU'),
			$this->account(5, 'EUR'),
		], 'alice');

		$this->assertSame([1 => '1', 2 => '0.8500000000', 3 => '50000.0000000000', 5 => '0.8500000000'], $result['rates']);
		$this->assertSame('GBP', $result['currency']);
		// An account with no rate is left out rather than added as pounds
		$this->assertSame(['XAU'], $result['unconverted']);
	}

	public function testAReportIsInTheCurrencyItsAccountsShareElseTheBaseCurrency(): void {
		$hidden = $this->account(9, 'USD');
		$hidden->setExcludedFromReports(true);
		$mapper = $this->createMock(\OCA\Budget\Db\AccountMapper::class);
		$mapper->method('findById')->willReturnCallback(fn (int $id) => $this->account($id, 'BTC'));
		$mapper->method('findByIds')->willReturnCallback(fn (array $ids) => array_map(
			fn (int $id) => $id === 9 ? $hidden : $this->account($id, $id === 3 ? 'EUR' : 'JPY'),
			$ids
		));
		$mapper->method('findAll')->willReturn([]);
		$totals = new CurrencyTotals($this->conversion, $mapper);

		$this->assertSame('BTC', $totals->reportCurrency('alice', 7, [1, 2]));
		// An account kept out of reports doesn't make the rest mixed
		$this->assertSame('JPY', $totals->reportCurrency('alice', null, [1, 2, 9]));
		$this->assertSame('GBP', $totals->reportCurrency('alice', null, [1, 3]));
		$this->assertSame('GBP', $totals->reportCurrency('alice', null, null));
		$this->assertNull((new CurrencyTotals($this->conversion))->reportCurrency('alice', null, null));
	}
}
