<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\YearOverYearService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class YearOverYearController extends Controller {
    private YearOverYearService $service;
    private string $userId;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        YearOverYearService $service,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userId;
        $this->logger = $logger;
    }

    /**
     * Compare the same month across multiple years.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function compareMonth(int $month = 0, int $years = 3): DataResponse {
        try {
            // Default to current month if not specified
            if ($month <= 0 || $month > 12) {
                $month = (int) date('n');
            }

            // Limit years to reasonable range
            $years = max(1, min(10, $years));

            $comparison = $this->service->compareMonth($this->userId, $month, $years);
            return new DataResponse($comparison);
        } catch (\Exception $e) {
            $this->logger->error('Failed to compare month', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to compare month data'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Compare full years.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function compareYears(int $years = 3): DataResponse {
        try {
            // Limit years to reasonable range
            $years = max(1, min(10, $years));

            $comparison = $this->service->compareYears($this->userId, $years);
            return new DataResponse($comparison);
        } catch (\Exception $e) {
            $this->logger->error('Failed to compare years', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to compare year data'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Compare spending by category across years.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function compareCategories(int $years = 2): DataResponse {
        try {
            // Limit years to reasonable range
            $years = max(1, min(5, $years));

            $comparison = $this->service->compareCategorySpending($this->userId, $years);
            return new DataResponse($comparison);
        } catch (\Exception $e) {
            $this->logger->error('Failed to compare categories', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to compare category data'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Get monthly trends for year comparison.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function monthlyTrends(int $years = 2): DataResponse {
        try {
            // Limit years to reasonable range
            $years = max(1, min(5, $years));

            $trends = $this->service->getMonthlyTrends($this->userId, $years);
            return new DataResponse($trends);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get monthly trends', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new DataResponse(
                ['error' => 'Failed to get monthly trends'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
