<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ImportRuleController;
use OCA\Budget\Db\ImportRule;
use OCA\Budget\Service\ImportRuleService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportRuleControllerTest extends TestCase {
	private ImportRuleController $controller;
	private ImportRuleService $service;
	private ValidationService $validationService;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ImportRuleService::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ImportRuleController(
			$this->request,
			$this->service,
			$this->validationService,
			'user1',
			$this->logger
		);
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsRules(): void {
		$rules = [['id' => 1, 'name' => 'Groceries']];
		$this->service->method('findAll')->with('user1')->willReturn($rules);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($rules, $response->getData());
	}

	public function testIndexHandlesError(): void {
		$this->service->method('findAll')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsRule(): void {
		$rule = $this->createMock(ImportRule::class);
		$this->service->method('find')->with(1, 'user1')->willReturn($rule);

		$response = $this->controller->show(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testShowReturnsNotFound(): void {
		$this->service->method('find')
			->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->show(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateV1RuleSuccess(): void {
		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'Test Rule']);
		$this->validationService->method('validatePattern')
			->willReturn(['valid' => true, 'sanitized' => 'grocery']);

		$rule = $this->createMock(ImportRule::class);
		$this->service->method('create')->willReturn($rule);

		$response = $this->controller->create(
			'Test Rule',
			'grocery',
			'description',
			'contains'
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateV1RequiresPatternFieldMatchType(): void {
		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'Test Rule']);

		$response = $this->controller->create('Test Rule');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('required', $response->getData()['error']);
	}

	public function testCreateV2RequiresCriteria(): void {
		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'Test Rule']);

		$response = $this->controller->create('Test Rule', schemaVersion: 2);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Criteria required', $response->getData()['error']);
	}

	public function testCreateRejectsInvalidField(): void {
		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'Test Rule']);
		$this->validationService->method('validatePattern')
			->willReturn(['valid' => true, 'sanitized' => 'test']);

		$response = $this->controller->create(
			'Test Rule',
			'test',
			'invalid_field',
			'contains'
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid field', $response->getData()['error']);
	}

	public function testCreateRejectsInvalidMatchType(): void {
		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'Test Rule']);
		$this->validationService->method('validatePattern')
			->willReturn(['valid' => true, 'sanitized' => 'test']);

		$response = $this->controller->create(
			'Test Rule',
			'test',
			'description',
			'invalid_type'
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid match type', $response->getData()['error']);
	}

	public function testCreateRejectsInvalidName(): void {
		$this->validationService->method('validateName')
			->willReturn(['valid' => false, 'error' => 'Name is required']);

		$response = $this->controller->create('');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateHandlesServiceError(): void {
		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'Test Rule']);
		$this->validationService->method('validatePattern')
			->willReturn(['valid' => true, 'sanitized' => 'test']);
		$this->service->method('create')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->create(
			'Test Rule',
			'test',
			'description',
			'contains'
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateReturnsUpdatedRule(): void {
		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'Updated']);

		$rule = $this->createMock(ImportRule::class);
		$this->service->method('update')->willReturn($rule);

		$response = $this->controller->update(1, name: 'Updated');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateRejectsEmptyUpdates(): void {
		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('No valid fields', $response->getData()['error']);
	}

	public function testUpdateRejectsInvalidField(): void {
		$response = $this->controller->update(1, field: 'invalid_field');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid field', $response->getData()['error']);
	}

	public function testUpdateHandlesError(): void {
		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'Test']);
		$this->service->method('update')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->update(1, name: 'Test');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroyDeletesRule(): void {
		$this->service->expects($this->once())->method('delete')->with(1, 'user1');

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	public function testDestroyHandlesError(): void {
		$this->service->method('delete')
			->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->destroy(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── test ────────────────────────────────────────────────────────

	public function testTestReturnsResults(): void {
		$results = ['matched' => true, 'categoryId' => 5];
		$this->service->method('testRules')
			->with('user1', ['description' => 'test'])
			->willReturn($results);

		$response = $this->controller->test(['description' => 'test']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($results, $response->getData());
	}

	public function testTestHandlesError(): void {
		$this->service->method('testRules')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->test([]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── preview ─────────────────────────────────────────────────────

	public function testPreviewReturnsResults(): void {
		$results = ['matches' => 10];
		$this->service->method('previewRuleApplication')->willReturn($results);

		$response = $this->controller->preview([1, 2]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($results, $response->getData());
	}

	public function testPreviewHandlesError(): void {
		$this->service->method('previewRuleApplication')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->preview([]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── testUnsaved ─────────────────────────────────────────────────

	public function testTestUnsavedReturnsResults(): void {
		$criteria = ['field' => 'description', 'operator' => 'contains', 'value' => 'test'];
		$results = ['matches' => 5];
		$this->service->method('testUnsavedRule')->willReturn($results);

		$response = $this->controller->testUnsaved($criteria);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($results, $response->getData());
	}

	// ── apply ───────────────────────────────────────────────────────

	public function testApplyReturnsResults(): void {
		$results = ['applied' => 15];
		$this->service->method('applyRulesToTransactions')->willReturn($results);

		$response = $this->controller->apply([1, 2]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($results, $response->getData());
	}

	public function testApplyHandlesError(): void {
		$this->service->method('applyRulesToTransactions')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->apply([]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── migrate ─────────────────────────────────────────────────────

	public function testMigrateReturnsRule(): void {
		$rule = $this->createMock(ImportRule::class);
		$this->service->method('migrateLegacyRule')->with(1, 'user1')->willReturn($rule);

		$response = $this->controller->migrate(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testMigrateHandlesError(): void {
		$this->service->method('migrateLegacyRule')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->migrate(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── migrateAll ──────────────────────────────────────────────────

	public function testMigrateAllReturnsResults(): void {
		$migrated = [['id' => 1], ['id' => 2]];
		$this->service->method('migrateAllLegacyRules')
			->with('user1')
			->willReturn($migrated);

		$response = $this->controller->migrateAll();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2, $response->getData()['count']);
	}

	public function testMigrateAllHandlesError(): void {
		$this->service->method('migrateAllLegacyRules')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->migrateAll();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── validateCriteria ────────────────────────────────────────────

	public function testValidateCriteriaReturnsValid(): void {
		$response = $this->controller->validateCriteria(['field' => 'description']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['valid']);
	}
}
