<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

/**
 * Formats monetary amounts with the user's currency symbol for server-rendered
 * surfaces (notifications, dashboard widgets, calendar feeds). The frontend
 * has its own richer formatter; this stays deliberately simple.
 */
class AmountFormatter {

    private const SYMBOLS = [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥',
        'CAD' => 'CA$', 'AUD' => 'A$', 'CHF' => 'CHF ', 'CNY' => '¥',
    ];

    public function __construct(
        private SettingService $settingService,
    ) {
    }

    /**
     * Format an amount with the given currency symbol (falls back to the
     * currency code as prefix for currencies without a common symbol).
     */
    public function format(float $amount, string $currency): string {
        $symbol = self::SYMBOLS[$currency] ?? $currency . ' ';
        return $symbol . number_format($amount, 2);
    }

    /**
     * Format using the user's configured default currency.
     */
    public function formatForUser(string $userId, float $amount, ?string $currency = null): string {
        if ($currency === null) {
            // The user's currency lives under 'default_currency' (the key used
            // by CurrencyConversionService and the settings UI). Reading the
            // wrong key here made every server-rendered amount fall back to USD
            // regardless of the user's actual currency.
            try {
                $currency = $this->settingService->get($userId, 'default_currency') ?? 'GBP';
            } catch (\Exception $e) {
                $currency = 'GBP';
            }
        }
        return $this->format($amount, $currency);
    }
}
