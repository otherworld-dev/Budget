<?php

declare(strict_types=1);

namespace OCA\Budget\Exception;

/**
 * Thrown when reverting a bill payment would delete a transaction reconciled
 * against a bank statement. The controller answers with a machine-readable
 * code so the client can warn and send the revert again once the user agrees.
 */
class ReconciledPaymentException extends \Exception {
}
