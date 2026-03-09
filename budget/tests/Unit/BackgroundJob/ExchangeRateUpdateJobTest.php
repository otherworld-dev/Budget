<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\ExchangeRateUpdateJob;
use OCA\Budget\Service\ExchangeRateService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ExchangeRateUpdateJobTest extends TestCase {
	private ExchangeRateUpdateJob $job;
	private ITimeFactory $timeFactory;
	private ExchangeRateService $exchangeRateService;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->exchangeRateService = $this->createMock(ExchangeRateService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[ExchangeRateService::class, $this->exchangeRateService],
			[LoggerInterface::class, $this->logger],
		]);
		\OC::$server = $container;

		$this->job = new ExchangeRateUpdateJob($this->timeFactory);
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

	public function testRunFetchesLatestRates(): void {
		$this->exchangeRateService->expects($this->once())
			->method('fetchLatestRates');

		$this->logger->expects($this->once())
			->method('info')
			->with(
				$this->stringContains('completed successfully'),
				$this->equalTo(['app' => 'budget'])
			);

		$this->invokeRun();
	}

	public function testRunLogsErrorOnFailure(): void {
		$this->exchangeRateService->method('fetchLatestRates')
			->willThrowException(new \RuntimeException('Network error'));

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('Network error'),
				$this->callback(fn($ctx) => $ctx['app'] === 'budget')
			);

		$this->invokeRun();
	}

	private function invokeRun(): void {
		$method = new \ReflectionMethod($this->job, 'run');
		$method->invoke($this->job, null);
	}
}
