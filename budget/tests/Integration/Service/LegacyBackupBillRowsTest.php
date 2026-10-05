<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\AccountBalanceCalculator;
use OCA\Budget\Service\MigrationService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Upgrading to 3.0 repairs two kinds of bill row that 2.54.0 and older left
 * behind (migrations 109 and 114). A backup made by those versions holds the
 * same rows, so restoring it into 3.0, the usual way to move servers, used
 * to bring them back unrepaired: an unpaid occurrence counted as paid in the
 * balance, and paying it booked it a second time (R1-5, T1-1). A restored
 * user now ends up like an upgraded one. A 3.0 backup was made from repaired
 * data and is restored as it is.
 */
class LegacyBackupBillRowsTest extends IntegrationTestCase {
	private MigrationService $migration;

	protected function setUp(): void {
		parent::setUp();
		$this->migration = $this->service(MigrationService::class);
	}

	/**
	 * @return array{account: int, stuck: int, paid: int, lastPaid: int}
	 */
	private function seedLegacyBills(): array {
		$account = $this->makeAccount(['name' => 'Current', 'openingBalance' => 1000.0])->getId();
		// Rent is unpaid for September, but the job cleared its row on the 1st
		$rent = $this->bill($account, 'Rent', ['next_due_date' => '2026-09-01']);
		$stuck = $this->row($account, $rent, 'Rent', '2026-09-01');
		// August was paid: that row is on another date
		$paid = $this->row($account, $rent, 'Rent', '2026-08-01');
		// A weekly bill paid late with Record transaction: the payment sits on
		// the last paid date, which is also its next due date, and 2.54.0
		// never named it in a snapshot. It stays booked.
		$cleaner = $this->bill($account, 'Cleaner', ['frequency' => 'weekly', 'next_due_date' => '2026-09-05', 'last_paid_date' => '2026-09-05']);
		$lastPaid = $this->row($account, $cleaner, 'Cleaner', '2026-09-05');
		$this->service(AccountBalanceCalculator::class)->recalculate($this->service(AccountMapper::class)->findById($account));
		return ['account' => $account, 'stuck' => $stuck, 'paid' => $paid, 'lastPaid' => $lastPaid];
	}

	public function testAPre30BackupComesBackWithItsUnpaidPlaceholderPending(): void {
		$this->seedLegacyBills();
		$target = $this->newUserId();

		$this->migration->importAll($target, $this->asVersion($this->migration->exportAll($this->userId)['content'], '1.2.0'));

		$this->assertSame([
			['2026-08-01', 'Rent', 'cleared'],
			['2026-09-01', 'Rent', 'scheduled'],
			['2026-09-05', 'Cleaner', 'cleared'],
		], $this->rowsOf($target));
		// 1000 - 800 (August) - 25 (the cleaner): September's rent is no
		// longer counted as paid
		$this->assertEqualsWithDelta(175.0, $this->balanceOf($target), 0.001);
	}

	/**
	 * Old Repair runs switched future payments to scheduled; migration 114
	 * puts back the ones a bill's snapshot names as its payment.
	 */
	public function testAPre30BackupComesBackWithARepairSwitchedPaymentCleared(): void {
		$account = $this->makeAccount(['name' => 'Current', 'openingBalance' => 1000.0])->getId();
		$rent = $this->bill($account, 'Rent', ['next_due_date' => '2026-11-01', 'last_paid_date' => '2026-10-01']);
		$payment = $this->row($account, $rent, 'Rent', '2026-10-01', ['status' => 'scheduled']);
		$placeholder = $this->row($account, $rent, 'Rent', '2026-11-01', ['status' => 'scheduled']);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_bills SET paid_undo_state = ? WHERE id = ?', [
			json_encode(['previousState' => ['nextDueDate' => '2026-10-01'], 'createdTransactionIds' => [$payment],
				'scheduledTransactionIds' => [$placeholder], 'linkedTransactionId' => null, 'paidDate' => '2026-10-01']),
			$rent,
		]);
		$target = $this->newUserId();

		$this->migration->importAll($target, $this->asVersion($this->migration->exportAll($this->userId)['content'], '1.2.0', false));

		$this->assertSame([
			['2026-10-01', 'Rent', 'cleared'],
			['2026-11-01', 'Rent', 'scheduled'],
		], $this->rowsOf($target));
		$this->assertEqualsWithDelta(200.0, $this->balanceOf($target), 0.001);
	}

	public function testA30BackupIsRestoredAsItIs(): void {
		$this->seedLegacyBills();
		$target = $this->newUserId();

		$this->migration->importAll($target, $this->migration->exportAll($this->userId)['content']);

		$this->assertSame([
			['2026-08-01', 'Rent', 'cleared'],
			['2026-09-01', 'Rent', 'cleared'],
			['2026-09-05', 'Cleaner', 'cleared'],
		], $this->rowsOf($target));
		$this->assertEqualsWithDelta(-625.0, $this->balanceOf($target), 0.001);
	}

	/**
	 * The repair only touches the restored user's own rows: another user's
	 * stuck row is left for the upgrade to deal with.
	 */
	public function testOnlyTheRestoredUsersRowsAreRepaired(): void {
		$this->seedLegacyBills();
		$other = $this->newUserId();
		$account = $this->makeAccount(['name' => 'Theirs'], $other)->getId();
		$theirBill = $this->bill($account, 'Gym', ['next_due_date' => '2026-09-01', 'user_id' => $other]);
		$theirs = $this->row($account, $theirBill, 'Gym', '2026-09-01');
		$target = $this->newUserId();

		$this->migration->importAll($target, $this->asVersion($this->migration->exportAll($this->userId)['content'], '1.2.0'));

		$this->assertSame('cleared', $this->fetchRow('budget_transactions', $theirs)['status']);
	}

	private function bill(int $accountId, string $name, array $overrides = []): int {
		return $this->insertRow('budget_bills', $overrides + [
			'user_id' => $this->userId, 'name' => $name, 'amount' => $name === 'Cleaner' ? '25.00' : '800.00',
			'frequency' => 'monthly', 'account_id' => $accountId, 'is_active' => true, 'due_day' => 1,
			'created_at' => $this->now(),
		]);
	}

	private function row(int $accountId, int $billId, string $name, string $date, array $overrides = []): int {
		return $this->makeTransaction($accountId, $overrides + [
			'bill_id' => $billId, 'date' => $date, 'description' => $name,
			'amount' => $name === 'Cleaner' ? '25.00' : '800.00', 'notes' => 'Auto-generated from bill: ' . $name,
		]);
	}

	/**
	 * The archive as 2.54.0 wrote it: an older format version and, unless
	 * told otherwise, no bill undo snapshots (2.54.0 never exported them).
	 */
	private function asVersion(string $zipContent, string $version, bool $dropSnapshots = true): string {
		$path = tempnam(sys_get_temp_dir(), 'budget-it-');
		file_put_contents($path, $zipContent);
		$zip = new \ZipArchive();
		$zip->open($path);
		$manifest = json_decode((string)$zip->getFromName('manifest.json'), true);
		$manifest['version'] = $version;
		$zip->addFromString('manifest.json', json_encode($manifest));
		if ($dropSnapshots) {
			$bills = json_decode((string)$zip->getFromName('bills.json'), true);
			foreach ($bills as &$bill) {
				unset($bill['paidUndoState']);
			}
			unset($bill);
			$zip->addFromString('bills.json', json_encode($bills));
		}
		$zip->close();
		$content = (string)file_get_contents($path);
		unlink($path);
		return $content;
	}

	/**
	 * @return list<array{0: string, 1: string, 2: string}> date, bill, status
	 */
	private function rowsOf(string $userId): array {
		$rows = $this->db()->executeQuery(
			'SELECT t.date, b.name, t.status FROM *PREFIX*budget_transactions t'
			. ' INNER JOIN *PREFIX*budget_accounts a ON a.id = t.account_id'
			. ' INNER JOIN *PREFIX*budget_bills b ON b.id = t.bill_id'
			. ' WHERE a.user_id = ? ORDER BY t.date, b.name',
			[$userId]
		)->fetchAll();
		return array_map(static fn (array $r) => [substr((string)$r['date'], 0, 10), (string)$r['name'], (string)$r['status']], $rows);
	}

	private function balanceOf(string $userId): float {
		$accounts = $this->service(AccountMapper::class)->findAll($userId);
		$this->assertCount(1, $accounts);
		return (float)$accounts[0]->getBalance();
	}
}
