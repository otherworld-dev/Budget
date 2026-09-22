<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Category;

/**
 * Which categories are out of scope for budgeting.
 *
 * A category is out of scope when it is flagged excluded_from_budget, or when
 * any ancestor is: flagging a parent takes its whole subtree out of budgeting,
 * matching how the Budget view drops an excluded branch. The flag is budget-only
 * — those categories still count in reports, the dashboard and every total.
 *
 * Every budget surface (Budget view, alerts, budget-vs-actual report, envelope
 * carryover) resolves the set through here so they can't drift apart.
 */
final class BudgetScope {

    /** Guard against a parent cycle in corrupt data */
    private const MAX_DEPTH = 64;

    /**
     * Ids of the categories no budget surface may include.
     *
     * @param Category[] $categories the user's full category list
     * @return array<int, true> categoryId => true (membership test: isset())
     */
    public static function excludedCategoryIds(array $categories): array {
        $flagged = [];
        $parents = [];
        foreach ($categories as $category) {
            $id = $category->getId();
            $flagged[$id] = (bool) ($category->getExcludedFromBudget() ?? false);
            $parents[$id] = $category->getParentId();
        }

        $excluded = [];
        foreach (array_keys($flagged) as $id) {
            $cursor = $id;
            for ($depth = 0; $cursor !== null && $depth < self::MAX_DEPTH; $depth++) {
                if (!isset($flagged[$cursor])) {
                    break; // parent outside this list (e.g. shared) — nothing to inherit
                }
                if ($flagged[$cursor]) {
                    $excluded[$id] = true;
                    break;
                }
                $cursor = $parents[$cursor];
            }
        }

        return $excluded;
    }

    /**
     * The categories whose spending counts toward each budget: the budgeted
     * category itself and its descendants, stopping at a descendant with a
     * budget of its own, which counts its own branch instead. A parent budget
     * whose spending is all filed under its children would otherwise read 0
     * spent and never alert (#551). Each category lands in at most one branch,
     * so a total over the branches counts nothing twice.
     *
     * A descendant out of reports or out of budgeting is left out along with
     * everything under it, as the Budget view drops it, and so is one of a
     * different type: an income subcategory is not spending.
     *
     * @param Category[] $categories the user's full category list
     * @param array<int, true> $budgetedIds categories with a budget in play
     * @return array<int, int[]> budgeted categoryId => member ids, itself first
     */
    public static function spendingBranches(array $categories, array $budgetedIds): array {
        $byId = [];
        $children = [];
        foreach ($categories as $category) {
            $byId[$category->getId()] = $category;
            if ($category->getParentId() !== null) {
                $children[$category->getParentId()][] = $category->getId();
            }
        }
        $notBudgeted = self::excludedCategoryIds($categories);

        $branches = [];
        foreach (array_keys($budgetedIds) as $rootId) {
            if (!isset($byId[$rootId])) {
                continue;
            }
            $type = $byId[$rootId]->getType();
            $members = [];
            $seen = [$rootId => true];
            $stack = [[$rootId, 0]];
            while ($stack) {
                [$id, $depth] = array_pop($stack);
                $members[] = $id;
                if ($depth >= self::MAX_DEPTH) {
                    continue;
                }
                foreach (array_reverse($children[$id] ?? []) as $childId) {
                    $child = $byId[$childId];
                    if (isset($seen[$childId]) || isset($budgetedIds[$childId])
                        || ($child->getExcludedFromReports() ?? false)
                        || isset($notBudgeted[$childId])
                        || $child->getType() !== $type) {
                        continue;
                    }
                    $seen[$childId] = true;
                    $stack[] = [$childId, $depth + 1];
                }
            }
            $branches[$rootId] = $members;
        }

        return $branches;
    }
}
