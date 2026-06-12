<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A statement reconciliation session: the user reconciles an account against
 * a bank statement (ending balance + date), ticking transactions off until
 * the difference reaches zero. Completed sessions are the account's
 * permanent reconciliation history; their balances are snapshotted so the
 * history stays meaningful even if underlying transactions later change.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getAccountId()
 * @method void setAccountId(int $accountId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getStatementDate()
 * @method void setStatementDate(string $statementDate)
 * @method string getStatementBalance()
 * @method void setStatementBalance(string $statementBalance)
 * @method string getStartingBalance()
 * @method void setStartingBalance(string $startingBalance)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getReconciledCount()
 * @method void setReconciledCount(int $reconciledCount)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getCompletedAt()
 * @method void setCompletedAt(?string $completedAt)
 */
class ReconciliationSession extends Entity implements JsonSerializable {
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';

    protected $accountId;
    protected $userId;
    protected $statementDate;
    protected $statementBalance;
    protected $startingBalance;
    protected $status;
    protected $reconciledCount;
    protected $completedAt;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('accountId', 'integer');
        $this->addType('reconciledCount', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'accountId' => $this->getAccountId(),
            'statementDate' => $this->getStatementDate(),
            'statementBalance' => (float) $this->getStatementBalance(),
            'startingBalance' => (float) $this->getStartingBalance(),
            'status' => $this->getStatus(),
            'reconciledCount' => $this->getReconciledCount(),
            'createdAt' => $this->getCreatedAt(),
            'completedAt' => $this->getCompletedAt(),
        ];
    }
}
