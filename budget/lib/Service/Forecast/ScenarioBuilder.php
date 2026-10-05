<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Forecast;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\CurrencyTotals;
use OCA\Budget\Service\MoneyCalculator;
use OCA\Budget\Service\UserClock;

/**
 * Builds and calculates forecast scenarios.
 */
class ScenarioBuilder {
	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;

	public function __construct(
		AccountMapper $accountMapper,
		TransactionMapper $transactionMapper,
		private ?UserClock $userClock = null,
		private ?CurrencyTotals $currencyTotals = null,
	) {
		$this->accountMapper = $accountMapper;
		$this->transactionMapper = $transactionMapper;
	}

	/**
	 * Multipliers into the base currency when the accounts hold more than
	 * one currency (CurrencyTotals::accountRates()), null when they share one
	 * and are added up as they are. Balances and history were summed as
	 * stored, so euros counted as pounds.
	 *
	 * @return array<int, string>|null account id => multiplier
	 */
	private function baseRates(array $accounts, string $userId): ?array {
		$conversion = $this->currencyTotals?->accountRates($accounts, $userId);
		return ($conversion['currency'] ?? null) !== null ? $conversion['rates'] : null;
	}

	/**
	 * The accounts' balances as of today, added up in the base currency when
	 * they hold more than one. An account with no rate is left out.
	 *
	 * @param array<int, float> $futureChanges
	 * @param array<int, string>|null $rates
	 */
	private function balanceToday(array $accounts, array $futureChanges, ?array $rates): float {
		$total = 0.0;
		foreach ($accounts as $account) {
			if ($rates !== null && !isset($rates[$account->getId()])) {
				continue;
			}
			$balance = $account->getBalance() - ($futureChanges[$account->getId()] ?? 0);
			$total += $rates !== null ? (float)MoneyCalculator::multiply($balance, $rates[$account->getId()], 10) : $balance;
		}
		return $total;
	}

	/**
	 * The history of the accounts counted: when converting, an account with
	 * no rate is left out of the history as it is out of the balance.
	 *
	 * @param array<int, string>|null $rates
	 */
	private static function inScope(array $transactions, ?array $rates): array {
		return $rates === null ? $transactions : array_values(array_filter(
			$transactions,
			static fn ($t) => isset($rates[(int)$t->getAccountId()])
		));
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
		float $expenseGrowth,
	): float {
		$accounts = $accountId
			? [$this->accountMapper->find($accountId, $userId)]
			: $this->accountMapper->findAll($userId);

		// Skip accounts flagged out of reports in all-accounts scenarios (#286)
		if ($accountId === null) {
			$accounts = array_values(array_filter($accounts, static fn ($a) => !$a->getExcludedFromReports()));
		}

		// Get future transaction adjustments to calculate balance as of today
		// (the user's: a purchase dated it is already in the stored balance)
		$today = $this->userClock?->today($userId) ?? date('Y-m-d');
		$futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

		$rates = $this->baseRates($accounts, $userId);
		$currentBalance = $this->balanceToday($accounts, $futureChanges, $rates);

		// Get historical averages (excluding extraordinary/one-time items so
		// scenario projections stay consistent with the main forecast, #270).
		$endDate = date('Y-m-d');
		$startDate = date('Y-m-d', strtotime('-6 months'));
		// Transfers between the user's accounts are neither income nor
		// spending, as in the main forecast
		$transactions = PatternAnalyzer::withoutInternalTransfers(array_filter(
			self::inScope($this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate), $rates),
			fn ($t) => !($t->getExcludedFromForecast() ?? false)
		));

		$monthlyIncome = 0.0;
		$monthlyExpenses = 0.0;
		$monthCount = [];

		foreach ($transactions as $transaction) {
			$month = date('Y-m', strtotime($transaction->getDate()));
			$monthCount[$month] = true;

			$amount = PatternAnalyzer::inForecastCurrency((float)$transaction->getAmount(), $transaction, $rates ?? []);
			if ($transaction->getType() === 'credit') {
				$monthlyIncome += $amount;
			} else {
				$monthlyExpenses += $amount;
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
		array $customScenarios = [],
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

		// Skip accounts flagged out of reports in all-accounts scenarios (#286)
		if ($accountId === null) {
			$accounts = array_values(array_filter($accounts, static fn ($a) => !$a->getExcludedFromReports()));
		}

		// Get future transaction adjustments to calculate balance as of today
		// (the user's: a purchase dated it is already in the stored balance)
		$today = $this->userClock?->today($userId) ?? date('Y-m-d');
		$futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

		$rates = $this->baseRates($accounts, $userId);
		$currentBalance = $this->balanceToday($accounts, $futureChanges, $rates);

		// Work backwards from current balance using transactions
		$balances = [];
		$endDate = date('Y-m-d');
		$startDate = date('Y-m-d', strtotime("-{$months} months"));
		$transactions = self::inScope($this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate), $rates);

		// Group transactions by month
		$monthlyChanges = [];
		foreach ($transactions as $transaction) {
			$month = date('Y-m', strtotime($transaction->getDate()));
			if (!isset($monthlyChanges[$month])) {
				$monthlyChanges[$month] = 0;
			}

			$amount = PatternAnalyzer::inForecastCurrency((float)$transaction->getAmount(), $transaction, $rates ?? []);
			if ($transaction->getType() === 'credit') {
				$monthlyChanges[$month] += $amount;
			} else {
				$monthlyChanges[$month] -= $amount;
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
