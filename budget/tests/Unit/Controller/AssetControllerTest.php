<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\AssetController;
use OCA\Budget\Db\Asset;
use OCA\Budget\Db\AssetSnapshot;
use OCA\Budget\Service\AssetProjector;
use OCA\Budget\Service\AssetService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AssetControllerTest extends TestCase {
	private AssetController $controller;
	private AssetService $service;
	private AssetProjector $projector;
	private ValidationService $validationService;
	private IRequest $request;
	private LoggerInterface $logger;
	private bool $streamOverridden = false;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(AssetService::class);
		$this->projector = $this->createMock(AssetProjector::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Default validation mocks
		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'My Asset']);
		$this->validationService->method('validateDescription')
			->willReturn(['valid' => true, 'sanitized' => 'desc']);
		$this->validationService->method('validateDate')
			->willReturn(['valid' => true]);

		$this->controller = new AssetController(
			$this->request,
			$this->service,
			$this->projector,
			$this->validationService,
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

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsAssets(): void {
		$assets = [['id' => 1, 'name' => 'House']];
		$this->service->method('findAll')->with('user1')->willReturn($assets);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($assets, $response->getData());
	}

	public function testIndexHandlesError(): void {
		$this->service->method('findAll')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsAsset(): void {
		$asset = $this->createMock(Asset::class);
		$this->service->method('find')->with(1, 'user1')->willReturn($asset);

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

	public function testCreateSuccess(): void {
		$this->mockInput(json_encode(['name' => 'House', 'type' => 'real_estate']));

		$asset = $this->createMock(Asset::class);
		$this->service->method('create')->willReturn($asset);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateMissingName(): void {
		$this->mockInput(json_encode(['type' => 'real_estate']));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('required', $response->getData()['error']);
	}

	public function testCreateMissingType(): void {
		$this->mockInput(json_encode(['name' => 'House']));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidName(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => false, 'error' => 'Name required']);

		$controller = new AssetController(
			$this->request, $this->service, $this->projector, $vs, 'user1', $this->logger
		);

		$this->mockInput(json_encode(['name' => '', 'type' => 'real_estate']));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidType(): void {
		$this->mockInput(json_encode(['name' => 'House', 'type' => 'invalid']));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid asset type', $response->getData()['error']);
	}

	public function testCreateInvalidDescription(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'House']);
		$vs->method('validateDescription')->willReturn(['valid' => false, 'error' => 'Too long']);

		$controller = new AssetController(
			$this->request, $this->service, $this->projector, $vs, 'user1', $this->logger
		);

		$this->mockInput(json_encode([
			'name' => 'House', 'type' => 'real_estate', 'description' => 'x',
		]));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidCurrency(): void {
		$this->mockInput(json_encode([
			'name' => 'House', 'type' => 'real_estate', 'currency' => 'ABCD',
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('3-letter', $response->getData()['error']);
	}

	public function testCreateNegativeCurrentValue(): void {
		$this->mockInput(json_encode([
			'name' => 'House', 'type' => 'real_estate', 'currentValue' => -1,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateNegativePurchasePrice(): void {
		$this->mockInput(json_encode([
			'name' => 'House', 'type' => 'real_estate', 'purchasePrice' => -1,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidAnnualChangeRate(): void {
		$this->mockInput(json_encode([
			'name' => 'House', 'type' => 'real_estate', 'annualChangeRate' => 1.5,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('-1 and 1', $response->getData()['error']);
	}

	public function testCreateInvalidPurchaseDate(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'House']);
		$vs->method('validateDate')->willReturn(['valid' => false, 'error' => 'Bad date']);

		$controller = new AssetController(
			$this->request, $this->service, $this->projector, $vs, 'user1', $this->logger
		);

		$this->mockInput(json_encode([
			'name' => 'House', 'type' => 'real_estate', 'purchaseDate' => 'bad',
		]));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateWithAllFields(): void {
		$this->mockInput(json_encode([
			'name' => 'House',
			'type' => 'real_estate',
			'description' => 'Primary residence',
			'currency' => 'GBP',
			'currentValue' => 350000.00,
			'purchasePrice' => 280000.00,
			'purchaseDate' => '2020-06-15',
			'annualChangeRate' => 0.03,
		]));

		$asset = $this->createMock(Asset::class);
		$this->service->method('create')->willReturn($asset);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateServiceException(): void {
		$this->mockInput(json_encode(['name' => 'House', 'type' => 'real_estate']));
		$this->service->method('create')->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateSuccess(): void {
		$this->mockInput(json_encode(['name' => 'Updated']));

		$asset = $this->createMock(Asset::class);
		$this->service->method('update')->willReturn($asset);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateInvalidType(): void {
		$this->mockInput(json_encode(['type' => 'invalid']));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateInvalidCurrency(): void {
		$this->mockInput(json_encode(['currency' => 'ABCD']));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateNegativeValue(): void {
		$this->mockInput(json_encode(['currentValue' => -1]));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateInvalidAnnualChangeRate(): void {
		$this->mockInput(json_encode(['annualChangeRate' => -1.5]));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateServiceException(): void {
		$this->mockInput(json_encode(['name' => 'Updated']));
		$this->service->method('update')->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroyDeletesAsset(): void {
		$this->service->expects($this->once())->method('delete')->with(1, 'user1');

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Asset deleted successfully', $response->getData()['message']);
	}

	public function testDestroyHandlesError(): void {
		$this->service->method('delete')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->destroy(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── snapshots ───────────────────────────────────────────────────

	public function testSnapshotsReturnsData(): void {
		$snapshots = [['id' => 1, 'value' => 250000.00]];
		$this->service->method('getSnapshots')->with(1, 'user1')->willReturn($snapshots);

		$response = $this->controller->snapshots(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSnapshotsHandlesError(): void {
		$this->service->method('getSnapshots')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->snapshots(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── createSnapshot ──────────────────────────────────────────────

	public function testCreateSnapshotSuccess(): void {
		$this->mockInput(json_encode(['value' => 300000.00, 'date' => '2026-03-01']));

		$snapshot = new AssetSnapshot();
		$snapshot->setId(1);
		$this->service->method('createSnapshot')->willReturn($snapshot);

		$response = $this->controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateSnapshotInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateSnapshotMissingValue(): void {
		$this->mockInput(json_encode(['date' => '2026-03-01']));

		$response = $this->controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateSnapshotMissingDate(): void {
		$this->mockInput(json_encode(['value' => 300000]));

		$response = $this->controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateSnapshotNegativeValue(): void {
		$this->mockInput(json_encode(['value' => -1, 'date' => '2026-03-01']));

		$response = $this->controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('negative', $response->getData()['error']);
	}

	public function testCreateSnapshotInvalidDate(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'x']);
		$vs->method('validateDate')->willReturn(['valid' => false, 'error' => 'Bad date']);

		$controller = new AssetController(
			$this->request, $this->service, $this->projector, $vs, 'user1', $this->logger
		);

		$this->mockInput(json_encode(['value' => 300000, 'date' => 'bad']));

		$response = $controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateSnapshotServiceException(): void {
		$this->mockInput(json_encode(['value' => 300000, 'date' => '2026-03-01']));
		$this->service->method('createSnapshot')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->createSnapshot(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroySnapshot ─────────────────────────────────────────────

	public function testDestroySnapshotDeletesSnapshot(): void {
		$this->service->expects($this->once())->method('deleteSnapshot')->with(1, 'user1');

		$response = $this->controller->destroySnapshot(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDestroySnapshotHandlesError(): void {
		$this->service->method('deleteSnapshot')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->destroySnapshot(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── summary ─────────────────────────────────────────────────────

	public function testSummaryReturnsData(): void {
		$summary = ['totalValue' => 500000.00, 'count' => 3];
		$this->service->method('getSummary')->with('user1')->willReturn($summary);

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($summary, $response->getData());
	}

	public function testSummaryHandlesError(): void {
		$this->service->method('getSummary')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── projection ──────────────────────────────────────────────────

	public function testProjectionReturnsData(): void {
		$projection = ['projectedValue' => 750000.00, 'years' => 10];
		$this->projector->method('getProjection')
			->with(1, 'user1', 10)
			->willReturn($projection);

		$response = $this->controller->projection(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($projection, $response->getData());
	}

	public function testProjectionWithCustomYears(): void {
		$projection = ['projectedValue' => 900000.00, 'years' => 20];
		$this->projector->method('getProjection')
			->with(1, 'user1', 20)
			->willReturn($projection);

		$response = $this->controller->projection(1, 20);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testProjectionHandlesError(): void {
		$this->projector->method('getProjection')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->projection(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── combinedProjection ──────────────────────────────────────────

	public function testCombinedProjectionReturnsData(): void {
		$projection = ['totalProjected' => 2000000.00];
		$this->projector->method('getCombinedProjection')
			->with('user1', 10)
			->willReturn($projection);

		$response = $this->controller->combinedProjection();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($projection, $response->getData());
	}

	public function testCombinedProjectionHandlesError(): void {
		$this->projector->method('getCombinedProjection')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->combinedProjection();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── null userId ─────────────────────────────────────────────────

	public function testNullUserIdThrowsOnIndex(): void {
		$controller = new AssetController(
			$this->request, $this->service, $this->projector,
			$this->validationService, null, $this->logger
		);

		$response = $controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
