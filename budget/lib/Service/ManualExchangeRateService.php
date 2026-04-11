<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\ManualExchangeRate;
use OCA\Budget\Db\ManualExchangeRateMapper;
use OCA\Budget\Enum\Currency;
use OCP\IL10N;

/**
 * Manages per-user manual exchange rate overrides.
 *
 * Manual rates are standing rates (no date) that take priority
 * over automatic rates from FloatRates/CoinGecko.
 * Rates are stored as "units per 1 EUR" internally, but the API
 * accepts rates in the user's base currency format for convenience.
 */
class ManualExchangeRateService {
    private ManualExchangeRateMapper $mapper;
    private ExchangeRateService $exchangeRateService;
    private SettingService $settingService;
    private IL10N $l;

    public function __construct(
        ManualExchangeRateMapper $mapper,
        ExchangeRateService $exchangeRateService,
        SettingService $settingService,
        IL10N $l
    ) {
        $this->mapper = $mapper;
        $this->exchangeRateService = $exchangeRateService;
        $this->settingService = $settingService;
        $this->l = $l;
    }

    /**
     * Get all manual rate overrides for a user.
     *
     * @return ManualExchangeRate[]
     */
    public function getAllForUser(string $userId): array {
        return $this->mapper->findAllByUser($userId);
    }

    /**
     * Set a manual exchange rate override.
     *
     * @param string $userId User ID
     * @param string $currency Target currency code
     * @param string $ratePerBaseCurrency Rate as "1 baseCurrency = X targetCurrency"
     * @return ManualExchangeRate The created/updated entity
     * @throws \InvalidArgumentException On validation failure
     */
    public function setRate(string $userId, string $currency, string $ratePerBaseCurrency): ManualExchangeRate {
        $currency = strtoupper(trim($currency));

        // Validate currency exists in enum
        $currencyEnum = Currency::tryFrom($currency);
        if ($currencyEnum === null) {
            throw new \InvalidArgumentException($this->l->t('Invalid currency code: %1$s', [$currency]));
        }

        // Cannot set rate for EUR (always 1.0)
        if ($currency === 'EUR') {
            throw new \InvalidArgumentException($this->l->t('Cannot set a manual rate for EUR'));
        }

        // Cannot set rate for user's own base currency
        $baseCurrency = $this->getBaseCurrency($userId);
        if ($currency === strtoupper($baseCurrency)) {
            throw new \InvalidArgumentException($this->l->t('Cannot set a manual rate for your base currency'));
        }

        // Validate rate is positive numeric
        if (!is_numeric($ratePerBaseCurrency) || (float) $ratePerBaseCurrency <= 0) {
            throw new \InvalidArgumentException($this->l->t('Rate must be a positive number'));
        }

        // Convert from "per base currency" to "per EUR"
        // ratePerEur = ratePerBase * baseRatePerEur
        $ratePerEur = $this->convertToEurRate($ratePerBaseCurrency, $baseCurrency);

        return $this->mapper->upsert($userId, $currency, $ratePerEur);
    }

    /**
     * Remove a manual rate override, reverting to automatic rates.
     *
     * @param string $userId User ID
     * @param string $currency Currency code to remove
     */
    public function removeRate(string $userId, string $currency): void {
        $currency = strtoupper(trim($currency));
        $this->mapper->deleteByUserAndCurrency($userId, $currency);
    }

    /**
     * Get the user's base currency.
     */
    private function getBaseCurrency(string $userId): string {
        return $this->settingService->get($userId, 'default_currency') ?? 'GBP';
    }

    /**
     * Convert a rate from "per base currency" to "per EUR".
     *
     * If base is EUR, the rate is already per EUR.
     * Otherwise: ratePerEur = ratePerBase * baseRatePerEur
     *
     * @param string $ratePerBase Rate as "1 baseCurrency = X targetCurrency"
     * @param string $baseCurrency The user's base currency code
     * @return string Rate per EUR for storage
     * @throws \InvalidArgumentException If base currency rate is unavailable
     */
    private function convertToEurRate(string $ratePerBase, string $baseCurrency): string {
        $baseCurrency = strtoupper($baseCurrency);

        if ($baseCurrency === 'EUR') {
            return number_format((float) $ratePerBase, 10, '.', '');
        }

        $baseRatePerEur = $this->exchangeRateService->getRateLocal($baseCurrency);
        if ($baseRatePerEur === null) {
            throw new \InvalidArgumentException(
                $this->l->t('Cannot convert rate: no exchange rate available for base currency %1$s', [$baseCurrency])
            );
        }

        // ratePerEur = ratePerBase * baseRatePerEur
        return bcmul($ratePerBase, $baseRatePerEur, 10);
    }
}
