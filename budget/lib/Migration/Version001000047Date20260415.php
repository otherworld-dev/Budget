<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create budget_share_items table for granular per-entity sharing.
 *
 * Each row represents one shared entity (account, category, bill, etc.)
 * within a share, with a read or write permission.
 */
class Version001000047Date20260415 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_share_items')) {
			$table = $schema->createTable('budget_share_items');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('share_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			// 'account', 'category', 'bill', 'recurring_income', 'savings_goal'
			$table->addColumn('entity_type', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('entity_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 8,
			]);
			// 'read' or 'write'
			$table->addColumn('permission', Types::STRING, [
				'notnull' => true,
				'length' => 8,
				'default' => 'read',
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['share_id', 'entity_type', 'entity_id'], 'budget_si_unique');
			$table->addIndex(['share_id', 'entity_type'], 'budget_si_type_idx');
		}

		return $schema;
	}
}
