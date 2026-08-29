<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import;

use OCA\Budget\Service\Import\FileValidator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class FileValidatorTest extends TestCase {
	private FileValidator $validator;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			foreach ($params as $i => $param) {
				$text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
			}
			return $text;
		});
		$this->validator = new FileValidator($l);
	}

	// ── validateSize ────────────────────────────────────────────────

	public function testValidateSizeAcceptsSmallFile(): void {
		$this->validator->validateSize(1024);
		$this->assertTrue(true); // No exception
	}

	public function testValidateSizeAcceptsExactLimit(): void {
		$this->validator->validateSize(10 * 1024 * 1024);
		$this->assertTrue(true);
	}

	public function testValidateSizeRejectsOverLimit(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('File too large');
		$this->validator->validateSize(10 * 1024 * 1024 + 1);
	}

	public function testValidateSizeAcceptsZero(): void {
		$this->validator->validateSize(0);
		$this->assertTrue(true);
	}

	// ── validateExtension ───────────────────────────────────────────

	public function testValidateExtensionCsv(): void {
		$this->assertSame('csv', $this->validator->validateExtension('data.csv'));
	}

	public function testValidateExtensionOfx(): void {
		$this->assertSame('ofx', $this->validator->validateExtension('bank.ofx'));
	}

	public function testValidateExtensionQif(): void {
		$this->assertSame('qif', $this->validator->validateExtension('money.qif'));
	}

	public function testValidateExtensionTxt(): void {
		$this->assertSame('txt', $this->validator->validateExtension('export.txt'));
	}

	public function testValidateExtensionUppercase(): void {
		$this->assertSame('csv', $this->validator->validateExtension('DATA.CSV'));
	}

	public function testValidateExtensionMixedCase(): void {
		$this->assertSame('ofx', $this->validator->validateExtension('Bank.Ofx'));
	}

	public function testValidateExtensionRejectsExe(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Unsupported file format');
		$this->validator->validateExtension('malware.exe');
	}

	public function testValidateExtensionRejectsPdf(): void {
		$this->expectException(\Exception::class);
		$this->validator->validateExtension('report.pdf');
	}

	public function testValidateExtensionRejectsNoExtension(): void {
		$this->expectException(\Exception::class);
		$this->validator->validateExtension('noextension');
	}

	public function testValidateExtensionWithPath(): void {
		$this->assertSame('csv', $this->validator->validateExtension('/path/to/file.csv'));
	}

	// ── containsBinaryData ──────────────────────────────────────────

	public function testContainsBinaryDataFalseForText(): void {
		$this->assertFalse($this->validator->containsBinaryData("Date,Amount,Description\n2025-01-01,100.00,Groceries\n"));
	}

	public function testContainsBinaryDataTrueForNullBytes(): void {
		$this->assertTrue($this->validator->containsBinaryData("some\x00binary\x00data"));
	}

	public function testContainsBinaryDataTrueForHighNonPrintableRatio(): void {
		// 50% non-printable chars
		$binary = str_repeat("\x01\x02", 50) . str_repeat('A', 100);
		$this->assertTrue($this->validator->containsBinaryData($binary));
	}

	public function testContainsBinaryDataFalseForTabsAndNewlines(): void {
		// Tabs, newlines, carriage returns are allowed
		$this->assertFalse($this->validator->containsBinaryData("col1\tcol2\r\ncol3\tcol4\n"));
	}

	public function testContainsBinaryDataFalseForEmptyString(): void {
		$this->assertFalse($this->validator->containsBinaryData(''));
	}

	public function testContainsBinaryDataFalseForLowNonPrintableRatio(): void {
		// One non-printable in 100 chars = 1% (under the threshold)
		$content = str_repeat('A', 99) . "\x01";
		$this->assertFalse($this->validator->containsBinaryData($content));
	}

	/**
	 * UTF-8 text is not binary, whatever script it is written in (#369). The
	 * old check allowed UTF-8 lead bytes but not continuation bytes, so half
	 * the bytes of a Cyrillic statement scored as non-printable and the import
	 * was refused. Accented Latin is in here because it tipped over the old
	 * 0.1 threshold too — this was never only a non-Latin problem.
	 *
	 * @dataProvider utf8TextProvider
	 */
	public function testContainsBinaryDataFalseForUtf8Text(string $sample): void {
		$this->assertFalse($this->validator->containsBinaryData(str_repeat($sample, 50)));
	}

	public static function utf8TextProvider(): array {
		return [
			'cyrillic' => ["Пятёрочка;Супермаркеты;Между своими счетами\n"],
			'greek' => ["Λογαριασμός;Ημερομηνία;Ποσό\n"],
			'hebrew' => ["חשבון;תאריך;סכום\n"],
			'arabic' => ["الحساب;التاريخ;المبلغ\n"],
			'cjk' => ["口座;日付;金額;説明\n"],
			'accented latin' => ["Café;Épicerie;Achats à Paris;Coût\n"],
		];
	}

	public function testContainsBinaryDataTrueForNonUtf8HighByteBinary(): void {
		// High bytes alone must not condemn a file, but control characters
		// still must — a NUL-free binary stays rejected on the ratio
		$binary = str_repeat("\xC3\x01\x02\x03\x04", 200);
		$this->assertTrue($this->validator->containsBinaryData($binary));
	}

	public function testContainsBinaryDataFalseForLegacyEightBitText(): void {
		// Windows-1251 Cyrillic: high bytes, no control characters. Banks
		// still export these, so the gate must not call them binary — nothing
		// transcodes them downstream yet, but that is a separate concern
		$latin1 = str_repeat("\xCF\xFF\xE5\xF0\xEE\xF7\xEA\xE0;100,00\n", 50);
		$this->assertFalse($this->validator->containsBinaryData($latin1));
	}

	// ── detectDelimiter ─────────────────────────────────────────────

	public function testDetectDelimiterComma(): void {
		$content = "Date,Amount,Description\n2025-01-01,100.00,Groceries\n";
		$this->assertSame(',', $this->validator->detectDelimiter($content));
	}

	public function testDetectDelimiterSemicolon(): void {
		$content = "Date;Amount;Description\n2025-01-01;100,00;Groceries\n";
		$this->assertSame(';', $this->validator->detectDelimiter($content));
	}

	public function testDetectDelimiterTab(): void {
		$content = "Date\tAmount\tDescription\n2025-01-01\t100.00\tGroceries\n";
		$this->assertSame("\t", $this->validator->detectDelimiter($content));
	}

	public function testDetectDelimiterDefaultsToComma(): void {
		$content = "single column data\nrow two\n";
		$this->assertSame(',', $this->validator->detectDelimiter($content));
	}

	public function testDetectDelimiterSkipsEmptyLines(): void {
		$content = "\n\nDate,Amount,Description\n2025-01-01,100.00,Groceries\n";
		$this->assertSame(',', $this->validator->detectDelimiter($content));
	}

	public function testDetectDelimiterSemicolonBeatsComma(): void {
		// More semicolons than commas
		$content = "Date;Amount;Category;Description,Note\n";
		$this->assertSame(';', $this->validator->detectDelimiter($content));
	}

	// ── getAllowedExtensions / getMaxFileSize ────────────────────────

	public function testGetAllowedExtensions(): void {
		$extensions = $this->validator->getAllowedExtensions();
		$this->assertContains('csv', $extensions);
		$this->assertContains('ofx', $extensions);
		$this->assertContains('qif', $extensions);
		$this->assertContains('txt', $extensions);
		$this->assertCount(5, $extensions);
	}

	public function testGetMaxFileSize(): void {
		$this->assertSame(10 * 1024 * 1024, $this->validator->getMaxFileSize());
	}

	// ── validate (integration of sub-validators) ────────────────────

	public function testValidateRejectsOversizedFile(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('File too large');
		$this->validator->validate('data.csv', 11 * 1024 * 1024);
	}

	public function testValidateRejectsBadExtension(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Unsupported file format');
		$this->validator->validate('data.xlsx', 1024);
	}

	public function testValidatePassesWithoutTmpPath(): void {
		$this->validator->validate('data.csv', 1024);
		$this->assertTrue(true);
	}

	public function testValidatePassesWithNullTmpPath(): void {
		$this->validator->validate('data.csv', 1024, null);
		$this->assertTrue(true);
	}

	// ── validateContent with temp files ─────────────────────────────

	public function testValidateContentRejectsEmptyFile(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, '');

		try {
			$this->expectException(\Exception::class);
			$this->expectExceptionMessage('empty or unreadable');
			$this->validator->validateContent($tmp, 'csv');
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateContentRejectsBinaryFile(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, str_repeat("\x00\x01\x02", 100));

		try {
			$this->expectException(\Exception::class);
			$this->expectExceptionMessage('binary');
			$this->validator->validateContent($tmp, 'csv');
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateContentAcceptsValidCsv(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, "Date,Amount,Description\n2025-01-01,100.00,Test\n");

		try {
			$this->validator->validateContent($tmp, 'csv');
			$this->assertTrue(true);
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateContentRejectsCsvWithOneRow(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, "Date,Amount,Description\n");

		try {
			$this->expectException(\Exception::class);
			$this->expectExceptionMessage('at least a header row and one data row');
			$this->validator->validateContent($tmp, 'csv');
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateContentRejectsCsvWithNoDelimiters(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, "nodelmiter\nrow two\n");

		try {
			$this->expectException(\Exception::class);
			$this->expectExceptionMessage('valid delimiters');
			$this->validator->validateContent($tmp, 'csv');
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateContentAcceptsValidOfx(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, "OFXHEADER:100\nDATA:OFXSGML\n<OFX>\n<SIGNONMSGSRSV1>\n");

		try {
			$this->validator->validateContent($tmp, 'ofx');
			$this->assertTrue(true);
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateContentAcceptsOfxWithTag(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, "<?xml version=\"1.0\"?>\n<OFX>\n<DATA>test</DATA>\n");

		try {
			$this->validator->validateContent($tmp, 'ofx');
			$this->assertTrue(true);
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateContentRejectsInvalidOfx(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, "This is just plain text, not OFX at all\n");

		try {
			$this->expectException(\Exception::class);
			$this->expectExceptionMessage('valid OFX file');
			$this->validator->validateContent($tmp, 'ofx');
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateContentAcceptsValidQif(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, "!Type:Bank\nD01/15/2025\nT-100.00\nPGroceries\n^\n");

		try {
			$this->validator->validateContent($tmp, 'qif');
			$this->assertTrue(true);
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateContentRejectsQifWithoutType(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, "D01/15/2025\nT-100.00\nPGroceries\n^\n");

		try {
			$this->expectException(\Exception::class);
			$this->expectExceptionMessage('Missing !Type:');
			$this->validator->validateContent($tmp, 'qif');
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateContentRejectsQifWithoutTransactionMarker(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, "!Type:Bank\nD01/15/2025\nT-100.00\nPGroceries\n");

		try {
			$this->expectException(\Exception::class);
			$this->expectExceptionMessage('transaction end markers');
			$this->validator->validateContent($tmp, 'qif');
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateContentAcceptsQifWithAccountHeader(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, "!Account\nNChecking\n^\n!Type:Bank\nD01/15/2025\nT-100.00\n^\n");

		try {
			$this->validator->validateContent($tmp, 'qif');
			$this->assertTrue(true);
		} finally {
			unlink($tmp);
		}
	}

	// ── validateMimeType with temp files ────────────────────────────

	public function testValidateMimeTypeAcceptsTextPlainForCsv(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, "Date,Amount,Description\n2025-01-01,100.00,Test\n");

		try {
			$this->validator->validateMimeType($tmp, 'csv');
			$this->assertTrue(true);
		} finally {
			unlink($tmp);
		}
	}

	public function testValidateFullIntegrationWithTmpFile(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmp, "Date,Amount,Description\n2025-01-01,100.00,Test\n");

		try {
			$this->validator->validate('import.csv', 1024, $tmp);
			$this->assertTrue(true);
		} finally {
			unlink($tmp);
		}
	}

    // ===== camt.053 XML (#350) =====

    public function testXmlExtensionIsAllowed(): void {
        $this->assertSame('xml', $this->validator->validateExtension('statement.xml'));
    }

    public function testCamtContentIsAccepted(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'camt');
        file_put_contents($tmp, file_get_contents(__DIR__ . '/../Parser/fixtures/camt053-wir-sample.xml'));
        try {
            $this->validator->validateContent($tmp, 'xml');
            $this->addToAssertionCount(1);
        } finally {
            unlink($tmp);
        }
    }

    public function testArbitraryXmlIsRejected(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'camt');
        file_put_contents($tmp, '<?xml version="1.0"?><note><to>Tove</to></note>');
        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('camt');
            $this->validator->validateContent($tmp, 'xml');
        } finally {
            unlink($tmp);
        }
    }
}
