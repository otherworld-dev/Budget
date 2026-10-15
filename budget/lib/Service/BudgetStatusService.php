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
		// Every row is measured over the month, whatever its period
		$ownSpent = $this->ownSpending($userId, $rows, $startDate, $endDate, $accountIds);
		$spent = $ownSpent;
		self::branchSpending($tree, $spent);
		$monthlyBudget = self::rowBudgets($tree, $ownBudget, $periods, 'monthly');

		return [
			'month' => $month,
			'startDate' => $startDate,
			'endDate' => $endDate,
			'totals' => self::totals($rows, $periods, $ownBudget, $ownSpent),
			'categories' => self::lines(
				$rows, $budgets, $periods, $monthlyBudget, $spent,
				$this->periodsToDate($userId, $tree, $rows, $periods, $ownBudget, $month, $accountIds)
			),
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
	 * Each row's own spent between two dates: calculateCategorySpending().
	 * Expense rows net debits, income rows net credits.
	 *
	 * @return array<int, string> by category id; absent means 0
	 */
	private function ownSpending(string $userId, array $rows, string $start, string $end, array $accountIds): array {
		$groups = [];
		foreach ($rows as $row) {
			$type = ($row['type'] ?? '') === 'income' ? 'credit' : 'debit';
			$groups[$type][(int)$row['id']] = true;
		}

		$spent = [];
		foreach ($groups as $type => $ids) {
			$summary = $this->categoryService->getAllCategorySpending($userId, $start, $end, $accountIds, $type);
			foreach ($summary as $item) {
				$id = (int)$item['categoryId'];
				if (isset($ids[$id])) {
					$spent[$id] = MoneyCalculator::add((float)$item['spent'], '0', self::SCALE);
				}
			}
		}
		return $spent;
	}

	/**
	 * What a quarterly or yearly row shows beside the month's figures, "400
	 * of 1,200 this year": the row's budget for its whole period, and its
	 * spending from the start of the period to the end of the month, over
	 * the branch as the month's figures are.
	 *
	 * @return array<int, array{startDate: string, endDate: string, budgeted: string, spent: string}> by category id
	 */
	private function periodsToDate(string $userId, array $tree, array $rows, array $periods, array $ownBudget, string $month, array $accountIds): array {
		$startDay = $this->carryoverService->budgetStartDay($userId);
		$toDate = [];
		$budgets = null;
		foreach (array_unique(array_values($periods)) as $period) {
			$range = BudgetPeriod::toDateRange($period, $month, $startDay);
			if ($range === null) {
				continue;
			}
			$spent = $this->ownSpending($userId, $rows, $range[0], $range[1], $accountIds);
			self::branchSpending($tree, $spent);
			$budgets ??= self::rowBudgets($tree, $ownBudget, $periods, null);
			foreach ($periods as $id => $rowPeriod) {
				if ($rowPeriod === $period) {
					$toDate[$id] = [
						'startDate' => $range[0],
						'endDate' => $range[1],
						'budgeted' => $budgets[$id],
						'spent' => $spent[$id] ?? '0',
					];
				}
			}
		}
		return $toDate;
	}

	/**
	 * aggregateParentSpending()'s spent: a parent's becomes its own plus its
	 * children's, which already hold theirs, so it covers every level.
	 *
	 * @param array<int, string> $spent own spent in, branch spent out
	 */
	private static function branchSpending(array $nodes, array &$spent): void {
		foreach ($nodes as $node) {
			$children = $node['children'] ?? [];
			if ($children === []) {
				continue;
			}
			self::branchSpending($children, $spent);

			$id = (int)$node['id'];
			$branch = [$spent[$id] ?? '0'];
			foreach ($children as $child) {
				$branch[] = $spent[(int)$child['id']] ?? '0';
			}
			$spent[$id] = MoneyCalculator::sum($branch, self::SCALE);
		}
	}

	/**
	 * The budget each row shows, as a total for $in, or for the row's own
	 * period when null. A parent's is its own budget plus each DIRECT
	 * child's own budget only, so a grandchild's budget never reaches its
	 * grandparent (page rule); budgets of other periods count by their
	 * yearly ratios, so a yearly 1,200 is 100 a month.
	 *
	 * @param array<int, string> $ownBudget in each row's own period
	 * @param array<int, string> $periods
	 * @return array<int, string> by category id
	 */
	private static function rowBudgets(array $nodes, array $ownBudget, array $periods, ?string $in): array {
		$budgets = [];
		foreach ($nodes as $node) {
			$id = (int)$node['id'];
			$children = $node['children'] ?? [];
			$parts = [[$ownBudget[$id], $periods[$id]]];
			foreach ($children as $child) {
				$parts[] = [$ownBudget[(int)$child['id']], $periods[(int)$child['id']]];
			}
			$budgets[$id] = BudgetPeriod::totalFor($parts, $in ?? $periods[$id]);
			$budgets += self::rowBudgets($children, $ownBudget, $periods, $in);
		}
		return $budgets;
	}

	/**
	 * The rows renderBudgetCategoryNodes() draws with a budget: a parent
	 * shows its branch, and hasBudget keeps an envelope whose overspend used
	 * up the month, since it still owes something. Every row's budget is
	 * its share of the month, and a quarterly or yearly row also has its
	 * period so far.
	 *
	 * @param array<int, string> $rowBudget each row's budget for the month
	 * @param array<int, array<string, string>> $toDate periodsToDate()
	 * @return list<array<string, mixed>>
	 */
	private static function lines(array $rows, array $budgets, array $periods, array $rowBudget, array $spent, array $toDate): array {
		$lines = [];
		foreach ($rows as $row) {
			$id = (int)$row['id'];
			$entry = $budgets[$id] ?? [];
			$budget = $rowBudget[$id];
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
				'periodToDate' => $toDate[$id] ?? null,
			];
		}
		return $lines;
	}

	/**
	 * The summary cards, updateBudgetSummary() for expenses: each budgeted
	 * row's own budget turned monthly, and the month's own spent of EVERY
	 * expense row, budgeted or not (page rule).
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
				$budgeted[] = [$ownBudget[$id], $periods[$id]];
			}
			$spent[] = $ownSpent[$id] ?? '0';
		}
		$budgetedTotal = BudgetPeriod::monthlyTotal($budgeted);
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
