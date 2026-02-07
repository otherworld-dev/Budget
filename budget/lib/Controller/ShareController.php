<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\ShareService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ShareController extends Controller {
	use ApiErrorHandlerTrait;

	private ShareService $shareService;
	private AuditService $auditService;
	private string $userId;

	public function __construct(
		IRequest $request,
		ShareService $shareService,
		AuditService $auditService,
		string $userId,
		LoggerInterface $logger
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->shareService = $shareService;
		$this->auditService = $auditService;
		$this->userId = $userId;
		$this->setLogger($logger);
	}

	/**
	 * Get all shares (both owned and accessible by the user)
	 *
	 * @NoAdminRequired
	 */
	public function index(): DataResponse {
		try {
			$ownedShares = $this->shareService->getSharesByOwner($this->userId);
			$accessibleShares = $this->shareService->getSharesAccessibleBy($this->userId);

			return new DataResponse([
				'owned' => $ownedShares,
				'received' => $accessibleShares, // Frontend expects 'received' not 'accessible'
			]);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to retrieve shares');
		}
	}

	/**
	 * Create a new share
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 10, period: 60)]
	public function create(string $sharedWithUserId, string $permissionLevel = 'read'): DataResponse {
		try {
			// Validate input
			if (empty($sharedWithUserId)) {
				return new DataResponse(
					['error' => 'User ID is required'],
					Http::STATUS_BAD_REQUEST
				);
			}

			// Create the share
			$share = $this->shareService->shareWith($this->userId, $sharedWithUserId, $permissionLevel);

			// Audit log
			$this->auditService->log($this->userId, 'share_created', 'share', $share->getId(), [
				'sharedWithUserId' => $sharedWithUserId,
				'permissionLevel' => $permissionLevel,
			]);

			// Get enriched share data
			$shares = $this->shareService->getSharesByOwner($this->userId);
			$enrichedShare = null;
			foreach ($shares as $s) {
				if ($s['id'] === $share->getId()) {
					$enrichedShare = $s;
					break;
				}
			}

			return new DataResponse($enrichedShare ?? $share, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to create share');
		}
	}

	/**
	 * Revoke a share
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function destroy(string $sharedWithUserId): DataResponse {
		try {
			if (empty($sharedWithUserId)) {
				return new DataResponse(
					['error' => 'User ID is required'],
					Http::STATUS_BAD_REQUEST
				);
			}

			$revoked = $this->shareService->revokeAccess($this->userId, $sharedWithUserId);

			if (!$revoked) {
				return new DataResponse(
					['error' => 'Share not found'],
					Http::STATUS_NOT_FOUND
				);
			}

			// Audit log
			$this->auditService->log($this->userId, 'share_revoked', 'share', 0, [
				'sharedWithUserId' => $sharedWithUserId,
			]);

			return new DataResponse(['message' => 'Share revoked successfully']);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to revoke share');
		}
	}

	/**
	 * Search for Nextcloud users to share with
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function searchUsers(string $query = ''): DataResponse {
		try {
			if (empty($query) || strlen($query) < 2) {
				return new DataResponse(
					['error' => 'Search query must be at least 2 characters'],
					Http::STATUS_BAD_REQUEST
				);
			}

			$users = $this->shareService->searchUsers($query, 15);

			// Remove the current user from results
			$users = array_filter($users, function ($user) {
				return $user['userId'] !== $this->userId;
			});

			return new DataResponse(array_values($users));
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to search users');
		}
	}

	/**
	 * Get information about accessible user IDs
	 * This is used internally by the frontend to know which budgets the user can access
	 *
	 * @NoAdminRequired
	 */
	public function getAccessibleUsers(): DataResponse {
		try {
			$userIds = $this->shareService->getAccessibleUserIds($this->userId);

			// Enrich with display information
			$users = [];
			foreach ($userIds as $userId) {
				$users[] = $this->shareService->getUserDisplayInfo($userId);
			}

			return new DataResponse($users);
		} catch (\Exception $e) {
			return $this->handleError($e, 'Failed to retrieve accessible users');
		}
	}
}
