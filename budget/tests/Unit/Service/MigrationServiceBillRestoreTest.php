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

		$db = $this->createMock(IDBConnection::class);
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
}
