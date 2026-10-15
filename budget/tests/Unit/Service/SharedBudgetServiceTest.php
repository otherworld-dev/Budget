<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\SharedBudgetService;
use PHPUnit\Framework\TestCase;

/**
 * The budgets of categories shared with someone, as each owner sees them:
 * worked out once here for the Budget page and the public API (#767).
 */
class SharedBudgetServiceTest extends TestCase {
	public function testEachOwnersBudgetsAreReadInTheirOwnScopeAndOnlySharedOnesKept(): void {
		$categories = $this->createMock(CategoryService::class);
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getSharedCategories')->with('user1')->willReturn([
			['id' => 70, 'userId' => 'owner1'],
			['id' => 80, 'userId' => 'owner2'],
		]);
		$shares->method('getVisibleAccountIds')->willReturnMap([
			['owner1', [8]],
			['owner2', [5, 6]],
		]);
		$categories->method('resolveEffectiveBudgets')->willReturnMap([
			['owner1', '2026-09', [8], [70 => ['available' => 40.0], 71 => ['available' => 999.0]]],
			['owner2', '2026-09', [5, 6], [80 => ['available' => 12.0]]],
		]);

		$budgets = (new SharedBudgetService($categories, $shares))->effectiveBudgets('user1', '2026-09');

		$this->assertSame([70, 80], array_keys($budgets));
		$this->assertSame(40.0, $budgets[70]['available']);
	}

	public function testNothingSharedMeansNoLookups(): void {
		$categories = $this->createMock(CategoryService::class);
		$categories->expects($this->never())->method('resolveEffectiveBudgets');
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getSharedCategories')->willReturn([]);

		$this->assertSame([], (new SharedBudgetService($categories, $shares))->effectiveBudgets('user1', '2026-09'));
	}
}
