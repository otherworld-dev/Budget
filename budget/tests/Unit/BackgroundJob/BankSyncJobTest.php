<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\BankSyncJob;
use OCA\Budget\BackgroundJob\Queued\BankSyncConnectionJob;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Service\AdminSettingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * BankSyncJob only fans out: one BankSyncConnectionJob per connection. The
 * sync itself is covered by BankSyncConnectionJobTest.
 */
class BankSyncJobTest extends TestCase {
	private BankSyncJob $job;
	private AdminSettingService $adminSettings;
	private BankConnectionMapper $connectionMapper;
	private IJobList $jobList;
	private LoggerInterface $logger;
	/** @var array<int, array{string, array}> */
	private array $added = [];
	/** @var array<int, true> connection ids whose job is still queued */
	private array $pending = [];

	protected function setUp(): void {
		$this->adminSettings = $this->createMock(AdminSettingService::class);
		$this->connectionMapper = $this->createMock(BankConnectionMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('has')
			->willReturnCallback(fn (string $class, $argument) => isset($this->pending[$argument['connectionId']]));
		$this->jobList->method('add')
			->willReturnCallback(function (string $class, $argument) {
				$this->added[] = [$class, $argument];
			});

		$this->job = new BankSyncJob(
			$this->createMock(ITimeFactory::class),
			$this->adminSettings,
			$this->connectionMapper,
			$this->jobList,
			$this->logger
		);
	}

	// ===== Constructor Config =====

	public function testIntervalIsTwentyFourHours(): void {
		$reflection = new \ReflectionProperty($this->job, 'interval');
		$this->assertEquals(24 * 60 * 60, $reflection->getValue($this->job));
	}

	public function testIsNotTimeSensitive(): void {
		$reflection = new \ReflectionProperty($this->job, 'timeSensitivity');
		$this->assertEquals(IJob::TIME_INSENSITIVE, $reflection->getValue($this->job));
	}

	// ===== run() =====

	public function testRunReturnsEarlyWhenBankSyncDisabled(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(false);

		$this->connectionMapper->expects($this->never())->method('findActiveIdsForSync');
		$this->jobList->expects($this->never())->method('add');

		$this->invokeRun();
	}

	public function testRunCompletesWithNoConnections(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
		$this->connectionMapper->method('findActiveIdsForSync')->willReturn([]);

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('queued 0 connections'),
				$this->callback(fn ($ctx) => $ctx['app'] === 'budget')
			);

		$this->invokeRun();

		$this->assertSame([], $this->added);
	}

	/**
	 * Each connection becomes its own queued job, so one slow provider holds
	 * up only itself instead of every sync after it in the same cron slot.
	 */
	public function testRunQueuesOneJobPerConnection(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
		$this->connectionMapper->method('findActiveIdsForSync')->willReturn([
			['id' => 1, 'userId' => 'user1'],
			['id' => 2, 'userId' => 'user2'],
		]);

		$this->invokeRun();

		$this->assertSame([
			[BankSyncConnectionJob::class, ['userId' => 'user1', 'connectionId' => 1]],
			[BankSyncConnectionJob::class, ['userId' => 'user2', 'connectionId' => 2]],
		], $this->added);
	}

	/**
	 * A connection whose job is still waiting keeps its place: re-adding it
	 * would move it to the back of the queue every day.
	 */
	public function testRunLeavesAStillQueuedConnectionWhereItIs(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
		$this->connectionMapper->method('findActiveIdsForSync')->willReturn([
			['id' => 1, 'userId' => 'user1'],
			['id' => 2, 'userId' => 'user2'],
		]);
		$this->pending = [1 => true];

		$this->invokeRun();

		$this->assertSame([[BankSyncConnectionJob::class, ['userId' => 'user2', 'connectionId' => 2]]], $this->added);
	}

	public function testRunLogsWhenTheConnectionListFails(): void {
		$this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
		$this->connectionMapper->method('findActiveIdsForSync')->willThrowException(new \RuntimeException('db down'));

		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains('db down'), $this->anything());

		$this->invokeRun();
	}

	private function invokeRun(): void {
		$method = new \ReflectionMethod($this->job, 'run');
		$method->invoke($this->job, null);
	}
}
