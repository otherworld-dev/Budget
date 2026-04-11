<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\SetupController;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Service\ImportRuleService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class SetupControllerTest extends TestCase {
	private SetupController $controller;
	private CategoryService $categoryService;
	private ImportRuleService $importRuleService;
	private FactoryResetService $factoryResetService;
	private AuditService $auditService;
	private AccountService $accountService;
	private IRequest $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->categoryService = $this->createMock(CategoryService::class);
		$this->importRuleService = $this->createMock(ImportRuleService::class);
		$this->factoryResetService = $this->createMock(FactoryResetService::class);
		$this->auditService = $this->createMock(AuditService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

		$this->controller = new SetupController(
			$this->request,
			$this->categoryService,
			$this->importRuleService,
			$this->factoryResetService,
			$this->auditService,
			$this->accountService,
			$l,
			'user1'
		);
	}

	// ── initialize ──────────────────────────────────────────────────

	public function testInitializeCreatesDefaults(): void {
		$this->categoryService->method('createDefaultCategories')
			->with('user1')
			->willReturn([['id' => 1], ['id' => 2], ['id' => 3]]);
		$this->importRuleService->method('createDefaultRules')
			->with('user1')
			->willReturn([['id' => 1], ['id' => 2]]);

		$response = $this->controller->initialize();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(3, $data['categoriesCreated']);
		$this->assertSame(2, $data['rulesCreated']);
		$this->assertSame('Budget app initialized successfully', $data['message']);
	}

	public function testInitializeHandlesError(): void {
		$this->categoryService->method('createDefaultCategories')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->initialize();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── status ──────────────────────────────────────────────────────

	public function testStatusReturnsInitializedState(): void {
		$this->categoryService->method('findAll')
			->with('user1')
			->willReturn([['id' => 1], ['id' => 2]]);
		$this->importRuleService->method('findAll')
			->with('user1')
			->willReturn([['id' => 1]]);

		$response = $this->controller->status();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['initialized']);
		$this->assertSame(2, $data['categoriesCount']);
		$this->assertSame(1, $data['rulesCount']);
	}

	public function testStatusReturnsUninitializedState(): void {
		$this->categoryService->method('findAll')->willReturn([]);
		$this->importRuleService->method('findAll')->willReturn([]);

		$response = $this->controller->status();

		$data = $response->getData();
		$this->assertFalse($data['initialized']);
		$this->assertSame(0, $data['categoriesCount']);
	}

	public function testStatusHandlesError(): void {
		$this->categoryService->method('findAll')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->status();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── removeDuplicateCategories ───────────────────────────────────

	public function testRemoveDuplicateCategoriesReturnsCount(): void {
		$this->categoryService->method('removeDuplicates')
			->with('user1')
			->willReturn([['id' => 5], ['id' => 8]]);

		$response = $this->controller->removeDuplicateCategories();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(2, $data['count']);
		$this->assertStringContainsString('2 duplicate', $data['message']);
	}

	public function testRemoveDuplicateCategoriesHandlesError(): void {
		$this->categoryService->method('removeDuplicates')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->removeDuplicateCategories();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── resetCategories ─────────────────────────────────────────────

	public function testResetCategoriesDeletesAndRecreates(): void {
		$this->categoryService->method('deleteAll')
			->with('user1')
			->willReturn(10);
		$this->categoryService->method('createDefaultCategories')
			->with('user1')
			->willReturn([['id' => 1], ['id' => 2], ['id' => 3]]);

		$response = $this->controller->resetCategories();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(10, $data['deleted']);
		$this->assertSame(3, $data['created']);
	}

	public function testResetCategoriesHandlesError(): void {
		$this->categoryService->method('deleteAll')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->resetCategories();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── factoryReset ────────────────────────────────────────────────

	public function testFactoryResetRequiresConfirmation(): void {
		$this->request->method('getParam')->with('confirmed', false)->willReturn(false);

		$response = $this->controller->factoryReset();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('confirmed=true', $response->getData()['error']);
	}

	public function testFactoryResetExecutesWhenConfirmed(): void {
		$this->request->method('getParam')->with('confirmed', false)->willReturn(true);
		$counts = ['accounts' => 3, 'transactions' => 100];
		$this->factoryResetService->method('executeFactoryReset')
			->with('user1')
			->willReturn($counts);

		$response = $this->controller->factoryReset();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame($counts, $data['deletedCounts']);
	}

	public function testFactoryResetHandlesError(): void {
		$this->request->method('getParam')->with('confirmed', false)->willReturn(true);
		$this->factoryResetService->method('executeFactoryReset')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->factoryReset();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	// ── recalculateBalances ─────────────────────────────────────────

	public function testRecalculateBalancesReturnsResults(): void {
		$results = ['updated' => 3, 'total' => 5];
		$this->accountService->method('recalculateAllBalances')
			->with('user1')
			->willReturn($results);

		$response = $this->controller->recalculateBalances();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($results, $response->getData());
	}

	public function testRecalculateBalancesHandlesError(): void {
		$this->accountService->method('recalculateAllBalances')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->recalculateBalances();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}
}
