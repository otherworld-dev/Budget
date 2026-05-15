<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Enum;

use OCA\Budget\Enum\AccountType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AccountTypeTest extends TestCase {
    public static function liabilityProvider(): array {
        return [
            'credit card is liability' => [AccountType::CREDIT_CARD, true],
            'loan is liability' => [AccountType::LOAN, true],
            'checking is not liability' => [AccountType::CHECKING, false],
            'savings is not liability' => [AccountType::SAVINGS, false],
            'investment is not liability' => [AccountType::INVESTMENT, false],
            'cash is not liability' => [AccountType::CASH, false],
            'money market is not liability' => [AccountType::MONEY_MARKET, false],
            'cryptocurrency is not liability' => [AccountType::CRYPTOCURRENCY, false],
        ];
    }

    #[DataProvider('liabilityProvider')]
    public function testIsLiability(AccountType $type, bool $expected): void {
        $this->assertSame($expected, $type->isLiability());
    }

    #[DataProvider('liabilityProvider')]
    public function testIsAssetIsOppositeOfIsLiability(AccountType $type, bool $isLiability): void {
        $this->assertSame(!$isLiability, $type->isAsset());
    }

    public static function canEarnInterestProvider(): array {
        return [
            'savings earns interest' => [AccountType::SAVINGS, true],
            'investment earns interest' => [AccountType::INVESTMENT, true],
            'money market earns interest' => [AccountType::MONEY_MARKET, true],
            'checking does not' => [AccountType::CHECKING, false],
            'credit card does not' => [AccountType::CREDIT_CARD, false],
            'loan does not' => [AccountType::LOAN, false],
            'cash does not' => [AccountType::CASH, false],
            'crypto does not' => [AccountType::CRYPTOCURRENCY, false],
        ];
    }

    #[DataProvider('canEarnInterestProvider')]
    public function testCanEarnInterest(AccountType $type, bool $expected): void {
        $this->assertSame($expected, $type->canEarnInterest());
    }

    public function testHasCreditLimitOnlyForCreditCard(): void {
        $this->assertTrue(AccountType::CREDIT_CARD->hasCreditLimit());

        foreach (AccountType::cases() as $type) {
            if ($type !== AccountType::CREDIT_CARD) {
                $this->assertFalse($type->hasCreditLimit(), "{$type->value} should not have credit limit");
            }
        }
    }

    public function testHasOverdraftLimitOnlyForChecking(): void {
        $this->assertTrue(AccountType::CHECKING->hasOverdraftLimit());

        foreach (AccountType::cases() as $type) {
            if ($type !== AccountType::CHECKING) {
                $this->assertFalse($type->hasOverdraftLimit(), "{$type->value} should not have overdraft limit");
            }
        }
    }

    public static function supportsInterestProvider(): array {
        return [
            'savings' => [AccountType::SAVINGS, true],
            'investment' => [AccountType::INVESTMENT, true],
            'money market' => [AccountType::MONEY_MARKET, true],
            'credit card' => [AccountType::CREDIT_CARD, true],
            'loan' => [AccountType::LOAN, true],
            'checking' => [AccountType::CHECKING, false],
            'cash' => [AccountType::CASH, false],
            'crypto' => [AccountType::CRYPTOCURRENCY, false],
        ];
    }

    #[DataProvider('supportsInterestProvider')]
    public function testSupportsInterest(AccountType $type, bool $expected): void {
        $this->assertSame($expected, $type->supportsInterest());
    }

    public function testLabelReturnsNonEmptyStringForAllCases(): void {
        foreach (AccountType::cases() as $type) {
            $label = $type->label();
            $this->assertNotEmpty($label, "{$type->value} should have a label");
            $this->assertIsString($label);
        }
    }

    public function testLabelValues(): void {
        $this->assertSame('Checking', AccountType::CHECKING->label());
        $this->assertSame('Credit Card', AccountType::CREDIT_CARD->label());
        $this->assertSame('Money Market', AccountType::MONEY_MARKET->label());
    }

    public function testValuesReturnsAllStringValues(): void {
        $values = AccountType::values();

        $this->assertCount(8, $values);
        $this->assertContains('checking', $values);
        $this->assertContains('savings', $values);
        $this->assertContains('credit_card', $values);
        $this->assertContains('investment', $values);
        $this->assertContains('loan', $values);
        $this->assertContains('cash', $values);
        $this->assertContains('money_market', $values);
        $this->assertContains('cryptocurrency', $values);
    }

    public function testIsValidAcceptsValidValues(): void {
        foreach (AccountType::values() as $value) {
            $this->assertTrue(AccountType::isValid($value), "'$value' should be valid");
        }
    }

    public function testIsValidRejectsInvalidValues(): void {
        $this->assertFalse(AccountType::isValid(''));
        $this->assertFalse(AccountType::isValid('bank'));
        $this->assertFalse(AccountType::isValid('CHECKING'));
        $this->assertFalse(AccountType::isValid('credit-card'));
    }
}
