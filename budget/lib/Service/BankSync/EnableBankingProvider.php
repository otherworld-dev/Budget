<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingAuthException;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingClient;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingKeys;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingKeyStore;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingNormalizer;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingRateLimitException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Enable Banking (PSD2 aggregator) provider for EU banks.
 *
 * Flow:
 * 1. The user stores an Enable Banking application ID and private key once
 *    (EnableBankingKeyStore); every connection of that user reuses them.
 * 2. initializeConnection() starts a bank authorization (POST /auth) and
 *    returns the bank's URL. The random `state` binds the bank's redirect to
 *    this connection.
 * 3. The callback hands the returned code to completeAuthorization(), which
 *    opens a session (POST /sessions).
 * 4. fetchAccounts() reads details, balances and transactions per account.
 *
 * Accounts are identified by `identification_hash`, not by `uid`: UIDs are
 * per session and change on re-authorization, the hash stays the same, so
 * mappings and deduplication survive a reauth.
 *
 * Credentials JSON: userId, aspspName, aspspCountry, state, stateCreatedAt,
 * sessionId, validUntil, accounts (identification_hash => uid), lastSyncDate.
 *
 * Failures are logged with the exception class only, never its message:
 * messages from the HTTP layer can contain URLs with session IDs and UIDs.
 */
class EnableBankingProvider implements BankSyncProviderInterface, InstitutionListingProviderInterface, ReauthorizableProviderInterface {
	private const PSU_TYPE = 'personal';
	private const MAX_CONSENT_SECONDS = 180 * 86400;
	private const FIRST_SYNC_DAYS = 90;
	private const SYNC_OVERLAP_DAYS = 7;
	/** Guards against a bank that keeps returning continuation keys. */
	private const MAX_TRANSACTION_PAGES = 100;

	private const MSG_NO_KEYS = 'Enable Banking keys are not set up';
	private const MSG_RATE_LIMIT = 'The bank only allows a few refreshes per day. Please try again later.';
	private const MSG_EXPIRED = 'Bank authorization has expired. Please reconnect.';

	public function __construct(
		private EnableBankingClient $client,
		private EnableBankingKeyStore $keyStore,
		private EnableBankingNormalizer $normalizer,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function getIdentifier(): string {
		return 'enablebanking';
	}

	public function getDisplayName(): string {
		return 'Enable Banking (EU, PSD2)';
	}

	public function initializeConnection(array $params): array {
		$userId = (string)($params['userId'] ?? '');
		$aspspName = (string)($params['institutionId'] ?? '');
		$country = strtoupper((string)($params['country'] ?? ''));
		$redirectUrl = (string)($params['redirectUrl'] ?? '');
		if ($aspspName === '' || $country === '' || $redirectUrl === '') {
			throw new \InvalidArgumentException('Bank, country and redirect URL are required');
		}

		$keys = $this->requireKeys($userId);
		$creds = [
			'userId' => $userId,
			'aspspName' => $aspspName,
			'aspspCountry' => $country,
			'state' => null,
			'stateCreatedAt' => null,
			'sessionId' => null,
			'validUntil' => null,
			'accounts' => [],
			'lastSyncDate' => null,
		];

		return $this->startAuthorization($keys, $creds, $redirectUrl, $params['maximumConsentValidity'] ?? null);
	}

	/**
	 * Keeps the current session (and its accounts) so syncing continues until
	 * the bank redirect completes; the callback replaces and revokes it.
	 */
	public function reinitializeConnection(#[\SensitiveParameter] string $credentials, array $params): array {
		$creds = $this->decode($credentials);
		$redirectUrl = (string)($params['redirectUrl'] ?? '');
		if ($redirectUrl === '') {
			throw new \InvalidArgumentException('Redirect URL is required');
		}

		$keys = $this->requireKeys((string)($creds['userId'] ?? ''));
		return $this->startAuthorization($keys, $creds, $redirectUrl, $params['maximumConsentValidity'] ?? null);
	}

	/**
	 * Exchange the code from the bank redirect for a session. Validating the
	 * `state` (match, age) is the caller's job; this only clears it so it
	 * cannot be used twice.
	 *
	 * The returned accounts come from the session response alone and have no
	 * balance: banks allow only a few accesses per day, and the wizard fetches
	 * balances right afterwards anyway.
	 *
	 * @return array{credentials: string, accounts: list<array>}
	 */
	public function completeAuthorization(#[\SensitiveParameter] string $credentials, #[\SensitiveParameter] string $code): array {
		$creds = $this->decode($credentials);
		$keys = $this->requireKeys((string)($creds['userId'] ?? ''));

		$session = $this->client->request('POST', '/sessions', $keys, ['code' => $code]);
		if (empty($session['session_id'])) {
			throw new \RuntimeException('Enable Banking did not return a session');
		}

		$map = [];
		$accounts = [];
		foreach ($session['accounts'] ?? [] as $account) {
			$hash = (string)($account['identification_hash'] ?? '');
			$uid = (string)($account['uid'] ?? '');
			if ($hash === '' || $uid === '') {
				continue;
			}
			$map[$hash] = $uid;
			$accounts[] = [
				'id' => $hash,
				'name' => $this->normalizer->accountName($account),
				'currency' => $account['currency'] ?? 'EUR',
				'balance' => null,
				'transactions' => [],
			];
		}

		$creds['sessionId'] = (string)$session['session_id'];
		$creds['validUntil'] = $session['access']['valid_until'] ?? null;
		$creds['accounts'] = $map;
		$creds['state'] = null;
		$creds['stateCreatedAt'] = null;

		return ['credentials' => json_encode($creds), 'accounts' => $accounts];
	}

	public function fetchAccounts(#[\SensitiveParameter] string $credentials, array $options = []): array {
		$creds = $this->decode($credentials);
		$keys = $this->requireKeys((string)($creds['userId'] ?? ''));
		$includePending = (bool)($options['includePending'] ?? false);
		$dateFrom = $this->syncStartDate($creds['lastSyncDate'] ?? null);

		$complete = true;
		$accounts = $this->withReadableErrors(function () use ($creds, $keys, $includePending, $dateFrom, &$complete) {
			$accounts = [];
			foreach ($creds['accounts'] ?? [] as $hash => $uid) {
				$account = $this->fetchAccount($keys, (string)$hash, (string)$uid, $includePending, $dateFrom);
				if ($account === null) {
					$complete = false;
					continue;
				}
				$accounts[] = $account;
			}
			return $accounts;
		});

		$result = ['accounts' => $accounts];
		// Only move the sync window forward when every account was read, so a
		// failing account does not lose the days it missed.
		if ($complete) {
			$creds['lastSyncDate'] = $this->today()->format('Y-m-d');
			$result['updatedCredentials'] = json_encode($creds);
		}
		return $result;
	}

	public function fetchAccountList(#[\SensitiveParameter] string $credentials, array $options = []): array {
		$creds = $this->decode($credentials);
		$keys = $this->requireKeys((string)($creds['userId'] ?? ''));
		$includePending = (bool)($options['includePending'] ?? false);

		$accounts = $this->withReadableErrors(function () use ($creds, $keys, $includePending) {
			$accounts = [];
			foreach ($creds['accounts'] ?? [] as $hash => $uid) {
				$account = $this->fetchAccount($keys, (string)$hash, (string)$uid, $includePending, null);
				if ($account !== null) {
					$accounts[] = $account;
				}
			}
			return $accounts;
		});

		return ['accounts' => $accounts];
	}

	public function requiresReauthorization(#[\SensitiveParameter] string $credentials): bool {
		$creds = json_decode($credentials, true);
		if (!is_array($creds) || empty($creds['sessionId'])) {
			return true;
		}

		$validUntil = $this->parseTimestamp($creds['validUntil'] ?? null);
		if ($validUntil !== null && $validUntil <= $this->time->getTime()) {
			return true;
		}

		$keys = $this->keyStore->get((string)($creds['userId'] ?? ''));
		if ($keys === null) {
			return true;
		}

		try {
			$session = $this->client->request('GET', '/sessions/' . rawurlencode((string)$creds['sessionId']), $keys);
			return ($session['status'] ?? null) !== 'AUTHORIZED';
		} catch (EnableBankingAuthException) {
			return true;
		} catch (\RuntimeException $e) {
			// Network trouble or a rate limit says nothing about the consent;
			// do not flip the connection to expired for it.
			$this->logger->warning('Enable Banking: session check failed, assuming still valid: ' . $e::class, ['app' => 'budget']);
			return false;
		}
	}

	public function revokeConnection(#[\SensitiveParameter] string $credentials): void {
		$creds = json_decode($credentials, true);
		if (!is_array($creds) || empty($creds['sessionId'])) {
			return;
		}
		$keys = $this->keyStore->get((string)($creds['userId'] ?? ''));
		if ($keys === null) {
			return;
		}

		try {
			$this->client->request('DELETE', '/sessions/' . rawurlencode((string)$creds['sessionId']), $keys);
		} catch (\Exception $e) {
			$this->logger->warning('Enable Banking: failed to revoke session: ' . $e::class, ['app' => 'budget']);
		}
	}

	public function listInstitutions(string $userId, array $params): array {
		$keys = $this->requireKeys($userId);
		$country = strtoupper((string)($params['country'] ?? 'DE'));

		$response = $this->client->request('GET', '/aspsps', $keys, null, [
			'country' => $country,
			'psu_type' => self::PSU_TYPE,
		]);

		$institutions = [];
		foreach ($response['aspsps'] ?? [] as $aspsp) {
			if (empty($aspsp['name'])) {
				continue;
			}
			$institutions[] = [
				'id' => (string)$aspsp['name'],
				'name' => (string)$aspsp['name'],
				'logo' => $aspsp['logo'] ?? null,
				'country' => (string)($aspsp['country'] ?? $country),
				'maximumConsentValidity' => isset($aspsp['maximum_consent_validity']) ? (int)$aspsp['maximum_consent_validity'] : null,
			];
		}
		return $institutions;
	}

	// ── Internals ──────────────────────────────────────────

	/**
	 * POST /auth for the stored ASPSP and record a fresh single-use state.
	 */
	private function startAuthorization(
		EnableBankingKeys $keys,
		#[\SensitiveParameter]
		array $creds,
		string $redirectUrl,
		mixed $maxConsentSeconds,
	): array {
		$now = $this->time->getTime();
		$validity = self::MAX_CONSENT_SECONDS;
		if (is_numeric($maxConsentSeconds) && (int)$maxConsentSeconds > 0) {
			$validity = min($validity, (int)$maxConsentSeconds);
		}
		$state = bin2hex(random_bytes(32));

		$response = $this->client->request('POST', '/auth', $keys, [
			'access' => ['valid_until' => (new \DateTimeImmutable('@' . ($now + $validity)))->format(DATE_RFC3339)],
			'aspsp' => ['name' => $creds['aspspName'], 'country' => $creds['aspspCountry']],
			'state' => $state,
			'redirect_url' => $redirectUrl,
			'psu_type' => self::PSU_TYPE,
		]);
		if (empty($response['url'])) {
			throw new \RuntimeException('Enable Banking did not return an authorization URL');
		}

		$creds['state'] = $state;
		$creds['stateCreatedAt'] = $now;

		return [
			'credentials' => json_encode($creds),
			'accounts' => [],
			'authorizationUrl' => (string)$response['url'],
		];
	}

	/**
	 * Read one account. Transactions are only fetched when $dateFrom is given.
	 * Returns null when the account could not be read for a reason that should
	 * not stop the other accounts; rate limits and lost consent propagate.
	 */
	private function fetchAccount(EnableBankingKeys $keys, string $hash, string $uid, bool $includePending, ?string $dateFrom): ?array {
		$base = '/accounts/' . rawurlencode($uid);
		try {
			$details = $this->client->request('GET', $base . '/details', $keys);
			$balances = $this->client->request('GET', $base . '/balances', $keys)['balances'] ?? [];
			$transactions = $dateFrom === null ? [] : $this->fetchTransactions($keys, $base, $dateFrom, $includePending);
		} catch (EnableBankingRateLimitException|EnableBankingAuthException $e) {
			throw $e;
		} catch (\RuntimeException $e) {
			$this->logger->warning('Enable Banking: failed to fetch an account: ' . $e::class, ['app' => 'budget']);
			return null;
		}

		return [
			'id' => $hash,
			'name' => $this->normalizer->accountName($details),
			'currency' => $details['currency'] ?? $balances[0]['balance_amount']['currency'] ?? 'EUR',
			'balance' => $this->normalizer->balance($balances, $includePending),
			'transactions' => $transactions,
		];
	}

	private function fetchTransactions(EnableBankingKeys $keys, string $base, string $dateFrom, bool $includePending): array {
		$transactions = [];
		$continuationKey = null;
		for ($page = 0; $page < self::MAX_TRANSACTION_PAGES; $page++) {
			$query = ['date_from' => $dateFrom];
			if ($continuationKey !== null) {
				$query['continuation_key'] = $continuationKey;
			}
			$response = $this->client->request('GET', $base . '/transactions', $keys, null, $query);

			foreach ($response['transactions'] ?? [] as $tx) {
				$row = $this->normalizer->transaction($tx);
				if ($row['date'] === '' || ($row['pending'] && !$includePending)) {
					continue;
				}
				$transactions[] = $row;
			}

			$next = $response['continuation_key'] ?? null;
			if (!is_string($next) || $next === '' || $next === $continuationKey) {
				break;
			}
			$continuationKey = $next;
		}
		return $transactions;
	}

	/**
	 * BankSyncService stores exception messages as the connection's lastError,
	 * so the two expected failure modes get a message the user can act on.
	 */
	private function withReadableErrors(#[\SensitiveParameter] callable $fetch): array {
		try {
			return $fetch();
		} catch (EnableBankingRateLimitException) {
			throw new \Exception(self::MSG_RATE_LIMIT);
		} catch (EnableBankingAuthException) {
			throw new \Exception(self::MSG_EXPIRED);
		}
	}

	private function syncStartDate(?string $lastSyncDate): string {
		$last = is_string($lastSyncDate) && $lastSyncDate !== ''
			? \DateTimeImmutable::createFromFormat('!Y-m-d', $lastSyncDate, new \DateTimeZone('UTC'))
			: false;

		$start = $last !== false
			? $last->modify('-' . self::SYNC_OVERLAP_DAYS . ' days')
			: $this->today()->modify('-' . self::FIRST_SYNC_DAYS . ' days');
		return $start->format('Y-m-d');
	}

	private function today(): \DateTimeImmutable {
		return new \DateTimeImmutable('@' . $this->time->getTime());
	}

	private function parseTimestamp(mixed $value): ?int {
		if (!is_string($value) || $value === '') {
			return null;
		}
		try {
			return (new \DateTimeImmutable($value))->getTimestamp();
		} catch (\Exception) {
			return null;
		}
	}

	private function requireKeys(string $userId): EnableBankingKeys {
		$keys = $userId === '' ? null : $this->keyStore->get($userId);
		if ($keys === null) {
			throw new \InvalidArgumentException(self::MSG_NO_KEYS);
		}
		return $keys;
	}

	private function decode(#[\SensitiveParameter] string $credentials): array {
		$creds = json_decode($credentials, true);
		if (!is_array($creds)) {
			throw new \Exception('Invalid credentials format');
		}
		return $creds;
	}
}
