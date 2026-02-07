<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create budget_shares table for multi-user budget sharing
 * - Enables users to share their budget (read-only initially) with other Nextcloud users
 * - Supports future expansion to write permissions
 */
class Version001000028Date20260207 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_shares')) {
			$table = $schema->createTable('budget_shares');

			// Primary key
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);

			// Owner of the budget
			$table->addColumn('owner_user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			// User who has been granted access
			$table->addColumn('shared_with_user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			// Permission level: 'read', 'write' (future)
			$table->addColumn('permission_level', Types::STRING, [
				'notnull' => true,
				'length' => 10,
				'default' => 'read',
			]);

			// Timestamps
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			// Primary key
			$table->setPrimaryKey(['id']);

			// Unique constraint: one share per owner-user pair
			$table->addUniqueIndex(['owner_user_id', 'shared_with_user_id'], 'idx_unique_share');

			// Index for fast lookups of budgets shared with a user
			$table->addIndex(['shared_with_user_id'], 'idx_shared_with');

			// Index for fast lookups of users an owner has shared with
			$table->addIndex(['owner_user_id'], 'idx_owner');

			return $schema;
		}

		return null;
	}
}
