<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\CleanupAuditLogsJob;
use OCA\Budget\Db\AuditLogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class CleanupAuditLogsJobTest extends TestCase {
	private CleanupAuditLogsJob $job;
	private ITimeFactory $timeFactory;
	private AuditLogMapper $auditLogMapper;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[AuditLogMapper::class, $this->auditLogMapper],
			[LoggerInterface::class, $this->logger],
		]);
		\OC::$server = $container;

		$this->job = new CleanupAuditLogsJob($this->timeFactory);
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

	public function testRunDeletesOldLogs(): void {
		$this->auditLogMapper->expects($this->once())
			->method('deleteOldLogs')
			->with(90)
			->willReturn(5);

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('5 records deleted'),
				$this->equalTo(['app' => 'budget'])
			);

		$this->invokeRun();
	}

	public function testRunDoesNotLogWhenNothingDeleted(): void {
		$this->auditLogMapper->expects($this->once())
			->method('deleteOldLogs')
			->with(90)
			->willReturn(0);

		$this->logger->expects($this->never())->method('info');

		$this->invokeRun();
	}

	public function testRunLogsErrorOnFailure(): void {
		$this->auditLogMapper->method('deleteOldLogs')
			->willThrowException(new \RuntimeException('DB error'));

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('DB error'),
				$this->callback(fn($ctx) => $ctx['app'] === 'budget')
			);

		$this->invokeRun();
	}

	private function invokeRun(): void {
		$method = new \ReflectionMethod($this->job, 'run');
		$method->invoke($this->job, null);
	}
}
