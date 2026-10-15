<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

/**
 * The budgets of the categories shared with a user, as each owner sees them.
 *
 * Lifted out of CategoryController so the web Budget page and the public
 * API's budget status (#767) work shared categories out the same way.
 */
class SharedBudgetService {
	public function __construct(
		private CategoryService $categoryService,
		private GranularShareService $granularShareService,
	) {
	}

	/**
	 * The effective budgets of the categories shared with $userId, as each
	 * owner sees them for $month: the owner's adjustment for the month, the
	 * owner's envelope and its carry-over over the owner's accounts. Without
	 * this a shared category showed only its plain budget, so in any month
	 * the owner had adjusted the two people saw different figures.
	 *
	 * @return array<int, array> keyed by category id, like CategoryService::resolveEffectiveBudgets()
	 */
	public function effectiveBudgets(string $userId, string $month): array {
		$idsByOwner = [];
		foreach ($this->granularShareService->getSharedCategories($userId) as $category) {
			$idsByOwner[$category['userId']][] = (int)$category['id'];
		}

		$budgets = [];
		foreach ($idsByOwner as $owner => $ids) {
			$ownerBudgets = $this->categoryService->resolveEffectiveBudgets(
				(string)$owner,
				$month,
				$this->granularShareService->getVisibleAccountIds((string)$owner)
			);
			$budgets += array_intersect_key($ownerBudgets, array_flip($ids));
		}
		return $budgets;
	}
}
