<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Bill;

use OCA\Budget\Service\Bill\BillNameMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BillNameMatcherTest extends TestCase {
	/**
	 * The bills of the August 2026 double count against the NatWest text
	 * their payments came in with
	 */
	public static function augustPairs(): array {
		return [
			['Octopus Energy', 'OCTOPUS ENERGY'],
			['Me Nationwide', 'ME NATIONWIDE'],
			['Lynne Williams', 'LYNNE WILLIAMS'],
			['Morgan Aj', 'MORGAN AJ'],
			['Adam', 'ADAM'],
			['Dwr Cymru Welsh Wa', 'DWR CYMRU WELSH WA'],
			['Nptcbc Income Acct', 'NPTCBC INCOME ACCT'],
			['Ee', 'EE LIMITED'],
			['Claude.ai', 'ANTHROPIC* CLAUDE'],
			['Aquiss', 'AQUISS'],
			['Practice Plan', 'PRACTICE PLAN'],
			['Sumup **neath Port', 'SUMUP **NEATH PORT'],
		];
	}

	#[DataProvider('augustPairs')]
	public function testAugustBillsMatchTheirBankText(string $bill, string $bank): void {
		$this->assertTrue(BillNameMatcher::matches($bill, $bank));
	}

	public static function otherMatches(): array {
		return [
			'bank cut the name short' => ['Dwr Cymru Welsh Water', 'DWR CYMRU WELSH WA'],
			'bill cut short, bank in full' => ['Dwr Cymru Welsh Wa', 'DWR CYMRU WELSH WATER'],
			'one distinctive word' => ['Netflix Subscription', 'NETFLIX.COM'],
			'name in the payee, not the memo' => ['Octopus Energy', 'DD REF 12345', 'Octopus Energy Ltd'],
		];
	}

	#[DataProvider('otherMatches')]
	public function testMatches(string $bill, string ...$texts): void {
		$this->assertTrue(BillNameMatcher::matches($bill, ...$texts));
	}

	public static function nonMatches(): array {
		return [
			'a short name inside a word' => ['Ee', 'COFFEE SHOP'],
			'a word starting a longer one' => ['Practice Plan', 'PLANET ORGANIC'],
			'only a generic word' => ['Tesco Bank', 'BARCLAYS BANK'],
			'other payee' => ['Octopus Energy', 'BRITISH GAS'],
			'empty bill name' => ['', 'OCTOPUS ENERGY'],
			'empty text' => ['Octopus Energy', ''],
			'punctuation only' => ['**', 'SUMUP **NEATH PORT'],
		];
	}

	#[DataProvider('nonMatches')]
	public function testDoesNotMatch(string $bill, string ...$texts): void {
		$this->assertFalse(BillNameMatcher::matches($bill, ...$texts));
	}
}
