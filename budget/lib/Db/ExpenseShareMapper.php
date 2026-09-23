<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ExpenseShare>
 */
class ExpenseShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_expense_shares', ExpenseShare::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): ExpenseShare {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * @return ExpenseShare[]
	 */
	public function findAll(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find all shares for a transaction.
	 *
	 * @return ExpenseShare[]
	 */
	public function findByTransaction(int $transactionId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntities($qb);
	}

	/**
	 * Find all shares for a contact.
	 *
	 * @return ExpenseShare[]
	 */
	public function findByContact(int $contactId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('contact_id', $qb->createNamedParameter($contactId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Delete every share with a contact, settled or not.
	 *
	 * @return int Number of deleted rows
	 */
	public function deleteByContact(int $contactId, string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('contact_id', $qb->createNamedParameter($contactId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $qb->executeStatement();
	}

	/**
	 * Find all unsettled shares.
	 *
	 * @return ExpenseShare[]
	 */
	public function findUnsettled(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('is_settled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find unsettled shares for a specific contact.
	 *
	 * @return ExpenseShare[]
	 */
	public function findUnsettledByContact(int $contactId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('contact_id', $qb->createNamedParameter($contactId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('is_settled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find expense shares directed at a given Nextcloud user — i.e. shares whose
	 * contact is linked to that user (contacts.nextcloud_user_id). This is the
	 * recipient side: "expenses shared with me" (#248).
	 *
	 * Returns raw rows enriched with the owner (share creator) and transaction
	 * details, since these span the owner's transaction which the recipient
	 * doesn't own.
	 *
	 * Pass $ownerUserId to narrow it to what one user shared with them — the
	 * recipient's contact card for that user (#390).
	 *
	 * @return array[] Rows with keys: id, owner_user_id, transaction_id, amount,
	 *                 is_settled, notes, currency, created_at, contact_name,
	 *                 transaction_description, transaction_date, transaction_amount,
	 *                 transaction_type
	 */
	public function findSharedWithNextcloudUser(string $nextcloudUserId, ?string $ownerUserId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('es.id', 'es.transaction_id', 'es.amount', 'es.is_settled', 'es.notes', 'es.currency', 'es.created_at')
			->selectAlias('es.user_id', 'owner_user_id')
			->selectAlias('c.name', 'contact_name')
			->selectAlias('t.description', 'transaction_description')
			->selectAlias('t.date', 'transaction_date')
			->selectAlias('t.amount', 'transaction_amount')
			->selectAlias('t.type', 'transaction_type')
			->from($this->getTableName(), 'es')
			->innerJoin('es', 'budget_contacts', 'c', $qb->expr()->eq('es.contact_id', 'c.id'))
			->leftJoin('es', 'budget_transactions', 't', $qb->expr()->eq('es.transaction_id', 't.id'))
			->where($qb->expr()->eq('c.nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId)))
			->orderBy('es.created_at', 'DESC');

		if ($ownerUserId !== null) {
			$qb->andWhere($qb->expr()->eq('es.user_id', $qb->createNamedParameter($ownerUserId)));
		}

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Unsettled totals of what other users have shared with a Nextcloud user,
	 * per sharer and currency (#390). Amounts are from the sharer's side:
	 * positive means the recipient owes the sharer.
	 *
	 * @return array<string, array<string, float>> Owner user ID => [currency => balance]
	 */
	public function getIncomingBalancesByOwner(string $nextcloudUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('es.currency')
			->selectAlias('es.user_id', 'owner_user_id')
			->selectAlias($qb->func()->sum('es.amount'), 'balance')
			->from($this->getTableName(), 'es')
			->innerJoin('es', 'budget_contacts', 'c', $qb->expr()->eq('es.contact_id', 'c.id'))
			->where($qb->expr()->eq('c.nextcloud_user_id', $qb->createNamedParameter($nextcloudUserId)))
			->andWhere($qb->expr()->eq('es.is_settled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('es.user_id', 'es.currency');

		$result = $qb->executeQuery();
		$balances = [];
		while ($row = $result->fetch()) {
			$owner = $row['owner_user_id'];
			$currency = $row['currency'] ?? 'USD';
			// += because a NULL currency folds into USD alongside a real USD group
			$balances[$owner][$currency] = ($balances[$owner][$currency] ?? 0.0) + (float)$row['balance'];
		}
		$result->closeCursor();

		return $balances;
	}

	/**
	 * Delete all shares for a transaction.
	 */
	public function deleteByTransaction(int $transactionId, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}

	/**
	 * Get balance summary per contact, grouped by currency.
	 *
	 * @return array<int, array<string, float>> Contact ID => [currency => balance]
	 */
	public function getBalancesByContact(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('contact_id', 'currency')
			->selectAlias($qb->func()->sum('amount'), 'balance')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('is_settled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('contact_id', 'currency');

		$result = $qb->executeQuery();
		$balances = [];
		while ($row = $result->fetch()) {
			$contactId = (int)$row['contact_id'];
			$currency = $row['currency'] ?? 'USD';
			if (!isset($balances[$contactId])) {
				$balances[$contactId] = [];
			}
			$balances[$contactId][$currency] = (float)$row['balance'];
		}
		$result->closeCursor();

		return $balances;
	}

	/**
	 * Get shared transaction IDs with their settlement status.
	 *
	 * @return array<int, string> transaction_id => 'shared' or 'settled'
	 */
	public function getSharedTransactionStatuses(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('transaction_id')
			->addSelect('is_settled')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$statuses = [];
		while ($row = $result->fetch()) {
			$txId = (int)$row['transaction_id'];
			$isSettled = (bool)$row['is_settled'];
			// If any share on this transaction is unsettled, status is 'shared'
			if (!isset($statuses[$txId])) {
				$statuses[$txId] = $isSettled ? 'settled' : 'shared';
			} elseif (!$isSettled) {
				$statuses[$txId] = 'shared';
			}
		}
		$result->closeCursor();

		return $statuses;
	}

	/**
	 * Delete all expense shares for a user
	 *
	 * @param string $userId
	 * @return int Number of deleted rows
	 */
	public function deleteAll(string $userId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}
}
