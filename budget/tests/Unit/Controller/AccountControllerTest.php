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

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(AccountService::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->auditService = $this->createMock(AuditService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new AccountController(
			$this->request,
			$this->service,
			$this->validationService,
			$this->auditService,
			'user1',
			$this->logger
		);
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

	public function testRevealReturnsNotFoundOnError(): void {
		$this->service->method('find')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->reveal(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── validateIban ────────────────────────────────────────────────

	public function testValidateIbanReturnsResult(): void {
		$this->validationService->method('validateIban')
			->with('GB82WEST12345698765432')
			->willReturn(['valid' => true, 'formatted' => 'GB82WEST12345698765432']);

		$response = $this->controller->validateIban('GB82WEST12345698765432');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['valid']);
	}

	// ── validateRoutingNumber ───────────────────────────────────────

	public function testValidateRoutingNumberReturnsResult(): void {
		$this->validationService->method('validateRoutingNumber')
			->with('021000021')
			->willReturn(['valid' => true, 'formatted' => '021000021']);

		$response = $this->controller->validateRoutingNumber('021000021');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['valid']);
	}

	// ── validateSortCode ────────────────────────────────────────────

	public function testValidateSortCodeReturnsResult(): void {
		$this->validationService->method('validateSortCode')
			->with('12-34-56')
			->willReturn(['valid' => true, 'formatted' => '12-34-56']);

		$response = $this->controller->validateSortCode('12-34-56');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── validateSwiftBic ────────────────────────────────────────────

	public function testValidateSwiftBicReturnsResult(): void {
		$this->validationService->method('validateSwiftBic')
			->with('DEUTDEFF')
			->willReturn(['valid' => true, 'formatted' => 'DEUTDEFF']);

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
