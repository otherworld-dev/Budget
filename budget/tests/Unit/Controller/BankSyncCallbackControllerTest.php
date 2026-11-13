<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\BankSyncCallbackController;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BankAccountMappingMapper;
use OCA\Budget\Db\BankConnection;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCA\Budget\Service\BankSync\EnableBanking\EnableBankingKeyStore;
use OCA\Budget\Service\BankSync\EnableBankingProvider;
use OCA\Budget\Service\BankSync\ProviderFactory;
use OCA\Budget\Service\TransactionService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The callback runs against the real BankSyncService (mappers and provider
 * mocked), so the state matching, expiry and user binding are exercised as
 * they run in production.
 */
class BankSyncCallbackControllerTest extends TestCase {
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

	private function controller(?BankSyncService $service = null): BankSyncCallbackController {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(fn (string $route, array $params = []) => match ($route) {
			'budget.page.index' => '/apps/budget/',
			'budget.bankSyncCallback.callback' => '/apps/budget/bank-sync/callback' . ($params === [] ? '' : '?' . http_build_query($params)),
		});

		return new BankSyncCallbackController(
			$this->request,
			$service ?? $this->syncService,
			$this->adminSettings,
			$this->l10n(),
			$urlGenerator,
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
			'/apps/budget/bank-sync/callback?result=' . ($success ? 'success' : 'failed'),
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

	public function testCallbackWithoutAccountsStillRendersSuccessButExpires(): void {
		$this->enable();
		$conn = $this->pendingConnection('state-a', time() - 60);
		$this->connectionMapper->method('findAll')->with(self::USER_ID)->willReturn([$conn]);
		$this->provider->method('completeAuthorization')
			->willReturn(['credentials' => json_encode(['sessionId' => 's', 'validUntil' => null]), 'accounts' => []]);

		$response = $this->controller()->callback('state-a', 'code-1');

		$this->assertRedirect($response, true);
		$this->assertSame('expired', $conn->getStatus());
		$this->assertStringContainsString('did not share any accounts', $conn->getLastError());
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
		$service->expects($this->once())->method('failCallbackAuthorization')
			->with(self::USER_ID, 'state-a', 'access_denied');
		$service->expects($this->never())->method('completeCallbackAuthorization');

		$response = $this->controller($service)->callback('state-a', '', 'access_denied');

		$this->assertRedirect($response, false);
	}

	public function testCallbackMissingCodeAndErrorFails(): void {
		$this->enable();
		$service = $this->createMock(BankSyncService::class);
		$service->expects($this->never())->method('completeCallbackAuthorization');

		$this->assertRedirect($this->controller($service)->callback('state-a'), false);
		$this->assertPage($this->controller($service)->callback(), false);
	}

	public function testCallbackDisabledRendersFailure(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(false);
		$service = $this->createMock(BankSyncService::class);
		$service->expects($this->never())->method('completeCallbackAuthorization');

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

	/**
	 * Nextcloud core appends the request URL, with `code` and `state` in its
	 * query string, to every log entry: nothing may be logged while the
	 * bank's redirect is handled. A failure is kept on the connection
	 * (lastError, cleared state) instead.
	 */
	#[DataProvider('failedCallbackProvider')]
	public function testFailedCallbackWritesNoLog(string $case): void {
		foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $method) {
			$this->logger->expects($this->never())->method($method);
		}

		switch ($case) {
			case 'unknown state':
				$this->enable();
				$this->connectionMapper->method('findAll')->willReturn([$this->pendingConnection('state-a', time() - 60)]);
				$response = $this->controller()->callback('state-unknown', 'code-1');
				break;
			case 'expired state':
				$this->enable();
				$this->connectionMapper->method('findAll')->willReturn([$this->pendingConnection('state-a', time() - 31 * 60)]);
				$response = $this->controller()->callback('state-a', 'code-1');
				break;
			case 'error param':
				$this->enable();
				$this->connectionMapper->method('findAll')->willReturn([$this->pendingConnection('state-a', time() - 60)]);
				$response = $this->controller()->callback('state-a', '', 'access_denied');
				break;
			case 'recording the error fails':
				$this->enable();
				$service = $this->createMock(BankSyncService::class);
				$service->method('failCallbackAuthorization')->willThrowException(new \RuntimeException('db down'));
				$response = $this->controller($service)->callback('state-a', '', 'access_denied');
				break;
			case 'completing throws a TypeError':
				$this->enable();
				$service = $this->createMock(BankSyncService::class);
				$service->method('completeCallbackAuthorization')->willThrowException(new \TypeError('bad code-1'));
				$response = $this->controller($service)->callback('state-a', 'code-1');
				break;
			case 'recording the error throws a TypeError':
				$this->enable();
				$service = $this->createMock(BankSyncService::class);
				$service->method('failCallbackAuthorization')->willThrowException(new \TypeError('bad state-a'));
				$response = $this->controller($service)->callback('state-a', '', 'access_denied');
				break;
			case 'missing params':
				$this->enable();
				$response = $this->controller()->callback('state-a');
				break;
			case 'provider throws on POST /sessions':
				$this->enable();
				$this->connectionMapper->method('findAll')->willReturn([$this->pendingConnection('state-a', time() - 60)]);
				$this->provider->method('completeAuthorization')
					->willThrowException(new \RuntimeException('POST /sessions code-1 failed'));
				$response = $this->controller()->callback('state-a', 'code-1');
				break;
			case 'bank sync disabled':
				$this->adminSettings->method('isBankSyncEnabled')->willReturn(false);
				$response = $this->controller()->callback('state-a', 'code-1');
				break;
			default:
				$this->fail('Unknown case ' . $case);
		}

		$this->assertRedirect($response, false);
	}

	public static function failedCallbackProvider(): array {
		return [
			'unknown state' => ['unknown state'],
			'expired state' => ['expired state'],
			'error param' => ['error param'],
			'recording the error fails' => ['recording the error fails'],
			'completing throws a TypeError' => ['completing throws a TypeError'],
			'recording the error throws a TypeError' => ['recording the error throws a TypeError'],
			'missing params' => ['missing params'],
			'provider throws on POST /sessions' => ['provider throws on POST /sessions'],
			'bank sync disabled' => ['bank sync disabled'],
		];
	}

	public function testResultSuccessRendersTheSuccessPage(): void {
		$service = $this->createMock(BankSyncService::class);
		$service->expects($this->never())->method('completeCallbackAuthorization');

		$response = $this->controller($service)->callback('', '', '', 'success');

		$this->assertPage($response, true);
		$this->assertStringContainsString('Your bank is connected', $response->getParams()['message']);
	}

	public function testResultFailedOrUnknownRendersTheFailurePage(): void {
		$this->assertPage($this->controller()->callback('', '', '', 'failed'), false);
		$this->assertPage($this->controller()->callback('', '', '', 'whatever'), false);
	}

	// ── settings ────────────────────────────────────────────────────
}
