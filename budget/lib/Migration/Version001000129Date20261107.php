<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A category can put its budget in the Bills Calendar (#459):
 * budget_categories.show_in_calendar. When set, the category's budget shows
 * there as estimated spending for each month, beside the bills, and in the
 * bills calendar feed. NULL, as every existing category has, means no.
 */
class Version001000129Date20261107 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_categories')) {
			return null;
		}
		$table = $schema->getTable('budget_categories');
		if ($table->hasColumn('show_in_calendar')) {
			return null;
		}
		$table->addColumn('show_in_calendar', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);
		return $schema;
	}
}
