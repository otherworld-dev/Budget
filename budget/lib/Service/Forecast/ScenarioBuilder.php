<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Forecast;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;

/**
 * Builds and calculates forecast scenarios.
 */
class ScenarioBuilder {
    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;

    public function __construct(
        AccountMapper $accountMapper,
        TransactionMapper $transactionMapper
    ) {
        $this->accountMapper = $accountMapper;
        $this->transactionMapper = $transactionMapper;
    }

    /**
     * Generate standard scenario definitions.
     *
     * @param string $userId User ID
     * @param array $accounts List of accounts
     * @param int $forecastMonths Forecast horizon in months
     * @return array Scenario definitions
     */
    public function generateScenarios(string $userId, array $accounts, int $forecastMonths): array {
        return [
            'conservative' => [
                'name' => 'Conservative',
                'description' => 'Assumes 20% lower income and 10% higher expenses',
                'assumptions' => ['income_factor' => 0.8, 'expense_factor' => 1.1]
            ],
            'optimistic' => [
                'name' => 'Optimistic',
                'description' => 'Assumes 10% higher income and 5% lower expenses',
                'assumptions' => ['income_factor' => 1.1, 'expense_factor' => 0.95]
            ],
            'recession' => [
                'name' => 'Economic Downturn',
                'description' => 'Assumes 30% income reduction and 20% expense increase',
                'assumptions' => ['income_factor' => 0.7, 'expense_factor' => 1.2]
            ]
        ];
    }

    /**
     * Calculate projected balance under a scenario.
     *
     * @param string $userId User ID
     * @param int|null $accountId Optional account filter
     * @param float $incomeGrowth Income growth factor (-0.3 to +0.3)
     * @param float $expenseGrowth Expense growth factor (-0.3 to +0.3)
     * @return float Projected balance
     */
    public function calculateScenarioBalance(
        string $userId,
        ?int $accountId,
        float $incomeGrowth,
        float $expenseGrowth
    ): float {
        $accounts = $accountId
            ? [$this->accountMapper->find($accountId, $userId)]
            : $this->accountMapper->findAll($userId);

        // Get future transaction adjustments to calculate balance as of today
        $today = date('Y-m-d');
        $futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

        $currentBalance = 0.0;
        foreach ($accounts as $account) {
            $storedBalance = $account->getBalance();
            $futureChange = $futureChanges[$account->getId()] ?? 0;
            $currentBalance += ($storedBalance - $futureChange);
        }

        // Get historical averages
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-6 months'));
        $transactions = $this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate);

        $monthlyIncome = 0.0;
        $monthlyExpenses = 0.0;
        $monthCount = [];

        foreach ($transactions as $transaction) {
            $month = date('Y-m', strtotime($transaction->getDate()));
            $monthCount[$month] = true;

            if ($transaction->getType() === 'credit') {
                $monthlyIncome += $transaction->getAmount();
            } else {
                $monthlyExpenses += $transaction->getAmount();
            }
        }

        $months = max(1, count($monthCount));
        $avgMonthlyIncome = $monthlyIncome / $months;
        $avgMonthlyExpenses = $monthlyExpenses / $months;

        // Apply growth factors
        $adjustedIncome = $avgMonthlyIncome * (1 + $incomeGrowth);
        $adjustedExpenses = $avgMonthlyExpenses * (1 + $expenseGrowth);

        // Project 12 months out
        return $currentBalance + (($adjustedIncome - $adjustedExpenses) * 12);
    }

    /**
     * Run multiple scenarios and return results.
     *
     * @param string $userId User ID
     * @param int|null $accountId Optional account filter
     * @param array $customScenarios Optional custom scenarios
     * @return array Scenario results
     */
    public function runScenarios(
        string $userId,
        ?int $accountId = null,
        array $customScenarios = []
    ): array {
        $results = [
            'baseCase' => [
                'balance' => $this->calculateScenarioBalance($userId, $accountId, 0.02, 0.03),
                'assumptions' => 'Current trend continues'
            ],
            'optimistic' => [
                'balance' => $this->calculateScenarioBalance($userId, $accountId, 0.10, -0.02),
                'assumptions' => '10% income increase, 2% expense reduction'
            ],
            'pessimistic' => [
                'balance' => $this->calculateScenarioBalance($userId, $accountId, -0.15, 0.10),
                'assumptions' => '15% income decrease, 10% expense increase'
            ],
            'custom' => []
        ];

        foreach ($customScenarios as $name => $scenario) {
            $results['custom'][$name] = [
                'balance' => $this->calculateScenarioBalance(
                    $userId,
                    $accountId,
                    $scenario['incomeGrowth'] ?? 0,
                    $scenario['expenseGrowth'] ?? 0
                ),
                'assumptions' => $scenario['description'] ?? ''
            ];
        }

        return $results;
    }

    /**
     * Generate month labels for charts.
     *
     * @param int $historicalPeriod Months of history
     * @param int $forecastHorizon Months to forecast
     * @return array Month labels
     */
    public function generateMonthLabels(int $historicalPeriod, int $forecastHorizon): array {
        $labels = [];
        $startDate = strtotime("-{$historicalPeriod} months");

        for ($i = 0; $i < ($historicalPeriod + $forecastHorizon); $i++) {
            $labels[] = date('M Y', strtotime("+{$i} months", $startDate));
        }

        return $labels;
    }

    /**
     * Get historical balance data for charts.
     *
     * @param string $userId User ID
     * @param int|null $accountId Optional account filter
     * @param int $months Number of months
     * @return array Historical balances
     */
    public function getHistoricalBalances(string $userId, ?int $accountId, int $months): array {
        $accounts = $accountId
            ? [$this->accountMapper->find($accountId, $userId)]
            : $this->accountMapper->findAll($userId);

        // Get future transaction adjustments to calculate balance as of today
        $today = date('Y-m-d');
        $futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

        $currentBalance = 0.0;
        foreach ($accounts as $account) {
            $storedBalance = $account->getBalance();
            $futureChange = $futureChanges[$account->getId()] ?? 0;
            $currentBalance += ($storedBalance - $futureChange);
        }

        // Work backwards from current balance using transactions
        $balances = [];
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$months} months"));
        $transactions = $this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate);

        // Group transactions by month
        $monthlyChanges = [];
        foreach ($transactions as $transaction) {
            $month = date('Y-m', strtotime($transaction->getDate()));
            if (!isset($monthlyChanges[$month])) {
                $monthlyChanges[$month] = 0;
            }

            if ($transaction->getType() === 'credit') {
                $monthlyChanges[$month] += $transaction->getAmount();
            } else {
                $monthlyChanges[$month] -= $transaction->getAmount();
            }
        }

        // Calculate balances working backwards
        $balance = $currentBalance;
        $monthBalances = [];

        for ($i = 0; $i < $months; $i++) {
            $month = date('Y-m', strtotime("-{$i} months"));
            $monthBalances[$month] = $balance;
            $balance -= $monthlyChanges[$month] ?? 0;
        }

        // Reverse to chronological order
        krsort($monthBalances);
        return array_values($monthBalances);
    }

    /**
     * Generate forecast balance projections.
     *
     * @param array $scenario Scenario configuration
     * @param int $months Number of months to project
     * @return array Projected balances
     */
    public function generateForecastBalances(array $scenario, int $months): array {
        $balances = [];
        $startBalance = $scenario['projectedBalance'] ?? $scenario['balance'] ?? 50000.0;
        $monthlyGrowth = ($scenario['projectedBalance'] ?? $startBalance) / 12;

        $balance = $startBalance;
        for ($i = 0; $i < $months; $i++) {
            $balances[] = round($balance, 2);
            $balance += $monthlyGrowth / 12;
        }

        return $balances;
    }
}
