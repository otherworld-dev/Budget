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
 * A bill marked paid with a payment the ledger already held names that row
 * in its snapshot (#399). A restore gives the row a new id, and the snapshot
 * follows it there, as it does for a row the payment linked.
 */
class MigrationServiceUsedPaymentTest extends TestCase {
	private MigrationService $service;
	/** @var Bill[] bills handed to insert(), in order */
	private array $inserted = [];

	protected function setUp(): void {
		$bills = $this->createMock(BillMapper::class);
		$nextId = 500;
		$bills->method('insert')->willReturnCallback(function (Bill $b) use (&$nextId) {
			$b->setId($nextId++);
			$this->inserted[] = $b;
			return $b;
		});
		$this->service = new MigrationService(
			$this->createMock(AccountMapper::class),
			$this->createMock(TransactionMapper::class),
			$this->createMock(CategoryMapper::class),
			$bills,
			$this->createMock(ImportRuleMapper::class),
			$this->createMock(SettingMapper::class),
			$this->createMock(IDBConnection::class)
		);
	}

	private function importBills(array $bills, array $idMaps): void {
		$method = new \ReflectionMethod($this->service, 'importBills');
		$method->setAccessible(true);
		$method->invoke($this->service, 'user1', $bills, $idMaps + ['accounts' => [], 'categories' => [], 'tags' => [], 'transactions' => []]);
	}

	private function snapshot(?int $used): array {
		return [
			'previousState' => ['lastPaidDate' => '2026-09-27', 'nextDueDate' => '2026-09-28', 'isActive' => true],
			'createdTransactionIds' => [],
			'scheduledTransactionIds' => [],
			'linkedTransactionId' => null,
			'usedTransactionId' => $used,
			'hadScheduledTransaction' => false,
			'paidDate' => '2026-09-27',
		];
	}

	public function testTheUsedPaymentPointsAtTheRestoredRow(): void {
		$this->importBills([
			['id' => 40, 'name' => 'CembraPay Temu', 'amount' => 151.67, 'accountId' => 1, 'frequency' => 'one-time',
				'startDate' => '2026-09-28', 'paidUndoState' => $this->snapshot(21)],
		], ['accounts' => [1 => 5], 'transactions' => [21 => 121]]);

		$restored = json_decode((string)$this->inserted[0]->getPaidUndoState(), true);
		$this->assertSame(121, $restored['usedTransactionId']);
		$this->assertTrue($this->inserted[0]->canMarkUnpaid());
	}

	public function testAUsedPaymentTheBackupDoesNotHoldIsLetGo(): void {
		// Deleted before the backup was made: the snapshot names nothing
		$this->importBills([
			['id' => 40, 'name' => 'CembraPay Temu', 'amount' => 151.67, 'accountId' => 1, 'frequency' => 'one-time',
				'startDate' => '2026-09-28', 'paidUndoState' => $this->snapshot(21)],
		], ['accounts' => [1 => 5]]);

		$restored = json_decode((string)$this->inserted[0]->getPaidUndoState(), true);
		$this->assertNull($restored['usedTransactionId']);
	}
}
