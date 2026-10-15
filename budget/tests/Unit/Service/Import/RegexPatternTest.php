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

	/**
	 * A replacement edits text, so it has to count characters: in byte mode
	 * "the first 20 characters" cut "ü" in half and stored broken text.
	 */
	public function testAReplacePatternWorksOnCharacters(): void {
		$this->assertSame('/^(.{20}).+$/iu', RegexPattern::forReplace('^(.{20}).+$'));
		$this->assertSame('/^ORDER-\d+$/u', RegexPattern::forReplace('/^ORDER-\d+$/'));
		$this->assertSame('/amazon/iu', RegexPattern::forReplace('/amazon/iu'));
	}

	public function testAReplacePatternThatOnlyCompilesOnBytesStillWorks(): void {
		// Valid as it was saved, but not as UTF-8: it keeps its old meaning
		// rather than silently doing nothing
		$this->assertSame("/\xC3/i", RegexPattern::forReplace("\xC3"));
	}

	public function testABrokenReplacePatternHasNoRegex(): void {
		$this->assertNull(RegexPattern::forReplace('([a-z'));
		$this->assertNull(RegexPattern::forReplace(''));
	}

	public function testMatchingIsLeftAsItWas(): void {
		// Only replacements changed: what a rule matches stays the same
		$this->assertSame('/^(.{20}).+$/i', RegexPattern::toPcre('^(.{20}).+$'));
	}
}
