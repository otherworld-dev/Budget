<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Widen interest_rate column from DECIMAL(5,4) to DECIMAL(7,4)
 * to support interest rates of 10% and above (up to 999.9999%).
 *
 * Fixes: https://github.com/otherworld-dev/budget/issues/74
 */
class Version001000042Date20260318 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_accounts')) {
			return null;
		}

		$table = $schema->getTable('budget_accounts');

		if ($table->hasColumn('interest_rate')) {
			$table->changeColumn('interest_rate', [
				'notnull' => false,
				'precision' => 7,
				'scale' => 4,
			]);
		}

		return $schema;
	}
}
