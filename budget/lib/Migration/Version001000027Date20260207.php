<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add transfer support to bills table
 * - is_transfer: flag to distinguish transfers from bills
 * - destination_account_id: target account for transfers
 * - transfer_description_pattern: optional description pattern for matching
 */
class Version001000027Date20260207 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_bills')) {
			$table = $schema->getTable('budget_bills');

			// Add is_transfer flag
			if (!$table->hasColumn('is_transfer')) {
				$table->addColumn('is_transfer', Types::BOOLEAN, [
					'notnull' => false,
					'default' => false,
				]);
			}

			// Add destination_account_id for transfers
			if (!$table->hasColumn('destination_account_id')) {
				$table->addColumn('destination_account_id', Types::INTEGER, [
					'notnull' => false,
					'default' => null,
				]);
			}

			// Add transfer_description_pattern for enhanced matching
			if (!$table->hasColumn('transfer_description_pattern')) {
				$table->addColumn('transfer_description_pattern', Types::STRING, [
					'notnull' => false,
					'length' => 255,
					'default' => null,
				]);
			}

			return $schema;
		}

		return null;
	}
}
