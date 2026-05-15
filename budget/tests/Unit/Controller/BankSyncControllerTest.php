<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\BankSyncController;
use OCA\Budget\Db\BankAccountMapping;
use OCA\Budget\Db\BankConnection;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCA\Budget\Service\BankSync\ProviderFactory;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BankSyncControllerTest extends TestCase {
	private BankSyncController $controller;
	private BankSyncService $syncService;
	private AdminSettingService $adminSettings;
	private ProviderFactory $providerFactory;
	private IRequest $request;
	private LoggerInterface $logger;
	private IL10N $l;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->syncService = $this->createMock(BankSyncService::class);
		$this->adminSettings = $this->createMock(AdminSettingService::class);
		$this->providerFactory = $this->createMock(ProviderFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

		$this->controller = new BankSyncController(
			$this->request,
			$this->syncService,
			$this->adminSettings,
			$this->providerFactory,
			$this->l,
			'user1',
			$this->logger
		);
	}

	private function enableBankSync(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
	}

	private function disableBankSync(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(false);
	}

	// ── status ──────────────────────────────────────────────────────

	public function testStatusReturnsEnabledWithConnectionCount(): void {
		$this->enableBankSync();

		$connection = $this->createMock(BankConnection::class);
		$this->syncService->method('getConnections')
			->with('user1')
			->willReturn([['connection' => $connection, 'mappings' => []]]);

		$response = $this->controller->status();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['enabled']);
		$this->assertTrue($data['hasConnections']);
		$this->assertSame(1, $data['connectionCount']);
	}

	public function testStatusReturnsDisabledWithNoConnections(): void {
		$this->disableBankSync();

		$response = $this->controller->status();

		$data = $response->getData();
		$this->assertFalse($data['enabled']);
		$this->assertFalse($data['hasConnections']);
		$this->assertSame(0, $data['connectionCount']);
	}

	// ── providers ───────────────────────────────────────────────────

	public function testProvidersReturns403WhenDisabled(): void {
		$this->disableBankSync();

		$response = $this->controller->providers();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('Bank sync is disabled by the administrator', $response->getData()['error']);
	}

	public function testProvidersReturnsListWhenEnabled(): void {
		$this->enableBankSync();

		$providers = [['name' => 'simplefin', 'label' => 'SimpleFIN']];
		$this->providerFactory->method('getAvailableProviders')
			->willReturn($providers);

		$response = $this->controller->providers();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($providers, $response->getData());
	}

	// ── connections ─────────────────────────────────────────────────

	public function testConnectionsReturns403WhenDisabled(): void {
		$this->disableBankSync();

		$response = $this->controller->connections();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testConnectionsReturnsListWhenEnabled(): void {
		$this->enableBankSync();

		$connections = [['connection' => 'conn1']];
		$this->syncService->method('getConnections')
			->with('user1')
			->willReturn($connections);

		$response = $this->controller->connections();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($connections, $response->getData());
	}

	// ── connect ─────────────────────────────────────────────────────

	public function testConnectReturns403WhenDisabled(): void {
		$this->disableBankSync();

		$response = $this->controller->connect();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testConnectSuccessReturnsResult(): void {
		$this->enableBankSync();

		$this->request->method('getParams')->willReturn([
			'provider' => 'simplefin',
			'name' => 'My Bank',
			'setupToken' => 'abc123',
		]);

		$result = ['id' => 1, 'status' => 'connected'];
		$this->syncService->expects($this->once())
			->method('connect')
			->with('user1', 'simplefin', $this->anything(), 'My Bank')
			->willReturn($result);

		$response = $this->controller->connect();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public function testConnectMissingProviderReturnsBadRequest(): void {
		$this->enableBankSync();

		$this->request->method('getParams')->willReturn([
			'name' => 'My Bank',
		]);

		$response = $this->controller->connect();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Provider and name are required', $response->getData()['error']);
	}

	public function testConnectInvalidProviderReturnsBadRequest(): void {
		$this->enableBankSync();

		$this->request->method('getParams')->willReturn([
			'provider' => 'invalid_provider',
			'name' => 'My Bank',
		]);

		$response = $this->controller->connect();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid provider', $response->getData()['error']);
	}

	// ── disconnect ──────────────────────────────────────────────────

	public function testDisconnectReturns403WhenDisabled(): void {
		$this->disableBankSync();

		$response = $this->controller->disconnect(1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testDisconnectReturnsOkOnSuccess(): void {
		$this->enableBankSync();

		$this->syncService->expects($this->once())
			->method('disconnect')
			->with('user1', 1);

		$response = $this->controller->disconnect(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('ok', $response->getData()['status']);
	}

	// ── sync ────────────────────────────────────────────────────────

	public function testSyncReturns403WhenDisabled(): void {
		$this->disableBankSync();

		$response = $this->controller->sync(1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testSyncReturnsResultOnSuccess(): void {
		$this->enableBankSync();

		$result = ['imported' => 5, 'duplicates' => 2];
		$this->syncService->expects($this->once())
			->method('sync')
			->with('user1', 1)
			->willReturn($result);

		$response = $this->controller->sync(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	// ── mappings ────────────────────────────────────────────────────

	public function testMappingsReturns403WhenDisabled(): void {
		$this->disableBankSync();

		$response = $this->controller->mappings(1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testMappingsReturnsMappingsForFoundConnection(): void {
		$this->enableBankSync();

		$connection = new BankConnection();
		$connection->setId(1);
		$connection->setUserId('user1');
		$connection->setProvider('simplefin');
		$connection->setName('My Bank');
		$connection->setStatus('active');
		$mappings = [['id' => 10, 'externalAccount' => 'checking']];

		$this->syncService->method('getConnections')
			->with('user1')
			->willReturn([['connection' => $connection, 'mappings' => $mappings]]);

		$response = $this->controller->mappings(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($mappings, $response->getData());
	}

	public function testMappingsReturns404ForUnknownConnection(): void {
		$this->enableBankSync();

		$connection = new BankConnection();
		$connection->setId(99);
		$connection->setUserId('user1');
		$connection->setProvider('simplefin');
		$connection->setName('Other Bank');
		$connection->setStatus('active');

		$this->syncService->method('getConnections')
			->with('user1')
			->willReturn([['connection' => $connection, 'mappings' => []]]);

		$response = $this->controller->mappings(1);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Connection not found', $response->getData()['error']);
	}

	// ── updateMapping ───────────────────────────────────────────────

	public function testUpdateMappingReturnsUpdatedMapping(): void {
		$this->enableBankSync();

		$this->request->method('getParams')->willReturn([
			'budgetAccountId' => 5,
			'enabled' => true,
		]);

		$mapping = $this->createMock(BankAccountMapping::class);
		$this->syncService->expects($this->once())
			->method('updateMapping')
			->with('user1', 1, 10, 5, true)
			->willReturn($mapping);

		$response = $this->controller->updateMapping(1, 10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── refreshAccounts ─────────────────────────────────────────────

	public function testRefreshAccountsReturns403WhenDisabled(): void {
		$this->disableBankSync();

		$response = $this->controller->refreshAccounts(1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testRefreshAccountsReturnsMappings(): void {
		$this->enableBankSync();

		$mappings = [['id' => 10, 'externalAccount' => 'savings']];
		$this->syncService->expects($this->once())
			->method('refreshAccounts')
			->with('user1', 1)
			->willReturn($mappings);

		$response = $this->controller->refreshAccounts(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($mappings, $response->getData());
	}

	// ── reauthorize ────────────────────────────────────────────────

	public function testReauthorizeSuccess(): void {
		$this->enableBankSync();
		$this->request->method('getParams')->willReturn([
			'institutionId' => 'BANK_ID',
			'redirectUrl' => 'https://app/callback',
		]);

		$this->syncService->method('reauthorize')->willReturn([
			'authorizationUrl' => 'https://bank.example.com/auth',
		]);

		$response = $this->controller->reauthorize(1);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals('https://bank.example.com/auth', $response->getData()['authorizationUrl']);
	}

	public function testReauthorizeMissingInstitutionId(): void {
		$this->enableBankSync();
		$this->request->method('getParams')->willReturn([]);

		$response = $this->controller->reauthorize(1);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testReauthorizeDisabledReturns403(): void {
		$this->disableBankSync();

		$response = $this->controller->reauthorize(1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}
}
