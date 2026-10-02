<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill as BillEntity;

/**
 * Computes the predictable monthly amount each category is committed to through
 * active recurring bills and recurring income (#269).
 *
 * The budget view uses these figures as an automatic fallback: a (sub-)category
 * with no manually-set budget shows its committed recurring total as the limit,
 * so the "fixed" part of a budget is derived from the recurring items while the
 * "variable" part can still be typed in manually (which always wins).
 *
 * Amounts are normalized to a monthly figure regardless of the item's
 * frequency; the frontend converts that to the category's budget period.
 *
 * A bill only budgets what the same budget will count as spent once it is
 * paid: its payments must land in an account the user's spending is
 * measured over, and a transfer must take the money out of that scope.
 * Otherwise the category stays fully "remaining" every month, however
 * faithfully the bill is paid.
 */
class RecurringBudgetService {
	private BillService $billService;
	private RecurringIncomeService $recurringIncomeService;
	private Bill\FrequencyCalculator $frequencyCalculator;

	public function __construct(
		BillService $billService,
		RecurringIncomeService $recurringIncomeService,
		Bill\FrequencyCalculator $frequencyCalculator,
		private ?AccountMapper $accountMapper = null,
		private ?GranularShareService $granularShareService = null,
		private ?SettingService $settingService = null,
	) {
		$this->billService = $billService;
		$this->recurringIncomeService = $recurringIncomeService;
		$this->frequencyCalculator = $frequencyCalculator;
	}

	/**
	 * Monthly-normalized recurring total per category id.
	 *
	 * Bills are summed into their category (split-template bills distribute to
	 * each split's category); recurring income is summed into its category.
	 * A category the user shared also counts the bills the people it is
	 * shared with file into it, since their payments count as its spending.
	 *
	 * @param string $userId
	 * @param string|null $month the budget month (Y-m) being budgeted; the
	 *                           current one when null. A month is budgeted from
	 *                           the bills running in it, not today's: an
	 *                           ending bill no longer counts for the months
	 *                           after it, and its replacement counts from the
	 *                           month it starts in.
	 * @return array<int, float> categoryId => monthly amount
	 */
	public function getMonthlyBudgetsByCategory(string $userId, ?string $month = null): array {
		$totals = [];
		[$from, $to] = $this->monthRange($userId, $month);
		$scope = $this->spendingScope($userId);

		// The user's own bills count in whatever category they file into;
		// another user's only in the categories this user shared with them
		$sources = [[$userId, null]];
		foreach ($this->granularShareService?->getCategoryShareRecipients($userId) ?? [] as $recipient => $categoryIds) {
			$sources[] = [(string)$recipient, array_flip($categoryIds)];
		}

		foreach ($sources as [$owner, $allowed]) {
			foreach ($this->billsRunningIn($owner, $from, $to) as $bill) {
				if (!$this->paymentsCountAsSpent($bill, $scope)) {
					continue;
				}
				$factor = $this->monthlyFactor($bill->getFrequency(), $bill->getCustomRecurrencePattern());
				if ($factor == 0.0) {
					continue; // e.g. one-time bills: not a recurring commitment
				}
				$splits = $bill->getSplitTemplateArray();
				$parts = !empty($splits)
					? array_map(static fn (array $split) => [
						isset($split['categoryId']) ? (int)$split['categoryId'] : 0,
						isset($split['amount']) ? (float)$split['amount'] : 0.0,
					], $splits)
					: [[(int)($bill->getCategoryId() ?? 0), (float)$bill->getAmount()]];
				foreach ($parts as [$catId, $amount]) {
					if ($catId <= 0 || $amount == 0.0 || ($allowed !== null && !isset($allowed[$catId]))) {
						continue;
					}
					$totals[$catId] = ($totals[$catId] ?? 0.0) + $amount * $factor;
				}
			}
		}

		foreach ($this->recurringIncomeService->findActive($userId) as $income) {
			if (!$income->getCategoryId()) {
				continue;
			}
			if ($income->getStartDate() !== null && $income->getStartDate() > $to) {
				continue; // starts after the month
			}
			$factor = $this->monthlyFactor($income->getFrequency());
			if ($factor == 0.0) {
				continue;
			}
			$catId = (int)$income->getCategoryId();
			$totals[$catId] = ($totals[$catId] ?? 0.0) + (float)$income->getAmount() * $factor;
		}

		foreach ($totals as $id => $amount) {
			$totals[$id] = round($amount, 2);
		}

		return $totals;
	}

	/**
	 * getMonthlyBudgetsByCategory() as $userId's Budget page shows it: a
	 * category shared with them carries its OWNER's figure, the one its
	 * available amount and Remaining are worked out from. The page showed
	 * the viewer's own bills in it as "auto" beside a Remaining measured
	 * against the owner's, so the row contradicted itself.
	 *
	 * @return array<int, float> categoryId => monthly amount
	 */
	public function getMonthlyBudgetsForViewer(string $userId, ?string $month = null): array {
		$budgets = $this->getMonthlyBudgetsByCategory($userId, $month);

		$idsByOwner = [];
		foreach ($this->granularShareService?->getSharedCategories($userId) ?? [] as $category) {
			$idsByOwner[$category['userId']][] = (int)$category['id'];
		}
		foreach ($idsByOwner as $owner => $ids) {
			$ownerBudgets = $this->getMonthlyBudgetsByCategory((string)$owner, $month);
			foreach ($ids as $id) {
				unset($budgets[$id]);
				if (isset($ownerBudgets[$id])) {
					$budgets[$id] = $ownerBudgets[$id];
				}
			}
		}

		return $budgets;
	}

	/**
	 * The first and last day of the budget month, by the user's start day.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function monthRange(string $userId, ?string $month): array {
		$startDay = max(1, min(31, (int)($this->settingService?->get($userId, 'budget_start_day') ?? 1)));
		$month ??= BudgetPeriod::monthContaining(date('Y-m-d'), $startDay);
		return BudgetPeriod::range($month, $startDay);
	}

	/**
	 * The bills of $owner's that run in the month: active ones whose start
	 * and end dates take in any of it (#268's "end bill A, start replacement
	 * B" no longer doubles up, and a bill starting later this month counts
	 * this month), plus an ended bill whose last payment was made in it -
	 * paying the last occurrence switches a bill off, which took its amount
	 * out of the very month it was paid in.
	 *
	 * @return BillEntity[]
	 */
	private function billsRunningIn(string $owner, string $from, string $to): array {
		$running = array_filter(
			$this->billService->findActive($owner),
			static fn (BillEntity $bill) => ($bill->getStartDate() === null || $bill->getStartDate() <= $to)
				&& ($bill->getEndDate() === null || $bill->getEndDate() >= $from)
		);
		$finished = array_filter(
			$this->billService->findByType($owner, null, false),
			static fn (BillEntity $bill) => $bill->getEndDate() !== null
				&& $bill->getLastPaidDate() !== null
				&& $bill->getLastPaidDate() >= $from && $bill->getLastPaidDate() <= $to
		);
		return array_merge(array_values($running), array_values($finished));
	}

	/**
	 * The accounts whose payments count as the user's spending: the ones
	 * the Budget page and alerts measure (own and shared with them), less
	 * any left out of reports. Null when that can't be known, which counts
	 * every bill as before.
	 *
	 * @return array<int, true>|null
	 */
	private function spendingScope(string $userId): ?array {
		if ($this->accountMapper === null) {
			return null;
		}
		$accounts = $this->granularShareService !== null
			? $this->accountMapper->findByIds($this->granularShareService->getVisibleAccountIds($userId))
			: $this->accountMapper->findAll($userId);
		$scope = [];
		foreach ($accounts as $account) {
			if (!$account->getExcludedFromReports()) {
				$scope[(int)$account->getId()] = true;
			}
		}
		return $scope;
	}

	/**
	 * Whether paying the bill shows up as spending in its category. A bill
	 * paid from an account outside the scope never does. Both legs of a
	 * transfer carry its category, so between two accounts in scope they
	 * net to nothing; only a transfer out of the scope is spent. A bill with
	 * no account is paid by hand, wherever the user records it.
	 *
	 * @param array<int, true>|null $scope
	 */
	private function paymentsCountAsSpent(BillEntity $bill, ?array $scope): bool {
		if ($scope === null) {
			return true;
		}
		$source = $bill->getAccountId();
		if ($bill->getIsTransfer()) {
			$destination = $bill->getDestinationAccountId();
			return $source !== null && isset($scope[$source])
				&& ($destination === null || !isset($scope[$destination]));
		}
		return $source === null || isset($scope[$source]);
	}

	/**
	 * Convert a monthly amount into the equivalent for a budget period.
	 * Mirrors the Budget view's conversion so every surface that applies the
	 * recurring fallback (alerts, budget report, frontend) agrees.
	 */
	public function convertMonthlyToPeriod(float $monthly, string $period): float {
		return match ($period) {
			'weekly' => $monthly * 12 / 52,
			'quarterly' => $monthly * 3,
			'yearly' => $monthly * 12,
			default => $monthly,
		};
	}

	/**
	 * Factor converting an amount at the given frequency into a monthly amount.
	 * Delegates to FrequencyCalculator so every frequency the app supports
	 * (incl. semi-monthly, semi-annually, daily, custom patterns) is handled
	 * by one shared implementation.
	 */
	private function monthlyFactor(string $frequency, ?string $customPattern = null): float {
		if ($frequency === 'one-time') {
			// A one-off is not a recurring monthly commitment: counting it
			// every month until it's marked paid would inflate the budget.
			return 0.0;
		}
		if ($frequency === 'custom') {
			return $this->frequencyCalculator->getCustomOccurrencesPerYear($customPattern) / 12.0;
		}
		return $this->frequencyCalculator->getMonthlyEquivalentFromValues(1.0, $frequency);
	}
}
