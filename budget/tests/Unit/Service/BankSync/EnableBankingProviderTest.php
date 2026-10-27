<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\BankSync;

use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingAuthException;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingClient;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingKeys;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingKeyStore;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingNormalizer;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingRateLimitException;
use OCA\Budget\Service\BankSync\EnableBankingProvider;
use OCA\Budget\Service\BankSync\InstitutionListingProviderInterface;
use OCA\Budget\Service\BankSync\ReauthorizableProviderInterface;
use OCA\Budget\Tests\Unit\Service\BankSync\EnableBanking\EnableBankingClientTest;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EnableBankingProviderTest extends TestCase {
	private const KEYS = ['applicationId' => 'app-id', 'privateKey' => 'PEM'];
	private const DAY = 86400;

	private EnableBankingProvider $provider;
	private EnableBankingClient&MockObject $client;
	private EnableBankingKeyStore&MockObject $keyStore;
	private LoggerInterface&MockObject $logger;
	private int $now;

	/** @var array<string, array|\Throwable|callable> responses keyed by "METHOD /path" */
	private array $responses = [];

	/** @var list<array{method: string, path: string, json: ?array, query: array}> */
	private array $calls = [];

	protected function setUp(): void {
		$this->now = gmmktime(12, 0, 0, 10, 27, 2026);

		$this->client = $this->createMock(EnableBankingClient::class);
		$this->client->method('request')->willReturnCallback(
			function (string $method, string $path, EnableBankingKeys $keys, ?array $json = null, array $query = []): array {
				$this->assertSame(self::KEYS['applicationId'], $keys->applicationId());
				$this->assertSame(self::KEYS['privateKey'], $keys->privateKey());
				$this->calls[] = ['method' => $method, 'path' => $path, 'json' => $json, 'query' => $query];

				$response = $this->responses[$method . ' ' . $path] ?? null;
				if ($response instanceof \Throwable) {
					throw $response;
				}
				if (is_callable($response)) {
					return $response($json, $query);
				}
				if ($response === null) {
					$this->fail("Unexpected request: {$method} {$path}");
				}
				return $response;
			}
		);

		$this->keyStore = $this->createMock(EnableBankingKeyStore::class);
		$this->keyStore->method('get')->willReturnCallback(
			fn (string $userId) => $userId === 'alice'
				? new EnableBankingKeys(self::KEYS['applicationId'], self::KEYS['privateKey'])
				: null
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);

		$this->provider = new EnableBankingProvider(
			$this->client,
			$this->keyStore,
			new EnableBankingNormalizer(),
			$time,
			$this->logger = $this->createMock(LoggerInterface::class),
		);
	}

	private function credentials(array $overrides = []): string {
		return json_encode(array_merge([
			'userId' => 'alice',
			'aspspName' => 'Sparkasse',
			'aspspCountry' => 'DE',
			'state' => null,
			'stateCreatedAt' => null,
			'sessionId' => 'sess-1',
			'validUntil' => gmdate('Y-m-d\TH:i:sP', $this->now + 30 * self::DAY),
			'accounts' => ['hash-a' => 'uid-a'],
			'lastSyncDate' => null,
		], $overrides));
	}

	private function callsTo(string $method, string $path): array {
		return array_values(array_filter(
			$this->calls,
			fn (array $c) => $c['method'] === $method && $c['path'] === $path
		));
	}

	// ── Identity ────────────────────────────────────────────────────

	public function testIdentityAndInterfaces(): void {
		$this->assertSame('enablebanking', $this->provider->getIdentifier());
		$this->assertSame('Enable Banking (EU, PSD2)', $this->provider->getDisplayName());
		$this->assertInstanceOf(InstitutionListingProviderInterface::class, $this->provider);
		$this->assertInstanceOf(ReauthorizableProviderInterface::class, $this->provider);
	}

	// ── initializeConnection ────────────────────────────────────────

	public function testInitializeConnectionPostsAuthWithStateAndValidUntil(): void {
		$this->responses['POST /auth'] = ['url' => 'https://bank.example/auth', 'authorization_id' => 'a-1'];

		$result = $this->provider->initializeConnection([
			'userId' => 'alice',
			'institutionId' => 'Sparkasse',
			'country' => 'DE',
			'redirectUrl' => 'https://cloud.example/callback',
		]);

		$body = $this->callsTo('POST', '/auth')[0]['json'];
		$this->assertSame(['name' => 'Sparkasse', 'country' => 'DE'], $body['aspsp']);
		$this->assertSame('personal', $body['psu_type']);
		$this->assertSame('https://cloud.example/callback', $body['redirect_url']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $body['state']);
		$validUntil = (new \DateTimeImmutable($body['access']['valid_until']))->getTimestamp();
		$this->assertLessThanOrEqual($this->now + 180 * self::DAY, $validUntil);
		$this->assertGreaterThan($this->now + 179 * self::DAY, $validUntil);

		$this->assertSame('https://bank.example/auth', $result['authorizationUrl']);
		$this->assertSame([], $result['accounts']);
		$creds = json_decode($result['credentials'], true);
		$this->assertSame($body['state'], $creds['state']);
		$this->assertSame('alice', $creds['userId']);
		$this->assertSame('Sparkasse', $creds['aspspName']);
		$this->assertSame('DE', $creds['aspspCountry']);
		$this->assertSame($this->now, $creds['stateCreatedAt']);
		$this->assertNull($creds['sessionId']);

		// A bank with a shorter maximum consent caps valid_until.
		$this->calls = [];
		$this->provider->initializeConnection([
			'userId' => 'alice',
			'institutionId' => 'Sparkasse',
			'country' => 'DE',
			'redirectUrl' => 'https://cloud.example/callback',
			'maximumConsentValidity' => 7776000,
		]);
		$body = $this->callsTo('POST', '/auth')[0]['json'];
		$validUntil = (new \DateTimeImmutable($body['access']['valid_until']))->getTimestamp();
		$this->assertLessThanOrEqual($this->now + 90 * self::DAY, $validUntil);
	}

	public function testInitializeConnectionThrowsWithoutStoredKeys(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Enable Banking keys are not set up');

		$this->provider->initializeConnection([
			'userId' => 'bob',
			'institutionId' => 'Sparkasse',
			'country' => 'DE',
			'redirectUrl' => 'https://cloud.example/callback',
		]);
	}

	// ── completeAuthorization ───────────────────────────────────────

	public function testCompleteAuthorizationStoresSessionAndHashMap(): void {
		$this->responses['POST /sessions'] = [
			'session_id' => 'sess-new',
			'access' => ['valid_until' => '2027-04-25T12:00:00+00:00'],
			'accounts' => [
				['uid' => 'uid-1', 'identification_hash' => 'hash-1', 'name' => 'Giro', 'currency' => 'EUR'],
				['uid' => 'uid-2', 'identification_hash' => 'hash-2', 'account_id' => ['iban' => 'DE89370400440532013000'], 'currency' => 'EUR'],
			],
		];

		$result = $this->provider->completeAuthorization(
			$this->credentials(['state' => str_repeat('a', 64), 'stateCreatedAt' => $this->now, 'sessionId' => null, 'accounts' => []]),
			'the-code'
		);

		$this->assertSame(['code' => 'the-code'], $this->callsTo('POST', '/sessions')[0]['json']);
		$creds = json_decode($result['credentials'], true);
		$this->assertSame('sess-new', $creds['sessionId']);
		$this->assertSame('2027-04-25T12:00:00+00:00', $creds['validUntil']);
		$this->assertSame(['hash-1' => 'uid-1', 'hash-2' => 'uid-2'], $creds['accounts']);
		$this->assertNull($creds['state']);
		$this->assertNull($creds['stateCreatedAt']);
		$this->assertSame('alice', $creds['userId']);

		$this->assertSame(['hash-1', 'hash-2'], array_column($result['accounts'], 'id'));
		$this->assertSame(['Giro', 'DE…3000'], array_column($result['accounts'], 'name'));
		$this->assertSame([], $result['accounts'][0]['transactions']);
	}

	// ── fetchAccounts ───────────────────────────────────────────────

	private function stubAccountEndpoints(): void {
		$this->responses['GET /accounts/uid-a/details'] = ['name' => 'Giro', 'currency' => 'EUR'];
		$this->responses['GET /accounts/uid-a/balances'] = ['balances' => [
			['balance_type' => 'CLBD', 'balance_amount' => ['amount' => '1000.00', 'currency' => 'EUR']],
			['balance_type' => 'XPCD', 'balance_amount' => ['amount' => '950.00', 'currency' => 'EUR']],
		]];
		$this->responses['GET /accounts/uid-a/transactions'] = fn (?array $json, array $query) => isset($query['continuation_key'])
			? ['transactions' => [[
				'transaction_id' => 't-2', 'transaction_amount' => ['amount' => '50.00', 'currency' => 'EUR'],
				'credit_debit_indicator' => 'DBIT', 'status' => 'PDNG', 'booking_date' => '2026-10-26',
			]], 'continuation_key' => null]
			: ['transactions' => [[
				'transaction_id' => 't-1', 'transaction_amount' => ['amount' => '12.30', 'currency' => 'EUR'],
				'credit_debit_indicator' => 'CRDT', 'status' => 'BOOK', 'booking_date' => '2026-10-25',
			]], 'continuation_key' => 'next-page'];
	}

	public function testFetchAccountsUsesHashAsIdAndPaginates(): void {
		$this->stubAccountEndpoints();

		$result = $this->provider->fetchAccounts($this->credentials(), ['includePending' => true]);

		$txCalls = $this->callsTo('GET', '/accounts/uid-a/transactions');
		$this->assertCount(2, $txCalls);
		$this->assertSame('2026-07-29', $txCalls[0]['query']['date_from']);
		$this->assertArrayNotHasKey('continuation_key', $txCalls[0]['query']);
		$this->assertSame('next-page', $txCalls[1]['query']['continuation_key']);

		$account = $result['accounts'][0];
		$this->assertSame('hash-a', $account['id']);
		$this->assertSame('Giro', $account['name']);
		$this->assertSame('EUR', $account['currency']);
		$this->assertSame('950.00', $account['balance']);
		$this->assertSame(['t-1', 't-2'], array_column($account['transactions'], 'id'));
		$this->assertSame(['12.30', '-50.00'], array_column($account['transactions'], 'amount'));

		$updated = json_decode($result['updatedCredentials'], true);
		$this->assertSame('2026-10-27', $updated['lastSyncDate']);
		$this->assertSame('sess-1', $updated['sessionId']);

		// Later syncs overlap the last successful one by 7 days.
		$this->calls = [];
		$this->provider->fetchAccounts($this->credentials(['lastSyncDate' => '2026-10-20']));
		$this->assertSame('2026-10-13', $this->callsTo('GET', '/accounts/uid-a/transactions')[0]['query']['date_from']);
	}

	public function testFetchAccountsDropsPendingUnlessRequested(): void {
		$this->stubAccountEndpoints();

		$account = $this->provider->fetchAccounts($this->credentials(), ['includePending' => false])['accounts'][0];

		$this->assertSame(['t-1'], array_column($account['transactions'], 'id'));
		$this->assertSame('1000.00', $account['balance']);
	}

	/**
	 * Two genuine, identical bookings on one day without a bank ID hash to
	 * the same fallback ID; the sync then skipped the second as a duplicate.
	 */
	public function testIdenticalRowsWithoutIdsGetDistinctStableIds(): void {
		$this->stubAccountEndpoints();
		$row = [
			'transaction_amount' => ['amount' => '4.50', 'currency' => 'EUR'],
			'credit_debit_indicator' => 'DBIT', 'status' => 'BOOK', 'booking_date' => '2026-10-25',
			'remittance_information' => ['Coffee'],
		];
		$this->responses['GET /accounts/uid-a/transactions'] = ['transactions' => [
			$row,
			['transaction_id' => 't-9'] + $row,
			$row,
			$row,
		]];

		$first = array_column($this->provider->fetchAccounts($this->credentials())['accounts'][0]['transactions'], 'id');
		$second = array_column($this->provider->fetchAccounts($this->credentials())['accounts'][0]['transactions'], 'id');

		$this->assertMatchesRegularExpression('/^h:[0-9a-f]{32}$/', $first[0]);
		$this->assertSame([$first[0], 't-9', $first[0] . ':2', $first[0] . ':3'], $first);
		$this->assertSame($first, $second);
	}

	/**
	 * When the session lists accounts and none can be read, the sync failed:
	 * reporting success left the connection active without an error.
	 */
	public function testFetchAccountsFailsWhenNoAccountCouldBeRead(): void {
		$this->responses['GET /accounts/uid-a/details'] = new \RuntimeException('HTTP 500');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('The bank could not be reached. Please try again later.');

		$this->provider->fetchAccounts($this->credentials());
	}

	public function testFetchAccountsSucceedsWhenSomeAccountsCouldBeRead(): void {
		$this->stubAccountEndpoints();
		$this->responses['GET /accounts/uid-b/details'] = new \RuntimeException('HTTP 500');

		$result = $this->provider->fetchAccounts($this->credentials(['accounts' => ['hash-a' => 'uid-a', 'hash-b' => 'uid-b']]));

		$this->assertSame(['hash-a'], array_column($result['accounts'], 'id'));
		$this->assertArrayNotHasKey('updatedCredentials', $result);
	}

	public function testFetchAccountsWithoutAccountsInTheSessionIsNoError(): void {
		$result = $this->provider->fetchAccounts($this->credentials(['accounts' => []]));

		$this->assertSame([], $result['accounts']);
	}

	/**
	 * A bank that keeps sending continuation keys is cut off at the page
	 * limit. The rows after it were never read, so the sync window must not
	 * move past them.
	 */
	public function testHittingThePageLimitKeepsTheSyncWindow(): void {
		$this->stubAccountEndpoints();
		$page = 0;
		$this->responses['GET /accounts/uid-a/transactions'] = function () use (&$page) {
			$page++;
			return ['transactions' => [[
				'transaction_id' => 't-' . $page, 'transaction_amount' => ['amount' => '1.00', 'currency' => 'EUR'],
				'credit_debit_indicator' => 'DBIT', 'status' => 'BOOK', 'booking_date' => '2026-10-25',
			]], 'continuation_key' => 'page-' . $page];
		};
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});

		$result = $this->provider->fetchAccounts($this->credentials());

		$this->assertCount(100, $result['accounts'][0]['transactions']);
		$this->assertArrayNotHasKey('updatedCredentials', $result);
		$this->assertSame(['Enable Banking: transaction list cut off at the page limit; the sync window is kept'], $warnings);
	}

	public function testFetchAccountsTurnsRateLimitIntoReadableError(): void {
		$this->responses['GET /accounts/uid-a/details'] = new EnableBankingRateLimitException('HTTP 429');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('The bank only allows a few refreshes per day. Please try again later.');

		$this->provider->fetchAccounts($this->credentials());
	}

	public function testFetchAccountsTurnsAuthErrorIntoReauthMessage(): void {
		$this->responses['GET /accounts/uid-a/details'] = new EnableBankingAuthException('HTTP 401');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Bank authorization has expired. Please reconnect.');

		$this->provider->fetchAccounts($this->credentials());
	}

	public function testFetchAccountsThrowsWhenKeysMissing(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Enable Banking keys are not set up');

		$this->provider->fetchAccounts($this->credentials(['userId' => 'bob']));
	}

	public function testFetchAccountListSkipsTransactions(): void {
		$this->stubAccountEndpoints();

		$result = $this->provider->fetchAccountList($this->credentials());

		$this->assertSame('hash-a', $result['accounts'][0]['id']);
		$this->assertSame([], $result['accounts'][0]['transactions']);
		$this->assertSame([], $this->callsTo('GET', '/accounts/uid-a/transactions'));
		$this->assertArrayNotHasKey('updatedCredentials', $result);
	}

	// ── requiresReauthorization ─────────────────────────────────────

	public function testRequiresReauthorization(): void {
		$this->responses['GET /sessions/sess-1'] = ['status' => 'AUTHORIZED'];
		$this->assertFalse($this->provider->requiresReauthorization($this->credentials()));

		$this->assertTrue($this->provider->requiresReauthorization(
			$this->credentials(['validUntil' => gmdate('Y-m-d\TH:i:sP', $this->now - 60)])
		));
		$this->assertTrue($this->provider->requiresReauthorization($this->credentials(['sessionId' => null])));
		$this->assertTrue($this->provider->requiresReauthorization($this->credentials(['userId' => 'bob'])));

		$this->responses['GET /sessions/sess-1'] = ['status' => 'EXPIRED'];
		$this->assertTrue($this->provider->requiresReauthorization($this->credentials()));

		$this->responses['GET /sessions/sess-1'] = new EnableBankingAuthException('HTTP 401');
		$this->assertTrue($this->provider->requiresReauthorization($this->credentials()));

		$this->responses['GET /sessions/sess-1'] = new \RuntimeException('HTTP 500');
		$this->assertFalse($this->provider->requiresReauthorization($this->credentials()));
	}

	// ── revokeConnection ────────────────────────────────────────────

	public function testRevokeConnectionDeletesSessionAndSwallowsErrors(): void {
		$this->responses['DELETE /sessions/sess-1'] = [];
		$this->provider->revokeConnection($this->credentials());
		$this->assertCount(1, $this->callsTo('DELETE', '/sessions/sess-1'));

		$this->responses['DELETE /sessions/sess-1'] = new \RuntimeException('HTTP 500');
		$this->provider->revokeConnection($this->credentials());

		// Missing keys or session: nothing to revoke, no request.
		$this->calls = [];
		$this->provider->revokeConnection($this->credentials(['userId' => 'bob']));
		$this->provider->revokeConnection($this->credentials(['sessionId' => null]));
		$this->provider->revokeConnection('not json');
		$this->assertSame([], $this->calls);
	}

	// ── listInstitutions ────────────────────────────────────────────

	public function testListInstitutionsMapsAspsps(): void {
		$this->responses['GET /aspsps'] = ['aspsps' => [
			['name' => 'Sparkasse', 'country' => 'DE', 'logo' => 'https://cdn.example/s.png', 'maximum_consent_validity' => 7776000],
			['name' => 'N26', 'country' => 'DE'],
		]];

		$result = $this->provider->listInstitutions('alice', ['country' => 'DE']);

		$this->assertSame(['country' => 'DE', 'psu_type' => 'personal'], $this->callsTo('GET', '/aspsps')[0]['query']);
		$this->assertSame([
			['id' => 'Sparkasse', 'name' => 'Sparkasse', 'logo' => 'https://cdn.example/s.png', 'country' => 'DE', 'maximumConsentValidity' => 7776000],
			['id' => 'N26', 'name' => 'N26', 'logo' => null, 'country' => 'DE', 'maximumConsentValidity' => null],
		], $result);
	}

	// ── reinitializeConnection ──────────────────────────────────────

	public function testReinitializeConnectionKeepsSessionUntilCallback(): void {
		$this->responses['POST /auth'] = ['url' => 'https://bank.example/reauth'];
		$oldState = str_repeat('b', 64);

		$result = $this->provider->reinitializeConnection(
			$this->credentials(['state' => $oldState, 'lastSyncDate' => '2026-10-20']),
			['redirectUrl' => 'https://cloud.example/callback']
		);

		$body = $this->callsTo('POST', '/auth')[0]['json'];
		$this->assertSame(['name' => 'Sparkasse', 'country' => 'DE'], $body['aspsp']);
		$this->assertSame('https://bank.example/reauth', $result['authorizationUrl']);
		$this->assertSame([], $result['accounts']);

		$creds = json_decode($result['credentials'], true);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $creds['state']);
		$this->assertNotSame($oldState, $creds['state']);
		$this->assertSame($body['state'], $creds['state']);
		$this->assertSame($this->now, $creds['stateCreatedAt']);
		$this->assertSame('sess-1', $creds['sessionId']);
		$this->assertSame(['hash-a' => 'uid-a'], $creds['accounts']);
		$this->assertSame('2026-10-20', $creds['lastSyncDate']);
	}

	// ── Logging hygiene ─────────────────────────────────────────────

	/**
	 * Sync failures are logged with their trace; with exception arguments on,
	 * the frames held the credentials JSON (session ID) and the keys.
	 */
	public function testFailureTracesCarryNeitherSessionIdNorKey(): void {
		$previous = ini_set('zend.exception_ignore_args', '0');
		try {
			$this->responses['GET /accounts/uid-a/details'] = new EnableBankingAuthException('HTTP 401');
			try {
				$this->provider->fetchAccounts($this->credentials());
				$this->fail('Expected an exception');
			} catch (\Exception $e) {
				$dump = EnableBankingClientTest::appFrames($e) . $e->getTraceAsString();
				$this->assertStringNotContainsString('sess-1', $dump);
				$this->assertStringNotContainsString(self::KEYS['privateKey'], $dump);
			}
		} finally {
			ini_set('zend.exception_ignore_args', (string)$previous);
		}
	}

	public function testFailureLogsNeverContainExceptionMessages(): void {
		$logged = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				$logged[] = $message . ' ' . json_encode($context);
			}
		);
		// A transport error message as Guzzle would build it, with the URI inside.
		$leaky = new \RuntimeException('cURL error 28 for https://api.enablebanking.com/sessions/sess-1');

		$this->responses['GET /sessions/sess-1'] = $leaky;
		$this->assertFalse($this->provider->requiresReauthorization($this->credentials()));

		$this->responses['DELETE /sessions/sess-1'] = $leaky;
		$this->provider->revokeConnection($this->credentials());

		$this->responses['GET /accounts/uid-a/details'] = new \RuntimeException('cURL error 28 for https://api.enablebanking.com/accounts/uid-a/details');
		try {
			$this->provider->fetchAccounts($this->credentials());
		} catch (\Exception $e) {
			// Every account failed (see testFetchAccountsFailsWhenNoAccountCouldBeRead)
		}

		$this->assertCount(3, $logged);
		foreach ($logged as $line) {
			$this->assertStringNotContainsString('sess-1', $line);
			$this->assertStringNotContainsString('uid-a', $line);
			$this->assertStringNotContainsString('cURL', $line);
		}
	}
}
