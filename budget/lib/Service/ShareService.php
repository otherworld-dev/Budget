<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use DateTime;
use OCA\Budget\Db\Share;
use OCA\Budget\Db\ShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use OCP\IL10N;
use OCP\Notification\IManager as INotificationManager;

class ShareService {
    private ShareMapper $mapper;
    private AuditService $auditService;
    private IUserManager $userManager;
    private INotificationManager $notificationManager;
    private IL10N $l;

    public function __construct(
        ShareMapper $mapper,
        AuditService $auditService,
        IUserManager $userManager,
        INotificationManager $notificationManager,
        IL10N $l
    ) {
        $this->mapper = $mapper;
        $this->auditService = $auditService;
        $this->userManager = $userManager;
        $this->notificationManager = $notificationManager;
        $this->l = $l;
    }

    /**
     * Share budget with another Nextcloud user.
     * Creates a pending share that the recipient must accept.
     */
    public function shareWith(string $ownerUserId, string $sharedWithUserId): Share {
        // Cannot share with yourself
        if ($ownerUserId === $sharedWithUserId) {
            throw new \InvalidArgumentException($this->l->t('You cannot share your budget with yourself'));
        }

        // Verify recipient exists in Nextcloud
        $recipient = $this->userManager->get($sharedWithUserId);
        if ($recipient === null) {
            throw new \InvalidArgumentException($this->l->t('User not found: %1$s', [$sharedWithUserId]));
        }

        // Check for existing share (any status)
        try {
            $existing = $this->mapper->findByOwnerAndRecipient($ownerUserId, $sharedWithUserId);
            if ($existing->getStatus() === Share::STATUS_ACCEPTED) {
                throw new \InvalidArgumentException($this->l->t('Budget is already shared with this user'));
            }
            if ($existing->getStatus() === Share::STATUS_PENDING) {
                throw new \InvalidArgumentException($this->l->t('A pending share already exists for this user'));
            }
            // If declined, allow re-sharing by updating the existing record
            $existing->setStatus(Share::STATUS_PENDING);
            $existing->setUpdatedAt(date('Y-m-d H:i:s'));
            $share = $this->mapper->update($existing);
        } catch (DoesNotExistException $e) {
            $share = new Share();
            $share->setOwnerUserId($ownerUserId);
            $share->setSharedWithUserId($sharedWithUserId);
            $share->setStatus(Share::STATUS_PENDING);
            $share->setCreatedAt(date('Y-m-d H:i:s'));
            $share->setUpdatedAt(date('Y-m-d H:i:s'));
            $share = $this->mapper->insert($share);
        }

        // Send notification to recipient
        $this->sendShareNotification($ownerUserId, $sharedWithUserId, $share->getId());

        $this->auditService->log(
            $ownerUserId,
            'share_created',
            'share',
            $share->getId(),
            ['sharedWith' => $sharedWithUserId]
        );

        return $share;
    }

    /**
     * Accept a pending share invitation
     */
    public function accept(int $shareId, string $recipientUserId): Share {
        $share = $this->mapper->findById($shareId);

        if ($share->getSharedWithUserId() !== $recipientUserId) {
            throw new \InvalidArgumentException($this->l->t('You are not the recipient of this share'));
        }

        if ($share->getStatus() !== Share::STATUS_PENDING) {
            throw new \InvalidArgumentException($this->l->t('This share is not pending'));
        }

        $share->setStatus(Share::STATUS_ACCEPTED);
        $share->setUpdatedAt(date('Y-m-d H:i:s'));
        $share = $this->mapper->update($share);

        $this->auditService->log(
            $recipientUserId,
            'share_accepted',
            'share',
            $shareId,
            ['owner' => $share->getOwnerUserId()]
        );

        return $share;
    }

    /**
     * Decline a pending share invitation
     */
    public function decline(int $shareId, string $recipientUserId): Share {
        $share = $this->mapper->findById($shareId);

        if ($share->getSharedWithUserId() !== $recipientUserId) {
            throw new \InvalidArgumentException($this->l->t('You are not the recipient of this share'));
        }

        if ($share->getStatus() !== Share::STATUS_PENDING) {
            throw new \InvalidArgumentException($this->l->t('This share is not pending'));
        }

        $share->setStatus(Share::STATUS_DECLINED);
        $share->setUpdatedAt(date('Y-m-d H:i:s'));
        $share = $this->mapper->update($share);

        $this->auditService->log(
            $recipientUserId,
            'share_declined',
            'share',
            $shareId,
            ['owner' => $share->getOwnerUserId()]
        );

        return $share;
    }

    /**
     * Revoke a share (owner removes access)
     */
    public function revoke(int $shareId, string $ownerUserId): void {
        $share = $this->mapper->findById($shareId);

        if ($share->getOwnerUserId() !== $ownerUserId) {
            throw new \InvalidArgumentException($this->l->t('You are not the owner of this share'));
        }

        $sharedWith = $share->getSharedWithUserId();
        $this->mapper->delete($share);

        // Dismiss any pending notifications for the revoked share
        $notification = $this->notificationManager->createNotification();
        $notification->setApp('budget')
            ->setUser($sharedWith)
            ->setObject('share', (string) $shareId);
        $this->notificationManager->markProcessed($notification);

        $this->auditService->log(
            $ownerUserId,
            'share_revoked',
            'share',
            $shareId,
            ['sharedWith' => $sharedWith]
        );
    }

    /**
     * Remove self from a share (recipient leaves)
     */
    public function leave(int $shareId, string $recipientUserId): void {
        $share = $this->mapper->findById($shareId);

        if ($share->getSharedWithUserId() !== $recipientUserId) {
            throw new \InvalidArgumentException($this->l->t('You are not the recipient of this share'));
        }

        $this->mapper->delete($share);

        $this->auditService->log(
            $recipientUserId,
            'share_left',
            'share',
            $shareId,
            ['owner' => $share->getOwnerUserId()]
        );
    }

    /**
     * Get all outgoing shares for an owner
     *
     * @return Share[]
     */
    public function getOutgoingShares(string $ownerUserId): array {
        return $this->mapper->findByOwner($ownerUserId);
    }

    /**
     * Get all incoming shares for a recipient
     *
     * @return Share[]
     */
    public function getIncomingShares(string $recipientUserId): array {
        return $this->mapper->findByRecipient($recipientUserId);
    }

    /**
     * Get pending incoming shares
     *
     * @return Share[]
     */
    public function getPendingShares(string $recipientUserId): array {
        return $this->mapper->findPendingForRecipient($recipientUserId);
    }

    /**
     * Get owner user IDs whose budgets the given user has accepted access to.
     *
     * @return string[]
     */
    public function getAcceptedOwnerIds(string $recipientUserId): array {
        return $this->mapper->findAcceptedOwnerIds($recipientUserId);
    }

    /**
     * Send a Nextcloud notification to the share recipient
     */
    private function sendShareNotification(string $ownerUserId, string $recipientUserId, int $shareId): void {
        $notification = $this->notificationManager->createNotification();

        $ownerUser = $this->userManager->get($ownerUserId);
        $ownerDisplayName = $ownerUser ? $ownerUser->getDisplayName() : $ownerUserId;

        $notification->setApp('budget')
            ->setUser($recipientUserId)
            ->setDateTime(new DateTime())
            ->setObject('share', (string) $shareId)
            ->setSubject('share_invitation', [
                'ownerUserId' => $ownerUserId,
                'ownerDisplayName' => $ownerDisplayName,
                'shareId' => $shareId,
            ]);

        $this->notificationManager->notify($notification);
    }
}
