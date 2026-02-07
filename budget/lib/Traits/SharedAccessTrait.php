<?php

declare(strict_types=1);

namespace OCA\Budget\Traits;

use OCA\Budget\Service\ShareService;

/**
 * Trait to enable multi-user budget sharing in controllers
 *
 * Controllers using this trait can easily:
 * - Get list of accessible user IDs (own + shared budgets)
 * - Check if current user has access to another user's data
 * - Determine if current user has read-only access
 * - Pass accessible user IDs to mappers for multi-user queries
 */
trait SharedAccessTrait {
	private ShareService $shareService;

	/**
	 * Set the ShareService (should be called in controller constructor)
	 *
	 * @param ShareService $shareService
	 */
	protected function setShareService(ShareService $shareService): void {
		$this->shareService = $shareService;
	}

	/**
	 * Get all user IDs that the current user can access
	 * Returns array containing: [currentUserId, ...sharedOwnerUserIds]
	 *
	 * Use this to pass to mapper methods for multi-user data queries
	 *
	 * @param string $userId Current user ID
	 * @return string[] Array of accessible user IDs
	 */
	protected function getAccessibleUserIds(string $userId): array {
		return $this->shareService->getAccessibleUserIds($userId);
	}

	/**
	 * Check if current user has access to target user's budget
	 *
	 * @param string $currentUserId Current user requesting access
	 * @param string $targetUserId Owner of the budget being accessed
	 * @return bool True if access is allowed
	 */
	protected function canAccessUserBudget(string $currentUserId, string $targetUserId): bool {
		return $this->shareService->canUserAccessBudget($currentUserId, $targetUserId);
	}

	/**
	 * Check if current user has read-only access to data
	 * Returns true if the data belongs to another user (shared access)
	 * Returns false if the data belongs to current user (full access)
	 *
	 * Use this to enforce read-only restrictions on shared budgets
	 *
	 * @param string $currentUserId Current user ID
	 * @param string $dataOwnerUserId Owner of the data being accessed
	 * @return bool True if access is read-only (data belongs to someone else)
	 */
	protected function isReadOnlyAccess(string $currentUserId, string $dataOwnerUserId): bool {
		return $this->shareService->isReadOnly($currentUserId, $dataOwnerUserId);
	}

	/**
	 * Enforce read-only access restriction
	 * Throws an exception if user is trying to modify data they don't own
	 *
	 * Call this at the beginning of write operations (create, update, delete)
	 *
	 * @param string $currentUserId Current user ID
	 * @param string $dataOwnerUserId Owner of the data being modified
	 * @throws \RuntimeException If user doesn't have write access
	 */
	protected function enforceWriteAccess(string $currentUserId, string $dataOwnerUserId): void {
		if ($this->isReadOnlyAccess($currentUserId, $dataOwnerUserId)) {
			throw new \RuntimeException('Cannot modify data from shared budget. You have read-only access.');
		}
	}

	/**
	 * Get user display information for enriching API responses
	 * Useful for showing owner names in shared budget views
	 *
	 * @param string $userId User ID to get info for
	 * @return array ['userId' => string, 'displayName' => string, 'email' => string|null]
	 */
	protected function getUserDisplayInfo(string $userId): array {
		return $this->shareService->getUserDisplayInfo($userId);
	}
}
