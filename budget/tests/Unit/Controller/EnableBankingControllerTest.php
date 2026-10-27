<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\EnableBankingController;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BankAccountMappingMapper;
use OCA\Budget\Db\BankConnection;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingKeys;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingKeyStore;
use OCA\Budget\Service\BankSync\EnableBankingProvider;
use OCA\Budget\Service\BankSync\ProviderFactory;
use OCA\Budget\Service\TransactionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The callback runs against the real BankSyncService (mappers and provider
 * mocked), so the state matching, expiry and user binding are exercised as
 * they run in production.
 */
class EnableBankingControllerTest extends TestCase {
	private const USER_ID = 'alice';

	private BankConnectionMapper&MockObject $connectionMapper;
	private BankAccountMappingMapper&MockObject $mappingMapper;
	private EnableBankingProvider&MockObject $provider;
	private AdminSettingService&MockObject $adminSettings;
	private EnableBankingKeyStore&MockObject $keyStore;
	private IRequest&MockObject $request;
	private BankSyncService $syncService;

	protected function setUp(): void {
		$this->connectionMapper = $this->createMock(BankConnectionMapper::class);
		$this->mappingMapper = $this->createMock(BankAccountMappingMapper::class);
		$this->provider = $this->createMock(EnableBankingProvider::class);
		$this->adminSettings = $this->createMock(AdminSettingService::class);
		$this->keyStore = $this->createMock(EnableBankingKeyStore::class);
		$this->request = $this->createMock(IRequest::class);

		$providerFactory = $this->createMock(ProviderFactory::class);
		$providerFactory->method('getProvider')->with('enablebanking')->willReturn($this->provider);

		$this->syncService = new BankSyncService(
			$this->connectionMapper,
			$this->mappingMapper,
			$providerFactory,
			$this->createMock(TransactionService::class),
			$this->createMock(AuditService::class),
			$this->adminSettings,
			$this->createMock(AccountMapper::class),
			$this->createMock(\OCA\Budget\Db\DismissedImportMapper::class),
			$this->createMock(\OCA\Budget\Service\Import\ImportRuleApplicator::class),
			$this->createMock(\OCA\Budget\Service\TransactionTagService::class),
			$this->createMock(\OCA\Budget\Service\BillService::class),
			$this->l10n(),
			$this->createMock(LoggerInterface::class),
		);
	}

	private function controller(?BankSyncService $service = null): EnableBankingController {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->with('budget.enableBanking.callback')
			->willReturn('https://cloud.example/apps/budget/bank-sync/enablebanking/callback');
		$urlGenerator->method('linkToRoute')->willReturnCallback(fn (string $route, array $params = []) => match ($route) {
			'budget.page.index' => '/apps/budget/',
			'budget.enableBanking.callback' => '/apps/budget/bank-sync/enablebanking/callback' . ($params === [] ? '' : '?' . http_build_query($params)),
		});

		return new EnableBankingController(
			$this->request,
			$service ?? $this->syncService,
			$this->keyStore,
			$this->adminSettings,
			$this->l10n(),
			$urlGenerator,
			$this->createMock(LoggerInterface::class),
			self::USER_ID,
		);
	}

	private function l10n(): IL10N&MockObject {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));
		return $l;
	}

	private function enable(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
	}

	private function pendingConnection(string $state, int $stateCreatedAt): BankConnection {
		$conn = new BankConnection();
		$conn->setId(1);
		$conn->setUserId(self::USER_ID);
		$conn->setProvider('enablebanking');
		$conn->setName('EU Bank');
		$conn->setStatus('pending_auth');
		$conn->setCredentials(json_encode([
			'userId' => self::USER_ID,
			'state' => $state,
			'stateCreatedAt' => $stateCreatedAt,
			'sessionId' => null,
			'validUntil' => null,
			'accounts' => [],
		]));
		return $conn;
	}

	/**
	 * Post-Redirect-Get: processing the bank's redirect answers with a
	 * redirect to the same page that carries only the outcome, so a reload
	 * neither re-sends the spent code nor keeps it in the address bar.
	 */
	private function assertRedirect(Response $response, bool $success): void {
		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame(
			'/apps/budget/bank-sync/enablebanking/callback?result=' . ($success ? 'success' : 'failed'),
			$response->getRedirectURL()
		);
	}

	private function assertPage(Response $response, bool $success): void {
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('bank-sync-callback', $response->getTemplateName());
		$params = $response->getParams();
		$this->assertSame($success, $params['success']);
		$this->assertIsString($params['message']);
		$this->assertSame('/apps/budget/#bank-sync', $params['backUrl']);
	}

	// ── callback ────────────────────────────────────────────────────

	public function testCallbackSuccessRendersSuccess(): void {
		$this->enable();
		$this->connectionMapper->method('findAll')->with(self::USER_ID)
			->willReturn([$this->pendingConnection('state-a', time() - 60)]);
		$this->provider->expects($this->once())->method('completeAuthorization')
			->with($this->anything(), 'code-1')
			->willReturn(['credentials' => json_encode(['sessionId' => 's', 'validUntil' => null]), 'accounts' => []]);

		$response = $this->controller()->callback('state-a', 'code-1');

		$this->assertRedirect($response, true);
	}

	public function testCallbackUnknownStateRendersFailureAndCallsNothing(): void {
		$this->enable();
		$this->connectionMapper->method('findAll')->willReturn([$this->pendingConnection('state-a', time() - 60)]);
		$this->provider->expects($this->never())->method('completeAuthorization');
		$this->connectionMapper->expects($this->never())->method('update');

		$response = $this->controller()->callback('state-unknown', 'code-1');

		$this->assertRedirect($response, false);
	}

	public function testCallbackExpiredStateFails(): void {
		$this->enable();
		$this->connectionMapper->method('findAll')->willReturn([$this->pendingConnection('state-a', time() - 31 * 60)]);
		$this->provider->expects($this->never())->method('completeAuthorization');

		$response = $this->controller()->callback('state-a', 'code-1');

		$this->assertRedirect($response, false);
	}

	public function testCallbackStateOfOtherUserFails(): void {
		$this->enable();
		// bob's pending connection holds the state, but only alice's
		// connections are ever looked at
		$this->connectionMapper->expects($this->atLeastOnce())->method('findAll')
			->willReturnCallback(fn (string $userId) => $userId === 'bob'
				? [$this->pendingConnection('state-bob', time() - 60)]
				: []);
		$this->connectionMapper->expects($this->never())->method('find');
		$this->provider->expects($this->never())->method('completeAuthorization');

		$response = $this->controller()->callback('state-bob', 'code-1');

		$this->assertRedirect($response, false);
	}

	public function testCallbackErrorParamCallsFail(): void {
		$this->enable();
		$service = $this->createMock(BankSyncService::class);
		$service->expects($this->once())->method('failEnableBankingAuthorization')
			->with(self::USER_ID, 'state-a', 'access_denied');
		$service->expects($this->never())->method('completeEnableBankingAuthorization');

		$response = $this->controller($service)->callback('state-a', '', 'access_denied');

		$this->assertRedirect($response, false);
	}

	public function testCallbackMissingCodeAndErrorFails(): void {
		$this->enable();
		$service = $this->createMock(BankSyncService::class);
		$service->expects($this->never())->method('completeEnableBankingAuthorization');

		$this->assertRedirect($this->controller($service)->callback('state-a'), false);
		$this->assertPage($this->controller($service)->callback(), false);
	}

	public function testCallbackDisabledRendersFailure(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(false);
		$service = $this->createMock(BankSyncService::class);
		$service->expects($this->never())->method('completeEnableBankingAuthorization');

		$this->assertRedirect($this->controller($service)->callback('state-a', 'code-1'), false);
	}

	public function testCallbackProviderFailureRendersGenericFailure(): void {
		$this->enable();
		$this->connectionMapper->method('findAll')->willReturn([$this->pendingConnection('state-a', time() - 60)]);
		$this->provider->method('completeAuthorization')
			->willThrowException(new \RuntimeException('POST /sessions code-1 failed'));

		$response = $this->controller()->callback('state-a', 'code-1');

		$this->assertRedirect($response, false);
		$this->assertStringNotContainsString('code-1', $response->getRedirectURL());
	}

	public function testResultSuccessRendersTheSuccessPage(): void {
		$service = $this->createMock(BankSyncService::class);
		$service->expects($this->never())->method('completeEnableBankingAuthorization');

		$response = $this->controller($service)->callback('', '', '', 'success');

		$this->assertPage($response, true);
		$this->assertStringContainsString('Your bank is connected', $response->getParams()['message']);
	}

	public function testResultFailedOrUnknownRendersTheFailurePage(): void {
		$this->assertPage($this->controller()->callback('', '', '', 'failed'), false);
		$this->assertPage($this->controller()->callback('', '', '', 'whatever'), false);
	}

	// ── settings ────────────────────────────────────────────────────

	public function testSettingsNeverReturnsPrivateKey(): void {
		$this->enable();
		$this->keyStore->method('get')->willReturn(new EnableBankingKeys('app-1', 'PRIVATE-PEM'));
		$this->keyStore->method('describe')->with(self::USER_ID)
			->willReturn(['hasKeys' => true, 'applicationId' => 'app-1']);

		$response = $this->controller()->settings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'hasKeys' => true,
			'applicationId' => 'app-1',
			'callbackUrl' => 'https://cloud.example/apps/budget/bank-sync/enablebanking/callback',
		], $response->getData());
		$this->assertStringNotContainsString('PRIVATE-PEM', json_encode($response->getData()));
	}

	public function testSettingsForbiddenWhenBankSyncDisabled(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(false);
		$this->keyStore->expects($this->never())->method('save');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->settings()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->saveSettings('app-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->deleteSettings()->getStatus());
	}

	public function testSaveSettingsStoresKeysAndReturnsSummary(): void {
		$this->enable();
		$this->request->method('getParam')->with('privateKey', '')->willReturn('PRIVATE-PEM');
		$this->keyStore->expects($this->once())->method('save')->with(
			self::USER_ID,
			$this->callback(fn (EnableBankingKeys $k) => $k->applicationId() === 'app-1' && $k->privateKey() === 'PRIVATE-PEM')
		);
		$this->keyStore->method('describe')->willReturn(['hasKeys' => true, 'applicationId' => 'app-1']);

		$response = $this->controller()->saveSettings('app-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(true, $response->getData()['hasKeys']);
		$this->assertStringNotContainsString('PRIVATE-PEM', json_encode($response->getData()));
	}

	public function testSaveSettingsRejectsInvalidKey(): void {
		$this->enable();
		$this->keyStore->method('save')->willThrowException(new \InvalidArgumentException('Invalid private key'));

		$this->request->method('getParam')->with('privateKey', '')->willReturn('not a key');

		$response = $this->controller()->saveSettings('app-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid private key', $response->getData()['error']);
	}

	public function testDeleteSettingsBlockedWhileConnectionsExist(): void {
		$this->enable();
		$conn = $this->pendingConnection('s', time());
		$conn->setStatus('active');
		$this->connectionMapper->method('findAll')->with(self::USER_ID)->willReturn([$conn]);
		$this->keyStore->expects($this->never())->method('delete');

		$response = $this->controller()->deleteSettings();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testDeleteSettingsDeletesWithoutEnableBankingConnections(): void {
		$this->enable();
		$other = $this->pendingConnection('s', time());
		$other->setProvider('simplefin');
		$this->connectionMapper->method('findAll')->willReturn([$other]);
		$this->keyStore->expects($this->once())->method('delete')->with(self::USER_ID);

		$response = $this->controller()->deleteSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}
}
