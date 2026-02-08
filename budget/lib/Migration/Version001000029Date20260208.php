<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Increase budget_amount decimal scale from 2 to 6.
 *
 * Budget period conversions (e.g. monthly→weekly→quarterly) lose precision
 * when the intermediate value is truncated to 2 decimal places. Increasing
 * the scale to 6 preserves enough precision for clean round-trip conversions.
 */
class Version001000029Date20260208 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_categories')) {
			$table = $schema->getTable('budget_categories');
			if ($table->hasColumn('budget_amount')) {
				$column = $table->getColumn('budget_amount');
				$column->setPrecision(15);
				$column->setScale(6);
			}
		}

		return $schema;
	}
}
