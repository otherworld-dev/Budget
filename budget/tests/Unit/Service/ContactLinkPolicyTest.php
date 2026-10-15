<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\ContactLinkPolicy;
use OCA\Budget\Service\ShareService;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContactLinkPolicyTest extends TestCase {
	private IUserManager $userManager;
	private ShareService $shareService;
	private ISearch $search;
	private ContactLinkPolicy $policy;

	protected function setUp(): void {
		$this->userManager = $this->createMock(IUserManager::class);
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$this->userManager->method('get')->willReturnCallback(fn (string $uid) => $uid === 'bob' ? $bob : null);
		$this->shareService = $this->createMock(ShareService::class);
		$this->search = $this->createMock(ISearch::class);
		$this->policy = new ContactLinkPolicy($this->userManager, $this->shareService, $this->search, $this->createMock(LoggerInterface::class));
	}

	private function offered(string ...$uids): void {
		$entries = array_map(static fn (string $uid) => ['label' => $uid, 'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => $uid]], $uids);
		$this->search->method('search')->willReturn([['exact' => ['users' => $entries], 'users' => []], false]);
	}

	public function testSomeoneThePickerOffersAndTheUserMayShareWithCanBeLinked(): void {
		$this->offered('bob');
		$this->shareService->method('mayShareWith')->with('alice', $this->anything())->willReturn(true);

		$this->assertTrue($this->policy->mayLink('alice', 'bob'));
	}

	public function testOnlyShareWithGroupMembersApplies(): void {
		$this->offered('bob');
		$this->shareService->method('mayShareWith')->willReturn(false);

		$this->assertFalse($this->policy->mayLink('alice', 'bob'));
	}

	public function testSomeoneThePickerHidesCantBeLinked(): void {
		$this->offered();
		$this->shareService->method('mayShareWith')->willReturn(true);

		$this->assertFalse($this->policy->mayLink('alice', 'bob'));
	}

	public function testAUserWhoDoesntExistCantBeLinked(): void {
		$this->offered('ghost');
		$this->shareService->method('mayShareWith')->willReturn(true);

		$this->assertFalse($this->policy->mayLink('alice', 'ghost'));
		$this->assertFalse($this->policy->mayLink('alice', ''));
	}

	/**
	 * With nobody signed in (a restore from the command line) the search
	 * fails, and the link is not kept.
	 */
	public function testASearchThatFailsKeepsNoLink(): void {
		$this->search->method('search')->willThrowException(new \Error('Call to a member function getUID() on null'));
		$this->shareService->method('mayShareWith')->willReturn(true);

		$this->assertFalse($this->policy->mayLink('alice', 'bob'));
	}
}
