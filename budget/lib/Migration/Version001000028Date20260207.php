<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Cleanup migration to fix boolean columns that were incorrectly created as NOT NULL.
 *
 * Nextcloud's DBAL requires boolean columns to be nullable for cross-database compatibility.
 * This migration drops and recreates columns added in migrations 001000024, 001000026, and 001000027
 * that were incorrectly created with 'notnull' => true.
 *
 * Affected columns:
 * - budget_import_rules.stop_processing
 * - budget_bills.auto_pay_enabled
 * - budget_bills.auto_pay_failed
 * - budget_bills.is_transfer
 *
 * Note: This will reset these columns to their default values, but since these are new features
 * in v2.1.0, data loss is minimal (users can re-enable auto-pay if needed).
 */
class Version001000028Date20260207 extends SimpleMigrationStep {

	/**
	 * Drop broken columns before schema reconciliation.
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$connection = \OC::$server->getDatabaseConnection();
		$prefix = \OC::$server->getConfig()->getSystemValue('dbtableprefix', 'oc_');

		// Drop columns that may have been created with incorrect NOT NULL constraint
		$columnsToDrop = [
			'budget_import_rules' => ['stop_processing'],
			'budget_bills' => ['auto_pay_enabled', 'auto_pay_failed', 'is_transfer'],
		];

		foreach ($columnsToDrop as $table => $columns) {
			try {
				/** @var ISchemaWrapper $schema */
				$schema = $schemaClosure();
				if ($schema->hasTable($table)) {
					$tableObj = $schema->getTable($table);
					foreach ($columns as $column) {
						if ($tableObj->hasColumn($column)) {
							$connection->executeStatement("ALTER TABLE {$prefix}{$table} DROP COLUMN {$column}");
							$output->info("Dropped {$table}.{$column}");
						}
					}
				}
			} catch (\Exception $e) {
				// Column might not exist, continue
			}
		}
	}

	/**
	 * Recreate columns with correct nullable boolean type.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Recreate budget_import_rules.stop_processing
		if ($schema->hasTable('budget_import_rules')) {
			$table = $schema->getTable('budget_import_rules');
			if (!$table->hasColumn('stop_processing')) {
				$table->addColumn('stop_processing', Types::BOOLEAN, [
					'notnull' => false,
					'default' => true,
					'comment' => 'If true, stop evaluating rules after this one matches',
				]);
			}
		}

		// Recreate budget_bills columns
		if ($schema->hasTable('budget_bills')) {
			$table = $schema->getTable('budget_bills');

			if (!$table->hasColumn('auto_pay_enabled')) {
				$table->addColumn('auto_pay_enabled', Types::BOOLEAN, [
					'notnull' => false,
					'default' => false,
					'comment' => 'Automatically mark bill as paid when due date arrives',
				]);
			}

			if (!$table->hasColumn('auto_pay_failed')) {
				$table->addColumn('auto_pay_failed', Types::BOOLEAN, [
					'notnull' => false,
					'default' => false,
					'comment' => 'Tracks if last auto-pay attempt failed, prevents retry loops',
				]);
			}

			if (!$table->hasColumn('is_transfer')) {
				$table->addColumn('is_transfer', Types::BOOLEAN, [
					'notnull' => false,
					'default' => false,
				]);
			}
		}

		return $schema;
	}
}
