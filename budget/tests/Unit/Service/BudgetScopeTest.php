<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Service\BudgetScope;
use PHPUnit\Framework\TestCase;

class BudgetScopeTest extends TestCase {

	private function makeCategory(int $id, ?int $parentId = null, bool $excluded = false): Category {
		$category = new Category();
		$category->setId($id);
		$category->setParentId($parentId);
		$category->setExcludedFromBudget($excluded);
		return $category;
	}

	public function testUnflaggedTreeExcludesNothing(): void {
		$categories = [
			$this->makeCategory(1),
			$this->makeCategory(2, 1),
			$this->makeCategory(3, 2),
		];

		$this->assertSame([], BudgetScope::excludedCategoryIds($categories));
	}

	public function testFlaggedCategoryIsExcluded(): void {
		$categories = [
			$this->makeCategory(1, null, true),
			$this->makeCategory(2),
		];

		$excluded = BudgetScope::excludedCategoryIds($categories);

		$this->assertArrayHasKey(1, $excluded);
		$this->assertArrayNotHasKey(2, $excluded);
	}

	public function testDescendantsOfAFlaggedParentAreExcluded(): void {
		// 1 (flagged) → 2 → 3; 4 is an unrelated sibling
		$categories = [
			$this->makeCategory(1, null, true),
			$this->makeCategory(2, 1),
			$this->makeCategory(3, 2),
			$this->makeCategory(4),
		];

		$excluded = BudgetScope::excludedCategoryIds($categories);

		$this->assertSame([1, 2, 3], array_keys($excluded));
	}

	public function testAncestorsOfAFlaggedChildStayBudgeted(): void {
		// Flagging a child must not take its parent out of budgeting
		$categories = [
			$this->makeCategory(1),
			$this->makeCategory(2, 1, true),
		];

		$excluded = BudgetScope::excludedCategoryIds($categories);

		$this->assertArrayNotHasKey(1, $excluded);
		$this->assertArrayHasKey(2, $excluded);
	}

	public function testNullFlagCountsAsBudgeted(): void {
		// Rows written before the column existed read back as NULL
		$category = new Category();
		$category->setId(1);
		$category->setParentId(null);

		$this->assertSame([], BudgetScope::excludedCategoryIds([$category]));
	}

	public function testParentOutsideTheListIsIgnored(): void {
		// A shared category's parent may not be in this user's list — the child
		// is judged on its own flag rather than inheriting from nothing
		$categories = [$this->makeCategory(2, 99)];

		$this->assertSame([], BudgetScope::excludedCategoryIds($categories));
	}

	public function testParentCycleTerminates(): void {
		// Corrupt data (1 → 2 → 1) must not hang the walk
		$categories = [
			$this->makeCategory(1, 2),
			$this->makeCategory(2, 1),
		];

		$this->assertSame([], BudgetScope::excludedCategoryIds($categories));
	}

	public function testFlaggedCategoryInACycleStillExcludes(): void {
		$categories = [
			$this->makeCategory(1, 2, true),
			$this->makeCategory(2, 1),
		];

		$excluded = BudgetScope::excludedCategoryIds($categories);

		$this->assertArrayHasKey(1, $excluded);
		$this->assertArrayHasKey(2, $excluded);
	}

	// ── spendingBranches (#551) ─────────────────────────────────────

	private function makeTyped(int $id, ?int $parentId = null, string $type = 'expense', bool $excludedFromReports = false, bool $excludedFromBudget = false): Category {
		$category = $this->makeCategory($id, $parentId, $excludedFromBudget);
		$category->setType($type);
		$category->setExcludedFromReports($excludedFromReports);
		return $category;
	}

	public function testBranchTakesInEveryUnbudgetedDescendant(): void {
		// Housing (1, budgeted) → Rent (2) → Deposit (3); Bills (4) → Energy (5)
		$categories = [
			$this->makeTyped(1),
			$this->makeTyped(2, 1),
			$this->makeTyped(3, 2),
			$this->makeTyped(4, 1),
			$this->makeTyped(5, 4),
		];

		$branches = BudgetScope::spendingBranches($categories, [1 => true]);

		$this->assertSame([1, 2, 3, 4, 5], $branches[1]);
	}

	public function testBranchStopsAtADescendantWithItsOwnBudget(): void {
		// 1 (budgeted) → 2 (budgeted) → 3; 1 → 4. 3 counts under 2 only.
		$categories = [
			$this->makeTyped(1),
			$this->makeTyped(2, 1),
			$this->makeTyped(3, 2),
			$this->makeTyped(4, 1),
		];

		$branches = BudgetScope::spendingBranches($categories, [1 => true, 2 => true]);

		$this->assertSame([1, 4], $branches[1]);
		$this->assertSame([2, 3], $branches[2]);
	}

	public function testBranchSkipsExcludedSubtrees(): void {
		// 1 (budgeted) → 2 (out of reports) → 3; 1 → 4 (out of budgeting) → 5;
		// 1 → 6 (income, a different kind of money)
		$categories = [
			$this->makeTyped(1),
			$this->makeTyped(2, 1, 'expense', true),
			$this->makeTyped(3, 2),
			$this->makeTyped(4, 1, 'expense', false, true),
			$this->makeTyped(5, 4),
			$this->makeTyped(6, 1, 'income'),
		];

		$branches = BudgetScope::spendingBranches($categories, [1 => true]);

		$this->assertSame([1], $branches[1]);
	}

	public function testBranchSurvivesAParentCycle(): void {
		// Corrupt data: 1 → 2 → 1
		$categories = [
			$this->makeTyped(1, 2),
			$this->makeTyped(2, 1),
		];

		$branches = BudgetScope::spendingBranches($categories, [1 => true]);

		$this->assertSame([1, 2], $branches[1]);
	}
}
