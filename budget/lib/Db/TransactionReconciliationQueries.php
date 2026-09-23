<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The reconciliation-session queries over budget_transactions: ticking rows
 * into and out of a session, the ticked and reconciled sums, and marking a
 * finished session's rows reconciled.
 *
 * Split out of TransactionMapper, which keeps the entity queries. Used by
 * ReconciliationService.
 */
class TransactionReconciliationQueries {
    private const TABLE = 'budget_transactions';

    public function __construct(
        private IDBConnection $db
    ) {
    }

    /**
     * Signed net of all already-reconciled, non-scheduled transactions —
     * the first-session anchor: opening_balance + this = cleared balance.
     */
    public function getReconciledNetChange(int $accountId): float {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias(
                $qb->createFunction('COALESCE(SUM(CASE WHEN t.type = \'credit\' THEN t.amount ELSE -t.amount END), 0)'),
                'net_change'
            )
            ->from(self::TABLE, 't')
            ->where($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('t.reconciled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->neq('t.status', $qb->createNamedParameter('scheduled')),
                    $qb->expr()->isNull('t.status')
                )
            );

        $result = $qb->executeQuery();
        $netChange = (float) $result->fetchOne();
        $result->closeCursor();

        return $netChange;
    }

    /**
     * Signed net of the transactions ticked into a reconciliation session.
     */
    public function getSessionTickedSum(int $sessionId): float {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias(
                $qb->createFunction('COALESCE(SUM(CASE WHEN t.type = \'credit\' THEN t.amount ELSE -t.amount END), 0)'),
                'net_change'
            )
            ->from(self::TABLE, 't')
            ->where($qb->expr()->eq('t.recon_session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->neq('t.status', $qb->createNamedParameter('scheduled')),
                    $qb->expr()->isNull('t.status')
                )
            );

        $result = $qb->executeQuery();
        $sum = (float) $result->fetchOne();
        $result->closeCursor();

        return $sum;
    }

    /**
     * Ids of transactions currently ticked into a session.
     *
     * @return int[]
     */
    public function getSessionTransactionIds(int $sessionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('recon_session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $ids = array_map(fn($row) => (int) $row['id'], $result->fetchAll());
        $result->closeCursor();

        return $ids;
    }

    /**
     * Tick transactions into a session (account-scoped; already-reconciled
     * rows and rows belonging to another session are never grabbed).
     */
    public function tickIntoSession(int $accountId, array $transactionIds, int $sessionId): int {
        if (empty($transactionIds)) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('recon_session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('id', $qb->createNamedParameter($transactionIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('reconciled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
                $qb->expr()->isNull('reconciled')
            ))
            ->andWhere($qb->expr()->isNull('recon_session_id'));

        return $qb->executeStatement();
    }

    /**
     * Untick transactions from a session.
     */
    public function untickFromSession(int $accountId, array $transactionIds, int $sessionId): int {
        if (empty($transactionIds)) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('recon_session_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('id', $qb->createNamedParameter($transactionIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('recon_session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('reconciled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
                $qb->expr()->isNull('reconciled')
            ));

        return $qb->executeStatement();
    }

    /**
     * Cancel a session: release its ticked (not yet reconciled) transactions.
     */
    public function clearSession(int $sessionId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('recon_session_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq('recon_session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('reconciled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
                $qb->expr()->isNull('reconciled')
            ));

        return $qb->executeStatement();
    }

    /**
     * Complete a session: mark everything ticked into it as reconciled.
     * The session linkage stays — permanent provenance for history.
     */
    public function markSessionReconciled(int $sessionId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('reconciled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
            ->where($qb->expr()->eq('recon_session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    /**
     * Tick every transaction of an account dated on or before the statement
     * date into a session, in one statement.
     *
     * Same predicate as {@see countUntickedBefore()} - already ticked, already
     * reconciled and scheduled rows are all left alone - because the count the
     * button offers has to be the number it then ticks. Ticking these one page
     * at a time is what made a first reconciliation of a real ledger 35 pages
     * of clicking (#374).
     */
    public function tickAllUpTo(int $accountId, string $statementDate, int $sessionId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('recon_session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('date', $qb->createNamedParameter($statementDate)))
            ->andWhere($qb->expr()->isNull('recon_session_id'))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('reconciled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
                $qb->expr()->isNull('reconciled')
            ))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->neq('status', $qb->createNamedParameter('scheduled')),
                $qb->expr()->isNull('status')
            ));

        return $qb->executeStatement();
    }

    /**
     * Unreconciled, unticked, non-scheduled transactions dated on or before
     * the statement date — surfaced as a heads-up when completing.
     */
    public function countUntickedBefore(int $accountId, string $statementDate): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
            ->from(self::TABLE, 't')
            ->where($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($statementDate)))
            ->andWhere($qb->expr()->isNull('t.recon_session_id'))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('t.reconciled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
                $qb->expr()->isNull('t.reconciled')
            ))
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->neq('t.status', $qb->createNamedParameter('scheduled')),
                    $qb->expr()->isNull('t.status')
                )
            );

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }
}
