<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Migration;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Migration\Version001000114Date20261002;
use OCA\Budget\Service\AccountBalanceCalculator;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;

/**
 * Repair's "future cleared" fix used to switch any cleared row dated after
 * the server's today to scheduled, bill payments included (#399 review,
 * F101). A bill's scheduled row is its placeholder to everything else: the
 * job never clears it, and the next Mark Paid, Skip or Mark Unpaid deleted
 * it as one. A payment the bill's own snapshot names goes back to cleared;
 * the placeholder for the next occurrence stays pending.
 */
class RescheduledPaymentRestoreMigrationTest extends IntegrationTestCase {
	private function paidBill(int $accountId, array $snapshot): int {
		$bill = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Rent', 'amount' => '800.00', 'frequency' => 'monthly',
			'account_id' => $accountId, 'is_active' => true, 'due_day' => 1, 'next_due_date' => '2026-10-01',
			'last_paid_date' => '2026-09-01', 'created_at' => $this->now(),
		]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_bills SET paid_undo_state = ? WHERE id = ?', [
			json_encode($snapshot + ['previousState' => ['nextDueDate' => '2026-09-01'], 'createdTransactionIds' => [],
				'scheduledTransactionIds' => [], 'linkedTransactionId' => null, 'paidDate' => '2026-09-01']),
			$bill,
		]);
		return $bill;
	}

	private function row(int $accountId, int $billId, string $date, string $status, array $overrides = []): int {
		return $this->makeTransaction($accountId, $overrides + [
			'bill_id' => $billId, 'date' => $date, 'amount' => '800.00', 'type' => 'debit', 'status' => $status,
			'notes' => 'Auto-generated from bill: Rent',
		]);
	}

	public function testAPaymentRepairSwitchedToScheduledIsAPaymentAgain(): void {
		$account = $this->makeAccount(['openingBalance' => 1000.0, 'balance' => 1000.0]);
		$id = $account->getId();
		$bill = $this->paidBill($id, []);
		$payment = $this->row($id, $bill, '2026-09-01', 'scheduled');
		$placeholder = $this->row($id, $bill, '2026-10-01', 'scheduled');
		$this->db()->executeStatement('UPDATE *PREFIX*budget_bills SET paid_undo_state = ? WHERE id = ?', [
			json_encode(['previousState' => ['nextDueDate' => '2026-09-01'], 'createdTransactionIds' => [$payment],
				'scheduledTransactionIds' => [$placeholder], 'linkedTransactionId' => null, 'paidDate' => '2026-09-01']),
			$bill,
		]);

		$this->runMigration();

		$this->assertSame('cleared', $this->fetchRow('budget_transactions', $payment)['status']);
		$this->assertSame('scheduled', $this->fetchRow('budget_transactions', $placeholder)['status']);
		// And the balance counts the payment again
		$this->assertEqualsWithDelta(200.0, (float)\OCP\Server::get(AccountMapper::class)->findById($id)->getBalance(), 0.001);
	}

	public function testALinkedBankRowAndBothTransferLegsComeBack(): void {
		$from = $this->makeAccount()->getId();
		$to = $this->makeAccount(['name' => 'Savings', 'type' => 'savings'])->getId();
		$bankRow = $this->makeTransaction($from, ['date' => '2026-09-02', 'amount' => '50.00', 'status' => 'scheduled', 'notes' => null]);
		$out = $this->makeTransaction($from, ['date' => '2026-09-01', 'amount' => '200.00', 'status' => 'scheduled']);
		$in = $this->makeTransaction($to, ['date' => '2026-09-01', 'amount' => '200.00', 'type' => 'credit', 'status' => 'scheduled']);
		$this->paidBill($from, ['linkedTransactionId' => $bankRow]);
		$this->paidBill($from, ['createdTransactionIds' => [$out, $in]]);

		$this->runMigration();

		foreach ([$bankRow, $out, $in] as $row) {
			$this->assertSame('cleared', $this->fetchRow('budget_transactions', $row)['status']);
		}
	}

	public function testRowsNoSnapshotNamesAreLeftAlone(): void {
		$account = $this->makeAccount()->getId();
		$bill = $this->paidBill($account, []);
		$unknown = $this->row($account, $bill, '2026-09-01', 'scheduled');
		$manual = $this->makeTransaction($account, ['date' => '2099-01-01', 'amount' => '5.00', 'status' => 'scheduled']);

		$this->runMigration();

		$this->assertSame('scheduled', $this->fetchRow('budget_transactions', $unknown)['status']);
		$this->assertSame('scheduled', $this->fetchRow('budget_transactions', $manual)['status']);
	}

	private function runMigration(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$migration = new Version001000114Date20261002(
			$this->db(),
			\OCP\Server::get(AccountMapper::class),
			\OCP\Server::get(AccountBalanceCalculator::class),
		);
		$migration->postSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);
	}
}
