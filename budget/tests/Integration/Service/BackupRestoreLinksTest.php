<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\BillService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\MigrationService;
use OCA\Budget\Tests\Integration\FullDataset;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Backup restore against the real database, for what a restore used to lose
 * or leave pointing at dead ids: the links between two users (shares, bills
 * on a shared account and the rows they book there) and the ids inside a
 * bill's own JSON. The decisions are unit tested in CrossUserLinksTest,
 * MigrationServiceCrossUserTest and MigrationServiceBillRestoreTest; this
 * proves the SQL behind them.
 */
class BackupRestoreLinksTest extends IntegrationTestCase {
	use FullDataset;

	private MigrationService $migration;
	private string $bob;

	protected function setUp(): void {
		parent::setUp();
		$this->migration = $this->service(MigrationService::class);
		$this->bob = $this->newUserId();
	}

	/**
	 * Alice ($this->userId) shares her joint account (write) and her Food
	 * category with Bob. Bob's Netflix bill pays from the joint account and
	 * is filed under Food; its last payment and its next pending row sit in
	 * the joint account, and its Mark Unpaid snapshot names both.
	 *
	 * @return array<string, int>
	 */
	private function sharedWorld(): array {
		$now = $this->now();
		$ids = [];
		$ids['joint'] = $this->makeAccount(['name' => 'Joint'])->getId();
		$ids['bobAccount'] = $this->makeAccount(['name' => 'Bob current'], $this->bob)->getId();
		$ids['food'] = $this->makeCategory(['name' => 'Food']);
		$ids['share'] = $this->insertRow('budget_shares', [
			'owner_user_id' => $this->userId, 'shared_with_user_id' => $this->bob, 'status' => 'accepted',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$ids['accountItem'] = $this->insertRow('budget_share_items', [
			'share_id' => $ids['share'], 'entity_type' => 'account', 'entity_id' => $ids['joint'], 'permission' => 'write',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$ids['categoryItem'] = $this->insertRow('budget_share_items', [
			'share_id' => $ids['share'], 'entity_type' => 'category', 'entity_id' => $ids['food'], 'permission' => 'read',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$ids['netflix'] = $this->insertRow('budget_bills', [
			'user_id' => $this->bob, 'name' => 'Netflix', 'amount' => '9.99', 'frequency' => 'monthly', 'due_day' => 15,
			'account_id' => $ids['joint'], 'category_id' => $ids['food'], 'is_active' => true, 'auto_pay_enabled' => true,
			'next_due_date' => '2026-10-15', 'last_paid_date' => '2026-09-15', 'created_at' => $now,
		]);
		$ids['pending'] = $this->makeTransaction($ids['joint'], [
			'bill_id' => $ids['netflix'], 'status' => 'scheduled', 'date' => '2026-10-15', 'amount' => '9.99', 'description' => 'Netflix',
		]);
		$ids['paid'] = $this->makeTransaction($ids['joint'], [
			'bill_id' => $ids['netflix'], 'date' => '2026-09-15', 'amount' => '9.99', 'description' => 'Netflix',
		]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_bills SET paid_undo_state = ? WHERE id = ?', [
			json_encode([
				'previousState' => ['lastPaidDate' => '2026-08-15', 'nextDueDate' => '2026-09-15', 'remainingPayments' => null,
					'isActive' => true, 'autoPayFailed' => false, 'amount' => 9.99],
				'createdTransactionIds' => [$ids['paid']],
				'scheduledTransactionIds' => [$ids['pending']],
				'linkedTransactionId' => null,
				'hadScheduledTransaction' => true,
				'paidDate' => '2026-09-15',
			]),
			$ids['netflix'],
		]);
		return $ids;
	}

	private function idOf(string $table, string $userId, string $name): int {
		return (int)$this->db()->executeQuery(
			'SELECT id FROM *PREFIX*' . $table . ' WHERE user_id = ? AND name = ?', [$userId, $name]
		)->fetchOne();
	}

	/**
	 * @return array<int, string> id => status of the rows carrying this bill
	 */
	private function billRows(int $billId): array {
		$rows = $this->db()->executeQuery(
			'SELECT id, status FROM *PREFIX*budget_transactions WHERE bill_id = ? ORDER BY id', [$billId]
		)->fetchAll();
		return array_column($rows, 'status', 'id');
	}

	private function snapshotOf(int $billId): ?array {
		$raw = $this->fetchRow('budget_bills', $billId)['paid_undo_state'] ?? null;
		return $raw === null ? null : json_decode((string)$raw, true);
	}

	/**
	 * Alice restores her own backup. Her joint account and Food category
	 * come back under new ids, and what she shares, Bob's bill on them, the
	 * rows it booked in her account and its Mark Unpaid all follow.
	 */
	public function testAnOwnersRestoreMovesHerSharesAndWhatUsesThem(): void {
		$w = $this->sharedWorld();

		$result = $this->migration->importAll($this->userId, $this->migration->exportAll($this->userId)['content']);

		$joint = $this->idOf('budget_accounts', $this->userId, 'Joint');
		$food = $this->idOf('budget_categories', $this->userId, 'Food');
		$this->assertNotSame($w['joint'], $joint, 'A restore gives the account a new id');
		$this->assertSame([], $result['warnings']);

		$this->assertSame($joint, (int)$this->fetchRow('budget_share_items', $w['accountItem'])['entity_id']);
		$this->assertSame($food, (int)$this->fetchRow('budget_share_items', $w['categoryItem'])['entity_id']);
		$this->assertTrue($this->service(GranularShareService::class)->canWrite($this->bob, 'account', $joint));

		$netflix = $this->fetchRow('budget_bills', $w['netflix']);
		$this->assertSame($joint, (int)$netflix['account_id']);
		$this->assertSame($food, (int)$netflix['category_id']);
		$this->assertTrue((bool)$netflix['auto_pay_enabled']);

		// Both rows came back in the restored account, still Bob's bill's
		$rows = array_map('strval', $this->billRows($w['netflix']));
		$this->assertCount(2, $rows);
		foreach (array_keys($rows) as $id) {
			$this->assertSame($joint, (int)$this->fetchRow('budget_transactions', $id)['account_id']);
		}
		$paid = array_search('cleared', $rows, true);
		$pending = array_search('scheduled', $rows, true);
		$this->assertIsInt($paid);
		$this->assertIsInt($pending);
		$snapshot = $this->snapshotOf($w['netflix']);
		$this->assertSame([$paid], $snapshot['createdTransactionIds']);
		$this->assertSame([$pending], $snapshot['scheduledTransactionIds']);

		$this->assertSame([], $this->danglingReferences());
	}

	/**
	 * Bob restores his own backup while Alice still shares the joint account.
	 * His bill used to come back with no account (auto-pay silently stopped
	 * and Mark Paid recorded nothing), and its rows in her account kept the
	 * old bill id.
	 */
	public function testARecipientsRestoreKeepsTheSharedAccountAndItsRows(): void {
		$w = $this->sharedWorld();

		$result = $this->migration->importAll($this->bob, $this->migration->exportAll($this->bob)['content']);

		$this->assertSame([], $result['warnings']);
		$netflixId = $this->idOf('budget_bills', $this->bob, 'Netflix');
		$this->assertNotSame($w['netflix'], $netflixId);
		$netflix = $this->fetchRow('budget_bills', $netflixId);
		$this->assertSame($w['joint'], (int)$netflix['account_id']);
		$this->assertSame($w['food'], (int)$netflix['category_id']);
		$this->assertTrue((bool)$netflix['auto_pay_enabled']);

		$this->assertSame([$w['pending'] => 'scheduled', $w['paid'] => 'cleared'], array_map('strval', $this->billRows($netflixId)));
		$snapshot = $this->snapshotOf($netflixId);
		$this->assertSame([$w['paid']], $snapshot['createdTransactionIds']);
		$this->assertSame([$w['pending']], $snapshot['scheduledTransactionIds']);

		// And Mark Unpaid takes back the payment in Alice's account
		$bill = $this->service(BillService::class)->markUnpaid($netflixId, $this->bob);
		$this->assertSame('2026-08-15', $bill->getLastPaidDate());
		$this->assertNull($this->fetchRow('budget_transactions', $w['paid']));

		$this->assertSame([], $this->danglingReferences());
	}

	/**
	 * Bob restores a backup from before he set up Netflix. The restore
	 * deletes his bills, and only rows in his own accounts went with them:
	 * the bill's pending row stayed in Alice's account under a dead bill
	 * id, and the scheduled-row job booked it against her on its date
	 * (#399 review, F139). The payment it already made stays, as money that
	 * really moved.
	 */
	public function testARecipientsRestoreWithoutTheBillTakesItsPendingRow(): void {
		$w = $this->sharedWorld();
		$archive = $this->rewriteArchive($this->migration->exportAll($this->bob)['content'], 'bills.json',
			fn (array $bills) => array_values(array_filter($bills, fn (array $bill) => $bill['name'] !== 'Netflix')));

		$this->migration->importAll($this->bob, $archive);

		$this->assertNull($this->fetchRow('budget_transactions', $w['pending']));
		$this->assertNotNull($this->fetchRow('budget_transactions', $w['paid']));
		$this->assertSame(0, (int)$this->db()->executeQuery(
			'SELECT COUNT(*) FROM *PREFIX*budget_transactions WHERE account_id = ? AND status = ?', [$w['joint'], 'scheduled']
		)->fetchOne());
	}

	/**
	 * A backup whose joint account isn't the one Alice has here (from
	 * another server, say) can't vouch for anything that hung off it: the
	 * share of it goes, Bob's bill loses it and stops auto-paying, and the
	 * pending row is not restored as one nothing could clear.
	 */
	public function testARestoreThatCannotVouchForTheAccountCutsItsLinks(): void {
		$w = $this->sharedWorld();
		$archive = $this->rewriteArchive($this->migration->exportAll($this->userId)['content'], 'accounts.json', function (array $accounts) {
			foreach ($accounts as &$account) {
				if ($account['name'] === 'Joint') {
					$account['createdAt'] = '2024-02-02 08:00:00';
				}
			}
			return $accounts;
		});

		$result = $this->migration->importAll($this->userId, $archive);

		$this->assertNull($this->fetchRow('budget_share_items', $w['accountItem']));
		$this->assertSame($this->idOf('budget_categories', $this->userId, 'Food'), (int)$this->fetchRow('budget_share_items', $w['categoryItem'])['entity_id']);

		$netflix = $this->fetchRow('budget_bills', $w['netflix']);
		$this->assertNull($netflix['account_id']);
		$this->assertFalse((bool)$netflix['auto_pay_enabled']);
		$this->assertNull($netflix['paid_undo_state']);

		$this->assertSame([], $this->billRows($w['netflix']));
		$this->assertSame(0, (int)$this->db()->executeQuery(
			'SELECT COUNT(*) FROM *PREFIX*budget_transactions t INNER JOIN *PREFIX*budget_accounts a ON a.id = t.account_id'
			. ' WHERE a.user_id = ? AND t.status = ?', [$this->userId, 'scheduled']
		)->fetchOne(), 'The pending row of a bill nothing restored links to is left out');

		$this->assertCount(2, $result['warnings']);
		$this->assertSame([], $this->danglingReferences());
	}

	/**
	 * The ids inside a bill's JSON follow the restore, and so do the
	 * reminder state and the one-time due date the bill editor needs.
	 */
	public function testABillsOwnStateFollowsTheRestore(): void {
		$now = $this->now();
		$current = $this->makeAccount(['name' => 'Current'])->getId();
		$house = $this->makeCategory(['name' => 'House']);
		$garage = $this->makeCategory(['name' => 'Garage']);
		$councilTax = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Council tax', 'amount' => '45.00', 'frequency' => 'monthly', 'due_day' => 1,
			'account_id' => $current, 'is_active' => true, 'created_at' => $now, 'next_due_date' => '2026-10-01',
			'last_paid_date' => '2026-09-01', 'reminder_days' => 3, 'last_reminder_sent' => '2026-09-28 06:00:00',
			'split_template' => json_encode([['categoryId' => $house, 'amount' => 30.5], ['categoryId' => $garage, 'amount' => 14.5]]),
		]);
		$paid = $this->makeTransaction($current, ['bill_id' => $councilTax, 'date' => '2026-09-01', 'amount' => '45.00']);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_bills SET paid_undo_state = ? WHERE id = ?', [
			json_encode([
				'previousState' => ['lastPaidDate' => '2026-08-01', 'nextDueDate' => '2026-09-01', 'isActive' => true, 'amount' => 45.0],
				'createdTransactionIds' => [$paid], 'scheduledTransactionIds' => [], 'linkedTransactionId' => null,
				'hadScheduledTransaction' => false, 'paidDate' => '2026-09-01',
			]),
			$councilTax,
		]);
		$invoice = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Invoice', 'amount' => '60.00', 'frequency' => 'one-time', 'due_day' => 15,
			'due_month' => 8, 'is_active' => false, 'created_at' => $now, 'last_paid_date' => '2026-08-14',
		]);
		$this->insertRow('budget_dismissed_sugg', [
			'user_id' => $this->userId, 'suggestion_type' => 'unrecorded', 'pattern' => $invoice . ':2026-08-14',
			'pattern_hash' => sha1($invoice . ':2026-08-14'), 'dismissed_at' => $now,
		]);
		$target = $this->newUserId();

		$this->migration->importAll($target, $this->migration->exportAll($this->userId)['content']);

		$restoredTax = $this->fetchRow('budget_bills', $this->idOf('budget_bills', $target, 'Council tax'));
		$this->assertSame(
			[['categoryId' => $this->idOf('budget_categories', $target, 'House'), 'amount' => 30.5],
				['categoryId' => $this->idOf('budget_categories', $target, 'Garage'), 'amount' => 14.5]],
			json_decode((string)$restoredTax['split_template'], true)
		);
		$this->assertSame('2026-09-28 06:00:00', substr((string)$restoredTax['last_reminder_sent'], 0, 19));
		$restoredPaid = (int)$this->db()->executeQuery(
			'SELECT id FROM *PREFIX*budget_transactions WHERE bill_id = ?', [(int)$restoredTax['id']]
		)->fetchOne();
		$this->assertSame([$restoredPaid], json_decode((string)$restoredTax['paid_undo_state'], true)['createdTransactionIds']);

		$bill = $this->service(BillService::class)->markUnpaid((int)$restoredTax['id'], $target);
		$this->assertSame('2026-08-01', $bill->getLastPaidDate());
		$this->assertNull($this->fetchRow('budget_transactions', $restoredPaid));

		$restoredInvoice = $this->idOf('budget_bills', $target, 'Invoice');
		$this->assertSame('2026-08-15', substr((string)$this->fetchRow('budget_bills', $restoredInvoice)['start_date'], 0, 10));
		$this->assertSame(1, $this->countRows('budget_dismissed_sugg', [
			'user_id' => $target, 'suggestion_type' => 'unrecorded', 'pattern_hash' => sha1($restoredInvoice . ':2026-08-14'),
		]));
	}

	/**
	 * @param callable(array): array $change
	 */
	private function rewriteArchive(string $zipContent, string $file, callable $change): string {
		$path = tempnam(sys_get_temp_dir(), 'budget-it-');
		file_put_contents($path, $zipContent);
		$zip = new \ZipArchive();
		$zip->open($path);
		$zip->addFromString($file, json_encode($change(json_decode((string)$zip->getFromName($file), true))));
		$zip->close();
		$content = (string)file_get_contents($path);
		unlink($path);
		return $content;
	}
}
