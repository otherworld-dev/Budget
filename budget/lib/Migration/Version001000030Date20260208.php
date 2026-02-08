<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add tag_id column to budget_savings_goals for tag-linked auto-calculated amounts.
 */
class Version001000030Date20260208 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_savings_goals')) {
			$table = $schema->getTable('budget_savings_goals');
			if (!$table->hasColumn('tag_id')) {
				$table->addColumn('tag_id', Types::BIGINT, [
					'notnull' => false,
					'unsigned' => true,
					'default' => null,
				]);
				$table->addIndex(['tag_id'], 'budget_goals_tag_id');
			}
		}

		return $schema;
	}
}
