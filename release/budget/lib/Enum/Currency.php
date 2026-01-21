<?php

declare(strict_types=1);

namespace OCA\Budget\Enum;

/**
 * Currency enum for supported ISO 4217 currency codes.
 */
enum Currency: string {
    case USD = 'USD';  // US Dollar
    case EUR = 'EUR';  // Euro
    case GBP = 'GBP';  // British Pound
    case CAD = 'CAD';  // Canadian Dollar
    case AUD = 'AUD';  // Australian Dollar
    case JPY = 'JPY';  // Japanese Yen
    case CHF = 'CHF';  // Swiss Franc
    case CNY = 'CNY';  // Chinese Yuan
    case SEK = 'SEK';  // Swedish Krona
    case NOK = 'NOK';  // Norwegian Krone
    case MXN = 'MXN';  // Mexican Peso
    case NZD = 'NZD';  // New Zealand Dollar
    case SGD = 'SGD';  // Singapore Dollar
    case HKD = 'HKD';  // Hong Kong Dollar
    case ZAR = 'ZAR';  // South African Rand
    case INR = 'INR';  // Indian Rupee
    case BRL = 'BRL';  // Brazilian Real
    case RUB = 'RUB';  // Russian Ruble
    case KRW = 'KRW';  // South Korean Won
    case TRY = 'TRY';  // Turkish Lira

    /**
     * Get the currency symbol.
     */
    public function symbol(): string {
        return match ($this) {
            self::USD, self::CAD, self::AUD, self::NZD, self::SGD, self::HKD, self::MXN => '$',
            self::EUR => '€',
            self::GBP => '£',
            self::JPY, self::CNY => '¥',
            self::CHF => 'CHF',
            self::SEK, self::NOK => 'kr',
            self::ZAR => 'R',
            self::INR => '₹',
            self::BRL => 'R$',
            self::RUB => '₽',
            self::KRW => '₩',
            self::TRY => '₺',
        };
    }

    /**
     * Get the number of decimal places for this currency.
     */
    public function decimals(): int {
        return match ($this) {
            self::JPY, self::KRW => 0,
            default => 2,
        };
    }

    /**
     * Get human-readable name.
     */
    public function name(): string {
        return match ($this) {
            self::USD => 'US Dollar',
            self::EUR => 'Euro',
            self::GBP => 'British Pound',
            self::CAD => 'Canadian Dollar',
            self::AUD => 'Australian Dollar',
            self::JPY => 'Japanese Yen',
            self::CHF => 'Swiss Franc',
            self::CNY => 'Chinese Yuan',
            self::SEK => 'Swedish Krona',
            self::NOK => 'Norwegian Krone',
            self::MXN => 'Mexican Peso',
            self::NZD => 'New Zealand Dollar',
            self::SGD => 'Singapore Dollar',
            self::HKD => 'Hong Kong Dollar',
            self::ZAR => 'South African Rand',
            self::INR => 'Indian Rupee',
            self::BRL => 'Brazilian Real',
            self::RUB => 'Russian Ruble',
            self::KRW => 'South Korean Won',
            self::TRY => 'Turkish Lira',
        };
    }

    /**
     * Format an amount in this currency.
     */
    public function format(float $amount): string {
        return $this->symbol() . number_format($amount, $this->decimals());
    }

    /**
     * Get all valid currency codes as strings.
     */
    public static function values(): array {
        return array_map(fn(self $c) => $c->value, self::cases());
    }

    /**
     * Check if a string is a valid currency code.
     */
    public static function isValid(string $value): bool {
        return in_array(strtoupper($value), self::values(), true);
    }

    /**
     * Try to create from string (case-insensitive).
     */
    public static function tryFromString(string $value): ?self {
        return self::tryFrom(strtoupper(trim($value)));
    }
}
