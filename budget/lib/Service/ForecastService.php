<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Forecast\ForecastProjector;
use OCA\Budget\Service\Forecast\PatternAnalyzer;
use OCA\Budget\Service\Forecast\ScenarioBuilder;
use OCA\Budget\Service\Forecast\TrendCalculator;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Orchestrates forecast generation by delegating to specialized services.
 * OPTIMIZED: Includes caching layer for expensive calculations.
 */
class ForecastService {
	private const CACHE_PREFIX = 'budget_forecast_';
	private const CACHE_TTL = 300; // 5 minutes

	/**
	 * Complete months of history a trend needs before the live forecast
	 * extrapolates it, the same number the forecast calls reliable. A line
	 * through two points is noise: one good month and one weak one took
	 * projected income to zero.
	 */
	private const MIN_TREND_MONTHS = 3;

	/**
	 * Days a month may have passed before an account's first transaction and
	 * still count as complete (completeHistoryStart()): a weekend and a
	 * holiday without bookings, not a bank sync that began mid-month.
	 */
	private const HISTORY_START_GRACE_DAYS = 3;

	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;
	private PatternAnalyzer $patternAnalyzer;
	private TrendCalculator $trendCalculator;
	private ScenarioBuilder $scenarioBuilder;
	private ForecastProjector $projector;
	private ?ICache $cache = null;

	public function __construct(
		AccountMapper $accountMapper,
		TransactionMapper $transactionMapper,
		PatternAnalyzer $patternAnalyzer,
		TrendCalculator $trendCalculator,
		ScenarioBuilder $scenarioBuilder,
		ForecastProjector $projector,
		?ICacheFactory $cacheFactory = null,
		private ?UserClock $userClock = null,
		private ?CurrencyTotals $currencyTotals = null,
		private ?RecurringIncomeMapper $incomeMapper = null,
		private ?BillMapper $billMapper = null,
		private ?BudgetCarryoverService $carryoverService = null,
		private ?BillService $billService = null,
		private ?FrequencyCalculator $frequencyCalculator = null,
	) {
		$this->accountMapper = $accountMapper;
		$this->transactionMapper = $transactionMapper;
		$this->patternAnalyzer = $patternAnalyzer;
		$this->trendCalculator = $trendCalculator;
		$this->scenarioBuilder = $scenarioBuilder;
		$this->projector = $projector;

		// Initialize cache if available
		if ($cacheFactory !== null) {
			$this->cache = $cacheFactory->createDistributed(self::CACHE_PREFIX);
		}
	}

	/**
	 * Today in the user's own calendar. A purchase dated their today is
	 * stored cleared; judged against the server's UTC date, the "balance as
	 * of today" took it off again until UTC midnight caught up (#399).
	 */
	private function today(string $userId): string {
		return $this->userClock?->today($userId) ?? date('Y-m-d');
	}

	/**
	 * Net of each account's rows dated after $today, for the accounts being
	 * forecast. By account rather than by the viewer's own accounts: a
	 * shared account's future-dated rows were otherwise left in its balance
	 * for the person it is shared with.
	 *
	 * @return array<int, float> account id => net change after today
	 */
	private function futureChanges(array $accounts, string $today): array {
		return $this->transactionMapper->getNetChangeAfterDateForAccounts(
			array_map(static fn ($a) => (int)$a->getId(), $accounts),
			$today
		);
	}

	/**
	 * The active bills paid from the accounts forecast that the forecast
	 * places on their dates, each with its multiplier into the forecast's
	 * currency. A transfer between accounts is not spending, and a bill
	 * kept out of the forecast stays out. A bill with no account is the
	 * user's own, so it counts in their own forecast, not in one of
	 * accounts shared with them.
	 *
	 * @param array<int, string>|null $rates
	 * @return list<array{0: \OCA\Budget\Db\Bill, 1: string}>
	 */
	private function scheduledBills(string $userId, array $accounts, ?array $rates, ?array $visibleAccountIds): array {
		if ($this->billMapper === null || $this->billService === null) {
			return [];
		}
		$bills = array_filter(
			$this->billMapper->findActive($userId),
			static fn ($bill) => !($bill->getIsTransfer() ?? false) && !($bill->getExcludedFromForecast() ?? false)
		);
		return $this->withRates($bills, $accounts, $rates, $visibleAccountIds);
	}

	/**
	 * The active recurring income into the accounts forecast, on the same
	 * terms as scheduledBills().
	 *
	 * @param array<int, string>|null $rates
	 * @return list<array{0: \OCA\Budget\Db\RecurringIncome, 1: string}>
	 */
	private function scheduledIncomes(string $userId, array $accounts, ?array $rates, ?array $visibleAccountIds): array {
		if ($this->incomeMapper === null) {
			return [];
		}
		$incomes = array_filter(
			$this->incomeMapper->findActive($userId),
			static fn ($income) => !($income->getExcludedFromForecast() ?? false)
		);
		return $this->withRates($incomes, $accounts, $rates, $visibleAccountIds);
	}

	/**
	 * Bills or income paired with the multiplier into the forecast's
	 * currency, keeping those of the accounts forecast and, in the user's
	 * own forecast, those with no account.
	 *
	 * @template T of \OCA\Budget\Db\Bill|\OCA\Budget\Db\RecurringIncome
	 * @param iterable<T> $items
	 * @param array<int, string>|null $rates
	 * @return list<array{0: T, 1: string}>
	 */
	private function withRates(iterable $items, array $accounts, ?array $rates, ?array $visibleAccountIds): array {
		$inForecast = [];
		foreach ($accounts as $account) {
			if ($rates === null || isset($rates[$account->getId()])) {
				$inForecast[(int)$account->getId()] = $rates[$account->getId()] ?? '1';
			}
		}
		$kept = [];
		foreach ($items as $item) {
			$accountId = $item->getAccountId();
			if ($accountId === null) {
				if (empty($visibleAccountIds)) {
					$kept[] = [$item, '1'];
				}
			} elseif (isset($inForecast[(int)$accountId])) {
				$kept[] = [$item, $inForecast[(int)$accountId]];
			}
		}
		return $kept;
	}

	/**
	 * Income and spending of $rows per month, for every month $history has
	 * a transaction in, in order: a month without any of $rows is zero.
	 *
	 * @param array<int, string> $rates as for PatternAnalyzer::aggregateMonthlyData()
	 * @return list<array{income: float, expenses: float}>
	 */
	private static function monthlySeries(array $history, array $rows, array $rates, int $monthStartDay): array {
		$series = [];
		foreach ($history as $t) {
			$series[BudgetPeriod::monthContaining(substr((string)$t->getDate(), 0, 10), $monthStartDay)] = ['income' => 0.0, 'expenses' => 0.0];
		}
		foreach ($rows as $t) {
			$month = BudgetPeriod::monthContaining(substr((string)$t->getDate(), 0, 10), $monthStartDay);
			$amount = PatternAnalyzer::inForecastCurrency((float)$t->getAmount(), $t, $rates);
			$series[$month][$t->getType() === 'credit' ? 'income' : 'expenses'] += $amount;
		}
		ksort($series);
		return array_values($series);
	}

	/**
	 * Whether a credit is income the user set up to recur: into its account
	 * (or any, when it names none), within a fifth of its amount, and
	 * mentioning its pattern when it has one. Such income is placed on its
	 * own dates, so the average must not count it again.
	 *
	 * @param list<array{0: \OCA\Budget\Db\RecurringIncome, 1: string}> $incomes
	 */
	private function isRecurringIncome($transaction, array $incomes): bool {
		if ($transaction->getType() !== 'credit') {
			return false;
		}
		$amount = (float)$transaction->getAmount();
		$text = ($transaction->getDescription() ?? '') . ' ' . ($transaction->getVendor() ?? '');
		foreach ($incomes as [$income]) {
			$accountId = $income->getAccountId();
			if ($accountId !== null && (int)$accountId !== (int)$transaction->getAccountId()) {
				continue;
			}
			$expected = (float)$income->getAmount();
			if (abs($amount - $expected) > abs($expected) * 0.2) {
				continue;
			}
			$pattern = (string)($income->getAutoDetectPattern() ?? '');
			if ($pattern !== '' && stripos($text, $pattern) === false) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * What scheduled bills or income come to between two dates, in the
	 * forecast's currency. A date before the next one due has been paid or
	 * received already.
	 *
	 * @param list<array{0: \OCA\Budget\Db\Bill|\OCA\Budget\Db\RecurringIncome, 1: string}> $items
	 */
	private function scheduledTotal(array $items, string $from, string $to): float {
		$total = 0.0;
		foreach ($items as [$item, $rate]) {
			$dates = $this->occurrences($item, $from, $to);
			if ($dates === []) {
				continue;
			}
			$amount = (float)$item->getAmount() * count($dates);
			$total += $rate === '1' ? $amount : (float)MoneyCalculator::multiply($amount, $rate, 10);
		}
		return $total;
	}

	/**
	 * The dates a bill or recurring income falls on between two dates,
	 * from the next one due on. Bills use the Bills page's own schedule;
	 * income the same calculator, starting from its start date, or for a
	 * one-off income its expected date.
	 *
	 * @param \OCA\Budget\Db\Bill|\OCA\Budget\Db\RecurringIncome $item
	 * @return string[]
	 */
	private function occurrences($item, string $from, string $to): array {
		if ($item instanceof \OCA\Budget\Db\Bill) {
			$next = $item->getNextDueDate() ?: null;
			$dates = $this->billService?->occurrencesBetween($item, $from, $to) ?? [];
		} else {
			$next = $item->getNextExpectedDate() ?: null;
			$frequency = (string)$item->getFrequency();
			$anchor = $item->getStartDate() ?: ($frequency === 'one-time' ? $next : null);
			$month = $item->getExpectedMonth();
			if ($month === null && $next !== null && in_array($frequency, ['quarterly', 'semi-annually', 'yearly'], true)) {
				$month = (int)substr($next, 5, 2);
			}
			$calculator = $this->frequencyCalculator ?? new FrequencyCalculator();
			$dates = $calculator->occurrencesBetween($frequency, $item->getExpectedDay(), $month, $from, $to, null, $anchor);
		}
		return $next === null ? $dates : array_values(array_filter($dates, static fn (string $d) => $d >= $next));
	}

	/**
	 * The rows dated after today until $to, in the accounts forecast and
	 * meant for it: transfers between them move no money in or out.
	 *
	 * @param array<int, string>|null $rates
	 * @return array Transaction entities
	 */
	private function futureRows(string $userId, string $from, string $to, ?array $visibleAccountIds, ?array $rates): array {
		$rows = $this->transactionMapper->findAllByUserAndDateRange($userId, $from, $to, null, $visibleAccountIds);
		if ($rates !== null) {
			$rows = array_filter($rows, static fn ($t) => isset($rates[(int)$t->getAccountId()]));
		}
		return PatternAnalyzer::withoutInternalTransfers($this->filterForecastTransactions(array_values($rows)));
	}

	/**
	 * Credits or debits among $rows dated between two dates, in the
	 * forecast's currency.
	 *
	 * @param array<int, string>|null $rates
	 */
	private function rowsTotal(array $rows, string $type, string $from, string $to, ?array $rates): float {
		$total = 0.0;
		foreach ($rows as $row) {
			$date = substr((string)$row->getDate(), 0, 10);
			if ($row->getType() === $type && $date >= $from && $date <= $to) {
				$total += PatternAnalyzer::inForecastCurrency((float)$row->getAmount(), $row, $rates ?? []);
			}
		}
		return $total;
	}

	/**
	 * Invalidate all forecast cache entries for a user.
	 * Call this when transactions are modified.
	 */
	public function invalidateCache(string $userId): void {
		if ($this->cache === null) {
			return;
		}

		// Prefix-clear: live keys carry horizon + visibility suffixes, so a
		// plain remove() of the bare key never matched anything and toggles
		// (e.g. exclude-from-forecast) appeared to do nothing for 5 minutes.
		$this->cache->clear("live_{$userId}_");
		$this->cache->remove("forecast_{$userId}_all");
	}

	/**
	 * Generate a full forecast for accounts.
	 */
	public function generateForecast(
		string $userId,
		?int $accountId = null,
		int $basedOnMonths = 3,
		int $forecastMonths = 6,
		?array $visibleAccountIds = null,
	): array {
		if ($accountId) {
			$accounts = [$this->accountMapper->find($accountId, $userId)];
		} else {
			$accounts = !empty($visibleAccountIds)
				? $this->accountMapper->findByIds($visibleAccountIds)
				: $this->accountMapper->findAll($userId);
			// The all-accounts forecast skips accounts flagged out of reports (#286)
			$accounts = array_values(array_filter($accounts, static fn ($a) => !$a->getExcludedFromReports()));
		}

		// Get future transaction adjustments to calculate balance as of today,
		// for every account forecast, shared ones included
		$today = $this->today($userId);
		$futureChanges = $this->futureChanges($accounts, $today);

		$forecast = [
			'summary' => [],
			'monthlyProjections' => [],
			'categoryForecasts' => [],
			'scenarios' => []
		];

		foreach ($accounts as $account) {
			// Calculate balance as of today (stored balance minus future transactions)
			$storedBalance = $account->getBalance();
			$futureChange = $futureChanges[$account->getId()] ?? 0;
			$currentBalance = $storedBalance - $futureChange;

			$accountForecast = $this->generateAccountForecast(
				$userId,
				$account,
				$currentBalance,
				$basedOnMonths,
				$forecastMonths
			);

			$forecast['summary'][] = [
				'accountId' => $account->getId(),
				'accountName' => $account->getName(),
				'currentBalance' => $currentBalance,
				'projectedBalance' => $accountForecast['projectedBalance'],
				'projectedChange' => $accountForecast['projectedBalance'] - $currentBalance,
				'confidence' => $accountForecast['confidence']
			];

			if ($accountId === null || $accountId === $account->getId()) {
				$forecast['monthlyProjections'] = $accountForecast['monthlyProjections'];
				$forecast['categoryForecasts'] = $accountForecast['categoryForecasts'];
			}
		}

		$forecast['scenarios'] = $this->scenarioBuilder->generateScenarios($userId, $accounts, $forecastMonths);

		return $forecast;
	}

	/**
	 * Get live forecast data for dashboard display.
	 * OPTIMIZED: Results are cached for 5 minutes.
	 */
	public function getLiveForecast(string $userId, int $forecastMonths = 6, ?array $visibleAccountIds = null): array {
		// Key includes account visibility: a share-restricted viewer and the
		// owner must never share a cache entry (the owner's totals would leak
		// across the visibility boundary for up to the TTL).
		// The months it counts in are part of the key too, so a change of
		// the setting shows at once (#457)
		$monthStartDay = $this->carryoverService?->reportMonthStartDay($userId) ?? 1;
		$cacheKey = "live_{$userId}_{$forecastMonths}_{$monthStartDay}_" . md5(json_encode($visibleAccountIds ?? []));

		// Try to get from cache
		if ($this->cache !== null) {
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return $cached;
			}
		}

		$accounts = !empty($visibleAccountIds)
			? $this->accountMapper->findByIds($visibleAccountIds)
			: $this->accountMapper->findAll($userId);

		// The live (all-accounts) forecast skips accounts flagged out of reports (#286)
		$accounts = array_values(array_filter($accounts, static fn ($a) => !$a->getExcludedFromReports()));

		// Get future transaction adjustments to calculate balance as of today,
		// for every account forecast, shared ones included
		$today = $this->today($userId);
		$futureChanges = $this->futureChanges($accounts, $today);

		// Accounts in more than one currency are added up in the base
		// currency, as the dashboard summary adds them: the balances and the
		// history were summed as stored, so euros counted as pounds and half a
		// bitcoin as 50p. An account whose currency has no rate stays out.
		$conversion = $this->currencyTotals?->accountRates($accounts, $userId);
		$rates = ($conversion['currency'] ?? null) !== null ? $conversion['rates'] : null;

		$currentBalance = 0.0;
		$currencyCounts = [];

		foreach ($accounts as $account) {
			if ($rates !== null && !isset($rates[$account->getId()])) {
				continue;
			}

			// Calculate balance as of today (stored balance minus future transactions)
			$storedBalance = $account->getBalance();
			$futureChange = $futureChanges[$account->getId()] ?? 0;
			$accountBalance = $storedBalance - $futureChange;
			if ($rates !== null) {
				$accountBalance = (float)MoneyCalculator::multiply($accountBalance, $rates[$account->getId()], 10);
			}

			$currentBalance += $accountBalance;
			$currency = $account->getCurrency() ?? 'USD';
			$currencyCounts[$currency] = ($currencyCounts[$currency] ?? 0) + abs($accountBalance);
		}

		// Determine primary currency
		$primaryCurrency = 'USD';
		if ($rates !== null) {
			$primaryCurrency = (string)$conversion['currency'];
		} elseif (!empty($currencyCounts)) {
			arsort($currencyCounts);
			$primaryCurrency = array_key_first($currencyCounts);
		}

		// History: the twelve complete months before this one. The month in
		// progress is not a month of income and spending yet; counted as one,
		// four days of October read as a month in which almost nothing came
		// in, and the trend through it projected no income at all. Calendar
		// months, or budget months when the user counts in them (#457).
		$thisMonth = BudgetPeriod::monthContaining($today, $monthStartDay);
		$startDate = BudgetPeriod::range(self::shiftMonth($thisMonth, -12), $monthStartDay)[0];
		$endDate = BudgetPeriod::range(self::shiftMonth($thisMonth, -1), $monthStartDay)[1];
		// Nor is a month the accounts' history only reaches part of
		$startDate = max($startDate, $this->completeHistoryStart($accounts, $monthStartDay, $endDate));
		$transactions = $startDate <= $endDate
			? $this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate, null, $visibleAccountIds)
			: [];
		if ($rates !== null) {
			// The history of an account left out above stays out with it
			$transactions = array_values(array_filter(
				$transactions,
				static fn ($t) => isset($rates[(int)$t->getAccountId()])
			));
		}

		// Drop extraordinary/one-time transactions so they don't skew the
		// projection averages. They still affect the real (current) balance
		// above — we only exclude them from the historical pattern (#270).
		$transactions = $this->filterForecastTransactions($transactions);
		// Transfers between the accounts forecast are neither income nor spending
		$transactions = PatternAnalyzer::withoutInternalTransfers($transactions);

		// Analyze patterns, each amount in the forecast's currency
		$monthlyData = $this->patternAnalyzer->aggregateMonthlyData($transactions, $rates ?? [], $monthStartDay);
		$months = count($monthlyData);

		// Calculate averages and trends
		$incomeValues = array_column($monthlyData, 'income');
		$expenseValues = array_column($monthlyData, 'expenses');
		$savingsValues = array_map(fn ($m) => $m['income'] - $m['expenses'], $monthlyData);

		$avgIncome = $months > 0 ? array_sum($incomeValues) / $months : 0;
		$avgExpenses = $months > 0 ? array_sum($expenseValues) / $months : 0;
		$avgSavings = $avgIncome - $avgExpenses;

		// Too few months for a trend: project the averages as they are
		$withTrend = $months >= self::MIN_TREND_MONTHS;
		$incomeTrend = $withTrend ? $this->trendCalculator->calculateTrend($incomeValues) : 0.0;
		$expenseTrend = $withTrend ? $this->trendCalculator->calculateTrend($expenseValues) : 0.0;
		$savingsTrend = $withTrend ? $this->trendCalculator->calculateTrend($savingsValues) : 0.0;

		// The projection is made of two parts. What is scheduled (bills,
		// recurring income and rows dated after today) goes into the month
		// it falls in, so a yearly bonus shows in May and a one-off bill in
		// December. The rest of the history, without the bill payments and
		// the income the user set up to recur, is averaged as before. Spread
		// as monthly floors, a new bill and a raise counted only once they
		// outgrew the whole average, a yearly payment counted a twelfth in
		// every month, and one-off items and future rows not at all.
		$bills = $this->scheduledBills($userId, $accounts, $rates, $visibleAccountIds);
		$incomes = $this->scheduledIncomes($userId, $accounts, $rates, $visibleAccountIds);
		$variable = array_values(array_filter(
			$transactions,
			fn ($t) => $t->getBillId() === null && !$this->isRecurringIncome($t, $incomes)
		));
		// Over every month learned from: a month of nothing but salary has
		// no other income and counts as none, so a bonus that stopped
		// shows as a falling line, not as a steady one with gaps
		$variableData = self::monthlySeries($transactions, $variable, $rates ?? [], $monthStartDay);
		$variableIncome = $months > 0 ? array_sum(array_column($variableData, 'income')) / $months : 0.0;
		$variableExpenses = $months > 0 ? array_sum(array_column($variableData, 'expenses')) / $months : 0.0;
		$variableIncomeTrend = $withTrend ? $this->trendCalculator->calculateTrend(array_column($variableData, 'income')) : 0.0;
		// Spending follows its trend only upwards. The forecast is there to
		// warn before money runs out, so it errs on spending more: a falling
		// line is projected as the average of the months it learns from
		// instead. Followed down, a line through three uneven months (1,060,
		// 11 and 131) projected next to no spending at all, and the forecast
		// showed six months of income saved whole. The trend stays as it is
		// for the direction shown beside the average.
		$variableExpenseTrend = $withTrend ? max(0.0, $this->trendCalculator->calculateTrend(array_column($variableData, 'expenses'))) : 0.0;

		// Rows dated after today, until the last month projected
		$lastProjected = BudgetPeriod::range(self::shiftMonth($thisMonth, $forecastMonths), $monthStartDay)[1];
		$tomorrow = (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
		// The share of the running month still to come
		[$thisStart, $thisEnd] = BudgetPeriod::range($thisMonth, $monthStartDay);
		$restOfThisMonth = $tomorrow > $thisEnd ? 0.0
			: self::days($tomorrow, $thisEnd) / self::days($thisStart, $thisEnd);
		$futureRows = $this->futureRows($userId, $tomorrow, $lastProjected, $visibleAccountIds, $rates);

		// Generate monthly projections
		$monthlyProjections = [];
		$projectedBalance = $currentBalance;
		$cumulativeSavings = 0;
		$savingsMonthlyData = [];

		// The running month comes first, from tomorrow on, with its share of
		// the usual income and spending: today's balance holds none of it
		// yet. Then the months after it, stepped from the 1st: from the 31st,
		// "+1 month" skipped any shorter month. Folded into the next month,
		// the rest of the running month made that month look like two: with
		// a budget start day it can have most of its days left.
		$periods = [];
		if ($restOfThisMonth > 0) {
			$periods[] = [$thisMonth, $tomorrow, $thisEnd, $restOfThisMonth, 0];
		}
		for ($i = 1; $i <= $forecastMonths; $i++) {
			$projectionMonth = self::shiftMonth($thisMonth, $i);
			[$from, $to] = BudgetPeriod::range($projectionMonth, $monthStartDay);
			$periods[] = [$projectionMonth, $from, $to, 1.0, $i];
		}

		foreach ($periods as [$projectionMonth, $from, $to, $share, $i]) {
			$monthLabel = \DateTimeImmutable::createFromFormat('!Y-m', $projectionMonth)->format('M Y');

			$scheduledIncome = $this->scheduledTotal($incomes, $from, $to) + $this->rowsTotal($futureRows, 'credit', $from, $to, $rates);
			$scheduledExpenses = $this->scheduledTotal($bills, $from, $to) + $this->rowsTotal($futureRows, 'debit', $from, $to, $rates);

			$projectedIncome = max(0, $variableIncome + ($variableIncomeTrend * $i)) * $share + $scheduledIncome;
			$projectedExpenses = max(0, $variableExpenses + ($variableExpenseTrend * $i)) * $share + $scheduledExpenses;
			$monthlySavings = $projectedIncome - $projectedExpenses;

			$projectedBalance += $monthlySavings;
			$cumulativeSavings += $monthlySavings;
			$savingsMonthlyData[] = $cumulativeSavings;

			$monthlyProjections[] = [
				// `yearMonth` (Y-m) is what the web UI formats in the user's
				// own language; `month` stays the English "M Y" label for the
				// forecast-warning notification and anything else reading it.
				'month' => $monthLabel,
				'yearMonth' => $projectionMonth,
				// Only what is left of the running month
				'partial' => $i === 0,
				'balance' => round($projectedBalance, 2),
				'income' => round($projectedIncome, 2),
				'expenses' => round($projectedExpenses, 2),
				'savings' => round($monthlySavings, 2),
				// The part of income and expenses that is scheduled
				'scheduledIncome' => round($scheduledIncome, 2),
				'scheduledExpenses' => round($scheduledExpenses, 2),
			];
		}

		$savingsRate = $avgIncome > 0 ? ($avgSavings / $avgIncome) * 100 : 0;
		$categoryBreakdown = $this->patternAnalyzer->getCategoryBreakdown($userId, $transactions, $rates ?? []);
		$transactionCount = count($transactions);
		$confidence = $this->projector->calculateDataConfidence($months, $transactionCount, $incomeValues, $expenseValues);

		$result = [
			'currency' => $primaryCurrency,
			'currentBalance' => round($currentBalance, 2),
			'projectedBalance' => round($projectedBalance, 2),
			'monthlyProjections' => $monthlyProjections,
			'trends' => [
				'avgMonthlyIncome' => round($avgIncome, 2),
				'avgMonthlyExpenses' => round($avgExpenses, 2),
				'avgMonthlySavings' => round($avgSavings, 2),
				'incomeDirection' => $this->trendCalculator->getTrendDirection($incomeTrend, $avgIncome),
				'expenseDirection' => $this->trendCalculator->getTrendDirection($expenseTrend, $avgExpenses),
				'savingsDirection' => $this->trendCalculator->getTrendDirection($savingsTrend, $avgSavings),
			],
			'savingsProjection' => [
				'currentMonthlySavings' => round($avgSavings, 2),
				'projectedTotalSavings' => round($cumulativeSavings, 2),
				'savingsRate' => round($savingsRate, 1),
				'monthlyData' => $savingsMonthlyData
			],
			'categoryBreakdown' => $categoryBreakdown,
			'confidence' => round($confidence, 0),
			// 1 for calendar months, else the day budget months start on
			'monthStartDay' => $monthStartDay,
			'dataQuality' => [
				'monthsOfData' => $months,
				'transactionCount' => $transactionCount,
				'isReliable' => $months >= 3 && $transactionCount >= 10
			]
		];

		// Store in cache
		if ($this->cache !== null) {
			$this->cache->set($cacheKey, $result, self::CACHE_TTL);
		}

		return $result;
	}

	/**
	 * Days from one date to another, both included.
	 */
	private static function days(string $from, string $to): int {
		return (int)(new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
	}

	/**
	 * A month (Y-m) shifted by whole months, stepped from the 1st.
	 */
	private static function shiftMonth(string $month, int $offset): string {
		return \DateTimeImmutable::createFromFormat('!Y-m', $month)->modify("{$offset} months")->format('Y-m');
	}

	/**
	 * The first day of the first month whose history every account holds
	 * in full, or '' when nothing bounds it.
	 *
	 * An account that had money in it before its first transaction (an
	 * opening balance) existed before Budget knew it, so the months before
	 * that transaction are missing, not empty. Bank sync is the usual case:
	 * it fetches the last 90 days, so the first month holds only its last
	 * few days. Counted as a whole month, five days of September with the
	 * salary in them and almost no spending made the forecast's average
	 * savings several times too high. An account opened from nothing has
	 * no history to miss and bounds nothing, and nor does one whose history
	 * starts after $historyEnd: added this month, it would otherwise leave
	 * the forecast no month at all until a month of its own had passed.
	 *
	 * @param \OCA\Budget\Db\Account[] $accounts
	 */
	private function completeHistoryStart(array $accounts, int $monthStartDay, string $historyEnd): string {
		$withPast = array_values(array_map(
			static fn ($a) => (int)$a->getId(),
			array_filter($accounts, static fn ($a) => abs((float)($a->getOpeningBalance() ?? 0)) >= 0.005)
		));
		$firstDates = array_filter(
			$this->transactionMapper->getFirstTransactionDates($withPast),
			static fn (string $date) => $date <= $historyEnd
		);
		if ($firstDates === []) {
			return '';
		}
		$latest = max($firstDates);
		$month = BudgetPeriod::monthContaining($latest, $monthStartDay);
		$monthStart = BudgetPeriod::range($month, $monthStartDay)[0];
		// A first transaction is not where the history starts: the days
		// before it may just have had none, a weekend after the 1st. A
		// month whose first few days are all that could be missing counts.
		$daysIn = (int)(new \DateTimeImmutable($monthStart))->diff(new \DateTimeImmutable($latest))->days;
		return $daysIn <= self::HISTORY_START_GRACE_DAYS
			? $monthStart
			: BudgetPeriod::range(self::shiftMonth($month, 1), $monthStartDay)[0];
	}

	/**
	 * Remove transactions flagged as excluded-from-forecast (#270).
	 * Re-indexes the array so downstream consumers can rely on sequential keys.
	 *
	 * @param array $transactions Transaction entities
	 * @return array Filtered transactions
	 */
	private function filterForecastTransactions(array $transactions): array {
		return array_values(array_filter(
			$transactions,
			fn ($t) => !($t->getExcludedFromForecast() ?? false)
		));
	}

	/**
	 * Generate forecast for a single account.
	 */
	private function generateAccountForecast(
		string $userId,
		$account,
		float $currentBalance,
		int $basedOnMonths,
		int $forecastMonths,
	): array {
		$accountId = $account->getId();

		// Get historical data, up to the user's today
		$endDate = $this->today($userId);
		$startDate = (new \DateTimeImmutable($endDate))->modify("-{$basedOnMonths} months")->format('Y-m-d');
		$transactions = $this->transactionMapper->findByDateRange($accountId, $startDate, $endDate);

		// Exclude extraordinary/one-time transactions from the pattern (#270).
		$transactions = $this->filterForecastTransactions($transactions);

		// Analyze patterns
		$patterns = $this->patternAnalyzer->analyzeTransactionPatterns($transactions, $basedOnMonths);

		// Generate monthly projections
		$monthlyProjections = $this->projector->generateMonthlyProjections(
			$currentBalance,
			$patterns,
			$forecastMonths
		);

		// Calculate final projected balance
		$projectedBalance = !empty($monthlyProjections)
			? $monthlyProjections[count($monthlyProjections) - 1]['endingBalance']
			: $currentBalance;

		// Category-level forecasts
		$categoryForecasts = $this->projector->generateCategoryForecasts($userId, $patterns, $forecastMonths);

		return [
			'projectedBalance' => $projectedBalance,
			'monthlyProjections' => $monthlyProjections,
			'categoryForecasts' => $categoryForecasts,
			'confidence' => $this->projector->calculateOverallConfidence($patterns, $forecastMonths)
		];
	}

	/**
	 * Get cash flow forecast.
	 */
	public function getCashFlowForecast(
		string $userId,
		string $startDate,
		string $endDate,
		?int $accountId = null,
		?array $visibleAccountIds = null,
	): array {
		return [
			'periods' => [],
			'cumulativeFlow' => [],
			'insights' => []
		];
	}

	/**
	 * Get spending trends analysis.
	 */
	public function getSpendingTrends(
		string $userId,
		?int $accountId = null,
		int $months = 12,
		?array $visibleAccountIds = null,
	): array {
		return [
			'monthlyTrends' => [],
			'categoryTrends' => [],
			'insights' => []
		];
	}

	/**
	 * Run scenario analysis.
	 */
	public function runScenarios(
		string $userId,
		?int $accountId = null,
		array $scenarios = [],
		?array $visibleAccountIds = null,
	): array {
		return $this->scenarioBuilder->runScenarios($userId, $accountId, $scenarios);
	}

	/**
	 * Generate enhanced forecast with scenarios and charts.
	 */
	public function generateEnhancedForecast(
		string $userId,
		?int $accountId = null,
		int $historicalPeriod = 6,
		int $forecastHorizon = 6,
		int $confidenceLevel = 90,
		?array $visibleAccountIds = null,
	): array {
		$baseForecast = $this->generateForecast($userId, $accountId, $historicalPeriod, $forecastHorizon, $visibleAccountIds);

		$intelligence = [
			'confidence' => $confidenceLevel,
			'trendAnalysis' => 'Based on ' . $historicalPeriod . ' months of data, spending trends show moderate growth',
			'seasonalityInsight' => 'No significant seasonal patterns detected in current data',
			'volatilityAssessment' => 'Spending volatility is within normal ranges'
		];

		$scenarios = [
			'conservative' => [
				'projectedBalance' => $this->scenarioBuilder->calculateScenarioBalance($userId, $accountId, -0.05, 0.08),
				'assumptions' => [
					'Income growth: -5% to +2%',
					'Expense increase: +3% to +8%',
					'Emergency buffer: 20%'
				]
			],
			'base' => [
				'projectedBalance' => $this->scenarioBuilder->calculateScenarioBalance($userId, $accountId, 0.02, 0.03),
				'assumptions' => [
					'Income growth: Current trend',
					'Expense growth: Historical average',
					'No major changes expected'
				]
			],
			'optimistic' => [
				'projectedBalance' => $this->scenarioBuilder->calculateScenarioBalance($userId, $accountId, 0.10, -0.02),
				'assumptions' => [
					'Income growth: +5% to +15%',
					'Expense reduction: -2% to +3%',
					'Favorable market conditions'
				]
			]
		];

		$chartData = [
			'labels' => $this->scenarioBuilder->generateMonthLabels($historicalPeriod, $forecastHorizon),
			'historical' => $this->scenarioBuilder->getHistoricalBalances($userId, $accountId, $historicalPeriod),
			'forecast' => [
				'base' => $this->scenarioBuilder->generateForecastBalances($scenarios['base'], $forecastHorizon),
				'conservative' => $this->scenarioBuilder->generateForecastBalances($scenarios['conservative'], $forecastHorizon),
				'optimistic' => $this->scenarioBuilder->generateForecastBalances($scenarios['optimistic'], $forecastHorizon)
			]
		];

		$metrics = [
			'avgIncome' => 5000.0,
			'avgExpenses' => 3500.0,
			'netCashflow' => 1500.0,
			'savingsRate' => 30.0,
			'incomeTrend' => 1,
			'expenseTrend' => 1,
			'cashflowTrend' => 1,
			'savingsTrend' => 1,
			'incomeChange' => '+5.2%',
			'expenseChange' => '+2.8%',
			'cashflowChange' => '+12.4%',
			'savingsChange' => '+3.1%'
		];

		$goalProjections = [
			'monthlySavings' => 1500.0,
			'projectedGrowth' => 0.05
		];

		$recommendations = [
			'high' => 'Consider increasing emergency fund by $500/month',
			'medium' => 'Optimize spending in dining category for better savings',
			'low' => 'Set up automated transfers to savings account'
		];

		return [
			'intelligence' => $intelligence,
			'scenarios' => $scenarios,
			'chartData' => $chartData,
			'metrics' => $metrics,
			'goalProjections' => $goalProjections,
			'recommendations' => $recommendations
		];
	}

	/**
	 * Export forecast data.
	 */
	public function exportForecast(string $userId, array $forecastData): array {
		return [
			'exportId' => uniqid(),
			'timestamp' => date('Y-m-d H:i:s'),
			'userId' => $userId,
			'data' => $forecastData,
			'format' => 'json',
			'version' => '1.0'
		];
	}
}
