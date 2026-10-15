<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Deleting a user with more transactions than one statement may bind
 * parameters: 65,535 on PostgreSQL. The reset deleted transactions by binding
 * every id in one IN list, so on PostgreSQL the purge of such a user failed
 * and left all of their data behind, while the shares had already been
 * revoked outside the transaction.
 */
class LargeUserDeletionTest extends IntegrationTestCase {
	private const ROWS = 65600;

	/**
	 * ROWS cleared debits in one statement: five cross-joined digit tables
	 * number the rows, which works the same on sqlite, MySQL and PostgreSQL.
	 */
	private function fillAccount(int $accountId): void {
		$digits = 'SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4'
			. ' UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9';
		$now = $this->now();
		$this->db()->executeStatement(
			'INSERT INTO *PREFIX*budget_transactions (account_id, date, description, amount, type, status, created_at, updated_at) '
			. 'SELECT ' . $accountId . ", '2026-03-15', 'Bulk row', 1.00, 'debit', 'cleared', '$now', '$now' "
			. "FROM ($digits) t1 CROSS JOIN ($digits) t2 CROSS JOIN ($digits) t3 CROSS JOIN ($digits) t4 CROSS JOIN ($digits) t5 "
			. 'WHERE t1.n * 10000 + t2.n * 1000 + t3.n * 100 + t4.n * 10 + t5.n < ' . self::ROWS
		);
	}

	public function testADeletedUserWithMoreThan65535TransactionsIsPurgedWithTheirAccess(): void {
		$account = $this->makeAccount();
		$this->fillAccount($account->getId());
		$this->assertSame(self::ROWS, $this->countRows('budget_transactions', ['account_id' => $account->getId()]));

		// Alice's data, which must survive, and the access she gave the user
		$alice = $this->newUserId();
		$joint = $this->makeAccount(['name' => 'Joint'], $alice);
		$alicesRow = $this->makeTransaction($joint->getId());
		$share = $this->insertRow('budget_shares', [
			'owner_user_id' => $alice, 'shared_with_user_id' => $this->userId, 'status' => 'accepted',
			'created_at' => $this->now(), 'updated_at' => $this->now(),
		]);
		$contact = $this->makeContact($alice);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_contacts SET nextcloud_user_id = ? WHERE id = ?', [$this->userId, $contact]);

		$this->service(FactoryResetService::class)->purgeDeletedUser($this->userId);

		$this->assertSame(0, $this->countRows('budget_transactions', ['account_id' => $account->getId()]));
		$this->assertSame(0, $this->countRows('budget_accounts', ['user_id' => $this->userId]));
		$this->assertNotNull($this->fetchRow('budget_transactions', $alicesRow));
		$this->assertNull($this->fetchRow('budget_shares', $share));
		$this->assertNull($this->fetchRow('budget_contacts', $contact)['nextcloud_user_id']);
	}
}
