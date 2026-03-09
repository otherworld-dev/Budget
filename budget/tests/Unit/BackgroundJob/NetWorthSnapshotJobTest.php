<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\NetWorthSnapshotJob;
use OCA\Budget\Db\NetWorthSnapshot;
use OCA\Budget\Service\NetWorthService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class NetWorthSnapshotJobTest extends TestCase {
	private NetWorthSnapshotJob $job;
	private ITimeFactory $timeFactory;
	private NetWorthService $netWorthService;
	private IDBConnection $db;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->netWorthService = $this->createMock(NetWorthService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[NetWorthService::class, $this->netWorthService],
			[IDBConnection::class, $this->db],
			[LoggerInterface::class, $this->logger],
		]);
		\OC::$server = $container;

		$this->job = new NetWorthSnapshotJob($this->timeFactory);
	}

	protected function tearDown(): void {
		\OC::$server = null;
	}

	public function testIntervalIsOncePerDay(): void {
		$reflection = new \ReflectionProperty($this->job, 'interval');
		$this->assertEquals(24 * 60 * 60, $reflection->getValue($this->job));
	}

	public function testIsNotTimeSensitive(): void {
		$reflection = new \ReflectionProperty($this->job, 'timeSensitivity');
		$this->assertEquals(IJob::TIME_INSENSITIVE, $reflection->getValue($this->job));
	}

	public function testRunCreatesSnapshotsForAllUsers(): void {
		$this->mockGetAllUserIds(['user1', 'user2']);

		$snapshot = new NetWorthSnapshot();
		$this->netWorthService->expects($this->exactly(2))
			->method('createSnapshot')
			->willReturnCallback(function (string $userId, string $source) use ($snapshot) {
				$this->assertContains($userId, ['user1', 'user2']);
				$this->assertEquals(NetWorthSnapshot::SOURCE_AUTO, $source);
				return $snapshot;
			});

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('2 snapshots created'),
				$this->equalTo(['app' => 'budget'])
			);

		$this->invokeRun();
	}

	public function testRunHandlesNoUsers(): void {
		$this->mockGetAllUserIds([]);

		$this->netWorthService->expects($this->never())->method('createSnapshot');

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('0 snapshots created'),
				$this->equalTo(['app' => 'budget'])
			);

		$this->invokeRun();
	}

	public function testRunContinuesOnPerUserFailure(): void {
		$this->mockGetAllUserIds(['user1', 'user2']);

		$snapshot = new NetWorthSnapshot();
		$callCount = 0;
		$this->netWorthService->method('createSnapshot')
			->willReturnCallback(function () use (&$callCount, $snapshot) {
				$callCount++;
				if ($callCount === 1) {
					throw new \RuntimeException('User error');
				}
				return $snapshot;
			});

		$this->logger->expects($this->once())->method('warning');
		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('1 snapshots created'),
				$this->equalTo(['app' => 'budget'])
			);

		$this->invokeRun();
	}

	public function testRunLogsErrorOnTotalFailure(): void {
		$this->db->method('getQueryBuilder')
			->willThrowException(new \RuntimeException('DB down'));

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('DB down'),
				$this->callback(fn($ctx) => $ctx['app'] === 'budget')
			);

		$this->invokeRun();
	}

	private function mockGetAllUserIds(array $userIds): void {
		$rows = array_map(fn($id) => ['user_id' => $id], $userIds);
		$currentIndex = 0;

		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturnCallback(function () use (&$currentIndex, $rows) {
			return $rows[$currentIndex++] ?? false;
		});
		$result->method('closeCursor');

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('selectDistinct')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	private function invokeRun(): void {
		$method = new \ReflectionMethod($this->job, 'run');
		$method->invoke($this->job, null);
	}
}
