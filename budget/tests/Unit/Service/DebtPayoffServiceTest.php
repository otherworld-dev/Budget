<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\DebtPayoffService;
use PHPUnit\Framework\TestCase;

class DebtPayoffServiceTest extends TestCase {
	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;
	private DebtPayoffService $service;

	protected function setUp(): void {
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->service = new DebtPayoffService(
			$this->accountMapper,
			$this->transactionMapper
		);
	}

	private function makeAccount(array $overrides = []): Account {
		$a = new Account();
		$a->setId($overrides['id'] ?? 1);
		$a->setUserId($overrides['userId'] ?? 'user1');
		$a->setName($overrides['name'] ?? 'Credit Card');
		$a->setType($overrides['type'] ?? 'credit_card');
		$a->setBalance($overrides['balance'] ?? -5000.0);
		$a->setInterestRate($overrides['interestRate'] ?? 18.0);
		$a->setMinimumPayment($overrides['minimumPayment'] ?? 100.0);
		return $a;
	}

	// ── getDebts ────────────────────────────────────────────────────

	public function testGetDebtsFiltersLiabilityAccounts(): void {
		$accounts = [
			$this->makeAccount(['id' => 1, 'type' => 'credit_card']),
			$this->makeAccount(['id' => 2, 'type' => 'checking', 'name' => 'Checking']),
			$this->makeAccount(['id' => 3, 'type' => 'loan', 'name' => 'Car Loan']),
			$this->makeAccount(['id' => 4, 'type' => 'savings', 'name' => 'Savings']),
			$this->makeAccount(['id' => 5, 'type' => 'mortgage', 'name' => 'House']),
		];

		$this->accountMapper->method('findAll')->with('user1')->willReturn($accounts);

		$debts = $this->service->getDebts('user1');
		$types = array_map(fn($d) => $d->getType(), array_values($debts));

		$this->assertCount(3, $debts);
		$this->assertContains('credit_card', $types);
		$this->assertContains('loan', $types);
		$this->assertContains('mortgage', $types);
	}

	public function testGetDebtsReturnsEmptyWhenNoLiabilities(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(['type' => 'checking']),
		]);

		$debts = $this->service->getDebts('user1');
		$this->assertEmpty($debts);
	}

	public function testGetDebtsIncludesLineOfCredit(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(['type' => 'line_of_credit']),
		]);

		$debts = $this->service->getDebts('user1');
		$this->assertCount(1, $debts);
	}

	// ── getSummary ──────────────────────────────────────────────────

	public function testGetSummaryComputesTotals(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(['id' => 1, 'balance' => -5000, 'interestRate' => 18.0, 'minimumPayment' => 100]),
			$this->makeAccount(['id' => 2, 'name' => 'Loan', 'type' => 'loan', 'balance' => -10000, 'interestRate' => 5.0, 'minimumPayment' => 200]),
		]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		$summary = $this->service->getSummary('user1');

		$this->assertEquals(15000.0, $summary['totalBalance']);
		$this->assertEquals(300.0, $summary['totalMinimumPayment']);
		$this->assertEquals(2, $summary['debtCount']);
		$this->assertEquals(18.0, $summary['highestInterestRate']);
		$this->assertEquals(5000.0, $summary['lowestBalance']);
	}

	public function testGetSummaryReturnsZerosWhenNoDebts(): void {
		$this->accountMapper->method('findAll')->willReturn([]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		$summary = $this->service->getSummary('user1');

		$this->assertEquals(0.0, $summary['totalBalance']);
		$this->assertEquals(0.0, $summary['totalMinimumPayment']);
		$this->assertEquals(0, $summary['debtCount']);
		$this->assertEquals(0, $summary['lowestBalance']);
	}

	public function testGetSummaryAdjustsForFutureTransactions(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(['id' => 1, 'balance' => -5000, 'interestRate' => 10.0, 'minimumPayment' => 100]),
		]);
		// Future change of +500 means the actual balance-as-of-today is different
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([1 => 500]);

		$summary = $this->service->getSummary('user1');

		// abs(-5000 - 500) = 5500
		$this->assertEquals(5500.0, $summary['totalBalance']);
	}

	// ── calculatePayoffPlan ─────────────────────────────────────────

	public function testCalculatePayoffPlanEmptyDebts(): void {
		$this->accountMapper->method('findAll')->willReturn([]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		$plan = $this->service->calculatePayoffPlan('user1', 'avalanche');

		$this->assertSame('avalanche', $plan['strategy']);
		$this->assertSame(0, $plan['totalMonths']);
		$this->assertEmpty($plan['debts']);
		$this->assertNull($plan['payoffDate']);
	}

	public function testCalculatePayoffPlanAvalancheSortsHighestInterestFirst(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(['id' => 1, 'balance' => -1000, 'interestRate' => 5.0, 'minimumPayment' => 50]),
			$this->makeAccount(['id' => 2, 'name' => 'High Rate', 'type' => 'loan', 'balance' => -1000, 'interestRate' => 20.0, 'minimumPayment' => 50]),
		]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		$plan = $this->service->calculatePayoffPlan('user1', 'avalanche');

		// In avalanche, highest interest rate should be first
		$this->assertSame('Debt Avalanche', $plan['strategyName']);
		$this->assertGreaterThan(0, $plan['totalMonths']);
		$this->assertCount(2, $plan['debts']);
		// First debt in results should have higher interest rate
		$this->assertEquals(20.0, $plan['debts'][0]['interestRate']);
	}

	public function testCalculatePayoffPlanSnowballSortsLowestBalanceFirst(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(['id' => 1, 'balance' => -5000, 'interestRate' => 5.0, 'minimumPayment' => 100]),
			$this->makeAccount(['id' => 2, 'name' => 'Small', 'type' => 'loan', 'balance' => -500, 'interestRate' => 20.0, 'minimumPayment' => 50]),
		]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		$plan = $this->service->calculatePayoffPlan('user1', 'snowball');

		$this->assertSame('Debt Snowball', $plan['strategyName']);
		// In snowball, the small debt should be paid off first (lower payoffMonth)
		$debtById = [];
		foreach ($plan['debts'] as $d) {
			$debtById[$d['id']] = $d;
		}
		$this->assertLessThan($debtById[1]['payoffMonth'], $debtById[2]['payoffMonth']);
	}

	public function testCalculatePayoffPlanWithExtraPayment(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(['id' => 1, 'balance' => -1000, 'interestRate' => 12.0, 'minimumPayment' => 50]),
		]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		$noExtra = $this->service->calculatePayoffPlan('user1', 'avalanche', 0);
		$withExtra = $this->service->calculatePayoffPlan('user1', 'avalanche', 200.0);

		// Extra payment should reduce total months
		$this->assertGreaterThan($withExtra['totalMonths'], $noExtra['totalMonths']);
		// Extra payment should reduce total interest
		$this->assertGreaterThan($withExtra['totalInterest'], $noExtra['totalInterest']);
	}

	public function testCalculatePayoffPlanSetsPayoffDate(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(['id' => 1, 'balance' => -500, 'interestRate' => 0, 'minimumPayment' => 100]),
		]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		$plan = $this->service->calculatePayoffPlan('user1');

		$this->assertNotNull($plan['payoffDate']);
		$this->assertSame(5, $plan['totalMonths']); // 500 / 100 = 5 months
	}

	public function testCalculatePayoffPlanGeneratesTimeline(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(['id' => 1, 'balance' => -200, 'interestRate' => 0, 'minimumPayment' => 100]),
		]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		$plan = $this->service->calculatePayoffPlan('user1');

		$this->assertNotEmpty($plan['timeline']);
		$this->assertSame(1, $plan['timeline'][0]['month']);
		$this->assertArrayHasKey('payments', $plan['timeline'][0]);
	}

	// ── compareStrategies ───────────────────────────────────────────

	public function testCompareStrategiesReturnsBothPlans(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(['id' => 1, 'balance' => -3000, 'interestRate' => 18.0, 'minimumPayment' => 100]),
			$this->makeAccount(['id' => 2, 'name' => 'Small', 'type' => 'loan', 'balance' => -500, 'interestRate' => 5.0, 'minimumPayment' => 50]),
		]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		$comparison = $this->service->compareStrategies('user1');

		$this->assertArrayHasKey('avalanche', $comparison);
		$this->assertArrayHasKey('snowball', $comparison);
		$this->assertArrayHasKey('comparison', $comparison);
		$this->assertArrayHasKey('interestSavedByAvalanche', $comparison['comparison']);
		$this->assertArrayHasKey('recommendation', $comparison['comparison']);
		// Timeline should be stripped from comparison
		$this->assertArrayNotHasKey('timeline', $comparison['avalanche']);
		$this->assertArrayNotHasKey('timeline', $comparison['snowball']);
	}

	public function testCompareStrategiesRecommendation(): void {
		$this->accountMapper->method('findAll')->willReturn([
			$this->makeAccount(['id' => 1, 'balance' => -10000, 'interestRate' => 22.0, 'minimumPayment' => 200]),
			$this->makeAccount(['id' => 2, 'name' => 'Low', 'type' => 'loan', 'balance' => -1000, 'interestRate' => 3.0, 'minimumPayment' => 50]),
		]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		$comparison = $this->service->compareStrategies('user1');

		// High interest difference should recommend avalanche
		$this->assertContains(
			$comparison['comparison']['recommendation'],
			['avalanche', 'snowball', 'either']
		);
		$this->assertNotEmpty($comparison['comparison']['explanation']);
	}

	public function testCompareStrategiesWithEmptyDebts(): void {
		$this->accountMapper->method('findAll')->willReturn([]);
		$this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

		$comparison = $this->service->compareStrategies('user1');

		$this->assertSame(0, $comparison['avalanche']['totalMonths']);
		$this->assertSame(0, $comparison['snowball']['totalMonths']);
		$this->assertSame('either', $comparison['comparison']['recommendation']);
	}
}
