<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Migration;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Migration\Version001000109Date20261002;
use OCA\Budget\Service\AccountBalanceCalculator;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;

/**
 * The scheduled job used to clear a bill's pre-booked row on its due date
 * while the bill stayed unpaid. The row for an occurrence still unpaid now
 * goes back to pending, so paying the bill records it once; rows of
 * occurrences already paid, and anything reconciled, are left alone.
 */
class UnpaidPlaceholderRestoreMigrationTest extends IntegrationTestCase {
	private function bill(int $accountId, string $nextDue, array $overrides = []): int {
		return $this->insertRow('budget_bills', $overrides + [
			'user_id' => $this->userId, 'name' => 'Rent', 'amount' => '800.00', 'frequency' => 'monthly',
			'account_id' => $accountId, 'is_active' => true, 'due_day' => 1, 'next_due_date' => $nextDue,
			'created_at' => $this->now(),
		]);
	}

	private function row(int $accountId, int $billId, string $date, array $overrides = []): int {
		return $this->makeTransaction($accountId, $overrides + [
			'bill_id' => $billId, 'date' => $date, 'amount' => '800.00',
			'notes' => 'Auto-generated from bill: Rent',
		]);
	}

	public function testTheClearedRowOfTheUnpaidOccurrenceGoesBackToPending(): void {
		$account = $this->makeAccount(['openingBalance' => 1000.0, 'balance' => 200.0]);
		$bill = $this->bill($account->getId(), '2026-09-01');
		$stuck = $this->row($account->getId(), $bill, '2026-09-01');
		// August was paid: its payment and the bill's next due date differ
		$paid = $this->row($account->getId(), $bill, '2026-08-03');

		$this->runMigration();

		$this->assertSame('scheduled', $this->fetchRow('budget_transactions', $stuck)['status']);
		$this->assertSame('cleared', $this->fetchRow('budget_transactions', $paid)['status']);
		// The balance no longer counts the unpaid September occurrence
		$this->assertEqualsWithDelta(200.0, (float)\OCP\Server::get(AccountMapper::class)->findById($account->getId())->getBalance(), 0.001);
	}

	/**
	 * A weekly bill due 1 September, paid a week late: the payment is dated
	 * the 8th, which is also the next occurrence. The bill's snapshot names
	 * it as the payment, so it stays a payment.
	 */
	public function testAPaymentOnTheNextDueDateIsLeftAlone(): void {
		$account = $this->makeAccount()->getId();
		$bill = $this->bill($account, '2026-09-08', ['frequency' => 'weekly', 'due_day' => 2]);
		$payment = $this->row($account, $bill, '2026-09-08');
		$this->db()->executeStatement('UPDATE *PREFIX*budget_bills SET paid_undo_state = ? WHERE id = ?', [
			json_encode(['previousState' => ['nextDueDate' => '2026-09-01'], 'createdTransactionIds' => [$payment],
				'scheduledTransactionIds' => [], 'linkedTransactionId' => null, 'paidDate' => '2026-09-08']),
			$bill,
		]);

		$this->runMigration();

		$this->assertSame('cleared', $this->fetchRow('budget_transactions', $payment)['status']);
	}

	/**
	 * 2.54.0: a weekly bill due 26 September is marked paid on 3 October
	 * without recording a payment, so the paid date and the next due date
	 * are both the 3rd. Record transaction then books the payment on the
	 * 3rd, and 2.54.0 never named that row in the snapshot. It is a real
	 * payment and stays booked; only the row the snapshot names as pending
	 * goes back.
	 */
	public function testA254PaymentOnTheLastPaidDateIsLeftAlone(): void {
		$account = $this->makeAccount()->getId();
		$bill = $this->bill($account, '2026-10-03', ['frequency' => 'weekly', 'due_day' => 6, 'last_paid_date' => '2026-10-03']);
		$pending = $this->row($account, $bill, '2026-10-03');
		$recorded = $this->row($account, $bill, '2026-10-03');
		$this->db()->executeStatement('UPDATE *PREFIX*budget_bills SET paid_undo_state = ? WHERE id = ?', [
			json_encode(['previousState' => ['nextDueDate' => '2026-09-26'], 'createdTransactionIds' => [],
				'scheduledTransactionIds' => [$pending], 'linkedTransactionId' => null, 'paidDate' => '2026-10-03']),
			$bill,
		]);

		$this->runMigration();

		$this->assertSame('cleared', $this->fetchRow('budget_transactions', $recorded)['status']);
		$this->assertSame('scheduled', $this->fetchRow('budget_transactions', $pending)['status']);
	}

	/**
	 * Skip wipes the snapshot, so after skip and undo skip on a bill paid
	 * late nothing says which row is pending. A row on the last paid date
	 * is then kept as the payment.
	 */
	public function testARowOnTheLastPaidDateWithNoSnapshotIsLeftAlone(): void {
		$account = $this->makeAccount()->getId();
		$bill = $this->bill($account, '2026-10-03', ['frequency' => 'weekly', 'due_day' => 6, 'last_paid_date' => '2026-10-03']);
		$payment = $this->row($account, $bill, '2026-10-03');

		$this->runMigration();

		$this->assertSame('cleared', $this->fetchRow('budget_transactions', $payment)['status']);
	}

	public function testBothLegsOfAnUnpaidTransferGoBack(): void {
		$from = $this->makeAccount()->getId();
		$to = $this->makeAccount(['name' => 'Savings', 'type' => 'savings'])->getId();
		$bill = $this->bill($from, '2026-09-01', ['is_transfer' => true, 'destination_account_id' => $to]);
		$out = $this->row($from, $bill, '2026-09-01', ['notes' => 'Auto-generated transfer: Rent']);
		$in = $this->row($to, $bill, '2026-09-01', ['notes' => 'Auto-generated transfer: Rent', 'type' => 'credit']);

		$this->runMigration();

		$this->assertSame('scheduled', $this->fetchRow('budget_transactions', $out)['status']);
		$this->assertSame('scheduled', $this->fetchRow('budget_transactions', $in)['status']);
	}

	public function testReconciledImportedAndInactiveRowsAreLeftAlone(): void {
		$account = $this->makeAccount()->getId();
		$bill = $this->bill($account, '2026-09-01');
		$reconciled = $this->row($account, $bill, '2026-09-01', ['reconciled' => true]);
		// A bank row the user linked to the bill: never one the app generated
		$imported = $this->row($account, $bill, '2026-09-01', ['notes' => null]);
		$ended = $this->bill($account, '2026-09-01', ['is_active' => false]);
		$endedRow = $this->row($account, $ended, '2026-09-01');

		$this->runMigration();

		foreach ([$reconciled, $imported, $endedRow] as $id) {
			$this->assertSame('cleared', $this->fetchRow('budget_transactions', $id)['status']);
		}
	}

	private function runMigration(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$migration = new Version001000109Date20261002(
			$this->db(),
			\OCP\Server::get(AccountMapper::class),
			\OCP\Server::get(AccountBalanceCalculator::class),
		);
		$migration->postSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);
	}
}
