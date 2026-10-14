<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Service\Import\Spreadsheet\HtmlTableReader;
use OCA\Budget\Service\Import\Spreadsheet\SheetBuilder;
use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetTooLargeError;
use PHPUnit\Framework\TestCase;

class HtmlTableReaderTest extends TestCase {
	private const FIXTURES = __DIR__ . '/../../../../fixtures/spreadsheets/';

	private const ROWS = [
		['Data', 'Descrizione', 'Importo'],
		['03/10/2026', 'Caffè Roma', '-1.234,56'],
		['Totale', '', '-1.234,56'],
	];

	public function testUtf8WithNbspAndColspan(): void {
		$rows = (new HtmlTableReader())->read(file_get_contents(self::FIXTURES . 'html-utf8.xls'))[0]['rows'];
		$this->assertSame(self::ROWS, $rows);
	}

	public function testWindows1252FromTheMetaTag(): void {
		$rows = (new HtmlTableReader())->read(file_get_contents(self::FIXTURES . 'html-cp1252.xls'))[0]['rows'];
		$this->assertSame(self::ROWS, $rows);
	}

	public function testWindows1252WithNoMetaTag(): void {
		$rows = (new HtmlTableReader())->read(file_get_contents(self::FIXTURES . 'html-nometa-cp1252.xls'))[0]['rows'];
		$this->assertSame(self::ROWS, $rows);
	}

	public function testEveryTableOfTwoRowsOrMoreIsASheet(): void {
		// A table of one row can never be a header over transactions
		$sheets = (new HtmlTableReader())->read('<table><tr><td>a</td></tr></table><table><tr><td>b</td><td>c</td></tr><tr><td>d</td></tr></table><table></table>');
		$this->assertSame(['Table 2'], array_column($sheets, 'name'));
		$this->assertSame([[['b', 'c'], ['d']]], array_column($sheets, 'rows'));
	}

	public function testTooManyTables(): void {
		$html = str_repeat('<table><tr><td>a</td></tr><tr><td>b</td></tr></table>', SheetBuilder::MAX_SHEETS + 1);
		$this->expectException(SpreadsheetTooLargeError::class);
		(new HtmlTableReader())->read($html);
	}

	public function testManyEmptyTablesAreSkippedQuickly(): void {
		$html = str_repeat('<table></table>', 100000) . '<table><tr><td>a</td></tr><tr><td>b</td></tr></table>';
		$begin = microtime(true);
		$this->assertSame([[['a'], ['b']]], array_column((new HtmlTableReader())->read($html), 'rows'));
		$this->assertLessThan(5.0, microtime(true) - $begin);
	}

	public function testACellSpanningRowsKeepsTheRowsBelowInTheirColumns(): void {
		// The spanned positions are left empty, as colspan leaves them and
		// as a spreadsheet saves a merged cell
		$html = '<table><tr><th>Date</th><th>Description</th><th>Amount</th></tr>'
			. '<tr><td rowspan="2">02/01/2024</td><td>Coffee</td><td>-3.50</td></tr>'
			. '<tr><td>Tea</td><td>-2.00</td></tr>'
			. '<tr><td>03/01/2024</td><td rowspan="3" colspan="2">Spans two columns, three rows</td></tr>'
			. '<tr><td>04/01/2024</td></tr>'
			. '<tr></tr>'
			. '<tr><td>05/01/2024</td><td>Salary</td><td>2000</td></tr></table>';
		$this->assertSame([
			['Date', 'Description', 'Amount'],
			['02/01/2024', 'Coffee', '-3.50'],
			['', 'Tea', '-2.00'],
			['03/01/2024', 'Spans two columns, three rows'],
			['04/01/2024'],
			['05/01/2024', 'Salary', '2000'],
		], (new HtmlTableReader())->read($html)[0]['rows']);
	}

	public function testAHugeRowspanCannotMultiplyWork(): void {
		// Each row below steps over a thousand spanned positions
		$html = '<table><tr>' . str_repeat('<td rowspan="65534">x</td>', 1000) . '</tr>'
			. str_repeat('<tr><td></td><td>y</td></tr>', 5000) . '</table>';
		$this->expectException(SpreadsheetTooLargeError::class);
		(new HtmlTableReader())->read($html);
	}
}
