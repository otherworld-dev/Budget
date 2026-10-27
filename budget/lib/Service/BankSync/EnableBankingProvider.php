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
 * sessionId, validUntil, accounts (identification_hash => uid),
 * lastSyncDates (identification_hash => Y-m-d of the account's last complete
 * transaction read).
 *
 * Failures are logged with the exception class only, never its message:
 * messages from the HTTP layer can contain URLs with session IDs and UIDs.
 */
class EnableBankingProvider implements BankSyncProviderInterface, InstitutionListingProviderInterface, ReauthorizableProviderInterface, ReliableBalanceProviderInterface {
	private const PSU_TYPE = 'personal';
	private const MAX_CONSENT_SECONDS = 180 * 86400;
	private const FIRST_SYNC_DAYS = 90;
	private const SYNC_OVERLAP_DAYS = 7;
	/** Guards against a bank that keeps returning continuation keys. */
	private const MAX_TRANSACTION_PAGES = 100;

	private const MSG_NO_KEYS = 'Enable Banking keys are not set up';
	private const MSG_RATE_LIMIT = 'The bank only allows a few refreshes per day. Please try again later.';
	private const MSG_EXPIRED = 'Bank authorization has expired. Please reconnect.';
	private const MSG_UNREACHABLE = 'The bank could not be reached. Please try again later.';

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
			'lastSyncDates' => [],
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

	/**
	 * Options: includePending (bool); syncAccounts (array<identification_hash,
	 * bool needsFullHistory>), the accounts whose transactions are imported.
	 * Accounts not listed get details and balance only, so they spend none of
	 * the bank's daily accesses on transactions and keep their sync window.
	 * Without syncAccounts every account's transactions are fetched.
	 */
	public function fetchAccounts(#[\SensitiveParameter] string $credentials, array $options = []): array {
		$creds = $this->decode($credentials);
		$keys = $this->requireKeys((string)($creds['userId'] ?? ''));
		$includePending = (bool)($options['includePending'] ?? false);
		$syncAccounts = isset($options['syncAccounts']) && is_array($options['syncAccounts'])
			? $options['syncAccounts']
			: null;

		// The connection-wide date from before per-account windows may have
		// been set by a sync that imported nothing: read it as absent.
		$lastSyncDates = is_array($creds['lastSyncDates'] ?? null) ? $creds['lastSyncDates'] : [];
		$newSyncDates = $lastSyncDates;

		$accounts = $this->withReadableErrors(function () use ($creds, $keys, $includePending, $syncAccounts, $lastSyncDates, &$newSyncDates) {
			$accounts = [];
			foreach ($creds['accounts'] ?? [] as $hash => $uid) {
				$hash = (string)$hash;
				$dateFrom = null;
				if ($syncAccounts === null || array_key_exists($hash, $syncAccounts)) {
					$needsFullHistory = $syncAccounts !== null && (bool)$syncAccounts[$hash];
					$dateFrom = $this->syncStartDate($needsFullHistory ? null : ($lastSyncDates[$hash] ?? null));
				}

				$cutOff = false;
				$account = $this->fetchAccount($keys, $hash, (string)$uid, $includePending, $dateFrom, $cutOff);
				if ($account === null) {
					continue;
				}
				$accounts[] = $account;
				// Only a complete read moves the account's window forward, so
				// a failing or cut-off account does not lose the days it missed.
				if ($dateFrom !== null && !$cutOff) {
					$newSyncDates[$hash] = $this->today()->format('Y-m-d');
				}
			}
			return $accounts;
		});

		// Every account failed: that is a failed sync (status error, the
		// message shown), not a successful one that found nothing.
		if ($accounts === [] && !empty($creds['accounts'])) {
			throw new \Exception(self::MSG_UNREACHABLE);
		}

		$result = ['accounts' => $accounts];
		if ($newSyncDates !== $lastSyncDates || array_key_exists('lastSyncDate', $creds)) {
			unset($creds['lastSyncDate']);
			$creds['lastSyncDates'] = $newSyncDates;
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
				$cutOff = false;
				$account = $this->fetchAccount($keys, (string)$hash, (string)$uid, $includePending, null, $cutOff);
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
		$error = null;
		if (!$this->revokeSession($credentials, $error)) {
			$this->logger->warning('Enable Banking: failed to revoke session: ' . (string)$error, ['app' => 'budget']);
		}
	}

	/**
	 * Revoke the session without logging, for the bank's redirect: Nextcloud
	 * adds the request URL, with code and state, to every log entry.
	 *
	 * @param-out ?class-string<\Exception> $error the failure's class, never its message
	 * @return bool false when the bank refused or could not be reached; true
	 *              when the session was revoked, or there is no session or
	 *              no keys to revoke it with
	 */
	public function revokeSession(#[\SensitiveParameter] string $credentials, ?string &$error = null): bool {
		$error = null;
		$creds = json_decode($credentials, true);
		if (!is_array($creds) || empty($creds['sessionId'])) {
			return true;
		}
		$keys = $this->keyStore->get((string)($creds['userId'] ?? ''));
		if ($keys === null) {
			return true;
		}

		try {
			$this->client->request('DELETE', '/sessions/' . rawurlencode((string)$creds['sessionId']), $keys);
			return true;
		} catch (\Exception $e) {
			$error = $e::class;
			return false;
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
				// Never a URL: the UI shows Enable Banking banks without logos
				'logo' => null,
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
	 * $cutOff is set when the transaction list stopped at the page limit.
	 */
	private function fetchAccount(EnableBankingKeys $keys, string $hash, string $uid, bool $includePending, ?string $dateFrom, bool &$cutOff): ?array {
		$base = '/accounts/' . rawurlencode($uid);
		try {
			$details = $this->client->request('GET', $base . '/details', $keys);
			$balances = $this->client->request('GET', $base . '/balances', $keys)['balances'] ?? [];
			$transactions = $dateFrom === null ? [] : $this->fetchTransactions($keys, $base, $dateFrom, $includePending, $cutOff);
		} catch (EnableBankingRateLimitException|EnableBankingAuthException $e) {
			throw $e;
		} catch (\RuntimeException $e) {
			$this->logger->warning('Enable Banking: failed to fetch an account: ' . $e::class, ['app' => 'budget']);
			return null;
		}

		$balance = $this->normalizer->balanceWithDate($balances, $includePending);
		return [
			'id' => $hash,
			'name' => $this->normalizer->accountName($details),
			'currency' => $details['currency'] ?? $balances[0]['balance_amount']['currency'] ?? 'EUR',
			'balance' => $balance['amount'],
			'balanceDate' => $balance['date'],
			'transactions' => $transactions,
		];
	}

	private function fetchTransactions(EnableBankingKeys $keys, string $base, string $dateFrom, bool $includePending, bool &$cutOff): array {
		$transactions = [];
		// Two genuine identical bookings without a bank ID share a fallback
		// hash; numbering repeats in feed order keeps both, and the same feed
		// numbers them the same way again on the next sync.
		$fallbackSeen = [];
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
				if (str_starts_with($row['id'], 'h:')) {
					$seen = $fallbackSeen[$row['id']] = ($fallbackSeen[$row['id']] ?? 0) + 1;
					if ($seen > 1) {
						$row['id'] .= ':' . $seen;
					}
				}
				$transactions[] = $row;
			}

			$next = $response['continuation_key'] ?? null;
			if (!is_string($next) || $next === '' || $next === $continuationKey) {
				break;
			}
			$continuationKey = $next;
		}
		if ($page === self::MAX_TRANSACTION_PAGES) {
			// The rows after the last page were never read: the caller keeps
			// the sync window, so the next sync asks for them again.
			$cutOff = true;
			$this->logger->warning('Enable Banking: transaction list cut off at the page limit; the sync window is kept', ['app' => 'budget']);
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

	private function syncStartDate(mixed $lastSyncDate): string {
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
