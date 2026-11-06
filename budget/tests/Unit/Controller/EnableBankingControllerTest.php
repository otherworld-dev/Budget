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
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The key settings. The bank's redirect is BankSyncCallbackController's.
 */
class EnableBankingControllerTest extends TestCase {
	private const USER_ID = 'alice';

	private BankConnectionMapper&MockObject $connectionMapper;
	private BankAccountMappingMapper&MockObject $mappingMapper;
	private EnableBankingProvider&MockObject $provider;
	private AdminSettingService&MockObject $adminSettings;
	private EnableBankingKeyStore&MockObject $keyStore;
	private IRequest&MockObject $request;
	private LoggerInterface&MockObject $logger;
	private BankSyncService $syncService;

	protected function setUp(): void {
		$this->connectionMapper = $this->createMock(BankConnectionMapper::class);
		$this->mappingMapper = $this->createMock(BankAccountMappingMapper::class);
		$this->provider = $this->createMock(EnableBankingProvider::class);
		$this->adminSettings = $this->createMock(AdminSettingService::class);
		$this->keyStore = $this->createMock(EnableBankingKeyStore::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$providerFactory = $this->createMock(ProviderFactory::class);
		$other = $this->createMock(\OCA\Budget\Service\BankSync\BankSyncProviderInterface::class);
		$providerFactory->method('getProvider')->willReturnCallback(
			fn (string $name) => $name === 'enablebanking' ? $this->provider : $other
		);

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
			$this->logger,
		);
	}

	private function controller(?BankSyncService $service = null): EnableBankingController {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->with('budget.bankSyncCallback.callback')
			->willReturn('https://cloud.example/apps/budget/bank-sync/callback');

		return new EnableBankingController(
			$this->request,
			$service ?? $this->syncService,
			$this->keyStore,
			$this->adminSettings,
			$this->l10n(),
			$urlGenerator,
			$this->logger,
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

	// ── callback ────────────────────────────────────────────────────

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
			'callbackUrl' => 'https://cloud.example/apps/budget/bank-sync/callback',
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
