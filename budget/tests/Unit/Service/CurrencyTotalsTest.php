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
		// The rate for one unit; the base currency converts to itself
		$this->conversion->method('convertToBase')->willReturnCallback(
			static fn ($amount, string $currency) => ['EUR' => '0.8500000000', 'BTC' => '50000.0000000000'][$currency] ?? '1'
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

	/**
	 * Accounts 1 (GBP) and 2 (EUR); the query answers per account set.
	 */
	private function twoCurrencies(): CurrencyTotals {
		$mapper = $this->createMock(\OCA\Budget\Db\AccountMapper::class);
		$mapper->method('findByIds')->willReturn([$this->account(1, 'GBP'), $this->account(2, 'EUR')]);
		return new CurrencyTotals($this->conversion, $mapper);
	}

	public function testAmountsAreConvertedPerCurrencyAndAddedKeyByKey(): void {
		$asked = [];
		$query = function (?array $ids) use (&$asked): array {
			$asked[] = $ids;
			return $ids === [1]
				? [5 => ['2026-01' => '100.00'], 6 => ['2026-01' => '1.00']]
				: [5 => ['2026-01' => '100.00', '2026-02' => '-20.00']];
		};

		$result = $this->twoCurrencies()->amountsInBase('alice', [1, 2], $query);

		$this->assertSame([[1], [2]], $asked);
		$this->assertSame([5 => ['2026-01' => 185.0, '2026-02' => -17.0], 6 => ['2026-01' => 1.0]], $result);
	}

	public function testRowsAreConvertedAndMergedOnTheirKeys(): void {
		$query = static fn (?array $ids): array => $ids === [1]
			? [['id' => 5, 'name' => 'Food', 'total' => '100.00', 'count' => 2], ['id' => null, 'total' => '3.00', 'count' => 1]]
			: [['id' => 5, 'name' => 'Food', 'total' => '40.00', 'count' => 1], ['id' => null, 'total' => '2.00', 'count' => 1]];

		$merged = $this->twoCurrencies()->rowsInBase('alice', [1, 2], $query, ['id'], ['total'], ['count']);
		$kept = $this->twoCurrencies()->rowsInBase('alice', [1, 2], $query, null, ['total']);

		$this->assertSame([
			['id' => 5, 'name' => 'Food', 'total' => 134.0, 'count' => 3],
			['id' => null, 'total' => 4.7, 'count' => 2],
		], $merged);
		$this->assertSame([100.0, 3.0, 34.0, 1.7], array_column($kept, 'total'));
	}

	public function testOneCurrencyRunsTheQueryOnceAsGiven(): void {
		$mapper = $this->createMock(\OCA\Budget\Db\AccountMapper::class);
		$mapper->method('findAll')->willReturn([$this->account(1, 'EUR'), $this->account(2, 'EUR')]);
		$asked = [];
		$query = function (?array $ids) use (&$asked): array {
			$asked[] = $ids;
			return [5 => '12.345'];
		};

		$result = (new CurrencyTotals($this->conversion, $mapper))->amountsInBase('alice', null, $query);

		$this->assertSame([null], $asked);
		$this->assertSame([5 => '12.345'], $result);
	}
}
