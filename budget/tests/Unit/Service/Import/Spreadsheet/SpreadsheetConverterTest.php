<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Exception\ValidationException;
use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetConverter;
use OCP\IL10N;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

class SpreadsheetConverterTest extends TestCase {
	private const FIXTURES = __DIR__ . '/../../../../fixtures/spreadsheets/';

	public const STATEMENT_CSV = "Data,Valuta,Descrizione,Importo,Note\n"
		. "2026-10-01,2026-10-03,\"Pagamento POS, Bar Centrale\",-1234.5,\n"
		. "2026-10-10,2026-10-10,Bonifico stipendio,2000,Ottobre\n"
		. "2026-10-12,2026-10-12,\"Caffè, cornetto\",-3.4,\"Più \"\"dolce\"\"\"\n";

	private const UNREADABLE = 'This spreadsheet could not be read. If it is password-protected, remove the password, or save it as CSV and import that.';

	private SpreadsheetConverter $converter;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(static fn (): string => tempnam(sys_get_temp_dir(), 'conv'));
		$this->converter = new SpreadsheetConverter($l, $temp);
	}

	private function convert(string $fixture): string {
		return $this->converter->toCsv(file_get_contents(self::FIXTURES . $fixture));
	}

	public function testHandlesSpreadsheetNames(): void {
		$this->assertTrue(SpreadsheetConverter::handles('estratto.XLS'));
		$this->assertTrue(SpreadsheetConverter::handles('a.b.xlsx'));
		$this->assertTrue(SpreadsheetConverter::handles('export.ods'));
		$this->assertFalse(SpreadsheetConverter::handles('statement.csv'));
		$this->assertFalse(SpreadsheetConverter::handles('xls'));
	}

	public function testTheSameStatementInEveryFormatGivesTheSameCsv(): void {
		foreach (['statement.xlsx', 'statement.xls', 'statement.ods', 'statement-1904.xlsx', 'xlsx-named.xls', 'cover-first.xlsx'] as $fixture) {
			$this->assertSame(self::STATEMENT_CSV, $this->convert($fixture), $fixture);
		}
	}

	public function testAnHtmlTable(): void {
		$this->assertSame(
			"Data,Descrizione,Importo\n03/10/2026,Caffè Roma,\"-1.234,56\"\nTotale,,\"-1.234,56\"\n",
			$this->convert('html-cp1252.xls')
		);
	}

	public function testPlainTextIsLeftAsItIs(): void {
		$bytes = file_get_contents(self::FIXTURES . 'tab-separated.xls');
		$this->assertSame($bytes, $this->converter->toCsv($bytes));
	}

	public function testRefusals(): void {
		$zip = tempnam(sys_get_temp_dir(), 'zip');
		$archive = new \ZipArchive();
		$archive->open($zip, \ZipArchive::OVERWRITE);
		$archive->addFromString('hello.txt', 'hello');
		$archive->close();
		$notASpreadsheet = file_get_contents($zip);
		unlink($zip);

		$cases = [
			'encrypted' => file_get_contents(self::FIXTURES . 'encrypted.xlsx'),
			'filepass' => file_get_contents(self::FIXTURES . 'filepass.xls'),
			'biff5' => file_get_contents(self::FIXTURES . 'biff5.xls'),
			'zip of something else' => $notASpreadsheet,
			'binary' => "\x00\x01\x02garbage\x00",
			'truncated xlsx' => substr(file_get_contents(self::FIXTURES . 'statement.xlsx'), 0, 300),
			'Excel 2003 XML' => '<?xml version="1.0"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"></Workbook>',
		];
		foreach ($cases as $name => $bytes) {
			try {
				$this->converter->toCsv($bytes);
				$this->fail("$name was not refused");
			} catch (ValidationException $e) {
				$this->assertSame(self::UNREADABLE, $e->getMessage(), $name);
			}
		}
	}

	public function testNoTable(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('This spreadsheet has no table of transactions to import.');
		$this->converter->toCsv('<table><tr><td>Nessun movimento</td></tr></table>');
	}

	public function testTooManyRows(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('This spreadsheet is too big to import in one go.');
		$this->converter->toCsv('<table>' . str_repeat('<tr><td>1</td><td>2</td></tr>', 100001) . '</table>');
	}
}
