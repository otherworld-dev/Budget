<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\BankSync;

use OCA\Budget\Service\BankSync\SimpleFINProvider;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SimpleFINProviderTest extends TestCase {
	private SimpleFINProvider $provider;
	private IClientService $clientService;
	private IClient $client;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->clientService->method('newClient')->willReturn($this->client);

		$this->provider = new SimpleFINProvider($this->clientService, $this->logger);
	}

	// ── Identity ────────────────────────────────────────────────────

	public function testGetIdentifierReturnsSimplefin(): void {
		$this->assertSame('simplefin', $this->provider->getIdentifier());
	}

	public function testGetDisplayNameReturnsSimplefinBridge(): void {
		$this->assertSame('SimpleFIN Bridge', $this->provider->getDisplayName());
	}

	// ── initializeConnection ────────────────────────────────────────

	public function testInitializeConnectionThrowsWhenSetupTokenMissing(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Setup token is required');

		$this->provider->initializeConnection([]);
	}

	public function testInitializeConnectionThrowsWhenSetupTokenIsEmptyString(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Setup token is required');

		$this->provider->initializeConnection(['setupToken' => '']);
	}

	public function testInitializeConnectionThrowsWhenTokenIsInvalidBase64(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid setup token');

		// Valid base64 but decodes to a non-URL string
		$this->provider->initializeConnection(['setupToken' => base64_encode('not-a-url')]);
	}

	public function testInitializeConnectionThrowsWhenTokenDecodesButNotUrl(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid setup token');

		// Garbage that is technically valid base64
		$this->provider->initializeConnection(['setupToken' => 'AAAA']);
	}

	public function testInitializeConnectionClaimsTokenAndFetchesAccounts(): void {
		$claimUrl = 'https://beta-bridge.simplefin.org/claim/abc123';
		$setupToken = base64_encode($claimUrl);
		$accessUrl = 'https://user:pass@beta-bridge.simplefin.org/access/xyz';

		// First call: POST to claim URL returns access URL
		$claimResponse = $this->createMock(IResponse::class);
		$claimResponse->method('getBody')->willReturn($accessUrl);

		// Second call: GET /accounts returns account data
		$accountsResponse = $this->createMock(IResponse::class);
		$accountsResponse->method('getBody')->willReturn(json_encode([
			'accounts' => [
				[
					'id' => 'acct-1',
					'name' => 'Checking',
					'currency' => 'usd',
					'balance' => '1234.56',
					'transactions' => [],
				],
			],
		]));

		$this->client->expects($this->once())
			->method('post')
			->with($claimUrl, $this->anything())
			->willReturn($claimResponse);

		$this->client->expects($this->once())
			->method('get')
			->willReturn($accountsResponse);

		$result = $this->provider->initializeConnection(['setupToken' => $setupToken]);

		$this->assertSame($accessUrl, $result['credentials']);
		$this->assertCount(1, $result['accounts']);
		$this->assertSame('acct-1', $result['accounts'][0]['id']);
		$this->assertSame('Checking', $result['accounts'][0]['name']);
		$this->assertSame('USD', $result['accounts'][0]['currency']);
	}

	public function testInitializeConnectionThrowsWhenClaimReturnsEmptyBody(): void {
		$claimUrl = 'https://beta-bridge.simplefin.org/claim/abc123';
		$setupToken = base64_encode($claimUrl);

		$claimResponse = $this->createMock(IResponse::class);
		$claimResponse->method('getBody')->willReturn('');

		$this->client->method('post')->willReturn($claimResponse);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Failed to claim SimpleFIN token');

		$this->provider->initializeConnection(['setupToken' => $setupToken]);
	}

	// ── fetchAccounts ───────────────────────────────────────────────

	public function testFetchAccountsThrowsWhenCredentialsHaveNoUser(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Invalid SimpleFIN access URL');

		$this->provider->fetchAccounts('https://beta-bridge.simplefin.org/access/xyz');
	}

	public function testFetchAccountsThrowsOnEmptyCredentials(): void {
		$this->expectException(\Exception::class);
		$this->provider->fetchAccounts('');
	}

	public function testFetchAccountsNormalizesAccountData(): void {
		$accessUrl = 'https://user:pass@bridge.simplefin.org/simplefin';

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			'accounts' => [
				[
					'id' => 'acct-checking',
					'name' => 'My Checking',
					'currency' => 'usd',
					'balance' => '500.25',
					'transactions' => [
						[
							'id' => 'tx-1',
							'posted' => 1700000000,
							'amount' => '-25.50',
							'description' => 'Coffee Shop',
						],
						[
							'id' => 'tx-2',
							'posted' => 0,
							'transacted_at' => 1700100000,
							'amount' => '100.00',
							'description' => 'Deposit',
							'pending' => true,
						],
					],
				],
				[
					'id' => 'acct-savings',
					'name' => 'Savings',
					'currency' => 'eur',
					'balance' => '10000',
					'transactions' => [],
				],
			],
		]));

		$this->client->expects($this->once())
			->method('get')
			->with(
				$this->stringStartsWith('https://bridge.simplefin.org/simplefin/accounts?start-date='),
				$this->callback(function ($opts) {
					return $opts['auth'] === ['user', 'pass'] && $opts['timeout'] === 30;
				})
			)
			->willReturn($response);

		$result = $this->provider->fetchAccounts($accessUrl);

		$this->assertArrayHasKey('accounts', $result);
		$this->assertCount(2, $result['accounts']);

		// First account
		$checking = $result['accounts'][0];
		$this->assertSame('acct-checking', $checking['id']);
		$this->assertSame('My Checking', $checking['name']);
		$this->assertSame('USD', $checking['currency']);
		$this->assertSame('500.25', $checking['balance']);
		$this->assertCount(2, $checking['transactions']);

		// Transaction with posted timestamp
		$tx1 = $checking['transactions'][0];
		$this->assertSame('tx-1', $tx1['id']);
		$this->assertSame(date('Y-m-d', 1700000000), $tx1['date']);
		$this->assertSame('-25.50', $tx1['amount']);
		$this->assertSame('Coffee Shop', $tx1['description']);
		$this->assertNull($tx1['vendor']);
		$this->assertFalse($tx1['pending']);

		// Pending transaction (posted=0, uses transacted_at)
		$tx2 = $checking['transactions'][1];
		$this->assertSame('tx-2', $tx2['id']);
		$this->assertSame(date('Y-m-d', 1700100000), $tx2['date']);
		$this->assertTrue($tx2['pending']);

		// Second account -- currency uppercased
		$savings = $result['accounts'][1];
		$this->assertSame('EUR', $savings['currency']);
		$this->assertSame('10000', $savings['balance']);
		$this->assertEmpty($savings['transactions']);
	}

	public function testFetchAccountsHandlesMissingFields(): void {
		$accessUrl = 'https://user:pass@bridge.simplefin.org/simplefin';

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			'accounts' => [
				[
					// Minimal account -- missing most fields
					'transactions' => [
						[
							// Transaction with no id, no posted, no transacted_at
							'amount' => '-10',
							'description' => 'Mystery charge',
						],
					],
				],
			],
		]));

		$this->client->method('get')->willReturn($response);

		$result = $this->provider->fetchAccounts($accessUrl);

		$account = $result['accounts'][0];
		$this->assertSame('', $account['id']);
		$this->assertSame('Unknown Account', $account['name']);
		$this->assertSame('USD', $account['currency']);
		$this->assertSame('0', $account['balance']);

		$tx = $account['transactions'][0];
		// Should generate a hash ID since none was provided
		$this->assertNotEmpty($tx['id']);
		// With posted=0 and no transacted_at, should fall back to today
		$this->assertSame(date('Y-m-d'), $tx['date']);
		$this->assertTrue($tx['pending']);
	}

	public function testFetchAccountsThrowsOnUnexpectedResponseFormat(): void {
		$accessUrl = 'https://user:pass@bridge.simplefin.org/simplefin';

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['error' => 'bad']));

		$this->client->method('get')->willReturn($response);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Unexpected response format from SimpleFIN');

		$this->provider->fetchAccounts($accessUrl);
	}

	public function testFetchAccountsThrowsOnHttpError(): void {
		$accessUrl = 'https://user:pass@bridge.simplefin.org/simplefin';

		$this->client->method('get')
			->willThrowException(new \Exception('Connection refused'));

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Failed to fetch accounts from SimpleFIN');

		$this->provider->fetchAccounts($accessUrl);
	}

	public function testFetchAccountsHandlesAccessUrlWithPort(): void {
		$accessUrl = 'https://user:pass@bridge.simplefin.org:8443/simplefin';

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			'accounts' => [],
		]));

		$this->client->expects($this->once())
			->method('get')
			->with(
				$this->stringStartsWith('https://bridge.simplefin.org:8443/simplefin/accounts?start-date='),
				$this->anything()
			)
			->willReturn($response);

		$result = $this->provider->fetchAccounts($accessUrl);
		$this->assertSame([], $result['accounts']);
	}

	// ── fetchAccountList ────────────────────────────────────────────

	public function testFetchAccountListDelegatesToFetchAccounts(): void {
		$accessUrl = 'https://user:pass@bridge.simplefin.org/simplefin';

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode([
			'accounts' => [
				['id' => 'a1', 'name' => 'Test', 'currency' => 'USD', 'balance' => '100', 'transactions' => []],
			],
		]));

		$this->client->method('get')->willReturn($response);

		$result = $this->provider->fetchAccountList($accessUrl);

		$this->assertArrayHasKey('accounts', $result);
		$this->assertCount(1, $result['accounts']);
		$this->assertSame('a1', $result['accounts'][0]['id']);
	}

	// ── requiresReauthorization ─────────────────────────────────────

	public function testRequiresReauthorizationAlwaysReturnsFalse(): void {
		$this->assertFalse($this->provider->requiresReauthorization('anything'));
		$this->assertFalse($this->provider->requiresReauthorization(''));
	}

	// ── revokeConnection ────────────────────────────────────────────

	public function testRevokeConnectionDoesNothing(): void {
		// Should not throw and should not call any HTTP methods
		$this->client->expects($this->never())->method('post');
		$this->client->expects($this->never())->method('get');
		$this->client->expects($this->never())->method('delete');

		$this->provider->revokeConnection('https://user:pass@bridge.simplefin.org/access');
	}
}
