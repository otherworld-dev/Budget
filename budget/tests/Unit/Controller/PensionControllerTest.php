<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\PensionController;
use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Service\PensionProjector;
use OCA\Budget\Service\PensionService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PensionControllerTest extends TestCase {
	private PensionController $controller;
	private PensionService $service;
	private PensionProjector $projector;
	private ValidationService $validationService;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(PensionService::class);
		$this->projector = $this->createMock(PensionProjector::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new PensionController(
			$this->request,
			$this->service,
			$this->projector,
			$this->validationService,
			'user1',
			$this->logger
		);
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsPensions(): void {
		$pensions = [['id' => 1, 'name' => 'Workplace Pension']];
		$this->service->method('findAll')->with('user1')->willReturn($pensions);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testIndexHandlesError(): void {
		$this->service->method('findAll')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsPension(): void {
		$pension = $this->createMock(PensionAccount::class);
		$this->service->method('find')->with(1, 'user1')->willReturn($pension);

		$response = $this->controller->show(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testShowReturnsNotFound(): void {
		$this->service->method('find')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->show(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroyDeletesPension(): void {
		$this->service->expects($this->once())->method('delete')->with(1, 'user1');

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Pension deleted successfully', $response->getData()['message']);
	}

	public function testDestroyHandlesError(): void {
		$this->service->method('delete')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->destroy(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── snapshots ───────────────────────────────────────────────────

	public function testSnapshotsReturnsData(): void {
		$snaps = [['id' => 1, 'balance' => 45000.00]];
		$this->service->method('getSnapshots')->with(1, 'user1')->willReturn($snaps);

		$response = $this->controller->snapshots(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSnapshotsHandlesError(): void {
		$this->service->method('getSnapshots')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->snapshots(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroySnapshot ─────────────────────────────────────────────

	public function testDestroySnapshotDeletesSnapshot(): void {
		$this->service->expects($this->once())->method('deleteSnapshot')->with(1, 'user1');

		$response = $this->controller->destroySnapshot(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── contributions ───────────────────────────────────────────────

	public function testContributionsReturnsData(): void {
		$contributions = [['id' => 1, 'amount' => 500.00]];
		$this->service->method('getContributions')->with(1, 'user1')->willReturn($contributions);

		$response = $this->controller->contributions(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── destroyContribution ─────────────────────────────────────────

	public function testDestroyContributionDeletesContribution(): void {
		$this->service->expects($this->once())->method('deleteContribution')->with(1, 'user1');

		$response = $this->controller->destroyContribution(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── summary ─────────────────────────────────────────────────────

	public function testSummaryReturnsData(): void {
		$summary = ['totalBalance' => 90000.00, 'count' => 2];
		$this->service->method('getSummary')->with('user1')->willReturn($summary);

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── projection ──────────────────────────────────────────────────

	public function testProjectionReturnsData(): void {
		$projection = ['projectedBalance' => 500000.00, 'years' => 30];
		$this->projector->method('getProjection')->with(1, 'user1', 35)->willReturn($projection);

		$response = $this->controller->projection(1, 35);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testProjectionHandlesError(): void {
		$this->projector->method('getProjection')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->projection(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── combinedProjection ──────────────────────────────────────────

	public function testCombinedProjectionReturnsData(): void {
		$projection = ['totalProjected' => 1000000.00];
		$this->projector->method('getCombinedProjection')->with('user1', null)->willReturn($projection);

		$response = $this->controller->combinedProjection();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCombinedProjectionHandlesError(): void {
		$this->projector->method('getCombinedProjection')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->combinedProjection();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
