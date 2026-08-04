<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\DismissedImportMapper;
use OCA\Budget\Db\ExpenseShareMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\UserClock;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The scheduled/cleared decision across a timezone boundary.
 *
 * A transaction wrongly marked `scheduled` is excluded from the account
 * balance by status alone — no date check rescues it — so it stays invisible
 * in the balance until a six-hourly job happens to run. These tests pin the
 * behaviour that caused that: a user recording "today" while the server is
 * still on yesterday's date.
 */
class TransactionServiceTimezoneTest extends TestCase {
	private TransactionMapper $mapper;
	private AccountMapper $accountMapper;
	private IConfig $config;

	/** Timezone reported for each user id. */
	private array $userTz = [];

	protected function setUp(): void {
		$this->mapper = $this->createMock(TransactionMapper::class);
		// insert() echoes the entity back so the test can read its status.
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->method('getNetChangeAll')->willReturn(0.0);

		$account = new Account();
		$account->setId(1);
		$account->setUserId('user1');
		$account->setCurrency('GBP');
		$account->setOpeningBalance(0.0);
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->accountMapper->method('find')->willReturn($account);
		$this->accountMapper->method('findById')->willReturn($account);

		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')
			->willReturnCallback(fn (string $uid, string $app, string $key, $default = '') => $this->userTz[$uid] ?? $default);
		$this->config->method('getSystemValue')->willReturn('');
	}

	private function service(): TransactionService {
		$splitMapper = $this->createMock(TransactionSplitMapper::class);
		$splitMapper->method('findByTransactionIds')->willReturn([]);

		return new TransactionService(
			$this->mapper,
			$this->accountMapper,
			$this->createMock(TransactionTagMapper::class),
			$splitMapper,
			$this->createMock(ExpenseShareMapper::class),
			$this->createMock(DismissedImportMapper::class),
			$this->createMock(\OCA\Budget\Db\AttachmentMapper::class),
			$this->createMock(AuditService::class),
			$this->createMock(\OCA\Budget\Db\PensionContributionMapper::class),
			new UserClock($this->config)
		);
	}

	/** Today as a given zone sees it. */
	private function todayIn(string $tz): string {
		return (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('Y-m-d');
	}

	public function testAUserTodayAheadOfTheServerIsStillCleared(): void {
		// THE BUG: server on UTC, user in Sydney (up to +11). For much of
		// their day the user's date is already tomorrow by UTC, and every
		// transaction they recorded was filed as scheduled and dropped out
		// of the account balance.
		$this->userTz['sydney'] = 'Australia/Sydney';

		$tx = $this->service()->create(
			'sydney', 1, $this->todayIn('Australia/Sydney'), 'Coffee', 3.50, 'debit'
		);

		$this->assertSame('cleared', $tx->getStatus());
	}

	public function testAUserTodayBehindTheServerIsAlsoCleared(): void {
		$this->userTz['la'] = 'America/Los_Angeles';

		$tx = $this->service()->create(
			'la', 1, $this->todayIn('America/Los_Angeles'), 'Coffee', 3.50, 'debit'
		);

		$this->assertSame('cleared', $tx->getStatus());
	}

	public function testAGenuineFutureDateIsStillScheduled(): void {
		// The fix must not start counting real scheduled payments today.
		$this->userTz['london'] = 'Europe/London';
		$future = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))
			->modify('+3 days')->format('Y-m-d');

		$tx = $this->service()->create('london', 1, $future, 'Rent', 900.00, 'debit');

		$this->assertSame('scheduled', $tx->getStatus());
	}

	public function testAnExplicitStatusStillWins(): void {
		$this->userTz['london'] = 'Europe/London';

		$tx = $this->service()->create(
			'london', 1, $this->todayIn('Europe/London'), 'Placeholder', 10.00, 'debit',
			null, null, null, null, null, null, 'scheduled'
		);

		$this->assertSame('scheduled', $tx->getStatus());
	}

	public function testAUserWithNoStoredTimezoneFallsBackToTheServer(): void {
		$tx = $this->service()->create('nobody', 1, date('Y-m-d'), 'Coffee', 3.50, 'debit');

		$this->assertSame('cleared', $tx->getStatus());
		$this->assertInstanceOf(Transaction::class, $tx);
	}
}
