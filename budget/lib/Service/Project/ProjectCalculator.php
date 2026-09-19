<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Project;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\Project;
use OCA\Budget\Db\ProjectAllocation;
use OCA\Budget\Service\MoneyCalculator;

/**
 * The figures behind a project budget (#391), kept free of the database so
 * every rule can be tested directly. ProjectService fetches the categories
 * and the spending; this decides what they add up to.
 *
 * Dates are Y-m-d strings, which compare correctly as strings.
 */
final class ProjectCalculator {
    public const STATUS_UPCOMING = 'upcoming';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FINISHED = 'finished';

    /**
     * @param Category[] $categories
     * @return array<int, Category[]> parent id (0 for top level) => children, by name
     */
    public static function childrenMap(array $categories): array {
        $map = [];
        foreach ($categories as $category) {
            $map[(int)($category->getParentId() ?? 0)][] = $category;
        }
        foreach ($map as &$children) {
            usort($children, static fn (Category $a, Category $b): int => strcasecmp((string)$a->getName(), (string)$b->getName()));
        }
        unset($children);
        return $map;
    }

    /**
     * The category plus every descendant, leaving out any flagged Exclude
     * from reports together with everything under it, the same as Category
     * Details (#359). The category itself always stays.
     *
     * @param array<int, Category[]> $childrenMap
     * @return int[]
     */
    public static function branchIds(int $rootId, array $childrenMap): array {
        $ids = [$rootId];
        $queue = [$rootId];
        while ($queue !== []) {
            $parentId = array_shift($queue);
            foreach ($childrenMap[$parentId] ?? [] as $child) {
                if ($child->getExcludedFromReports()) {
                    continue;
                }
                $ids[] = $child->getId();
                $queue[] = $child->getId();
            }
        }
        return $ids;
    }

    /**
     * The branch below the category in tree order, for the form's amount
     * inputs. Depth 1 is a direct subcategory.
     *
     * @param array<int, Category[]> $childrenMap
     * @return array<int, array{id: int, name: string, depth: int}>
     */
    public static function subcategories(int $rootId, array $childrenMap, int $depth = 1): array {
        $list = [];
        foreach ($childrenMap[$rootId] ?? [] as $child) {
            if ($child->getExcludedFromReports()) {
                continue;
            }
            $list[] = ['id' => $child->getId(), 'name' => (string)$child->getName(), 'depth' => $depth];
            $list = array_merge($list, self::subcategories($child->getId(), $childrenMap, $depth + 1));
        }
        return $list;
    }

    public static function status(string $startDate, ?string $endDate, string $today): string {
        if ($today < $startDate) {
            return self::STATUS_UPCOMING;
        }
        if ($endDate !== null && $today > $endDate) {
            return self::STATUS_FINISHED;
        }
        return self::STATUS_ACTIVE;
    }

    /**
     * The dates spending is counted between, or null before the project starts.
     *
     * @return array{from: string, to: string}|null
     */
    public static function window(string $startDate, ?string $endDate, string $today): ?array {
        if ($today < $startDate) {
            return null;
        }
        $to = ($endDate !== null && $endDate < $today) ? $endDate : $today;
        return ['from' => $startDate, 'to' => $to];
    }

    /**
     * How much of the project's time has gone, from 0 to 1, counting whole
     * days with both ends included. Null without an end date.
     */
    public static function timeElapsed(string $startDate, ?string $endDate, string $today): ?float {
        if ($endDate === null) {
            return null;
        }
        if ($today < $startDate) {
            return 0.0;
        }
        $start = new \DateTimeImmutable($startDate);
        $total = (int)$start->diff(new \DateTimeImmutable($endDate))->days + 1;
        $until = $today > $endDate ? $endDate : $today;
        $gone = (int)$start->diff(new \DateTimeImmutable($until))->days + 1;
        return min(1.0, $gone / (float)$total);
    }

    /**
     * Every category spending has to be fetched for: the branch, plus the
     * branch of each allocation, which covers one moved out of the project
     * since it was set.
     *
     * @param ProjectAllocation[] $allocations
     * @param array<int, Category[]> $childrenMap
     * @return int[]
     */
    public static function queryIds(int $rootId, array $allocations, array $childrenMap): array {
        $ids = self::branchIds($rootId, $childrenMap);
        foreach ($allocations as $allocation) {
            $ids = array_merge($ids, self::branchIds($allocation->getCategoryId(), $childrenMap));
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param ProjectAllocation[] $allocations
     * @param Category[] $categories the owner's categories
     * @param array<int, float> $spending category id => net spent in the window
     */
    public static function summarise(Project $project, array $allocations, array $categories, array $spending, string $today): array {
        $byId = [];
        foreach ($categories as $category) {
            $byId[$category->getId()] = $category;
        }
        $childrenMap = self::childrenMap($categories);
        $rootId = $project->getCategoryId();
        $start = (string)Project::day($project->getStartDate());
        $end = Project::day($project->getEndDate());
        $total = (float)$project->getTotalAmount();

        $sum = static function (array $ids) use ($spending): float {
            return MoneyCalculator::toFloat(MoneyCalculator::sum(
                array_map(static fn (int $id): float => (float)($spending[$id] ?? 0.0), $ids)
            ));
        };

        $branch = self::branchIds($rootId, $childrenMap);
        $inBranch = array_flip($branch);
        $spent = $sum($branch);

        $amounts = [];
        foreach ($allocations as $allocation) {
            $amounts[$allocation->getCategoryId()] = (float)$allocation->getAmount();
        }

        $rows = [];
        foreach ($childrenMap[$rootId] ?? [] as $child) {
            if (!isset($inBranch[$child->getId()])) {
                continue;
            }
            $rows[] = self::row($child, 0, $amounts[$child->getId()] ?? null, $sum(self::branchIds($child->getId(), $childrenMap)), false);
            foreach (self::nestedAllocations($child->getId(), $childrenMap, $amounts, 1) as [$category, $depth]) {
                $rows[] = self::row($category, $depth, $amounts[$category->getId()], $sum(self::branchIds($category->getId(), $childrenMap)), false);
            }
        }
        // An amount whose category has since moved out of the project, or
        // been flagged out of reports: still shown, but it counts for nothing
        foreach ($amounts as $categoryId => $amount) {
            if (isset($inBranch[$categoryId]) || !isset($byId[$categoryId])) {
                continue;
            }
            $rows[] = self::row($byId[$categoryId], 0, $amount, $sum(self::branchIds($categoryId, $childrenMap)), true);
        }

        $allocated = MoneyCalculator::toFloat(MoneyCalculator::sum(array_values($amounts)));

        return [
            'status' => self::status($start, $end, $today),
            'window' => self::window($start, $end, $today),
            'timeElapsed' => self::timeElapsed($start, $end, $today),
            'spent' => $spent,
            'remaining' => MoneyCalculator::toFloat(MoneyCalculator::subtract($total, $spent)),
            'percentage' => $total > 0 ? round($spent / $total * 100, 1) : 0.0,
            'directSpent' => $sum([$rootId]),
            'allocated' => $allocated,
            'unallocated' => MoneyCalculator::toFloat(MoneyCalculator::subtract($total, $allocated)),
            'branchCategoryIds' => $branch,
            'subcategories' => self::subcategories($rootId, $childrenMap),
            'breakdown' => $rows,
        ];
    }

    /**
     * Allocated categories below $parentId, depth first, with their depth
     * below the direct subcategory.
     *
     * @param array<int, Category[]> $childrenMap
     * @param array<int, float> $amounts
     * @return array<int, array{0: Category, 1: int}>
     */
    private static function nestedAllocations(int $parentId, array $childrenMap, array $amounts, int $depth): array {
        $found = [];
        foreach ($childrenMap[$parentId] ?? [] as $child) {
            if ($child->getExcludedFromReports()) {
                continue;
            }
            if (isset($amounts[$child->getId()])) {
                $found[] = [$child, $depth];
            }
            $found = array_merge($found, self::nestedAllocations($child->getId(), $childrenMap, $amounts, $depth + 1));
        }
        return $found;
    }

    private static function row(Category $category, int $depth, ?float $allocation, float $spent, bool $outside): array {
        return [
            'categoryId' => $category->getId(),
            'name' => (string)$category->getName(),
            'depth' => $depth,
            'allocation' => $allocation,
            'spent' => $spent,
            'remaining' => $allocation === null ? null : MoneyCalculator::toFloat(MoneyCalculator::subtract($allocation, $spent)),
            'percentage' => ($allocation === null || $allocation <= 0) ? null : round($spent / $allocation * 100, 1),
            'outsideProject' => $outside,
        ];
    }
}
