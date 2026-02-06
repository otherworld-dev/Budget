<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add bill_id column to budget_transactions table.
 *
 * This enables tracking which transactions were auto-generated from bills,
 * supporting the "create future transaction" feature for recurring bills.
 *
 * Changes:
 * - Adds nullable bill_id column (BIGINT) to link transactions to their source bills
 * - Adds index on bill_id for efficient queries
 * - No foreign key constraint to allow bills to be deleted without cascading
 */
class Version001000025Date20260206 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_transactions')) {
			$table = $schema->getTable('budget_transactions');

			// Add bill_id column to link transactions to bills
			if (!$table->hasColumn('bill_id')) {
				$table->addColumn('bill_id', Types::BIGINT, [
					'notnull' => false,
					'unsigned' => true,
					'comment' => 'Links transaction to bill for auto-generation tracking',
				]);
			}

			// Add index for efficient queries (find all transactions for a bill)
			if (!$table->hasIndex('budget_transactions_bill_id')) {
				$table->addIndex(['bill_id'], 'budget_transactions_bill_id');
			}
		}

		return $schema;
	}
}
