<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Service\PromoRate;
use PHPUnit\Framework\TestCase;

class PromoRateTest extends TestCase {
	private function account(?float $interest, ?float $promo, ?string $start, ?string $end): Account {
		$a = new Account();
		$a->setInterestRate($interest);
		$a->setPromoRate($promo);
		$a->setPromoStart($start);
		$a->setPromoEnd($end);
		return $a;
	}

	public function testAppliesInsideWindow(): void {
		$this->assertTrue(PromoRate::applies(0.0, '2026-01-01', '2027-01-01', '2026-01-01'));
		$this->assertTrue(PromoRate::applies(0.0, '2026-01-01', '2027-01-01', '2026-12-31'));
		$this->assertFalse(PromoRate::applies(0.0, '2026-01-01', '2027-01-01', '2027-01-01'));
		$this->assertFalse(PromoRate::applies(0.0, '2026-01-01', '2027-01-01', '2025-12-31'));
	}

	public function testAppliesFalseWhenAnyPartMissing(): void {
		$this->assertFalse(PromoRate::applies(null, '2026-01-01', '2027-01-01', '2026-06-01'));
		$this->assertFalse(PromoRate::applies(0.0, null, '2027-01-01', '2026-06-01'));
		$this->assertFalse(PromoRate::applies(0.0, '2026-01-01', null, '2026-06-01'));
	}

	public function testRateOnFallsBackToNormalRate(): void {
		$a = $this->account(22.9, 0.0, '2026-01-01', '2027-01-01');
		$this->assertSame(0.0, PromoRate::rateOn($a, '2026-06-01'));
		$this->assertSame(22.9, PromoRate::rateOn($a, '2027-01-01'));
	}

	public function testRateOnWithoutPromoReturnsInterestRate(): void {
		$this->assertSame(22.9, PromoRate::rateOn($this->account(22.9, null, null, null), '2026-06-01'));
		$this->assertSame(0.0, PromoRate::rateOn($this->account(null, null, null, null), '2026-06-01'));
	}

	public function testValidateRejectsMissingEnd(): void {
		$this->expectException(\InvalidArgumentException::class);
		PromoRate::validate(0.0, '2026-01-01', null);
	}

	public function testValidateRejectsMissingStart(): void {
		$this->expectException(\InvalidArgumentException::class);
		PromoRate::validate(0.0, null, '2027-01-01');
	}

	public function testValidateRejectsStartAfterEnd(): void {
		$this->expectException(\InvalidArgumentException::class);
		PromoRate::validate(0.0, '2027-01-01', '2026-01-01');
	}

	public function testValidateRejectsRateOutOfRange(): void {
		$this->expectException(\InvalidArgumentException::class);
		PromoRate::validate(1000.0, '2026-01-01', '2027-01-01');
	}

	public function testValidateRejectsBadDateFormat(): void {
		$this->expectException(\InvalidArgumentException::class);
		PromoRate::validate(0.0, '01/01/2026', '2027-01-01');
	}

	public function testValidateAcceptsAllNull(): void {
		PromoRate::validate(null, null, null);
		$this->addToAssertionCount(1);
	}

	public function testValidateAcceptsValidPromo(): void {
		PromoRate::validate(0.0, '2026-01-01', '2027-01-01');
		$this->addToAssertionCount(1);
	}

	public function testEndsWithin(): void {
		$this->assertTrue(PromoRate::endsWithin($this->account(null, 0.0, '2026-01-01', '2026-12-09'), '2026-11-09', 30));
		$this->assertFalse(PromoRate::endsWithin($this->account(null, 0.0, '2026-01-01', '2026-12-10'), '2026-11-09', 30));
		$this->assertFalse(PromoRate::endsWithin($this->account(null, 0.0, '2026-01-01', '2026-11-09'), '2026-11-09', 30));
		$this->assertFalse(PromoRate::endsWithin($this->account(null, null, null, null), '2026-11-09', 30));
	}
}
