<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auto-share new entities (#306). One row per (share, entity_type) opts that
 * recipient into automatically receiving newly-created entities of that type at
 * the stored permission. Row presence = enabled (toggling off deletes the row),
 * mirroring how budget_share_items models "is this shared". Short table name
 * (budget_share_auto) keeps the identifier within limits.
 */
class Version001000088Date20260627 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_share_auto')) {
			return null;
		}

		$table = $schema->createTable('budget_share_auto');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('share_id', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('entity_type', Types::STRING, [
			'notnull' => true,
			'length' => 32,
		]);
		$table->addColumn('permission', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => 'read',
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->addColumn('updated_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['share_id', 'entity_type'], 'bgt_shauto_uniq');
		$table->addIndex(['share_id'], 'bgt_shauto_sid');

		return $schema;
	}
}
