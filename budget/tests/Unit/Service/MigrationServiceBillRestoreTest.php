<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\MigrationService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * What a backup restore carries over on a bill beyond its plain columns: the
 * ids inside its JSON (split template, undo snapshot) and the state the
 * reminder job and the bill editor rely on.
 */
class MigrationServiceBillRestoreTest extends TestCase {
	private MigrationService $service;
	private BillMapper $billMapper;

	/** @var Bill[] bills handed to insert(), in order */
	private array $inserted = [];

	protected function setUp(): void {
		$this->billMapper = $this->createMock(BillMapper::class);
		$nextId = 500;
		$this->billMapper->method('insert')->willReturnCallback(function (Bill $b) use (&$nextId) {
			$b->setId($nextId++);
			$this->inserted[] = $b;
			return $b;
		});

		// The table-level export runs raw query-builder statements; give it
		// inert builders that find no rows
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () {
			$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
			$expr->method('eq')->willReturn('eq');
			$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
			foreach (['select', 'from', 'where', 'andWhere', 'innerJoin', 'orderBy', 'setMaxResults'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn(':p');
			$result = $this->createMock(\OCP\DB\IResult::class);
			$result->method('fetch')->willReturn(false);
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});
		$this->service = new MigrationService(
			$this->createMock(AccountMapper::class),
			$this->createMock(TransactionMapper::class),
			$this->createMock(CategoryMapper::class),
			$this->billMapper,
			$this->createMock(ImportRuleMapper::class),
			$this->createMock(SettingMapper::class),
			$db
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $bills archived bills
	 * @return array<int, int> old id => new id
	 */
	private function importBills(array $bills, array $idMaps): array {
		$method = new \ReflectionMethod($this->service, 'importBills');
		$method->setAccessible(true);
		return $method->invoke($this->service, 'user1', $bills, $idMaps + ['accounts' => [], 'categories' => [], 'tags' => [], 'transactions' => []]);
	}

	/**
	 * A split bill stores its categories inside the template, not in
	 * category_id (which is NULL for it), and the restore copied the
	 * template verbatim. Every category comes back with a new id, so each
	 * payment's split was refused ("Category not found") and the payment
	 * was saved unsplit and uncategorised.
	 */
	public function testSplitTemplateCategoriesFollowTheRestoredCategories(): void {
		$this->importBills([[
			'id' => 40, 'name' => 'Council tax', 'amount' => 50.0, 'categoryId' => null,
			'splitTemplate' => [
				['categoryId' => 100, 'amount' => 30.5, 'description' => 'House'],
				['categoryId' => 101, 'amount' => 14.5, 'description' => null],
				// A category that isn't in the backup: the part stays, uncategorised
				['categoryId' => 999, 'amount' => 5.25, 'description' => 'Garage'],
			],
		]], ['categories' => [100 => 7, 101 => 8]]);

		$this->assertSame([
			['categoryId' => 7, 'amount' => 30.5, 'description' => 'House'],
			['categoryId' => 8, 'amount' => 14.5, 'description' => null],
			['categoryId' => null, 'amount' => 5.25, 'description' => 'Garage'],
		], $this->inserted[0]->getSplitTemplateArray());
		$this->assertNull($this->inserted[0]->getCategoryId());
	}

	/**
	 * The reminder job only sends once per due date because it remembers
	 * when it last sent. A restore dropped that, so every bill inside its
	 * reminder window got the same reminder again.
	 */
	public function testTheLastReminderSentSurvivesARestore(): void {
		$this->importBills([
			['id' => 40, 'name' => 'Rent', 'amount' => 800.0, 'reminderDays' => 3, 'nextDueDate' => '2026-10-03',
				'lastReminderSent' => '2026-09-30 06:00:00'],
			['id' => 41, 'name' => 'Phone', 'amount' => 20.0],
		], []);

		$this->assertSame('2026-09-30 06:00:00', $this->inserted[0]->getLastReminderSent());
		$this->assertNull($this->inserted[1]->getLastReminderSent());
	}

	/**
	 * Before 2.52 a one-time bill had no start date (its date lives there
	 * since #375), and marking one paid cleared its next due date. Migration
	 * 104 rebuilt the date once, at the upgrade; a restore of an older backup
	 * brought the gap straight back, so the bill opened with an empty Due
	 * Date. The restore applies the same rule as the migration.
	 */
	public function testAOneTimeBillFromAnOldBackupGetsItsDueDateBack(): void {
		$this->importBills([
			// Unpaid: its date is the next due date
			['id' => 40, 'name' => 'Invoice A', 'amount' => 90.0, 'frequency' => 'one-time',
				'dueDay' => 20, 'dueMonth' => 11, 'nextDueDate' => '2026-11-20', 'isActive' => true],
			// Paid before 2.52: only the day and month are left, the year
			// is the one nearest the day it was paid
			['id' => 41, 'name' => 'Invoice B', 'amount' => 60.0, 'frequency' => 'one-time',
				'dueDay' => 15, 'dueMonth' => 8, 'nextDueDate' => null, 'lastPaidDate' => '2026-08-14', 'isActive' => false],
			// Already has one: kept as it is
			['id' => 42, 'name' => 'Invoice C', 'amount' => 10.0, 'frequency' => 'one-time',
				'startDate' => '2026-07-01', 'nextDueDate' => null, 'lastPaidDate' => '2026-07-01', 'isActive' => false],
			// Not one-time: no start date is a valid state, left alone
			['id' => 43, 'name' => 'Rent', 'amount' => 800.0, 'frequency' => 'monthly',
				'dueDay' => 1, 'nextDueDate' => '2026-11-01'],
		], []);

		$this->assertSame('2026-11-20', $this->inserted[0]->getStartDate());
		$this->assertSame('2026-08-15', $this->inserted[1]->getStartDate());
		$this->assertSame('2026-07-01', $this->inserted[2]->getStartDate());
		$this->assertNull($this->inserted[3]->getStartDate());
	}

	private const SNAPSHOT = [
		'previousState' => ['lastPaidDate' => '2026-08-01', 'nextDueDate' => '2026-09-01', 'isActive' => true, 'amount' => 812.5],
		'createdTransactionIds' => [11, 12],
		'scheduledTransactionIds' => [13],
		'linkedTransactionId' => null,
		'hadScheduledTransaction' => true,
		'paidDate' => '2026-09-01',
	];

	/**
	 * Mark Unpaid works from the snapshot the last payment left on the bill
	 * (#365). The export left it out, so no restored bill could be marked
	 * unpaid, and a paid one-time bill, which the Bills page only lists while
	 * it has one, disappeared from the page.
	 */
	public function testTheExportCarriesTheUndoSnapshot(): void {
		$bill = new Bill();
		$bill->setId(40);
		$bill->setName('Rent');
		$bill->setPaidUndoState(json_encode(self::SNAPSHOT));
		$this->billMapper->method('findAll')->willReturn([$bill]);

		$bills = $this->exportedFile('bills.json');

		$this->assertSame(self::SNAPSHOT['createdTransactionIds'], $bills[0]['paidUndoState']['createdTransactionIds']);
		$this->assertSame(self::SNAPSHOT['previousState'], $bills[0]['paidUndoState']['previousState']);
	}

	/**
	 * The snapshot names the rows the payment booked, and a restore gives
	 * every row a new id. Copied as it was, a revert would delete whatever
	 * now held the old ids, so the ids move to the restored rows.
	 */
	public function testTheUndoSnapshotPointsAtTheRestoredTransactions(): void {
		$this->importBills([
			['id' => 40, 'name' => 'Rent', 'amount' => 800.0, 'accountId' => 1, 'paidUndoState' => self::SNAPSHOT],
			// The pre-existing row a payment linked, and a placeholder the
			// user deleted before the backup was made (its id is gone)
			['id' => 41, 'name' => 'Card', 'amount' => 50.0, 'accountId' => 1,
				'paidUndoState' => json_encode(['linkedTransactionId' => 14, 'scheduledTransactionIds' => [99]] + self::SNAPSHOT)],
		], ['accounts' => [1 => 5], 'transactions' => [11 => 111, 12 => 112, 13 => 113, 14 => 114]]);

		$restored = json_decode((string)$this->inserted[0]->getPaidUndoState(), true);
		$this->assertSame([111, 112], $restored['createdTransactionIds']);
		$this->assertSame([113], $restored['scheduledTransactionIds']);
		$this->assertNull($restored['linkedTransactionId']);
		$this->assertSame(self::SNAPSHOT['previousState'], $restored['previousState']);
		$this->assertTrue($restored['hadScheduledTransaction']);
		$this->assertTrue($this->inserted[0]->canMarkUnpaid());

		$linked = json_decode((string)$this->inserted[1]->getPaidUndoState(), true);
		$this->assertSame(114, $linked['linkedTransactionId']);
		$this->assertSame([], $linked['scheduledTransactionIds']);
	}

	/**
	 * A bill paid into an account that isn't in the backup booked rows that
	 * aren't in it either. Without them a revert would put the bill back to
	 * unpaid and leave the payment standing, so the next payment books it
	 * twice. Such a bill comes back without Mark Unpaid, as do snapshots
	 * that don't read as one.
	 */
	public function testAnUndoSnapshotThatCannotBeCarriedIsDropped(): void {
		$this->importBills([
			['id' => 40, 'name' => 'Rent', 'amount' => 800.0, 'accountId' => 77, 'paidUndoState' => self::SNAPSHOT],
			['id' => 41, 'name' => 'Savings', 'amount' => 100.0, 'accountId' => 1, 'isTransfer' => true,
				'destinationAccountId' => 78, 'paidUndoState' => self::SNAPSHOT],
			['id' => 42, 'name' => 'Phone', 'amount' => 20.0, 'accountId' => 1, 'paidUndoState' => '{not json'],
			['id' => 43, 'name' => 'Water', 'amount' => 20.0, 'accountId' => 1,
				'paidUndoState' => ['createdTransactionIds' => [11]]],
			['id' => 44, 'name' => 'Gas', 'amount' => 20.0, 'accountId' => 1,
				'paidUndoState' => ['createdTransactionIds' => ['x']] + self::SNAPSHOT],
		], ['accounts' => [1 => 5], 'transactions' => [11 => 111, 12 => 112, 13 => 113]]);

		foreach ($this->inserted as $bill) {
			$this->assertNull($bill->getPaidUndoState(), $bill->getName() . ' must come back without a snapshot');
		}
	}

	/**
	 * Recurring income's Mark Unreceived keeps the same kind of snapshot,
	 * in a table the backup copies column for column, so it came back
	 * naming transactions from before the restore.
	 */
	public function testAnIncomeReceiptSnapshotPointsAtTheRestoredTransaction(): void {
		$spec = MigrationService::EXTRA_TABLES_POST['recurring_income'];
		$snapshot = ['nextExpectedDate' => '2026-09-25', 'lastReceivedDate' => '2026-08-25', 'isActive' => true,
			'startDate' => null, 'transactionIds' => [11]];
		$idMaps = ['accounts' => [1 => 5], 'categories' => [], 'transactions' => [11 => 111]];

		$own = $this->remapRow(['id' => 3, 'account_id' => 1, 'received_undo_state' => json_encode($snapshot)], $spec, $idMaps);
		$this->assertSame(5, $own['account_id']);
		$this->assertSame(array_replace($snapshot, ['transactionIds' => [111]]), json_decode($own['received_undo_state'], true));

		// Paid into an account the backup doesn't hold: the credit isn't in
		// it, so there is nothing a revert could remove
		$foreign = $this->remapRow(['id' => 4, 'account_id' => 77, 'received_undo_state' => json_encode($snapshot)], $spec, $idMaps);
		$this->assertNull($foreign['received_undo_state']);

		$corrupt = $this->remapRow(['id' => 5, 'account_id' => 1, 'received_undo_state' => '{"transactionIds":[11]}'], $spec, $idMaps);
		$this->assertNull($corrupt['received_undo_state']);

		$none = $this->remapRow(['id' => 6, 'account_id' => 1, 'received_undo_state' => null], $spec, $idMaps);
		$this->assertNull($none['received_undo_state']);
	}

	/**
	 * Dismissing a payment on the unrecorded-payments card (#394) stores the
	 * bill's id inside the dismissal, and the restore copied it as it was.
	 * The payment came back on the card after every restore, and on a new
	 * server, where a different bill can get the old id, it could hide that
	 * bill's payment instead.
	 */
	public function testAnUnrecordedPaymentDismissalFollowsItsBill(): void {
		$spec = MigrationService::EXTRA_TABLES_POST['dismissed_sugg'];
		$idMaps = ['bills' => [40 => 500, 41 => 501]];
		$row = static fn (string $type, ?string $pattern) => [
			'id' => 9, 'suggestion_type' => $type, 'pattern' => $pattern,
			'pattern_hash' => $pattern === null ? 'x' : sha1($pattern), 'dismissed_at' => '2026-09-02 10:00:00',
		];

		$moved = $this->remapRow($row('unrecorded', '40:2026-09-01'), $spec, $idMaps);
		$this->assertSame('500:2026-09-01', $moved['pattern']);
		$this->assertSame(sha1('500:2026-09-01'), $moved['pattern_hash']);

		// The bill isn't in the backup: the dismissal can't apply to anything
		$this->assertNull($this->remapRow($row('unrecorded', '77:2026-09-01'), $spec, $idMaps));
		$this->assertNull($this->remapRow($row('unrecorded', 'nonsense'), $spec, $idMaps));
		$this->assertNull($this->remapRow($row('unrecorded', null), $spec, $idMaps));

		// Dismissed bill suggestions are keyed by the payee, not an id
		$suggestion = $row('bill', md5('netflix'));
		$this->assertSame($suggestion, $this->remapRow($suggestion, $spec, $idMaps));
	}

	private function remapRow(array $row, array $spec, array $idMaps): ?array {
		$method = new \ReflectionMethod($this->service, 'remapRow');
		$method->setAccessible(true);
		return $method->invoke($this->service, $row, $spec, $idMaps);
	}

	/**
	 * @return mixed the decoded contents of one file in a fresh export
	 */
	private function exportedFile(string $name): mixed {
		$path = tempnam(sys_get_temp_dir(), 'test_export_');
		file_put_contents($path, $this->service->exportAll('user1')['content']);
		$zip = new \ZipArchive();
		$zip->open($path);
		$content = $zip->getFromName($name);
		$zip->close();
		unlink($path);
		return json_decode((string)$content, true);
	}
}
