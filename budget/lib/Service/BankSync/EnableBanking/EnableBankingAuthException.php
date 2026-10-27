<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync\EnableBanking;

/**
 * Raised when Enable Banking rejects our credentials (HTTP 401/403), e.g. a wrong
 * application ID or private key, or an expired/revoked session.
 */
class EnableBankingAuthException extends \RuntimeException {
}
