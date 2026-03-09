<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\ManualExchangeRateMapper;

/**
 * Converts monetary amounts between currencies using cached exchange rates.
 *
 * Conversion goes through EUR as an intermediate:
 *   amount_target = amount * (target_rate / source_rate)
 * where rate = units of currency per 1 EUR.
 *
 * For user-scoped conversions (convertToBase), manual rate overrides
 * take priority over automatic rates from FloatRates/CoinGecko.
 */
class CurrencyConversionService {
    private ExchangeRateService $exchangeRateService;
    private SettingService $settingService;
    private ManualExchangeRateMapper $manualRateMapper;

    public function __construct(
        ExchangeRateService $exchangeRateService,
        SettingService $settingService,
        ManualExchangeRateMapper $manualRateMapper
    ) {
        $this->exchangeRateService = $exchangeRateService;
        $this->settingService = $settingService;
        $this->manualRateMapper = $manualRateMapper;
    }

    /**
     * Convert an amount from one currency to another.
     *
     * @param string|float $amount The amount to convert
     * @param string $fromCurrency Source currency code
     * @param string $toCurrency Target currency code
     * @param string|null $date Date for historical rate lookup (null = today)
     * @return string Converted amount as string (bcmath precision)
     */
    public function convert($amount, string $fromCurrency, string $toCurrency, ?string $date = null): string {
        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency = strtoupper($toCurrency);

        // Short-circuit: same currency
        if ($fromCurrency === $toCurrency) {
            return (string) $amount;
        }

        $fromRate = $this->exchangeRateService->getRate($fromCurrency, $date);
        $toRate = $this->exchangeRateService->getRate($toCurrency, $date);

        // If either rate is unavailable, return amount unchanged (graceful degradation)
        if ($fromRate === null || $toRate === null) {
            return (string) $amount;
        }

        // amount_target = amount * (target_rate / source_rate)
        $ratio = bcdiv($toRate, $fromRate, 10);
        return bcmul((string) $amount, $ratio, 10);
    }

    /**
     * Convert an amount between currencies using only cached/DB rates (no network calls).
     *
     * Suitable for aggregation paths (dashboard, reports) where network latency
     * is unacceptable and graceful degradation is preferred over accuracy.
     *
     * @param string|float $amount The amount to convert
     * @param string $fromCurrency Source currency code
     * @param string $toCurrency Target currency code
     * @param string|null $date Date for rate lookup (null = today)
     * @return string Converted amount as string (bcmath precision)
     */
    public function convertLocal($amount, string $fromCurrency, string $toCurrency, ?string $date = null): string {
        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency = strtoupper($toCurrency);

        if ($fromCurrency === $toCurrency) {
            return (string) $amount;
        }

        $fromRate = $this->exchangeRateService->getRateLocal($fromCurrency, $date);
        $toRate = $this->exchangeRateService->getRateLocal($toCurrency, $date);

        if ($fromRate === null || $toRate === null) {
            return (string) $amount;
        }

        $ratio = bcdiv($toRate, $fromRate, 10);
        return bcmul((string) $amount, $ratio, 10);
    }

    /**
     * Convert an amount to the user's base currency.
     * Uses manual rate overrides when available (highest priority).
     *
     * @param string|float $amount The amount to convert
     * @param string $fromCurrency Source currency code
     * @param string $userId User ID (to look up base currency and manual rates)
     * @param string|null $date Date for historical rate lookup
     * @return string Converted amount
     */
    public function convertToBase($amount, string $fromCurrency, string $userId, ?string $date = null): string {
        $baseCurrency = strtoupper($this->getBaseCurrency($userId));
        $fromCurrency = strtoupper($fromCurrency);

        if ($fromCurrency === $baseCurrency) {
            return (string) $amount;
        }

        $fromRate = $this->getEffectiveRate($fromCurrency, $userId, $date);
        $toRate = $this->getEffectiveRate($baseCurrency, $userId, $date);

        if ($fromRate === null || $toRate === null) {
            return (string) $amount;
        }

        $ratio = bcdiv($toRate, $fromRate, 10);
        return bcmul((string) $amount, $ratio, 10);
    }

    /**
     * Convert an amount to the user's base currency, returning a float.
     *
     * @param string|float $amount The amount to convert
     * @param string $fromCurrency Source currency code
     * @param string $userId User ID
     * @param string|null $date Date for historical rate lookup
     * @return float Converted amount as float
     */
    public function convertToBaseFloat($amount, string $fromCurrency, string $userId, ?string $date = null): float {
        return (float) $this->convertToBase($amount, $fromCurrency, $userId, $date);
    }

    /**
     * Get the user's base/default currency.
     */
    public function getBaseCurrency(string $userId): string {
        return $this->settingService->get($userId, 'default_currency') ?? 'GBP';
    }

    /**
     * Check if accounts use multiple currencies (i.e., conversion is needed).
     *
     * @param Account[] $accounts
     * @return bool True if accounts have mixed currencies
     */
    public function needsConversion(array $accounts): bool {
        $currencies = [];
        foreach ($accounts as $account) {
            $currency = $account->getCurrency() ?: 'USD';
            $currencies[$currency] = true;
            if (count($currencies) > 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build an accountId → currency lookup map.
     *
     * @param Account[] $accounts
     * @return array<int, string>
     */
    public function getAccountCurrencyMap(array $accounts): array {
        $map = [];
        foreach ($accounts as $account) {
            $map[$account->getId()] = $account->getCurrency() ?: 'USD';
        }
        return $map;
    }

    /**
     * Check if a single account's currency differs from the user's base currency.
     */
    public function accountNeedsConversion(string $accountCurrency, string $userId): bool {
        return strtoupper($accountCurrency) !== strtoupper($this->getBaseCurrency($userId));
    }

    /**
     * Check if a currency can be converted to the user's base currency.
     * Returns true if both rates are available.
     */
    public function canConvert(string $fromCurrency, string $userId): bool {
        $baseCurrency = strtoupper($this->getBaseCurrency($userId));
        $fromCurrency = strtoupper($fromCurrency);

        if ($fromCurrency === $baseCurrency) {
            return true;
        }

        $fromRate = $this->getEffectiveRate($fromCurrency, $userId);
        $toRate = $this->getEffectiveRate($baseCurrency, $userId);

        return $fromRate !== null && $toRate !== null;
    }

    /**
     * Get the effective rate for a currency, checking manual overrides first.
     * Manual rates (per-user standing rates) take priority over automatic rates.
     *
     * @param string $currency Currency code (uppercase)
     * @param string $userId User ID for manual rate lookup
     * @param string|null $date Date for automatic rate fallback
     * @return string|null Rate per EUR, or null if unavailable
     */
    private function getEffectiveRate(string $currency, string $userId, ?string $date = null): ?string {
        if ($currency === 'EUR') {
            return '1.0000000000';
        }

        // Check for user's manual rate override (standing rate, ignores date)
        $manual = $this->manualRateMapper->findByUserAndCurrency($userId, $currency);
        if ($manual !== null) {
            $rate = $manual->getRatePerEur();
            // Normalize in case of scientific notation from SQLite
            if (is_float($rate) || (is_string($rate) && stripos($rate, 'e') !== false)) {
                return number_format((float) $rate, 10, '.', '');
            }
            return (string) $rate;
        }

        // Fall back to automatic rate (FloatRates/CoinGecko/ECB)
        return $this->exchangeRateService->getRateLocal($currency, $date);
    }
}
