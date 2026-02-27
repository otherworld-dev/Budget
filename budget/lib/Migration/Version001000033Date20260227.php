<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add cryptocurrency account support.
 *
 * - Widen currency column to accommodate crypto tickers (e.g., DOGE, LINK, USDT)
 * - Add wallet_address column for encrypted wallet/exchange address storage
 * - Increase decimal precision on balance/amount columns for crypto quantities
 *   (Bitcoin uses 8 decimal places, most other cryptos use 6-8)
 *
 * Implements: https://github.com/otherworld-dev/budget/issues/47
 */
class Version001000033Date20260227 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Widen currency column and add wallet address to accounts
		if ($schema->hasTable('budget_accounts')) {
			$table = $schema->getTable('budget_accounts');

			// Widen currency from VARCHAR(3) to VARCHAR(10) for crypto tickers
			if ($table->hasColumn('currency')) {
				$table->modifyColumn('currency', [
					'length' => 10,
				]);
			}

			// Add encrypted wallet address column
			if (!$table->hasColumn('wallet_address')) {
				$table->addColumn('wallet_address', Types::TEXT, [
					'notnull' => false,
					'default' => null,
				]);
			}

			// Increase balance precision from DECIMAL(15,2) to DECIMAL(20,8)
			if ($table->hasColumn('balance')) {
				$table->modifyColumn('balance', [
					'precision' => 20,
					'scale' => 8,
				]);
			}
		}

		// Increase transaction amount precision
		if ($schema->hasTable('budget_transactions')) {
			$table = $schema->getTable('budget_transactions');

			if ($table->hasColumn('amount')) {
				$table->modifyColumn('amount', [
					'precision' => 20,
					'scale' => 8,
				]);
			}
		}

		// Increase transaction split amount precision
		if ($schema->hasTable('budget_tx_splits')) {
			$table = $schema->getTable('budget_tx_splits');

			if ($table->hasColumn('amount')) {
				$table->modifyColumn('amount', [
					'precision' => 20,
					'scale' => 8,
				]);
			}
		}

		return $schema;
	}
}
