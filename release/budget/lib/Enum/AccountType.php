<?php

declare(strict_types=1);

namespace OCA\Budget\Enum;

/**
 * Account type enum for different financial account categories.
 */
enum AccountType: string {
    case CHECKING = 'checking';
    case SAVINGS = 'savings';
    case CREDIT_CARD = 'credit_card';
    case INVESTMENT = 'investment';
    case LOAN = 'loan';
    case CASH = 'cash';
    case MONEY_MARKET = 'money_market';

    /**
     * Check if this account type typically has a negative balance (liability).
     */
    public function isLiability(): bool {
        return match ($this) {
            self::CREDIT_CARD, self::LOAN => true,
            default => false,
        };
    }

    /**
     * Check if this account type is an asset.
     */
    public function isAsset(): bool {
        return !$this->isLiability();
    }

    /**
     * Check if this account type earns interest.
     */
    public function canEarnInterest(): bool {
        return match ($this) {
            self::SAVINGS, self::INVESTMENT, self::MONEY_MARKET => true,
            default => false,
        };
    }

    /**
     * Check if this account type has a credit limit.
     */
    public function hasCreditLimit(): bool {
        return $this === self::CREDIT_CARD;
    }

    /**
     * Check if this account type can have an overdraft limit.
     */
    public function hasOverdraftLimit(): bool {
        return $this === self::CHECKING;
    }

    /**
     * Get human-readable label.
     */
    public function label(): string {
        return match ($this) {
            self::CHECKING => 'Checking',
            self::SAVINGS => 'Savings',
            self::CREDIT_CARD => 'Credit Card',
            self::INVESTMENT => 'Investment',
            self::LOAN => 'Loan',
            self::CASH => 'Cash',
            self::MONEY_MARKET => 'Money Market',
        };
    }

    /**
     * Get all valid account type values as strings.
     */
    public static function values(): array {
        return array_map(fn(self $t) => $t->value, self::cases());
    }

    /**
     * Check if a string is a valid account type.
     */
    public static function isValid(string $value): bool {
        return in_array($value, self::values(), true);
    }
}
