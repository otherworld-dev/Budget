<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\LegacyBillRows;
use OCA\Budget\Service\AccountBalanceCalculator;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Put a bill payment back to cleared when an old Repair run switched it to
 * scheduled.
 *
 * Repair's "future cleared" fix switched any cleared row dated after the
 * server's today to scheduled, bill payments included, and the next Mark
 * Paid, Skip or Mark Unpaid deleted it as a placeholder. Repair no longer
 * touches bill rows. Which rows qualify is
 * LegacyBillRows::rescheduledPayments(), which a restore of a backup made
 * before 3.0 applies to the rows it brings back.
 */
class Version001000114Date20261002 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
		private AccountMapper $accountMapper,
		private AccountBalanceCalculator $balanceCalculator,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('budget_transactions') || !$schema->hasTable('budget_bills')) {
			return;
		}

		$rows = LegacyBillRows::rescheduledPayments($this->db);
		if ($rows === []) {
			return;
		}

		LegacyBillRows::setStatus($this->db, array_keys($rows), 'cleared');

		foreach (array_unique(array_values($rows)) as $accountId) {
			try {
				$this->balanceCalculator->recalculate($this->accountMapper->findById($accountId));
			} catch (\Exception $e) {
				$output->warning("Could not recalculate the balance of account {$accountId}: {$e->getMessage()}");
			}
		}

		$output->info('Put ' . count($rows) . ' bill payment(s) switched to scheduled back to cleared');
	}
}
