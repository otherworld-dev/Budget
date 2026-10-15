<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add excluded_from_reports flag to budget_categories.
 * Categories with this flag are excluded from budget calculations,
 * spending reports, and dashboard totals.
 */
class Version001000059Date20260502 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('budget_categories');
		if (!$table->hasColumn('excluded_from_reports')) {
			$table->addColumn('excluded_from_reports', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
		}

		return $schema;
	}
}
