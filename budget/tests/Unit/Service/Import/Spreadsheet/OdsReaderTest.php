<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Service\Import\Spreadsheet\OdsReader;
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
		$path = tempnam(sys_get_temp_dir(), 'ods');
		$this->tempFiles[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
		$zip->addFromString('content.xml', '<office:document-content'
			. ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
			. ' xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0">'
			. '<office:body><office:spreadsheet><table:table table:name="Foglio1">' . $tableContent
			. '</table:table></office:spreadsheet></office:body></office:document-content>');
		$zip->close();
		return $path;
	}

	public function testReadsTheStatement(): void {
		$sheets = $this->readFile(self::FIXTURES . 'statement.ods');
		$this->assertSame('Movimenti', $sheets[0]['name']);
		$this->assertSame(XlsxReaderTest::STATEMENT_ROWS, $sheets[0]['rows']);
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
