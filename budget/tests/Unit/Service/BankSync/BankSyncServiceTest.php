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
use OCP\IL10N;
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
	private IL10N $l;
	private LoggerInterface $logger;
	private BankSyncProviderInterface $provider;
	private \OCA\Budget\Service\BillService $billService;

	private const USER_ID = 'user1';

	protected function setUp(): void {
		$this->connectionMapper = $this->createMock(BankConnectionMapper::class);
		$this->mappingMapper = $this->createMock(BankAccountMappingMapper::class);
		$this->providerFactory = $this->createMock(ProviderFactory::class);
		$this->transactionService = $this->createMock(TransactionService::class);
		$this->auditService = $this->createMock(AuditService::class);
		$this->adminSettings = $this->createMock(AdminSettingService::class);
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->provider = $this->createMock(BankSyncProviderInterface::class);

		$dismissedImportMapper = $this->createMock(\OCA\Budget\Db\DismissedImportMapper::class);

		$ruleApplicator = $this->createMock(\OCA\Budget\Service\Import\ImportRuleApplicator::class);
		$ruleApplicator->method('applyRules')->willReturnArgument(1);

		$transactionTagService = $this->createMock(\OCA\Budget\Service\TransactionTagService::class);
		$this->billService = $this->createMock(\OCA\Budget\Service\BillService::class);

		$this->service = new BankSyncService(
			$this->connectionMapper,
			$this->mappingMapper,
			$this->providerFactory,
			$this->transactionService,
			$this->auditService,
			$this->adminSettings,
			$this->accountMapper,
			$dismissedImportMapper,
			$ruleApplicator,
			$transactionTagService,
			$this->billService,
			$this->l,
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

	public function testSyncThrowsWhenConnectionExpired(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'expired');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Bank authorization has expired');

		$this->service->sync(self::USER_ID, 1);
	}

	public function testSyncRetriesConnectionInErrorState(): void {
		// A failed fetch sets status 'error'; the next sync must attempt the
		// provider again instead of throwing 'Connection is not active' forever
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'error');
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);

		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('requiresReauthorization')->willReturn(false);
		$this->provider->expects($this->once())->method('fetchAccounts')
			->willThrowException(new \Exception('Payment required (402)'));

		$this->connectionMapper->expects($this->once())->method('update')
			->willReturnCallback(function (BankConnection $conn) {
				$this->assertEquals('Payment required (402)', $conn->getLastError());
				return $conn;
			});

		// The real provider error surfaces, not the status gate
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Payment required (402)');

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

		$existingDup = new \OCA\Budget\Db\Transaction();
		$existingDup->setId(500);
		$existingDup->setStatus('cleared');
		$this->transactionService->method('findByImportId')
			->willReturnMap([
				[100, 'simplefin:tx-new', null],
				[100, 'simplefin:tx-dup', $existingDup],
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

	/**
	 * Synced rows are offered to pension entries the app already booked, so
	 * the bank's row replaces the app's instead of doubling the payment.
	 */
	public function testSyncedRowsAreOfferedToPensionEntriesTheAppBooked(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$this->connectionMapper->method('find')->willReturn($connection);
		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('requiresReauthorization')->willReturn(false);
		$this->provider->method('fetchAccounts')->willReturn([
			'accounts' => [[
				'id' => 'ext-1', 'name' => 'Checking', 'balance' => '1200.00', 'currency' => 'USD',
				'transactions' => [['id' => 'tx-pen', 'date' => '2026-10-02', 'amount' => '-200.00', 'description' => 'NEST PENSIONS']],
			]],
		]);
		$mapping = $this->createMapping(10, 1, 'ext-1', 100, true);
		$this->mappingMapper->method('findByConnection')->willReturn([$mapping]);
		$this->mappingMapper->method('findEnabledByConnection')->willReturn([$mapping]);
		$account = new \OCA\Budget\Db\Account();
		$account->setId(100);
		$account->setUserId(self::USER_ID);
		$this->accountMapper->method('find')->willReturn($account);
		$this->transactionService->method('findByImportId')->willReturn(null);
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(999);
		$this->transactionService->method('create')->willReturn($tx);
		$pensions = $this->createMock(\OCA\Budget\Service\PensionService::class);
		$pensions->expects($this->once())->method('adoptImportedDuplicates')->with(self::USER_ID, [$tx]);
		$service = new BankSyncService(
			$this->connectionMapper, $this->mappingMapper, $this->providerFactory, $this->transactionService,
			$this->auditService, $this->adminSettings, $this->accountMapper,
			$this->createMock(\OCA\Budget\Db\DismissedImportMapper::class),
			$this->createMock(\OCA\Budget\Service\Import\ImportRuleApplicator::class),
			$this->createMock(\OCA\Budget\Service\TransactionTagService::class),
			$this->createMock(\OCA\Budget\Service\BillService::class),
			$this->l, $this->logger,
			pensionService: $pensions,
		);

		$service->sync(self::USER_ID, 1);
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

	// ===== pending transactions (issue #257) =====

	/** Build a one-account sync fixture with the given incoming transactions. */
	private function setUpPendingSync(BankConnection $connection, array $transactions): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
		$this->connectionMapper->method('find')->with(1, self::USER_ID)->willReturn($connection);
		$this->providerFactory->method('getProvider')->willReturn($this->provider);
		$this->provider->method('requiresReauthorization')->willReturn(false);
		$this->provider->method('fetchAccounts')->willReturn([
			'accounts' => [[
				'id' => 'ext-1', 'name' => 'Checking', 'balance' => '100', 'currency' => 'USD',
				'transactions' => $transactions,
			]],
		]);
		$mapping = $this->createMapping(10, 1, 'ext-1', 100, true);
		$this->mappingMapper->method('findByConnection')->willReturn([$mapping]);
		$this->mappingMapper->method('findEnabledByConnection')->willReturn([$mapping]);
		$account = new \OCA\Budget\Db\Account();
		$account->setId(100);
		$account->setUserId(self::USER_ID);
		$this->accountMapper->method('find')->willReturn($account);
	}

	public function testSyncImportsPendingTransactionWithPendingStatus(): void {
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$connection->setIncludePending(true);
		$this->setUpPendingSync($connection, [
			['id' => 'tx-p', 'date' => '2026-05-20', 'amount' => '-12.00', 'description' => 'Hold', 'pending' => true],
		]);
		$this->transactionService->method('findByImportId')->willReturn(null);
		$this->transactionService->method('findPendingImported')->willReturn([]);

		$captured = [];
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(1);
		$this->transactionService->method('create')->willReturnCallback(function (...$args) use (&$captured, $tx) {
			$captured = $args;
			return $tx;
		});

		$result = $this->service->sync(self::USER_ID, 1);

		$this->assertContains('pending', $captured, 'Pending tx should be created with status=pending');
		$this->assertEquals(1, $result['imported']);
	}

	public function testSyncReconcilesPendingToPostedSameId(): void {
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$connection->setIncludePending(true);
		// Same provider id, now posted (pending flag absent)
		$this->setUpPendingSync($connection, [
			['id' => 'tx-1', 'date' => '2026-05-21', 'amount' => '-12.00', 'description' => 'Coffee'],
		]);

		$existing = new \OCA\Budget\Db\Transaction();
		$existing->setId(7);
		$existing->setStatus('pending');
		$existing->setImportId('simplefin:tx-1');
		$this->transactionService->method('findByImportId')
			->with(100, 'simplefin:tx-1')->willReturn($existing);
		$this->transactionService->method('findPendingImported')->willReturn([$existing]);

		$this->transactionService->expects($this->once())
			->method('reconcilePendingToPosted')
			->with($existing, null, '2026-05-21');
		$this->transactionService->expects($this->never())->method('create');
		$this->transactionService->expects($this->never())->method('delete');

		$result = $this->service->sync(self::USER_ID, 1);
		$this->assertEquals(0, $result['imported']);
	}

	public function testSyncReconcilesPendingToPostedWhenIdChanged(): void {
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$connection->setIncludePending(true);
		// New provider id; matches the pending hold by amount + nearby date
		$this->setUpPendingSync($connection, [
			['id' => 'NEW', 'date' => '2026-05-19', 'amount' => '-30.00', 'description' => 'Store'],
		]);

		$pending = new \OCA\Budget\Db\Transaction();
		$pending->setId(8);
		$pending->setStatus('pending');
		$pending->setImportId('simplefin:OLD');
		$pending->setType('debit');
		$pending->setAmount(30.0);
		$pending->setDate('2026-05-18');
		$this->transactionService->method('findByImportId')->willReturn(null);
		$this->transactionService->method('findPendingImported')->willReturn([$pending]);

		$this->transactionService->expects($this->once())
			->method('reconcilePendingToPosted')
			->with($pending, 'simplefin:NEW', '2026-05-19');
		$this->transactionService->expects($this->never())->method('create');
		$this->transactionService->expects($this->never())->method('delete');

		$this->service->sync(self::USER_ID, 1);
	}

	public function testSyncCleansUpStalePendingHolds(): void {
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$connection->setIncludePending(true);
		// Feed returns no transactions this time
		$this->setUpPendingSync($connection, []);

		$stale = new \OCA\Budget\Db\Transaction();
		$stale->setId(9);
		$stale->setStatus('pending');
		$stale->setImportId('simplefin:GONE');
		$stale->setType('debit');
		$stale->setAmount(5.0);
		$stale->setDate('2020-01-01'); // well past the staleness cutoff

		$recent = new \OCA\Budget\Db\Transaction();
		$recent->setId(11);
		$recent->setStatus('pending');
		$recent->setImportId('simplefin:RECENT');
		$recent->setType('debit');
		$recent->setAmount(7.0);
		$recent->setDate(date('Y-m-d')); // too recent to clean up

		$this->transactionService->method('findPendingImported')->willReturn([$stale, $recent]);

		// Only the stale hold is removed, and without dismissing it.
		$this->transactionService->expects($this->once())
			->method('delete')
			->with(9, self::USER_ID, false);

		$this->service->sync(self::USER_ID, 1);
	}

	/** A bank-sync hold as findPendingImported() returns it. */
	private function makeHold(int $id, string $providerId, float $amount, string $date, string $description = 'Store', ?int $billId = null): \OCA\Budget\Db\Transaction {
		$hold = new \OCA\Budget\Db\Transaction();
		$hold->setId($id);
		$hold->setAccountId(100);
		$hold->setStatus('pending');
		$hold->setImportId('simplefin:' . $providerId);
		$hold->setType('debit');
		$hold->setAmount($amount);
		$hold->setDate($date);
		$hold->setDescription($description);
		$hold->setBillId($billId);
		$hold->setCreatedAt($date . ' 08:00:00');
		return $hold;
	}

	// ===== which hold a posted row with a new id belongs to =====

	public function testAPostedRowNeverTakesAHoldTheBankStillLists(): void {
		// Spotify posts under a new id, listed before Netflix's hold, which is
		// still pending under its old id. Netflix's hold is closer in date, and
		// taking it cleared Netflix's row under Spotify's id, re-imported the
		// Netflix hold as a new row and later deleted the real Spotify hold.
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$connection->setIncludePending(true);
		$this->setUpPendingSync($connection, [
			['id' => 's2', 'date' => '2026-09-26', 'amount' => '-9.99', 'description' => 'SPOTIFY P1234'],
			['id' => 'n1', 'date' => '2026-09-25', 'amount' => '-9.99', 'description' => 'NETFLIX.COM', 'pending' => true],
		]);

		$spotify = $this->makeHold(3404, 's1', 9.99, '2026-09-22', 'SPOTIFY P1234', 1);
		$netflix = $this->makeHold(3405, 'n1', 9.99, '2026-09-25', 'NETFLIX.COM', 2);
		$this->transactionService->method('findByImportId')->willReturnMap([
			[100, 'simplefin:s2', null],
			[100, 'simplefin:n1', $netflix],
		]);
		$this->transactionService->method('findPendingImported')->willReturn([$spotify, $netflix]);

		$this->transactionService->expects($this->once())
			->method('reconcilePendingToPosted')
			->with($spotify, 'simplefin:s2', '2026-09-26');
		$this->transactionService->expects($this->never())->method('create');

		$this->service->sync(self::USER_ID, 1);
	}

	public function testAPostedRowPrefersTheHoldWithTheSameMerchant(): void {
		// Both holds have dropped off the feed: the matching merchant wins over
		// the closer date.
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$connection->setIncludePending(true);
		$this->setUpPendingSync($connection, [
			['id' => 's2', 'date' => '2026-09-26', 'amount' => '-9.99', 'description' => 'SPOTIFY P1234'],
		]);

		$spotify = $this->makeHold(3404, 's1', 9.99, '2026-09-22', 'SPOTIFY P1234', 1);
		$netflix = $this->makeHold(3405, 'n1', 9.99, '2026-09-25', 'NETFLIX.COM', 2);
		$this->transactionService->method('findByImportId')->willReturn(null);
		$this->transactionService->method('findPendingImported')->willReturn([$spotify, $netflix]);

		$this->transactionService->expects($this->once())
			->method('reconcilePendingToPosted')
			->with($spotify, 'simplefin:s2', '2026-09-26');

		$this->service->sync(self::USER_ID, 1);
	}

	// ===== a hold posting under a new id with changed figures =====

	/**
	 * @return array{0: \OCA\Budget\Db\Transaction[], 1: \OCA\Budget\Db\Transaction[]} reconciled holds, created rows
	 */
	private function syncAgainstHolds(array $feed, array $holds, array $existingByImportId = []): array {
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$connection->setIncludePending(true);
		$this->setUpPendingSync($connection, $feed);
		$this->transactionService->method('findByImportId')->willReturnCallback(
			fn (int $accountId, string $importId) => $existingByImportId[$importId] ?? null
		);
		$this->transactionService->method('findPendingImported')->willReturn($holds);

		$reconciled = [];
		$this->transactionService->method('reconcilePendingToPosted')->willReturnCallback(
			function (\OCA\Budget\Db\Transaction $hold) use (&$reconciled) {
				$reconciled[] = $hold;
				return $hold;
			}
		);
		$created = [];
		$this->transactionService->method('create')->willReturnCallback(function (...$args) use (&$created) {
			$row = new \OCA\Budget\Db\Transaction();
			$row->setId(900 + count($created));
			$row->setStatus($args[10] ?? 'cleared');
			$created[] = $row;
			return $row;
		});

		$this->service->sync(self::USER_ID, 1);

		return [$reconciled, $created];
	}

	public function testAHoldPostingWithADifferentAmountIsReconciled(): void {
		// Hold 50.00 posts two days later as 51.00 under a new id. It used to
		// import as a separate row, leaving the bill tied to the hold, which
		// was then deleted as stale.
		$hold = $this->makeHold(3387, 'h2', 50.0, '2026-09-22', 'ACME ENERGY', 42);
		[$reconciled, $created] = $this->syncAgainstHolds(
			[['id' => 'p2', 'date' => '2026-09-24', 'amount' => '-51.00', 'description' => 'ACME ENERGY DD']],
			[$hold]
		);

		$this->assertSame([$hold], $reconciled);
		$this->assertSame([], $created);
	}

	public function testAHoldPostingMoreThanFiveDaysLaterIsReconciled(): void {
		$hold = $this->makeHold(3387, 'h2', 20.0, '2026-09-20', 'PUREGYM', 7);
		[$reconciled, $created] = $this->syncAgainstHolds(
			[['id' => 'p2', 'date' => '2026-09-26', 'amount' => '-20.00', 'description' => 'PUREGYM LTD']],
			[$hold]
		);

		$this->assertSame([$hold], $reconciled);
		$this->assertSame([], $created);
	}

	public function testAHoldSettlingForAFewPenceMoreIsReconciledWhateverItsText(): void {
		// An FX settlement a few pence off, posted under the merchant's name
		// rather than the authorisation's
		$hold = $this->makeHold(3387, 'h2', 50.0, '2026-09-22', 'CARD AUTH 4471', 42);
		[$reconciled, $created] = $this->syncAgainstHolds(
			[['id' => 'p2', 'date' => '2026-09-23', 'amount' => '-50.03', 'description' => 'ACME LTD']],
			[$hold]
		);

		$this->assertSame([$hold], $reconciled);
		$this->assertSame([], $created);
	}

	public function testAPostedRowUnlikeTheHoldIsImportedAsANewRow(): void {
		$hold = $this->makeHold(3387, 'h2', 50.0, '2026-09-22', 'ACME ENERGY', 42);
		[$reconciled, $created] = $this->syncAgainstHolds(
			[
				// another merchant, a quarter more
				['id' => 'p2', 'date' => '2026-09-24', 'amount' => '-62.50', 'description' => 'TESCO STORES'],
				// the same merchant, but nearly double
				['id' => 'p3', 'date' => '2026-09-24', 'amount' => '-95.00', 'description' => 'ACME ENERGY'],
			],
			[$hold]
		);

		$this->assertSame([], $reconciled);
		$this->assertCount(2, $created);
	}

	public function testAPostedCopyOfAListedBillHoldIsNotMatchedToTheBillAgain(): void {
		// The bank still lists the weekly gym hold as pending while the same
		// payment also shows as posted under a new id. The posted row was
		// matched as the NEXT week's payment: one payment, two weeks paid.
		$hold = $this->makeHold(3390, 'h1', 20.0, '2026-09-22', 'PUREGYM', 7);
		$this->billService->expects($this->never())->method('autoMatchPaidFromImport');

		[$reconciled, $created] = $this->syncAgainstHolds(
			[
				['id' => 'h1', 'date' => '2026-09-22', 'amount' => '-20.00', 'description' => 'PUREGYM', 'pending' => true],
				['id' => 'p1', 'date' => '2026-09-25', 'amount' => '-20.00', 'description' => 'PUREGYM'],
			],
			[$hold],
			['simplefin:h1' => $hold]
		);

		$this->assertSame([], $reconciled, 'a hold the bank still lists is never merged');
		$this->assertCount(1, $created);
	}

	public function testAnUnrelatedPostedRowIsStillMatchedToBillsBesideABillHold(): void {
		$hold = $this->makeHold(3390, 'h1', 20.0, '2026-09-22', 'PUREGYM', 7);
		$this->billService->expects($this->once())
			->method('autoMatchPaidFromImport')
			->with(self::USER_ID, $this->countOf(1));

		$this->syncAgainstHolds(
			[
				['id' => 'h1', 'date' => '2026-09-22', 'amount' => '-20.00', 'description' => 'PUREGYM', 'pending' => true],
				['id' => 'p1', 'date' => '2026-09-25', 'amount' => '-9.99', 'description' => 'NETFLIX.COM'],
			],
			[$hold],
			['simplefin:h1' => $hold]
		);
	}

	public function testAStaleBillHoldHandsOverToItsPostedCopyImportedEarlier(): void {
		// The posted copy came in while the bank still listed the hold, so it
		// was imported as its own row. Once the hold drops off, the hold takes
		// over the copy's import id and figures, keeping its bill link and
		// edits, rather than being deleted with the bill left pointing at it.
		$holdDate = date('Y-m-d', strtotime('-8 days'));
		$postedDate = date('Y-m-d', strtotime('-6 days'));
		$hold = $this->makeHold(3390, 'h1', 20.0, $holdDate, 'PUREGYM', 7);

		$copy = new \OCA\Budget\Db\Transaction();
		$copy->setId(3391);
		$copy->setAccountId(100);
		$copy->setStatus('cleared');
		$copy->setImportId('simplefin:p1');
		$copy->setType('debit');
		$copy->setAmount(20.40);
		$copy->setDate($postedDate);
		$copy->setDescription('PUREGYM LTD');
		$copy->setCreatedAt($postedDate . ' 09:00:00');

		$deleted = [];
		$this->transactionService->method('delete')->willReturnCallback(
			function (int $id) use (&$deleted) {
				$deleted[] = $id;
				return 100;
			}
		);

		[$reconciled] = $this->syncAgainstHolds(
			[['id' => 'p1', 'date' => $postedDate, 'amount' => '-20.40', 'description' => 'PUREGYM LTD']],
			[$hold],
			['simplefin:p1' => $copy]
		);

		$this->assertSame([3391], $deleted, 'the copy goes, the hold stays');
		$this->assertSame([$hold], $reconciled);
	}

	public function testAStaleBillHoldNeverTakesOverARowImportedBeforeIt(): void {
		// Last week's payment is older than the hold: it is not its posted copy
		$holdDate = date('Y-m-d', strtotime('-8 days'));
		$hold = $this->makeHold(3390, 'h1', 20.0, $holdDate, 'PUREGYM', 7);

		$older = new \OCA\Budget\Db\Transaction();
		$older->setId(3300);
		$older->setAccountId(100);
		$older->setStatus('cleared');
		$older->setImportId('simplefin:p0');
		$older->setType('debit');
		$older->setAmount(20.0);
		$older->setDate(date('Y-m-d', strtotime('-11 days')));
		$older->setDescription('PUREGYM');
		$older->setCreatedAt(date('Y-m-d', strtotime('-10 days')) . ' 09:00:00');

		$deleted = [];
		$this->transactionService->method('delete')->willReturnCallback(
			function (int $id) use (&$deleted) {
				$deleted[] = $id;
				return 100;
			}
		);

		[$reconciled] = $this->syncAgainstHolds(
			[['id' => 'p0', 'date' => $older->getDate(), 'amount' => '-20.00', 'description' => 'PUREGYM']],
			[$hold],
			['simplefin:p0' => $older]
		);

		$this->assertSame([], $reconciled);
		$this->assertSame([3390], $deleted);
	}

	// ===== a cancelled hold that paid a bill =====

	public function testACancelledHoldUndoesTheBillPaymentBeforeItIsDeleted(): void {
		// The hold paid the ACME bill, then the bank dropped it. It was
		// deleted with the bill left paid and moved on a month.
		$hold = $this->makeHold(3387, 'h1', 50.0, date('Y-m-d', strtotime('-10 days')), 'ACME ENERGY', 42);

		$calls = [];
		$this->billService->expects($this->once())
			->method('revertCancelledPayment')
			->with(42, 3387)
			->willReturnCallback(function () use (&$calls) {
				$calls[] = 'revert';
				return true;
			});
		$this->transactionService->expects($this->once())
			->method('delete')
			->with(3387, self::USER_ID, false)
			->willReturnCallback(function () use (&$calls) {
				$calls[] = 'delete';
				return 100;
			});

		$this->syncAgainstHolds([], [$hold]);

		$this->assertSame(['revert', 'delete'], $calls);
	}

	public function testACancelledHoldIsStillDeletedWhenItsBillCanNotBeReverted(): void {
		$hold = $this->makeHold(3387, 'h1', 50.0, date('Y-m-d', strtotime('-10 days')), 'ACME ENERGY', 42);
		$this->billService->method('revertCancelledPayment')
			->willThrowException(new \Exception('account no longer writable'));

		$this->transactionService->expects($this->once())->method('delete')->with(3387);

		$this->syncAgainstHolds([], [$hold]);
	}

	public function testACancelledHoldWithoutABillTouchesNoBill(): void {
		$hold = $this->makeHold(3387, 'h1', 50.0, date('Y-m-d', strtotime('-10 days')));
		$this->billService->expects($this->never())->method('revertCancelledPayment');
		$this->transactionService->expects($this->once())->method('delete')->with(3387);

		$this->syncAgainstHolds([], [$hold]);
	}

	public function testAHoldThatTookOverItsPostedCopyLeavesItsBillPaid(): void {
		$holdDate = date('Y-m-d', strtotime('-8 days'));
		$postedDate = date('Y-m-d', strtotime('-6 days'));
		$hold = $this->makeHold(3390, 'h1', 20.0, $holdDate, 'PUREGYM', 7);
		$copy = $this->makeHold(3391, 'p1', 20.0, $postedDate, 'PUREGYM');
		$copy->setStatus('cleared');

		$this->billService->expects($this->never())->method('revertCancelledPayment');

		$this->syncAgainstHolds(
			[['id' => 'p1', 'date' => $postedDate, 'amount' => '-20.00', 'description' => 'PUREGYM']],
			[$hold],
			['simplefin:p1' => $copy]
		);
	}

	// ===== a hold posting takes the bank's final figures =====

	public function testAHoldPostingUnderTheSameIdTakesThePostedAmountAndDescription(): void {
		// Hold for 58.00 "CARD AUTH 4471" posts as 50.00 "ACME ENERGY DD".
		// The row kept 58.00 for good and never reached bill matching, so the
		// ACME bill stayed unpaid and its pre-booked row booked it a second time.
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$connection->setIncludePending(true);
		$this->setUpPendingSync($connection, [
			['id' => 'h4', 'date' => '2026-09-27', 'amount' => '-50.00', 'description' => 'ACME ENERGY DD'],
		]);

		$hold = $this->makeHold(7, 'h4', 58.0, '2026-09-25', 'CARD AUTH 4471');
		$this->transactionService->method('findByImportId')->willReturn($hold);
		$this->transactionService->method('findPendingImported')->willReturn([$hold]);

		$this->transactionService->expects($this->once())
			->method('reconcilePendingToPosted')
			->with($hold, null, '2026-09-27', $this->callback(function (array $posted) {
				return abs($posted['amount'] - 50.0) < 0.001
					&& $posted['type'] === 'debit'
					&& $posted['description'] === 'ACME ENERGY DD';
			}))
			->willReturnArgument(0);
		$this->transactionService->expects($this->once())
			->method('recalculateAccountBalance')
			->with(100, self::USER_ID);
		$this->billService->expects($this->once())
			->method('autoMatchPaidFromImport')
			->with(self::USER_ID, [$hold]);

		$this->service->sync(self::USER_ID, 1);
	}

	public function testAHoldPostingUnderANewIdIsOfferedToBillMatching(): void {
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$connection->setIncludePending(true);
		$this->setUpPendingSync($connection, [
			['id' => 'NEW', 'date' => '2026-05-19', 'amount' => '-30.00', 'description' => 'STORE LTD 0042'],
		]);

		$hold = $this->makeHold(8, 'OLD', 30.0, '2026-05-18', 'PENDING STORE');
		$this->transactionService->method('findByImportId')->willReturn(null);
		$this->transactionService->method('findPendingImported')->willReturn([$hold]);

		$this->transactionService->expects($this->once())
			->method('reconcilePendingToPosted')
			->with($hold, 'simplefin:NEW', '2026-05-19', $this->callback(
				fn (array $posted) => $posted['description'] === 'STORE LTD 0042'
			))
			->willReturnArgument(0);
		$this->billService->expects($this->once())
			->method('autoMatchPaidFromImport')
			->with(self::USER_ID, [$hold]);

		$this->service->sync(self::USER_ID, 1);
	}

	public function testAHoldThatAlreadyPaidABillIsNotMatchedAgainWhenItPosts(): void {
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$connection->setIncludePending(true);
		$this->setUpPendingSync($connection, [
			['id' => 'h4', 'date' => '2026-09-24', 'amount' => '-51.00', 'description' => 'ACME ENERGY'],
		]);

		$hold = $this->makeHold(7, 'h4', 50.0, '2026-09-22', 'ACME ENERGY', 42);
		$this->transactionService->method('findByImportId')->willReturn($hold);
		$this->transactionService->method('findPendingImported')->willReturn([$hold]);
		$this->transactionService->method('reconcilePendingToPosted')->willReturnArgument(0);

		$this->billService->expects($this->never())->method('autoMatchPaidFromImport');

		$this->service->sync(self::USER_ID, 1);
	}

	// ===== Include pending switched off (holds imported while it was on) =====

	public function testHoldsStillReconcileAfterIncludePendingIsTurnedOff(): void {
		// The hold came in while Include pending was on. With it off the feed
		// has no pending rows, and the posted version under a new id used to
		// be inserted beside the hold, counting the payment twice.
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$connection->setIncludePending(false);
		$this->setUpPendingSync($connection, [
			['id' => 'NEW', 'date' => '2026-05-19', 'amount' => '-30.00', 'description' => 'Store'],
		]);

		$hold = $this->makeHold(8, 'OLD', 30.0, '2026-05-18');
		$this->transactionService->method('findByImportId')->willReturn(null);
		$this->transactionService->method('findPendingImported')->willReturn([$hold]);

		$this->transactionService->expects($this->once())
			->method('reconcilePendingToPosted')
			->with($hold, 'simplefin:NEW', '2026-05-19');
		$this->transactionService->expects($this->never())->method('create');

		$this->service->sync(self::USER_ID, 1);
	}

	public function testStaleHoldsAreStillCleanedUpAfterIncludePendingIsTurnedOff(): void {
		// A hold cancelled after the option was switched off stayed pending
		// for good, its amount still taken off the balance.
		$connection = $this->createConnection(1, 'simplefin', 'My Bank', 'active');
		$connection->setIncludePending(false);
		$this->setUpPendingSync($connection, []);

		$stale = $this->makeHold(9, 'GONE', 5.0, '2020-01-01');
		$this->transactionService->method('findPendingImported')->willReturn([$stale]);

		$this->transactionService->expects($this->once())
			->method('delete')
			->with(9, self::USER_ID, false);

		$this->service->sync(self::USER_ID, 1);
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
		bool $enabled = false,
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
