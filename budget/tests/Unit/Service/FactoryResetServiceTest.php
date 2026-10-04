<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\AttachmentMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ContactMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Db\ShareMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\CrossUserLinks;
use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Service\MigrationService;
use OCA\Budget\Service\TransactionService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FactoryResetServiceTest extends TestCase {
	private FactoryResetService $service;
	private IDBConnection $db;
	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;
	private BillMapper $billMapper;
	private CategoryMapper $categoryMapper;
	private ImportRuleMapper $importRuleMapper;
	private SettingMapper $settingMapper;
	private AttachmentMapper $attachmentMapper;

	/** @var list<array{table: string, sql: ?string, params: array}> every DELETE, in order */
	private array $deletes = [];
	/** Tables whose DELETE should throw, with the message */
	private array $failingTables = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->billMapper = $this->createMock(BillMapper::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->importRuleMapper = $this->createMock(ImportRuleMapper::class);
		$this->settingMapper = $this->createMock(SettingMapper::class);
		$this->attachmentMapper = $this->createMock(AttachmentMapper::class);

		// Query-builder deletes (user-scoped registry tables)
		$this->db->method('getQueryBuilder')->willReturnCallback(function () {
			$table = null;
			$params = [];
			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('eq')->willReturnCallback(fn ($col, $val) => "$col = $val");
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('expr')->willReturn($expr);
			$qb->method('delete')->willReturnCallback(function (string $t) use (&$table, $qb) {
				$table = $t;
				return $qb;
			});
			$qb->method('where')->willReturnSelf();
			$qb->method('createNamedParameter')->willReturnCallback(function ($v) use (&$params) {
				$params[] = $v;
				return ':p' . count($params);
			});
			$qb->method('executeStatement')->willReturnCallback(function () use (&$table, &$params) {
				return $this->recordDelete($table, null, $params);
			});
			return $qb;
		});
		// Raw deletes (join-scoped registry tables)
		$this->db->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []) {
			preg_match('/^DELETE FROM \*PREFIX\*([a-z_]+)/', $sql, $m);
			return $this->recordDelete($m[1] ?? '?', $sql, $params);
		});

		// Bespoke entities go through their mappers; log them in the same sequence
		foreach ([
			'budget_transactions' => $this->transactionMapper,
			'budget_bills' => $this->billMapper,
			'budget_import_rules' => $this->importRuleMapper,
			'budget_accounts' => $this->accountMapper,
			'budget_categories' => $this->categoryMapper,
			'budget_settings' => $this->settingMapper,
			'budget_attachments' => $this->attachmentMapper,
		] as $table => $mapper) {
			$mapper->method('deleteAll')->willReturnCallback(
				fn (string $userId) => $this->recordDelete($table, null, [$userId])
			);
		}

		$this->service = new FactoryResetService(
			$this->accountMapper,
			$this->transactionMapper,
			$this->billMapper,
			$this->categoryMapper,
			$this->importRuleMapper,
			$this->settingMapper,
			$this->attachmentMapper,
			$this->db,
		);
	}

	private function recordDelete(string $table, ?string $sql, array $params): int {
		if (isset($this->failingTables[$table])) {
			throw new \Exception($this->failingTables[$table]);
		}
		$this->deletes[] = ['table' => $table, 'sql' => $sql, 'params' => $params];
		return 1;
	}

	/** @return string[] */
	private function deletedTables(): array {
		return array_column($this->deletes, 'table');
	}

	private function registryTables(): array {
		return array_column(MigrationService::EXTRA_TABLES_PRE + MigrationService::EXTRA_TABLES_POST, 'table');
	}

	/**
	 * Every table registered for backup must be wiped by a reset, scoped to
	 * the user. The old hand-written list missed ten of them.
	 */
	public function testResetClearsEveryRegisteredTableForTheUser(): void {
		$this->service->executeFactoryReset('user1');

		foreach ($this->registryTables() as $table) {
			$this->assertContains($table, $this->deletedTables(), "factory reset must clear $table");
		}
		foreach ($this->deletes as $delete) {
			$this->assertContains('user1', $delete['params'], "{$delete['table']} delete must be scoped to the user");
		}
	}

	private function serviceWith(TransactionService $transactions, ?ShareMapper $shares = null): FactoryResetService {
		return new FactoryResetService(
			$this->accountMapper,
			$this->transactionMapper,
			$this->billMapper,
			$this->categoryMapper,
			$this->importRuleMapper,
			$this->settingMapper,
			$this->attachmentMapper,
			$this->db,
			null,
			$transactions,
			$shares,
		);
	}

	public function testResetRemovesTheUsersPendingBillRowsFromOtherUsersAccounts(): void {
		// A share recipient's bill pre-books into the owner's account. The
		// transactions delete only reaches the user's own accounts, so those
		// rows outlived the bill and the scheduled job booked them later.
		$transactions = $this->createMock(TransactionService::class);
		$rent = new Bill();
		$rent->setId(4);
		$gym = new Bill();
		$gym->setId(5);
		$this->billMapper->method('findAll')->with('user1')->willReturn([$rent, $gym]);
		$deleted = [];
		$transactions->method('deleteScheduledBillTransactions')
			->willReturnCallback(function (int $billId) use (&$deleted) {
				$deleted[] = $billId;
			});

		$this->serviceWith($transactions)->executeFactoryReset('user1');

		$this->assertSame([4, 5], $deleted);
	}

	public function testPurgingADeletedUserAlsoRemovesSharesGrantedToThem(): void {
		// A reset keeps shares other users granted to this user; a deleted
		// user's must go, or a re-created uid inherits write access to them
		$shares = $this->createMock(ShareMapper::class);
		$shares->expects($this->once())->method('deleteAllForUser')->with('gone');
		$this->billMapper->method('findAll')->willReturn([]);

		$this->serviceWith($this->createMock(TransactionService::class), $shares)->purgeDeletedUser('gone');

		$this->assertContains('budget_accounts', $this->deletedTables());
	}

	private function purgingService(ShareMapper $shares, ContactMapper $contacts, ?CrossUserLinks $links = null): FactoryResetService {
		return new FactoryResetService(
			$this->accountMapper,
			$this->transactionMapper,
			$this->billMapper,
			$this->categoryMapper,
			$this->importRuleMapper,
			$this->settingMapper,
			$this->attachmentMapper,
			$this->db,
			null,
			$this->createMock(TransactionService::class),
			$shares,
			$contacts,
			$links,
		);
	}

	/**
	 * Shared expenses reach their recipient through a contact linked to
	 * their uid, so a new account given a deleted user's uid saw everything
	 * shared with the old one: the purge revokes that access. It does so in
	 * the same transaction as the data, after it, so it is gone exactly when
	 * the data is gone.
	 */
	public function testPurgingADeletedUserRevokesAccessWithTheDataInsideTheTransaction(): void {
		$this->billMapper->method('findAll')->willReturn([]);
		$this->db->method('beginTransaction')->willReturnCallback(function () {
			$this->deletes[] = ['table' => 'BEGIN', 'sql' => null, 'params' => []];
		});
		$this->db->method('commit')->willReturnCallback(function () {
			$this->deletes[] = ['table' => 'COMMIT', 'sql' => null, 'params' => []];
		});
		$shares = $this->createMock(ShareMapper::class);
		$shares->expects($this->once())->method('deleteAllForUser')->with('gone')
			->willReturnCallback(function () {
				$this->deletes[] = ['table' => 'shares', 'sql' => null, 'params' => []];
			});
		$contacts = $this->createMock(ContactMapper::class);
		$contacts->expects($this->once())->method('unlinkNextcloudUser')->with('gone')
			->willReturnCallback(function () {
				$this->deletes[] = ['table' => 'contacts', 'sql' => null, 'params' => []];
				return 2;
			});

		$this->purgingService($shares, $contacts)->purgeDeletedUser('gone');

		$order = array_flip($this->deletedTables());
		$this->assertSame(0, $order['BEGIN']);
		$this->assertGreaterThan($order['budget_accounts'], $order['shares']);
		$this->assertGreaterThan($order['budget_transactions'], $order['shares']);
		$this->assertSame(['shares', 'contacts', 'COMMIT'], array_slice($this->deletedTables(), -3));
	}

	/**
	 * A purge that fails part way must leave the data as it was, so it can
	 * be run again (`occ budget:purge-deleted-users`). Deleting the shares
	 * first, outside the transaction, left the data behind and took away
	 * the shared links a second run needs to cut. (The access others gave
	 * the uid is still cut: see the next test.)
	 */
	public function testAFailedPurgeLeavesTheDataSoItCanBeRunAgain(): void {
		$rent = new Bill();
		$rent->setId(4);
		$this->billMapper->method('findAll')->willReturn([$rent]);
		$transactions = $this->createMock(TransactionService::class);
		$transactions->expects($this->once())->method('deleteScheduledBillTransactions')->with(4)
			->willReturnCallback(function () {
				$this->deletes[] = ['table' => 'pending bill rows', 'sql' => null, 'params' => []];
			});
		$this->db->method('beginTransaction')->willReturnCallback(function () {
			$this->deletes[] = ['table' => 'BEGIN', 'sql' => null, 'params' => []];
		});
		$this->db->expects($this->never())->method('commit');
		$this->db->expects($this->once())->method('rollBack');
		$this->failingTables['budget_expense_shares'] = 'DB error';
		$shares = $this->createMock(ShareMapper::class);
		// Shares the uid granted are its data: they stay for the second run
		$shares->expects($this->never())->method('deleteAllForUser');
		$contacts = $this->createMock(ContactMapper::class);
		$service = new FactoryResetService(
			$this->accountMapper, $this->transactionMapper, $this->billMapper, $this->categoryMapper,
			$this->importRuleMapper, $this->settingMapper, $this->attachmentMapper, $this->db,
			null, $transactions, $shares, $contacts,
		);

		try {
			$service->purgeDeletedUser('gone');
			$this->fail('the failure must reach the caller');
		} catch (\Exception $e) {
			$this->assertSame('DB error', $e->getMessage());
		}

		// The pending bill rows went inside the transaction that rolled back
		$this->assertSame(['BEGIN', 'pending bill rows'], array_slice($this->deletedTables(), 0, 2));
	}

	/**
	 * The data of a failed purge stays for a second run, but the access
	 * other users gave the deleted uid must not wait for it: a re-created
	 * account with the same uid would inherit it. After the rollback the
	 * shares granted to them and the contacts linked to them go on their
	 * own; the shares they granted are their data and stay with it.
	 */
	public function testAFailedPurgeStillCutsTheAccessOtherUsersGaveTheUid(): void {
		$this->billMapper->method('findAll')->willReturn([]);
		$this->failingTables['budget_expense_shares'] = 'DB error';
		$steps = [];
		$this->db->method('rollBack')->willReturnCallback(function () use (&$steps) {
			$steps[] = 'rollback';
		});
		$this->db->expects($this->never())->method('commit');
		$shares = $this->createMock(ShareMapper::class);
		$shares->expects($this->never())->method('deleteAllForUser');
		$shares->expects($this->once())->method('deleteSharedWithUser')->with('gone')
			->willReturnCallback(function () use (&$steps) {
				$steps[] = 'shares to the uid';
				return 1;
			});
		$contacts = $this->createMock(ContactMapper::class);
		$contacts->expects($this->once())->method('unlinkNextcloudUser')->with('gone')
			->willReturnCallback(function () use (&$steps) {
				$steps[] = 'contact links';
				return 2;
			});

		try {
			$this->purgingService($shares, $contacts)->purgeDeletedUser('gone');
			$this->fail('the failure must reach the caller');
		} catch (\Exception $e) {
			$this->assertSame('DB error', $e->getMessage());
		}

		$this->assertSame(['rollback', 'shares to the uid', 'contact links'], $steps);
	}

	public function testAFailingRevocationIsLoggedAndTheOtherStillRuns(): void {
		$this->billMapper->method('findAll')->willReturn([]);
		$this->failingTables['budget_expense_shares'] = 'DB error';
		$shares = $this->createMock(ShareMapper::class);
		$shares->method('deleteSharedWithUser')->willThrowException(new \RuntimeException('shares table locked'));
		$contacts = $this->createMock(ContactMapper::class);
		$contacts->expects($this->once())->method('unlinkNextcloudUser')->with('gone');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with($this->stringContains('shares'), $this->callback(
				fn (array $context) => ($context['app'] ?? null) === 'budget' && ($context['user'] ?? null) === 'gone'
			));
		$service = new FactoryResetService(
			$this->accountMapper, $this->transactionMapper, $this->billMapper, $this->categoryMapper,
			$this->importRuleMapper, $this->settingMapper, $this->attachmentMapper, $this->db,
			null, $this->createMock(TransactionService::class), $shares, $contacts, null, $logger,
		);

		$this->expectExceptionMessage('DB error');
		$service->purgeDeletedUser('gone');
	}

	public function testAFailedFactoryResetRevokesNothing(): void {
		// The user still exists: their access is theirs to keep
		$this->billMapper->method('findAll')->willReturn([]);
		$this->failingTables['budget_expense_shares'] = 'DB error';
		$shares = $this->createMock(ShareMapper::class);
		$shares->expects($this->never())->method('deleteSharedWithUser');
		$contacts = $this->createMock(ContactMapper::class);
		$contacts->expects($this->never())->method('unlinkNextcloudUser');

		$this->expectExceptionMessage('DB error');
		$this->purgingService($shares, $contacts)->executeFactoryReset('user1');
	}

	public function testAResetLeavesContactLinksToTheUserAlone(): void {
		// The user still exists after a reset: other people's contacts for
		// them are still right
		$contacts = $this->createMock(ContactMapper::class);
		$contacts->expects($this->never())->method('unlinkNextcloudUser');
		$shares = $this->createMock(ShareMapper::class);
		$shares->expects($this->never())->method('deleteAllForUser');
		$this->billMapper->method('findAll')->willReturn([]);

		$this->purgingService($shares, $contacts)->executeFactoryReset('user1');
	}

	/**
	 * Other users' rows that used the user's shared accounts and categories
	 * are read before anything goes and cut loose inside the reset, so a
	 * failure rolls the cut back with the rest.
	 */
	public function testOtherUsersLinksAreReadFirstAndCutBeforeTheCommit(): void {
		$this->billMapper->method('findAll')->willReturn([]);
		$links = $this->createMock(CrossUserLinks::class);
		$links->expects($this->once())->method('capture')->with('user1')
			->willReturnCallback(function () {
				$this->assertSame([], $this->deletes, 'links are read before anything is deleted');
			});
		$links->expects($this->once())->method('apply')->with([])
			->willReturnCallback(function () {
				$this->assertContains('budget_categories', $this->deletedTables());
				$this->deletes[] = ['table' => 'links cut', 'sql' => null, 'params' => []];
				return ['sharesDropped' => 0, 'othersDetached' => 0];
			});
		$this->db->expects($this->once())->method('commit')->willReturnCallback(function () {
			$this->assertContains('links cut', $this->deletedTables());
		});

		$this->purgingService($this->createMock(ShareMapper::class), $this->createMock(ContactMapper::class), $links)
			->executeFactoryReset('user1');
	}

	public function testResetClearsTheBespokeEntitiesAndAttachmentRows(): void {
		$this->service->executeFactoryReset('user1');

		foreach (['budget_transactions', 'budget_bills', 'budget_import_rules', 'budget_accounts',
			'budget_categories', 'budget_settings', 'budget_attachments'] as $table) {
			$this->assertContains($table, $this->deletedTables());
		}
	}

	/**
	 * Transaction tags and splits are only reachable by joining through
	 * transactions -> accounts; tag sets through categories. Deleting a
	 * parent first orphans them for good (#359).
	 */
	public function testJoinScopedTablesAreClearedBeforeTheirParents(): void {
		$this->service->executeFactoryReset('user1');
		$order = array_flip($this->deletedTables());

		foreach (MigrationService::EXTRA_TABLES_PRE + MigrationService::EXTRA_TABLES_POST as $spec) {
			if (($spec['scope'] ?? 'user') === 'user') {
				continue;
			}
			foreach ($spec['scope']['joins'] as [$parent]) {
				$this->assertLessThan($order[$parent], $order[$spec['table']],
					"{$spec['table']} must be cleared before $parent");
			}
		}
		$this->assertLessThan($order['budget_accounts'], $order['budget_transactions'],
			'transactions are found through their account');
	}

	public function testTransactionTagsAreClearedThroughTheUsersTransactions(): void {
		$this->service->executeFactoryReset('user1');

		$tagDelete = array_values(array_filter($this->deletes, fn ($d) => $d['table'] === 'budget_transaction_tags'))[0];
		$this->assertStringContainsString('transaction_id IN (SELECT id FROM *PREFIX*budget_transactions', $tagDelete['sql']);
		$this->assertStringContainsString('budget_accounts WHERE user_id = ?', $tagDelete['sql']);
	}

	/**
	 * A write recipient's tags on the owner's rows are found through the
	 * tags, not the user's transactions, so they go while the tags are still
	 * there (T4-11).
	 */
	public function testTheUsersTagsOnOtherUsersRowsAreClearedBeforeTheTags(): void {
		$this->service->executeFactoryReset('user1');

		$byTag = array_keys(array_filter($this->deletes, fn ($d) => $d['table'] === 'budget_transaction_tags'
			&& str_contains((string)$d['sql'], 'tag_id IN (SELECT id FROM *PREFIX*budget_tags WHERE user_id = ?)')));
		$tags = array_keys(array_filter($this->deletes, fn ($d) => $d['table'] === 'budget_tags'));
		$this->assertCount(1, $byTag);
		$this->assertLessThan($tags[0], $byTag[0]);
	}

	public function testExecuteFactoryResetCommitsAndReportsCounts(): void {
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$counts = $this->service->executeFactoryReset('user1');

		$this->assertSame(1, $counts['transactions']);
		$this->assertSame(1, $counts['accounts']);
		$this->assertSame(1, $counts['categories']);
		$this->assertSame(1, $counts['settings']);
		$this->assertSame(1, $counts['attachments']);
		$this->assertSame(1, $counts['transaction_tags']);
		$this->assertSame(1, $counts['tag_sets']);
	}

	public function testExecuteFactoryResetRollsBackOnError(): void {
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->never())->method('commit');
		$this->db->expects($this->once())->method('rollBack');

		$this->failingTables['budget_expense_shares'] = 'DB error';

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('DB error');

		$this->service->executeFactoryReset('user1');
	}

	public function testExecuteFactoryResetHandlesMissingTables(): void {
		$this->failingTables['budget_expense_shares'] = 'no such table: oc_budget_expense_shares';
		$this->failingTables['budget_pen_snaps'] = "Table 'nc.oc_budget_pen_snaps' doesn't exist";
		$this->failingTables['budget_attachments'] = 'no such table: oc_budget_attachments';

		$counts = $this->service->executeFactoryReset('user1');

		$this->assertSame(0, $counts['expense_shares']);
		$this->assertSame(0, $counts['pen_snaps']);
		$this->assertSame(0, $counts['attachments']);
		$this->assertSame(1, $counts['transactions']);
	}
}
