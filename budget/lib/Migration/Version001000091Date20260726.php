<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Widen the money columns that can hold an amount in a non-fiat (crypto)
 * currency to 8 decimal places: transaction amounts, split amounts, and account
 * balance / opening balance. A crypto account and its transactions are stored in
 * the account's native currency, so they need up to 8dp (BTC/ETH); fiat amounts
 * are unaffected — widening is lossless and each currency is still displayed and
 * entered at its own Currency::decimals() (#331).
 *
 * Precision 24 keeps well over the previous 13 integer digits alongside 8 decimals.
 */
class Version001000091Date20260726 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_transactions')) {
			$table = $schema->getTable('budget_transactions');
			if ($table->hasColumn('amount')) {
				$table->changeColumn('amount', [
					'notnull' => true,
					'precision' => 24,
					'scale' => 8,
				]);
			}
		}

		if ($schema->hasTable('budget_tx_splits')) {
			$table = $schema->getTable('budget_tx_splits');
			if ($table->hasColumn('amount')) {
				$table->changeColumn('amount', [
					'notnull' => true,
					'precision' => 24,
					'scale' => 8,
				]);
			}
		}

		if ($schema->hasTable('budget_accounts')) {
			$table = $schema->getTable('budget_accounts');
			if ($table->hasColumn('balance')) {
				$table->changeColumn('balance', [
					'notnull' => true,
					'precision' => 24,
					'scale' => 8,
					'default' => 0,
				]);
			}
			if ($table->hasColumn('opening_balance')) {
				$table->changeColumn('opening_balance', [
					'notnull' => false,
					'precision' => 24,
					'scale' => 8,
					'default' => 0,
				]);
			}
		}

		return $schema;
	}
}
