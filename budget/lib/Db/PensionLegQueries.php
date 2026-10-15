<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Finding the two records of one pension payment (#304).
 *
 * When a contribution is paid from an account that also gets its
 * transactions from statement imports or bank sync, the same money arrives
 * twice: as the bank leg the app books, and as the bank's own row. These
 * queries find each side so PensionService can keep one of them.
 */
class PensionLegQueries {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Rows an import or bank sync brought into the account that could be the
	 * bank's record of a pension payment: the same direction and amount,
	 * dated within the window, and not already claimed by a pension entry, a
	 * bill or a transfer.
	 *
	 * @return array<int, array{id: int, date: string, description: ?string, vendor: ?string}>
	 */
	public function findImportedCandidates(int $accountId, string $type, float $amount, string $from, string $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'date', 'description', 'vendor')
			->from('budget_transactions')
			->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('amount', $qb->createNamedParameter($amount)))
			->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('date', $qb->createNamedParameter($to)))
			->andWhere($qb->expr()->isNotNull('import_id'))
			->andWhere($qb->expr()->neq('import_id', $qb->createNamedParameter('')))
			->andWhere($qb->expr()->isNull('pension_contrib_id'))
			->andWhere($qb->expr()->isNull('linked_transaction_id'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('bill_id'),
				$qb->expr()->eq('bill_id', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			))
			->andWhere($qb->expr()->orX(
				$qb->expr()->neq('status', $qb->createNamedParameter('scheduled')),
				$qb->expr()->isNull('status')
			))
			->orderBy('date', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = [
				'id' => (int)$row['id'],
				'date' => (string)$row['date'],
				'description' => $row['description'] !== null ? (string)$row['description'] : null,
				'vendor' => $row['vendor'] !== null ? (string)$row['vendor'] : null,
			];
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * Bank legs the app booked for pension entries in the account, dated
	 * within the window: they carry a pension entry and no import id.
	 *
	 * @return array<int, array{id: int, date: string, type: string, amount: float, pensionContribId: int, reconciled: bool}>
	 */
	public function findAppCreatedLegs(int $accountId, string $from, string $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'date', 'type', 'amount', 'pension_contrib_id', 'reconciled')
			->from('budget_transactions')
			->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('pension_contrib_id'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('import_id'),
				$qb->expr()->eq('import_id', $qb->createNamedParameter(''))
			))
			->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('date', $qb->createNamedParameter($to)))
			->orderBy('date', 'ASC')
			->addOrderBy('id', 'ASC');

		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = [
				'id' => (int)$row['id'],
				'date' => (string)$row['date'],
				'type' => (string)$row['type'],
				'amount' => (float)$row['amount'],
				'pensionContribId' => (int)$row['pension_contrib_id'],
				'reconciled' => (bool)$row['reconciled'],
			];
		}
		$result->closeCursor();
		return $rows;
	}
}
