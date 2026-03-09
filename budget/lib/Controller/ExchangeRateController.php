<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\ExchangeRateMapper;
use OCA\Budget\Enum\Currency;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\ExchangeRateService;
use OCA\Budget\Service\ManualExchangeRateService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ExchangeRateController extends Controller {
    use ApiErrorHandlerTrait;

    private ExchangeRateService $exchangeRateService;
    private CurrencyConversionService $conversionService;
    private ManualExchangeRateService $manualRateService;
    private ExchangeRateMapper $exchangeRateMapper;
    private string $userId;

    public function __construct(
        IRequest $request,
        ExchangeRateService $exchangeRateService,
        CurrencyConversionService $conversionService,
        ManualExchangeRateService $manualRateService,
        ExchangeRateMapper $exchangeRateMapper,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->exchangeRateService = $exchangeRateService;
        $this->conversionService = $conversionService;
        $this->manualRateService = $manualRateService;
        $this->exchangeRateMapper = $exchangeRateMapper;
        $this->userId = $userId;
        $this->setLogger($logger);
    }

    /**
     * Get all exchange rates (auto + manual) for the current user.
     *
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $baseCurrency = $this->conversionService->getBaseCurrency($this->userId);

            // Get all latest auto rates
            $autoRates = $this->exchangeRateMapper->findAllLatest();
            $autoRatesData = [];
            foreach ($autoRates as $rate) {
                $autoRatesData[$rate->getCurrency()] = [
                    'ratePerEur' => $rate->getRatePerEur(),
                    'source' => $rate->getSource(),
                    'date' => $rate->getDate(),
                ];
            }

            // EUR is the reference currency (rate = 1.0) but has no DB row.
            // Synthesize it so the UI can display a rate for EUR.
            if (!isset($autoRatesData['EUR'])) {
                $autoRatesData['EUR'] = [
                    'ratePerEur' => '1.0000000000',
                    'source' => 'reference',
                    'date' => date('Y-m-d'),
                ];
            }

            // Get all manual rates for this user
            $manualRates = $this->manualRateService->getAllForUser($this->userId);
            $manualRatesData = [];
            foreach ($manualRates as $rate) {
                $manualRatesData[$rate->getCurrency()] = [
                    'ratePerEur' => $rate->getRatePerEur(),
                    'updatedAt' => $rate->getUpdatedAt(),
                ];
            }

            // Build currency metadata from enum
            $currencies = [];
            foreach (Currency::cases() as $currency) {
                $code = $currency->value;
                $currencies[$code] = [
                    'code' => $code,
                    'name' => $currency->name(),
                    'symbol' => $currency->symbol(),
                    'isCrypto' => $currency->isCrypto(),
                    'decimals' => $currency->decimals(),
                ];
            }

            return new DataResponse([
                'baseCurrency' => $baseCurrency,
                'autoRates' => $autoRatesData,
                'manualRates' => $manualRatesData,
                'currencies' => $currencies,
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to load exchange rates');
        }
    }

    /**
     * Get the user's base currency (backward-compatible endpoint).
     *
     * @NoAdminRequired
     */
    public function latest(): DataResponse {
        try {
            $baseCurrency = $this->conversionService->getBaseCurrency($this->userId);
            return new DataResponse([
                'baseCurrency' => $baseCurrency,
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to get base currency');
        }
    }

    /**
     * Trigger a manual refresh of exchange rates.
     *
     * @NoAdminRequired
     */
    public function refresh(): DataResponse {
        try {
            $this->exchangeRateService->fetchLatestRates();
            return new DataResponse([
                'status' => 'ok',
                'message' => 'Exchange rates refreshed successfully',
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to refresh exchange rates');
        }
    }

    /**
     * Set a manual exchange rate override.
     *
     * @NoAdminRequired
     */
    public function setManualRate(): DataResponse {
        try {
            $currency = $this->request->getParam('currency');
            $rate = $this->request->getParam('rate');

            if (empty($currency) || empty($rate)) {
                return new DataResponse(
                    ['error' => 'Currency and rate are required'],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $entity = $this->manualRateService->setRate($this->userId, $currency, $rate);
            return new DataResponse($entity);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to set manual rate');
        }
    }

    /**
     * Remove a manual exchange rate override.
     *
     * @NoAdminRequired
     */
    public function removeManualRate(string $currency): DataResponse {
        try {
            $this->manualRateService->removeRate($this->userId, $currency);
            return new DataResponse(['status' => 'ok']);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to remove manual rate');
        }
    }
}
