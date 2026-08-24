<?php

declare(strict_types=1);

namespace OCA\Budget\Enum;

enum AccountType: string {
    case CHECKING = 'checking';
    case SAVINGS = 'savings';
    case CREDIT_CARD = 'credit_card';
    case INVESTMENT = 'investment';
    case LOAN = 'loan';
    case CASH = 'cash';
    case MONEY_MARKET = 'money_market';
    case CRYPTOCURRENCY = 'cryptocurrency';
    case MORTGAGE = 'mortgage';
    case LINE_OF_CREDIT = 'line_of_credit';

    public function isLiability(): bool {
        return match ($this) {
            self::CREDIT_CARD, self::LOAN, self::MORTGAGE, self::LINE_OF_CREDIT => true,
            default => false,
        };
    }

    public function isAsset(): bool {
        return !$this->isLiability();
    }

    /**
     * Apply this type's sign convention to an opening/starting balance (#353).
     *
     * Liability accounts store the amount owed as a NEGATIVE number. The form
     * collects a magnitude plus an explicit "in credit" flag, so the sign is
     * derived from intent, never inferred from what the user typed. Written in
     * magnitude form (abs() then sign) so it is idempotent -- applying it twice
     * gives the same answer as applying it once.
     *
     * Asset types pass through unchanged: a negative opening balance on a
     * current account is a legitimate overdraft.
     */
    public function signedOpeningBalance(float $amount, bool $inCredit = false): float {
        if (!$this->isLiability()) {
            return $amount;
        }
        return $inCredit ? abs($amount) : -abs($amount);
    }

    /**
     * As signedOpeningBalance(), for a raw type string. Unknown types are
     * treated as assets (pass-through) rather than throwing.
     */
    public static function signFor(?string $type, float $amount, bool $inCredit = false): float {
        $t = $type !== null ? self::tryFrom($type) : null;
        return $t === null ? $amount : $t->signedOpeningBalance($amount, $inCredit);
    }

    public function canEarnInterest(): bool {
        return match ($this) {
            self::SAVINGS, self::INVESTMENT, self::MONEY_MARKET => true,
            default => false,
        };
    }

    public function hasCreditLimit(): bool {
        return $this === self::CREDIT_CARD;
    }

    public function hasOverdraftLimit(): bool {
        return $this === self::CHECKING;
    }

    public function supportsInterest(): bool {
        return match ($this) {
            self::SAVINGS, self::INVESTMENT, self::MONEY_MARKET,
            self::CREDIT_CARD, self::LOAN, self::MORTGAGE, self::LINE_OF_CREDIT => true,
            default => false,
        };
    }

    public function label(): string {
        return match ($this) {
            self::CHECKING => 'Checking',
            self::SAVINGS => 'Savings',
            self::CREDIT_CARD => 'Credit Card',
            self::INVESTMENT => 'Investment',
            self::LOAN => 'Loan',
            self::CASH => 'Cash',
            self::MONEY_MARKET => 'Money Market',
            self::CRYPTOCURRENCY => 'Cryptocurrency',
            self::MORTGAGE => 'Mortgage',
            self::LINE_OF_CREDIT => 'Line of Credit',
        };
    }

    public static function values(): array {
        return array_map(fn(self $t) => $t->value, self::cases());
    }

    public static function isValid(string $value): bool {
        return in_array($value, self::values(), true);
    }
}
