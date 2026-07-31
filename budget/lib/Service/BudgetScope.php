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
}
