<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Report;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\Report\ReportAggregator;
use OCA\Budget\Service\Report\ReportCalculator;
use PHPUnit\Framework\TestCase;

class ReportAggregatorTest extends TestCase {
	private ReportAggregator $aggregator;
	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;
	private CategoryMapper $categoryMapper;
	private ReportCalculator $calculator;
	private CurrencyConversionService $conversionService;

	protected function setUp(): void {
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->calculator = $this->createMock(ReportCalculator::class);
		$this->conversionService = $this->createMock(CurrencyConversionService::class);

		$this->aggregator = new ReportAggregator(
			$this->accountMapper,
			$this->transactionMapper,
			$this->categoryMapper,
			$this->calculator,
			$this->conversionService
		);
	}

	private function makeAccount(int $id, string $name, string $type, float $balance, string $currency): Account {
		$account = new Account();
		$account->setId($id);
		$account->setName($name);
		$account->setType($type);
		$account->setBalance($balance);
		$account->setCurrency($currency);
		return $account;
	}

	private function setupDefaultMocks(): void {
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
		$this->transactionMapper->method('getSpendingSummary')->willReturn([]);
		$this->transactionMapper->method('getMonthlyTrendData')->willReturn([]);
	}

	// ===== Single currency (no conversion) =====

	public function testSingleCurrencyNoConversion(): void {
		$accounts = [
			$this->makeAccount(1, 'Checking', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'Savings', 'savings', 2000.00, 'GBP'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 500, 'expenses' => 200, 'count' => 10],
			2 => ['income' => 100, 'expenses' => 0, 'count' => 2],
		]);
		$this->transactionMapper->method('getTransferTotals')->willReturn(['income' => 0, 'expenses' => 0]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(false);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		$this->assertEquals(3000.00, $result['totals']['currentBalance']);
		$this->assertEquals(600, $result['totals']['totalIncome']);
		$this->assertEquals(200, $result['totals']['totalExpenses']);
		$this->assertEquals('GBP', $result['baseCurrency']);
		$this->assertFalse($result['currencyConverted']);
		$this->assertEmpty($result['unconvertedCurrencies']);
	}

	// ===== Multi-currency conversion =====

	public function testMultiCurrencyConversion(): void {
		$accounts = [
			$this->makeAccount(1, 'GBP Account', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'EUR Account', 'savings', 1200.00, 'EUR'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 500, 'expenses' => 200, 'count' => 5],
			2 => ['income' => 600, 'expenses' => 100, 'count' => 3],
		]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(true);
		$this->conversionService->method('getAccountCurrencyMap')->willReturn([1 => 'GBP', 2 => 'EUR']);

		// Mock conversion for EUR account values
		$this->conversionService->method('convertToBaseFloat')
			->willReturnCallback(function ($amount, $currency, $userId) {
				if ($currency === 'EUR') {
					return (float)$amount * 0.85; // EUR→GBP rate
				}
				return (float)$amount;
			});

		// Transfer deduction uses per-account method
		$this->transactionMapper->method('getTransferTotalsByAccount')->willReturn([]);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		// GBP: 1000 + EUR→GBP: 1200*0.85=1020 = 2020
		$this->assertEqualsWithDelta(2020.00, $result['totals']['currentBalance'], 0.01);
		// GBP income: 500 + EUR→GBP: 600*0.85=510 = 1010
		$this->assertEqualsWithDelta(1010.00, $result['totals']['totalIncome'], 0.01);
		// GBP expenses: 200 + EUR→GBP: 100*0.85=85 = 285
		$this->assertEqualsWithDelta(285.00, $result['totals']['totalExpenses'], 0.01);
		$this->assertTrue($result['currencyConverted']);
		$this->assertEquals('GBP', $result['baseCurrency']);
	}

	// ===== Transfer deduction with multi-currency =====

	public function testTransferDeductionMultiCurrency(): void {
		$accounts = [
			$this->makeAccount(1, 'GBP Account', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'EUR Account', 'savings', 1000.00, 'EUR'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 1000, 'expenses' => 500, 'count' => 10],
			2 => ['income' => 800, 'expenses' => 300, 'count' => 5],
		]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(true);
		$this->conversionService->method('getAccountCurrencyMap')->willReturn([1 => 'GBP', 2 => 'EUR']);

		$this->conversionService->method('convertToBaseFloat')
			->willReturnCallback(function ($amount, $currency, $userId) {
				if ($currency === 'EUR') {
					return (float)$amount * 0.85;
				}
				return (float)$amount;
			});

		// Transfer: 200 GBP out, 170 EUR in (cross-currency transfer)
		$this->transactionMapper->method('getTransferTotalsByAccount')->willReturn([
			1 => ['income' => 0, 'expenses' => 200],
			2 => ['income' => 170, 'expenses' => 0],
		]);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		// Income: GBP 1000 + EUR 800*0.85=680 = 1680, minus transfers: 0 + 170*0.85=144.5 = 1535.5
		$expectedIncome = 1000 + (800 * 0.85) - (0 + 170 * 0.85);
		$this->assertEqualsWithDelta($expectedIncome, $result['totals']['totalIncome'], 0.01);
	}

	// ===== Per-account data keeps native currency =====

	public function testPerAccountDataKeepsNativeCurrency(): void {
		$accounts = [
			$this->makeAccount(1, 'GBP Account', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'EUR Account', 'savings', 1200.00, 'EUR'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 500, 'expenses' => 200, 'count' => 5],
			2 => ['income' => 600, 'expenses' => 100, 'count' => 3],
		]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(true);
		$this->conversionService->method('getAccountCurrencyMap')->willReturn([1 => 'GBP', 2 => 'EUR']);
		$this->conversionService->method('convertToBaseFloat')->willReturnCallback(fn($a) => (float)$a);
		$this->transactionMapper->method('getTransferTotalsByAccount')->willReturn([]);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		// Per-account data should keep original currency and values
		$this->assertEquals('GBP', $result['accounts'][0]['currency']);
		$this->assertEquals(1000.00, $result['accounts'][0]['balance']);
		$this->assertEquals('EUR', $result['accounts'][1]['currency']);
		$this->assertEquals(1200.00, $result['accounts'][1]['balance']);
	}

	// ===== Single account view skips conversion =====

	public function testSingleAccountViewNoConversion(): void {
		$account = $this->makeAccount(1, 'EUR Account', 'checking', 1000.00, 'EUR');

		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([
			1 => ['income' => 300, 'expenses' => 100, 'count' => 5],
		]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		// needsConversion should NOT be called for single-account view
		$this->conversionService->expects($this->never())->method('needsConversion');

		$result = $this->aggregator->generateSummary('user1', 1, '2026-01-01', '2026-01-31');

		// Values should be in native EUR, not converted
		$this->assertEquals(1000.00, $result['totals']['currentBalance']);
		$this->assertFalse($result['currencyConverted']);
	}

	// ===== Metadata in response =====

	public function testResponseIncludesCurrencyMetadata(): void {
		$accounts = [
			$this->makeAccount(1, 'Account', 'checking', 100.00, 'USD'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getAccountSummaries')->willReturn([]);
		$this->transactionMapper->method('getTransferTotals')->willReturn(['income' => 0, 'expenses' => 0]);
		$this->setupDefaultMocks();

		$this->conversionService->method('getBaseCurrency')->willReturn('USD');
		$this->conversionService->method('needsConversion')->willReturn(false);

		$result = $this->aggregator->generateSummary('user1', null, '2026-01-01', '2026-01-31');

		$this->assertArrayHasKey('baseCurrency', $result);
		$this->assertArrayHasKey('currencyConverted', $result);
		$this->assertArrayHasKey('unconvertedCurrencies', $result);
		$this->assertEquals('USD', $result['baseCurrency']);
	}
}
