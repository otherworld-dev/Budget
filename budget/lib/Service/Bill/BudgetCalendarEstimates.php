<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Bill;

use OCA\Budget\Db\BudgetSnapshot;
use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Enum\Currency;
use OCA\Budget\Service\BudgetPeriod;
use OCA\Budget\Service\BudgetScope;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\MoneyCalculator;

/**
 * The budgets a user put in the Bills Calendar (#459), as what each one is
 * expected to spend in each calendar month: estimated spending shown beside
 * the bills in the Bills Calendar report and its feed.
 *
 * A category is in when it is flagged show_in_calendar, is an expense
 * category, and is budgeted: not out of budgeting (BudgetScope, so the
 * children of an excluded parent are out too) and not out of reports with
 * its branch, as the Budget page leaves both out. A month takes the budget
 * that applies to it, a snapshot's from the month it took effect as on the
 * Budget page (CategoryService::resolveEffectiveBudgets()), turned into a
 * month's share by the ratios the Budget page uses (BudgetPeriod::shareOf()):
 * a weekly 20 is 86.67, a quarterly 300 is 100 and a yearly 1,200 is 100 in
 * every month. Only an amount the user set counts. The Budget page falls
 * back to the category's recurring bills when none is set, and those bills
 * are in the calendar already, so counting them here would count them twice.
 * Envelope carryover is left out: the estimate is what the month's budget
 * plans to spend, not what is left of an earlier one.
 *
 * Budgets are in the user's base currency.
 */
class BudgetCalendarEstimates {

	public function __construct(
		private CategoryMapper $categoryMapper,
		private BudgetSnapshotMapper $snapshotMapper,
		private CurrencyConversionService $currencyConversion,
	) {
	}

	/**
	 * Each row's amounts are month => the month's share, at the currency's
	 * decimals, and its budgets month => [amount, period] as set.
	 *
	 * @param string[] $months 'YYYY-MM', in order
	 * @return array{currency: string, rows: list<array{category: Category, amounts: array<string, string>, budgets: array<string, array{0: float, 1: string}>}>}
	 */
	public function forMonths(string $userId, array $months): array {
		$currency = $this->currencyConversion->getBaseCurrency($userId);
		$categories = $this->categoryMapper->findAll($userId);
		if ($months === [] || !self::anyFlagged($categories)) {
			return ['currency' => $currency, 'rows' => []];
		}
		return [
			'currency' => $currency,
			'rows' => self::estimates($categories, $this->snapshotMapper->findAll($userId), $months, Currency::decimalsFor($currency)),
		];
	}

	/** @param Category[] $categories */
	private static function anyFlagged(array $categories): bool {
		foreach ($categories as $category) {
			if ($category->getShowInCalendar()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param Category[] $categories the user's full category list
	 * @param BudgetSnapshot[] $snapshots the user's snapshots, newest first (BudgetSnapshotMapper::findAll())
	 * @param string[] $months 'YYYY-MM'
	 * @return list<array{category: Category, amounts: array<string, string>, budgets: array<string, array{0: float, 1: string}>}>
	 */
	public static function estimates(array $categories, array $snapshots, array $months, int $decimals): array {
		$notBudgeted = BudgetScope::excludedCategoryIds($categories) + BudgetScope::reportExcludedIds($categories);

		$snapshotsByCategory = [];
		foreach ($snapshots as $snapshot) {
			$snapshotsByCategory[$snapshot->getCategoryId()][] = $snapshot;
		}
		foreach ($snapshotsByCategory as &$list) {
			usort($list, static fn (BudgetSnapshot $a, BudgetSnapshot $b): int => strcmp((string)$b->getEffectiveFrom(), (string)$a->getEffectiveFrom()));
		}
		unset($list);

		$rows = [];
		foreach ($categories as $category) {
			$id = $category->getId();
			if (!$category->getShowInCalendar() || $category->getType() !== 'expense' || isset($notBudgeted[$id])) {
				continue;
			}
			$amounts = [];
			$budgets = [];
			foreach ($months as $month) {
				[$amount, $period] = self::budgetFor($category, $snapshotsByCategory[$id] ?? [], $month);
				if ($amount === null || $amount <= 0) {
					continue;
				}
				$share = round((float)BudgetPeriod::shareOf($amount, $period, '1'), $decimals);
				$amounts[$month] = MoneyCalculator::add('0', $share, $decimals);
				$budgets[$month] = [$amount, $period];
			}
			if ($amounts !== []) {
				$rows[] = ['category' => $category, 'amounts' => $amounts, 'budgets' => $budgets];
			}
		}
		return $rows;
	}

	/**
	 * The budget that applies to $month: the latest snapshot taking effect by
	 * then, or the category's own.
	 *
	 * @param BudgetSnapshot[] $snapshots the category's, newest first
	 * @return array{0: float|null, 1: string}
	 */
	private static function budgetFor(Category $category, array $snapshots, string $month): array {
		foreach ($snapshots as $snapshot) {
			if ((string)$snapshot->getEffectiveFrom() <= $month) {
				$amount = $snapshot->getAmount();
				return [$amount === null ? null : (float)$amount, $snapshot->getPeriod() ?? 'monthly'];
			}
		}
		$amount = $category->getBudgetAmount();
		return [$amount === null ? null : (float)$amount, $category->getBudgetPeriod() ?? 'monthly'];
	}
}
