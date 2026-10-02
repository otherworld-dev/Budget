<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\MigrationService;
use OCA\Budget\Service\TransactionService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * How a backup restore treats the restoring user's links to other users'
 * data while it reads the archive (CrossUserLinks decides; these check the
 * import asks it, and acts on the answer).
 *
 * Alice shares her joint account (write) and her Food category with Bob.
 * Bob has a Netflix bill paid from the joint account, filed under Food and
 * split with one of his own categories, and Alice's account holds its rows.
 */
class MigrationServiceCrossUserTest extends TestCase {
	private const CREATED = '2026-01-01 10:00:00';

	private InMemoryCrossUserLinks $links;
	private BillMapper $billMapper;
	private TransactionMapper $transactionMapper;
	private ImportRuleMapper $importRuleMapper;
	private MigrationService $service;

	/** @var Bill[] */
	private array $insertedBills = [];

	/** @var Transaction[] */
	private array $insertedTransactions = [];

	protected function setUp(): void {
		$this->links = new InMemoryCrossUserLinks($this->createMock(IDBConnection::class), $this->createMock(TransactionService::class));
		$this->links->tables = [
			'budget_accounts' => [
				10 => ['user_id' => 'alice', 'name' => 'Joint', 'created_at' => self::CREATED],
				50 => ['user_id' => 'bob', 'name' => 'Bob current', 'created_at' => self::CREATED],
			],
			'budget_categories' => [
				20 => ['user_id' => 'alice', 'name' => 'Food', 'created_at' => self::CREATED],
				60 => ['user_id' => 'bob', 'name' => 'Fun', 'created_at' => self::CREATED],
			],
			'budget_bills' => [
				40 => ['user_id' => 'bob', 'name' => 'Netflix', 'created_at' => self::CREATED,
					'account_id' => 10, 'destination_account_id' => null, 'category_id' => 20,
					'split_template' => json_encode([['categoryId' => 20, 'amount' => 5.5], ['categoryId' => 60, 'amount' => 4.49]]),
					'paid_undo_state' => json_encode(['previousState' => [], 'createdTransactionIds' => [101], 'scheduledTransactionIds' => [100]])],
				70 => ['user_id' => 'alice', 'name' => 'Rent', 'created_at' => self::CREATED, 'account_id' => 10],
			],
			'budget_recurring_income' => [
				80 => ['user_id' => 'bob', 'name' => 'Pocket money', 'created_at' => self::CREATED, 'account_id' => 10, 'category_id' => null,
					'received_undo_state' => json_encode(['nextExpectedDate' => '2026-10-01', 'transactionIds' => [107]])],
			],
			'budget_shares' => [
				1 => ['owner_user_id' => 'alice', 'shared_with_user_id' => 'bob', 'status' => 'accepted'],
			],
			'budget_share_items' => [
				1 => ['share_id' => 1, 'entity_type' => 'account', 'entity_id' => 10, 'permission' => 'write'],
				2 => ['share_id' => 1, 'entity_type' => 'category', 'entity_id' => 20, 'permission' => 'read'],
			],
			'budget_transactions' => [
				100 => ['account_id' => 10, 'date' => '2026-10-15', 'amount' => '9.99', 'type' => 'debit', 'status' => 'scheduled', 'bill_id' => 40],
				101 => ['account_id' => 10, 'date' => '2026-09-15', 'amount' => '9.99', 'type' => 'debit', 'status' => 'cleared', 'bill_id' => 40],
				107 => ['account_id' => 10, 'date' => '2026-09-01', 'amount' => '5.00', 'type' => 'credit', 'status' => 'cleared'],
			],
		];

		$this->billMapper = $this->createMock(BillMapper::class);
		$nextBillId = 500;
		$this->billMapper->method('insert')->willReturnCallback(function (Bill $b) use (&$nextBillId) {
			$b->setId($nextBillId++);
			$this->insertedBills[] = $b;
			// Where CrossUserLinks::apply() looks for the restored bill
			$this->links->tables['budget_bills'][$b->getId()] = [
				'user_id' => $b->getUserId(), 'name' => $b->getName(), 'created_at' => $b->getCreatedAt(), 'account_id' => $b->getAccountId(),
			];
			return $b;
		});
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$nextTxId = 900;
		$this->transactionMapper->method('insert')->willReturnCallback(function (Transaction $t) use (&$nextTxId) {
			$t->setId($nextTxId++);
			$this->insertedTransactions[] = $t;
			return $t;
		});
		$this->importRuleMapper = $this->createMock(ImportRuleMapper::class);

		$this->service = new MigrationService(
			$this->createMock(AccountMapper::class),
			$this->transactionMapper,
			$this->createMock(CategoryMapper::class),
			$this->billMapper,
			$this->importRuleMapper,
			$this->createMock(SettingMapper::class),
			$this->createMock(IDBConnection::class),
			null,
			null,
			$this->links
		);
	}

	private function invoke(string $method, mixed ...$args): mixed {
		$reflection = new \ReflectionMethod($this->service, $method);
		$reflection->setAccessible(true);
		return $reflection->invoke($this->service, ...$args);
	}

	private function bobRestores(): void {
		$this->links->capture('bob');
		$this->links->clearUser('bob');
	}

	private function netflix(array $overrides = []): array {
		return $overrides + [
			'id' => 40, 'name' => 'Netflix', 'createdAt' => self::CREATED, 'amount' => 9.99, 'frequency' => 'monthly',
			'accountId' => 10, 'categoryId' => 20, 'autoPayEnabled' => true, 'lastPaidDate' => '2026-09-15',
			'splitTemplate' => [['categoryId' => 20, 'amount' => 5.5], ['categoryId' => 60, 'amount' => 4.49]],
			'paidUndoState' => ['previousState' => [], 'createdTransactionIds' => [101], 'scheduledTransactionIds' => [100]],
		];
	}

	private const BOB_MAPS = ['accounts' => [50 => 150], 'categories' => [60 => 160], 'tags' => [], 'transactions' => []];

	/**
	 * Bob restores his own backup while Alice still shares the joint
	 * account with him. His bill used to come back with no account: auto-pay
	 * stopped while still showing, Mark Paid recorded nothing, and the
	 * unrecorded-payments card offered to book the payment a second time.
	 */
	public function testARecipientsBillKeepsTheAccountStillSharedWithThem(): void {
		$this->bobRestores();

		$this->invoke('importBills', 'bob', [$this->netflix()], self::BOB_MAPS);
		$bill = $this->insertedBills[0];

		$this->assertSame(10, $bill->getAccountId());
		$this->assertSame(20, $bill->getCategoryId());
		$this->assertTrue($bill->getAutoPayEnabled());
		$this->assertSame(
			[['categoryId' => 20, 'amount' => 5.5], ['categoryId' => 160, 'amount' => 4.49]],
			$bill->getSplitTemplateArray()
		);
		// The rows its last payment booked are in Alice's account, outside
		// the backup, and still there
		$snapshot = json_decode((string)$bill->getPaidUndoState(), true);
		$this->assertSame([101], $snapshot['createdTransactionIds']);
		$this->assertSame([100], $snapshot['scheduledTransactionIds']);
	}

	/**
	 * Once Bob can no longer write to the account, or the bill isn't the
	 * one he had here, the account goes. Auto-pay goes with it, and so does
	 * Mark Unpaid, whose rows the restore can't vouch for.
	 */
	public function testARecipientsBillLosesAnAccountItCanNoLongerUse(): void {
		$this->links->tables['budget_share_items'][1]['permission'] = 'read';
		$this->bobRestores();

		$this->invoke('importBills', 'bob', [$this->netflix(), $this->netflix(['id' => 41, 'name' => 'Gym'])], self::BOB_MAPS);

		foreach ($this->insertedBills as $bill) {
			$this->assertNull($bill->getAccountId(), $bill->getName());
			$this->assertFalse($bill->getAutoPayEnabled(), $bill->getName());
			$this->assertNull($bill->getPaidUndoState(), $bill->getName());
		}
		// The category is only read, which a read share allows
		$this->assertSame(20, $this->insertedBills[0]->getCategoryId());
		// The Gym bill was never here: nothing of Alice's is kept for it
		$this->assertNull($this->insertedBills[1]->getCategoryId());
	}

	/**
	 * The restore reports what it couldn't keep, so a bill that lost its
	 * account isn't a surprise.
	 */
	public function testAnImportReportsWhatItCouldNotKeep(): void {
		$this->links->tables['budget_share_items'][1]['permission'] = 'read';
		$service = $this->serviceForImportAll();

		$result = $service->importAll('bob', $this->archive(['bills.json' => json_encode([$this->netflix()])]));

		$this->assertTrue($result['success']);
		$this->assertCount(1, $result['warnings']);
		$this->assertStringContainsString('1 bill or transfer', $result['warnings'][0]);
	}

	public function testAnImportWithNothingToReportHasNoWarnings(): void {
		$result = $this->serviceForImportAll()->importAll('bob', $this->archive(['bills.json' => json_encode([$this->netflix()])]));

		$this->assertSame([], $result['warnings']);
	}

	/**
	 * Alice restores her own backup. The rows Bob's Netflix booked in her
	 * account are restored with their link to his bill, which he still has,
	 * when they're the rows she had here. A pending row whose bill can't be
	 * vouched for is not restored at all: unlinked, nothing could clear or
	 * remove it, and it would charge her on its date.
	 */
	public function testAnOwnersRowsKeepAnotherUsersBillOnlyWhenItIsTheSameRow(): void {
		$this->links->capture('alice');
		$this->links->clearUser('alice');

		$this->invoke('importTransactions', 'alice', [
			['id' => 100, 'accountId' => 10, 'date' => '2026-10-15', 'amount' => 9.99, 'type' => 'debit', 'status' => 'scheduled', 'billId' => 40],
			['id' => 101, 'accountId' => 10, 'date' => '2026-09-15', 'amount' => 9.99, 'type' => 'debit', 'status' => 'cleared', 'billId' => 40],
			// Another user's bill this server never had linked here
			['id' => 102, 'accountId' => 10, 'date' => '2026-10-20', 'amount' => 15.0, 'type' => 'debit', 'status' => 'scheduled', 'billId' => 41],
			['id' => 103, 'accountId' => 10, 'date' => '2026-09-20', 'amount' => 15.0, 'type' => 'debit', 'status' => 'cleared', 'billId' => 41],
			// Her own bill's row, linked through the archive as before
			['id' => 104, 'accountId' => 10, 'date' => '2026-10-01', 'amount' => 800.0, 'type' => 'debit', 'status' => 'scheduled', 'billId' => 70],
		], ['accounts' => [10 => 110], 'categories' => []], [70], [10 => ['id' => 10, 'name' => 'Joint', 'createdAt' => self::CREATED]]);

		$byOldDate = [];
		foreach ($this->insertedTransactions as $t) {
			$byOldDate[$t->getDate()] = $t;
		}
		$this->assertCount(4, $this->insertedTransactions, 'The unvouched pending row is left out');
		$this->assertArrayNotHasKey('2026-10-20', $byOldDate);
		$this->assertSame(40, $byOldDate['2026-10-15']->getBillId());
		$this->assertSame(40, $byOldDate['2026-09-15']->getBillId());
		$this->assertNull($byOldDate['2026-09-20']->getBillId(), 'A paid row keeps the money, not the dead link');
		// Her own bill is linked in the fixup pass, once it has a new id
		$this->assertNull($byOldDate['2026-10-01']->getBillId());
	}

	/**
	 * Recurring income on a shared account follows the same rule as bills,
	 * and its Mark Unreceived keeps the credit in the shared account.
	 */
	public function testARecipientsIncomeKeepsTheAccountStillSharedWithThem(): void {
		$this->bobRestores();
		$spec = MigrationService::EXTRA_TABLES_POST['recurring_income'];
		$row = ['id' => 80, 'name' => 'Pocket money', 'created_at' => self::CREATED, 'account_id' => 10, 'category_id' => null,
			'received_undo_state' => json_encode(['nextExpectedDate' => '2026-10-01', 'transactionIds' => [107]])];

		$kept = $this->invoke('remapRow', $row, $spec, self::BOB_MAPS);
		$this->assertSame(10, $kept['account_id']);
		$this->assertSame([107], json_decode($kept['received_undo_state'], true)['transactionIds']);

		$other = $this->invoke('remapRow', ['name' => 'Something else'] + $row, $spec, self::BOB_MAPS);
		$this->assertNull($other['account_id']);
		$this->assertNull($other['received_undo_state']);
	}

	/**
	 * Share items follow the entities they share to their new ids, so the
	 * restore has to know the new ids of every shareable kind.
	 */
	public function testEveryShareableKindGetsAnIdMap(): void {
		$post = MigrationService::EXTRA_TABLES_POST;
		$this->assertSame('recurring_income', $post['recurring_income']['idMap']);
		$this->assertSame('savings_goals', $post['savings_goals']['idMap']);
		$this->assertSame('projects', $post['projects']['idMap']);

		$this->importRuleMapper->method('insert')->willReturnCallback(function (ImportRule $r) {
			$r->setId(77);
			return $r;
		});
		$map = $this->invoke('importImportRules', 'bob', [['id' => 7, 'name' => 'Tesco']], ['categories' => [], 'accounts' => []]);
		$this->assertSame([7 => 77], $map);
	}

	private function serviceForImportAll(): MigrationService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () {
			$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
			$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
			foreach (['select', 'from', 'where', 'andWhere', 'innerJoin', 'delete', 'insert', 'update', 'set', 'setValue'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn(':p');
			$result = $this->createMock(\OCP\DB\IResult::class);
			$result->method('fetch')->willReturn(false);
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});
		$this->billMapper->method('findAll')->willReturn([]);
		return new MigrationService(
			$this->createMock(AccountMapper::class),
			$this->transactionMapper,
			$this->createMock(CategoryMapper::class),
			$this->billMapper,
			$this->importRuleMapper,
			$this->createMock(SettingMapper::class),
			$db,
			null,
			null,
			$this->links
		);
	}

	private function archive(array $files): string {
		$files += [
			'manifest.json' => json_encode(['version' => '1.2.0', 'appId' => 'budget']),
			'categories.json' => '[]',
			'accounts.json' => '[]',
			'transactions.json' => '[]',
		];
		$path = tempnam(sys_get_temp_dir(), 'test_zip_');
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		foreach ($files as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		$content = (string)file_get_contents($path);
		unlink($path);
		return $content;
	}
}
