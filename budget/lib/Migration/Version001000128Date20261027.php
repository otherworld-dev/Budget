<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A bank account mapping remembers the day the bank reported its last
 * balance for (last_balance_date).
 *
 * Banks such as comdirect report the previous day's closing balance. Budget
 * compared it with its own balance as of today, so a row booked today showed
 * as a difference, and the first sync moved the opening balance by it. NULL
 * means the provider gave no date; the balance is then compared as of today.
 */
class Version001000128Date20261027 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_bam')) {
			return null;
		}
		$table = $schema->getTable('budget_bam');
		if ($table->hasColumn('last_balance_date')) {
			return null;
		}
		$table->addColumn('last_balance_date', Types::DATE, [
			'notnull' => false,
		]);
		return $schema;
	}
}
