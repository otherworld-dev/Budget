<?php

declare(strict_types=1);

namespace OCA\Budget\Exception;

/**
 * Thrown when an account cannot be deleted because transactions still belong to
 * it. The controller surfaces a machine-readable code and the transaction count
 * so the client can offer to delete the ledger along with the account and retry
 * (#336).
 */
class AccountInUseException extends \Exception {
    private int $transactionCount;

    public function __construct(string $message, int $transactionCount = 0) {
        parent::__construct($message);
        $this->transactionCount = $transactionCount;
    }

    public function getTransactionCount(): int {
        return $this->transactionCount;
    }
}
