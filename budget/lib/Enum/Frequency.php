<?php

declare(strict_types=1);

namespace OCA\Budget\Enum;

/**
 * Frequency enum for recurring bills and transactions.
 */
enum Frequency: string {
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case BIWEEKLY = 'biweekly';
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    case SEMI_ANNUALLY = 'semi-annually';
    case YEARLY = 'yearly';

    /**
     * Get the number of occurrences per year.
     */
    public function occurrencesPerYear(): int {
        return match ($this) {
            self::DAILY => 365,
            self::WEEKLY => 52,
            self::BIWEEKLY => 26,
            self::MONTHLY => 12,
            self::QUARTERLY => 4,
            self::SEMI_ANNUALLY => 2,
            self::YEARLY => 1,
        };
    }

    /**
     * Get the monthly equivalent multiplier.
     * Used to normalize amounts to monthly values.
     */
    public function monthlyMultiplier(): float {
        return match ($this) {
            self::DAILY => 365 / 12,
            self::WEEKLY => 52 / 12,
            self::BIWEEKLY => 26 / 12,
            self::MONTHLY => 1,
            self::QUARTERLY => 1 / 3,
            self::SEMI_ANNUALLY => 1 / 6,
            self::YEARLY => 1 / 12,
        };
    }

    /**
     * Convert an amount to its monthly equivalent.
     */
    public function toMonthlyAmount(float $amount): float {
        return $amount * $this->monthlyMultiplier();
    }

    /**
     * Get human-readable label.
     */
    public function label(): string {
        return match ($this) {
            self::DAILY => 'Daily',
            self::WEEKLY => 'Weekly',
            self::BIWEEKLY => 'Bi-weekly',
            self::MONTHLY => 'Monthly',
            self::QUARTERLY => 'Quarterly',
            self::SEMI_ANNUALLY => 'Semi-Annually',
            self::YEARLY => 'Yearly',
        };
    }

    /**
     * Get all valid frequency values as strings.
     */
    public static function values(): array {
        return array_map(fn(self $f) => $f->value, self::cases());
    }

    /**
     * Check if a string is a valid frequency.
     */
    public static function isValid(string $value): bool {
        return in_array($value, self::values(), true);
    }

    /**
     * Try to create from string, returns null if invalid.
     */
    public static function tryFromString(string $value): ?self {
        return self::tryFrom(strtolower($value));
    }
}
