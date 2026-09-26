<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Service\Import\RegexPattern;
use PHPUnit\Framework\TestCase;

class RegexPatternTest extends TestCase {
	public function testBarePatternIsCaseInsensitive(): void {
		$this->assertSame('/amazon|ebay/i', RegexPattern::toPcre('amazon|ebay'));
	}

	public function testBarePatternKeepsItsSpaces(): void {
		$this->assertSame('/^TFR /i', RegexPattern::toPcre('^TFR '));
	}

	public function testLiteralUsesItsOwnFlags(): void {
		$this->assertSame('/^ORDER-\d+$/', RegexPattern::toPcre('/^ORDER-\d+$/'));
		$this->assertSame('/amazon/iu', RegexPattern::toPcre(' /amazon/iu '));
	}

	public function testEmptyPatternHasNoRegex(): void {
		$this->assertNull(RegexPattern::toPcre(''));
		$this->assertNull(RegexPattern::toPcre('   '));
		$this->assertFalse(RegexPattern::isValid('  '));
	}

	public function testPcreOnlySyntaxIsValid(): void {
		// Inline flags and possessive quantifiers aren't JavaScript regex,
		// which is why the builder stopped checking patterns itself.
		$this->assertTrue(RegexPattern::isValid('(?i)amazon'));
		$this->assertTrue(RegexPattern::isValid('\d++'));
	}

	public function testBrokenPatternsAreInvalid(): void {
		$this->assertFalse(RegexPattern::isValid('([a-z'));
		$this->assertFalse(RegexPattern::isValid('/amazon/q'));
	}
}
