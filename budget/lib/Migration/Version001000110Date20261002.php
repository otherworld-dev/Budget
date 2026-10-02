<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Undo for Mark Received: markReceived keeps what it changed (the previous
 * dates and whether the income was active, and the transaction it created)
 * as JSON on the income, so undo can put it all back. Undo only ever reset
 * the last received date, leaving the credit in the ledger and the expected
 * date moved on.
 */
class Version001000110Date20261002 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_recurring_income')) {
			return null;
		}
		$table = $schema->getTable('budget_recurring_income');
		if ($table->hasColumn('received_undo_state')) {
			return null;
		}
		$table->addColumn('received_undo_state', Types::TEXT, [
			'notnull' => false,
		]);
		return $schema;
	}
}
