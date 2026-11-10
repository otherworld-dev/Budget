<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Runway;

use OCA\Budget\Enum\Frequency;
use OCA\Budget\Service\MoneyCalculator;

/**
 * The runway maths: how long the chosen accounts' money lasts at the recent
 * average drain, and at that drain with budgets swapped in.
 *
 * Pure (no database, no clock) so every rule can be pinned by a unit test.
 * RunwayService gathers the inputs, already converted to the base currency.
 */
class RunwayCalculator {
	/** No zero within this many months counts as not running out */
	private const CAP_MONTHS = 120;
	/** How many month ends the history chart goes back */
	private const HISTORY_MONTHS = 12;
	private const DAYS_PER_MONTH = 365.25 / 12;
	/** Sums and averages keep this scale; output is rounded to 2 places only at the end */
	private const SCALE = 6;

	/**
	 * @param array{
	 *     today: string, total: string, chosenAccountIds: int[], windowMonths: int,
	 *     firstActivityDate: ?string, rows: RunwayRow[], historyRows: RunwayRow[],
	 *     futureRows: RunwayRow[], includeScheduled: bool, bills: array<int, RunwayBill>,
	 *     oneOffs: list<array{id: int, description: string, amount: string, date: string}>,
	 *     budgets: ?array<int, string>
	 * } $in
	 */
	public function calculate(array $in): array {
		$today = new \DateTimeImmutable($in['today']);
		$todayDate = $today->format('Y-m-d');
		$total = MoneyCalculator::add($in['total'], '0', self::SCALE);
		$windowMonths = (int)$in['windowMonths'];

		$monthStart = $today->modify('first day of this month');
		$from = $monthStart->modify("-{$windowMonths} months");
		$to = $monthStart->modify('-1 day');
		$effectiveMonths = $this->effectiveMonths($from, $windowMonths, $in['firstActivityDate'] ?? null);

		$actuals = $this->windowActuals($in, $from->format('Y-m-d'), $to->format('Y-m-d'), $effectiveMonths);
		$monthlyOut = $this->perMonth(MoneyCalculator::negate($actuals['out'], self::SCALE), $effectiveMonths);
		$monthlyIn = $this->perMonth($actuals['in'], $effectiveMonths);
		$currentDrain = MoneyCalculator::subtract($monthlyOut, $monthlyIn);

		$budgets = $in['budgets'] ?? [];
		$onBudgetDrain = $budgets === [] ? null : $this->onBudgetDrain($currentDrain, $actuals['byCategory'], $budgets, $effectiveMonths);

		$events = $this->events($in, $todayDate);
		$eventTotals = [];
		foreach ($events as $event) {
			$eventTotals[$event['date']] = MoneyCalculator::add($eventTotals[$event['date']] ?? '0', $event['amount'], self::SCALE);
		}

		$atZero = MoneyCalculator::compare($total, '0') <= 0;
		[$current, $currentSeries] = $effectiveMonths === 0
			? [$this->scenario('insufficient_data'), []]
			: $this->scenarioFor($currentDrain, $total, $atZero, $eventTotals, $today);
		if ($effectiveMonths === 0) {
			[$onBudget, $onBudgetSeries] = [$this->scenario('insufficient_data'), []];
		} elseif ($onBudgetDrain === null) {
			[$onBudget, $onBudgetSeries] = [$this->scenario('unavailable'), []];
		} else {
			[$onBudget, $onBudgetSeries] = $this->scenarioFor($onBudgetDrain, $total, $atZero, $eventTotals, $today);
		}

		$differenceMonths = $current['state'] === 'running_out' && $onBudget['state'] === 'running_out'
			? round($onBudget['months'] - $current['months'], 1)
			: null;

		return [
			'window' => [
				'months' => $windowMonths,
				'effectiveMonths' => $effectiveMonths,
				'from' => $from->format('Y-m-d'),
				'to' => $to->format('Y-m-d'),
			],
			'monthlyOut' => $monthlyOut,
			'monthlyIn' => $monthlyIn,
			'excluded' => [
				'count' => count($actuals['excludedIds']),
				'total' => self::round2(MoneyCalculator::negate($actuals['excludedSum'], self::SCALE)),
				'ids' => $actuals['excludedIds'],
			],
			'scenarios' => ['current' => $current, 'onBudget' => $onBudget],
			'differenceMonths' => $differenceMonths,
			'series' => [
				'history' => $this->history($in['historyRows'] ?? [], $total, $monthStart, $todayDate),
				'current' => $currentSeries,
				'onBudget' => $onBudgetSeries,
			],
			'events' => $events,
		];
	}

	/**
	 * Window months from the first one with any activity on the chosen
	 * accounts, so a user with two months of history isn't averaged over three.
	 */
	private function effectiveMonths(\DateTimeImmutable $from, int $windowMonths, ?string $firstActivityDate): int {
		if ($firstActivityDate === null || $firstActivityDate === '') {
			return 0;
		}
		$firstMonth = (new \DateTimeImmutable($firstActivityDate))->modify('first day of this month')->format('Y-m-d');
		$count = 0;
		for ($i = 0; $i < $windowMonths; $i++) {
			if ($from->modify("+{$i} months")->format('Y-m-d') >= $firstMonth) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * A bill that comes round less than once per window would be missing
	 * from the average, or counted several times over while it sits in it.
	 */
	private function isLongPeriod(RunwayBill $bill, int $windowMonths): bool {
		$frequency = Frequency::tryFrom($bill->frequency);
		if ($frequency === null || $frequency === Frequency::ONE_TIME || $frequency === Frequency::CUSTOM) {
			return false;
		}
		// 12 / occurrences > window, kept in integers
		return $windowMonths * $frequency->occurrencesPerYear() < 12;
	}

	/**
	 * Window totals (signed, whole window) of the rows that count, with
	 * long-period bills swapped for their monthly equivalent.
	 *
	 * @return array{out: string, in: string, byCategory: array<int, string>, excludedSum: string, excludedIds: int[]}
	 */
	private function windowActuals(array $in, string $from, string $to, int $effectiveMonths): array {
		$chosen = array_map('intval', $in['chosenAccountIds'] ?? []);
		$longPeriod = array_filter(
			$in['bills'] ?? [],
			fn (RunwayBill $bill) => $this->isLongPeriod($bill, (int)$in['windowMonths']),
		);
		$totals = ['out' => '0', 'in' => '0', 'byCategory' => [], 'excludedSum' => '0', 'excludedIds' => []];

		foreach ($in['rows'] ?? [] as $row) {
			if ($row->date < $from || $row->date > $to || $row->status === 'scheduled') {
				continue;
			}
			if ($row->excludedFromForecast) {
				$totals['excludedSum'] = MoneyCalculator::add($totals['excludedSum'], $row->amount, self::SCALE);
				$totals['excludedIds'][] = $row->id;
				continue;
			}
			// An internal transfer only moves money between chosen accounts. Checking the
			// partner's account, not whether the partner is in rows, keeps a transfer whose
			// other leg falls just outside the window from counting as one-sided.
			if ($row->pensionLeg
				|| ($row->linkedAccountId !== null && in_array($row->linkedAccountId, $chosen, true))
				|| ($row->billId !== null && isset($longPeriod[$row->billId]))) {
				continue;
			}
			$this->addToTotals($totals, $row->amount);
			if ($row->splits !== []) {
				foreach ($row->splits as $split) {
					$this->addToCategory($totals['byCategory'], $split['categoryId'], $split['amount']);
				}
			} else {
				$this->addToCategory($totals['byCategory'], $row->categoryId, $row->amount);
			}
		}

		// Long-period bills cost exactly their monthly equivalent in every
		// counted month, paid in the window or not
		foreach ($longPeriod as $bill) {
			$smoothed = MoneyCalculator::negate(MoneyCalculator::multiply($bill->monthlyAmount, (string)$effectiveMonths, self::SCALE), self::SCALE);
			$this->addToTotals($totals, $smoothed);
			$this->addToCategory($totals['byCategory'], $bill->categoryId, $smoothed);
		}

		return $totals;
	}

	private function addToTotals(array &$totals, string $amount): void {
		$key = MoneyCalculator::compare($amount, '0', self::SCALE) < 0 ? 'out' : 'in';
		$totals[$key] = MoneyCalculator::add($totals[$key], $amount, self::SCALE);
	}

	/**
	 * Only budgeted categories are ever swapped, so uncategorised amounts
	 * (null) aren't tracked: they stay inside the actual totals as they are.
	 */
	private function addToCategory(array &$byCategory, ?int $categoryId, string $amount): void {
		if ($categoryId === null) {
			return;
		}
		$byCategory[$categoryId] = MoneyCalculator::add($byCategory[$categoryId] ?? '0', $amount, self::SCALE);
	}

	private function perMonth(string $windowTotal, int $effectiveMonths): string {
		if ($effectiveMonths === 0) {
			return '0.00';
		}
		return self::round2(MoneyCalculator::divide($windowTotal, (string)$effectiveMonths, self::SCALE));
	}

	/**
	 * Actual spending with each budgeted category's average replaced by its
	 * monthly budget. A category under budget is swapped up, on purpose: it
	 * answers "what if I spent my full budgets".
	 *
	 * @param array<int, string> $byCategory Signed window totals
	 * @param array<int, string> $budgets Monthly budget per category
	 */
	private function onBudgetDrain(string $currentDrain, array $byCategory, array $budgets, int $effectiveMonths): ?string {
		if ($effectiveMonths === 0) {
			return null;
		}
		$budgetedNetOut = '0';
		$budgetTotal = '0';
		foreach ($budgets as $categoryId => $monthly) {
			$budgetedNetOut = MoneyCalculator::subtract($budgetedNetOut, $byCategory[$categoryId] ?? '0', self::SCALE);
			$budgetTotal = MoneyCalculator::add($budgetTotal, $monthly, self::SCALE);
		}
		$budgetedActual = MoneyCalculator::divide($budgetedNetOut, (string)$effectiveMonths, self::SCALE);
		return self::round2(MoneyCalculator::add(MoneyCalculator::subtract($currentDrain, $budgetedActual, self::SCALE), $budgetTotal, self::SCALE));
	}

	/**
	 * One-offs from today on, plus future rows when scheduled payments are
	 * included. A recurring bill's pre-booking is left out: that bill is
	 * already in the average (as actual rows or smoothed), so applying it
	 * again would count it twice. A one-time bill is in no average, so its
	 * row is applied.
	 *
	 * @return list<array{date: string, amount: string, description: string, source: string}>
	 */
	private function events(array $in, string $today): array {
		$events = [];
		foreach ($in['oneOffs'] ?? [] as $oneOff) {
			if ($oneOff['date'] >= $today) {
				$events[] = [
					'date' => $oneOff['date'],
					'amount' => MoneyCalculator::add($oneOff['amount'], '0'),
					'description' => $oneOff['description'],
					'source' => 'oneOff',
				];
			}
		}

		if ($in['includeScheduled'] ?? false) {
			$bills = $in['bills'] ?? [];
			foreach ($in['futureRows'] ?? [] as $row) {
				// Today's rows are already in the total
				if ($row->date <= $today) {
					continue;
				}
				$bill = $row->billId !== null ? ($bills[$row->billId] ?? null) : null;
				if ($bill !== null && $bill->frequency !== Frequency::ONE_TIME->value) {
					continue;
				}
				$events[] = [
					'date' => $row->date,
					'amount' => MoneyCalculator::add($row->amount, '0'),
					'description' => '',
					'source' => 'scheduled',
				];
			}
		}

		usort($events, fn (array $a, array $b) => strcmp($a['date'], $b['date']));
		return $events;
	}

	/**
	 * @param array<string, string> $eventTotals Net event amount per date
	 * @return array{0: array, 1: list<array{date: string, total: string}>} The scenario and its series
	 */
	private function scenarioFor(string $drain, string $total, bool $atZero, array $eventTotals, \DateTimeImmutable $today): array {
		if ($atZero) {
			return [$this->scenario('at_zero', $drain), []];
		}
		[$runsOutOn, $days, $series] = $this->project((float)$total, $drain, $eventTotals, $today);
		if ($runsOutOn === null) {
			return [$this->scenario('not_running_out', $drain), $series];
		}
		return [$this->scenario('running_out', $drain, round($days / self::DAYS_PER_MONTH, 1), $runsOutOn), $series];
	}

	private function scenario(string $state, ?string $drain = null, ?float $months = null, ?string $runsOutOn = null): array {
		return ['state' => $state, 'monthlyDrain' => $drain, 'months' => $months, 'runsOutOn' => $runsOutOn];
	}

	/**
	 * Steps a day at a time from today, spreading the monthly drain evenly
	 * over the days and applying events on their dates, until the total
	 * reaches zero or the cap. Each day's balance is worked out from the
	 * start rather than carried forward, so float error can't build up.
	 *
	 * @return array{0: ?string, 1: ?int, 2: list<array{date: string, total: string}>} Run-out date, days to it, series
	 */
	private function project(float $start, string $drain, array $eventTotals, \DateTimeImmutable $today): array {
		$daily = (float)$drain * 12 / 365.25;
		$cap = $today->modify('+' . self::CAP_MONTHS . ' months')->format('Y-m-d');
		$applied = '0';
		$series = [];

		for ($day = $today, $n = 0; ; $day = $day->modify('+1 day'), $n++) {
			$date = $day->format('Y-m-d');
			$isEvent = isset($eventTotals[$date]);
			if ($isEvent) {
				$applied = MoneyCalculator::add($applied, $eventTotals[$date], self::SCALE);
			}
			$balance = round($start + (float)$applied - $daily * $n, 2);
			if ($balance <= 0) {
				$series[] = $this->point($date, $balance);
				return [$date, $n, $series];
			}
			if ($n === 0 || $day->format('j') === '1' || $isEvent || $date === $cap) {
				$series[] = $this->point($date, $balance);
			}
			if ($date >= $cap) {
				return [null, null, $series];
			}
		}
	}

	private function point(string $date, float $total): array {
		// round() can leave -0.0, which would print as "-0.00"
		return ['date' => $date, 'total' => number_format($total == 0.0 ? 0.0 : $total, 2, '.', '')];
	}

	/**
	 * Month-end totals for the last 12 months plus today, rebuilt backwards
	 * from today's total. Every non-scheduled row counts, excluded rows and
	 * transfers included, because this is the true balance.
	 *
	 * @param RunwayRow[] $historyRows
	 * @return list<array{date: string, total: string}>
	 */
	private function history(array $historyRows, string $total, \DateTimeImmutable $monthStart, string $today): array {
		$rows = array_filter($historyRows, fn (RunwayRow $row) => $row->status !== 'scheduled' && $row->date <= $today);
		$dates = [];
		for ($i = self::HISTORY_MONTHS; $i >= 1; $i--) {
			$dates[] = $monthStart->modify("-{$i} months")->modify('last day of this month')->format('Y-m-d');
		}
		$dates[] = $today;

		$history = [];
		foreach ($dates as $date) {
			$balance = $total;
			foreach ($rows as $row) {
				if ($row->date > $date) {
					$balance = MoneyCalculator::subtract($balance, $row->amount, self::SCALE);
				}
			}
			$history[] = ['date' => $date, 'total' => self::round2($balance)];
		}
		return $history;
	}

	/**
	 * Half away from zero to 2 places. bcmath truncates, so add half a cent
	 * in the value's direction first.
	 */
	private static function round2(string $value): string {
		$half = MoneyCalculator::compare($value, '0', self::SCALE) < 0 ? '-0.005' : '0.005';
		$rounded = MoneyCalculator::add($value, $half, 2);
		return MoneyCalculator::compare($rounded, '0') === 0 ? '0.00' : $rounded;
	}
}
