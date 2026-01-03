<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\ReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;

class ReportController extends Controller {
    private ReportService $service;
    private string $userId;

    public function __construct(
        IRequest $request,
        ReportService $service,
        string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userId;
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
                $accountId,
                $startDate,
                $endDate
            );
            return new DataResponse($summary);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
                $accountId,
                $startDate,
                $endDate,
                $groupBy
            );
            return new DataResponse($spending);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
                $accountId,
                $startDate,
                $endDate,
                $groupBy
            );
            return new DataResponse($income);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
    ): StreamResponse {
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
                $accountId,
                $startDate,
                $endDate
            );

            $response = new StreamResponse($export['stream']);
            $response->addHeader('Content-Type', $export['contentType']);
            $response->addHeader('Content-Disposition', 'attachment; filename="' . $export['filename'] . '"');
            
            return $response;
        } catch (\Exception $e) {
            return new StreamResponse('');
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}