<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create budget_shared_sessions table for multi-user password sessions
 * - Allows users to authenticate to shared budgets with password protection
 * - Each user can have multiple active shared sessions (one per shared budget owner)
 */
class Version001000029Date20260207 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_shared_sessions')) {
			$table = $schema->createTable('budget_shared_sessions');

			// Primary key
			$table->addColumn('id', Types::INTEGER, [
				'autoincrement' => true,
				'notnull' => true,
			]);

			// User who is accessing the shared budget
			$table->addColumn('current_user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			// Budget owner whose password was verified
			$table->addColumn('owner_user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			// Unique session token for this shared access
			$table->addColumn('session_token', Types::STRING, [
				'notnull' => true,
				'length' => 128,
			]);

			// Session expiration timestamp
			$table->addColumn('expires_at', Types::DATETIME, [
				'notnull' => true,
			]);

			// When the session was created
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			// Primary key
			$table->setPrimaryKey(['id']);

			// Unique index on session token for fast lookups
			$table->addUniqueIndex(['session_token'], 'idx_shared_session_token');

			// Unique constraint: one active session per current_user + owner_user pair
			$table->addUniqueIndex(['current_user_id', 'owner_user_id'], 'idx_unique_shared_session');

			// Index for finding all shared sessions for a user
			$table->addIndex(['current_user_id'], 'idx_current_user');

			// Index for finding all sessions accessing an owner's budget
			$table->addIndex(['owner_user_id'], 'idx_owner_user');

			return $schema;
		}

		return null;
	}
}
