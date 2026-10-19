<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCA\Budget\Db\DanglingLinks;
use OCA\Budget\Db\LegacyIncomeRows;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Clear up what older versions left in recurring income and transactions
 * (#399).
 *
 * - An income 2.54.0 received early stayed due on the date its credit was
 *   already booked for, and the next Mark Received booked the money again.
 *   Each one moves on to its next expected date; which ones qualify is
 *   LegacyIncomeRows::stuckEarlyReceipts(), which Repair Data runs as well.
 * - Payments of deleted bills still carrying the bill's id, and transfer
 *   sides still naming another side deleted long ago, let go of them
 *   (DanglingLinks).
 *
 * Nothing is booked or deleted, and no balance changes.
 */
class Version001000125Date20261019 extends SimpleMigrationStep {
	public function __construct(
		private LegacyIncomeRows $legacyIncomeRows,
		private DanglingLinks $danglingLinks,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('budget_transactions')) {
			return;
		}

		if ($schema->hasTable('budget_recurring_income')) {
			$settled = 0;
			foreach ($this->legacyIncomeRows->stuckEarlyReceipts() as $stuck) {
				if ($this->legacyIncomeRows->settle($stuck)) {
					$settled++;
				}
			}
			if ($settled > 0) {
				$output->info("Moved {$settled} recurring income(s) received early on to their next expected date");
			}
		}

		$transactions = $schema->getTable('budget_transactions');
		if ($schema->hasTable('budget_bills') && $transactions->hasColumn('bill_id') && $transactions->hasColumn('linked_transaction_id')) {
			$cleared = $this->danglingLinks->clear();
			if ($cleared > 0) {
				$output->info("Cleared {$cleared} reference(s) to bills or transactions that no longer exist");
			}
		}
	}
}
