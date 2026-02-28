<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add end date and remaining payments support to recurring bills.
 *
 * - end_date: Optional date after which the bill auto-deactivates
 * - remaining_payments: Optional countdown of payments before auto-deactivation
 *
 * Implements: https://github.com/otherworld-dev/budget/issues/46
 */
class Version001000034Date20260227 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_bills')) {
			$table = $schema->getTable('budget_bills');

			if (!$table->hasColumn('end_date')) {
				$table->addColumn('end_date', Types::DATE, [
					'notnull' => false,
					'default' => null,
				]);
			}

			if (!$table->hasColumn('remaining_payments')) {
				$table->addColumn('remaining_payments', Types::INTEGER, [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

		return $schema;
	}
}
