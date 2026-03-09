<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\AccountController;
use OCA\Budget\Db\Account;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AccountControllerTest extends TestCase {
	private AccountController $controller;
	private AccountService $service;
	private ValidationService $validationService;
	private AuditService $auditService;
	private IRequest $request;
	private LoggerInterface $logger;
	private bool $streamOverridden = false;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(AccountService::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->auditService = $this->createMock(AuditService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Default validation mocks
		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'Checking']);
		$this->validationService->method('validateAccountType')
			->willReturn(['valid' => true, 'formatted' => 'checking']);
		$this->validationService->method('validateCurrency')
			->willReturn(['valid' => true, 'formatted' => 'USD']);
		$this->validationService->method('validateStringLength')
			->willReturn(['valid' => true, 'sanitized' => 'sanitized']);
		$this->validationService->method('validateRoutingNumber')
			->willReturn(['valid' => true, 'formatted' => '021000021']);
		$this->validationService->method('validateSortCode')
			->willReturn(['valid' => true, 'formatted' => '12-34-56']);
		$this->validationService->method('validateIban')
			->willReturn(['valid' => true, 'formatted' => 'GB82WEST12345698765432']);
		$this->validationService->method('validateSwiftBic')
			->willReturn(['valid' => true, 'formatted' => 'DEUTDEFF']);

		$this->controller = new AccountController(
			$this->request,
			$this->service,
			$this->validationService,
			$this->auditService,
			'user1',
			$this->logger
		);
	}

	protected function tearDown(): void {
		if ($this->streamOverridden) {
			stream_wrapper_restore('php');
			$this->streamOverridden = false;
		}
	}

	private function mockInput(string $json): void {
		MockPhpInputStream::$data = $json;
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', MockPhpInputStream::class);
		$this->streamOverridden = true;
	}

	private function makeAccount(array $overrides = []): Account {
		$a = new Account();
		$a->setId($overrides['id'] ?? 1);
		$a->setUserId('user1');
		$a->setName($overrides['name'] ?? 'Checking');
		$a->setType($overrides['type'] ?? 'checking');
		$a->setBalance($overrides['balance'] ?? 1000.00);
		$a->setCurrency($overrides['currency'] ?? 'GBP');
		return $a;
	}

	private function controllerWithValidation(ValidationService $vs): AccountController {
		return new AccountController(
			$this->request,
			$this->service,
			$vs,
			$this->auditService,
			'user1',
			$this->logger
		);
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsAccounts(): void {
		$accounts = [['id' => 1, 'name' => 'Checking'], ['id' => 2, 'name' => 'Savings']];
		$this->service->method('findAllWithCurrentBalances')->with('user1')->willReturn($accounts);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $response->getData());
	}

	public function testIndexHandlesError(): void {
		$this->service->method('findAllWithCurrentBalances')->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Failed to retrieve accounts', $response->getData()['error']);
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsAccount(): void {
		$account = ['id' => 1, 'name' => 'Checking'];
		$this->service->method('findWithCurrentBalance')->with(1, 'user1')->willReturn($account);

		$response = $this->controller->show(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['id']);
	}

	public function testShowReturnsNotFoundOnError(): void {
		$this->service->method('findWithCurrentBalance')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->show(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Account not found', $response->getData()['error']);
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateSuccess(): void {
		$this->mockInput(json_encode([
			'name' => 'Checking',
			'type' => 'checking',
		]));

		$account = $this->makeAccount();
		$this->service->method('create')->willReturn($account);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateLogsAudit(): void {
		$this->mockInput(json_encode([
			'name' => 'Checking',
			'type' => 'checking',
		]));

		$account = $this->makeAccount();
		$this->service->method('create')->willReturn($account);
		$this->auditService->expects($this->once())
			->method('logAccountCreated')
			->with('user1', 1, 'Checking');

		$this->controller->create();
	}

	public function testCreateInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid JSON', $response->getData()['error']);
	}

	public function testCreateEmptyBody(): void {
		$this->mockInput('');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidName(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => false, 'error' => 'Name required']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['name' => '', 'type' => 'checking']));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateEmptyType(): void {
		$this->mockInput(json_encode(['name' => 'Checking', 'type' => '']));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('type is required', $response->getData()['error']);
	}

	public function testCreateMissingType(): void {
		$this->mockInput(json_encode(['name' => 'Checking']));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidAccountType(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'Checking']);
		$vs->method('validateAccountType')->willReturn(['valid' => false, 'error' => 'Bad type']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['name' => 'Checking', 'type' => 'bad']));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid account type', $response->getData()['error']);
	}

	public function testCreateInvalidCurrency(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'Checking']);
		$vs->method('validateAccountType')->willReturn(['valid' => true, 'formatted' => 'checking']);
		$vs->method('validateCurrency')->willReturn(['valid' => false, 'error' => 'Bad currency']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['name' => 'Checking', 'type' => 'checking', 'currency' => 'XXX']));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid currency', $response->getData()['error']);
	}

	public function testCreateInvalidInstitutionLength(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'Checking']);
		$vs->method('validateAccountType')->willReturn(['valid' => true, 'formatted' => 'checking']);
		$vs->method('validateCurrency')->willReturn(['valid' => true, 'formatted' => 'USD']);
		$vs->method('validateStringLength')->willReturn(['valid' => false, 'error' => 'Too long']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode([
			'name' => 'Checking', 'type' => 'checking',
			'institution' => str_repeat('x', 500),
		]));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidRoutingNumber(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'Checking']);
		$vs->method('validateAccountType')->willReturn(['valid' => true, 'formatted' => 'checking']);
		$vs->method('validateCurrency')->willReturn(['valid' => true, 'formatted' => 'USD']);
		$vs->method('validateStringLength')->willReturn(['valid' => true, 'sanitized' => 'x']);
		$vs->method('validateRoutingNumber')->willReturn(['valid' => false, 'error' => 'Bad routing']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode([
			'name' => 'Checking', 'type' => 'checking',
			'routingNumber' => 'bad',
		]));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid routing', $response->getData()['error']);
	}

	public function testCreateInvalidSortCode(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'Checking']);
		$vs->method('validateAccountType')->willReturn(['valid' => true, 'formatted' => 'checking']);
		$vs->method('validateCurrency')->willReturn(['valid' => true, 'formatted' => 'GBP']);
		$vs->method('validateStringLength')->willReturn(['valid' => true, 'sanitized' => 'x']);
		$vs->method('validateSortCode')->willReturn(['valid' => false, 'error' => 'Bad sort code']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode([
			'name' => 'Checking', 'type' => 'checking',
			'sortCode' => 'bad',
		]));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid sort code', $response->getData()['error']);
	}

	public function testCreateInvalidIban(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'Checking']);
		$vs->method('validateAccountType')->willReturn(['valid' => true, 'formatted' => 'checking']);
		$vs->method('validateCurrency')->willReturn(['valid' => true, 'formatted' => 'EUR']);
		$vs->method('validateStringLength')->willReturn(['valid' => true, 'sanitized' => 'x']);
		$vs->method('validateIban')->willReturn(['valid' => false, 'error' => 'Bad IBAN']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode([
			'name' => 'Checking', 'type' => 'checking', 'iban' => 'bad',
		]));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid IBAN', $response->getData()['error']);
	}

	public function testCreateInvalidSwiftBic(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'Checking']);
		$vs->method('validateAccountType')->willReturn(['valid' => true, 'formatted' => 'checking']);
		$vs->method('validateCurrency')->willReturn(['valid' => true, 'formatted' => 'EUR']);
		$vs->method('validateStringLength')->willReturn(['valid' => true, 'sanitized' => 'x']);
		$vs->method('validateSwiftBic')->willReturn(['valid' => false, 'error' => 'Bad SWIFT']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode([
			'name' => 'Checking', 'type' => 'checking', 'swiftBic' => 'bad',
		]));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid SWIFT', $response->getData()['error']);
	}

	public function testCreateWithAllOptionalFields(): void {
		$this->mockInput(json_encode([
			'name' => 'Full Account',
			'type' => 'checking',
			'currency' => 'GBP',
			'balance' => 5000.50,
			'institution' => 'Barclays',
			'accountNumber' => '12345678',
			'routingNumber' => '021000021',
			'sortCode' => '12-34-56',
			'iban' => 'GB82WEST12345698765432',
			'swiftBic' => 'DEUTDEFF',
			'accountHolderName' => 'John Doe',
			'openingDate' => '2025-01-01',
			'interestRate' => 1.5,
			'creditLimit' => 10000.00,
			'overdraftLimit' => 500.00,
			'minimumPayment' => 25.00,
			'walletAddress' => '0xABC123',
		]));

		$account = $this->makeAccount();
		$this->service->method('create')->willReturn($account);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateServiceException(): void {
		$this->mockInput(json_encode(['name' => 'Checking', 'type' => 'checking']));
		$this->service->method('create')->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateSuccess(): void {
		$this->mockInput(json_encode(['name' => 'Updated']));

		$account = $this->makeAccount();
		$this->service->method('update')->willReturn($account);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateLogsAudit(): void {
		$this->mockInput(json_encode(['name' => 'Updated']));

		$account = $this->makeAccount();
		$this->service->method('update')->willReturn($account);
		$this->auditService->expects($this->once())
			->method('logAccountUpdated');

		$this->controller->update(1);
	}

	public function testUpdateInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateEmptyUpdates(): void {
		$this->mockInput(json_encode([]));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid request data', $response->getData()['error']);
	}

	public function testUpdateInvalidName(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => false, 'error' => 'Bad name']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['name' => '']));

		$response = $controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateInvalidType(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateAccountType')->willReturn(['valid' => false, 'error' => 'Bad type']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['type' => 'bad']));

		$response = $controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateInvalidCurrency(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateCurrency')->willReturn(['valid' => false, 'error' => 'Bad currency']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['currency' => 'bad']));

		$response = $controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateStringFieldClearsOnEmpty(): void {
		$this->mockInput(json_encode(['institution' => '']));

		$account = $this->makeAccount();
		$this->service->method('update')->willReturn($account);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateSkipsMaskedRoutingNumber(): void {
		$this->mockInput(json_encode(['routingNumber' => '****0021']));

		// Should not validate (masked value skipped), but also no valid updates
		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('No valid fields', $response->getData()['error']);
	}

	public function testUpdateClearsRoutingNumber(): void {
		$this->mockInput(json_encode(['routingNumber' => '']));

		$account = $this->makeAccount();
		$this->service->method('update')->willReturn($account);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateSkipsMaskedIban(): void {
		$this->mockInput(json_encode(['iban' => 'GB82****5432']));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateClearsIban(): void {
		$this->mockInput(json_encode(['iban' => '']));

		$account = $this->makeAccount();
		$this->service->method('update')->willReturn($account);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateSkipsMaskedSortCode(): void {
		$this->mockInput(json_encode(['sortCode' => '**-**-56']));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateSkipsMaskedSwiftBic(): void {
		$this->mockInput(json_encode(['swiftBic' => 'DEUT****']));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateSkipsDecryptionFailedValues(): void {
		$this->mockInput(json_encode(['routingNumber' => '[DECRYPTION FAILED]']));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateAccountNumberMaskedSkipped(): void {
		$this->mockInput(json_encode(['accountNumber' => '****5678']));

		// Masked account number should be skipped
		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateAccountNumberCleared(): void {
		$this->mockInput(json_encode(['accountNumber' => '']));

		$account = $this->makeAccount();
		$this->service->method('update')->willReturn($account);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateWalletAddressMaskedSkipped(): void {
		$this->mockInput(json_encode(['walletAddress' => '0xAB...23']));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateNumericFields(): void {
		$this->mockInput(json_encode([
			'interestRate' => 2.5,
			'creditLimit' => 5000.00,
			'overdraftLimit' => 250.00,
			'minimumPayment' => 50.00,
			'openingBalance' => 1000.00,
		]));

		$account = $this->makeAccount();
		$this->service->method('update')->willReturn($account);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateOpeningDate(): void {
		$this->mockInput(json_encode(['openingDate' => '2025-06-01']));

		$account = $this->makeAccount();
		$this->service->method('update')->willReturn($account);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateServiceException(): void {
		$this->mockInput(json_encode(['name' => 'Updated']));
		$this->service->method('update')->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroyDeletesAccount(): void {
		$account = $this->makeAccount();
		$this->service->method('find')->with(1, 'user1')->willReturn($account);
		$this->service->expects($this->once())->method('delete')->with(1, 'user1');
		$this->auditService->expects($this->once())->method('logAccountDeleted');

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	public function testDestroyReturnsNotFoundOnError(): void {
		$this->service->method('find')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->destroy(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── reveal ──────────────────────────────────────────────────────

	public function testRevealReturnsFullDataWhenSensitiveDataExists(): void {
		$account = $this->createMock(Account::class);
		$account->method('hasSensitiveData')->willReturn(true);
		$account->method('getPopulatedSensitiveFields')->willReturn(['accountNumber']);
		$account->method('toArrayFull')->willReturn(['id' => 1, 'accountNumber' => '12345678']);
		$this->service->method('find')->with(1, 'user1')->willReturn($account);

		$response = $this->controller->reveal(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('12345678', $response->getData()['accountNumber']);
	}

	public function testRevealReturnsBadRequestWhenNoSensitiveData(): void {
		$account = $this->createMock(Account::class);
		$account->method('hasSensitiveData')->willReturn(false);
		$this->service->method('find')->with(1, 'user1')->willReturn($account);

		$response = $this->controller->reveal(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testRevealLogsAudit(): void {
		$account = $this->createMock(Account::class);
		$account->method('hasSensitiveData')->willReturn(true);
		$account->method('getPopulatedSensitiveFields')->willReturn(['accountNumber']);
		$account->method('toArrayFull')->willReturn(['id' => 1]);
		$this->service->method('find')->willReturn($account);

		$this->auditService->expects($this->once())
			->method('logAccountRevealed')
			->with('user1', 1, ['accountNumber']);

		$this->controller->reveal(1);
	}

	public function testRevealReturnsNotFoundOnError(): void {
		$this->service->method('find')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->reveal(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── summary ─────────────────────────────────────────────────────

	public function testSummaryReturnsSummary(): void {
		$summary = ['total' => 5000.00, 'count' => 3];
		$this->service->method('getSummary')->with('user1')->willReturn($summary);

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(5000.00, $response->getData()['total']);
	}

	public function testSummaryHandlesError(): void {
		$this->service->method('getSummary')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── validation endpoints ────────────────────────────────────────

	public function testValidateIbanReturnsResult(): void {
		$response = $this->controller->validateIban('GB82WEST12345698765432');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['valid']);
	}

	public function testValidateRoutingNumberReturnsResult(): void {
		$response = $this->controller->validateRoutingNumber('021000021');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testValidateSortCodeReturnsResult(): void {
		$response = $this->controller->validateSortCode('12-34-56');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testValidateSwiftBicReturnsResult(): void {
		$response = $this->controller->validateSwiftBic('DEUTDEFF');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── getBankingInstitutions ──────────────────────────────────────

	public function testGetBankingInstitutionsReturnsData(): void {
		$institutions = [['name' => 'Chase', 'routingNumber' => '021000021']];
		$this->validationService->method('getBankingInstitutions')->willReturn($institutions);

		$response = $this->controller->getBankingInstitutions();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	// ── getBankingFieldRequirements ─────────────────────────────────

	public function testGetBankingFieldRequirementsReturnsData(): void {
		$requirements = ['sortCode' => true, 'iban' => false];
		$this->validationService->method('getBankingFieldRequirements')
			->with('GBP')
			->willReturn($requirements);

		$response = $this->controller->getBankingFieldRequirements('GBP');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── getBalanceHistory ───────────────────────────────────────────

	public function testGetBalanceHistoryReturnsData(): void {
		$history = [['date' => '2026-03-01', 'balance' => 1000.00]];
		$this->service->method('getBalanceHistory')->with(1, 'user1', 30)->willReturn($history);

		$response = $this->controller->getBalanceHistory(1, 30);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testGetBalanceHistoryReturnsNotFoundOnError(): void {
		$this->service->method('getBalanceHistory')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->getBalanceHistory(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── reconcile ───────────────────────────────────────────────────

	public function testReconcileReturnsResult(): void {
		$result = ['difference' => 0.00, 'reconciled' => true];
		$this->service->method('reconcile')->with(1, 'user1', 1000.00)->willReturn($result);

		$response = $this->controller->reconcile(1, 1000.00);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['reconciled']);
	}

	public function testReconcileHandlesError(): void {
		$this->service->method('reconcile')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->reconcile(1, 1000.00);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
