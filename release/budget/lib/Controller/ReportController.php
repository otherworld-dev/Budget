<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\ReportService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ReportController extends Controller {
    use ApiErrorHandlerTrait;

    private ReportService $service;
    private string $userId;

    public function __construct(
        IRequest $request,
        ReportService $service,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userId;
        $this->setLogger($logger);
    }

    /**
     * @NoAdminRequired
     */
    public function summary(
        ?int $accountId = null,
        string $startDate = null,
        string $endDate = null
    ): DataResponse {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-01', strtotime('-12 months'));
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $summary = $this->service->generateSummary(
                $this->userId,
                $startDate,
                $endDate,
                $accountId
            );
            return new DataResponse($summary);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to generate summary report');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function spending(
        ?int $accountId = null,
        string $startDate = null,
        string $endDate = null,
        string $groupBy = 'category'
    ): DataResponse {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-01', strtotime('-12 months'));
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $spending = $this->service->getSpendingReport(
                $this->userId,
                $startDate,
                $endDate,
                $accountId,
                $groupBy
            );
            return new DataResponse($spending);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to generate spending report');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function income(
        ?int $accountId = null,
        string $startDate = null,
        string $endDate = null,
        string $groupBy = 'month'
    ): DataResponse {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-01', strtotime('-12 months'));
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $income = $this->service->getIncomeReport(
                $this->userId,
                $startDate,
                $endDate,
                $accountId,
                $groupBy
            );
            return new DataResponse($income);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to generate income report');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function export(
        string $type,
        string $format = 'csv',
        ?int $accountId = null,
        string $startDate = null,
        string $endDate = null
    ): DataDownloadResponse|DataResponse {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-01', strtotime('-12 months'));
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $export = $this->service->exportReport(
                $this->userId,
                $type,
                $format,
                $startDate,
                $endDate,
                $accountId
            );

            return new DataDownloadResponse(
                $export['stream'],
                $export['filename'],
                $export['contentType']
            );
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to export report');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function budget(
        string $startDate = null,
        string $endDate = null
    ): DataResponse {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-01');
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $budget = $this->service->getBudgetReport(
                $this->userId,
                $startDate,
                $endDate
            );
            return new DataResponse($budget);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to generate budget report');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function summaryWithComparison(
        ?int $accountId = null,
        string $startDate = null,
        string $endDate = null
    ): DataResponse {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-01');
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $summary = $this->service->generateSummaryWithComparison(
                $this->userId,
                $startDate,
                $endDate,
                $accountId
            );
            return new DataResponse($summary);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to generate comparison report');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function cashflow(
        ?int $accountId = null,
        string $startDate = null,
        string $endDate = null
    ): DataResponse {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-01', strtotime('-12 months'));
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $cashflow = $this->service->getCashFlowReport(
                $this->userId,
                $startDate,
                $endDate,
                $accountId
            );
            return new DataResponse($cashflow);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to generate cash flow report');
        }
    }
}