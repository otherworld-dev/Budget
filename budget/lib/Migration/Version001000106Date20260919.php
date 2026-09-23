<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Project budgets (#391): one total over a date range for a category and
 * everything under it, with optional amounts for its subcategories.
 *
 * Allocations carry their own user_id so the backup registry can clear them
 * by user. A table scoped only through budget_projects would be cleared after
 * its parents are gone and never found again.
 */
class Version001000106Date20260919 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_projects')) {
			$table = $schema->createTable('budget_projects');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('category_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('total_amount', Types::DECIMAL, ['notnull' => true, 'precision' => 15, 'scale' => 2]);
			$table->addColumn('start_date', Types::DATE, ['notnull' => true]);
			$table->addColumn('end_date', Types::DATE, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id'], 'bproj_pk');
			$table->addIndex(['user_id'], 'bproj_user_idx');
			// The category-delete guard looks projects up by category
			$table->addIndex(['category_id'], 'bproj_cat_idx');
		}

		if (!$schema->hasTable('budget_project_allocs')) {
			$table = $schema->createTable('budget_project_allocs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('project_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('category_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('amount', Types::DECIMAL, ['notnull' => true, 'precision' => 15, 'scale' => 2]);

			$table->setPrimaryKey(['id'], 'bpalloc_pk');
			// One amount per subcategory per project
			$table->addUniqueIndex(['project_id', 'category_id'], 'bpalloc_proj_cat_uq');
			$table->addIndex(['category_id'], 'bpalloc_cat_idx');
			$table->addIndex(['user_id'], 'bpalloc_user_idx');
		}

		return $schema;
	}
}
