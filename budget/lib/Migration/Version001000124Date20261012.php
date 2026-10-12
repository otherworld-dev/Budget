<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Import folders (#425): a subfolder of the user's import folder in Files,
 * tied to one of their accounts and, for CSV and spreadsheet statements, a
 * saved import template. Files dropped into it are imported in the
 * background.
 */
class Version001000124Date20261012 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_import_folders')) {
			$table = $schema->createTable('budget_import_folders');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// One path segment; short enough for the unique index on every database
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 100]);
			$table->addColumn('account_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('template_id', Types::BIGINT, ['notnull' => false]);
			// 'auto' imports straight away; 'review' (held for a look first) comes later
			$table->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'auto']);
			$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id'], 'bimpf_pk');
			$table->addUniqueIndex(['user_id', 'name'], 'bimpf_user_name_uq');
			// The account-closing and account-delete checks look rows up by account
			$table->addIndex(['account_id'], 'bimpf_acct_idx');
			$table->addIndex(['template_id'], 'bimpf_tpl_idx');
		}

		return $schema;
	}
}
