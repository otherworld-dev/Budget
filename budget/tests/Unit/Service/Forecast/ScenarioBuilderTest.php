<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Forecast;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Forecast\ScenarioBuilder;
use PHPUnit\Framework\TestCase;

class ScenarioBuilderTest extends TestCase {
    private ScenarioBuilder $builder;
    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;

    protected function setUp(): void {
        $this->accountMapper = $this->createMock(AccountMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);

        $this->builder = new ScenarioBuilder(
            $this->accountMapper,
            $this->transactionMapper
        );
    }

    private function makeAccount(int $id, float $balance): Account {
        $account = new Account();
        $account->setId($id);
        $account->setBalance($balance);
        return $account;
    }

    private function makeTransaction(string $date, float $amount, string $type): Transaction {
        $tx = new Transaction();
        $tx->setDate($date);
        $tx->setAmount($amount);
        $tx->setType($type);
        return $tx;
    }

    // ===== generateScenarios =====

    public function testGenerateScenariosReturnsThreeScenarios(): void {
        $result = $this->builder->generateScenarios('user1', [], 12);

        $this->assertArrayHasKey('conservative', $result);
        $this->assertArrayHasKey('optimistic', $result);
        $this->assertArrayHasKey('recession', $result);

        $this->assertEquals(0.8, $result['conservative']['assumptions']['income_factor']);
        $this->assertEquals(1.1, $result['conservative']['assumptions']['expense_factor']);
        $this->assertEquals(1.1, $result['optimistic']['assumptions']['income_factor']);
        $this->assertEquals(0.7, $result['recession']['assumptions']['income_factor']);
    }

    // ===== calculateScenarioBalance =====

    public function testCalculateScenarioBalanceWithGrowth(): void {
        $account = $this->makeAccount(1, 10000.0);
        $this->accountMapper->method('findAll')->willReturn([$account]);
        $this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

        // 6 months of transactions: 3 months credit, 3 months debit
        $transactions = [
            $this->makeTransaction('2025-10-15', 5000.0, 'credit'),
            $this->makeTransaction('2025-10-20', 3000.0, 'debit'),
            $this->makeTransaction('2025-11-15', 5000.0, 'credit'),
            $this->makeTransaction('2025-11-20', 3000.0, 'debit'),
            $this->makeTransaction('2025-12-15', 5000.0, 'credit'),
            $this->makeTransaction('2025-12-20', 3000.0, 'debit'),
        ];
        $this->transactionMapper->method('findAllByUserAndDateRange')->willReturn($transactions);

        $result = $this->builder->calculateScenarioBalance('user1', null, 0.0, 0.0);

        // Average monthly income: 15000/3 = 5000, expenses: 9000/3 = 3000
        // Projected 12 months: 10000 + (5000-3000)*12 = 34000
        $this->assertEqualsWithDelta(34000.0, $result, 1.0);
    }

    public function testCalculateScenarioBalanceWithAccountFilter(): void {
        $account = $this->makeAccount(5, 8000.0);
        $this->accountMapper->expects($this->once())->method('find')
            ->with(5, 'user1')->willReturn($account);
        $this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
        $this->transactionMapper->method('findAllByUserAndDateRange')->willReturn([]);

        $this->builder->calculateScenarioBalance('user1', 5, 0.0, 0.0);
    }

    public function testCalculateScenarioBalanceSubtractsFutureChanges(): void {
        $account = $this->makeAccount(1, 12000.0);
        $this->accountMapper->method('findAll')->willReturn([$account]);
        // Future transactions already in balance: subtract 2000
        $this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([1 => 2000.0]);
        $this->transactionMapper->method('findAllByUserAndDateRange')->willReturn([]);

        $result = $this->builder->calculateScenarioBalance('user1', null, 0.0, 0.0);

        // Current balance = 12000 - 2000 = 10000, no monthly changes
        $this->assertEqualsWithDelta(10000.0, $result, 1.0);
    }

    // ===== runScenarios =====

    public function testRunScenariosReturnsAllCases(): void {
        $account = $this->makeAccount(1, 10000.0);
        $this->accountMapper->method('findAll')->willReturn([$account]);
        $this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
        $this->transactionMapper->method('findAllByUserAndDateRange')->willReturn([]);

        $result = $this->builder->runScenarios('user1');

        $this->assertArrayHasKey('baseCase', $result);
        $this->assertArrayHasKey('optimistic', $result);
        $this->assertArrayHasKey('pessimistic', $result);
        $this->assertArrayHasKey('custom', $result);
        $this->assertArrayHasKey('balance', $result['baseCase']);
        $this->assertArrayHasKey('assumptions', $result['baseCase']);
    }

    public function testRunScenariosWithCustomScenarios(): void {
        $account = $this->makeAccount(1, 10000.0);
        $this->accountMapper->method('findAll')->willReturn([$account]);
        $this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);
        $this->transactionMapper->method('findAllByUserAndDateRange')->willReturn([]);

        $custom = [
            'highGrowth' => [
                'incomeGrowth' => 0.2,
                'expenseGrowth' => -0.1,
                'description' => '20% income boost',
            ],
        ];

        $result = $this->builder->runScenarios('user1', null, $custom);

        $this->assertArrayHasKey('highGrowth', $result['custom']);
        $this->assertEquals('20% income boost', $result['custom']['highGrowth']['assumptions']);
    }

    // ===== generateMonthLabels =====

    public function testGenerateMonthLabelsCorrectCount(): void {
        $result = $this->builder->generateMonthLabels(6, 6);

        $this->assertCount(12, $result);
    }

    public function testGenerateMonthLabelsFormat(): void {
        $result = $this->builder->generateMonthLabels(1, 1);

        // Each label should be "Mon YYYY" format
        $this->assertCount(2, $result);
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2} \d{4}$/', $result[0]);
    }

    // ===== generateForecastBalances =====

    public function testGenerateForecastBalancesReturnsCorrectLength(): void {
        $scenario = ['balance' => 50000.0, 'projectedBalance' => 60000.0];

        $result = $this->builder->generateForecastBalances($scenario, 6);

        $this->assertCount(6, $result);
        $this->assertEquals(60000.0, $result[0]);
    }

    public function testGenerateForecastBalancesFallsBackToBalance(): void {
        $scenario = ['balance' => 30000.0];

        $result = $this->builder->generateForecastBalances($scenario, 3);

        $this->assertCount(3, $result);
        $this->assertEquals(30000.0, $result[0]);
    }
}
