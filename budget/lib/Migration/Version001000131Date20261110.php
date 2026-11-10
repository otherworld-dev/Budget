<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Runway planned one-offs: a dated amount the user expects to spend or
 * receive (a car repair, a tax refund) that the runway forecast folds in on
 * top of the monthly average.
 */
class Version001000131Date20261110 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_runway_oneoffs')) {
			$table = $schema->createTable('budget_runway_oneoffs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('description', Types::STRING, ['notnull' => true, 'length' => 255]);
			// Signed like a transaction's (negative is money out); the same definition as budget_transactions.amount
			$table->addColumn('amount', Types::DECIMAL, [
				'notnull' => true,
				'precision' => 15,
				'scale' => 2,
			]);
			$table->addColumn('date', Types::DATE, ['notnull' => true]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id'], 'budget_rwo_pk');
			$table->addIndex(['user_id'], 'budget_rwo_user');
		}

		return $schema;
	}
}
