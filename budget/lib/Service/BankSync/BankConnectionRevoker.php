<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

use OCA\Budget\Db\BankConnectionMapper;
use Psr\Log\LoggerInterface;

/**
 * Ends a user's bank connections at the provider (a GoCardless requisition
 * is deleted, so the bank consent stops), best-effort.
 *
 * Disconnect always did this. A factory reset, clearing the sample data and
 * purging a deleted user delete the connection rows as a table, so the
 * consent the user gave their bank stayed live at the provider with nothing
 * left in Budget to end it. They now read the connections first and revoke
 * them once the rows are gone.
 *
 * Nothing here throws, and a failure is logged with the exception's class
 * and message only: the credentials are this class's arguments, and an
 * exception object carries them in its trace on PHP 8.1.
 */
class BankConnectionRevoker {
	public function __construct(
		private BankConnectionMapper $connectionMapper,
		private ProviderFactory $providerFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The user's connections with their decrypted credentials, read before
	 * the rows are deleted. A connection whose secret can't be read is
	 * skipped (there is nothing to revoke with), and a failed read gives an
	 * empty list: this must never be what stops a reset.
	 *
	 * @return list<array{id: int, provider: string, credentials: string}>
	 */
	public function collect(string $userId): array {
		try {
			$connections = $this->connectionMapper->findAll($userId);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not read the bank connections of {user} to revoke them: {class}: {message}', [
				'app' => 'budget',
				'user' => $userId,
				'class' => get_class($e),
				'message' => $e->getMessage(),
			]);
			return [];
		}

		$collected = [];
		foreach ($connections as $connection) {
			$credentials = $connection->getCredentials();
			if (!is_string($credentials) || $credentials === '') {
				continue;
			}
			$collected[] = [
				'id' => (int)$connection->getId(),
				'provider' => (string)$connection->getProvider(),
				'credentials' => $credentials,
			];
		}
		return $collected;
	}

	/**
	 * @param list<array{id: int, provider: string, credentials: string}> $connections from collect()
	 */
	public function revokeAll(array $connections): void {
		foreach ($connections as $connection) {
			$this->revoke($connection['provider'], $connection['credentials'], $connection['id']);
		}
	}

	public function revoke(string $providerName, ?string $credentials, ?int $connectionId = null): void {
		if ($credentials === null || $credentials === '') {
			return;
		}
		try {
			$this->providerFactory->getProvider($providerName)->revokeConnection($credentials);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to revoke bank connection {connection} at {provider}: {class}: {message}', [
				'app' => 'budget',
				'connection' => $connectionId,
				'provider' => $providerName,
				'class' => get_class($e),
				'message' => $e->getMessage(),
			]);
		}
	}
}
