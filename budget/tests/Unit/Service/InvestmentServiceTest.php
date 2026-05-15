<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\InvestmentService;
use PHPUnit\Framework\TestCase;

class InvestmentServiceTest extends TestCase {
	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;
	private CurrencyConversionService $conversionService;
	private InvestmentService $service;

	protected function setUp(): void {
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->conversionService = $this->createMock(CurrencyConversionService::class);
		$this->service = new InvestmentService(
			$this->accountMapper,
			$this->transactionMapper,
			$this->conversionService
		);

		$this->conversionService->method('getBaseCurrency')
			->willReturn('GBP');
	}

	private function makeAccount(array $overrides = []): Account {
		$a = new Account();
		$a->setId($overrides['id'] ?? 1);
		$a->setUserId($overrides['userId'] ?? 'user1');
		$a->setType($overrides['type'] ?? 'investment');
		$a->setCurrency($overrides['currency'] ?? 'USD');
		$a->setBalance($overrides['balance'] ?? 0.0);
		$a->setName($overrides['name'] ?? 'My Investment');
		return $a;
	}

	private function makeTransaction(array $overrides = []): Transaction {
		$tx = new Transaction();
		$tx->setId($overrides['id'] ?? 1);
		$tx->setAccountId($overrides['accountId'] ?? 1);
		$tx->setAmount($overrides['amount'] ?? 100.0);
		$tx->setType($overrides['type'] ?? 'credit');
		$tx->setDate($overrides['date'] ?? '2025-01-15');
		$tx->setStatus($overrides['status'] ?? 'cleared');
		return $tx;
	}

	// ── Non-investment types return empty result ────────────────────

	public function testCheckingAccountReturnsEmptyResult(): void {
		$account = $this->makeAccount(['type' => 'checking']);
		$this->accountMapper->method('find')->willReturn($account);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertSame(0.0, $result['totalCost']);
		$this->assertSame(0.0, $result['totalProceeds']);
		$this->assertSame(0.0, $result['netInvested']);
		$this->assertSame(0.0, $result['currentValue']);
		$this->assertSame(0.0, $result['unrealisedPnL']);
		$this->assertNull($result['pnlPercentage']);
		$this->assertSame('GBP', $result['baseCurrency']);
		$this->assertArrayNotHasKey('conversionWarning', $result);
	}

	public function testSavingsAccountReturnsEmptyResult(): void {
		$account = $this->makeAccount(['type' => 'savings']);
		$this->accountMapper->method('find')->willReturn($account);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertSame(0.0, $result['unrealisedPnL']);
	}

	public function testCreditCardAccountReturnsEmptyResult(): void {
		$account = $this->makeAccount(['type' => 'credit_card']);
		$this->accountMapper->method('find')->willReturn($account);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertSame(0.0, $result['unrealisedPnL']);
	}

	// ── Investment types are accepted ───────────────────────────────

	public function testInvestmentAccountTypeIsAccepted(): void {
		$account = $this->makeAccount(['type' => 'investment', 'balance' => 500.0, 'currency' => 'GBP']);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('findAllClearedByAccount')->willReturn([
			$this->makeTransaction(['amount' => 1000.0, 'type' => 'credit']),
		]);
		$this->conversionService->method('convert')
			->willReturnArgument(0);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertSame(1000.0, $result['totalCost']);
	}

	public function testCryptocurrencyAccountTypeIsAccepted(): void {
		$account = $this->makeAccount(['type' => 'cryptocurrency', 'balance' => 500.0, 'currency' => 'GBP']);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('findAllClearedByAccount')->willReturn([
			$this->makeTransaction(['amount' => 1000.0, 'type' => 'credit']),
		]);
		$this->conversionService->method('convert')
			->willReturnArgument(0);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertSame(1000.0, $result['totalCost']);
	}

	// ── No transactions → empty result ──────────────────────────────

	public function testNoTransactionsReturnsEmptyResult(): void {
		$account = $this->makeAccount(['type' => 'investment']);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('findAllClearedByAccount')->willReturn([]);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertSame(0.0, $result['totalCost']);
		$this->assertSame(0.0, $result['netInvested']);
		$this->assertNull($result['pnlPercentage']);
		$this->assertArrayNotHasKey('conversionWarning', $result);
	}

	// ── P&L calculations ────────────────────────────────────────────

	public function testBuysOnlyCalculatesCorrectTotalCostAndNetInvested(): void {
		$account = $this->makeAccount(['type' => 'investment', 'balance' => 800.0, 'currency' => 'GBP']);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('findAllClearedByAccount')->willReturn([
			$this->makeTransaction(['id' => 1, 'amount' => 500.0, 'type' => 'credit', 'date' => '2025-01-10']),
			$this->makeTransaction(['id' => 2, 'amount' => 300.0, 'type' => 'credit', 'date' => '2025-02-15']),
		]);
		// Same currency, convert returns amount unchanged
		$this->conversionService->method('convert')
			->willReturnArgument(0);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertSame(800.0, $result['totalCost']);
		$this->assertSame(0.0, $result['totalProceeds']);
		$this->assertSame(800.0, $result['netInvested']);
	}

	public function testBuysAndSellsCalculatesCorrectNetInvested(): void {
		$account = $this->makeAccount(['type' => 'investment', 'balance' => 600.0, 'currency' => 'GBP']);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('findAllClearedByAccount')->willReturn([
			$this->makeTransaction(['id' => 1, 'amount' => 1000.0, 'type' => 'credit', 'date' => '2025-01-10']),
			$this->makeTransaction(['id' => 2, 'amount' => 400.0, 'type' => 'debit', 'date' => '2025-03-01']),
		]);
		$this->conversionService->method('convert')
			->willReturnArgument(0);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertSame(1000.0, $result['totalCost']);
		$this->assertSame(400.0, $result['totalProceeds']);
		$this->assertSame(600.0, $result['netInvested']);
	}

	public function testPositiveReturnCalculatesPositivePnLAndPercentage(): void {
		// Invested 1000, now worth 1500 → PnL = 500, percentage = 50%
		$account = $this->makeAccount(['type' => 'investment', 'balance' => 1500.0, 'currency' => 'GBP']);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('findAllClearedByAccount')->willReturn([
			$this->makeTransaction(['amount' => 1000.0, 'type' => 'credit']),
		]);
		$this->conversionService->method('convert')
			->willReturnArgument(0);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertSame(1000.0, $result['netInvested']);
		$this->assertSame(1500.0, $result['currentValue']);
		$this->assertSame(500.0, $result['unrealisedPnL']);
		$this->assertSame(50.0, $result['pnlPercentage']);
	}

	public function testNegativeReturnCalculatesNegativePnL(): void {
		// Invested 1000, now worth 700 → PnL = -300, percentage = -30%
		$account = $this->makeAccount(['type' => 'investment', 'balance' => 700.0, 'currency' => 'GBP']);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('findAllClearedByAccount')->willReturn([
			$this->makeTransaction(['amount' => 1000.0, 'type' => 'credit']),
		]);
		$this->conversionService->method('convert')
			->willReturnArgument(0);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertSame(-300.0, $result['unrealisedPnL']);
		$this->assertSame(-30.0, $result['pnlPercentage']);
	}

	public function testZeroNetInvestedReturnsPnlPercentageNull(): void {
		// All sold: cost 1000, proceeds 1000 → netInvested = 0
		$account = $this->makeAccount(['type' => 'investment', 'balance' => 0.0, 'currency' => 'GBP']);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('findAllClearedByAccount')->willReturn([
			$this->makeTransaction(['id' => 1, 'amount' => 1000.0, 'type' => 'credit']),
			$this->makeTransaction(['id' => 2, 'amount' => 1000.0, 'type' => 'debit']),
		]);
		$this->conversionService->method('convert')
			->willReturnArgument(0);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertSame(0.0, $result['netInvested']);
		$this->assertNull($result['pnlPercentage']);
	}

	// ── Currency conversion ─────────────────────────────────────────

	public function testSameCurrencyPassesThroughWithoutConversionWarning(): void {
		$account = $this->makeAccount(['type' => 'investment', 'balance' => 500.0, 'currency' => 'GBP']);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('findAllClearedByAccount')->willReturn([
			$this->makeTransaction(['amount' => 500.0, 'type' => 'credit']),
		]);
		// Same currency: convert returns the amount unchanged (not a failure)
		$this->conversionService->method('convert')
			->willReturnArgument(0);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertFalse($result['conversionWarning']);
	}

	public function testDifferentCurrencyConvertsAtHistoricalAndCurrentRates(): void {
		// Account in BTC, base is GBP. Buy 0.5 BTC at rate where 0.5 BTC = 15000 GBP
		// Current balance 0.5 BTC = 20000 GBP now
		$account = $this->makeAccount(['type' => 'cryptocurrency', 'balance' => 0.5, 'currency' => 'BTC']);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('findAllClearedByAccount')->willReturn([
			$this->makeTransaction(['amount' => 0.5, 'type' => 'credit', 'date' => '2025-01-10']),
		]);

		$this->conversionService->method('convert')
			->willReturnCallback(function ($amount, $from, $to, $date) {
				if ($date === '2025-01-10') {
					// Historical rate: 1 BTC = 30000 GBP, so 0.5 = 15000
					return '15000.00';
				}
				// Current rate: 1 BTC = 40000 GBP, so 0.5 = 20000
				return '20000.00';
			});

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertSame(15000.0, $result['totalCost']);
		$this->assertSame(15000.0, $result['netInvested']);
		$this->assertSame(20000.0, $result['currentValue']);
		$this->assertSame(5000.0, $result['unrealisedPnL']);
		$this->assertFalse($result['conversionWarning']);
	}

	public function testConversionFailureSetsConversionWarning(): void {
		// Account in BTC, base is GBP, but convert returns amount unchanged (failure)
		$account = $this->makeAccount(['type' => 'investment', 'balance' => 100.0, 'currency' => 'BTC']);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionMapper->method('findAllClearedByAccount')->willReturn([
			$this->makeTransaction(['amount' => 100.0, 'type' => 'credit']),
		]);

		// Simulate conversion failure: amount returned unchanged despite different currencies
		$this->conversionService->method('convert')
			->willReturnArgument(0);

		$result = $this->service->calculateUnrealisedPnL(1, 'user1');

		$this->assertTrue($result['conversionWarning']);
	}
}
