<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\ShareService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ShareController extends Controller {
    use ApiErrorHandlerTrait;

    private ShareService $shareService;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        ShareService $shareService,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->shareService = $shareService;
        $this->l = $l;
        $this->userId = $userId;
        $this->setLogger($logger);
    }

    /**
     * Get all outgoing shares (budgets I've shared)
     *
     * @NoAdminRequired
     */
    public function outgoing(): DataResponse {
        try {
            $shares = $this->shareService->getOutgoingShares($this->userId);
            return new DataResponse($shares);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve shares'));
        }
    }

    /**
     * Get all incoming shares (budgets shared with me)
     *
     * @NoAdminRequired
     */
    public function incoming(): DataResponse {
        try {
            $shares = $this->shareService->getIncomingShares($this->userId);
            return new DataResponse($shares);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve shares'));
        }
    }

    /**
     * Get pending share invitations for the current user
     *
     * @NoAdminRequired
     */
    public function pending(): DataResponse {
        try {
            $shares = $this->shareService->getPendingShares($this->userId);
            return new DataResponse($shares);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve pending shares'));
        }
    }

    /**
     * Share budget with another user
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function create(string $sharedWithUserId): DataResponse {
        try {
            $share = $this->shareService->shareWith($this->userId, $sharedWithUserId);
            return new DataResponse($share, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to create share'));
        }
    }

    /**
     * Accept a pending share invitation
     *
     * @NoAdminRequired
     */
    public function accept(int $id): DataResponse {
        try {
            $share = $this->shareService->accept($id, $this->userId);
            return new DataResponse($share);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (DoesNotExistException $e) {
            return $this->handleNotFoundError($e, $this->l->t('Share'));
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to accept share'));
        }
    }

    /**
     * Decline a pending share invitation
     *
     * @NoAdminRequired
     */
    public function decline(int $id): DataResponse {
        try {
            $share = $this->shareService->decline($id, $this->userId);
            return new DataResponse($share);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (DoesNotExistException $e) {
            return $this->handleNotFoundError($e, $this->l->t('Share'));
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to decline share'));
        }
    }

    /**
     * Revoke a share (owner removes access)
     *
     * @NoAdminRequired
     */
    public function revoke(int $id): DataResponse {
        try {
            $this->shareService->revoke($id, $this->userId);
            return new DataResponse(['status' => 'success']);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (DoesNotExistException $e) {
            return $this->handleNotFoundError($e, $this->l->t('Share'));
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to revoke share'));
        }
    }

    /**
     * Leave a share (recipient removes self)
     *
     * @NoAdminRequired
     */
    public function leave(int $id): DataResponse {
        try {
            $this->shareService->leave($id, $this->userId);
            return new DataResponse(['status' => 'success']);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (DoesNotExistException $e) {
            return $this->handleNotFoundError($e, $this->l->t('Share'));
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to leave share'));
        }
    }
}
