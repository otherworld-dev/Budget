<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Per-viewer category report mutes. A recipient of a shared category can hide
 * it from THEIR OWN reports without touching the owner's excluded_from_reports
 * flag (which is a single switch that changes every viewer's reports — that
 * stays owner-only by design). Row presence = muted for that user.
 */
class Version001000090Date20260706 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_cat_mutes')) {
			return null;
		}

		$table = $schema->createTable('budget_cat_mutes');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('category_id', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['user_id', 'category_id'], 'bgt_catmute_uniq');
		$table->addIndex(['category_id'], 'bgt_catmute_cid');

		return $schema;
	}
}
