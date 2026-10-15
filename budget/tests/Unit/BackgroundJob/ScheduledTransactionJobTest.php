<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\ScheduledTransactionJob;
use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountBalanceCalculator;
use OCA\Budget\Service\UserClock;
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

		$this->useContainer();

		$this->job = new ScheduledTransactionJob($this->timeFactory);
	}

	private function useContainer(?UserClock $clock = null): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[TransactionMapper::class, $this->mapper],
			[AccountMapper::class, $this->accountMapper],
			[AccountBalanceCalculator::class, new AccountBalanceCalculator($this->accountMapper, $this->mapper)],
			[LoggerInterface::class, $this->logger],
			[UserClock::class, $clock],
		]);
		\OC::$server = $container;
	}

	protected function tearDown(): void {
		\OC::$server = null;
	}

	/** Hourly: a row clears within the hour of its owner's midnight, wherever that falls */
	public function testIntervalIsAnHour(): void {
		$reflection = new \ReflectionProperty($this->job, 'interval');
		$this->assertEquals(60 * 60, $reflection->getValue($this->job));
	}

	/**
	 * A row clears once its date arrives on its account owner's calendar
	 * (#399 review, F90). On the server's UTC date, Los Angeles saw
	 * tomorrow's payment in its balance every evening, and Auckland waited
	 * until noon for today's.
	 */
	public function testRowsClearOnTheirOwnersDate(): void {
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturnMap([['auckland', '2030-06-15'], ['la', '2030-06-14']]);
		$owners = [1 => 'auckland', 2 => 'la'];
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->accountMapper->method('findById')->willReturnCallback(function (int $id) use ($owners) {
			$account = new Account();
			$account->setId($id);
			$account->setUserId($owners[$id]);
			$account->setBalance(0.0);
			$account->setOpeningBalance(0.0);
			return $account;
		});
		$this->useContainer($clock);
		$auckland = $this->makeTransaction(1, 1, '2030-06-15');
		$la = $this->makeTransaction(2, 2, '2030-06-15');
		$bounds = [];
		$this->mapper->method('findScheduledDueForTransition')->willReturnCallback(function (?string $latest = null) use (&$bounds, $auckland, $la) {
			$bounds[] = $latest;
			return [$auckland, $la];
		});

		$this->invokeRun();

		$this->assertSame('cleared', $auckland->getStatus());
		$this->assertSame('scheduled', $la->getStatus());
		// The query reaches the furthest-ahead calendar: the day after UTC's
		$this->assertSame([(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d')], $bounds);
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

	private function makeTransaction(int $id, int $accountId = 1, string $date = '2026-01-01'): Transaction {
		$txn = new Transaction();
		$txn->setId($id);
		$txn->setAccountId($accountId);
		$txn->setDate($date);
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
