<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetReadError;
use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetTooLargeError;
use OCA\Budget\Service\Import\Spreadsheet\XlsxReader;
use OCA\Budget\Service\Import\Spreadsheet\ZipPackage;
use PHPUnit\Framework\TestCase;

class XlsxReaderTest extends TestCase {
	private const FIXTURES = __DIR__ . '/../../../../fixtures/spreadsheets/';

	public const STATEMENT_ROWS = [
		['Conto; IT60X0542811101000000123456'],
		['Data', 'Valuta', 'Descrizione', 'Importo', 'Note'],
		['2026-10-01', '2026-10-03', 'Pagamento POS, Bar Centrale', '-1234.5'],
		['2026-10-10', '2026-10-10', 'Bonifico stipendio', '2000', 'Ottobre'],
		['2026-10-12', '2026-10-12', 'Caffè, cornetto', '-3.4', 'Più "dolce"'],
	];

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
			return (new XlsxReader())->read($zip);
		} finally {
			$zip->close();
		}
	}

	/** @param array<string, string> $parts */
	private function zipOf(array $parts): string {
		$path = tempnam(sys_get_temp_dir(), 'xlsx');
		$this->tempFiles[] = $path;
		$zip = new \ZipArchive();
		$zip->open($path, \ZipArchive::OVERWRITE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();
		return $path;
	}

	private const NS = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

	/** A minimal workbook of one sheet; $sheetData is the inside of <sheetData> */
	private function workbookWith(string $sheetData, string $sharedStrings = '', string $styles = ''): string {
		$parts = [
			'xl/workbook.xml' => '<workbook ' . self::NS . '><sheets><sheet name="Foglio1" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
			'xl/worksheets/sheet1.xml' => '<worksheet ' . self::NS . '><sheetData>' . $sheetData . '</sheetData></worksheet>',
		];
		if ($sharedStrings !== '') {
			$parts['xl/sharedStrings.xml'] = '<sst ' . self::NS . '>' . $sharedStrings . '</sst>';
		}
		if ($styles !== '') {
			$parts['xl/styles.xml'] = '<styleSheet ' . self::NS . '>' . $styles . '</styleSheet>';
		}
		return $this->zipOf($parts);
	}

	public function testReadsTheStatement(): void {
		$sheets = $this->readFile(self::FIXTURES . 'statement.xlsx');
		$this->assertSame('Movimenti', $sheets[0]['name']);
		$this->assertSame(self::STATEMENT_ROWS, $sheets[0]['rows']);
	}

	public function testReadsThe1904DateSystem(): void {
		$this->assertSame(self::STATEMENT_ROWS, $this->readFile(self::FIXTURES . 'statement-1904.xlsx')[0]['rows']);
	}

	public function testReadsEverySheetInOrder(): void {
		$sheets = $this->readFile(self::FIXTURES . 'cover-first.xlsx');
		$this->assertSame(['Riepilogo', 'Movimenti'], array_column($sheets, 'name'));
		$this->assertSame([['Riepilogo del mese']], $sheets[0]['rows']);
	}

	public function testCellTypesFromAnotherWriter(): void {
		$path = $this->workbookWith(
			'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="inlineStr"><is><t>Inline</t></is></c></row>'
			. '<row r="2"><c r="A2" s="1"><v>46296</v></c><c r="B2" t="d"><v>2026-10-03T00:00:00</v></c>'
			. '<c r="C2" t="b"><v>1</v></c><c r="D2" t="e"><v>#N/A</v></c><c r="E2" t="str"><f>A1</f><v> Formula </v></c>'
			. '<c r="F2" s="2"><v>1.234</v></c></row>',
			'<si><r><t>Rich </t></r><r><t>text</t></r><rPh><t>phonetic</t></rPh></si>',
			'<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts>'
			. '<cellStyleXfs count="1"><xf numFmtId="14"/></cellStyleXfs>'
			. '<cellXfs count="3"><xf numFmtId="0"/><xf numFmtId="164"/><xf numFmtId="4"/></cellXfs>'
		);
		$this->assertSame([
			['Rich text', '', 'Inline'],
			['2026-10-01', '2026-10-03', 'TRUE', '', 'Formula', '1.2340'],
		], $this->readFile($path)[0]['rows']);
	}

	public function testADateTypedAsTextStaysAsWritten(): void {
		$path = $this->workbookWith(
			'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="inlineStr"><is><t>-1.234,56</t></is></c></row>',
			'<si><t>03/10/2026</t></si>'
		);
		$this->assertSame([['03/10/2026', '-1.234,56']], $this->readFile($path)[0]['rows']);
	}

	public function testStyledEmptyCellsFarAwayCostNothing(): void {
		$path = $this->workbookWith(
			'<row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c><c r="XFD1" s="1"/></row>'
			. '<row r="1048576"><c r="XFD1048576" s="1"/></row>'
		);
		$this->assertSame([['a']], $this->readFile($path)[0]['rows']);
	}

	public function testHiddenSheetsAreSkipped(): void {
		$path = $this->zipOf([
			'xl/workbook.xml' => '<workbook ' . self::NS . '><sheets><sheet name="Lookup" sheetId="1" state="hidden" r:id="rId1"/><sheet name="Data" sheetId="2" r:id="rId2"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="/xl/worksheets/sheet2.xml"/></Relationships>',
			'xl/worksheets/sheet1.xml' => '<worksheet ' . self::NS . '><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>hidden</t></is></c></row></sheetData></worksheet>',
			'xl/worksheets/sheet2.xml' => '<worksheet ' . self::NS . '><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>shown</t></is></c></row></sheetData></worksheet>',
		]);
		$sheets = $this->readFile($path);
		$this->assertSame(['Data'], array_column($sheets, 'name'));
		$this->assertSame([['shown']], $sheets[0]['rows']);
	}

	public function testOneHugeSharedStringInManyCellsIsTooLarge(): void {
		$cells = '';
		for ($row = 1; $row <= 40; $row++) {
			$cells .= '<row r="' . $row . '"><c r="A' . $row . '" t="s"><v>0</v></c></row>';
		}
		$path = $this->workbookWith($cells, '<si><t>' . str_repeat('x', 1024 * 1024) . '</t></si>');
		$this->expectException(SpreadsheetTooLargeError::class);
		$this->readFile($path);
	}

	public function testADoctypeIsRefused(): void {
		$secret = tempnam(sys_get_temp_dir(), 'secret');
		$this->tempFiles[] = $secret;
		file_put_contents($secret, 'SECRET-TOKEN');
		$path = $this->workbookWith(
			'<row r="1"><c r="A1" t="s"><v>0</v></c></row>',
			'<si><t>&xxe;</t></si>'
		);
		// Put the DOCTYPE in front of the shared strings part
		$zip = new \ZipArchive();
		$zip->open($path);
		$xml = $zip->getFromName('xl/sharedStrings.xml');
		$zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><!DOCTYPE sst [<!ENTITY xxe SYSTEM "file://' . str_replace('\\', '/', $secret) . '">]>' . $xml);
		$zip->close();

		// Spreadsheet XML never has one, and refusing it rules out external
		// entities and entity expansion ("billion laughs") alike
		$this->expectException(SpreadsheetReadError::class);
		$this->readFile($path);
	}

	public function testADoctypeInAnotherEncodingIsRefusedToo(): void {
		// In UTF-16 the bytes of "<!DOCTYPE" are not the ASCII text a plain
		// search looks for, but libxml decodes them all the same
		$path = $this->workbookWith('<row r="1"><c r="A1" t="s"><v>0</v></c></row>');
		$xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE sst [<!ENTITY a "expanded">]>'
			. '<sst ' . self::NS . '><si><t>&a;</t></si></sst>';
		$zip = new \ZipArchive();
		$zip->open($path);
		$zip->addFromString('xl/sharedStrings.xml', "\xFF\xFE" . mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8'));
		$zip->close();

		$this->expectException(SpreadsheetReadError::class);
		$this->readFile($path);
	}

	public function testAZipThatIsNotAZip(): void {
		$path = tempnam(sys_get_temp_dir(), 'bad');
		$this->tempFiles[] = $path;
		file_put_contents($path, "PK\x03\x04garbage");
		$this->expectException(SpreadsheetReadError::class);
		new ZipPackage($path);
	}
}
