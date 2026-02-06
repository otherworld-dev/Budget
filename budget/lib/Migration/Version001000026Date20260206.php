<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add auto-pay columns to budget_bills table.
 *
 * Enables automatic payment marking when bills reach their due date.
 * Auto-pay requires an account_id to create transactions.
 *
 * Changes:
 * - Adds auto_pay_enabled (BOOLEAN) to enable/disable auto-pay per bill
 * - Adds auto_pay_failed (BOOLEAN) to track failure state and prevent retry loops
 * - Both columns default to false, preserving existing bill behavior
 */
class Version001000026Date20260206 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_bills')) {
			$table = $schema->getTable('budget_bills');

			// Add auto_pay_enabled column
			if (!$table->hasColumn('auto_pay_enabled')) {
				$table->addColumn('auto_pay_enabled', Types::BOOLEAN, [
					'notnull' => true,
					'default' => false,
					'comment' => 'Automatically mark bill as paid when due date arrives',
				]);
			}

			// Add auto_pay_failed column to track failure state
			if (!$table->hasColumn('auto_pay_failed')) {
				$table->addColumn('auto_pay_failed', Types::BOOLEAN, [
					'notnull' => true,
					'default' => false,
					'comment' => 'Tracks if last auto-pay attempt failed, prevents retry loops',
				]);
			}
		}

		return $schema;
	}
}
