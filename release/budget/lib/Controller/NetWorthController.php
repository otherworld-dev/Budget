<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\NetWorthService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class NetWorthController extends Controller {
    use ApiErrorHandlerTrait;

    private NetWorthService $service;
    private ?string $userId;

    public function __construct(
        IRequest $request,
        NetWorthService $service,
        ?string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userId;
        $this->setLogger($logger);
    }

    /**
     * Get the current user ID or throw an error if not authenticated.
     */
    private function getUserId(): string {
        if ($this->userId === null) {
            throw new \RuntimeException('User not authenticated');
        }
        return $this->userId;
    }

    /**
     * Get current net worth calculation (real-time, not from snapshot).
     *
     * @NoAdminRequired
     */
    public function current(): DataResponse {
        try {
            $data = $this->service->calculateNetWorth($this->getUserId());
            return new DataResponse($data);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to calculate net worth');
        }
    }

    /**
     * Get historical snapshots for charting.
     *
     * @NoAdminRequired
     */
    public function snapshots(?int $days = 30): DataResponse {
        try {
            $days = max(1, min($days ?? 30, 3650)); // Clamp to 1-3650 days (10 years)
            $snapshots = $this->service->getSnapshots($this->getUserId(), $days);
            return new DataResponse($snapshots);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve net worth snapshots');
        }
    }

    /**
     * Create a manual snapshot for today.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function createSnapshot(): DataResponse {
        try {
            $snapshot = $this->service->createSnapshot(
                $this->getUserId(),
                \OCA\Budget\Db\NetWorthSnapshot::SOURCE_MANUAL
            );
            return new DataResponse($snapshot, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to create net worth snapshot');
        }
    }

    /**
     * Delete a specific snapshot.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroySnapshot(int $id): DataResponse {
        try {
            $this->service->deleteSnapshot($id, $this->getUserId());
            return new DataResponse(['message' => 'Snapshot deleted']);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Snapshot', ['snapshotId' => $id]);
        }
    }
}
