<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Self-heal recently-added budget_categories / budget_accounts columns (#302),
 * the same failure mode as #289. On some instances the original migrations were
 * recorded as applied but the columns never landed — an interrupted upgrade, or
 * a database restored from a backup taken mid-upgrade — so creating a category
 * failed with "Unknown column 'excluded_from_reports'". Because those migrations
 * are already marked applied and guard themselves with hasColumn(), re-running
 * migrations never re-adds the columns, so this fresh migration re-asserts them
 * idempotently. It also covers the rollover columns (079) that the same INSERT
 * writes, so a borked instance is fixed in one pass rather than surfacing the
 * next missing column on the following create. A no-op on healthy instances.
 */
class Version001000085Date20260624 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		// budget_categories: excluded_from_reports (059) + rollover columns (079)
		if ($schema->hasTable('budget_categories')) {
			$table = $schema->getTable('budget_categories');

			if (!$table->hasColumn('excluded_from_reports')) {
				$table->addColumn('excluded_from_reports', Types::BOOLEAN, [
					'notnull' => false,
					'default' => false,
				]);
				$changed = true;
			}
			if (!$table->hasColumn('budget_rollover')) {
				$table->addColumn('budget_rollover', Types::BOOLEAN, [
					'notnull' => false,
					'default' => false,
				]);
				$changed = true;
			}
			if (!$table->hasColumn('rollover_start')) {
				$table->addColumn('rollover_start', Types::STRING, [
					'notnull' => false,
					'length' => 7,
				]);
				$changed = true;
			}
		}

		// budget_accounts: excluded_from_reports (082)
		if ($schema->hasTable('budget_accounts')) {
			$table = $schema->getTable('budget_accounts');
			if (!$table->hasColumn('excluded_from_reports')) {
				$table->addColumn('excluded_from_reports', Types::BOOLEAN, [
					'notnull' => false,
					'default' => false,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
