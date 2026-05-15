<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\BankSync;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BankAccountMapping;
use OCA\Budget\Db\BankAccountMappingMapper;
use OCA\Budget\Db\BankConnection;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\BankSync\BankSyncProviderInterface;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCA\Budget\Service\BankSync\ProviderFactory;
use OCA\Budget\Service\TransactionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BankSyncServiceTest extends TestCase {
	private BankSyncService $service;
	private BankConnectionMapper $connectionMapper;
	private BankAccountMappingMapper $mappingMapper;
	private ProviderFactory $providerFactory;
	private TransactionService $transactionService;
	private AuditService $auditService;
	private AdminSettingService $adminSettings;
	private AccountMapper $accountMapper;
	private LoggerInterface $logger;
	private BankSyncProviderInterface $provider;

	private const USER_ID = 'user1';

	protected function setUp(): void {
		$this->connectionMapper = $this->createMock(BankConnectionMapper::class);
		$this->mappingMapper = $this->createMock(BankAccountMappingMapper::class);
		$this->providerFactory = $this->createMock(ProviderFactory::class);
		$this->transactionService = $this->createMock(TransactionService::class);
		$this->auditService = $this->createMock(AuditService::class);
		$this->adminSettings = $this->createMock(AdminSettingService::class);
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->provider = $this->createMock(BankSyncProviderInterface::class);

		$this->service = new BankSyncService(
			$this->connectionMapper,
			$this->mappingMapper,
			$this->providerFactory,
			$this->transactionService,
			$this->auditService,
			$this->adminSettings,
			$this->accountMapper,
			$this->logger
		);
	}

	// ===== connect =====

	public function testConnectThrowsWhenDisabled(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(false);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Bank sync is disabled by the administrator');

		$this->service->connect(self::USER_ID, 'simplefin', ['token' => 'abc'], 'My Bank');
	}

	public function testConnectCreatesConnectionAndMappings(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$this->providerFactory->method('getProvider')
			->with('simplefin')
			->willReturn($this->provider);

		$this->provider->method('initializeConnection')
			->with(['token' => 'abc'])
			->willReturn([
				'credentials' => 'encrypted-creds',
				'accounts' => [
					['id' => 'ext-1', 'name' => 'Checking', 'balance' => '1500.00', 'currency' => 'USD'],
					['id' => 'ext-2', 'name' => 'Savings', 'balance' => '5000.00', 'currency' => 'USD'],
				],
				'authorizationUrl' => null,
			]);

		$insertedConnection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');

		$this->connectionMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (BankConnection $conn) use ($insertedConnection) {
				$this->assertEquals(self::USER_ID, $conn->getUserId());
				$this->assertEquals('simplefin', $conn->getProvider());
				$this->assertEquals('My Bank', $conn->getName());
				$this->assertEquals('encrypted-creds', $conn->getCredentials());
				$this->assertEquals('active', $conn->getStatus());
				return $insertedConnection;
			});

		$this->mappingMapper->expects($this->exactly(2))->method('insert')
			->willReturnCallback(function (BankAccountMapping $m) {
				$this->assertEquals(1, $m->getConnectionId());
				$this->assertFalse($m->getEnabled());
				return $m;
			});

		$mappings = [$this->createMapping(10, 1, 'ext-1'), $this->createMapping(11, 1, 'ext-2')];
		$this->mappingMapper->method('findByConnection')->with(1)->willReturn($mappings);

		$this->auditService->expects($this->once())->method('log')
			->with(self::USER_ID, 'bank_connected', 'bank_connection', 1, $this->callback(function ($meta) {
				return $meta['provider'] === 'simplefin'
					&& $meta['name'] === 'My Bank'
					&& $meta['accountCount'] === 2;
			}));

		$result = $this->service->connect(self::USER_ID, 'simplefin', ['token' => 'abc'], 'My Bank');

		$this->assertSame($insertedConnection, $result['connection']);
		$this->assertCount(2, $result['mappings']);
		$this->assertNull($result['authorizationUrl']);
	}

	// ===== disconnect =====

	public function testDisconnectDeletesMappingsAndConnection(): void {
		$connection = $this->createConnection(5, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->with(5, self::USER_ID)->willReturn($connection);

		$this->mappingMapper->expects($this->once())->method('deleteByConnection')->with(5);
		$this->connectionMapper->expects($this->once())->method('delete')->with($connection);

		$this->auditService->expects($this->once())->method('log')
			->with(self::USER_ID, 'bank_disconnected', 'bank_connection', 5, $this->callback(function ($meta) {
				return $meta['provider'] === 'simplefin' && $meta['name'] === 'My Bank';
			}));

		$this->service->disconnect(self::USER_ID, 5);
	}

	// ===== sync =====

	public function testSyncThrowsWhenDisabled(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(false);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Bank sync is disabled by the administrator');

		$this->service->sync(self::USER_ID, 1);
	}

	public function testSyncThrowsWhenConnectionNotActive(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'expired');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Connection is not active');

		$this->service->sync(self::USER_ID, 1);
	}

	public function testSyncSetsStatusExpiredWhenReauthorizationNeeded(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'gocardless', 'EU Bank', 'active');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('requiresReauthorization')->willReturn(true);

		$this->connectionMapper->expects($this->once())->method('update')
			->willReturnCallback(function (BankConnection $conn) {
				$this->assertEquals('expired', $conn->getStatus());
				$this->assertNotNull($conn->getLastError());
				return $conn;
			});

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Bank authorization has expired');

		$this->service->sync(self::USER_ID, 1);
	}

	public function testSyncSetsStatusErrorOnProviderException(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('requiresReauthorization')->willReturn(false);
		$this->provider->method('fetchAccounts')->willThrowException(new \Exception('API timeout'));

		$this->connectionMapper->expects($this->once())->method('update')
			->willReturnCallback(function (BankConnection $conn) {
				$this->assertEquals('error', $conn->getStatus());
				$this->assertEquals('API timeout', $conn->getLastError());
				return $conn;
			});

		$this->auditService->expects($this->once())->method('log')
			->with(self::USER_ID, 'bank_sync_failed', 'bank_connection', 1, $this->anything());

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('API timeout');

		$this->service->sync(self::USER_ID, 1);
	}

	public function testSyncReturnsDiscoveredCountWhenNoEnabledMappings(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('requiresReauthorization')->willReturn(false);
		$this->provider->method('fetchAccounts')->willReturn([
			'accounts' => [
				['id' => 'ext-1', 'name' => 'Checking', 'balance' => '1000', 'currency' => 'USD', 'transactions' => []],
			],
		]);

		// Has mappings but none enabled
		$disabledMapping = $this->createMapping(10, 1, 'ext-1');
		$this->mappingMapper->method('findByConnection')->with(1)->willReturn([$disabledMapping]);
		$this->mappingMapper->method('findEnabledByConnection')->with(1)->willReturn([]);

		$this->connectionMapper->expects($this->once())->method('update')
			->willReturnCallback(function (BankConnection $conn) {
				$this->assertNotNull($conn->getLastSyncAt());
				return $conn;
			});

		$result = $this->service->sync(self::USER_ID, 1);

		$this->assertEquals(0, $result['imported']);
		$this->assertEquals(0, $result['skipped']);
		$this->assertEquals(0, $result['errors']);
		$this->assertEmpty($result['accounts']);
		$this->assertEquals(1, $result['discovered']);
	}

	public function testSyncImportsTransactionsAndSkipsDuplicates(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('requiresReauthorization')->willReturn(false);
		$this->provider->method('fetchAccounts')->willReturn([
			'accounts' => [
				[
					'id' => 'ext-1',
					'name' => 'Checking',
					'balance' => '1200.00',
					'currency' => 'USD',
					'transactions' => [
						['id' => 'tx-new', 'date' => '2026-05-10', 'amount' => '-50.00', 'description' => 'Coffee'],
						['id' => 'tx-dup', 'date' => '2026-05-09', 'amount' => '-25.00', 'description' => 'Lunch'],
					],
				],
			],
		]);

		$mapping = $this->createMapping(10, 1, 'ext-1', 100, true);
		$this->mappingMapper->method('findByConnection')->with(1)->willReturn([$mapping]);
		$this->mappingMapper->method('findEnabledByConnection')->with(1)->willReturn([$mapping]);

		$account = new \OCA\Budget\Db\Account();
		$account->setId(100);
		$account->setUserId(self::USER_ID);
		$this->accountMapper->method('find')->with(100, self::USER_ID)->willReturn($account);

		$this->transactionService->method('existsByImportId')
			->willReturnMap([
				[100, 'simplefin:tx-new', false],
				[100, 'simplefin:tx-dup', true],
			]);

		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(999);
		$created = [];
		$this->transactionService->method('create')
			->willReturnCallback(function () use ($tx, &$created) {
				$args = func_get_args();
				$created[] = $args;
				return $tx;
			});

		$result = $this->service->sync(self::USER_ID, 1);

		$this->assertCount(1, $created, 'Expected exactly 1 transaction created');

		$this->assertEquals(1, $result['imported']);
		$this->assertEquals(1, $result['skipped']);
		$this->assertEquals(0, $result['errors']);
		$this->assertCount(1, $result['accounts']);
		$this->assertEquals('ext-1', $result['accounts'][0]['externalAccountId']);
	}

	public function testSyncNegativeAmountCreatesDebitPositiveCreatesCredit(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('requiresReauthorization')->willReturn(false);
		$this->provider->method('fetchAccounts')->willReturn([
			'accounts' => [
				[
					'id' => 'ext-1',
					'name' => 'Checking',
					'balance' => '2000.00',
					'currency' => 'USD',
					'transactions' => [
						['id' => 'tx-out', 'date' => '2026-05-10', 'amount' => '-75.50', 'description' => 'Groceries'],
						['id' => 'tx-in', 'date' => '2026-05-11', 'amount' => '2500.00', 'description' => 'Salary'],
					],
				],
			],
		]);

		$mapping = $this->createMapping(10, 1, 'ext-1', 100, true);
		$this->mappingMapper->method('findByConnection')->with(1)->willReturn([$mapping]);
		$this->mappingMapper->method('findEnabledByConnection')->with(1)->willReturn([$mapping]);
		$dummyAccount = new \OCA\Budget\Db\Account();
		$dummyAccount->setId(100);
		$dummyAccount->setUserId(self::USER_ID);
		$this->accountMapper->method('find')->willReturn($dummyAccount);
		$this->transactionService->method('existsByImportId')->willReturn(false);

		$createdTypes = [];
		$dummyTx = new \OCA\Budget\Db\Transaction();
		$dummyTx->setId(1);
		$this->transactionService->expects($this->exactly(2))->method('create')
			->willReturnCallback(function () use (&$createdTypes, $dummyTx) {
				$args = func_get_args();
				// Named args come through as positional in the mock
				// amount is index 4, type is index 5
				$createdTypes[] = ['amount' => $args[4], 'type' => $args[5]];
				return $dummyTx;
			});

		$this->service->sync(self::USER_ID, 1);

		$this->assertEquals(75.50, $createdTypes[0]['amount']);
		$this->assertEquals('debit', $createdTypes[0]['type']);
		$this->assertEquals(2500.00, $createdTypes[1]['amount']);
		$this->assertEquals('credit', $createdTypes[1]['type']);
	}

	public function testSyncUpdatesConnectionTimestamp(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('requiresReauthorization')->willReturn(false);
		$this->provider->method('fetchAccounts')->willReturn([
			'accounts' => [
				['id' => 'ext-1', 'name' => 'Checking', 'balance' => '100', 'currency' => 'USD', 'transactions' => []],
			],
		]);

		$mapping = $this->createMapping(10, 1, 'ext-1', 100, true);
		$this->mappingMapper->method('findByConnection')->with(1)->willReturn([$mapping]);
		$this->mappingMapper->method('findEnabledByConnection')->with(1)->willReturn([$mapping]);
		$dummyAccount = new \OCA\Budget\Db\Account();
		$dummyAccount->setId(100);
		$dummyAccount->setUserId(self::USER_ID);
		$this->accountMapper->method('find')->willReturn($dummyAccount);

		$updatedConnection = false;
		$this->connectionMapper->method('update')
			->willReturnCallback(function (BankConnection $conn) use (&$updatedConnection) {
				$updatedConnection = true;
				$this->assertNotNull($conn->getLastSyncAt());
				$this->assertEquals('active', $conn->getStatus());
				return $conn;
			});

		$this->service->sync(self::USER_ID, 1);

		$this->assertTrue($updatedConnection);
	}

	public function testSyncLogsAuditOnCompletion(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('requiresReauthorization')->willReturn(false);
		$this->provider->method('fetchAccounts')->willReturn([
			'accounts' => [
				[
					'id' => 'ext-1',
					'name' => 'Checking',
					'balance' => '500',
					'currency' => 'USD',
					'transactions' => [
						['id' => 'tx-1', 'date' => '2026-05-10', 'amount' => '-10', 'description' => 'Test'],
					],
				],
			],
		]);

		$mapping = $this->createMapping(10, 1, 'ext-1', 100, true);
		$this->mappingMapper->method('findByConnection')->with(1)->willReturn([$mapping]);
		$this->mappingMapper->method('findEnabledByConnection')->with(1)->willReturn([$mapping]);
		$dummyAccount = new \OCA\Budget\Db\Account();
		$dummyAccount->setId(100);
		$dummyAccount->setUserId(self::USER_ID);
		$this->accountMapper->method('find')->willReturn($dummyAccount);
		$this->transactionService->method('existsByImportId')->willReturn(false);
		$dummyTx = new \OCA\Budget\Db\Transaction();
		$dummyTx->setId(1);
		$this->transactionService->method('create')->willReturn($dummyTx);

		$this->auditService->expects($this->once())->method('log')
			->with(
				self::USER_ID,
				'bank_sync_completed',
				'bank_connection',
				1,
				$this->callback(function ($meta) {
					return isset($meta['imported']) && isset($meta['skipped']) && isset($meta['errors']);
				})
			);

		$this->service->sync(self::USER_ID, 1);
	}

	// ===== getConnections =====

	public function testGetConnectionsReturnsConnectionsWithMappings(): void {
		$conn1 = $this->createConnection(1, 'simplefin', 'Bank A', 'active');
		$conn2 = $this->createConnection(2, 'gocardless', 'Bank B', 'active');

		$this->connectionMapper->method('findAll')->with(self::USER_ID)->willReturn([$conn1, $conn2]);

		$mappings1 = [$this->createMapping(10, 1, 'ext-1')];
		$mappings2 = [$this->createMapping(20, 2, 'ext-2'), $this->createMapping(21, 2, 'ext-3')];

		$this->mappingMapper->method('findByConnection')
			->willReturnMap([
				[1, $mappings1],
				[2, $mappings2],
			]);

		$result = $this->service->getConnections(self::USER_ID);

		$this->assertCount(2, $result);
		$this->assertSame($conn1, $result[0]['connection']);
		$this->assertCount(1, $result[0]['mappings']);
		$this->assertSame($conn2, $result[1]['connection']);
		$this->assertCount(2, $result[1]['mappings']);
	}

	// ===== updateMapping =====

	public function testUpdateMappingThrowsWhenMappingDoesNotBelongToConnection(): void {
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$mapping = $this->createMapping(10, 99, 'ext-1'); // connectionId=99, not 1
		$this->mappingMapper->method('find')->with(10)->willReturn($mapping);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Mapping does not belong to this connection');

		$this->service->updateMapping(self::USER_ID, 1, 10, 100, false, true);
	}

	public function testUpdateMappingUpdatesBudgetAccountIdAndEnabled(): void {
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$mapping = $this->createMapping(10, 1, 'ext-1');
		$this->mappingMapper->method('find')->with(10)->willReturn($mapping);
		$budgetAccount = new \OCA\Budget\Db\Account();
		$budgetAccount->setId(200);
		$budgetAccount->setUserId(self::USER_ID);
		$this->accountMapper->method('find')->with(200, self::USER_ID)->willReturn($budgetAccount);

		$this->mappingMapper->expects($this->once())->method('update')
			->willReturnCallback(function (BankAccountMapping $m) {
				$this->assertEquals(200, $m->getBudgetAccountId());
				$this->assertTrue($m->getEnabled());
				$this->assertNotNull($m->getUpdatedAt());
				return $m;
			});

		$result = $this->service->updateMapping(self::USER_ID, 1, 10, 200, false, true);

		$this->assertInstanceOf(BankAccountMapping::class, $result);
	}

	// ===== refreshAccounts =====

	public function testRefreshAccountsAddsNewAccountsAsMappings(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('fetchAccountList')->willReturn([
			'accounts' => [
				['id' => 'ext-new', 'name' => 'New Account', 'balance' => '3000', 'currency' => 'EUR'],
			],
		]);

		$this->mappingMapper->method('findByExternalId')->with(1, 'ext-new')->willReturn(null);

		$this->mappingMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (BankAccountMapping $m) {
				$this->assertEquals(1, $m->getConnectionId());
				$this->assertEquals('ext-new', $m->getExternalAccountId());
				$this->assertEquals('New Account', $m->getExternalAccountName());
				$this->assertFalse($m->getEnabled());
				$this->assertEquals('3000', $m->getLastBalance());
				$this->assertEquals('EUR', $m->getLastCurrency());
				return $m;
			});

		$allMappings = [$this->createMapping(10, 1, 'ext-new')];
		$this->mappingMapper->method('findByConnection')->with(1)->willReturn($allMappings);

		$result = $this->service->refreshAccounts(self::USER_ID, 1);

		$this->assertCount(1, $result);
	}

	public function testRefreshAccountsUpdatesBalanceForExistingAccounts(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('fetchAccountList')->willReturn([
			'accounts' => [
				['id' => 'ext-1', 'name' => 'Checking', 'balance' => '9999.99', 'currency' => 'USD'],
			],
		]);

		$existingMapping = $this->createMapping(10, 1, 'ext-1', 100, true);
		$this->mappingMapper->method('findByExternalId')->with(1, 'ext-1')->willReturn($existingMapping);

		$this->mappingMapper->expects($this->never())->method('insert');
		$this->mappingMapper->expects($this->once())->method('update')
			->willReturnCallback(function (BankAccountMapping $m) {
				$this->assertEquals('9999.99', $m->getLastBalance());
				$this->assertEquals('USD', $m->getLastCurrency());
				return $m;
			});

		$this->mappingMapper->method('findByConnection')->with(1)->willReturn([$existingMapping]);

		$result = $this->service->refreshAccounts(self::USER_ID, 1);

		$this->assertCount(1, $result);
	}

	// ===== reauthorize =====

	public function testReauthorizeCreatesNewRequisitionAndUpdatesConnection(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = new BankConnection();
		$connection->setId(1);
		$connection->setUserId(self::USER_ID);
		$connection->setProvider('gocardless');
		$connection->setCredentials(json_encode([
			'secretId' => 'sid',
			'secretKey' => 'skey',
			'accessToken' => 'old-token',
			'requisitionId' => 'old-req',
		]));
		$connection->setStatus('expired');

		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);
		$this->providerFactory->method('getProvider')->with('gocardless')->willReturn($this->provider);

		$this->provider->method('initializeConnection')->willReturn([
			'credentials' => json_encode(['secretId' => 'sid', 'secretKey' => 'skey', 'requisitionId' => 'new-req']),
			'accounts' => [],
			'authorizationUrl' => 'https://bank.example.com/auth',
		]);

		$this->connectionMapper->expects($this->once())->method('update')
			->willReturnCallback(function (BankConnection $c) {
				$this->assertEquals('pending_auth', $c->getStatus());
				$this->assertNull($c->getLastError());
				$creds = json_decode($c->getCredentials(), true);
				$this->assertEquals('new-req', $creds['requisitionId']);
				return $c;
			});

		$result = $this->service->reauthorize(self::USER_ID, 1, 'BANK_ID', 'https://app/callback');

		$this->assertEquals('https://bank.example.com/auth', $result['authorizationUrl']);
	}

	public function testReauthorizeRejectsNonGoCardlessProvider(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = new BankConnection();
		$connection->setId(1);
		$connection->setUserId(self::USER_ID);
		$connection->setProvider('simplefin');
		$connection->setCredentials('{}');

		$this->connectionMapper->method('find')->willReturn($connection);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('only supported for GoCardless');

		$this->service->reauthorize(self::USER_ID, 1, 'BANK_ID', '');
	}

	public function testReauthorizeThrowsWhenDisabled(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(false);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('disabled');

		$this->service->reauthorize(self::USER_ID, 1, 'BANK_ID', '');
	}

	// ===== connect edge cases =====

	public function testConnectWithAuthorizationUrlSetsPendingAuthStatus(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('initializeConnection')->willReturn([
			'credentials' => '{"secretId":"sid"}',
			'accounts' => [],
			'authorizationUrl' => 'https://bank.example.com/auth',
		]);

		$this->connectionMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (BankConnection $c) {
				$this->assertEquals('pending_auth', $c->getStatus());
				$c->setId(1);
				return $c;
			});

		$this->mappingMapper->method('findByConnection')->willReturn([]);

		$result = $this->service->connect(self::USER_ID, 'gocardless', ['secretId' => 'sid', 'secretKey' => 'skey', 'institutionId' => 'BANK_1'], 'My Bank');

		$this->assertEquals('https://bank.example.com/auth', $result['authorizationUrl']);
	}

	public function testConnectWithoutAuthUrlSetsActiveStatus(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('initializeConnection')->willReturn([
			'credentials' => '{"token":"abc"}',
			'accounts' => [['id' => 'a1', 'name' => 'Checking', 'balance' => '100', 'currency' => 'USD']],
		]);

		$this->connectionMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (BankConnection $c) {
				$this->assertEquals('active', $c->getStatus());
				$c->setId(1);
				return $c;
			});

		$this->mappingMapper->method('insert')->willReturnArgument(0);
		$this->mappingMapper->method('findByConnection')->willReturn([]);

		$result = $this->service->connect(self::USER_ID, 'simplefin', ['setupToken' => 'tok'], 'My Bank');

		$this->assertNull($result['authorizationUrl']);
	}

	// ===== updateMapping edge cases =====

	public function testUpdateMappingClearsBudgetAccountId(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->willReturn($connection);

		$mapping = $this->createMapping(10, 1, 'ext-1', 200, true);
		$this->mappingMapper->method('find')->with(10)->willReturn($mapping);

		$this->mappingMapper->expects($this->once())->method('update')
			->willReturnCallback(function (BankAccountMapping $m) {
				$this->assertNull($m->getBudgetAccountId());
				return $m;
			});

		$this->service->updateMapping(self::USER_ID, 1, 10, null, true, null);
	}

	// ===== disconnect edge cases =====

	public function testDisconnectCallsProviderRevoke(): void {
		$connection = $this->createConnection(1, 'gocardless', 'My Bank', 'active');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);
		$this->providerFactory->method('getProvider')->with('gocardless')->willReturn($this->provider);

		$this->provider->expects($this->once())->method('revokeConnection');
		$this->mappingMapper->expects($this->once())->method('deleteByConnection')->with(1);
		$this->connectionMapper->expects($this->once())->method('delete')->with($connection);

		$this->service->disconnect(self::USER_ID, 1);
	}

	// ===== refreshAccounts edge cases =====

	public function testRefreshAccountsPromotesPendingAuthToActive(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'gocardless', 'My Bank', 'pending_auth');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);
		$this->providerFactory->method('getProvider')->willReturn($this->provider);

		$this->provider->method('fetchAccountList')->willReturn([
			'accounts' => [['id' => 'ext-1', 'name' => 'Checking', 'balance' => '500', 'currency' => 'GBP']],
		]);

		$this->mappingMapper->method('findByExternalId')->willReturn(null);
		$this->mappingMapper->method('insert')->willReturnArgument(0);
		$this->mappingMapper->method('findByConnection')->willReturn([]);

		$this->connectionMapper->expects($this->once())->method('update')
			->willReturnCallback(function (BankConnection $c) {
				$this->assertEquals('active', $c->getStatus());
				return $c;
			});

		$this->service->refreshAccounts(self::USER_ID, 1);
	}

	// ===== Helpers =====

	private function createConnection(int $id, string $provider, string $name, string $status): BankConnection {
		$conn = new BankConnection();
		$conn->setId($id);
		$conn->setUserId(self::USER_ID);
		$conn->setProvider($provider);
		$conn->setName($name);
		$conn->setCredentials('creds-' . $id);
		$conn->setStatus($status);
		$conn->setCreatedAt('2026-05-01 00:00:00');
		$conn->setUpdatedAt('2026-05-01 00:00:00');
		return $conn;
	}

	private function createMapping(
		int $id,
		int $connectionId,
		string $externalAccountId,
		?int $budgetAccountId = null,
		bool $enabled = false
	): BankAccountMapping {
		$m = new BankAccountMapping();
		$m->setId($id);
		$m->setConnectionId($connectionId);
		$m->setExternalAccountId($externalAccountId);
		$m->setExternalAccountName('Account ' . $externalAccountId);
		$m->setBudgetAccountId($budgetAccountId);
		$m->setEnabled($enabled);
		$m->setCreatedAt('2026-05-01 00:00:00');
		$m->setUpdatedAt('2026-05-01 00:00:00');
		return $m;
	}
}
