<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\MoneyCalculator;
use PHPUnit\Framework\TestCase;

class MoneyCalculatorTest extends TestCase {

	// ── add ──────────────────────────────────────────────────────────

	public function testAddFloats(): void {
		$this->assertSame('30.30', MoneyCalculator::add(10.10, 20.20));
	}

	public function testAddStrings(): void {
		$this->assertSame('30.30', MoneyCalculator::add('10.10', '20.20'));
	}

	public function testAddMixed(): void {
		$this->assertSame('25.75', MoneyCalculator::add(10.50, '15.25'));
	}

	public function testAddNegatives(): void {
		$this->assertSame('-5.00', MoneyCalculator::add(-10.0, 5.0));
	}

	public function testAddCustomScale(): void {
		$this->assertSame('1.333', MoneyCalculator::add('1.111', '0.222', 3));
	}

	// ── subtract ────────────────────────────────────────────────────

	public function testSubtract(): void {
		$this->assertSame('5.00', MoneyCalculator::subtract(15.0, 10.0));
	}

	public function testSubtractResultsNegative(): void {
		$this->assertSame('-5.00', MoneyCalculator::subtract(10.0, 15.0));
	}

	public function testSubtractFloatingPointPrecision(): void {
		// Classic floating-point issue: 0.3 - 0.1 != 0.2 in IEEE 754
		$this->assertSame('0.20', MoneyCalculator::subtract(0.3, 0.1));
	}

	// ── multiply ────────────────────────────────────────────────────

	public function testMultiply(): void {
		$this->assertSame('25.00', MoneyCalculator::multiply(5.0, 5.0));
	}

	public function testMultiplyDecimals(): void {
		$this->assertSame('12.50', MoneyCalculator::multiply(2.5, 5.0));
	}

	public function testMultiplyByNegative(): void {
		$this->assertSame('-50.00', MoneyCalculator::multiply(10.0, -5.0));
	}

	public function testMultiplyByZero(): void {
		$this->assertSame('0.00', MoneyCalculator::multiply(100.0, 0.0));
	}

	// ── divide ──────────────────────────────────────────────────────

	public function testDivide(): void {
		$this->assertSame('5.00', MoneyCalculator::divide(10.0, 2.0));
	}

	public function testDivideWithRemainder(): void {
		$this->assertSame('3.33', MoneyCalculator::divide(10.0, 3.0));
	}

	public function testDivideCustomScale(): void {
		$this->assertSame('3.3333', MoneyCalculator::divide(10.0, 3.0, 4));
	}

	public function testDivideByZeroThrows(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Division by zero');
		MoneyCalculator::divide(10.0, 0.0);
	}

	public function testDivideByZeroStringThrows(): void {
		$this->expectException(\InvalidArgumentException::class);
		MoneyCalculator::divide(10.0, '0');
	}

	// ── compare ─────────────────────────────────────────────────────

	public function testCompareEqual(): void {
		$this->assertSame(0, MoneyCalculator::compare(10.0, 10.0));
	}

	public function testCompareGreater(): void {
		$this->assertSame(1, MoneyCalculator::compare(10.01, 10.0));
	}

	public function testCompareLess(): void {
		$this->assertSame(-1, MoneyCalculator::compare(9.99, 10.0));
	}

	public function testCompareStringsAndFloats(): void {
		$this->assertSame(0, MoneyCalculator::compare('10.00', 10.0));
	}

	// ── equals ──────────────────────────────────────────────────────

	public function testEqualsExact(): void {
		$this->assertTrue(MoneyCalculator::equals(10.0, 10.0));
	}

	public function testEqualsWithinDefaultTolerance(): void {
		$this->assertTrue(MoneyCalculator::equals(10.0, 10.005));
	}

	public function testEqualsOutsideTolerance(): void {
		$this->assertFalse(MoneyCalculator::equals(10.0, 10.02));
	}

	public function testEqualsCustomTolerance(): void {
		$this->assertTrue(MoneyCalculator::equals(10.0, 10.05, '0.10'));
	}

	public function testEqualsNegative(): void {
		$this->assertTrue(MoneyCalculator::equals(-5.0, -5.0));
	}

	// ── abs ──────────────────────────────────────────────────────────

	public function testAbsPositive(): void {
		// Float input normalizes to 10-decimal sprintf format; positive stays as-is
		$this->assertSame('10.0000000000', MoneyCalculator::abs(10.0));
	}

	public function testAbsNegative(): void {
		// Negative float → multiplied by -1 with default scale (2)
		$this->assertSame('10.00', MoneyCalculator::abs(-10.0));
	}

	public function testAbsZero(): void {
		$this->assertSame('0.0000000000', MoneyCalculator::abs(0.0));
	}

	public function testAbsString(): void {
		// String input: '-25.50' → bcmul by -1 with scale 2 → '25.50'
		$this->assertSame('25.50', MoneyCalculator::abs('-25.50'));
	}

	public function testAbsStringPositive(): void {
		// String positive: passes through normalize directly
		$this->assertSame('25.50', MoneyCalculator::abs('25.50'));
	}

	// ── sum ──────────────────────────────────────────────────────────

	public function testSumMultipleValues(): void {
		$this->assertSame('60.30', MoneyCalculator::sum([10.10, 20.20, 30.00]));
	}

	public function testSumEmpty(): void {
		$this->assertSame('0', MoneyCalculator::sum([]));
	}

	public function testSumSingleValue(): void {
		$this->assertSame('42.50', MoneyCalculator::sum([42.50]));
	}

	public function testSumMixedPositiveNegative(): void {
		$this->assertSame('0.00', MoneyCalculator::sum([100.0, -50.0, -50.0]));
	}

	public function testSumStrings(): void {
		$this->assertSame('30.00', MoneyCalculator::sum(['10.00', '20.00']));
	}

	// ── toFloat ─────────────────────────────────────────────────────

	public function testToFloat(): void {
		$this->assertSame(10.5, MoneyCalculator::toFloat('10.5'));
	}

	public function testToFloatNegative(): void {
		$this->assertSame(-25.99, MoneyCalculator::toFloat('-25.99'));
	}

	// ── format ──────────────────────────────────────────────────────

	public function testFormatUsd(): void {
		$this->assertSame('$1,234.56', MoneyCalculator::format(1234.56, 'USD'));
	}

	public function testFormatEur(): void {
		$this->assertSame('€500.00', MoneyCalculator::format(500.0, 'EUR'));
	}

	public function testFormatGbp(): void {
		$this->assertSame('£99.99', MoneyCalculator::format(99.99, 'GBP'));
	}

	public function testFormatJpy(): void {
		$this->assertSame('¥1,000.00', MoneyCalculator::format(1000.0, 'JPY'));
	}

	public function testFormatCad(): void {
		$this->assertSame('C$50.00', MoneyCalculator::format(50.0, 'CAD'));
	}

	public function testFormatAud(): void {
		$this->assertSame('A$75.50', MoneyCalculator::format(75.5, 'AUD'));
	}

	public function testFormatChf(): void {
		$this->assertSame('CHF 100.00', MoneyCalculator::format(100.0, 'CHF'));
	}

	public function testFormatUnknownCurrency(): void {
		$this->assertSame('BRL 50.00', MoneyCalculator::format(50.0, 'BRL'));
	}

	public function testFormatNegative(): void {
		$this->assertSame('$-50.00', MoneyCalculator::format(-50.0, 'USD'));
	}

	public function testFormatCustomDecimals(): void {
		$this->assertSame('$100.0', MoneyCalculator::format(100.0, 'USD', 1));
	}

	public function testFormatLargeNumber(): void {
		$this->assertSame('$1,000,000.00', MoneyCalculator::format(1000000.0, 'USD'));
	}

	public function testFormatStringInput(): void {
		$this->assertSame('£25.50', MoneyCalculator::format('25.50', 'GBP'));
	}
}
