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
 * Put a bill's pre-booked row back to pending when its occurrence is still
 * unpaid.
 *
 * The scheduled job used to clear a bill's row on its due date while the
 * bill stayed unpaid, so the money was booked and then booked again when the
 * bill was paid. The job no longer does that, but rows it already cleared
 * would still double up on the next Mark Paid. Which rows qualify is
 * LegacyBillRows::unpaidPlaceholders(), which a restore of a backup made
 * before 3.0 applies to the rows it brings back.
 */
class Version001000109Date20261002 extends SimpleMigrationStep {
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

		$rows = LegacyBillRows::unpaidPlaceholders($this->db);
		if ($rows === []) {
			return;
		}

		LegacyBillRows::setStatus($this->db, array_keys($rows), 'scheduled');

		foreach (array_unique(array_values($rows)) as $accountId) {
			try {
				$this->balanceCalculator->recalculate($this->accountMapper->findById($accountId));
			} catch (\Exception $e) {
				$output->warning("Could not recalculate the balance of account {$accountId}: {$e->getMessage()}");
			}
		}

		$output->info('Put ' . count($rows) . ' pre-booked bill row(s) of unpaid occurrences back to pending');
	}
}
