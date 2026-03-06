<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\NetWorthController;
use OCA\Budget\Db\NetWorthSnapshot;
use OCA\Budget\Service\NetWorthService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NetWorthControllerTest extends TestCase {
	private NetWorthService $service;
	private LoggerInterface $logger;
	private IRequest $request;

	private function makeController(?string $userId = 'user1'): NetWorthController {
		return new NetWorthController(
			$this->request,
			$this->service,
			$userId,
			$this->logger
		);
	}

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(NetWorthService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	// ── current ─────────────────────────────────────────────────────

	public function testCurrentReturnsNetWorth(): void {
		$data = ['netWorth' => 50000, 'assets' => 60000, 'liabilities' => 10000];
		$this->service->method('calculateNetWorth')->with('user1')->willReturn($data);

		$controller = $this->makeController();
		$response = $controller->current();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($data, $response->getData());
	}

	public function testCurrentHandlesException(): void {
		$this->service->method('calculateNetWorth')
			->willThrowException(new \RuntimeException('error'));

		$controller = $this->makeController();
		$response = $controller->current();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCurrentWithNullUserThrowsError(): void {
		$controller = $this->makeController(null);
		$response = $controller->current();

		// getUserId() throws RuntimeException, caught by handleError
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── snapshots ───────────────────────────────────────────────────

	public function testSnapshotsReturnsData(): void {
		$snapshots = [['date' => '2025-01-01', 'netWorth' => 48000]];
		$this->service->method('getSnapshots')
			->with('user1', 30)
			->willReturn($snapshots);

		$controller = $this->makeController();
		$response = $controller->snapshots();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($snapshots, $response->getData());
	}

	public function testSnapshotsWithCustomDays(): void {
		$this->service->expects($this->once())
			->method('getSnapshots')
			->with('user1', 90)
			->willReturn([]);

		$controller = $this->makeController();
		$response = $controller->snapshots(90);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSnapshotsClampsMinDays(): void {
		$this->service->expects($this->once())
			->method('getSnapshots')
			->with('user1', 1)
			->willReturn([]);

		$controller = $this->makeController();
		$response = $controller->snapshots(-5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSnapshotsClampsMaxDays(): void {
		$this->service->expects($this->once())
			->method('getSnapshots')
			->with('user1', 3650)
			->willReturn([]);

		$controller = $this->makeController();
		$response = $controller->snapshots(99999);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSnapshotsHandlesException(): void {
		$this->service->method('getSnapshots')
			->willThrowException(new \RuntimeException('error'));

		$controller = $this->makeController();
		$response = $controller->snapshots();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── createSnapshot ──────────────────────────────────────────────

	public function testCreateSnapshotReturns201(): void {
		$snapshot = new NetWorthSnapshot();
		$snapshot->setId(1);
		$this->service->method('createSnapshot')
			->with('user1', NetWorthSnapshot::SOURCE_MANUAL)
			->willReturn($snapshot);

		$controller = $this->makeController();
		$response = $controller->createSnapshot();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateSnapshotHandlesException(): void {
		$this->service->method('createSnapshot')
			->willThrowException(new \RuntimeException('error'));

		$controller = $this->makeController();
		$response = $controller->createSnapshot();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroySnapshot ─────────────────────────────────────────────

	public function testDestroySnapshotSuccess(): void {
		$this->service->expects($this->once())
			->method('deleteSnapshot')
			->with(5, 'user1');

		$controller = $this->makeController();
		$response = $controller->destroySnapshot(5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertStringContainsString('deleted', strtolower($response->getData()['message']));
	}

	public function testDestroySnapshotNotFound(): void {
		$this->service->method('deleteSnapshot')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));

		$controller = $this->makeController();
		$response = $controller->destroySnapshot(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
