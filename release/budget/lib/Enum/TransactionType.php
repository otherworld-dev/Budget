<?php

declare(strict_types=1);

namespace OCA\Budget\Enum;

/**
 * Transaction type enum for credits and debits.
 */
enum TransactionType: string {
    case DEBIT = 'debit';
    case CREDIT = 'credit';

    /**
     * Get the opposite transaction type.
     * Useful for reversing transactions.
     */
    public function opposite(): self {
        return match ($this) {
            self::DEBIT => self::CREDIT,
            self::CREDIT => self::DEBIT,
        };
    }

    /**
     * Get the balance multiplier.
     * Credits increase balance (+1), debits decrease balance (-1).
     */
    public function balanceMultiplier(): int {
        return match ($this) {
            self::CREDIT => 1,
            self::DEBIT => -1,
        };
    }

    /**
     * Check if this is an expense (debit).
     */
    public function isExpense(): bool {
        return $this === self::DEBIT;
    }

    /**
     * Check if this is income (credit).
     */
    public function isIncome(): bool {
        return $this === self::CREDIT;
    }

    /**
     * Get human-readable label.
     */
    public function label(): string {
        return match ($this) {
            self::DEBIT => 'Expense',
            self::CREDIT => 'Income',
        };
    }

    /**
     * Get all valid transaction type values as strings.
     */
    public static function values(): array {
        return array_map(fn(self $t) => $t->value, self::cases());
    }

    /**
     * Check if a string is a valid transaction type.
     */
    public static function isValid(string $value): bool {
        return in_array($value, self::values(), true);
    }
}
