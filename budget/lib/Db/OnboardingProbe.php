<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Cheap yes/no questions about what a user has, for the first-run checklist.
 * Each is one LIMIT 1 query, so the checklist can ask on every dashboard load
 * without counting anybody's transactions.
 */
class OnboardingProbe {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function hasAccounts(string $userId): bool {
		return $this->exists('budget_accounts', $userId);
	}

	public function hasCategories(string $userId): bool {
		return $this->exists('budget_categories', $userId);
	}

	public function hasBankConnection(string $userId): bool {
		return $this->exists('budget_bc', $userId);
	}

	/** A category of the user's own with a budget above zero */
	public function hasBudget(string $userId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('budget_categories')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gt('budget_amount', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		return $this->fetchesRow($qb);
	}

	/** A transaction in one of the user's own accounts */
	public function hasTransactions(string $userId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id')
			->from('budget_transactions', 't')
			->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
			->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
			->setMaxResults(1);
		return $this->fetchesRow($qb);
	}

	/** Someone else's budget, shared with this user and accepted */
	public function hasIncomingShare(string $userId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('budget_shares')
			->where($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Share::STATUS_ACCEPTED)))
			->setMaxResults(1);
		return $this->fetchesRow($qb);
	}

	private function exists(string $table, string $userId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($table)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->setMaxResults(1);
		return $this->fetchesRow($qb);
	}

	private function fetchesRow(IQueryBuilder $qb): bool {
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}
}
