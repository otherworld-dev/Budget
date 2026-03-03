<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\NetWorthSnapshotMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AssetService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\NetWorthService;
use PHPUnit\Framework\TestCase;

class NetWorthServiceTest extends TestCase {
	private NetWorthService $service;
	private NetWorthSnapshotMapper $snapshotMapper;
	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;
	private CurrencyConversionService $conversionService;
	private AssetService $assetService;

	protected function setUp(): void {
		$this->snapshotMapper = $this->createMock(NetWorthSnapshotMapper::class);
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->conversionService = $this->createMock(CurrencyConversionService::class);
		$this->assetService = $this->createMock(AssetService::class);
		$this->assetService->method('getSummary')->willReturn(['totalAssetWorth' => 0]);

		$this->service = new NetWorthService(
			$this->snapshotMapper,
			$this->accountMapper,
			$this->transactionMapper,
			$this->conversionService,
			$this->assetService
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

	// ===== Single currency (no conversion) =====

	public function testSingleCurrencyNoConversion(): void {
		$accounts = [
			$this->makeAccount(1, 'Checking', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'Savings', 'savings', 5000.00, 'GBP'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(false);

		$result = $this->service->calculateNetWorth('user1');

		$this->assertEquals(6000.00, $result['totalAssets']);
		$this->assertEquals(0.00, $result['totalLiabilities']);
		$this->assertEquals(6000.00, $result['netWorth']);
		$this->assertEquals('GBP', $result['baseCurrency']);
		$this->assertEmpty($result['unconvertedCurrencies']);
	}

	// ===== Multi-currency with conversion =====

	public function testMultiCurrencyConversion(): void {
		$accounts = [
			$this->makeAccount(1, 'GBP Account', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'EUR Account', 'savings', 1200.00, 'EUR'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(true);

		// EUR 1200 → GBP 1020 (mock conversion)
		$this->conversionService->method('convertToBase')
			->with('1200', 'EUR', 'user1', null)
			->willReturn('1020.0000000000');

		$result = $this->service->calculateNetWorth('user1');

		$this->assertEqualsWithDelta(2020.00, $result['totalAssets'], 0.01);
		$this->assertEquals(0.00, $result['totalLiabilities']);
		$this->assertEqualsWithDelta(2020.00, $result['netWorth'], 0.01);
		$this->assertEquals('GBP', $result['baseCurrency']);
		$this->assertEmpty($result['unconvertedCurrencies']);
	}

	// ===== Multi-currency with liabilities =====

	public function testMultiCurrencyWithLiabilities(): void {
		$accounts = [
			$this->makeAccount(1, 'GBP Checking', 'checking', 5000.00, 'GBP'),
			$this->makeAccount(2, 'USD Credit Card', 'credit_card', -500.00, 'USD'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(true);

		// USD -500 → GBP -400 (mock conversion)
		$this->conversionService->method('convertToBase')
			->with('-500', 'USD', 'user1', null)
			->willReturn('-400.0000000000');

		$result = $this->service->calculateNetWorth('user1');

		$this->assertEqualsWithDelta(5000.00, $result['totalAssets'], 0.01);
		$this->assertEqualsWithDelta(400.00, $result['totalLiabilities'], 0.01);
		$this->assertEqualsWithDelta(4600.00, $result['netWorth'], 0.01);
	}

	// ===== Graceful degradation when rate unavailable =====

	public function testUnconvertedCurrencyTracked(): void {
		$accounts = [
			$this->makeAccount(1, 'GBP Account', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'BTC Account', 'cryptocurrency', 0.5, 'BTC'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(true);

		// BTC conversion fails - returns unchanged amount
		$this->conversionService->method('convertToBase')
			->with('0.5', 'BTC', 'user1', null)
			->willReturn('0.5');

		$result = $this->service->calculateNetWorth('user1');

		$this->assertContains('BTC', $result['unconvertedCurrencies']);
	}

	// ===== Future transaction adjustments =====

	public function testFutureTransactionsAdjusted(): void {
		$accounts = [
			$this->makeAccount(1, 'Checking', 'checking', 2000.00, 'GBP'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		// Future transaction of 500 means stored balance includes it
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([1 => 500]);
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(false);

		$result = $this->service->calculateNetWorth('user1');

		// Balance should be 2000 - 500 = 1500
		$this->assertEqualsWithDelta(1500.00, $result['totalAssets'], 0.01);
	}

	// ===== Base currency account skips conversion =====

	public function testBaseCurrencyAccountSkipsConversion(): void {
		$accounts = [
			$this->makeAccount(1, 'GBP Account', 'checking', 1000.00, 'GBP'),
			$this->makeAccount(2, 'EUR Account', 'savings', 500.00, 'EUR'),
		];

		$this->accountMapper->method('findAll')->willReturn($accounts);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->conversionService->method('needsConversion')->willReturn(true);

		// convertToBase should only be called for EUR account, not GBP
		$this->conversionService->expects($this->once())
			->method('convertToBase')
			->with('500', 'EUR', 'user1', null)
			->willReturn('425.0000000000');

		$result = $this->service->calculateNetWorth('user1');

		$this->assertEqualsWithDelta(1425.00, $result['totalAssets'], 0.01);
	}
}
