<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
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
    private IL10N $l;

    protected function setUp(): void {
        $this->shareMapper = $this->createMock(ShareMapper::class);
        $this->shareItemMapper = $this->createMock(ShareItemMapper::class);
        $this->accountMapper = $this->createMock(AccountMapper::class);
        $this->billMapper = $this->createMock(BillMapper::class);
        $this->categoryMapper = $this->createMock(CategoryMapper::class);
        $this->recurringIncomeMapper = $this->createMock(RecurringIncomeMapper::class);
        $this->savingsGoalMapper = $this->createMock(SavingsGoalMapper::class);
        $this->l = $this->createMock(IL10N::class);

        $this->l->method('t')->willReturnCallback(
            fn(string $text, array $params = []) => vsprintf(str_replace('%1$s', '%s', $text), $params)
        );

        $this->service = new GranularShareService(
            $this->shareMapper,
            $this->shareItemMapper,
            $this->accountMapper,
            $this->billMapper,
            $this->categoryMapper,
            $this->recurringIncomeMapper,
            $this->savingsGoalMapper,
            $this->l
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
}
