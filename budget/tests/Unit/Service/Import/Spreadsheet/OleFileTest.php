<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Service\Import\Spreadsheet\OleFile;
use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetReadError;
use PHPUnit\Framework\TestCase;

class OleFileTest extends TestCase {
	private const FIXTURES = __DIR__ . '/../../../../fixtures/spreadsheets/';

	public function testAStreamInTheMiniStream(): void {
		$ole = new OleFile(file_get_contents(self::FIXTURES . 'statement.xls'));
		$this->assertTrue($ole->hasStream('workbook'));
		$this->assertStringStartsWith("\x09\x08", $ole->stream('Workbook'));
	}

	public function testAStreamInRegularSectors(): void {
		$stream = (new OleFile(file_get_contents(self::FIXTURES . 'statement-large.xls')))->stream('Workbook');
		$this->assertGreaterThan(4096, strlen($stream));
		$this->assertStringStartsWith("\x09\x08", $stream);
	}

	public function testOtherStreams(): void {
		$ole = new OleFile(file_get_contents(self::FIXTURES . 'encrypted.xlsx'));
		$this->assertTrue($ole->hasStream('EncryptionInfo'));
		$this->assertFalse($ole->hasStream('Workbook'));
	}

	public function testGarbageAfterTheSignature(): void {
		$this->expectException(SpreadsheetReadError::class);
		new OleFile(OleFile::SIGNATURE . str_repeat("\xFF", 600));
	}

	public function testATruncatedFile(): void {
		$this->expectException(SpreadsheetReadError::class);
		(new OleFile(substr(file_get_contents(self::FIXTURES . 'statement-large.xls'), 0, 1600)))->stream('Workbook');
	}

	public function testASectorChainThatLoops(): void {
		// Header: v3, 512-byte sectors, one FAT sector (0), directory starting at sector 1
		$header = OleFile::SIGNATURE . str_repeat("\0", 16)
			. pack('vvvvv', 0x3E, 3, 0xFFFE, 9, 6) . str_repeat("\0", 6)
			. pack('VVVVVVVVV', 0, 1, 1, 0, 4096, 0xFFFFFFFE, 0, 0xFFFFFFFE, 0)
			. pack('V', 0) . str_repeat("\xFF", 4 * 108);
		// FAT: sector 0 is the FAT itself, sector 1 points at itself
		$fat = pack('VV', 0xFFFFFFFD, 1) . str_repeat("\xFF", 504);
		$this->expectException(SpreadsheetReadError::class);
		new OleFile($header . $fat . str_repeat("\0", 512));
	}
}
