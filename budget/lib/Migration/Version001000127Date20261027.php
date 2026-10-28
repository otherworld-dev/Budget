<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A bank account mapping remembers when its account's balance was matched
 * to the bank's (balance_reconciled_at).
 *
 * The first sync into an account that holds only the rows it brought sets
 * the opening balance so that the balance equals the bank's. Without a
 * record of that, an account that stayed empty passed the same check on
 * every sync, and its balance silently followed the bank's. NULL means not
 * matched yet, which is true of every mapping that exists now.
 */
class Version001000127Date20261027 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_bam')) {
			return null;
		}
		$table = $schema->getTable('budget_bam');
		if ($table->hasColumn('balance_reconciled_at')) {
			return null;
		}
		$table->addColumn('balance_reconciled_at', Types::DATETIME, [
			'notnull' => false,
		]);
		return $schema;
	}
}
