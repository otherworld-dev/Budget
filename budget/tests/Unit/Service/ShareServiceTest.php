<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Share;
use OCA\Budget\Db\ShareItemMapper;
use OCA\Budget\Db\ShareMapper;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class ShareServiceTest extends TestCase {
    private ShareService $service;
    private ShareMapper $mapper;
    private ShareItemMapper $shareItemMapper;
    private AuditService $auditService;
    private IUserManager $userManager;
    private INotificationManager $notificationManager;

    protected function setUp(): void {
        $this->mapper = $this->createMock(ShareMapper::class);
        $this->shareItemMapper = $this->createMock(ShareItemMapper::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);

        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(function (string $text, array $params = []) {
            foreach ($params as $i => $param) {
                $text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
            }
            return $text;
        });

        $this->service = new ShareService(
            $this->mapper,
            $this->shareItemMapper,
            $this->auditService,
            $this->userManager,
            $this->notificationManager,
            $l
        );
    }

    private function makeShare(array $overrides = []): Share {
        $share = new Share();
        $defaults = [
            'id' => 1,
            'ownerUserId' => 'owner1',
            'sharedWithUserId' => 'recipient1',
            'status' => Share::STATUS_PENDING,
        ];
        $data = array_merge($defaults, $overrides);

        $share->setId($data['id']);
        $share->setOwnerUserId($data['ownerUserId']);
        $share->setSharedWithUserId($data['sharedWithUserId']);
        $share->setStatus($data['status']);
        $share->setCreatedAt('2026-01-01 00:00:00');
        $share->setUpdatedAt('2026-01-01 00:00:00');
        return $share;
    }

    private function mockRecipientExists(string $userId = 'recipient1'): void {
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn('Recipient User');
        $this->userManager->method('get')->willReturn($user);
    }

    private function mockNotificationCreation(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();
        $this->notificationManager->method('createNotification')->willReturn($notification);
    }

    // ===== shareWith() =====

    public function testShareWithThrowsWhenSharingWithSelf(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You cannot share your budget with yourself');

        $this->service->shareWith('user1', 'user1');
    }

    public function testShareWithThrowsWhenRecipientDoesNotExist(): void {
        $this->userManager->method('get')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to share with this user');

        $this->service->shareWith('owner1', 'nonexistent');
    }

    public function testShareWithThrowsWhenAlreadyAccepted(): void {
        $this->mockRecipientExists();

        $existing = $this->makeShare(['status' => Share::STATUS_ACCEPTED]);
        $this->mapper->method('findByOwnerAndRecipient')->willReturn($existing);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Budget is already shared with this user');

        $this->service->shareWith('owner1', 'recipient1');
    }

    public function testShareWithThrowsWhenAlreadyPending(): void {
        $this->mockRecipientExists();

        $existing = $this->makeShare(['status' => Share::STATUS_PENDING]);
        $this->mapper->method('findByOwnerAndRecipient')->willReturn($existing);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A pending share already exists for this user');

        $this->service->shareWith('owner1', 'recipient1');
    }

    public function testShareWithReSharesDeclinedShare(): void {
        $this->mockRecipientExists();
        $this->mockNotificationCreation();

        $existing = $this->makeShare(['status' => Share::STATUS_DECLINED]);
        $this->mapper->method('findByOwnerAndRecipient')->willReturn($existing);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (Share $share) {
                $this->assertEquals(Share::STATUS_PENDING, $share->getStatus());
                return $share;
            });

        $this->mapper->expects($this->never())->method('insert');

        $this->auditService->expects($this->once())
            ->method('log')
            ->with('owner1', 'share_created', 'share', 1, ['sharedWith' => 'recipient1']);

        $result = $this->service->shareWith('owner1', 'recipient1');
        $this->assertEquals(Share::STATUS_PENDING, $result->getStatus());
    }

    public function testShareWithCreatesNewShareWhenNoneExists(): void {
        $this->mockRecipientExists();
        $this->mockNotificationCreation();

        $this->mapper->method('findByOwnerAndRecipient')
            ->willThrowException(new DoesNotExistException(''));

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Share $share) {
                $this->assertEquals('owner1', $share->getOwnerUserId());
                $this->assertEquals('recipient1', $share->getSharedWithUserId());
                $this->assertEquals(Share::STATUS_PENDING, $share->getStatus());
                $share->setId(42);
                return $share;
            });

        $this->notificationManager->expects($this->once())->method('notify');

        $this->auditService->expects($this->once())
            ->method('log')
            ->with('owner1', 'share_created', 'share', 42, ['sharedWith' => 'recipient1']);

        $result = $this->service->shareWith('owner1', 'recipient1');
        $this->assertEquals('owner1', $result->getOwnerUserId());
    }

    // ===== accept() =====

    public function testAcceptThrowsWhenNotRecipient(): void {
        $share = $this->makeShare(['sharedWithUserId' => 'recipient1']);
        $this->mapper->method('findById')->willReturn($share);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not the recipient of this share');

        $this->service->accept(1, 'wronguser');
    }

    public function testAcceptThrowsWhenShareNotPending(): void {
        $share = $this->makeShare(['status' => Share::STATUS_ACCEPTED]);
        $this->mapper->method('findById')->willReturn($share);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('This share is not pending');

        $this->service->accept(1, 'recipient1');
    }

    public function testAcceptSetsStatusAcceptedAndLogsAudit(): void {
        $share = $this->makeShare(['status' => Share::STATUS_PENDING]);
        $this->mapper->method('findById')->willReturn($share);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (Share $s) {
                $this->assertEquals(Share::STATUS_ACCEPTED, $s->getStatus());
                return $s;
            });

        $this->auditService->expects($this->once())
            ->method('log')
            ->with('recipient1', 'share_accepted', 'share', 1, ['owner' => 'owner1']);

        $result = $this->service->accept(1, 'recipient1');
        $this->assertEquals(Share::STATUS_ACCEPTED, $result->getStatus());
    }

    // ===== decline() =====

    public function testDeclineThrowsWhenNotRecipient(): void {
        $share = $this->makeShare(['sharedWithUserId' => 'recipient1']);
        $this->mapper->method('findById')->willReturn($share);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not the recipient of this share');

        $this->service->decline(1, 'wronguser');
    }

    public function testDeclineSetsStatusDeclined(): void {
        $share = $this->makeShare(['status' => Share::STATUS_PENDING]);
        $this->mapper->method('findById')->willReturn($share);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (Share $s) {
                $this->assertEquals(Share::STATUS_DECLINED, $s->getStatus());
                return $s;
            });

        $this->auditService->expects($this->once())
            ->method('log')
            ->with('recipient1', 'share_declined', 'share', 1, ['owner' => 'owner1']);

        $result = $this->service->decline(1, 'recipient1');
        $this->assertEquals(Share::STATUS_DECLINED, $result->getStatus());
    }

    // ===== revoke() =====

    public function testRevokeThrowsWhenNotOwner(): void {
        $share = $this->makeShare(['ownerUserId' => 'owner1']);
        $this->mapper->method('findById')->willReturn($share);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not the owner of this share');

        $this->service->revoke(1, 'wronguser');
    }

    public function testRevokeDeletesItemsThenShareAndDismissesNotification(): void {
        $share = $this->makeShare();
        $this->mapper->method('findById')->willReturn($share);

        $this->shareItemMapper->expects($this->once())
            ->method('deleteByShareId')
            ->with(1);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($share);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $this->notificationManager->method('createNotification')->willReturn($notification);

        $this->notificationManager->expects($this->once())
            ->method('markProcessed')
            ->with($notification);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with('owner1', 'share_revoked', 'share', 1, ['sharedWith' => 'recipient1']);

        $this->service->revoke(1, 'owner1');
    }

    // ===== leave() =====

    public function testLeaveThrowsWhenNotRecipient(): void {
        $share = $this->makeShare(['sharedWithUserId' => 'recipient1']);
        $this->mapper->method('findById')->willReturn($share);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You are not the recipient of this share');

        $this->service->leave(1, 'wronguser');
    }

    public function testLeaveDeletesItemsThenShareAndLogsAudit(): void {
        $share = $this->makeShare();
        $this->mapper->method('findById')->willReturn($share);

        $this->shareItemMapper->expects($this->once())
            ->method('deleteByShareId')
            ->with(1);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($share);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with('recipient1', 'share_left', 'share', 1, ['owner' => 'owner1']);

        $this->service->leave(1, 'recipient1');
    }

    // ===== getOutgoingShares() =====

    public function testGetOutgoingSharesDelegatesToMapper(): void {
        $shares = [$this->makeShare()];
        $this->mapper->expects($this->once())
            ->method('findByOwner')
            ->with('owner1')
            ->willReturn($shares);

        $result = $this->service->getOutgoingShares('owner1');
        $this->assertCount(1, $result);
    }

    // ===== getIncomingShares() =====

    public function testGetIncomingSharesDelegatesToMapper(): void {
        $shares = [$this->makeShare()];
        $this->mapper->expects($this->once())
            ->method('findByRecipient')
            ->with('recipient1')
            ->willReturn($shares);

        $result = $this->service->getIncomingShares('recipient1');
        $this->assertCount(1, $result);
    }

    // ===== getPendingShares() =====

    public function testGetPendingSharesDelegatesToMapper(): void {
        $shares = [$this->makeShare()];
        $this->mapper->expects($this->once())
            ->method('findPendingForRecipient')
            ->with('recipient1')
            ->willReturn($shares);

        $result = $this->service->getPendingShares('recipient1');
        $this->assertCount(1, $result);
    }

    // ===== getAcceptedOwnerIds() =====

    public function testGetAcceptedOwnerIdsDelegatesToMapper(): void {
        $this->mapper->expects($this->once())
            ->method('findAcceptedOwnerIds')
            ->with('recipient1')
            ->willReturn(['owner1', 'owner2']);

        $result = $this->service->getAcceptedOwnerIds('recipient1');
        $this->assertEquals(['owner1', 'owner2'], $result);
    }
}
