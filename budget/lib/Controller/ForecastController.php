<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\ForecastService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ForecastController extends Controller {
    private ForecastService $service;
    private string $userId;

    public function __construct(
        IRequest $request,
        ForecastService $service,
        string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function generate(
        ?int $accountId = null,
        int $basedOnMonths = 3,
        int $forecastMonths = 6
    ): DataResponse {
        try {
            $forecast = $this->service->generateForecast(
                $this->userId,
                $accountId,
                $basedOnMonths,
                $forecastMonths
            );
            return new DataResponse($forecast);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
                $endDate = date('Y-m-d', strtotime('+6 months'));
            }

            $cashflow = $this->service->getCashFlowForecast(
                $this->userId,
                $startDate,
                $endDate,
                $accountId
            );
            return new DataResponse($cashflow);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function trends(
        ?int $accountId = null,
        int $months = 12
    ): DataResponse {
        try {
            $trends = $this->service->getSpendingTrends(
                $this->userId,
                $accountId,
                $months
            );
            return new DataResponse($trends);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function scenarios(
        ?int $accountId = null,
        array $scenarios = []
    ): DataResponse {
        try {
            $results = $this->service->runScenarios(
                $this->userId,
                $accountId,
                $scenarios
            );
            return new DataResponse($results);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function enhanced(
        ?int $accountId = null,
        int $historicalPeriod = 6,
        int $forecastHorizon = 6,
        int $confidenceLevel = 90
    ): DataResponse {
        try {
            $enhancedForecast = $this->service->generateEnhancedForecast(
                $this->userId,
                $accountId,
                $historicalPeriod,
                $forecastHorizon,
                $confidenceLevel
            );
            return new DataResponse($enhancedForecast);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     * Get live forecast data for dashboard
     */
    public function live(int $forecastMonths = 6): DataResponse {
        try {
            $forecast = $this->service->getLiveForecast(
                $this->userId,
                $forecastMonths
            );
            return new DataResponse($forecast);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function export(array $forecastData): DataResponse {
        try {
            $exportData = $this->service->exportForecast(
                $this->userId,
                $forecastData
            );
            return new DataResponse($exportData, Http::STATUS_OK, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="forecast-export.json"'
            ]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}