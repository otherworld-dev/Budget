<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\ScheduledTransactionJob;
use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountBalanceCalculator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ScheduledTransactionJobTest extends TestCase {
	private ScheduledTransactionJob $job;
	private ITimeFactory $timeFactory;
	private TransactionMapper $mapper;
	private AccountMapper $accountMapper;
	private LoggerInterface $logger;
	private Account $account;
	/** @var array<int, string> balances passed to updateBalance(), by account id */
	private array $writtenBalances = [];

	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->mapper = $this->createMock(TransactionMapper::class);
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Mock account lookup for balance updates
		$this->account = new Account();
		$this->account->setId(1);
		$this->account->setUserId('user1');
		$this->account->setBalance(1000.00);
		$this->accountMapper->method('findById')->willReturnCallback(fn () => $this->account);
		$this->accountMapper->method('updateBalance')->willReturnCallback(function ($id, $balance) {
			$this->writtenBalances[$id] = $balance;
			return $this->account;
		});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[TransactionMapper::class, $this->mapper],
			[AccountMapper::class, $this->accountMapper],
			[AccountBalanceCalculator::class, new AccountBalanceCalculator($this->accountMapper, $this->mapper)],
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

	/**
	 * The job used to recompute at the default 2dp, so every scheduled row
	 * clearing on a crypto account rounded its balance away (#331).
	 */
	public function testRunKeepsCryptoPrecisionWhenRecomputingBalance(): void {
		$this->account->setCurrency('BTC');
		$this->account->setOpeningBalance(0.12345678);
		$this->mapper->method('findScheduledDueForTransition')->willReturn([$this->makeTransaction(1)]);
		$this->mapper->method('getNetChangeAll')->willReturn(-0.00000123);

		$this->invokeRun();

		$this->assertSame('0.12345555', $this->writtenBalances[1] ?? null);
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
