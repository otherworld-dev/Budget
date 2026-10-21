<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Migration;

use OCA\Budget\Db\DanglingLinks;
use OCA\Budget\Db\LegacyIncomeRows;
use OCA\Budget\Migration\Version001000125Date20261019;
use OCA\Budget\Service\RepairService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;

/**
 * What older versions left that 3.x clears up (#399), against the real
 * database: incomes 2.54.0 left expected on a receipt it had already
 * booked, references to deleted bills and transactions (migration 125), and
 * the doubled transfer Repair Data now removes with both its sides.
 *
 * The migration is global (it has no user scope), so it also settles and
 * clears other users' rows on the server it runs on; the assertions are
 * about this test's own rows only.
 */
class LegacyCleanupTest extends IntegrationTestCase {
	private const SBB = 'Gutschrift SBB Abo Leon';

	/** The reporter's SBB Abo income: due the 29th, received on the 28th */
	private function income(int $accountId, array $overrides = [], ?string $userId = null): int {
		return $this->insertRow('budget_recurring_income', $overrides + [
			'user_id' => $userId ?? $this->userId, 'name' => self::SBB, 'amount' => '100.00', 'frequency' => 'monthly',
			'expected_day' => 29, 'account_id' => $accountId, 'is_active' => true,
			'last_received_date' => '2026-09-28', 'next_expected_date' => '2026-09-29',
			'created_at' => '2026-09-27 21:00:13',
		]);
	}

	/** A credit the income booked: 2.54.0 dated it on the expected date */
	private function credit(int $accountId, string $date, string $createdAt, array $overrides = []): int {
		return $this->makeTransaction($accountId, $overrides + [
			'date' => $date, 'amount' => '100.00', 'type' => 'credit', 'vendor' => self::SBB, 'description' => '',
			'notes' => 'Auto-generated from income: ' . self::SBB, 'created_at' => $createdAt, 'updated_at' => $createdAt,
		]);
	}

	private function nextExpected(int $incomeId): string {
		return substr((string)$this->fetchRow('budget_recurring_income', $incomeId)['next_expected_date'], 0, 10);
	}

	public function testAnIncomeReceivedEarlyUnder254MovesOnToItsNextDate(): void {
		$account = $this->makeAccount()->getId();
		$income = $this->income($account);
		$credit = $this->credit($account, '2026-09-29', '2026-09-28 18:43:15');

		$this->runMigration();

		$this->assertSame('2026-10-29', $this->nextExpected($income));
		$row = $this->fetchRow('budget_recurring_income', $income);
		$this->assertSame('2026-09-28', substr((string)$row['last_received_date'], 0, 10));
		// Nothing booked or deleted
		$this->assertNotNull($this->fetchRow('budget_transactions', $credit));
		$this->assertSame(1, $this->countRows('budget_transactions', ['account_id' => $account]));
	}

	public function testOrdinaryReceiptsAreLeftAlone(): void {
		$account = $this->makeAccount()->getId();
		// Received on its date: 2.54.0 moved it on itself
		$onTheDay = $this->income($account, ['name' => 'On the day']);
		$this->credit($account, '2026-09-29', '2026-09-29 08:00:00', ['notes' => 'Auto-generated from income: On the day']);
		// Moved on since (skipped or received again)
		$movedOn = $this->income($account, ['name' => 'Moved on', 'next_expected_date' => '2026-10-29']);
		$this->credit($account, '2026-09-29', '2026-09-28 18:00:00', ['notes' => 'Auto-generated from income: Moved on']);
		// A credit another income booked
		$other = $this->income($account, ['name' => 'Other']);
		$this->credit($account, '2026-09-29', '2026-09-28 18:00:00', ['notes' => 'Auto-generated from income: Somebody else']);
		// The bank's own row, imported
		$imported = $this->income($account, ['name' => 'Imported']);
		$this->credit($account, '2026-09-29', '2026-09-28 18:00:00', ['notes' => 'Auto-generated from income: Imported', 'import_id' => 'csv-1']);
		// Switched off
		$paused = $this->income($account, ['name' => 'Paused', 'is_active' => false]);
		$this->credit($account, '2026-09-29', '2026-09-28 18:00:00', ['notes' => 'Auto-generated from income: Paused']);

		$this->runMigration();

		$this->assertSame('2026-09-29', $this->nextExpected($onTheDay));
		$this->assertSame('2026-10-29', $this->nextExpected($movedOn));
		$this->assertSame('2026-09-29', $this->nextExpected($other));
		$this->assertSame('2026-09-29', $this->nextExpected($imported));
		$this->assertSame('2026-09-29', $this->nextExpected($paused));
	}

	public function testStuckEarlyReceiptsReadsOneUsersIncomesOnly(): void {
		$mine = $this->makeAccount()->getId();
		$this->income($mine);
		$this->credit($mine, '2026-09-29', '2026-09-28 18:43:15');
		$someoneElse = $this->newUserId();
		$theirs = $this->makeAccount([], $someoneElse)->getId();
		$this->income($theirs, [], $someoneElse);
		$this->credit($theirs, '2026-09-29', '2026-09-28 18:43:15');

		$found = $this->service(LegacyIncomeRows::class)->stuckEarlyReceipts($this->userId);

		$this->assertCount(1, $found);
		$this->assertSame($this->userId, $found[0]['userId']);
		$this->assertSame('2026-10-29', $found[0]['settledNextExpectedDate']);
	}

	public function testADeletedBillsPaymentsLetGoOfItsId(): void {
		$account = $this->makeAccount()->getId();
		$live = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'KPT', 'amount' => '484.75', 'frequency' => 'monthly',
			'account_id' => $account, 'is_active' => true, 'due_day' => 29, 'next_due_date' => '2026-10-29',
			'created_at' => $this->now(),
		]);
		$gone = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Christian Uhrenschmuck24', 'amount' => '91.59', 'frequency' => 'monthly',
			'account_id' => $account, 'is_active' => true, 'due_day' => 29, 'next_due_date' => '2026-09-29',
			'created_at' => $this->now(),
		]);
		$livePayment = $this->makeTransaction($account, ['bill_id' => $live, 'date' => '2026-08-29']);
		$deadPayment = $this->makeTransaction($account, ['bill_id' => $gone, 'date' => '2026-08-29']);
		$deadPending = $this->makeTransaction($account, ['bill_id' => $gone, 'date' => '2026-09-29', 'status' => 'scheduled']);
		$this->db()->executeStatement('DELETE FROM *PREFIX*budget_bills WHERE id = ?', [$gone]);

		$this->runMigration();

		$this->assertSame($live, (int)$this->fetchRow('budget_transactions', $livePayment)['bill_id']);
		$this->assertNull($this->fetchRow('budget_transactions', $deadPayment)['bill_id']);
		// Without its bill's id, the scheduled job would book it on its date
		$this->assertSame($gone, (int)$this->fetchRow('budget_transactions', $deadPending)['bill_id']);
	}

	/** The reporter's July CSX payment: its deposit was deleted by a version before 2.41 */
	public function testATransferSideWhoseOtherSideIsGoneLetsGoOfIt(): void {
		$account = $this->makeAccount()->getId();
		$card = $this->makeAccount(['name' => 'CSX Pro Credit Card', 'type' => 'credit_card'])->getId();
		[$out, $in] = $this->transferPair($account, $card, null, '2026-06-28', '2026-06-28 12:12:34');
		[$orphanOut, $deletedIn] = $this->transferPair($account, $card, null, '2026-07-25', '2026-06-28 12:12:34');
		$this->db()->executeStatement('DELETE FROM *PREFIX*budget_transactions WHERE id = ?', [$deletedIn]);

		$this->runMigration();

		$this->assertNull($this->fetchRow('budget_transactions', $orphanOut)['linked_transaction_id']);
		$this->assertSame($in, (int)$this->fetchRow('budget_transactions', $out)['linked_transaction_id']);
		$this->assertSame($out, (int)$this->fetchRow('budget_transactions', $in)['linked_transaction_id']);
	}

	public function testRunningItTwiceChangesNothingMore(): void {
		$account = $this->makeAccount()->getId();
		$income = $this->income($account);
		$this->credit($account, '2026-09-29', '2026-09-28 18:43:15');

		$this->runMigration();
		$this->runMigration();

		$this->assertSame('2026-10-29', $this->nextExpected($income));
	}

	/**
	 * CSX in August: the old job cleared the pending pair on the 29th, Mark
	 * Paid booked another on the 30th. Repair Data offers the job's pair and
	 * removes both its sides.
	 */
	public function testRepairDataRemovesBothSidesOfADoubledTransfer(): void {
		$account = $this->makeAccount(['name' => 'Lohnkonto'])->getId();
		$card = $this->makeAccount(['name' => 'CSX Pro Credit Card', 'type' => 'credit_card'])->getId();
		$bill = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'CSX Pro Credit Cards', 'amount' => '50.00', 'frequency' => 'monthly',
			'account_id' => $account, 'destination_account_id' => $card, 'is_transfer' => true, 'is_active' => true,
			'due_day' => 28, 'last_paid_date' => '2026-08-30', 'next_due_date' => '2026-09-28', 'created_at' => '2026-04-15 19:36:17',
		]);
		[$jobOut, $jobIn] = $this->transferPair($account, $card, $bill, '2026-08-29', '2026-07-25 10:55:38', '2026-08-29 01:00:07');
		[$paidOut, $paidIn] = $this->transferPair($account, $card, $bill, '2026-08-30', '2026-08-30 19:39:30');
		$repair = $this->service(RepairService::class);

		$found = array_values(array_filter(
			$repair->diagnose($this->userId)['duplicateTransactions'],
			fn (array $f) => $f['billId'] === $bill
		));

		$this->assertCount(1, $found);
		$this->assertSame($jobOut, $found[0]['duplicateId']);
		$this->assertSame($paidOut, $found[0]['originalId']);
		$this->assertTrue($found[0]['transfer']);
		$this->assertTrue($found[0]['removesDeposit']);

		$result = $repair->repair($this->userId, ['duplicateTransactions'], ['transactionIds' => [$jobOut]]);

		$this->assertSame(1, $result['duplicateTransactions']['deleted']);
		$this->assertNull($this->fetchRow('budget_transactions', $jobOut));
		$this->assertNull($this->fetchRow('budget_transactions', $jobIn));
		$this->assertNotNull($this->fetchRow('budget_transactions', $paidOut));
		$this->assertNotNull($this->fetchRow('budget_transactions', $paidIn));
	}

	/**
	 * The user's transfer bill pays into Leon's card, which Leon shares with
	 * the user read-only. Removing the doubled withdrawal would delete its
	 * deposit in Leon's ledger, which Mark Unpaid refuses to do, so Repair
	 * Data neither lists the pair nor removes it when asked to.
	 */
	public function testRepairDataLeavesATransferIntoAnAccountSharedReadOnlyAlone(): void {
		[$bill, $rows] = $this->doubledTransferIntoLeonsCard('read');
		$repair = $this->service(RepairService::class);

		$this->assertSame([], $this->findingsFor($repair, $bill));

		$result = $repair->repair($this->userId, ['duplicateTransactions'], ['transactionIds' => [$rows[0]]]);

		$this->assertSame(0, $result['duplicateTransactions']['deleted']);
		foreach ($rows as $id) {
			$this->assertNotNull($this->fetchRow('budget_transactions', $id));
		}
	}

	public function testRepairDataRemovesBothSidesOfADoubledTransferIntoAnAccountSharedWithWriteAccess(): void {
		[$bill, [$jobOut, $jobIn, $paidOut, $paidIn]] = $this->doubledTransferIntoLeonsCard('write');
		$repair = $this->service(RepairService::class);

		$this->assertSame([$jobOut], array_column($this->findingsFor($repair, $bill), 'duplicateId'));

		$result = $repair->repair($this->userId, ['duplicateTransactions'], ['transactionIds' => [$jobOut]]);

		$this->assertSame(1, $result['duplicateTransactions']['deleted']);
		$this->assertNull($this->fetchRow('budget_transactions', $jobOut));
		$this->assertNull($this->fetchRow('budget_transactions', $jobIn));
		$this->assertNotNull($this->fetchRow('budget_transactions', $paidOut));
		$this->assertNotNull($this->fetchRow('budget_transactions', $paidIn));
	}

	/**
	 * CSX's doubled August payment, but paying into a card of Leon's that he
	 * shares with the user at $permission.
	 *
	 * @return array{0: int, 1: int[]} the bill, and [job's withdrawal, its deposit, Mark Paid's withdrawal, its deposit]
	 */
	private function doubledTransferIntoLeonsCard(string $permission): array {
		$leon = $this->newUserId();
		$account = $this->makeAccount(['name' => 'Lohnkonto'])->getId();
		$card = $this->makeAccount(['name' => 'Leon CSX', 'type' => 'credit_card'], $leon)->getId();
		$share = $this->insertRow('budget_shares', [
			'owner_user_id' => $leon, 'shared_with_user_id' => $this->userId, 'status' => 'accepted',
			'created_at' => $this->now(), 'updated_at' => $this->now(),
		]);
		$this->insertRow('budget_share_items', [
			'share_id' => $share, 'entity_type' => 'account', 'entity_id' => $card,
			'permission' => $permission, 'created_at' => $this->now(), 'updated_at' => $this->now(),
		]);
		$bill = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'CSX Pro Credit Cards', 'amount' => '50.00', 'frequency' => 'monthly',
			'account_id' => $account, 'destination_account_id' => $card, 'is_transfer' => true, 'is_active' => true,
			'due_day' => 28, 'last_paid_date' => '2026-08-30', 'next_due_date' => '2026-09-28', 'created_at' => '2026-04-15 19:36:17',
		]);
		$job = $this->transferPair($account, $card, $bill, '2026-08-29', '2026-07-25 10:55:38', '2026-08-29 01:00:07');
		$paid = $this->transferPair($account, $card, $bill, '2026-08-30', '2026-08-30 19:39:30');
		return [$bill, [...$job, ...$paid]];
	}

	private function findingsFor(RepairService $repair, int $billId): array {
		return array_values(array_filter(
			$repair->diagnose($this->userId)['duplicateTransactions'],
			fn (array $f) => $f['billId'] === $billId
		));
	}

	public function testRepairDataSettlesAStuckIncome(): void {
		$account = $this->makeAccount()->getId();
		$income = $this->income($account);
		$this->credit($account, '2026-09-29', '2026-09-28 18:43:15');
		$repair = $this->service(RepairService::class);

		$this->assertSame([$income], array_column($repair->diagnose($this->userId)['stuckIncome'], 'incomeId'));

		$result = $repair->repair($this->userId, ['stuckIncome']);

		$this->assertSame(['fixed' => 1, 'found' => 1], $result['stuckIncome']);
		$this->assertSame('2026-10-29', $this->nextExpected($income));
		$this->assertSame([], $repair->diagnose($this->userId)['stuckIncome']);
	}

	/**
	 * Both sides of a transfer a bill booked, linked to each other.
	 *
	 * @return array{0: int, 1: int} [withdrawal, deposit]
	 */
	private function transferPair(int $from, int $to, ?int $billId, string $date, string $createdAt, ?string $updatedAt = null): array {
		$row = [
			'bill_id' => $billId, 'date' => $date, 'amount' => '50.00', 'vendor' => 'CSX Pro Credit Cards', 'description' => '',
			'notes' => 'Auto-generated transfer: CSX Pro Credit Cards', 'created_at' => $createdAt, 'updated_at' => $updatedAt ?? $createdAt,
		];
		$out = $this->makeTransaction($from, $row);
		$in = $this->makeTransaction($to, $row + ['type' => 'credit', 'linked_transaction_id' => $out]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_transactions SET linked_transaction_id = ? WHERE id = ?', [$in, $out]);
		return [$out, $in];
	}

	private function runMigration(): void {
		// getTable() is declared to return Doctrine's Table on older servers
		// and OCP\DB\Schema\ITable on newer ones (NC 35.0.2 on): hand back a
		// mock of whichever this server declares
		$type = (new \ReflectionMethod(ISchemaWrapper::class, 'getTable'))->getReturnType();
		$tableClass = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
		if ($tableClass !== null) {
			$table = $this->createMock($tableClass);
			$table->method('hasColumn')->willReturn(true);
		} else {
			$table = new class {
				public function hasColumn(string $name): bool {
					return true;
				}
			};
		}
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);
		$migration = new Version001000125Date20261019(
			$this->service(LegacyIncomeRows::class),
			$this->service(DanglingLinks::class),
		);
		$migration->postSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);
	}
}
