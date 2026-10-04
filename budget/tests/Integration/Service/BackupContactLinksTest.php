<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\MigrationService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * A shared-expense contact linked to a Nextcloud user shows that user its
 * shared expenses. Creating one only links someone the user picker offers
 * and the sharing settings allow, but a restore kept whatever user id the
 * archive named, so a crafted backup reached anyone on the server, past
 * "only share with group members" (R6-6). The restore applies the same
 * check now, as the restoring user, and says how many links it removed.
 */
class BackupContactLinksTest extends IntegrationTestCase {
	private MigrationService $migration;

	/** @var string[] real Nextcloud users this test created */
	private array $users = [];

	private string $restorer;
	private string $friend;

	protected function setUp(): void {
		parent::setUp();
		$this->migration = $this->service(MigrationService::class);
		$this->restorer = $this->realUser();
		$this->friend = $this->realUser();
		$this->service(IUserSession::class)->setUser($this->service(IUserManager::class)->get($this->restorer));
	}

	protected function tearDown(): void {
		$this->service(IUserSession::class)->setUser(null);
		$this->service(IConfig::class)->deleteAppValue('core', 'shareapi_only_share_with_group_members');
		foreach ($this->users as $uid) {
			$this->service(IUserManager::class)->get($uid)?->delete();
		}
		parent::tearDown();
	}

	private function realUser(): string {
		$uid = 'itc' . bin2hex(random_bytes(4));
		$this->service(IUserManager::class)->createUser($uid, 'Contact-link-' . bin2hex(random_bytes(6)));
		$this->users[] = $uid;
		return $uid;
	}

	/**
	 * @return string an archive of $this->userId holding contacts linked to these users
	 */
	private function archiveLinkingTo(string ...$uids): string {
		foreach ($uids as $uid) {
			$contact = $this->makeContact();
			$this->db()->executeStatement('UPDATE *PREFIX*budget_contacts SET name = ?, nextcloud_user_id = ? WHERE id = ?', [$uid, $uid, $contact]);
		}
		return $this->migration->exportAll($this->userId)['content'];
	}

	/**
	 * @return array<string, string|null> contact name => linked user
	 */
	private function restoredLinks(): array {
		$rows = $this->db()->executeQuery(
			'SELECT name, nextcloud_user_id FROM *PREFIX*budget_contacts WHERE user_id = ? ORDER BY name', [$this->restorer]
		)->fetchAll();
		return array_column($rows, 'nextcloud_user_id', 'name');
	}

	public function testALinkToSomeoneTheUserCouldLinkIsKept(): void {
		$archive = $this->archiveLinkingTo($this->friend);

		$result = $this->migration->importAll($this->restorer, $archive);

		$this->assertSame([$this->friend => $this->friend], $this->restoredLinks());
		$this->assertSame([], $result['warnings']);
	}

	public function testALinkToSomeoneWhoDoesntExistIsRemovedWithAWarning(): void {
		$archive = $this->archiveLinkingTo($this->friend, 'ghost-' . bin2hex(random_bytes(4)));

		$result = $this->migration->importAll($this->restorer, $archive);

		$links = $this->restoredLinks();
		$this->assertSame($this->friend, $links[$this->friend]);
		$this->assertCount(1, array_filter($links, static fn ($uid) => $uid === null));
		$this->assertCount(1, $result['warnings']);
		$this->assertStringContainsString('1 contact', $result['warnings'][0]);
	}

	/**
	 * With "only share with group members" on, a user in no group with the
	 * restoring user can't be linked, as when creating the contact by hand.
	 */
	public function testOnlyShareWithGroupMembersApplies(): void {
		$archive = $this->archiveLinkingTo($this->friend);
		$this->service(IConfig::class)->setAppValue('core', 'shareapi_only_share_with_group_members', 'yes');

		$this->migration->importAll($this->restorer, $archive);

		$this->assertSame([$this->friend => null], $this->restoredLinks());
	}
}
