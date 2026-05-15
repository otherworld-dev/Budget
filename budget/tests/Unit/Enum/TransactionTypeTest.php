<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Enum;

use OCA\Budget\Enum\TransactionType;
use PHPUnit\Framework\TestCase;

class TransactionTypeTest extends TestCase {
    public function testOppositeOfDebitIsCredit(): void {
        $this->assertSame(TransactionType::CREDIT, TransactionType::DEBIT->opposite());
    }

    public function testOppositeOfCreditIsDebit(): void {
        $this->assertSame(TransactionType::DEBIT, TransactionType::CREDIT->opposite());
    }

    public function testDoubleOppositeReturnsSelf(): void {
        foreach (TransactionType::cases() as $type) {
            $this->assertSame($type, $type->opposite()->opposite());
        }
    }

    public function testBalanceMultiplier(): void {
        $this->assertSame(1, TransactionType::CREDIT->balanceMultiplier());
        $this->assertSame(-1, TransactionType::DEBIT->balanceMultiplier());
    }

    public function testIsExpense(): void {
        $this->assertTrue(TransactionType::DEBIT->isExpense());
        $this->assertFalse(TransactionType::CREDIT->isExpense());
    }

    public function testIsIncome(): void {
        $this->assertTrue(TransactionType::CREDIT->isIncome());
        $this->assertFalse(TransactionType::DEBIT->isIncome());
    }

    public function testLabels(): void {
        $this->assertSame('Expense', TransactionType::DEBIT->label());
        $this->assertSame('Income', TransactionType::CREDIT->label());
    }

    public function testValues(): void {
        $values = TransactionType::values();

        $this->assertCount(2, $values);
        $this->assertContains('debit', $values);
        $this->assertContains('credit', $values);
    }

    public function testIsValid(): void {
        $this->assertTrue(TransactionType::isValid('debit'));
        $this->assertTrue(TransactionType::isValid('credit'));

        $this->assertFalse(TransactionType::isValid(''));
        $this->assertFalse(TransactionType::isValid('DEBIT'));
        $this->assertFalse(TransactionType::isValid('expense'));
        $this->assertFalse(TransactionType::isValid('income'));
    }
}
