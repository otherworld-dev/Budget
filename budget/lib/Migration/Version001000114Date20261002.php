<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BillPaymentRows;
use OCA\Budget\Service\AccountBalanceCalculator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Put a bill payment back to cleared when an old Repair run switched it to
 * scheduled.
 *
 * Repair's "future cleared" fix switched any cleared row dated after the
 * server's today to scheduled, bill payments included. A bill's scheduled
 * row is its placeholder to everything else: the scheduled job never clears
 * it, and the next Mark Paid, Skip or Mark Unpaid deleted it as one, taking
 * a real payment with it. Repair no longer touches bill rows. Only a row the
 * bill's own Mark Unpaid snapshot names as its payment is restored; one no
 * snapshot vouches for could be a placeholder, and is left as it is.
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

		$ids = [];
		$accounts = [];
		foreach (array_chunk(BillPaymentRows::ids($this->db), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'account_id')
				->from('budget_transactions')
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('scheduled')));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$ids[] = (int)$row['id'];
				$accounts[(int)$row['account_id']] = true;
			}
			$result->closeCursor();
		}

		if ($ids === []) {
			return;
		}

		foreach (array_chunk($ids, 500) as $chunk) {
			$update = $this->db->getQueryBuilder();
			$update->update('budget_transactions')
				->set('status', $update->createNamedParameter('cleared'))
				->where($update->expr()->in('id', $update->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$update->executeStatement();
		}

		foreach (array_keys($accounts) as $accountId) {
			try {
				$this->balanceCalculator->recalculate($this->accountMapper->findById($accountId));
			} catch (\Exception $e) {
				$output->warning("Could not recalculate the balance of account {$accountId}: {$e->getMessage()}");
			}
		}

		$output->info('Put ' . count($ids) . ' bill payment(s) switched to scheduled back to cleared');
	}
}
