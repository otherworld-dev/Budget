<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\ReconciliationSession;
use OCA\Budget\Db\ReconciliationSessionMapper;
use OCA\Budget\Db\TransactionMapper;
use OCP\IL10N;

/**
 * Statement reconciliation sessions.
 *
 * The difference is anchored on the PREVIOUS reconciliation, not the
 * account's current balance:
 *
 *   starting = last completed session's statement balance
 *              (first session: opening balance + net of already-reconciled)
 *   difference = statement balance − (starting + Σ ticked signed amounts)
 *
 * Ticking a transaction moves the difference; completing requires it to be
 * zero (±0.005). Sessions persist (leave the page, resume later) and
 * completed sessions form the account's reconciliation history with
 * snapshotted balances.
 */
class ReconciliationService {

    private const TOLERANCE = '0.005';

    public function __construct(
        private ReconciliationSessionMapper $sessionMapper,
        private TransactionMapper $transactionMapper,
        private AccountMapper $accountMapper,
        private AuditService $auditService,
        private IL10N $l,
    ) {
    }

    /**
     * The active session for an account with live sums, or null.
     */
    public function getActiveSession(int $accountId, string $userId): ?array {
        $this->accountMapper->find($accountId, $userId); // ownership or 404

        $session = $this->sessionMapper->findInProgress($accountId, $userId);
        if ($session === null) {
            return null;
        }

        return $this->sessionState($session);
    }

    /**
     * Start a session. Throws SessionConflictException carrying the existing
     * session when one is already in progress (the UI offers resume).
     */
    public function startSession(int $accountId, string $userId, float $statementBalance, string $statementDate): array {
        $account = $this->accountMapper->find($accountId, $userId);

        $existing = $this->sessionMapper->findInProgress($accountId, $userId);
        if ($existing !== null) {
            throw new ReconciliationConflictException($this->sessionState($existing));
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $statementDate)) {
            throw new \InvalidArgumentException($this->l->t('Invalid statement date'));
        }

        $startingBalance = $this->computeStartingBalance($accountId, $userId, $account->getOpeningBalance());

        $session = new ReconciliationSession();
        $session->setAccountId($accountId);
        $session->setUserId($userId);
        $session->setStatementDate($statementDate);
        $session->setStatementBalance(number_format($statementBalance, 2, '.', ''));
        $session->setStartingBalance($startingBalance);
        $session->setStatus(ReconciliationSession::STATUS_IN_PROGRESS);
        $session->setReconciledCount(0);
        $session->setCreatedAt(date('Y-m-d H:i:s'));

        $session = $this->sessionMapper->insert($session);

        return $this->sessionState($session);
    }

    /**
     * Edit the statement balance/date of the active session.
     */
    public function updateSession(int $accountId, string $userId, ?float $statementBalance, ?string $statementDate): array {
        $session = $this->requireActiveSession($accountId, $userId);

        if ($statementBalance !== null) {
            $session->setStatementBalance(number_format($statementBalance, 2, '.', ''));
        }
        if ($statementDate !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $statementDate)) {
                throw new \InvalidArgumentException($this->l->t('Invalid statement date'));
            }
            $session->setStatementDate($statementDate);
        }
        $session = $this->sessionMapper->update($session);

        return $this->sessionState($session);
    }

    /**
     * Tick or untick transactions; returns authoritative live sums.
     *
     * @param int[] $transactionIds
     */
    public function tick(int $accountId, string $userId, array $transactionIds, bool $ticked): array {
        $session = $this->requireActiveSession($accountId, $userId);

        $transactionIds = array_values(array_unique(array_map('intval', $transactionIds)));
        if (count($transactionIds) > 500) {
            throw new \InvalidArgumentException($this->l->t('Too many transactions in one request (max 500)'));
        }

        if ($ticked) {
            $this->transactionMapper->tickIntoSession($accountId, $transactionIds, $session->getId());
        } else {
            $this->transactionMapper->untickFromSession($accountId, $transactionIds, $session->getId());
        }

        return $this->sessionState($session);
    }

    /**
     * Complete the session: requires the difference to be zero (±tolerance);
     * marks ticked transactions reconciled and stamps the account.
     */
    public function complete(int $accountId, string $userId): array {
        $session = $this->requireActiveSession($accountId, $userId);

        $state = $this->sessionState($session);
        if (!$state['isBalanced']) {
            throw new \InvalidArgumentException(
                $this->l->t('The difference must be zero before finishing — %1$s left to resolve', [
                    number_format(abs($state['difference']), 2),
                ])
            );
        }

        $untickedBefore = $this->transactionMapper->countUntickedBefore($accountId, $session->getStatementDate());

        $reconciledCount = $this->transactionMapper->markSessionReconciled($session->getId());

        $now = date('Y-m-d H:i:s');
        $session->setStatus(ReconciliationSession::STATUS_COMPLETED);
        $session->setReconciledCount($reconciledCount);
        $session->setCompletedAt($now);
        $this->sessionMapper->update($session);

        $account = $this->accountMapper->find($accountId, $userId);
        $account->setLastReconciled($now);
        $this->accountMapper->update($account);

        $this->auditService->log($userId, 'reconciliation_completed', 'account', $accountId, [
            'sessionId' => $session->getId(),
            'statementDate' => $session->getStatementDate(),
            'statementBalance' => $session->getStatementBalance(),
            'reconciledCount' => $reconciledCount,
        ]);

        return [
            'session' => $session->jsonSerialize(),
            'reconciledCount' => $reconciledCount,
            'untickedBeforeStatementDate' => $untickedBefore,
        ];
    }

    /**
     * Cancel the active session: release ticked transactions, drop the row.
     */
    public function cancel(int $accountId, string $userId): void {
        $session = $this->requireActiveSession($accountId, $userId);

        $this->transactionMapper->clearSession($session->getId());
        $this->sessionMapper->delete($session);
    }

    /**
     * Completed sessions, newest first.
     */
    public function getHistory(int $accountId, string $userId, int $limit = 20, int $offset = 0): array {
        $this->accountMapper->find($accountId, $userId); // ownership or 404

        $sessions = $this->sessionMapper->findCompletedByAccount($accountId, $userId, min($limit, 100), $offset);
        return array_map(fn(ReconciliationSession $s) => $s->jsonSerialize(), $sessions);
    }

    /**
     * Session payload with live sums: ticked ids, ticked total, difference.
     */
    private function sessionState(ReconciliationSession $session): array {
        $tickedSum = $this->transactionMapper->getSessionTickedSum($session->getId());
        $tickedIds = $this->transactionMapper->getSessionTransactionIds($session->getId());

        $cleared = MoneyCalculator::add($session->getStartingBalance(), (string) $tickedSum);
        $difference = MoneyCalculator::subtract($session->getStatementBalance(), $cleared);

        return [
            'session' => $session->jsonSerialize(),
            'tickedIds' => $tickedIds,
            'tickedCount' => count($tickedIds),
            'tickedSum' => MoneyCalculator::toFloat(MoneyCalculator::add('0', (string) $tickedSum)),
            'clearedBalance' => MoneyCalculator::toFloat($cleared),
            'difference' => MoneyCalculator::toFloat($difference),
            'isBalanced' => MoneyCalculator::equals($difference, '0', self::TOLERANCE),
        ];
    }

    /**
     * First-session anchor: opening balance plus the net of everything
     * already marked reconciled (trusts legacy flags — shown in the UI so
     * the user can sanity-check before ticking).
     */
    private function computeStartingBalance(int $accountId, string $userId, ?float $openingBalance): string {
        $last = $this->sessionMapper->findLastCompleted($accountId, $userId);
        if ($last !== null) {
            // DECIMAL columns hydrate as float on some drivers (SQLite)
            return (string) $last->getStatementBalance();
        }

        return MoneyCalculator::add(
            (string) ($openingBalance ?? 0),
            (string) $this->transactionMapper->getReconciledNetChange($accountId)
        );
    }

    private function requireActiveSession(int $accountId, string $userId): ReconciliationSession {
        $this->accountMapper->find($accountId, $userId); // ownership or 404

        $session = $this->sessionMapper->findInProgress($accountId, $userId);
        if ($session === null) {
            throw new \InvalidArgumentException($this->l->t('No reconciliation in progress for this account'));
        }
        return $session;
    }
}

/**
 * Thrown when starting a session while one is already in progress; carries
 * the existing session state so the controller can offer resume (409).
 */
class ReconciliationConflictException extends \RuntimeException {
    public function __construct(
        public readonly array $existingState,
    ) {
        parent::__construct('A reconciliation is already in progress for this account');
    }
}
