<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Uncategorize rows still filed under a category that no longer exists.
 *
 * Deleting a category missed some of what used it: its delete guard didn't
 * see pre-booked rows, and nothing cleared bills or recurring income. Those
 * rows kept counting in balances and totals but showed in no category's
 * breakdown, nor under Uncategorized, and each payment of such a bill was
 * booked to the dead category again. Deleting a category now clears all of
 * them (CategoryService); this does the same for what earlier deletes left.
 */
class Version001000115Date20261002 extends SimpleMigrationStep {
	private const TABLES = ['budget_bills', 'budget_recurring_income', 'budget_transactions', 'budget_tx_splits'];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('budget_categories')) {
			return;
		}

		$cleared = 0;
		foreach (self::TABLES as $table) {
			if (!$schema->hasTable($table)) {
				continue;
			}
			$categories = $this->db->getQueryBuilder();
			$categories->select('id')->from('budget_categories');

			$qb = $this->db->getQueryBuilder();
			$qb->update($table)
				->set('category_id', $qb->createNamedParameter(null))
				->where($qb->expr()->isNotNull('category_id'))
				->andWhere($qb->expr()->notIn('category_id', $qb->createFunction($categories->getSQL())));
			$cleared += $qb->executeStatement();
		}

		if ($cleared > 0) {
			$output->info("Uncategorized {$cleared} row(s) filed under deleted categories");
		}
	}
}
