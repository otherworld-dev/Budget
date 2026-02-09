<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Widen encrypted banking columns to accommodate encrypted output.
 *
 * These columns store values encrypted via EncryptionService (AES-CBC + HMAC),
 * which produces ~232 chars for a typical IBAN. The previous column lengths
 * (10-100 chars) caused "Data too long" errors on save.
 *
 * Fixes: https://github.com/otherworld-dev/budget/issues/38
 */
class Version001000032Date20260209 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_accounts')) {
			$table = $schema->getTable('budget_accounts');

			$columnsToWiden = [
				'account_number',
				'routing_number',
				'sort_code',
				'iban',
				'swift_bic',
			];

			foreach ($columnsToWiden as $columnName) {
				if ($table->hasColumn($columnName)) {
					$table->modifyColumn($columnName, [
						'length' => 512,
					]);
				}
			}
		}

		return $schema;
	}
}
