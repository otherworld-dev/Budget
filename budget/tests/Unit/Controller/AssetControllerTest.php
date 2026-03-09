<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\AssetController;
use OCA\Budget\Db\Asset;
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

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(AssetService::class);
		$this->projector = $this->createMock(AssetProjector::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new AssetController(
			$this->request,
			$this->service,
			$this->projector,
			$this->validationService,
			'user1',
			$this->logger
		);
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
		$this->assertSame($asset, $response->getData());
	}

	public function testShowReturnsNotFound(): void {
		$this->service->method('find')
			->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->show(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
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
		$this->assertSame($snapshots, $response->getData());
	}

	public function testSnapshotsHandlesError(): void {
		$this->service->method('getSnapshots')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->snapshots(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroySnapshot ─────────────────────────────────────────────

	public function testDestroySnapshotDeletesSnapshot(): void {
		$this->service->expects($this->once())->method('deleteSnapshot')->with(1, 'user1');

		$response = $this->controller->destroySnapshot(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Snapshot deleted successfully', $response->getData()['message']);
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
}
