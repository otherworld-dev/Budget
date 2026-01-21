<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AlertController extends Controller {
    use ApiErrorHandlerTrait;

    private BudgetAlertService $alertService;
    private string $userId;

    public function __construct(
        IRequest $request,
        BudgetAlertService $alertService,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->alertService = $alertService;
        $this->userId = $userId;
        $this->setLogger($logger);
    }

    /**
     * Get all budget alerts (categories at or above warning threshold)
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $alerts = $this->alertService->getAlerts($this->userId);
            return new DataResponse($alerts);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve budget alerts');
        }
    }

    /**
     * Get full budget status for all categories with budgets
     * @NoAdminRequired
     */
    public function status(): DataResponse {
        try {
            $status = $this->alertService->getBudgetStatus($this->userId);
            return new DataResponse($status);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve budget status');
        }
    }

    /**
     * Get summary statistics for budget alerts
     * @NoAdminRequired
     */
    public function summary(): DataResponse {
        try {
            $summary = $this->alertService->getSummary($this->userId);
            return new DataResponse($summary);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve budget summary');
        }
    }
}
