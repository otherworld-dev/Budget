<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Share;
use OCA\Budget\Db\ShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;

class ShareService {
	private ShareMapper $mapper;
	private IUserManager $userManager;
	private AuditService $auditService;

	public function __construct(
		ShareMapper $mapper,
		IUserManager $userManager,
		AuditService $auditService
	) {
		$this->mapper = $mapper;
		$this->userManager = $userManager;
		$this->auditService = $auditService;
	}

	/**
	 * Share budget with another Nextcloud user
	 *
	 * @param string $ownerUserId User sharing their budget
	 * @param string $targetUserId User to share with (Nextcloud user ID or email)
	 * @param string $permissionLevel 'read' or 'write' (future)
	 * @return Share
	 * @throws \InvalidArgumentException If target user doesn't exist or is the same as owner
	 * @throws \RuntimeException If share already exists
	 */
	public function shareWith(string $ownerUserId, string $targetUserId, string $permissionLevel = 'read'): Share {
		// Validate that user is not sharing with themselves
		if ($ownerUserId === $targetUserId) {
			throw new \InvalidArgumentException('Cannot share budget with yourself');
		}

		// Validate that target user exists in Nextcloud
		$targetUser = $this->userManager->get($targetUserId);
		if (!$targetUser) {
			throw new \InvalidArgumentException('Target user does not exist: ' . $targetUserId);
		}

		// Check if share already exists
		try {
			$existingShare = $this->mapper->findShare($ownerUserId, $targetUserId);
			throw new \RuntimeException('Budget already shared with this user');
		} catch (DoesNotExistException $e) {
			// Good, share doesn't exist yet
		}

		// Validate permission level
		if (!in_array($permissionLevel, ['read', 'write'])) {
			throw new \InvalidArgumentException('Invalid permission level. Must be "read" or "write"');
		}

		// Create the share
		$share = new Share();
		$share->setOwnerUserId($ownerUserId);
		$share->setSharedWithUserId($targetUserId);
		$share->setPermissionLevel($permissionLevel);
		$share->setCreatedAt(date('Y-m-d H:i:s'));
		$share->setUpdatedAt(date('Y-m-d H:i:s'));

		$share = $this->mapper->insert($share);

		// Log share creation
		$this->auditService->logShareCreated(
			$ownerUserId,
			$share->getId(),
			$targetUserId,
			$permissionLevel
		);

		return $share;
	}

	/**
	 * Revoke access to budget from a user
	 *
	 * @param string $ownerUserId Owner of the budget
	 * @param string $sharedWithUserId User to revoke access from
	 * @return bool True if share was revoked, false if no share existed
	 */
	public function revokeAccess(string $ownerUserId, string $sharedWithUserId): bool {
		// Try to find the share before deleting so we can log it
		try {
			$share = $this->mapper->findShare($ownerUserId, $sharedWithUserId);
			$shareId = $share->getId();
		} catch (DoesNotExistException $e) {
			// Share doesn't exist
			return false;
		}

		$deletedRows = $this->mapper->revokeShare($ownerUserId, $sharedWithUserId);

		if ($deletedRows > 0) {
			// Log share revocation
			$this->auditService->logShareRevoked(
				$ownerUserId,
				$shareId,
				$sharedWithUserId
			);
			return true;
		}

		return false;
	}

	/**
	 * Get all users that have been granted access to the owner's budget
	 *
	 * @param string $ownerUserId
	 * @return array Array of shares with user display info
	 */
	public function getSharesByOwner(string $ownerUserId): array {
		$shares = $this->mapper->findSharesByOwner($ownerUserId);
		return $this->enrichSharesWithUserInfo($shares);
	}

	/**
	 * Get all budgets that have been shared with the user
	 *
	 * @param string $sharedWithUserId
	 * @return array Array of shares with owner display info
	 */
	public function getSharesAccessibleBy(string $sharedWithUserId): array {
		$shares = $this->mapper->findSharesAccessibleBy($sharedWithUserId);
		return $this->enrichSharesWithOwnerInfo($shares);
	}

	/**
	 * Get all user IDs that the current user can access (own + shared)
	 *
	 * @param string $currentUserId
	 * @return string[]
	 */
	public function getAccessibleUserIds(string $currentUserId): array {
		return $this->mapper->getAccessibleUserIds($currentUserId);
	}

	/**
	 * Check if a user can access another user's budget
	 *
	 * @param string $currentUserId User requesting access
	 * @param string $targetUserId Owner of the budget
	 * @return bool
	 */
	public function canUserAccessBudget(string $currentUserId, string $targetUserId): bool {
		return $this->mapper->hasAccess($currentUserId, $targetUserId);
	}

	/**
	 * Check if a user has read-only access (not the owner)
	 *
	 * @param string $currentUserId
	 * @param string $dataOwnerUserId
	 * @return bool
	 */
	public function isReadOnly(string $currentUserId, string $dataOwnerUserId): bool {
		return $currentUserId !== $dataOwnerUserId;
	}

	/**
	 * Get display information for a user (name, email)
	 *
	 * @param string $userId
	 * @return array ['userId' => string, 'displayName' => string, 'email' => string|null]
	 */
	public function getUserDisplayInfo(string $userId): array {
		$user = $this->userManager->get($userId);

		if (!$user) {
			return [
				'userId' => $userId,
				'displayName' => $userId,
				'email' => null,
			];
		}

		return [
			'userId' => $userId,
			'displayName' => $user->getDisplayName() ?: $userId,
			'email' => $user->getEMailAddress(),
		];
	}

	/**
	 * Search Nextcloud users for sharing
	 *
	 * @param string $query Search query (partial name or email)
	 * @param int $limit Maximum results to return
	 * @return array Array of user info
	 */
	public function searchUsers(string $query, int $limit = 10): array {
		$users = $this->userManager->search($query, $limit);

		$results = [];
		foreach ($users as $user) {
			$results[] = [
				'userId' => $user->getUID(),
				'displayName' => $user->getDisplayName() ?: $user->getUID(),
				'email' => $user->getEMailAddress(),
			];
		}

		return $results;
	}

	/**
	 * Enrich shares with shared user display information
	 *
	 * @param Share[] $shares
	 * @return array
	 */
	private function enrichSharesWithUserInfo(array $shares): array {
		$enriched = [];

		foreach ($shares as $share) {
			$userInfo = $this->getUserDisplayInfo($share->getSharedWithUserId());
			$enriched[] = array_merge($share->jsonSerialize(), [
				'sharedWithDisplayName' => $userInfo['displayName'],
				'sharedWithEmail' => $userInfo['email'],
			]);
		}

		return $enriched;
	}

	/**
	 * Enrich shares with owner display information
	 *
	 * @param Share[] $shares
	 * @return array
	 */
	private function enrichSharesWithOwnerInfo(array $shares): array {
		$enriched = [];

		foreach ($shares as $share) {
			$ownerInfo = $this->getUserDisplayInfo($share->getOwnerUserId());
			$enriched[] = array_merge($share->jsonSerialize(), [
				'ownerDisplayName' => $ownerInfo['displayName'],
				'ownerEmail' => $ownerInfo['email'],
			]);
		}

		return $enriched;
	}
}
