<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Service\Import\Spreadsheet\OdsReader;
use OCA\Budget\Service\Import\Spreadsheet\SheetBuilder;
use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetReadError;
use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetTooLargeError;
use OCA\Budget\Service\Import\Spreadsheet\ZipPackage;
use PHPUnit\Framework\TestCase;

class OdsReaderTest extends TestCase {
	private const FIXTURES = __DIR__ . '/../../../../fixtures/spreadsheets/';

	/** @var list<string> */
	private array $tempFiles = [];

	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			if (file_exists($file)) {
				unlink($file);
			}
		}
	}

	/** @return list<array{name: string, rows: list<list<string>>}> */
	private function readFile(string $path): array {
		$zip = new ZipPackage($path);
		try {
			return (new OdsReader())->read($zip);
		} finally {
			$zip->close();
		}
	}

	private function odsWith(string $tableContent): string {
		return $this->odsOf('<table:table table:name="Foglio1">' . $tableContent . '</table:table>');
	}

	/** An ODS of these tables, and these automatic styles */
	private function odsOf(string $tables, string $styles = ''): string {
		$path = tempnam(sys_get_temp_dir(), 'ods');
		$this->tempFiles[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
		$zip->addFromString('content.xml', '<office:document-content'
			. ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
			. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
			. ' xmlns:xlink="http://www.w3.org/1999/xlink"'
			. ' xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0">'
			. '<office:automatic-styles>' . $styles . '</office:automatic-styles>'
			. '<office:body><office:spreadsheet>' . $tables
			. '</office:spreadsheet></office:body></office:document-content>');
		$zip->close();
		return $path;
	}

	public function testReadsTheStatement(): void {
		$sheets = $this->readFile(self::FIXTURES . 'statement.ods');
		$this->assertSame('Movimenti', $sheets[0]['name']);
		$this->assertSame(XlsxReaderTest::STATEMENT_ROWS, $sheets[0]['rows']);
	}

	public function testHiddenSheetsAreSkipped(): void {
		$cell = static fn (string $text): string => '<table:table-row><table:table-cell office:value-type="string"><text:p>' . $text . '</text:p></table:table-cell></table:table-row>';
		$path = $this->odsOf(
			'<table:table table:name="Lookup" table:style-name="ta2">' . $cell('hidden') . '</table:table>'
			. '<table:table table:name="Movimenti" table:style-name="ta1">' . $cell('shown') . '</table:table>'
			. '<table:table table:name="Note">' . $cell('unstyled') . '</table:table>',
			'<style:style style:name="ta1" style:family="table"><style:table-properties table:display="true"/></style:style>'
			. '<style:style style:name="ta2" style:family="table"><style:table-properties table:display="false"/></style:style>'
			. '<style:style style:name="co1" style:family="table-column"><style:table-column-properties style:column-width="1in"/></style:style>'
		);
		$this->assertSame(['Movimenti', 'Note'], array_column($this->readFile($path), 'name'));
	}

	public function testCellTypesAndRepeats(): void {
		$path = $this->odsWith(
			'<table:table-row>'
			. '<table:table-cell office:value-type="float" calcext:value-type="float" office:value="1.234"><text:p>1,23</text:p></table:table-cell>'
			. '<table:table-cell table:number-columns-repeated="2"/>'
			. '<table:table-cell office:value-type="date" office:date-value="2026-10-01T00:00:00"><text:p>01/10/26</text:p></table:table-cell>'
			. '<table:table-cell office:value-type="boolean" office:boolean-value="true"/>'
			. '<table:table-cell office:value-type="time" office:time-value="PT14H30M00S"/>'
			. '<table:table-cell office:value-type="string"><text:p>Riga uno</text:p><text:p>riga due</text:p>'
			. '<office:annotation><text:p>commento</text:p></office:annotation></table:table-cell>'
			. '</table:table-row>'
			. '<table:table-row table:number-rows-repeated="2"><table:table-cell office:value-type="string"><text:p>x</text:p></table:table-cell></table:table-row>'
		);
		$this->assertSame([
			['1.2340', '', '', '2026-10-01', 'TRUE', '14:30:00', 'Riga uno riga due'],
			['x'],
			['x'],
		], $this->readFile($path)[0]['rows']);
	}

	public function testSpacesTabsAndLineBreaksInsideACell(): void {
		// LibreOffice writes a run of spaces as <text:s text:c="N"/>, and N
		// spaces are what the cell holds; a tab or a line break is a space
		// here, as every line break in a cell is in the CSV
		$path = $this->odsWith(
			'<table:table-row><table:table-cell office:value-type="string">'
			. '<text:p>CARD<text:line-break/>TESCO<text:tab/>LONDON<text:s text:c="3"/>GB<text:s/>1</text:p>'
			. '<text:p><text:span text:style-name="T1">second</text:span> <text:a xlink:href="x">para</text:a></text:p>'
			. '</table:table-cell></table:table-row>'
		);
		$this->assertSame([['CARD TESCO LONDON   GB 1 second para']], $this->readFile($path)[0]['rows']);
	}

	public function testACellOfManyElementsIsTooLarge(): void {
		// Expanding this one cell into a DOM took gigabytes outside memory_limit
		$path = $this->odsWith('<table:table-row><table:table-cell>' . str_repeat('<a/>', 200000) . '</table:table-cell></table:table-row>');
		$this->expectException(SpreadsheetTooLargeError::class);
		$this->readFile($path);
	}

	public function testANumberCellOfManyElementsIsTooLarge(): void {
		$path = $this->odsWith('<table:table-row><table:table-cell office:value-type="float" office:value="1">'
			. str_repeat('<text:p/>', 200000) . '</table:table-cell></table:table-row>');
		$this->expectException(SpreadsheetTooLargeError::class);
		$this->readFile($path);
	}

	public function testAHugeRunOfSpacesIsTooLarge(): void {
		$path = $this->odsWith('<table:table-row><table:table-cell office:value-type="string"><text:p>a<text:s text:c="2000000000"/>b</text:p></table:table-cell></table:table-row>');
		$this->expectException(SpreadsheetTooLargeError::class);
		$this->readFile($path);
	}

	public function testTheCellLimitCountsEveryParagraph(): void {
		// Three paragraphs each under the limit, a cell over it. Writing two
		// thousand of them (a 791-byte file, ods-spaces.ods) took gigabytes.
		$paragraph = '<text:p>x<text:s text:c="1000"/>x</text:p>';
		$path = $this->odsWith('<table:table-row><table:table-cell office:value-type="string">'
			. str_repeat($paragraph, intdiv(SheetBuilder::MAX_CELL_BYTES, 1002) + 1)
			. '</table:table-cell></table:table-row>');
		$this->expectException(SpreadsheetTooLargeError::class);
		$this->readFile($path);
	}

	public function testTheCellLimitCountsTheTextOfEveryParagraph(): void {
		$half = '<text:p>' . str_repeat('a', intdiv(SheetBuilder::MAX_CELL_BYTES, 2)) . '</text:p>';
		$path = $this->odsWith('<table:table-row><table:table-cell office:value-type="string">'
			. $half . $half . $half . '</table:table-cell></table:table-row>');
		$this->expectException(SpreadsheetTooLargeError::class);
		$this->readFile($path);
	}

	public function testACellOfExactlyTheLimitIsRead(): void {
		// Two paragraphs and the space joining them come to the limit exactly
		$first = str_repeat('a', SheetBuilder::MAX_CELL_BYTES - 2);
		$path = $this->odsWith('<table:table-row><table:table-cell office:value-type="string">'
			. '<text:p>' . $first . '</text:p><text:p>b</text:p></table:table-cell></table:table-row>');
		$this->assertSame([[$first . ' b']], $this->readFile($path)[0]['rows']);
	}

	public function testARunOfSpacesIsCapped(): void {
		// Each <text:s/> is a few bytes in the file; unbounded, a run of a
		// million spaces in every cell is a lot of work for nothing
		$path = $this->odsWith('<table:table-row><table:table-cell office:value-type="string"><text:p>a<text:s text:c="1000"/>b</text:p></table:table-cell></table:table-row>');
		$this->assertSame([['a' . str_repeat(' ', 1000) . 'b']], $this->readFile($path)[0]['rows']);

		$path = $this->odsWith('<table:table-row><table:table-cell office:value-type="string"><text:p>a<text:s text:c="1001"/>b</text:p></table:table-cell></table:table-row>');
		$this->expectException(SpreadsheetTooLargeError::class);
		$this->readFile($path);
	}

	public function testRowAndColumnRepeatsPastAnySheet(): void {
		// Used to overflow into a float and end in a TypeError (a 500)
		$path = $this->odsWith(
			'<table:table-row><table:table-cell table:number-columns-repeated="9223372036854775807"/>'
			. '<table:table-cell office:value-type="string"><text:p>past the last column</text:p></table:table-cell></table:table-row>'
			. '<table:table-row table:number-rows-repeated="9223372036854775807"><table:table-cell/></table:table-row>'
			. '<table:table-row table:number-rows-repeated="5"><table:table-cell/></table:table-row>'
		);
		$this->assertSame([], $this->readFile($path)[0]['rows']);

		$path = $this->odsWith(
			'<table:table-row table:number-rows-repeated="9223372036854775807"><table:table-cell/></table:table-row>'
			. '<table:table-row><table:table-cell office:value-type="string"><text:p>a</text:p></table:table-cell></table:table-row>'
		);
		$this->expectException(SpreadsheetReadError::class);
		$this->readFile($path);
	}

	public function testARepeatedFilledRowCannotBlowUp(): void {
		$path = $this->odsWith(
			'<table:table-row table:number-rows-repeated="1000000">'
			. '<table:table-cell table:number-columns-repeated="1000" office:value-type="string"><text:p>x</text:p></table:table-cell>'
			. '</table:table-row>'
		);
		$start = microtime(true);
		try {
			$this->readFile($path);
			$this->fail('A million filled rows were read');
		} catch (SpreadsheetTooLargeError) {
			$this->assertLessThan(5.0, microtime(true) - $start);
		}
	}

	public function testABrokenContentPartIsRefused(): void {
		$path = $this->odsWith(
			'<table:table-row><table:table-cell office:value-type="string"><text:p>kept</text:p></table:table-cell></table:table-row>'
			. '<table:table-row><table:table-cell office:value-type="string"><text:p>&undefined;</text:p></table:table-cell></table:table-row>'
			. '<table:table-row><table:table-cell office:value-type="string"><text:p>lost</text:p></table:table-cell></table:table-row>'
		);
		$this->expectException(SpreadsheetReadError::class);
		$this->readFile($path);
	}

	/**
	 * Read $path, which must be refused as too large, in under a second and
	 * without holding more than a few megabytes along the way
	 */
	private function assertRefusedQuicklyAndSmall(string $path): void {
		$reset = function_exists('memory_reset_peak_usage');
		if ($reset) {
			memory_reset_peak_usage();
		}
		$before = memory_get_usage();
		$start = microtime(true);
		try {
			$this->readFile($path);
			$this->fail(basename($path) . ' was read');
		} catch (SpreadsheetTooLargeError) {
			$this->assertLessThan(1.0, microtime(true) - $start);
			if ($reset) {
				// The workbook's text budget is 32 MB; nothing past it may be held
				$this->assertLessThan(48 * 1024 * 1024, memory_get_peak_usage() - $before);
			}
		}
	}

	public function testARowOfLargeCellsIsRefusedBeforeItIsHeld(): void {
		// 400 cells of a megabyte each from 24 KB of file: each cell was kept
		// for its row and only counted at the row's end, by which time the
		// row held 400 MB and memory_limit had taken the process down
		$this->assertRefusedQuicklyAndSmall(self::FIXTURES . 'ods-big-cells.ods');
	}

	public function testLargeCellsInRowsOutsideAnyTableAreNeverRead(): void {
		$big = '<table:table-cell office:value-type="string"><text:p>a' . str_repeat('<text:s text:c="1000"/>', 1047) . 'b</text:p></table:table-cell>';
		$path = $this->odsOf(
			'<table:table table:name="Movimenti"><table:table-row><table:table-cell office:value-type="string"><text:p>kept</text:p></table:table-cell></table:table-row></table:table>'
			. '<table:table-row>' . str_repeat($big, 400) . '</table:table-row>'
			. str_repeat($big, 400)
		);
		$start = microtime(true);
		$this->assertSame([['kept']], $this->readFile($path)[0]['rows']);
		$this->assertLessThan(1.0, microtime(true) - $start);
	}

	public function testAHiddenSheetsCellsAreNeverRead(): void {
		// The hidden sheet's 400 cells of a megabyte each are stepped over
		// unread, and the statement after it is read
		$reset = function_exists('memory_reset_peak_usage');
		if ($reset) {
			memory_reset_peak_usage();
		}
		$before = memory_get_usage();
		$start = microtime(true);
		$sheets = $this->readFile(self::FIXTURES . 'ods-big-cells-hidden.ods');
		$this->assertLessThan(1.0, microtime(true) - $start);
		if ($reset) {
			// content.xml itself is 10 MB; its cells would be 400 MB
			$this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before);
		}
		$this->assertSame(['Statement'], array_column($sheets, 'name'));
		$this->assertSame([['Date', 'Description', 'Amount'], ['01/10/2026', 'Coffee', '-3.50'], ['02/10/2026', 'Salary', '2000.00']], $sheets[0]['rows']);
	}

	public function testALargeCellRepeatedAcrossColumnsAndRowsIsRefusedQuickly(): void {
		$path = $this->odsWith(
			'<table:table-row table:number-rows-repeated="1000000">'
			. '<table:table-cell table:number-columns-repeated="1000" office:value-type="string"><text:p>a'
			. str_repeat('<text:s text:c="1000"/>', 1047) . 'b</text:p></table:table-cell></table:table-row>'
		);
		$this->assertRefusedQuicklyAndSmall($path);
	}

	public function testTheMillionRowPaddingIsSkippedQuickly(): void {
		$path = $this->odsWith(
			'<table:table-row><table:table-cell office:value-type="string"><text:p>a</text:p></table:table-cell>'
			. '<table:table-cell table:number-columns-repeated="16383"/></table:table-row>'
			. '<table:table-row table:number-rows-repeated="1048575"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>'
		);
		$start = microtime(true);
		$this->assertSame([['a']], $this->readFile($path)[0]['rows']);
		$this->assertLessThan(2.0, microtime(true) - $start);
	}
}
