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
 *
 * A row that is also on the bill's last paid date is a payment unless the
 * snapshot names it as the pending row: 2.54.0 recorded late payments (the
 * missed-payment row and Record transaction) on the paid date without ever
 * naming them in the snapshot, and Skip wipes the snapshot altogether. When
 * nothing vouches for it as a placeholder, it is left booked.
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
		$qb->select('t.id', 't.account_id', 't.date', 'b.last_paid_date', 'b.paid_undo_state')
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
			if ($this->onLastPaidDate($row) && !$this->namedAsPending($row)) {
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

	private function onLastPaidDate(array $row): bool {
		if ($row['last_paid_date'] === null || $row['last_paid_date'] === '') {
			return false;
		}
		return substr((string)$row['date'], 0, 10) === substr((string)$row['last_paid_date'], 0, 10);
	}

	private function namedAsPending(array $row): bool {
		$snapshot = $row['paid_undo_state'] === null ? null : json_decode((string)$row['paid_undo_state'], true);
		if (!is_array($snapshot) || !is_array($snapshot['scheduledTransactionIds'] ?? null)) {
			return false;
		}
		foreach ($snapshot['scheduledTransactionIds'] as $id) {
			if (is_numeric($id) && (int)$id === (int)$row['id']) {
				return true;
			}
		}
		return false;
	}
}
