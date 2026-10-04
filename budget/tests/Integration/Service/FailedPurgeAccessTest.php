<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\AttachmentMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ContactMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Db\ShareMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\CrossUserLinks;
use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use Psr\Log\LoggerInterface;

/**
 * A deleted user's purge that fails part way leaves their data for a second
 * run (`occ budget:purge-deleted-users`), but never the access other users
 * gave them: a re-created account with the same uid would inherit it.
 */
class FailedPurgeAccessTest extends IntegrationTestCase {
	public function testPurgeFailsSharesAndLinksAreStillGoneDataStillThere(): void {
		$account = $this->makeAccount()->getId();
		$row = $this->makeTransaction($account);
		$this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Phone', 'amount' => '30.00', 'frequency' => 'monthly',
			'is_active' => true, 'created_at' => $this->now(),
		]);

		// Alice gave the user access; the user gave Bob access to their own
		$alice = $this->newUserId();
		$bob = $this->newUserId();
		$given = $this->insertRow('budget_shares', [
			'owner_user_id' => $alice, 'shared_with_user_id' => $this->userId, 'status' => 'accepted',
			'created_at' => $this->now(), 'updated_at' => $this->now(),
		]);
		$givenItem = $this->insertRow('budget_share_items', [
			'share_id' => $given, 'entity_type' => 'account', 'entity_id' => $this->makeAccount(['name' => 'Joint'], $alice)->getId(),
			'permission' => 'write', 'created_at' => $this->now(), 'updated_at' => $this->now(),
		]);
		$granted = $this->insertRow('budget_shares', [
			'owner_user_id' => $this->userId, 'shared_with_user_id' => $bob, 'status' => 'accepted',
			'created_at' => $this->now(), 'updated_at' => $this->now(),
		]);
		$contact = $this->makeContact($alice);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_contacts SET nextcloud_user_id = ? WHERE id = ?', [$this->userId, $contact]);

		// Fails inside the purge's transaction, after it started deleting
		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('deleteScheduledBillTransactions')->willThrowException(new \RuntimeException('database went away'));
		$reset = new FactoryResetService(
			$this->service(AccountMapper::class),
			$this->service(TransactionMapper::class),
			$this->service(BillMapper::class),
			$this->service(CategoryMapper::class),
			$this->service(ImportRuleMapper::class),
			$this->service(SettingMapper::class),
			$this->service(AttachmentMapper::class),
			$this->db(),
			null,
			$transactions,
			$this->service(ShareMapper::class),
			$this->service(ContactMapper::class),
			$this->service(CrossUserLinks::class),
			$this->service(LoggerInterface::class),
		);

		try {
			$reset->purgeDeletedUser($this->userId);
			$this->fail('the purge was made to fail');
		} catch (\RuntimeException $e) {
			$this->assertSame('database went away', $e->getMessage());
		}

		// Access others gave the uid: gone
		$this->assertNull($this->fetchRow('budget_shares', $given));
		$this->assertNull($this->fetchRow('budget_share_items', $givenItem));
		$this->assertNull($this->fetchRow('budget_contacts', $contact)['nextcloud_user_id']);
		// The user's own data, their own share included: there for a second run
		$this->assertNotNull($this->fetchRow('budget_accounts', $account));
		$this->assertNotNull($this->fetchRow('budget_transactions', $row));
		$this->assertSame(1, $this->countRows('budget_bills', ['user_id' => $this->userId]));
		$this->assertNotNull($this->fetchRow('budget_shares', $granted));
		$this->assertFalse($this->db()->inTransaction());

		// The second run removes the rest
		$this->service(FactoryResetService::class)->purgeDeletedUser($this->userId);
		$this->assertNull($this->fetchRow('budget_accounts', $account));
		$this->assertNull($this->fetchRow('budget_shares', $granted));
	}
}
