<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add split_template column to budget_bills for split transaction templates (GitHub Issue #121).
 * Stores a JSON array of {categoryId, amount, description} defining how transactions
 * created from this bill should be automatically split across categories.
 */
class Version001000045Date20260411 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_bills')) {
			$table = $schema->getTable('budget_bills');

			if (!$table->hasColumn('split_template')) {
				$table->addColumn('split_template', Types::TEXT, [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

		return $schema;
	}
}
