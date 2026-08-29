<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Service\Import\EncodingNormalizer;
use PHPUnit\Framework\TestCase;

class EncodingNormalizerTest extends TestCase {
	private EncodingNormalizer $normalizer;

	protected function setUp(): void {
		$this->normalizer = new EncodingNormalizer();
	}

	// ── toUtf8 ──────────────────────────────────────────────────────

	public function testUtf8IsReturnedUntouched(): void {
		$content = "Пятёрочка;Супермаркеты;100,00\n";
		$this->assertSame($content, $this->normalizer->toUtf8($content));
	}

	public function testAsciiIsReturnedUntouched(): void {
		$content = "Date,Amount,Description\n2026-01-01,10.00,Coffee\n";
		$this->assertSame($content, $this->normalizer->toUtf8($content));
	}

	/**
	 * The bug: ISO-8859-1 accepts every byte value, so it always matched
	 * first and every non-UTF-8 upload was decoded as Latin-1 (#371).
	 */
	public function testDeclaredWindows1251IsNotDecodedAsLatin1(): void {
		$text = "Пятёрочка;100,00\n";
		$content = "<?xml version=\"1.0\" encoding=\"windows-1251\"?>\n<doc>"
			. mb_convert_encoding($text, 'Windows-1251', 'UTF-8') . "</doc>";

		$result = $this->normalizer->toUtf8($content);

		$this->assertStringContainsString('Пятёрочка', $result);
		$this->assertStringNotContainsString('Ïÿò', $result);
	}

	public function testXmlDeclarationIsRetaggedToUtf8(): void {
		$content = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n<doc>"
			. mb_convert_encoding("Coût\n", 'ISO-8859-1', 'UTF-8') . "</doc>";

		$result = $this->normalizer->toUtf8($content);

		// Bytes and label must agree, or DOMDocument decodes them twice
		$this->assertStringContainsString('encoding="UTF-8"', $result);
		$this->assertStringNotContainsString('ISO-8859-1', $result);
		$this->assertTrue(mb_check_encoding($result, 'UTF-8'));
	}

	public function testRetaggedXmlSurvivesDomParsing(): void {
		$content = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n<doc><name>"
			. mb_convert_encoding('Coût', 'ISO-8859-1', 'UTF-8') . '</name></doc>';

		$doc = new \DOMDocument();
		$doc->loadXML($this->normalizer->toUtf8($content));

		$this->assertSame('Coût', $doc->getElementsByTagName('name')->item(0)->textContent);
	}

	public function testUndeclaredContentFallsBackToWindows1252(): void {
		// 0x80 and 0x96 are the euro sign and an en dash in Windows-1252,
		// unusable C1 controls in ISO-8859-1
		$content = "Total;\x80100\x9650\n";

		$result = $this->normalizer->toUtf8($content);

		$this->assertStringContainsString('€', $result);
		$this->assertStringContainsString('–', $result);
	}

	public function testOfxCharsetHeaderIsHonoured(): void {
		$content = "OFXHEADER:100\r\nDATA:OFXSGML\r\nVERSION:102\r\n"
			. "ENCODING:USASCII\r\nCHARSET:1251\r\n\r\n<OFX><NAME>"
			. mb_convert_encoding('Пятёрочка', 'Windows-1251', 'UTF-8') . '</NAME></OFX>';

		$result = $this->normalizer->toUtf8($content);

		$this->assertStringContainsString('Пятёрочка', $result);
	}

	public function testResultIsAlwaysValidUtf8(): void {
		// Every byte value, declared as nothing
		$content = '';
		for ($i = 0; $i < 256; $i++) {
			$content .= chr($i);
		}

		$this->assertTrue(mb_check_encoding($this->normalizer->toUtf8($content), 'UTF-8'));
	}

	// ── explicit override (the import screen's picker) ──────────────

	public function testExplicitEncodingIsObeyedOverDetection(): void {
		// Undeclared Windows-1251 is indistinguishable from Windows-1252, so
		// detection settles on the latter and only the user can correct it
		$content = mb_convert_encoding("Пятёрочка;100,00\n", 'Windows-1251', 'UTF-8');

		$this->assertSame('Windows-1252', $this->normalizer->detectedEncoding($content));
		$this->assertStringContainsString('Ïÿò', $this->normalizer->toUtf8($content));
		$this->assertStringContainsString('Пятёрочка', $this->normalizer->toUtf8($content, 'Windows-1251'));
	}

	public function testExplicitEncodingWinsOverAValidDeclaration(): void {
		// A file can declare the wrong thing; the picker exists to overrule it
		$content = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><doc>"
			. mb_convert_encoding('Пятёрочка', 'Windows-1251', 'UTF-8') . '</doc>';

		$this->assertStringContainsString('Пятёрочка', $this->normalizer->toUtf8($content, 'Windows-1251'));
	}

	public function testUnsupportedEncodingFallsBackToDetection(): void {
		$content = mb_convert_encoding("Coût\n", 'ISO-8859-1', 'UTF-8');

		$this->assertSame(
			$this->normalizer->toUtf8($content),
			$this->normalizer->toUtf8($content, 'NOT-A-REAL-SET')
		);
	}

	public function testExplicitUtf8LeavesValidUtf8Untouched(): void {
		$content = "Пятёрочка;100,00\n";
		$this->assertSame($content, $this->normalizer->toUtf8($content, 'UTF-8'));
	}

	public function testSupportedEncodingsAreAllConvertible(): void {
		$encodings = $this->normalizer->supportedEncodings();

		$this->assertNotEmpty($encodings);
		$this->assertArrayHasKey('UTF-8', $encodings);

		// An offered encoding mbstring cannot convert from would fail silently
		foreach (array_keys($encodings) as $name) {
			$this->assertTrue(
				$this->normalizer->isSupported($name),
				"$name is offered but not usable"
			);
			$this->assertTrue(
				mb_check_encoding($this->normalizer->toUtf8("Total;100,00\n", $name), 'UTF-8'),
				"$name did not produce valid UTF-8"
			);
		}
	}

	public function testUnsupportedEncodingIsNotOffered(): void {
		// mbstring has no Windows-1253; it must not reach the picker
		$this->assertFalse($this->normalizer->isSupported('Windows-1253'));
	}

	// ── declaredEncoding ────────────────────────────────────────────

	public function testDeclaredEncodingReadsXmlDeclaration(): void {
		$this->assertSame(
			'ISO-8859-1',
			$this->normalizer->declaredEncoding('<?xml version="1.0" encoding="iso-8859-1"?><doc/>')
		);
	}

	public function testDeclaredEncodingReadsOfxCharsetCodePage(): void {
		$this->assertSame(
			'Windows-1252',
			$this->normalizer->declaredEncoding("OFXHEADER:100\r\nENCODING:USASCII\r\nCHARSET:1252\r\n")
		);
	}

	public function testDeclaredEncodingIgnoresCharsetNone(): void {
		// OFX 2.x: CHARSET:NONE with a real ENCODING is the usual pairing
		$this->assertSame(
			'UTF-8',
			$this->normalizer->declaredEncoding("OFXHEADER:100\r\nENCODING:UTF-8\r\nCHARSET:NONE\r\n")
		);
	}

	public function testDeclaredEncodingIgnoresUsasciiAlone(): void {
		// USASCII names no code page, so there is nothing to convert from
		$this->assertNull(
			$this->normalizer->declaredEncoding("OFXHEADER:100\r\nENCODING:USASCII\r\n")
		);
	}

	public function testDeclaredEncodingReturnsNullForPlainCsv(): void {
		$this->assertNull($this->normalizer->declaredEncoding("Date,Amount\n2026-01-01,10.00\n"));
	}

	public function testDeclaredEncodingReturnsNullForUnknownName(): void {
		$this->assertNull($this->normalizer->declaredEncoding('<?xml version="1.0" encoding="NOT-A-REAL-SET"?>'));
	}
}
