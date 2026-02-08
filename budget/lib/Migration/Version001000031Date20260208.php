<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add tag_ids column to budget_bills for storing tag selections (JSON array).
 * Tags are applied to transactions created from the bill (immediate or auto-pay).
 */
class Version001000031Date20260208 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_bills')) {
			$table = $schema->getTable('budget_bills');
			if (!$table->hasColumn('tag_ids')) {
				$table->addColumn('tag_ids', Types::TEXT, [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

		return $schema;
	}
}
