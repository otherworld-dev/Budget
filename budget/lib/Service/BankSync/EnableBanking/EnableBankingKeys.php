<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync\EnableBanking;

/**
 * The user's Enable Banking application ID and private key, carried as one
 * object instead of two strings.
 *
 * With `zend.exception_ignore_args` off (the PHP default without a
 * production php.ini, e.g. in the official Docker images) every stack frame
 * of a logged exception keeps its arguments, and Nextcloud writes them to
 * nextcloud.log. A PEM passed as a string argument would land there.
 * `#[\SensitiveParameter]` prevents that from PHP 8.2 on, but the app still
 * supports 8.1, where the attribute is ignored. Frames only show this object:
 * the properties are private (Nextcloud's log serializer reads public ones
 * only) and the debug view leaves the key out.
 */
final class EnableBankingKeys {
	public function __construct(
		private string $applicationId,
		#[\SensitiveParameter]
		private string $privateKey,
	) {
	}

	public function applicationId(): string {
		return $this->applicationId;
	}

	public function privateKey(): string {
		return $this->privateKey;
	}

	/**
	 * What var_dump(), print_r() and debuggers show instead of the properties.
	 */
	public function __debugInfo(): array {
		return ['applicationId' => $this->applicationId, 'privateKey' => '[redacted]'];
	}
}
