<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\BankSync;

use OCA\Budget\Service\BankSync\GoCardlessProvider;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GoCardlessProviderTest extends TestCase {
	private GoCardlessProvider $provider;
	private IClientService $clientService;
	private IClient $client;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->clientService->method('newClient')->willReturn($this->client);

		$this->provider = new GoCardlessProvider($this->clientService, $this->logger);
	}

	// ── Identity ────────────────────────────────────────────────────

	public function testGetIdentifierReturnsGocardless(): void {
		$this->assertSame('gocardless', $this->provider->getIdentifier());
	}

	public function testGetDisplayNameReturnsGoCardlessUkEurope(): void {
		$this->assertSame('GoCardless (UK/Europe)', $this->provider->getDisplayName());
	}

	// ── initializeConnection ────────────────────────────────────────

	public function testInitializeConnectionThrowsWhenSecretIdMissing(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Secret ID and Secret Key are required');

		$this->provider->initializeConnection(['secretKey' => 'key123']);
	}

	public function testInitializeConnectionThrowsWhenSecretKeyMissing(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Secret ID and Secret Key are required');

		$this->provider->initializeConnection(['secretId' => 'id123']);
	}

	public function testInitializeConnectionThrowsWhenBothMissing(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->provider->initializeConnection([]);
	}

	public function testInitializeConnectionWithInstitutionCreatesRequisition(): void {
		// POST /token/new/ returns access token
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')->willReturn(json_encode([
			'access' => 'access-token-123',
			'refresh' => 'refresh-token-456',
			'access_expires' => 86400,
		]));

		// POST /requisitions/ returns requisition with auth link
		$requisitionResponse = $this->createMock(IResponse::class);
		$requisitionResponse->method('getBody')->willReturn(json_encode([
			'id' => 'req-abc',
			'link' => 'https://ob.gocardless.com/psd2/start/req-abc',
		]));

		$this->client->expects($this->exactly(2))
			->method('post')
			->willReturnOnConsecutiveCalls($tokenResponse, $requisitionResponse);

		$result = $this->provider->initializeConnection([
			'secretId' => 'my-id',
			'secretKey' => 'my-key',
			'institutionId' => 'BANK_GB_001',
			'redirectUrl' => 'https://myapp.com/callback',
		]);

		$this->assertArrayHasKey('credentials', $result);
		$this->assertArrayHasKey('authorizationUrl', $result);
		$this->assertSame('https://ob.gocardless.com/psd2/start/req-abc', $result['authorizationUrl']);
		$this->assertSame('req-abc', $result['requisitionId']);
		$this->assertSame([], $result['accounts']);

		// Verify credentials contain requisitionId
		$creds = json_decode($result['credentials'], true);
		$this->assertSame('my-id', $creds['secretId']);
		$this->assertSame('my-key', $creds['secretKey']);
		$this->assertSame('access-token-123', $creds['accessToken']);
		$this->assertSame('refresh-token-456', $creds['refreshToken']);
		$this->assertSame('req-abc', $creds['requisitionId']);
	}

	public function testInitializeConnectionWithoutInstitutionReturnsEmptyAccounts(): void {
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')->willReturn(json_encode([
			'access' => 'access-token-123',
			'access_expires' => 86400,
		]));

		$this->client->expects($this->once())
			->method('post')
			->willReturn($tokenResponse);

		$result = $this->provider->initializeConnection([
			'secretId' => 'my-id',
			'secretKey' => 'my-key',
		]);

		$this->assertSame([], $result['accounts']);
		$this->assertArrayNotHasKey('authorizationUrl', $result);
	}

	// ── fetchAccounts ───────────────────────────────────────────────

	public function testFetchAccountsThrowsOnInvalidCredentials(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Invalid credentials format');

		$this->provider->fetchAccounts('not-json');
	}

	public function testFetchAccountsReturnsEmptyWhenNoRequisitionId(): void {
		$creds = json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
			'accessToken' => 'token',
			'tokenExpires' => time() + 3600,
		]);

		$result = $this->provider->fetchAccounts($creds);
		$this->assertSame([], $result['accounts']);
	}

	public function testFetchAccountsReturnsNormalizedAccountData(): void {
		$creds = json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
			'accessToken' => 'valid-token',
			'tokenExpires' => time() + 3600,
			'requisitionId' => 'req-123',
		]);

		// GET /requisitions/req-123/ returns account IDs
		$requisitionResponse = $this->createMock(IResponse::class);
		$requisitionResponse->method('getBody')->willReturn(json_encode([
			'accounts' => ['acct-aaa'],
			'status' => 'LN',
		]));

		// GET /accounts/acct-aaa/details/
		$detailsResponse = $this->createMock(IResponse::class);
		$detailsResponse->method('getBody')->willReturn(json_encode([
			'account' => [
				'name' => 'Current Account',
				'currency' => 'GBP',
			],
		]));

		// GET /accounts/acct-aaa/balances/
		$balancesResponse = $this->createMock(IResponse::class);
		$balancesResponse->method('getBody')->willReturn(json_encode([
			'balances' => [
				[
					'balanceAmount' => ['amount' => '1500.00', 'currency' => 'GBP'],
					'balanceType' => 'interimAvailable',
				],
				[
					'balanceAmount' => ['amount' => '1450.00', 'currency' => 'GBP'],
					'balanceType' => 'expected',
				],
			],
		]));

		// GET /accounts/acct-aaa/transactions/
		$txResponse = $this->createMock(IResponse::class);
		$txResponse->method('getBody')->willReturn(json_encode([
			'transactions' => [
				'booked' => [
					[
						'transactionId' => 'tx-001',
						'bookingDate' => '2024-01-15',
						'transactionAmount' => ['amount' => '-42.50', 'currency' => 'GBP'],
						'remittanceInformationUnstructured' => 'Tesco Groceries',
						'creditorName' => 'Tesco PLC',
					],
					[
						'internalTransactionId' => 'tx-int-002',
						'bookingDate' => '2024-01-14',
						'valueDate' => '2024-01-14',
						'transactionAmount' => ['amount' => '2000.00', 'currency' => 'GBP'],
						'remittanceInformationUnstructuredArray' => ['Salary January'],
						'debtorName' => 'Employer Ltd',
					],
				],
			],
		]));

		$this->client->expects($this->exactly(4))
			->method('get')
			->willReturnOnConsecutiveCalls(
				$requisitionResponse,
				$detailsResponse,
				$balancesResponse,
				$txResponse
			);

		$result = $this->provider->fetchAccounts($creds);

		$this->assertCount(1, $result['accounts']);
		$account = $result['accounts'][0];
		$this->assertSame('acct-aaa', $account['id']);
		$this->assertSame('Current Account', $account['name']);
		$this->assertSame('GBP', $account['currency']);
		// Should prefer 'expected' balance
		$this->assertSame('1450.00', $account['balance']);

		$this->assertCount(2, $account['transactions']);

		$tx1 = $account['transactions'][0];
		$this->assertSame('tx-001', $tx1['id']);
		$this->assertSame('2024-01-15', $tx1['date']);
		$this->assertSame('-42.50', $tx1['amount']);
		$this->assertSame('Tesco Groceries', $tx1['description']);
		$this->assertSame('Tesco PLC', $tx1['vendor']);

		$tx2 = $account['transactions'][1];
		$this->assertSame('tx-int-002', $tx2['id']);
		$this->assertSame('Salary January', $tx2['description']);
		$this->assertSame('Employer Ltd', $tx2['vendor']);
	}

	public function testFetchAccountsRefreshesExpiredToken(): void {
		$creds = json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
			'accessToken' => 'expired-token',
			'tokenExpires' => time() - 100, // Expired
			'requisitionId' => 'req-123',
		]);

		// POST /token/new/ for refresh
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')->willReturn(json_encode([
			'access' => 'new-access-token',
			'refresh' => 'new-refresh',
			'access_expires' => 86400,
		]));

		$this->client->expects($this->once())
			->method('post')
			->willReturn($tokenResponse);

		// GET /requisitions/req-123/ -- no linked accounts
		$requisitionResponse = $this->createMock(IResponse::class);
		$requisitionResponse->method('getBody')->willReturn(json_encode([
			'accounts' => [],
			'status' => 'LN',
		]));

		$this->client->expects($this->once())
			->method('get')
			->willReturn($requisitionResponse);

		$result = $this->provider->fetchAccounts($creds);

		$this->assertSame([], $result['accounts']);
		// Should include updated credentials
		$this->assertArrayHasKey('updatedCredentials', $result);
		$updated = json_decode($result['updatedCredentials'], true);
		$this->assertSame('new-access-token', $updated['accessToken']);
		$this->assertSame('new-refresh', $updated['refreshToken']);
	}

	public function testFetchAccountsSkipsFailingAccountsGracefully(): void {
		$creds = json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
			'accessToken' => 'token',
			'tokenExpires' => time() + 3600,
			'requisitionId' => 'req-123',
		]);

		// Requisition returns two accounts
		$requisitionResponse = $this->createMock(IResponse::class);
		$requisitionResponse->method('getBody')->willReturn(json_encode([
			'accounts' => ['acct-good', 'acct-bad'],
		]));

		// Details for good account
		$goodDetails = $this->createMock(IResponse::class);
		$goodDetails->method('getBody')->willReturn(json_encode([
			'account' => ['name' => 'Good Account', 'currency' => 'EUR'],
		]));

		$goodBalances = $this->createMock(IResponse::class);
		$goodBalances->method('getBody')->willReturn(json_encode([
			'balances' => [['balanceAmount' => ['amount' => '100', 'currency' => 'EUR'], 'balanceType' => 'expected']],
		]));

		$goodTx = $this->createMock(IResponse::class);
		$goodTx->method('getBody')->willReturn(json_encode([
			'transactions' => ['booked' => []],
		]));

		$callCount = 0;
		$this->client->method('get')
			->willReturnCallback(function ($url) use (&$callCount, $requisitionResponse, $goodDetails, $goodBalances, $goodTx) {
				$callCount++;
				if ($callCount === 1) {
					return $requisitionResponse;
				}
				// Good account calls
				if ($callCount === 2) {
					return $goodDetails;
				}
				if ($callCount === 3) {
					return $goodBalances;
				}
				if ($callCount === 4) {
					return $goodTx;
				}
				// Bad account throws on details
				throw new \Exception('Account suspended');
			});

		$result = $this->provider->fetchAccounts($creds);

		// Only the good account should be returned
		$this->assertCount(1, $result['accounts']);
		$this->assertSame('Good Account', $result['accounts'][0]['name']);
	}

	// ── requiresReauthorization ─────────────────────────────────────

	/**
	 * @dataProvider expiredStatusProvider
	 */
	public function testRequiresReauthorizationReturnsTrueForExpiredStatuses(string $status): void {
		$creds = json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
			'accessToken' => 'token',
			'requisitionId' => 'req-123',
		]);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			'status' => $status,
		]));

		$this->client->method('get')->willReturn($response);

		$this->assertTrue($this->provider->requiresReauthorization($creds));
	}

	public static function expiredStatusProvider(): array {
		return [
			'expired' => ['EX'],
			'rejected' => ['RJ'],
			'suspended' => ['SA'],
		];
	}

	public function testRequiresReauthorizationReturnsFalseForLinkedStatus(): void {
		$creds = json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
			'accessToken' => 'token',
			'requisitionId' => 'req-123',
		]);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			'status' => 'LN',
		]));

		$this->client->method('get')->willReturn($response);

		$this->assertFalse($this->provider->requiresReauthorization($creds));
	}

	public function testRequiresReauthorizationReturnsFalseForCreatedStatus(): void {
		$creds = json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
			'accessToken' => 'token',
			'requisitionId' => 'req-123',
		]);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			'status' => 'CR',
		]));

		$this->client->method('get')->willReturn($response);

		$this->assertFalse($this->provider->requiresReauthorization($creds));
	}

	public function testRequiresReauthorizationReturnsFalseOnApiError(): void {
		$creds = json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
			'accessToken' => 'token',
			'requisitionId' => 'req-123',
		]);

		$this->client->method('get')
			->willThrowException(new \Exception('Network timeout'));

		$this->assertFalse($this->provider->requiresReauthorization($creds));
	}

	public function testRequiresReauthorizationReturnsTrueWhenNoRequisitionId(): void {
		$creds = json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
			'accessToken' => 'token',
		]);

		$this->assertTrue($this->provider->requiresReauthorization($creds));
	}

	public function testRequiresReauthorizationReturnsTrueWhenNoAccessToken(): void {
		$creds = json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
			'requisitionId' => 'req-123',
		]);

		$this->assertTrue($this->provider->requiresReauthorization($creds));
	}

	public function testRequiresReauthorizationReturnsTrueOnInvalidJson(): void {
		$this->assertTrue($this->provider->requiresReauthorization('not-json'));
	}

	// ── revokeConnection ────────────────────────────────────────────

	public function testRevokeConnectionDeletesRequisition(): void {
		$creds = json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
			'requisitionId' => 'req-123',
		]);

		// Token fetch
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')->willReturn(json_encode([
			'access' => 'token-for-delete',
			'access_expires' => 86400,
		]));

		$this->client->expects($this->once())
			->method('post')
			->willReturn($tokenResponse);

		$this->client->expects($this->once())
			->method('delete')
			->with(
				$this->stringContains('/requisitions/req-123/'),
				$this->callback(function ($opts) {
					return $opts['headers']['Authorization'] === 'Bearer token-for-delete';
				})
			);

		$this->provider->revokeConnection($creds);
	}

	public function testRevokeConnectionDoesNothingOnMissingFields(): void {
		$this->client->expects($this->never())->method('post');
		$this->client->expects($this->never())->method('delete');

		// Missing requisitionId
		$this->provider->revokeConnection(json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
		]));
	}

	public function testRevokeConnectionDoesNothingOnInvalidJson(): void {
		$this->client->expects($this->never())->method('post');
		$this->client->expects($this->never())->method('delete');

		$this->provider->revokeConnection('invalid');
	}

	public function testRevokeConnectionSwallowsApiErrors(): void {
		$creds = json_encode([
			'secretId' => 'id',
			'secretKey' => 'key',
			'requisitionId' => 'req-123',
		]);

		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')->willReturn(json_encode([
			'access' => 'token',
			'access_expires' => 86400,
		]));

		$this->client->method('post')->willReturn($tokenResponse);
		$this->client->method('delete')->willThrowException(new \Exception('404 Not Found'));

		// Should not throw — just verify it completes
		$this->provider->revokeConnection($creds);
		$this->assertTrue(true);
	}

	// ── getToken ────────────────────────────────────────────────────

	public function testGetTokenReturnsAccessTokenString(): void {
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')->willReturn(json_encode([
			'access' => 'my-access-token',
			'access_expires' => 86400,
		]));

		$this->client->expects($this->once())
			->method('post')
			->willReturn($tokenResponse);

		$token = $this->provider->getToken('secret-id', 'secret-key');
		$this->assertSame('my-access-token', $token);
	}

	public function testGetTokenThrowsWhenApiReturnsNoAccess(): void {
		$tokenResponse = $this->createMock(IResponse::class);
		$tokenResponse->method('getBody')->willReturn(json_encode([
			'error' => 'invalid_credentials',
		]));

		$this->client->method('post')->willReturn($tokenResponse);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Failed to obtain GoCardless access token');

		$this->provider->getToken('bad-id', 'bad-key');
	}

	// ── getInstitutions ─────────────────────────────────────────────

	public function testGetInstitutionsReturnsNormalizedList(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			[
				'id' => 'BANK_GB_001',
				'name' => 'Barclays',
				'logo' => 'https://cdn.gocardless.com/barclays.png',
				'countries' => ['GB'],
				'extra_field' => 'ignored',
			],
			[
				'id' => 'BANK_GB_002',
				'name' => 'HSBC',
				'countries' => ['GB', 'IE'],
			],
		]));

		$this->client->expects($this->once())
			->method('get')
			->with(
				$this->stringContains('/institutions/?country=GB'),
				$this->anything()
			)
			->willReturn($response);

		$institutions = $this->provider->getInstitutions('access-token', 'GB');

		$this->assertCount(2, $institutions);
		$this->assertSame('BANK_GB_001', $institutions[0]['id']);
		$this->assertSame('Barclays', $institutions[0]['name']);
		$this->assertSame('https://cdn.gocardless.com/barclays.png', $institutions[0]['logo']);
		$this->assertSame(['GB'], $institutions[0]['countries']);
		$this->assertArrayNotHasKey('extra_field', $institutions[0]);

		$this->assertSame('BANK_GB_002', $institutions[1]['id']);
		$this->assertNull($institutions[1]['logo']);
	}

	public function testGetInstitutionsReturnsEmptyOnNonArrayResponse(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn('null');

		$this->client->method('get')->willReturn($response);

		$institutions = $this->provider->getInstitutions('access-token');
		$this->assertSame([], $institutions);
	}

	public function testGetInstitutionsDefaultsToGbCountry(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([]));

		$this->client->expects($this->once())
			->method('get')
			->with(
				$this->stringContains('/institutions/?country=GB'),
				$this->anything()
			)
			->willReturn($response);

		$this->provider->getInstitutions('token');
	}
}
