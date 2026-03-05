<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rename budget_manual_exchange_rates → budget_manual_rates for users
 * who already ran migration 038 before the table name was shortened.
 * The old name exceeded Nextcloud's 27-char table name limit.
 */
class Version001000039Date20260305 extends SimpleMigrationStep {
	private IDBConnection $db;

	/** @var array Rows saved from old table before schema change drops it */
	private array $rowsToMigrate = [];

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * Read all data from the old table before it gets dropped.
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_manual_exchange_rates')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'currency', 'rate_per_eur', 'updated_at')
			->from('budget_manual_exchange_rates');
		$result = $qb->executeQuery();
		$this->rowsToMigrate = $result->fetchAll();
		$result->free();
	}

	/**
	 * Create the new short-named table and drop the old one.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_manual_exchange_rates')) {
			return null;
		}

		if (!$schema->hasTable('budget_manual_rates')) {
			$table = $schema->createTable('budget_manual_rates');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);

			$table->addColumn('currency', Types::STRING, [
				'notnull' => true,
				'length' => 10,
			]);

			$table->addColumn('rate_per_eur', Types::DECIMAL, [
				'notnull' => true,
				'precision' => 20,
				'scale' => 10,
			]);

			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['user_id', 'currency'], 'budget_mrate_usr_curr');
			$table->addIndex(['user_id'], 'budget_mrate_user_idx');
		}

		$schema->dropTable('budget_manual_exchange_rates');

		return $schema;
	}

	/**
	 * Re-insert saved rows into the new table after schema change.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (empty($this->rowsToMigrate)) {
			return;
		}

		foreach ($this->rowsToMigrate as $row) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('budget_manual_rates')
				->values([
					'user_id' => $qb->createNamedParameter($row['user_id']),
					'currency' => $qb->createNamedParameter($row['currency']),
					'rate_per_eur' => $qb->createNamedParameter($row['rate_per_eur']),
					'updated_at' => $qb->createNamedParameter($row['updated_at']),
				]);
			$qb->executeStatement();
		}
	}
}
