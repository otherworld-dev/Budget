<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Saved reports (#299): named report configurations a user can re-run. The
 * configuration (report type, date selection, selected accounts, tags and
 * report-specific options) is stored as JSON in `config` so the shape can
 * evolve without schema changes.
 */
class Version001000083Date20260617 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_saved_reports')) {
			return null;
		}

		$table = $schema->createTable('budget_saved_reports');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('name', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('config', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->addColumn('updated_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id'], 'bgt_savedrep_pk');
		$table->addIndex(['user_id'], 'bgt_savedrep_user_idx');

		return $schema;
	}
}
