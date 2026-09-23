<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds transaction receipt attachments: rows reference files in the user's
 * own Files space by fileId (the file itself is never owned or deleted by
 * the app). Explicit PK/index names — a default-named primary key on a
 * >=23-char table crashes Nextcloud <= 32 (#272).
 */
class Version001000078Date20260612 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_attachments')) {
			return null;
		}

		$table = $schema->createTable('budget_attachments');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('transaction_id', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('file_id', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('file_name', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('mime_type', Types::STRING, [
			'notnull' => false,
			'length' => 128,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id'], 'bgt_attach_pk');
		$table->addIndex(['transaction_id'], 'bgt_attach_tx_idx');
		$table->addIndex(['user_id'], 'bgt_attach_user_idx');
		$table->addUniqueIndex(['transaction_id', 'file_id'], 'bgt_attach_txfile_unq');

		return $schema;
	}
}
