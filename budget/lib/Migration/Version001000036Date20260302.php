<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create asset tracking tables: assets and asset_snapshots.
 */
class Version001000036Date20260302 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Create assets table
		if (!$schema->hasTable('budget_assets')) {
			$table = $schema->createTable('budget_assets');
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
			$table->addColumn('type', Types::STRING, [
				'notnull' => true,
				'length' => 50,
				'default' => 'other',
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('currency', Types::STRING, [
				'notnull' => true,
				'length' => 3,
				'default' => 'USD',
			]);
			$table->addColumn('current_value', Types::DECIMAL, [
				'notnull' => false,
				'precision' => 15,
				'scale' => 2,
			]);
			$table->addColumn('purchase_price', Types::DECIMAL, [
				'notnull' => false,
				'precision' => 15,
				'scale' => 2,
			]);
			$table->addColumn('purchase_date', Types::DATE, [
				'notnull' => false,
			]);
			$table->addColumn('annual_change_rate', Types::DECIMAL, [
				'notnull' => false,
				'precision' => 5,
				'scale' => 4,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'bgt_assets_uid');
			$table->addIndex(['type'], 'bgt_assets_type');
		}

		// Create asset_snapshots table (shortened name for index limits)
		if (!$schema->hasTable('budget_asset_snaps')) {
			$table = $schema->createTable('budget_asset_snaps');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('asset_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('value', Types::DECIMAL, [
				'notnull' => true,
				'precision' => 15,
				'scale' => 2,
			]);
			$table->addColumn('date', Types::DATE, [
				'notnull' => true,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'bgt_assetsnap_uid');
			$table->addIndex(['asset_id'], 'bgt_assetsnap_aid');
			$table->addIndex(['date'], 'bgt_assetsnap_date');
		}

		return $schema;
	}
}
