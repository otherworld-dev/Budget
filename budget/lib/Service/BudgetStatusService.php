<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

/**
 * A month's budget as the web Budget page shows it, worked out on the
 * server for the public API (#767).
 *
 * The rule is that phone and web agree: every row and every total here
 * equals what CategoriesModule.js shows for the same user and month. That
 * page does its arithmetic in the browser, so this is a port of its rules,
 * kept literal on purpose, including the ones that look odd (noted where
 * they happen). A change to the page's figures needs the same change here.
 */
class BudgetStatusService {
	/** Intermediate precision, as CategoryService::getReadyToAssign() uses */
	private const SCALE = 6;

	public function __construct(
		private CategoryService $categoryService,
		private SharedBudgetService $sharedBudgets,
		private GranularShareService $granularShareService,
		private BudgetCarryoverService $carryoverService,
		private RecurringBudgetService $recurringBudgetService,
	) {
	}

	/**
	 * @param string|null $month YYYY-MM, or null for the budget month running today
	 * @return array{month: string, startDate: string, endDate: string, totals: array{budgeted: string, spent: string, remaining: string}, categories: list<array<string, mixed>>}
	 */
	public function forMonth(string $userId, ?string $month = null): array {
		$currentMonth = $this->carryoverService->currentBudgetMonth($userId);
		$month ??= $currentMonth;
		[$startDate, $endDate] = $this->carryoverService->budgetMonthRange($userId, $month);
		// The page reads the same scope for its budgets and its spending
		$accountIds = $this->granularShareService->getVisibleAccountIds($userId);

		$budgets = $this->categoryService->resolveEffectiveBudgets($userId, $month, $accountIds)
			+ $this->sharedBudgets->effectiveBudgets($userId, $month);

		$tree = self::filterBudgetCategories(self::mergeCategoryTree(
			$this->categoryService->getCategoryTree($userId),
			self::sharedTree($this->granularShareService->getSharedCategories($userId))
		));
		$rows = self::flatten($tree);

		[$periods, $ownBudget] = $this->ownBudgets($userId, $rows, $budgets, $month, $month < $currentMonth);
		$ownSpent = $this->ownSpending($userId, $rows, $month, $accountIds);
		$spent = $ownSpent;
		$branchBudget = [];
		self::aggregateParents($tree, $spent, $branchBudget, $ownBudget);

		return [
			'month' => $month,
			'startDate' => $startDate,
			'endDate' => $endDate,
			'totals' => self::totals($rows, $periods, $ownBudget, $ownSpent),
			'categories' => self::lines($rows, $budgets, $periods, $ownBudget, $branchBudget, $spent),
		];
	}

	/**
	 * Each row's own budget and the period it is set for: the page's
	 * _getEffectiveBudgetForCalc() and _getEffectiveBudgetPeriod(). The
	 * server's `available` wins; a category with no entry falls back to its
	 * own amount, then to the recurring figure (never for a past month).
	 *
	 * @return array{0: array<int, string>, 1: array<int, string>} [periods, budgets] by category id
	 */
	private function ownBudgets(string $userId, array $rows, array $budgets, string $month, bool $pastMonth): array {
		$periods = [];
		$own = [];
		$recurring = null;
		foreach ($rows as $row) {
			$id = (int)$row['id'];
			$entry = $budgets[$id] ?? null;
			$periods[$id] = $entry !== null
				? (((string)($entry['period'] ?? '')) ?: 'monthly')
				: (((string)($row['budgetPeriod'] ?? '')) ?: 'monthly');

			if ($entry !== null && ($entry['available'] ?? null) !== null) {
				$own[$id] = MoneyCalculator::add((float)$entry['available'], '0', self::SCALE);
				continue;
			}
			$manual = (float)($entry !== null ? ($entry['amount'] ?? 0) : ($row['budgetAmount'] ?? 0));
			if ($manual > 0 || $pastMonth) {
				$own[$id] = MoneyCalculator::add(max($manual, 0.0), '0', self::SCALE);
				continue;
			}
			$recurring ??= $this->recurringBudgetService->getMonthlyBudgetsByCategory($userId, $month);
			$monthly = (float)($recurring[$id] ?? 0);
			$own[$id] = MoneyCalculator::add(
				$monthly != 0.0 ? $this->recurringBudgetService->convertMonthlyToPeriod($monthly, $periods[$id]) : 0.0,
				'0',
				self::SCALE
			);
		}
		return [$periods, $own];
	}

	/**
	 * Each row's own spent: calculateCategorySpending(). Categories are
	 * grouped by their budgetPeriod COLUMN, not a month's adjusted period,
	 * and each group is measured over its own dates, so a weekly category
	 * shows one week and a yearly one the whole calendar year (page rule).
	 * Expense rows net debits, income rows net credits.
	 *
	 * @return array<int, string> by category id; absent means 0
	 */
	private function ownSpending(string $userId, array $rows, string $month, array $accountIds): array {
		$startDay = $this->carryoverService->budgetStartDay($userId);
		$groups = [];
		foreach ($rows as $row) {
			$period = ((string)($row['budgetPeriod'] ?? '')) ?: 'monthly';
			$type = ($row['type'] ?? '') === 'income' ? 'credit' : 'debit';
			$groups["$period:$type"]['period'] = $period;
			$groups["$period:$type"]['type'] = $type;
			$groups["$period:$type"]['ids'][(int)$row['id']] = true;
		}

		$spent = [];
		foreach ($groups as $group) {
			[$start, $end] = self::periodRange($group['period'], $month, $startDay);
			$summary = $this->categoryService->getAllCategorySpending($userId, $start, $end, $accountIds, $group['type']);
			foreach ($summary as $item) {
				$id = (int)$item['categoryId'];
				if (isset($group['ids'][$id])) {
					$spent[$id] = MoneyCalculator::add((float)$item['spent'], '0', self::SCALE);
				}
			}
		}
		return $spent;
	}

	/**
	 * The dates a budget period covers in the selected month: the page's
	 * getPeriodDateRange() with the month's 15th as the reference day.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function periodRange(string $period, string $month, int $startDay): array {
		$reference = new \DateTimeImmutable($month . '-15');
		switch ($period) {
			case 'weekly':
				// Monday to Sunday of the week holding the 15th
				$monday = $reference->modify('-' . ((int)$reference->format('N') - 1) . ' days');
				return [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d')];
			case 'quarterly':
				$first = sprintf('%s-%02d-01', $reference->format('Y'), intdiv((int)$reference->format('n') - 1, 3) * 3 + 1);
				return [$first, (new \DateTimeImmutable($first))->modify('+2 months')->format('Y-m-t')];
			case 'yearly':
				return [$reference->format('Y') . '-01-01', $reference->format('Y') . '-12-31'];
			default:
				// Only the monthly period honours the budget start day
				return BudgetPeriod::range($month, $startDay);
		}
	}

	/**
	 * aggregateParentSpending(): a parent's spent becomes its own plus its
	 * children's, which already hold theirs, so it covers every level. Its
	 * budget becomes its own plus each DIRECT child's own budget only, so a
	 * grandchild's budget never reaches its grandparent (page rule).
	 *
	 * @param array<int, string> $spent own spent in, branch spent out
	 * @param array<int, string> $branchBudget filled for every parent
	 * @param array<int, string> $ownBudget
	 */
	private static function aggregateParents(array $nodes, array &$spent, array &$branchBudget, array $ownBudget): void {
		foreach ($nodes as $node) {
			$children = $node['children'] ?? [];
			if ($children === []) {
				continue;
			}
			self::aggregateParents($children, $spent, $branchBudget, $ownBudget);

			$id = (int)$node['id'];
			$branchSpent = [$spent[$id] ?? '0'];
			$budget = [$ownBudget[$id]];
			foreach ($children as $child) {
				$branchSpent[] = $spent[(int)$child['id']] ?? '0';
				$budget[] = $ownBudget[(int)$child['id']];
			}
			$spent[$id] = MoneyCalculator::sum($branchSpent, self::SCALE);
			$branchBudget[$id] = MoneyCalculator::sum($budget, self::SCALE);
		}
	}

	/**
	 * The rows renderBudgetCategoryNodes() draws with a budget: a parent
	 * shows its branch, and hasBudget keeps an envelope whose overspend used
	 * up the month, since it still owes something.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function lines(array $rows, array $budgets, array $periods, array $ownBudget, array $branchBudget, array $spent): array {
		$lines = [];
		foreach ($rows as $row) {
			$id = (int)$row['id'];
			$entry = $budgets[$id] ?? [];
			$budget = $row['hasChildren'] ? $branchBudget[$id] : $ownBudget[$id];
			$carried = MoneyCalculator::add((float)($entry['carried'] ?? 0), '0', self::SCALE);
			$hasBudget = MoneyCalculator::compare($budget, '0', self::SCALE) > 0
				|| (!empty($entry['rollover']) && MoneyCalculator::compare(MoneyCalculator::abs($carried, self::SCALE), '0.005', self::SCALE) >= 0);
			if (!$hasBudget) {
				continue;
			}
			$spentHere = $spent[$id] ?? '0';
			$lines[] = [
				'categoryId' => $id,
				'name' => (string)($row['name'] ?? ''),
				'parentId' => $row['treeParentId'],
				'type' => (string)($row['type'] ?? ''),
				'period' => $periods[$id],
				'budgeted' => $budget,
				'carried' => $carried,
				'spent' => $spentHere,
				'remaining' => MoneyCalculator::subtract($budget, $spentHere, self::SCALE),
				'shared' => !empty($row['_shared']),
			];
		}
		return $lines;
	}

	/**
	 * The summary cards, updateBudgetSummary() for expenses: each budgeted
	 * row's own budget turned monthly, and the own spent of EVERY expense
	 * row, budgeted or not, each over its own period (page rule).
	 *
	 * @return array{budgeted: string, spent: string, remaining: string}
	 */
	private static function totals(array $rows, array $periods, array $ownBudget, array $ownSpent): array {
		$budgeted = [];
		$spent = [];
		foreach ($rows as $row) {
			if (($row['type'] ?? '') !== 'expense') {
				continue;
			}
			$id = (int)$row['id'];
			if (MoneyCalculator::compare($ownBudget[$id], '0', self::SCALE) > 0) {
				$budgeted[] = BudgetPeriod::monthlyEquivalent($ownBudget[$id], $periods[$id]);
			}
			$spent[] = $ownSpent[$id] ?? '0';
		}
		$budgetedTotal = MoneyCalculator::sum($budgeted, self::SCALE);
		$spentTotal = MoneyCalculator::sum($spent, self::SCALE);

		return [
			'budgeted' => $budgetedTotal,
			'spent' => $spentTotal,
			'remaining' => MoneyCalculator::subtract($budgetedTotal, $spentTotal, self::SCALE),
		];
	}

	/**
	 * The shared categories as CategoryController::tree() nests them: under
	 * a parent only when that parent is shared too, else at the top.
	 */
	private static function sharedTree(array $shared): array {
		$ids = [];
		foreach ($shared as $category) {
			$ids[(int)$category['id']] = true;
		}
		$childrenOf = [];
		$roots = [];
		foreach ($shared as $category) {
			$parentId = $category['parentId'] ?? null;
			if ($parentId && isset($ids[(int)$parentId])) {
				$childrenOf[(int)$parentId][] = $category;
			} else {
				$roots[] = $category;
			}
		}
		$build = static function (array $category) use (&$build, $childrenOf): array {
			$category['children'] = array_map($build, $childrenOf[(int)$category['id']] ?? []);
			return $category;
		};
		return array_map($build, $roots);
	}

	/**
	 * mergeCategoryTree(): a shared top-level category with the same name
	 * and type as an own one takes its place, their children merged by
	 * name; the other shared ones follow the own ones.
	 */
	private static function mergeCategoryTree(array $own, array $shared): array {
		if ($shared === []) {
			return $own;
		}
		if ($own === []) {
			return $shared;
		}
		$merged = [];
		$used = [];
		foreach ($own as $ownCategory) {
			$match = null;
			foreach ($shared as $candidate) {
				if ($candidate['name'] === $ownCategory['name'] && $candidate['type'] === $ownCategory['type']
					&& !isset($used[$candidate['id']])) {
					$match = $candidate;
					break;
				}
			}
			if ($match === null) {
				$merged[] = $ownCategory;
				continue;
			}
			$used[$match['id']] = true;
			$match['children'] = self::mergeChildren($ownCategory['children'] ?? [], $match['children'] ?? []);
			$merged[] = $match;
		}
		foreach ($shared as $candidate) {
			if (!isset($used[$candidate['id']])) {
				$merged[] = $candidate;
			}
		}
		return $merged;
	}

	/** mergeChildren(): as mergeCategoryTree(), matching on name alone. */
	private static function mergeChildren(array $own, array $shared): array {
		if ($shared === []) {
			return $own;
		}
		if ($own === []) {
			return $shared;
		}
		$merged = [];
		$used = [];
		foreach ($own as $ownChild) {
			$match = null;
			foreach ($shared as $candidate) {
				if ($candidate['name'] === $ownChild['name'] && !isset($used[$candidate['id']])) {
					$match = $candidate;
					break;
				}
			}
			if ($match === null) {
				$merged[] = $ownChild;
				continue;
			}
			$used[$match['id']] = true;
			$match['children'] = self::mergeChildren($ownChild['children'] ?? [], $match['children'] ?? []);
			$merged[] = $match;
		}
		foreach ($shared as $candidate) {
			if (!isset($used[$candidate['id']])) {
				$merged[] = $candidate;
			}
		}
		return $merged;
	}

	/** filterBudgetCategories(): report- or budget-excluded branches drop out whole. */
	private static function filterBudgetCategories(array $nodes): array {
		$kept = [];
		foreach ($nodes as $node) {
			if (!empty($node['excludedFromReports']) || !empty($node['excludedFromBudget'])) {
				continue;
			}
			$node['children'] = self::filterBudgetCategories($node['children'] ?? []);
			$kept[] = $node;
		}
		return $kept;
	}

	/**
	 * The tree in page order (flattenCategories), each row knowing its
	 * parent on the page and whether it has children there.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function flatten(array $nodes, ?int $parentId = null): array {
		$rows = [];
		foreach ($nodes as $node) {
			$children = $node['children'] ?? [];
			$rows[] = ['treeParentId' => $parentId, 'hasChildren' => $children !== []] + $node;
			array_push($rows, ...self::flatten($children, (int)$node['id']));
		}
		return $rows;
	}
}
