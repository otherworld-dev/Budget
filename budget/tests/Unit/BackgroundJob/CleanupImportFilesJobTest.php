<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\CleanupImportFilesJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class CleanupImportFilesJobTest extends TestCase {
	private CleanupImportFilesJob $job;
	private ITimeFactory $timeFactory;
	private IAppData $appData;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[IAppData::class, $this->appData],
			[LoggerInterface::class, $this->logger],
		]);
		\OC::$server = $container;

		$this->job = new CleanupImportFilesJob($this->timeFactory);
	}

	protected function tearDown(): void {
		\OC::$server = null;
	}

	public function testIntervalIsSixHours(): void {
		$reflection = new \ReflectionProperty($this->job, 'interval');
		$this->assertEquals(6 * 60 * 60, $reflection->getValue($this->job));
	}

	public function testIsNotTimeSensitive(): void {
		$reflection = new \ReflectionProperty($this->job, 'timeSensitivity');
		$this->assertEquals(IJob::TIME_INSENSITIVE, $reflection->getValue($this->job));
	}

	public function testRunReturnsEarlyWhenNoImportsFolder(): void {
		$this->appData->method('getFolder')
			->with('imports')
			->willThrowException(new NotFoundException());

		$this->logger->expects($this->never())->method('info');

		$this->invokeRun();
	}

	public function testRunDeletesOldFiles(): void {
		$cutoff = time() - (24 * 60 * 60);

		$oldFile = $this->createMock(ISimpleFile::class);
		$oldFile->method('getMTime')->willReturn($cutoff - 100);
		$oldFile->expects($this->once())->method('delete');

		$newFile = $this->createMock(ISimpleFile::class);
		$newFile->method('getMTime')->willReturn($cutoff + 100);
		$newFile->expects($this->never())->method('delete');

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getDirectoryListing')->willReturn([$oldFile, $newFile]);

		$this->appData->method('getFolder')->with('imports')->willReturn($folder);

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('1 deleted'),
				$this->equalTo(['app' => 'budget'])
			);

		$this->invokeRun();
	}

	public function testRunDoesNotLogWhenNothingToClean(): void {
		$newFile = $this->createMock(ISimpleFile::class);
		$newFile->method('getMTime')->willReturn(time());
		$newFile->expects($this->never())->method('delete');

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getDirectoryListing')->willReturn([$newFile]);

		$this->appData->method('getFolder')->with('imports')->willReturn($folder);

		$this->logger->expects($this->never())->method('info');

		$this->invokeRun();
	}

	public function testRunContinuesOnIndividualDeleteFailure(): void {
		$cutoff = time() - (24 * 60 * 60);

		$failFile = $this->createMock(ISimpleFile::class);
		$failFile->method('getMTime')->willReturn($cutoff - 100);
		$failFile->method('delete')->willThrowException(new \RuntimeException('Permission denied'));

		$okFile = $this->createMock(ISimpleFile::class);
		$okFile->method('getMTime')->willReturn($cutoff - 200);
		$okFile->expects($this->once())->method('delete');

		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getDirectoryListing')->willReturn([$failFile, $okFile]);

		$this->appData->method('getFolder')->with('imports')->willReturn($folder);

		$this->logger->expects($this->once())->method('warning');
		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->logicalAnd(
					$this->stringContains('1 deleted'),
					$this->stringContains('1 errors')
				),
				$this->equalTo(['app' => 'budget'])
			);

		$this->invokeRun();
	}

	public function testRunLogsErrorOnTotalFailure(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getDirectoryListing')
			->willThrowException(new \RuntimeException('Storage error'));

		$this->appData->method('getFolder')->with('imports')->willReturn($folder);

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('Storage error'),
				$this->callback(fn($ctx) => $ctx['app'] === 'budget')
			);

		$this->invokeRun();
	}

	private function invokeRun(): void {
		$method = new \ReflectionMethod($this->job, 'run');
		$method->invoke($this->job, null);
	}
}
