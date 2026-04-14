<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\ShareService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ForecastController extends Controller {
    use ApiErrorHandlerTrait;
    use SharedAccessTrait;

    private ForecastService $service;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        ForecastService $service,
        ShareService $shareService,
        IL10N $l,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->l = $l;
        $this->userId = $userId;
        $this->setLogger($logger);
        $this->setShareService($shareService);
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
                $this->getEffectiveUserId(),
                $accountId,
                $basedOnMonths,
                $forecastMonths
            );
            return new DataResponse($forecast);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to generate forecast'));
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
                $this->getEffectiveUserId(),
                $startDate,
                $endDate,
                $accountId
            );
            return new DataResponse($cashflow);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to generate cash flow forecast'));
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
                $this->getEffectiveUserId(),
                $accountId,
                $months
            );
            return new DataResponse($trends);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve spending trends'));
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
                $this->getEffectiveUserId(),
                $accountId,
                $scenarios
            );
            return new DataResponse($results);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to run forecast scenarios'));
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
                $this->getEffectiveUserId(),
                $accountId,
                $historicalPeriod,
                $forecastHorizon,
                $confidenceLevel
            );
            return new DataResponse($enhancedForecast);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to generate enhanced forecast'));
        }
    }

    /**
     * @NoAdminRequired
     * Get live forecast data for dashboard
     */
    public function live(int $forecastMonths = 6): DataResponse {
        try {
            $forecast = $this->service->getLiveForecast(
                $this->getEffectiveUserId(),
                $forecastMonths
            );
            return new DataResponse($forecast);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve live forecast'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function export(array $forecastData): DataResponse {
        try {
            $exportData = $this->service->exportForecast(
                $this->getEffectiveUserId(),
                $forecastData
            );
            return new DataResponse($exportData, Http::STATUS_OK, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="forecast-export.json"'
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to export forecast'));
        }
    }
}