<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Service\Import\Spreadsheet\HtmlTableReader;
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

	public function testEveryTableIsASheet(): void {
		$sheets = (new HtmlTableReader())->read('<table><tr><td>a</td></tr></table><table><tr><td>b</td><td>c</td></tr></table>');
		$this->assertSame([[['a']], [['b', 'c']]], array_column($sheets, 'rows'));
	}
}
