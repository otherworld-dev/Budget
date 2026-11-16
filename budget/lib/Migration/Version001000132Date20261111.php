<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A liability can carry a promotional interest rate (e.g. 0%) that runs between
 * two dates: budget_accounts.promo_rate, promo_start, promo_end. promo_warned
 * holds the promo_end value an expiry warning was last sent for, so each end
 * date is warned about once. All NULL for every existing account.
 */
class Version001000132Date20261111 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_accounts')) {
			return null;
		}
		$table = $schema->getTable('budget_accounts');
		$changed = false;

		if (!$table->hasColumn('promo_rate')) {
			$table->addColumn('promo_rate', Types::DECIMAL, [
				'notnull' => false,
				'precision' => 10,
				'scale' => 4,
			]);
			$changed = true;
		}
		foreach (['promo_start', 'promo_end', 'promo_warned'] as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, Types::DATE, [
					'notnull' => false,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
