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
        string $endDate = null,
        ?array $tagIds = null,
        ?bool $includeUntagged = null
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
                $accountId,
                $tagIds ?? [],
                $includeUntagged ?? true
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
        string $groupBy = 'category',
        ?int $tagSetId = null,
        ?int $categoryId = null
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
                $groupBy,
                $tagSetId,
                $categoryId
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
        string $groupBy = 'month',
        ?int $tagSetId = null,
        ?int $categoryId = null
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
                $groupBy,
                $tagSetId,
                $categoryId
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
        string $endDate = null,
        ?array $tagIds = null,
        ?bool $includeUntagged = null
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
                $accountId,
                $tagIds ?? [],
                $includeUntagged ?? true
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
        string $endDate = null,
        ?array $tagIds = null,
        ?bool $includeUntagged = null
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
                $accountId,
                $tagIds ?? [],
                $includeUntagged ?? true
            );
            return new DataResponse($cashflow);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to generate cash flow report');
        }
    }

    /**
     * @NoAdminRequired
     * Get tag dimensions for spending across categories
     */
    public function tagDimensions(
        string $startDate = null,
        string $endDate = null,
        ?int $accountId = null,
        ?int $categoryId = null
    ): DataResponse {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-01', strtotime('-12 months'));
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $dimensions = $this->service->getTagDimensions(
                $this->userId,
                $startDate,
                $endDate,
                $accountId,
                $categoryId
            );
            return new DataResponse($dimensions);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to generate tag dimensions');
        }
    }

    /**
     * @NoAdminRequired
     * Get tag combination report
     */
    public function tagCombinations(
        string $startDate = null,
        string $endDate = null,
        ?int $accountId = null,
        ?int $categoryId = null,
        int $minCombinationSize = 2,
        int $limit = 50
    ): DataResponse {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-01', strtotime('-12 months'));
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $combinations = $this->service->getTagCombinationReport(
                $this->userId,
                $startDate,
                $endDate,
                $accountId,
                $categoryId,
                $minCombinationSize,
                $limit
            );
            return new DataResponse($combinations);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to generate tag combination report');
        }
    }

    /**
     * @NoAdminRequired
     * Get cross-tabulation (pivot table) of two tag sets
     */
    public function tagCrossTab(
        int $tagSetId1,
        int $tagSetId2,
        string $startDate = null,
        string $endDate = null,
        ?int $accountId = null,
        ?int $categoryId = null
    ): DataResponse {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-01', strtotime('-12 months'));
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $crossTab = $this->service->getTagCrossTabulation(
                $this->userId,
                $tagSetId1,
                $tagSetId2,
                $startDate,
                $endDate,
                $accountId,
                $categoryId
            );
            return new DataResponse($crossTab);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to generate cross-tabulation');
        }
    }

    /**
     * @NoAdminRequired
     * Get monthly trend for specific tags
     */
    public function tagTrends(
        ?array $tagIds = null,
        string $startDate = null,
        string $endDate = null,
        ?int $accountId = null
    ): DataResponse {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-01', strtotime('-12 months'));
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $trends = $this->service->getTagTrendReport(
                $this->userId,
                $tagIds ?? [],
                $startDate,
                $endDate,
                $accountId
            );
            return new DataResponse($trends);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to generate tag trend report');
        }
    }

    /**
     * @NoAdminRequired
     * Get spending breakdown by a specific tag set
     */
    public function tagSetBreakdown(
        int $tagSetId,
        string $startDate = null,
        string $endDate = null,
        ?int $accountId = null,
        ?int $categoryId = null
    ): DataResponse {
        try {
            if (!$startDate) {
                $startDate = date('Y-m-01', strtotime('-12 months'));
            }
            if (!$endDate) {
                $endDate = date('Y-m-d');
            }

            $breakdown = $this->service->getTagSetBreakdown(
                $this->userId,
                $tagSetId,
                $startDate,
                $endDate,
                $accountId,
                $categoryId
            );
            return new DataResponse($breakdown);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to generate tag set breakdown');
        }
    }
}