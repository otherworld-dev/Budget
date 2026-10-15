<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\DeletedUserData;
use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * What `occ budget:purge-deleted-users` finds, against the real database.
 * The test's own user ids were never created in Nextcloud, so they stand in
 * for users deleted before 3.0; 'admin' is a real user.
 */
class DeletedUserDataTest extends IntegrationTestCase {
	public function testFindsTheDataOfUsersNextcloudDoesNotKnowAndNobodyElses(): void {
		$account = $this->makeAccount();
		$this->makeTransaction($account->getId());
		$this->makeTransaction($account->getId());
		$this->makeCategory();
		$adminsAccount = $this->makeAccount(['name' => 'Admin current'], 'admin');
		$this->makeTransaction($adminsAccount->getId());

		// Only the access another user gave them is left of this one
		$accessOnly = $this->newUserId();
		$contact = $this->makeContact('admin');
		$this->db()->executeStatement('UPDATE *PREFIX*budget_contacts SET nextcloud_user_id = ? WHERE id = ?', [$accessOnly, $contact]);
		$share = $this->insertRow('budget_shares', [
			'owner_user_id' => 'admin', 'shared_with_user_id' => $accessOnly, 'status' => 'accepted',
			'created_at' => $this->now(), 'updated_at' => $this->now(),
		]);

		$found = $this->service(DeletedUserData::class)->find();

		$this->assertSame(1, $found['gone'][$this->userId]['budget_accounts']);
		$this->assertSame(1, $found['gone'][$this->userId]['budget_categories']);
		$this->assertSame(2, $found['gone'][$this->userId]['budget_transactions']);
		$this->assertSame([
			'budget_contacts.nextcloud_user_id' => 1,
			'budget_shares.shared_with_user_id' => 1,
		], $found['gone'][$accessOnly]);
		$this->assertArrayNotHasKey('admin', $found['gone']);

		// What the command's --force does for each user it lists
		$this->service(FactoryResetService::class)->purgeDeletedUser($accessOnly);
		$this->service(FactoryResetService::class)->purgeDeletedUser($this->userId);

		$after = $this->service(DeletedUserData::class)->find();
		$this->assertArrayNotHasKey($accessOnly, $after['gone']);
		$this->assertArrayNotHasKey($this->userId, $after['gone']);
		$this->assertNull($this->fetchRow('budget_contacts', $contact)['nextcloud_user_id']);
		$this->assertNull($this->fetchRow('budget_shares', $share));
		$this->assertSame(1, $this->countRows('budget_accounts', ['user_id' => 'admin', 'id' => $adminsAccount->getId()]));
	}
}
