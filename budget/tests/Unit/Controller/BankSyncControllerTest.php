<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\BankSyncController;
use OCA\Budget\Db\BankAccountMapping;
use OCA\Budget\Db\BankConnection;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingAuthException;
use OCA\Budget\Service\BankSync\EnableBankingProvider;
use OCA\Budget\Service\BankSync\GoCardlessProvider;
use OCA\Budget\Service\BankSync\ProviderFactory;
use OCA\Budget\Service\BankSync\SimpleFINProvider;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
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
	private IURLGenerator $urlGenerator;

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

		$this->urlGenerator = $this->createMock(IURLGenerator::class);

		$this->controller = new BankSyncController(
			$this->request,
			$this->syncService,
			$this->adminSettings,
			$this->providerFactory,
			$this->l,
			$this->urlGenerator,
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

	public function testMappingsIncludeBalanceDifference(): void {
		$this->enableBankSync();

		$connection = new BankConnection();
		$connection->setId(1);
		$connection->setUserId('user1');
		$connection->setProvider('simplefin');
		$connection->setName('My Bank');
		$connection->setStatus('active');
		$matched = new BankAccountMapping();
		$matched->setId(10);
		$matched->setExternalAccountId('checking');
		$matched->setLastBalance('1000.00');
		$unmapped = new BankAccountMapping();
		$unmapped->setId(11);
		$unmapped->setExternalAccountId('savings');

		$this->syncService->method('getConnections')
			->with('user1')
			->willReturn([['connection' => $connection, 'mappings' => [$matched, $unmapped]]]);
		$this->syncService->method('getBalanceDifference')
			->willReturnMap([[$matched, 'user1', '12.35'], [$unmapped, 'user1', null]]);

		$response = $this->controller->mappings(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(2, $data);
		$this->assertSame(10, $data[0]['id']);
		$this->assertSame('checking', $data[0]['externalAccountId']);
		$this->assertSame('1000.00', $data[0]['lastBalance']);
		$this->assertSame('12.35', $data[0]['balanceDifference']);
		$this->assertArrayHasKey('balanceDifference', $data[1]);
		$this->assertNull($data[1]['balanceDifference']);
	}

	/** One account that can't be compared must not hide every mapping. */
	public function testMappingsSurviveADifferenceThatFails(): void {
		$this->enableBankSync();
		$connection = new BankConnection();
		$connection->setId(1);
		$broken = new BankAccountMapping();
		$broken->setId(10);
		$fine = new BankAccountMapping();
		$fine->setId(11);
		$this->syncService->method('getConnections')
			->willReturn([['connection' => $connection, 'mappings' => [$broken, $fine]]]);
		$this->syncService->method('getBalanceDifference')->willReturnCallback(
			fn (BankAccountMapping $m) => $m === $broken ? throw new \RuntimeException('db error 1000.00') : '5.00'
		);
		$this->logger->expects($this->once())->method('warning')
			->with('Bank sync: could not compute a balance difference', $this->anything());

		$response = $this->controller->mappings(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($response->getData()[0]['balanceDifference']);
		$this->assertSame('5.00', $response->getData()[1]['balanceDifference']);
	}

	public function testReconcileMappingReturnsTheMatchedBalance(): void {
		$this->enableBankSync();
		$this->syncService->expects($this->once())->method('reconcileMapping')
			->with('user1', 1, 10)
			->willReturn(['balance' => '1000.00', 'balanceDifference' => '0.00']);

		$response = $this->controller->reconcileMapping(1, 10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['balance' => '1000.00', 'balanceDifference' => '0.00'], $response->getData());
	}

	public function testReconcileMappingReturns403WhenDisabled(): void {
		$this->disableBankSync();
		$this->syncService->expects($this->never())->method('reconcileMapping');

		$response = $this->controller->reconcileMapping(1, 10);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testReconcileMappingFailureReturnsAGenericError(): void {
		$this->enableBankSync();
		$this->syncService->method('reconcileMapping')
			->willThrowException(new \Exception('Mapping does not belong to this connection'));

		$response = $this->controller->reconcileMapping(1, 10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Failed to reconcile the balance', $response->getData()['error']);
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
			->with('user1', 1, 10, 5, false, true)
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

	// ── institutions ──────────────────────────────────────────────

	public function testInstitutionsReturnsListForGoCardless(): void {
		$this->enableBankSync();
		$this->request->method('getParams')->willReturn([
			'country' => 'GB',
			'secretId' => 'sid',
			'secretKey' => 'skey',
		]);

		$gcProvider = $this->createMock(GoCardlessProvider::class);
		$this->providerFactory->method('getProvider')->with('gocardless')->willReturn($gcProvider);
		$gcProvider->method('listInstitutions')->willReturn([
			['id' => 'BANK_1', 'name' => 'Test Bank', 'logo' => null],
		]);

		$response = $this->controller->institutions('gocardless');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
		$this->assertEquals('BANK_1', $response->getData()[0]['id']);
	}

	public function testInstitutionsForEnableBankingUsesKeyStore(): void {
		$this->enableBankSync();
		// A client-supplied secretId must not reach the provider: keys come from the key store
		$this->request->method('getParams')->willReturn(['country' => 'de', 'secretId' => 'ignored']);

		$eb = $this->createMock(EnableBankingProvider::class);
		$this->providerFactory->method('getProvider')->with('enablebanking')->willReturn($eb);
		$eb->expects($this->once())->method('listInstitutions')
			->with('user1', $this->callback(fn ($p) => $p['country'] === 'DE'))
			->willReturn([['id' => 'Nordea', 'name' => 'Nordea', 'logo' => null, 'country' => 'DE', 'maximumConsentValidity' => 7776000]]);

		$response = $this->controller->institutions('enablebanking');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals('Nordea', $response->getData()[0]['id']);
	}

	public function testInstitutionsExplainsAnInactiveEnableBankingApplication(): void {
		$this->enableBankSync();
		$this->request->method('getParams')->willReturn(['country' => 'DE']);

		$eb = $this->createMock(EnableBankingProvider::class);
		$this->providerFactory->method('getProvider')->with('enablebanking')->willReturn($eb);
		$eb->method('listInstitutions')->willThrowException(
			new EnableBankingAuthException('Enable Banking rejected the credentials (HTTP 403)', EnableBankingAuthException::REASON_APPLICATION_INACTIVE)
		);

		$response = $this->controller->institutions('enablebanking');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'Your Enable Banking application is not active yet. Link at least one of your accounts in the Enable Banking control panel, then try again.'],
			$response->getData()
		);
	}

	public function testInstitutionsRejectsProviderWithoutListing(): void {
		$this->enableBankSync();
		$this->request->method('getParams')->willReturn(['country' => 'GB']);
		$this->providerFactory->method('getProvider')->with('simplefin')->willReturn($this->createMock(SimpleFINProvider::class));

		$response = $this->controller->institutions('simplefin');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testConnectEnableBankingUsesCallbackUrlNotClientUrl(): void {
		$this->enableBankSync();
		$this->request->method('getParams')->willReturn([
			'provider' => 'enablebanking',
			'name' => 'My Bank',
			'institutionId' => 'Nordea',
			'country' => 'FI',
			'maximumConsentValidity' => 7776000,
			'redirectUrl' => 'https://evil.example/steal',
		]);
		$this->urlGenerator->method('linkToRouteAbsolute')->with('budget.enableBanking.callback')->willReturn('https://nc.example/apps/budget/enable-banking/callback');

		$this->syncService->expects($this->once())->method('connect')
			->with('user1', 'enablebanking', [
				'userId' => 'user1',
				'institutionId' => 'Nordea',
				'country' => 'FI',
				'redirectUrl' => 'https://nc.example/apps/budget/enable-banking/callback',
				'maximumConsentValidity' => 7776000,
			], 'My Bank')
			->willReturn(['authorizationUrl' => 'https://auth.example/start']);

		$response = $this->controller->connect();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testReauthorizeEnableBankingPassesParamsAndCallbackUrl(): void {
		$this->enableBankSync();
		$this->request->method('getParams')->willReturn([
			'institutionId' => 'Nordea',
			'country' => 'FI',
			'maximumConsentValidity' => 7776000,
			'redirectUrl' => 'https://evil.example/steal',
		]);
		$connection = new BankConnection();
		$connection->setProvider('enablebanking');
		$this->syncService->method('getConnection')->with('user1', 1)->willReturn($connection);
		$this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.example/cb');

		$this->syncService->expects($this->once())->method('reauthorize')
			->with('user1', 1, [
				'institutionId' => 'Nordea',
				'country' => 'FI',
				'redirectUrl' => 'https://nc.example/cb',
				'maximumConsentValidity' => 7776000,
			])
			->willReturn(['authorizationUrl' => 'https://auth.example/start']);

		$response = $this->controller->reauthorize(1);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	private function goCardlessReauthorizeWith(string $redirectUrl): void {
		$this->enableBankSync();
		$this->request->method('getParams')->willReturn(['institutionId' => 'BANK_ID', 'redirectUrl' => $redirectUrl]);
		$this->request->method('getServerProtocol')->willReturn('https');
		$this->request->method('getServerHost')->willReturn('nc.example');
		$connection = new BankConnection();
		$connection->setProvider('gocardless');
		$this->syncService->method('getConnection')->with('user1', 1)->willReturn($connection);
	}

	/** The bank must never send the user (and the requisition) to another host. */
	public function testReauthorizeGoCardlessDropsForeignRedirectUrl(): void {
		$this->goCardlessReauthorizeWith('https://evil.example/steal');
		$this->syncService->expects($this->once())->method('reauthorize')
			->with('user1', 1, ['institutionId' => 'BANK_ID', 'redirectUrl' => ''])
			->willReturn(['authorizationUrl' => 'https://bank.example/auth']);

		$this->assertSame(Http::STATUS_OK, $this->controller->reauthorize(1)->getStatus());
	}

	public function testReauthorizeGoCardlessKeepsSameHostRedirectUrl(): void {
		$this->goCardlessReauthorizeWith('https://nc.example/apps/budget/#bank-sync');
		$this->syncService->expects($this->once())->method('reauthorize')
			->with('user1', 1, ['institutionId' => 'BANK_ID', 'redirectUrl' => 'https://nc.example/apps/budget/#bank-sync'])
			->willReturn(['authorizationUrl' => 'https://bank.example/auth']);

		$this->assertSame(Http::STATUS_OK, $this->controller->reauthorize(1)->getStatus());
	}

	public function testInstitutionsDisabledReturns403(): void {
		$this->disableBankSync();
		$response = $this->controller->institutions('gocardless');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testInstitutionsMissingCredentialsReturns400(): void {
		$this->enableBankSync();
		$this->request->method('getParams')->willReturn(['country' => 'GB']);

		$gcProvider = $this->createMock(GoCardlessProvider::class);
		$this->providerFactory->method('getProvider')->with('gocardless')->willReturn($gcProvider);

		$response = $this->controller->institutions('gocardless');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testInstitutionsRejectsNonGoCardlessProvider(): void {
		$this->enableBankSync();

		$sfProvider = $this->createMock(SimpleFINProvider::class);
		$this->providerFactory->method('getProvider')->with('simplefin')->willReturn($sfProvider);

		$response = $this->controller->institutions('simplefin');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── updateConnection ────────────────────────────────────────────

	public function testUpdateConnectionSetsIncludePending(): void {
		$this->enableBankSync();
		$this->request->method('getParams')->willReturn(['includePending' => true]);

		$connection = new BankConnection();
		$connection->setId(5);
		$connection->setProvider('simplefin');
		$this->syncService->method('getConnection')->with('user1', 5)->willReturn($connection);

		$this->syncService->expects($this->once())
			->method('updateConnectionEntity')
			->with($this->callback(fn (BankConnection $c) => $c->getIncludePending() === true));

		$response = $this->controller->updateConnection(5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($connection->getIncludePending());
	}
}
