<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Enum;

use OCA\Budget\Enum\TransactionStatus;
use PHPUnit\Framework\TestCase;

class TransactionStatusTest extends TestCase {
    public function testLabels(): void {
        $this->assertSame('Cleared', TransactionStatus::CLEARED->label());
        $this->assertSame('Scheduled', TransactionStatus::SCHEDULED->label());
    }

    public function testIsReportable(): void {
        $this->assertTrue(TransactionStatus::CLEARED->isReportable());
        $this->assertFalse(TransactionStatus::SCHEDULED->isReportable());
    }

    public function testValues(): void {
        $values = TransactionStatus::values();

        $this->assertCount(2, $values);
        $this->assertContains('cleared', $values);
        $this->assertContains('scheduled', $values);
    }

    public function testIsValidAcceptsValidValues(): void {
        $this->assertTrue(TransactionStatus::isValid('cleared'));
        $this->assertTrue(TransactionStatus::isValid('scheduled'));
    }

    public function testIsValidRejectsInvalidValues(): void {
        $this->assertFalse(TransactionStatus::isValid(''));
        $this->assertFalse(TransactionStatus::isValid('pending'));
        $this->assertFalse(TransactionStatus::isValid('CLEARED'));
        $this->assertFalse(TransactionStatus::isValid('reconciled'));
    }
}
