<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\ScheduledTransactionJob;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ScheduledTransactionJobTest extends TestCase {
	private ScheduledTransactionJob $job;
	private ITimeFactory $timeFactory;
	private TransactionMapper $mapper;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->mapper = $this->createMock(TransactionMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[TransactionMapper::class, $this->mapper],
			[LoggerInterface::class, $this->logger],
		]);
		\OC::$server = $container;

		$this->job = new ScheduledTransactionJob($this->timeFactory);
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

	public function testRunTransitionsScheduledTransactions(): void {
		$txn1 = $this->makeTransaction(1);
		$txn2 = $this->makeTransaction(2);

		$this->mapper->method('findScheduledDueForTransition')
			->willReturn([$txn1, $txn2]);

		$this->mapper->expects($this->exactly(2))->method('update');

		$this->logger->expects($this->once())
			->method('info');

		$this->invokeRun();

		$this->assertEquals('cleared', $txn1->getStatus());
		$this->assertEquals('cleared', $txn2->getStatus());
		$this->assertNotNull($txn1->getUpdatedAt());
		$this->assertNotNull($txn2->getUpdatedAt());
	}

	public function testRunDoesNothingWhenNoScheduledTransactions(): void {
		$this->mapper->method('findScheduledDueForTransition')
			->willReturn([]);

		$this->mapper->expects($this->never())->method('update');
		$this->logger->expects($this->never())->method('info');

		$this->invokeRun();
	}

	public function testRunContinuesOnIndividualFailure(): void {
		$txn1 = $this->makeTransaction(1);
		$txn2 = $this->makeTransaction(2);

		$this->mapper->method('findScheduledDueForTransition')
			->willReturn([$txn1, $txn2]);

		$this->mapper->method('update')
			->willReturnCallback(function ($txn) use ($txn1) {
				if ($txn === $txn1) {
					throw new \RuntimeException('DB error');
				}
				return $txn;
			});

		$this->logger->expects($this->once())->method('warning');
		$this->logger->expects($this->once())->method('info');

		$this->invokeRun();
	}

	public function testRunLogsErrorOnTotalFailure(): void {
		$this->mapper->method('findScheduledDueForTransition')
			->willThrowException(new \RuntimeException('Connection lost'));

		$this->logger->expects($this->once())
			->method('error');

		$this->invokeRun();
	}

	private function makeTransaction(int $id): Transaction {
		$txn = new Transaction();
		$txn->setId($id);
		$txn->setAccountId(1);
		$txn->setDate('2026-01-01');
		$txn->setDescription('Test');
		$txn->setAmount(100.00);
		$txn->setType('debit');
		$txn->setStatus('scheduled');
		return $txn;
	}

	private function invokeRun(): void {
		$method = new \ReflectionMethod($this->job, 'run');
		$method->invoke($this->job, null);
	}
}
