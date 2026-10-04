<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\Project;
use OCA\Budget\Db\ProjectMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\SavingsGoalMapper;
use OCA\Budget\Db\Share;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Db\ShareItemMapper;
use OCA\Budget\Db\ShareMapper;
use OCA\Budget\Exception\ReadOnlyShareException;
use OCA\Budget\Service\GranularShareService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class GranularShareServiceTest extends TestCase {
	private GranularShareService $service;
	private ShareMapper $shareMapper;
	private ShareItemMapper $shareItemMapper;
	private AccountMapper $accountMapper;
	private BillMapper $billMapper;
	private CategoryMapper $categoryMapper;
	private RecurringIncomeMapper $recurringIncomeMapper;
	private SavingsGoalMapper $savingsGoalMapper;
	private ImportRuleMapper $importRuleMapper;
	private ProjectMapper $projectMapper;
	private IL10N $l;

	protected function setUp(): void {
		$this->shareMapper = $this->createMock(ShareMapper::class);
		$this->shareItemMapper = $this->createMock(ShareItemMapper::class);
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$this->billMapper = $this->createMock(BillMapper::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->recurringIncomeMapper = $this->createMock(RecurringIncomeMapper::class);
		$this->savingsGoalMapper = $this->createMock(SavingsGoalMapper::class);
		$this->importRuleMapper = $this->createMock(ImportRuleMapper::class);
		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->l = $this->createMock(IL10N::class);

		$this->l->method('t')->willReturnCallback(
			fn (string $text, array $params = []) => vsprintf(str_replace('%1$s', '%s', $text), $params)
		);

		$this->service = new GranularShareService(
			$this->shareMapper,
			$this->shareItemMapper,
			$this->accountMapper,
			$this->billMapper,
			$this->categoryMapper,
			$this->recurringIncomeMapper,
			$this->savingsGoalMapper,
			$this->importRuleMapper,
			$this->l,
			null,
			$this->projectMapper
		);
	}

	private function makeShare(int $id, string $owner, string $recipient, string $status): Share {
		$share = new Share();
		$share->setId($id);
		$share->setOwnerUserId($owner);
		$share->setSharedWithUserId($recipient);
		$share->setStatus($status);
		return $share;
	}

	private function makeShareItem(int $id, int $shareId, string $type, int $entityId, string $permission): ShareItem {
		$item = new ShareItem();
		$item->setId($id);
		$item->setShareId($shareId);
		$item->setEntityType($type);
		$item->setEntityId($entityId);
		$item->setPermission($permission);
		return $item;
	}

	private function makeEntity(int $id): Account {
		$account = new Account();
		$account->setId($id);
		return $account;
	}

	private function makeAccount(int $id): Account {
		$account = new Account();
		$account->setId($id);
		$account->setName('Account ' . $id);
		$account->setUserId('test');
		$account->setType('checking');
		$account->setBalance(0.0);
		$account->setCurrency('USD');
		return $account;
	}

	// =============================================
	// getVisibleAccountIds
	// =============================================

	public function testGetVisibleAccountIdsMergesOwnAndShared(): void {
		$ownAccount1 = $this->makeEntity(1);
		$ownAccount2 = $this->makeEntity(2);
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([$ownAccount1, $ownAccount2]);

		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$share]);

		$this->shareItemMapper->method('findSharedEntityIds')
			->with(100, ShareItem::TYPE_ACCOUNT)
			->willReturn([3, 4]);

		$result = $this->service->getVisibleAccountIds('alice');

		$this->assertEqualsCanonicalizing([1, 2, 3, 4], $result);
	}

	public function testGetVisibleAccountIdsDeduplicatesOverlappingIds(): void {
		$ownAccount = $this->makeEntity(1);
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([$ownAccount]);

		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$share]);

		// Shared ID overlaps with own ID
		$this->shareItemMapper->method('findSharedEntityIds')
			->with(100, ShareItem::TYPE_ACCOUNT)
			->willReturn([1, 2]);

		$result = $this->service->getVisibleAccountIds('alice');

		$this->assertEqualsCanonicalizing([1, 2], $result);
		// No duplicates
		$this->assertCount(2, $result);
	}

	public function testGetVisibleAccountIdsCachesResult(): void {
		$ownAccount = $this->makeEntity(1);
		$this->accountMapper->expects($this->once())
			->method('findAll')
			->with('alice')
			->willReturn([$ownAccount]);

		$this->shareMapper->expects($this->once())
			->method('findByRecipient')
			->with('alice')
			->willReturn([]);

		// Call twice - mapper should only be invoked once
		$this->service->getVisibleAccountIds('alice');
		$result = $this->service->getVisibleAccountIds('alice');

		$this->assertSame([1], $result);
	}

	// =============================================
	// getSharedAccountIds / getSharedCategoryIds
	// =============================================

	public function testGetSharedAccountIdsReturnsOnlySharedIds(): void {
		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$share]);

		$this->shareItemMapper->method('findSharedEntityIds')
			->with(100, ShareItem::TYPE_ACCOUNT)
			->willReturn([5, 6]);

		$result = $this->service->getSharedAccountIds('alice');

		$this->assertSame([5, 6], $result);
	}

	public function testGetSharedCategoryIdsReturnsOnlySharedIds(): void {
		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$share]);

		$this->shareItemMapper->method('findSharedEntityIds')
			->with(100, ShareItem::TYPE_CATEGORY)
			->willReturn([10, 11]);

		$result = $this->service->getSharedCategoryIds('alice');

		$this->assertSame([10, 11], $result);
	}

	// =============================================
	// requireUsableCategory
	// =============================================

	private function aliceSeesCategories(array $own, array $shared): void {
		$this->categoryMapper->method('findAll')->with('alice')
			->willReturn(array_map(fn (int $id) => $this->makeEntity($id), $own));
		$this->shareMapper->method('findByRecipient')->with('alice')
			->willReturn([$this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED)]);
		$this->shareItemMapper->method('findSharedEntityIds')->willReturn($shared);
	}

	public function testRequireUsableCategoryAcceptsOwnAndSharedCategories(): void {
		$this->aliceSeesCategories([1, 2], [10]);

		$this->service->requireUsableCategory('alice', 1);
		$this->service->requireUsableCategory('alice', 10);
		$this->service->requireUsableCategory('alice', null);
		$this->addToAssertionCount(3);
	}

	/**
	 * Another user's category id used to be stored as-is, and the listing
	 * join then handed back that user's category name.
	 */
	public function testRequireUsableCategoryRejectsSomeoneElsesCategory(): void {
		$this->aliceSeesCategories([1, 2], [10]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Category not found');
		$this->service->requireUsableCategory('alice', 999);
	}

	// =============================================
	// usable tags (R6-2 / T4-6)
	// =============================================

	/**
	 * alice owns categories 1 and 2 and has category 10 shared with her.
	 * Tags: 100 alice's global, 101 bob's global, 102 in a set (50) on her
	 * category 1, 103 in a set (51) on bob's shared category 10, 104 in a
	 * set (52) on bob's unshared category 20, 105 in a set (53) that no
	 * longer exists.
	 */
	private function serviceWithTags(): GranularShareService {
		$this->aliceSeesCategories([1, 2], [10]);

		$tag = function (int $id, ?int $tagSetId, string $userId): \OCA\Budget\Db\Tag {
			$t = new \OCA\Budget\Db\Tag();
			$t->setId($id);
			$t->setTagSetId($tagSetId);
			$t->setUserId($userId);
			return $t;
		};
		$tags = [
			100 => $tag(100, null, 'alice'),
			101 => $tag(101, null, 'bob'),
			102 => $tag(102, 50, 'alice'),
			103 => $tag(103, 51, 'bob'),
			104 => $tag(104, 52, 'bob'),
			105 => $tag(105, 53, 'bob'),
		];
		$tagMapper = $this->createMock(\OCA\Budget\Db\TagMapper::class);
		$tagMapper->method('findByIds')->willReturnCallback(
			fn (array $ids) => array_intersect_key($tags, array_flip($ids))
		);
		$tagSetMapper = $this->createMock(\OCA\Budget\Db\TagSetMapper::class);
		$tagSetMapper->method('findById')->willReturnCallback(function (int $id) {
			$categoryOf = [50 => 1, 51 => 10, 52 => 20];
			if (!isset($categoryOf[$id])) {
				throw new \OCP\AppFramework\Db\DoesNotExistException('');
			}
			$set = new \OCA\Budget\Db\TagSet();
			$set->setCategoryId($categoryOf[$id]);
			return $set;
		});

		return new GranularShareService(
			$this->shareMapper, $this->shareItemMapper, $this->accountMapper, $this->billMapper,
			$this->categoryMapper, $this->recurringIncomeMapper, $this->savingsGoalMapper,
			$this->importRuleMapper, $this->l, null, $this->projectMapper, $tagMapper, $tagSetMapper
		);
	}

	public function testUsableTagsAreOwnGlobalTagsAndTagsOfVisibleCategories(): void {
		$usable = $this->serviceWithTags()->getUsableTagIds('alice', [100, 101, 102, 103, 104, 105, 999]);

		$this->assertEqualsCanonicalizing([100, 102, 103], $usable);
	}

	public function testRequireUsableTagsAcceptsUsableTags(): void {
		$this->serviceWithTags()->requireUsableTags('alice', [100, 102, 103, '103']);
		$this->serviceWithTags()->requireUsableTags('alice', []);
		$this->addToAssertionCount(2);
	}

	public function testRequireUsableTagsRejectsAnotherUsersGlobalTag(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid tag ID');
		$this->serviceWithTags()->requireUsableTags('alice', [100, 101]);
	}

	public function testRequireUsableTagsRejectsATagOfACategoryNeverShared(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->serviceWithTags()->requireUsableTags('alice', [104]);
	}

	public function testRequireUsableTagsRejectsAnUnknownTag(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->serviceWithTags()->requireUsableTags('alice', [999]);
	}

	public function testNoTagIsUsableWithoutTheTagMappers(): void {
		$this->aliceSeesCategories([1], []);
		$this->assertSame([], $this->service->getUsableTagIds('alice', [100]));
	}

	// =============================================
	// import rules
	// =============================================

	public function testGetVisibleImportRuleIdsMergesOwnAndShared(): void {
		$this->importRuleMapper->method('findAll')
			->with('alice')
			->willReturn([$this->makeEntity(1), $this->makeEntity(2)]);

		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$share]);

		$this->shareItemMapper->method('findSharedEntityIds')
			->with(100, ShareItem::TYPE_IMPORT_RULE)
			->willReturn([5, 6]);

		$result = $this->service->getVisibleImportRuleIds('alice');

		$this->assertEqualsCanonicalizing([1, 2, 5, 6], $result);
	}

	public function testGetSharedImportRulesFlagsOwnerAndWritePermission(): void {
		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$share]);
		$this->shareItemMapper->method('findSharedEntityIds')
			->with(100, ShareItem::TYPE_IMPORT_RULE)
			->willReturn([7]);

		$rule = new \OCA\Budget\Db\ImportRule();
		$rule->setId(7);
		$rule->setUserId('bob');
		$rule->setName('Groceries');
		// canWrite() checks own ids first — alice owns no rules here
		$this->importRuleMapper->method('findAll')->willReturn([]);
		$this->importRuleMapper->method('findByIds')->with([7])->willReturn([$rule]);
		$this->shareItemMapper->method('getEntityPermission')
			->with(100, ShareItem::TYPE_IMPORT_RULE, 7)
			->willReturn(ShareItem::PERMISSION_WRITE);

		$result = $this->service->getSharedImportRules('alice');

		$this->assertCount(1, $result);
		$this->assertTrue($result[0]['_shared']);
		$this->assertSame('bob', $result[0]['_sharedBy']);
		$this->assertTrue($result[0]['_canWrite']);
	}

	// =============================================
	// canAccess
	// =============================================

	public function testCanAccessReturnsTrueForOwnEntity(): void {
		$ownAccount = $this->makeEntity(1);
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([$ownAccount]);

		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([]);

		$this->assertTrue($this->service->canAccess('alice', ShareItem::TYPE_ACCOUNT, 1));
	}

	public function testCanAccessReturnsTrueForSharedEntity(): void {
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([]);

		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$share]);

		$this->shareItemMapper->method('findSharedEntityIds')
			->with(100, ShareItem::TYPE_ACCOUNT)
			->willReturn([5]);

		$this->assertTrue($this->service->canAccess('alice', ShareItem::TYPE_ACCOUNT, 5));
	}

	public function testCanAccessReturnsFalseForUnrelatedEntity(): void {
		$ownAccount = $this->makeEntity(1);
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([$ownAccount]);

		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([]);

		$this->assertFalse($this->service->canAccess('alice', ShareItem::TYPE_ACCOUNT, 999));
	}

	// =============================================
	// canWrite
	// =============================================

	public function testCanWriteReturnsTrueForOwnEntity(): void {
		$ownAccount = $this->makeEntity(1);
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([$ownAccount]);

		$this->assertTrue($this->service->canWrite('alice', ShareItem::TYPE_ACCOUNT, 1));
	}

	public function testCanWriteReturnsTrueForSharedEntityWithWritePermission(): void {
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([]);

		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$share]);

		$this->shareItemMapper->method('getEntityPermission')
			->with(100, ShareItem::TYPE_ACCOUNT, 5)
			->willReturn(ShareItem::PERMISSION_WRITE);

		$this->assertTrue($this->service->canWrite('alice', ShareItem::TYPE_ACCOUNT, 5));
	}

	public function testCanWriteReturnsFalseForSharedEntityWithReadPermission(): void {
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([]);

		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$share]);

		$this->shareItemMapper->method('getEntityPermission')
			->with(100, ShareItem::TYPE_ACCOUNT, 5)
			->willReturn(ShareItem::PERMISSION_READ);

		$this->assertFalse($this->service->canWrite('alice', ShareItem::TYPE_ACCOUNT, 5));
	}

	public function testCanWriteReturnsFalseForUnknownEntity(): void {
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([]);

		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([]);

		$this->assertFalse($this->service->canWrite('alice', ShareItem::TYPE_ACCOUNT, 999));
	}

	// =============================================
	// requireWriteAccess
	// =============================================

	public function testRequireWriteAccessPassesForOwnEntity(): void {
		$ownAccount = $this->makeEntity(1);
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([$ownAccount]);

		// Should not throw
		$this->service->requireWriteAccess('alice', ShareItem::TYPE_ACCOUNT, 1);
		$this->addToAssertionCount(1);
	}

	public function testRequireWriteAccessThrowsReadOnlyShareException(): void {
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([]);

		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$share]);

		$this->shareItemMapper->method('getEntityPermission')
			->with(100, ShareItem::TYPE_ACCOUNT, 5)
			->willReturn(ShareItem::PERMISSION_READ);

		$this->expectException(ReadOnlyShareException::class);
		$this->service->requireWriteAccess('alice', ShareItem::TYPE_ACCOUNT, 5);
	}

	// =============================================
	// getShareConfig
	// =============================================

	public function testGetShareConfigReturnsTypeToIdsPermissionMapping(): void {
		$accountItem1 = $this->makeShareItem(1, 100, ShareItem::TYPE_ACCOUNT, 10, ShareItem::PERMISSION_WRITE);
		$accountItem2 = $this->makeShareItem(2, 100, ShareItem::TYPE_ACCOUNT, 11, ShareItem::PERMISSION_WRITE);
		$categoryItem = $this->makeShareItem(3, 100, ShareItem::TYPE_CATEGORY, 20, ShareItem::PERMISSION_READ);

		$this->shareItemMapper->method('findByShareIdAndType')
			->willReturnMap([
				[100, ShareItem::TYPE_ACCOUNT, [$accountItem1, $accountItem2]],
				[100, ShareItem::TYPE_CATEGORY, [$categoryItem]],
				[100, ShareItem::TYPE_BILL, []],
				[100, ShareItem::TYPE_RECURRING_INCOME, []],
				[100, ShareItem::TYPE_SAVINGS_GOAL, []],
				[100, ShareItem::TYPE_IMPORT_RULE, []],
				[100, ShareItem::TYPE_PROJECT, []],
			]);

		$config = $this->service->getShareConfig(100);

		$this->assertArrayHasKey(ShareItem::TYPE_ACCOUNT, $config);
		$this->assertSame([10, 11], $config[ShareItem::TYPE_ACCOUNT]['ids']);
		$this->assertSame(ShareItem::PERMISSION_WRITE, $config[ShareItem::TYPE_ACCOUNT]['permission']);

		$this->assertArrayHasKey(ShareItem::TYPE_CATEGORY, $config);
		$this->assertSame([20], $config[ShareItem::TYPE_CATEGORY]['ids']);
		$this->assertSame(ShareItem::PERMISSION_READ, $config[ShareItem::TYPE_CATEGORY]['permission']);

		// Empty types should not appear
		$this->assertArrayNotHasKey(ShareItem::TYPE_BILL, $config);
		$this->assertArrayNotHasKey(ShareItem::TYPE_RECURRING_INCOME, $config);
		$this->assertArrayNotHasKey(ShareItem::TYPE_SAVINGS_GOAL, $config);
		$this->assertArrayNotHasKey(ShareItem::TYPE_IMPORT_RULE, $config);
		$this->assertArrayNotHasKey(ShareItem::TYPE_PROJECT, $config);
	}

	// =============================================
	// updateShareItems
	// =============================================

	public function testUpdateShareItemsThrowsOnInvalidEntityType(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid entity type: bogus');

		$this->service->updateShareItems('alice', 100, 'bogus', [1], ShareItem::PERMISSION_READ);
	}

	public function testUpdateShareItemsThrowsOnInvalidPermission(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid permission: admin');

		$this->service->updateShareItems('alice', 100, ShareItem::TYPE_ACCOUNT, [1], 'admin');
	}

	public function testUpdateShareItemsThrowsWhenNotShareOwner(): void {
		$share = $this->makeShare(100, 'bob', 'carol', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findById')
			->with(100)
			->willReturn($share);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('You are not the owner of this share');

		$this->service->updateShareItems('alice', 100, ShareItem::TYPE_ACCOUNT, [1], ShareItem::PERMISSION_READ);
	}

	public function testUpdateShareItemsThrowsWhenEntityDoesNotBelongToOwner(): void {
		$share = $this->makeShare(100, 'alice', 'carol', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findById')
			->with(100)
			->willReturn($share);

		// Alice owns account 1 but not account 99
		$ownAccount = $this->makeEntity(1);
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([$ownAccount]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Some entities do not belong to you');

		$this->service->updateShareItems('alice', 100, ShareItem::TYPE_ACCOUNT, [1, 99], ShareItem::PERMISSION_READ);
	}

	public function testUpdateShareItemsSuccessDelegatesToMapper(): void {
		$share = $this->makeShare(100, 'alice', 'carol', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findById')
			->with(100)
			->willReturn($share);

		$ownAccount1 = $this->makeEntity(1);
		$ownAccount2 = $this->makeEntity(2);
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([$ownAccount1, $ownAccount2]);

		$this->shareItemMapper->expects($this->once())
			->method('replaceForShareAndType')
			->with(100, ShareItem::TYPE_ACCOUNT, [1, 2], ShareItem::PERMISSION_WRITE);

		$this->service->updateShareItems('alice', 100, ShareItem::TYPE_ACCOUNT, [1, 2], ShareItem::PERMISSION_WRITE);
	}

	// =============================================
	// Full control (categories only)
	// =============================================

	private function shareCategoryToAlice(string $permission): void {
		$this->categoryMapper->method('findAll')->with('alice')->willReturn([]);
		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')->with('alice')->willReturn([$share]);
		$this->shareItemMapper->method('getEntityPermission')
			->with(100, ShareItem::TYPE_CATEGORY, 5)
			->willReturn($permission);
	}

	public function testFullControlCountsAsWrite(): void {
		$this->shareCategoryToAlice(ShareItem::PERMISSION_FULL);

		$this->assertTrue($this->service->canWrite('alice', ShareItem::TYPE_CATEGORY, 5));
		$this->assertTrue($this->service->canManage('alice', ShareItem::TYPE_CATEGORY, 5));
	}

	public function testWriteShareDoesNotManage(): void {
		$this->shareCategoryToAlice(ShareItem::PERMISSION_WRITE);

		$this->assertTrue($this->service->canWrite('alice', ShareItem::TYPE_CATEGORY, 5));
		$this->assertFalse($this->service->canManage('alice', ShareItem::TYPE_CATEGORY, 5));
	}

	public function testOwnCategoryIsAlwaysManaged(): void {
		$own = new \OCA\Budget\Db\Category();
		$own->setId(5);
		$this->categoryMapper->method('findAll')->with('alice')->willReturn([$own]);
		$this->shareItemMapper->expects($this->never())->method('getEntityPermission');

		$this->assertTrue($this->service->canManage('alice', ShareItem::TYPE_CATEGORY, 5));
	}

	public function testUpdateShareItemsRefusesFullControlOutsideCategories(): void {
		$this->shareItemMapper->expects($this->never())->method('replaceForShareAndType');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid permission: full');
		$this->service->updateShareItems('alice', 100, ShareItem::TYPE_ACCOUNT, [1], ShareItem::PERMISSION_FULL);
	}

	public function testUpdateShareItemsAcceptsFullControlOnCategories(): void {
		$share = $this->makeShare(100, 'alice', 'carol', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findById')->with(100)->willReturn($share);
		$own = new \OCA\Budget\Db\Category();
		$own->setId(3);
		$this->categoryMapper->method('findAll')->with('alice')->willReturn([$own]);

		$this->shareItemMapper->expects($this->once())
			->method('replaceForShareAndType')
			->with(100, ShareItem::TYPE_CATEGORY, [3], ShareItem::PERMISSION_FULL);

		$this->service->updateShareItems('alice', 100, ShareItem::TYPE_CATEGORY, [3], ShareItem::PERMISSION_FULL);
	}

	public function testShareBackUsesThePermissionHeldOnTheParent(): void {
		// bob shares with alice (100, full on parent 5) and with carol (200)
		$toAlice = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$fromDave = $this->makeShare(300, 'dave', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')->with('alice')->willReturn([$fromDave, $toAlice]);
		$this->shareItemMapper->method('getEntityPermission')
			->with(100, ShareItem::TYPE_CATEGORY, 5)
			->willReturn(ShareItem::PERMISSION_FULL);

		$this->shareItemMapper->expects($this->once())
			->method('shareEntity')
			->with(100, ShareItem::TYPE_CATEGORY, 42, ShareItem::PERMISSION_FULL);

		$this->service->shareBackToRecipient('bob', 'alice', ShareItem::TYPE_CATEGORY, 42, 5);
	}

	public function testGetSharedCategoriesFlagsManageAndCreatorDelete(): void {
		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')->with('alice')->willReturn([$share]);
		$this->categoryMapper->method('findAll')->with('alice')->willReturn([]);
		$this->shareItemMapper->method('findSharedEntityIds')->willReturn([5, 6]);
		$this->shareItemMapper->method('getEntityPermission')->willReturn(ShareItem::PERMISSION_FULL);

		$parent = new \OCA\Budget\Db\Category();
		$parent->setId(5);
		$parent->setUserId('bob');
		$parent->setName('Food');
		$added = new \OCA\Budget\Db\Category();
		$added->setId(6);
		$added->setUserId('bob');
		$added->setName('Snacks');
		$added->setCreatedBy('alice');
		$this->categoryMapper->method('findByIdsUnscoped')->willReturn([$parent, $added]);

		$byId = [];
		foreach ($this->service->getSharedCategories('alice') as $row) {
			$byId[$row['id']] = $row;
		}

		$this->assertTrue($byId[5]['_canManage']);
		$this->assertFalse($byId[5]['_canDelete']);
		$this->assertTrue($byId[6]['_canDelete']);
	}

	// =============================================
	// getSharedAccounts
	// =============================================

	public function testGetSharedAccountsReturnsAccountsWithSharedFlag(): void {
		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$share]);

		$this->shareItemMapper->method('findSharedEntityIds')
			->with(100, ShareItem::TYPE_ACCOUNT)
			->willReturn([5]);

		$account = new Account();
		$account->setId(5);
		$account->setName('Shared Checking');
		$account->setUserId('bob');
		$account->setType('checking');
		$account->setBalance(0.0);
		$account->setCurrency('USD');

		$this->accountMapper->method('findByIds')
			->with([5])
			->willReturn([$account]);

		$result = $this->service->getSharedAccounts('alice');

		$this->assertCount(1, $result);
		$this->assertSame(5, $result[0]['id']);
		$this->assertSame('Shared Checking', $result[0]['name']);
		$this->assertTrue($result[0]['_shared']);
	}

	public function testGetSharedAccountsReturnsEmptyWhenNoSharedIds(): void {
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([]);

		$this->accountMapper->expects($this->never())
			->method('findByIds');

		$result = $this->service->getSharedAccounts('alice');

		$this->assertSame([], $result);
	}

	// =============================================
	// getAcceptedIncomingShares
	// =============================================

	public function testGetAcceptedIncomingSharesFiltersByStatusAccepted(): void {
		$accepted = $this->makeShare(1, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$pending = $this->makeShare(2, 'carol', 'alice', Share::STATUS_PENDING);
		$declined = $this->makeShare(3, 'dave', 'alice', Share::STATUS_DECLINED);

		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$accepted, $pending, $declined]);

		$result = $this->service->getAcceptedIncomingShares('alice');

		$this->assertCount(1, $result);
		$this->assertSame(1, $result[0]->getId());
		$this->assertSame(Share::STATUS_ACCEPTED, $result[0]->getStatus());
	}

	// =============================================
	// getOwnAccountIds
	// =============================================

	public function testGetOwnAccountIdsDelegatesToAccountMapper(): void {
		$account1 = $this->makeEntity(1);
		$account2 = $this->makeEntity(2);

		$this->accountMapper->expects($this->once())
			->method('findAll')
			->with('alice')
			->willReturn([$account1, $account2]);

		$result = $this->service->getOwnAccountIds('alice');

		$this->assertSame([1, 2], $result);
	}

	// =============================================
	// resolveOwner
	// =============================================

	private function makeSavingsGoal(int $id, string $owner): \OCA\Budget\Db\SavingsGoal {
		$goal = new \OCA\Budget\Db\SavingsGoal();
		$goal->setId($id);
		$goal->setUserId($owner);
		return $goal;
	}

	public function testResolveOwnerReturnsUserForOwnEntity(): void {
		$this->savingsGoalMapper->method('findAll')
			->with('alice')
			->willReturn([$this->makeSavingsGoal(1, 'alice')]);
		$this->shareMapper->method('findByRecipient')->with('alice')->willReturn([]);

		$this->assertSame('alice', $this->service->resolveOwner('alice', ShareItem::TYPE_SAVINGS_GOAL, 1));
	}

	public function testResolveOwnerReturnsShareOwnerForSharedEntity(): void {
		$this->savingsGoalMapper->method('findAll')->with('alice')->willReturn([]);
		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')->with('alice')->willReturn([$share]);
		$this->shareItemMapper->method('findSharedEntityIds')
			->with(100, ShareItem::TYPE_SAVINGS_GOAL)
			->willReturn([5]);

		$this->assertSame('bob', $this->service->resolveOwner('alice', ShareItem::TYPE_SAVINGS_GOAL, 5));
	}

	public function testResolveOwnerReturnsNullForInaccessibleEntity(): void {
		$this->savingsGoalMapper->method('findAll')->with('alice')->willReturn([]);
		$this->shareMapper->method('findByRecipient')->with('alice')->willReturn([]);

		$this->assertNull($this->service->resolveOwner('alice', ShareItem::TYPE_SAVINGS_GOAL, 999));
	}

	// =============================================
	// getSharedBills
	// =============================================

	public function testGetSharedBillsNeverOffersMarkUnpaid(): void {
		// markUnpaid's owner-scoped find can never succeed for a share
		// recipient, so serializing the owner's canMarkUnpaid hint would give
		// recipients a button that always 400s (#365 review).
		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')->with('alice')->willReturn([$share]);
		$this->shareItemMapper->method('findSharedEntityIds')
			->with(100, ShareItem::TYPE_BILL)
			->willReturn([7]);

		$bill = new Bill();
		$bill->setId(7);
		$bill->setUserId('bob');
		$bill->setName('Rent');
		$bill->setAmount(100.0);
		$bill->setFrequency('monthly');
		$bill->setIsActive(true);
		$bill->setPaidUndoState('{"previousState":{"isActive":true}}');
		$this->billMapper->method('findByIds')->with([7])->willReturn([$bill]);

		$result = $this->service->getSharedBills('alice');

		$this->assertCount(1, $result);
		$this->assertTrue($result[0]['_shared']);
		$this->assertTrue($bill->jsonSerialize()['canMarkUnpaid'], 'the owner-side hint stays on');
		$this->assertFalse($result[0]['canMarkUnpaid'], 'recipients must never be offered the action');
	}

	public function testGetSharedBillEntitiesReturnsTheBillsThemselves(): void {
		// The Bills page summary works on entities, so it needs the shared
		// bills unserialised
		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')->with('alice')->willReturn([$share]);
		$this->shareItemMapper->method('findSharedEntityIds')->with(100, ShareItem::TYPE_BILL)->willReturn([7]);
		$bill = new Bill();
		$bill->setId(7);
		$bill->setUserId('bob');
		$this->billMapper->method('findByIds')->with([7])->willReturn([$bill]);

		$this->assertSame([$bill], $this->service->getSharedBillEntities('alice'));
	}

	public function testGetSharedBillEntitiesWithNothingShared(): void {
		$this->shareMapper->method('findByRecipient')->willReturn([]);
		$this->billMapper->expects($this->never())->method('findByIds');

		$this->assertSame([], $this->service->getSharedBillEntities('alice'));
	}

	// =============================================
	// getWritableAccountIds
	// =============================================

	public function testGetWritableAccountIdsDropsReadOnlyShares(): void {
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([$this->makeEntity(1)]);

		$share = $this->makeShare(100, 'bob', 'alice', Share::STATUS_ACCEPTED);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([$share]);

		$this->shareItemMapper->method('findSharedEntityIds')
			->with(100, ShareItem::TYPE_ACCOUNT)
			->willReturn([5, 6]);

		// 5 shared read/write, 6 shared read-only
		$this->shareItemMapper->method('getEntityPermission')->willReturnCallback(
			fn (int $shareId, string $type, int $entityId) => $entityId === 5
				? ShareItem::PERMISSION_WRITE
				: ShareItem::PERMISSION_READ
		);

		$this->assertEqualsCanonicalizing([1, 5], $this->service->getWritableAccountIds('alice'));
	}

	public function testGetWritableAccountIdsKeepsEveryOwnAccount(): void {
		$this->accountMapper->method('findAll')
			->with('alice')
			->willReturn([$this->makeEntity(1), $this->makeEntity(2)]);
		$this->shareMapper->method('findByRecipient')
			->with('alice')
			->willReturn([]);

		$this->assertEqualsCanonicalizing([1, 2], $this->service->getWritableAccountIds('alice'));
	}

	// =============================================
	// projects
	// =============================================

	public function testProjectsAreSharedLikeAnyOtherType(): void {
		$project = new Project();
		$project->setId(10);
		$project->setUserId('owner');
		$this->projectMapper->method('findAll')
			->willReturnCallback(fn (string $uid) => $uid === 'owner' ? [$project] : []);
		$this->shareMapper->method('findByRecipient')
			->willReturnCallback(fn (string $uid) => $uid === 'bob'
				? [$this->makeShare(1, 'owner', 'bob', Share::STATUS_ACCEPTED)]
				: []);
		$this->shareItemMapper->method('findSharedEntityIds')
			->willReturnCallback(fn (int $shareId, string $type) => $type === ShareItem::TYPE_PROJECT ? [10] : []);
		$this->shareItemMapper->method('getEntityPermission')->willReturn(ShareItem::PERMISSION_READ);

		$this->assertSame([10], $this->service->getSharedProjectIds('bob'));
		$this->assertTrue($this->service->canAccess('bob', ShareItem::TYPE_PROJECT, 10));
		$this->assertFalse($this->service->canWrite('bob', ShareItem::TYPE_PROJECT, 10));
		$this->assertSame('owner', $this->service->resolveOwner('bob', ShareItem::TYPE_PROJECT, 10));
		$this->assertTrue($this->service->canWrite('owner', ShareItem::TYPE_PROJECT, 10));
	}

	public function testAProjectSharedAtReadAndWriteIsWritable(): void {
		$this->projectMapper->method('findAll')->willReturn([]);
		$this->shareMapper->method('findByRecipient')
			->willReturn([$this->makeShare(1, 'owner', 'bob', Share::STATUS_ACCEPTED)]);
		$this->shareItemMapper->method('getEntityPermission')->willReturn(ShareItem::PERMISSION_WRITE);

		$this->assertTrue($this->service->canWrite('bob', ShareItem::TYPE_PROJECT, 10));
	}

	public function testOwnerDisplayNameFallsBackToTheUid(): void {
		$this->assertSame('owner', $this->service->ownerDisplayName('owner'));
	}

	/**
	 * Who files into the owner's categories: the people they shared a
	 * category with, and which ones. The automatic budget counts their bills.
	 */
	public function testCategoryShareRecipientsAreTheAcceptedSharesWithCategories(): void {
		$this->shareMapper->method('findByOwner')->with('alice')->willReturn([
			$this->makeShare(1, 'alice', 'bob', Share::STATUS_ACCEPTED),
			$this->makeShare(2, 'alice', 'carol', Share::STATUS_PENDING),
			$this->makeShare(3, 'alice', 'dan', Share::STATUS_ACCEPTED),
		]);
		$this->shareItemMapper->method('findSharedEntityIds')->willReturnMap([
			[1, ShareItem::TYPE_CATEGORY, [10, 11]],
			[2, ShareItem::TYPE_CATEGORY, [12]],
			[3, ShareItem::TYPE_CATEGORY, []],
		]);

		$this->assertSame(['bob' => [10, 11]], $this->service->getCategoryShareRecipients('alice'));
	}
}
