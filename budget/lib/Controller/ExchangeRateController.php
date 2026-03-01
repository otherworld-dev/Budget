<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\ExchangeRateService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ExchangeRateController extends Controller {
    use ApiErrorHandlerTrait;

    private ExchangeRateService $exchangeRateService;
    private CurrencyConversionService $conversionService;
    private string $userId;

    public function __construct(
        IRequest $request,
        ExchangeRateService $exchangeRateService,
        CurrencyConversionService $conversionService,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->exchangeRateService = $exchangeRateService;
        $this->conversionService = $conversionService;
        $this->userId = $userId;
        $this->setLogger($logger);
    }

    /**
     * Get the latest cached exchange rates.
     *
     * @NoAdminRequired
     */
    public function latest(): JSONResponse {
        return $this->handleApiCall(function () {
            $baseCurrency = $this->conversionService->getBaseCurrency($this->userId);

            return new JSONResponse([
                'baseCurrency' => $baseCurrency,
            ]);
        }, $this->logger);
    }

    /**
     * Trigger a manual refresh of exchange rates.
     *
     * @NoAdminRequired
     */
    public function refresh(): JSONResponse {
        return $this->handleApiCall(function () {
            $this->exchangeRateService->fetchLatestRates();

            return new JSONResponse([
                'status' => 'ok',
                'message' => 'Exchange rates refreshed successfully',
            ]);
        }, $this->logger);
    }
}
