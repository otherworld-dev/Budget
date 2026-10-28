<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync\EnableBanking;

/**
 * Raised when Enable Banking answers HTTP 429 (too many requests, typically the
 * ASPSP's daily access limit), so callers can back off instead of retrying.
 */
class EnableBankingRateLimitException extends \RuntimeException {
}
