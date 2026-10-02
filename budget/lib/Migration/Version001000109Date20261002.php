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
 * Put a bill's pre-booked row back to pending when its occurrence is still
 * unpaid.
 *
 * The scheduled job used to clear a bill's row on its due date while the
 * bill stayed unpaid, so the money was booked and then booked again when the
 * bill was paid. The job no longer does that, but rows it already cleared
 * would still double up on the next Mark Paid. A row qualifies only when the
 * app generated it for an active bill and it sits exactly on that bill's
 * next due date, the occurrence the bill still owes, and its snapshot doesn't
 * name it as the payment: a weekly bill paid a week late is paid on its next
 * due date. Reconciled rows stay as they are.
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

		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id', 't.account_id')
			->from('budget_transactions', 't')
			->innerJoin('t', 'budget_bills', 'b', $qb->expr()->eq('b.id', 't.bill_id'))
			->where($qb->expr()->eq('t.status', $qb->createNamedParameter('cleared')))
			->andWhere($qb->expr()->eq('t.date', 'b.next_due_date'))
			->andWhere($qb->expr()->eq('b.is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('t.reconciled'),
				$qb->expr()->eq('t.reconciled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			))
			->andWhere($qb->expr()->orX(
				$qb->expr()->like('t.notes', $qb->createNamedParameter('Auto-generated from bill:%')),
				$qb->expr()->like('t.notes', $qb->createNamedParameter('Auto-generated transfer:%'))
			));
		$result = $qb->executeQuery();
		$payments = array_flip(BillPaymentRows::ids($this->db));
		$ids = [];
		$accounts = [];
		while ($row = $result->fetch()) {
			if (isset($payments[(int)$row['id']])) {
				continue;
			}
			$ids[] = (int)$row['id'];
			$accounts[(int)$row['account_id']] = true;
		}
		$result->closeCursor();

		if ($ids === []) {
			return;
		}

		foreach (array_chunk($ids, 500) as $chunk) {
			$update = $this->db->getQueryBuilder();
			$update->update('budget_transactions')
				->set('status', $update->createNamedParameter('scheduled'))
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

		$output->info('Put ' . count($ids) . ' pre-booked bill row(s) of unpaid occurrences back to pending');
	}
}
