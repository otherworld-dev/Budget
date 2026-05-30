<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Enum;

use OCA\Budget\Enum\Frequency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FrequencyTest extends TestCase {
    public static function occurrencesPerYearProvider(): array {
        return [
            'daily' => [Frequency::DAILY, 365],
            'weekly' => [Frequency::WEEKLY, 52],
            'biweekly' => [Frequency::BIWEEKLY, 26],
            'monthly' => [Frequency::MONTHLY, 12],
            'quarterly' => [Frequency::QUARTERLY, 4],
            'semi-annually' => [Frequency::SEMI_ANNUALLY, 2],
            'yearly' => [Frequency::YEARLY, 1],
            'one-time' => [Frequency::ONE_TIME, 1],
            'custom' => [Frequency::CUSTOM, 0],
        ];
    }

    #[DataProvider('occurrencesPerYearProvider')]
    public function testOccurrencesPerYear(Frequency $freq, int $expected): void {
        $this->assertSame($expected, $freq->occurrencesPerYear());
    }

    public static function monthlyMultiplierProvider(): array {
        return [
            'daily' => [Frequency::DAILY, 365 / 12],
            'weekly' => [Frequency::WEEKLY, 52 / 12],
            'biweekly' => [Frequency::BIWEEKLY, 26 / 12],
            'monthly' => [Frequency::MONTHLY, 1.0],
            'quarterly' => [Frequency::QUARTERLY, 1 / 3],
            'semi-annually' => [Frequency::SEMI_ANNUALLY, 1 / 6],
            'yearly' => [Frequency::YEARLY, 1 / 12],
            'one-time' => [Frequency::ONE_TIME, 1 / 12],
            'custom' => [Frequency::CUSTOM, 0.0],
        ];
    }

    #[DataProvider('monthlyMultiplierProvider')]
    public function testMonthlyMultiplier(Frequency $freq, float $expected): void {
        $this->assertEqualsWithDelta($expected, $freq->monthlyMultiplier(), 0.0001);
    }

    public function testToMonthlyAmountMultipliesCorrectly(): void {
        // A $100 monthly bill stays $100/month
        $this->assertEqualsWithDelta(100.0, Frequency::MONTHLY->toMonthlyAmount(100.0), 0.01);

        // A $1200 yearly bill becomes $100/month
        $this->assertEqualsWithDelta(100.0, Frequency::YEARLY->toMonthlyAmount(1200.0), 0.01);

        // A $100 weekly bill becomes ~$433.33/month
        $this->assertEqualsWithDelta(100.0 * 52 / 12, Frequency::WEEKLY->toMonthlyAmount(100.0), 0.01);

        // Custom frequency yields zero
        $this->assertEqualsWithDelta(0.0, Frequency::CUSTOM->toMonthlyAmount(500.0), 0.01);
    }

    public function testLabelReturnsNonEmptyStringForAllCases(): void {
        foreach (Frequency::cases() as $freq) {
            $label = $freq->label();
            $this->assertNotEmpty($label, "{$freq->value} should have a label");
        }
    }

    public function testSpecificLabels(): void {
        $this->assertSame('Bi-weekly', Frequency::BIWEEKLY->label());
        $this->assertSame('Semi-Annually', Frequency::SEMI_ANNUALLY->label());
        $this->assertSame('One-Time', Frequency::ONE_TIME->label());
    }

    public function testValuesReturnsAllStringValues(): void {
        $values = Frequency::values();

        $this->assertCount(10, $values);
        $this->assertContains('daily', $values);
        $this->assertContains('biweekly', $values);
        $this->assertContains('semi-monthly', $values);
        $this->assertContains('semi-annually', $values);
        $this->assertContains('one-time', $values);
        $this->assertContains('custom', $values);
    }

    public function testIsValidAcceptsValidAndRejectsInvalid(): void {
        $this->assertTrue(Frequency::isValid('monthly'));
        $this->assertTrue(Frequency::isValid('semi-annually'));
        $this->assertTrue(Frequency::isValid('one-time'));

        $this->assertFalse(Frequency::isValid(''));
        $this->assertFalse(Frequency::isValid('annually'));
        $this->assertFalse(Frequency::isValid('MONTHLY'));
        $this->assertFalse(Frequency::isValid('bi-weekly'));
    }

    public function testTryFromStringMatchesLowercase(): void {
        $this->assertSame(Frequency::MONTHLY, Frequency::tryFromString('Monthly'));
        $this->assertSame(Frequency::MONTHLY, Frequency::tryFromString('MONTHLY'));
        $this->assertSame(Frequency::SEMI_ANNUALLY, Frequency::tryFromString('Semi-Annually'));
        $this->assertSame(Frequency::ONE_TIME, Frequency::tryFromString('one-time'));
    }

    public function testTryFromStringReturnsNullForInvalid(): void {
        $this->assertNull(Frequency::tryFromString('invalid'));
        $this->assertNull(Frequency::tryFromString(''));
        $this->assertNull(Frequency::tryFromString('bi-weekly'));
    }
}
