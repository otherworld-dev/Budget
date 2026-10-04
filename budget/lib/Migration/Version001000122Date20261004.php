<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCA\Budget\Service\Import\SetupDefaultRules;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Switch off the empty import rules "Create default categories" used to add.
 *
 * Setup made six rules (Grocery Stores, Gas Stations, ...) with no category
 * and no other action. A rule with nothing to do still matches first and
 * stops the rules after it, so a description containing "shell", "amazon" or
 * "cash" was never categorised by the user's own rules, on import or in Run
 * rules. Setup now gives each rule its category, at the lowest priority (0)
 * so a rule the user makes outranks it; this deactivates the ones it already
 * made and moves them to that priority, so one switched back on can't block
 * the user's rules either. Only rules still exactly what setup made (same
 * pattern, priority, flags, no group, no action) are touched; nothing is
 * deleted: the user can switch a rule back on or remove it. A backup restore
 * does the same to the rules it brings back (SetupDefaultRules).
 */
class Version001000122Date20261004 extends SimpleMigrationStep {
	/** The rules setup made, name => pattern, exactly as it wrote them */
	public const DEFAULT_RULES = SetupDefaultRules::RULES;

	/** The priority setup gave each of them (unchanged since 2.0) */
	public const DEFAULT_PRIORITIES = SetupDefaultRules::PRIORITIES;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('budget_import_rules')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'pattern', 'field', 'match_type', 'category_id', 'vendor_name', 'actions', 'criteria',
			'priority', 'stop_processing', 'apply_on_import', 'group_name')
			->from('budget_import_rules')
			->where($qb->expr()->in('name', $qb->createNamedParameter(array_keys(self::DEFAULT_RULES), IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			if (self::isUntouchedEmptyDefault($row)) {
				$ids[] = (int)$row['id'];
			}
		}
		$result->closeCursor();

		$now = date('Y-m-d H:i:s');
		foreach (array_chunk($ids, 500) as $chunk) {
			$update = $this->db->getQueryBuilder();
			$update->update('budget_import_rules')
				->set('active', $update->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
				->set('priority', $update->createNamedParameter(SetupDefaultRules::RETIRED_PRIORITY, IQueryBuilder::PARAM_INT))
				->set('updated_at', $update->createNamedParameter($now))
				->where($update->expr()->in('id', $update->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$update->executeStatement();
		}

		if ($ids !== []) {
			$output->info('Switched off ' . count($ids) . ' default import rule(s) that had no action');
		}
	}

	/**
	 * Whether a stored rule is one setup made, unchanged, with no action.
	 *
	 * @param array<string, mixed> $row A budget_import_rules row
	 */
	public static function isUntouchedEmptyDefault(array $row): bool {
		return SetupDefaultRules::isUntouchedEmptyDefault($row);
	}
}
