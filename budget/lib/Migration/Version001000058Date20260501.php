<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add last_reconciled column to budget_accounts for tracking reconciliation history.
 */
class Version001000058Date20260501 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('budget_accounts');
		if (!$table->hasColumn('last_reconciled')) {
			$table->addColumn('last_reconciled', Types::STRING, [
				'notnull' => false,
				'length' => 19,
				'default' => null,
			]);
		}

		return $schema;
	}
}
