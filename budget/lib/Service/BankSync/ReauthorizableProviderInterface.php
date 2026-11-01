<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

/**
 * A provider whose expired consent can be renewed on the existing connection,
 * so account mappings and imported history survive a re-authorization.
 */
interface ReauthorizableProviderInterface {
	/**
	 * Start a new bank authorization for an existing connection. Returns the
	 * same shape as BankSyncProviderInterface::initializeConnection().
	 *
	 * @param string $credentials Current stored credentials (decrypted)
	 * @param array $params Provider-specific parameters (e.g. redirectUrl)
	 * @return array{credentials: string, accounts: array, authorizationUrl: string}
	 * @throws \Exception on API failure
	 */
	public function reinitializeConnection(string $credentials, array $params): array;
}
