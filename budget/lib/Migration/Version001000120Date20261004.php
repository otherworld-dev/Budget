<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Bring back categories a reorder hid inside a loop.
 *
 * Dropping a category above or below one of its own subcategories made it
 * its own parent (or its child's child). The whole branch dropped out of the
 * tree, and editing or deleting it hung. Reordering now refuses that move;
 * this breaks each loop already saved by moving one category in it to the
 * top level, which puts the rest of the branch back under it.
 */
class Version001000120Date20261004 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('budget_categories')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'parent_id')
			->from('budget_categories')
			->where($qb->expr()->isNotNull('parent_id'));
		$result = $qb->executeQuery();
		$parents = [];
		while ($row = $result->fetch()) {
			$parents[(int)$row['id']] = (int)$row['parent_id'];
		}
		$result->closeCursor();

		$ids = self::loopBreakIds($parents);
		foreach (array_chunk($ids, 500) as $chunk) {
			$update = $this->db->getQueryBuilder();
			$update->update('budget_categories')
				->set('parent_id', $update->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->where($update->expr()->in('id', $update->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$update->executeStatement();
		}

		if ($ids !== []) {
			$output->info('Moved ' . count($ids) . ' category(ies) caught in a parent loop to the top level');
		}
	}

	/**
	 * One category from each parent loop, the one to move to the top level.
	 *
	 * The lowest id in the loop is taken: a parent is normally created before
	 * its subcategories, so that is usually the category that was dropped
	 * next to its own child, and moving it out restores the rest of the
	 * branch as it was.
	 *
	 * @param array<int, int> $parents category id => parent id (rows with a parent only)
	 * @return int[]
	 */
	public static function loopBreakIds(array $parents): array {
		$breakIds = [];
		$done = [];
		foreach (array_keys($parents) as $start) {
			$path = [];
			$onPath = [];
			$current = $start;
			while (isset($parents[$current]) && !isset($done[$current])) {
				if (isset($onPath[$current])) {
					$breakIds[] = min(array_slice($path, $onPath[$current]));
					break;
				}
				$onPath[$current] = count($path);
				$path[] = $current;
				$current = $parents[$current];
			}
			foreach ($path as $id) {
				$done[$id] = true;
			}
		}
		sort($breakIds);
		return $breakIds;
	}
}
