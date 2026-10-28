<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync\EnableBanking;

/**
 * Raised when Enable Banking rejects our credentials (HTTP 401/403), e.g. a wrong
 * application ID or private key, or an expired/revoked session.
 */
class EnableBankingAuthException extends \RuntimeException {
	/** The application is restricted and has no linked account yet. */
	public const REASON_APPLICATION_INACTIVE = 'application_inactive';

	public function __construct(
		string $message = '',
		private readonly ?string $reason = null,
	) {
		parent::__construct($message);
	}

	/** A machine-readable cause, or null when the API gave no recognised one. */
	public function getReason(): ?string {
		return $this->reason;
	}
}
