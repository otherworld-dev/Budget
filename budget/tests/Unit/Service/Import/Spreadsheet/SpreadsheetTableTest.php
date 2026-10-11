<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetTable;
use PHPUnit\Framework\TestCase;

class SpreadsheetTableTest extends TestCase {
	public function testDropsThePreambleAndPadsEveryRow(): void {
		$rows = [
			['Estratto conto'],
			['Intestatario', 'Mario Rossi'],
			['Data', 'Descrizione', 'Importo', 'Note'],
			['2026-10-01', 'Caffè', '-1.5'],
			[],
			['2026-10-02', 'Stipendio', '2000', 'Ottobre'],
		];
		$this->assertSame([
			['Data', 'Descrizione', 'Importo', 'Note'],
			['2026-10-01', 'Caffè', '-1.5', ''],
			['2026-10-02', 'Stipendio', '2000', 'Ottobre'],
		], SpreadsheetTable::extract($rows));
	}

	public function testTakesAHeaderWhoseLastColumnHasNoTitle(): void {
		$rows = [['Data', 'Descrizione', 'Importo'], ['2026-10-01', 'Caffè', '-1.5', 'x']];
		$this->assertSame(['Data', 'Descrizione', 'Importo', ''], SpreadsheetTable::extract($rows)[0]);
	}

	public function testATableThatStartsInColumnC(): void {
		$rows = [
			['', '', 'Movimenti'],
			['', '', 'Data', 'Importo'],
			['', '', '2026-10-01', '-1.5'],
		];
		$this->assertSame([
			['', '', 'Data', 'Importo'],
			['', '', '2026-10-01', '-1.5'],
		], SpreadsheetTable::extract($rows));
	}

	public function testNoTable(): void {
		$this->assertSame([], SpreadsheetTable::extract([['Nessun movimento']]));
		$this->assertSame([], SpreadsheetTable::extract([]));
	}

	public function testCsvQuotingAndLineBreaks(): void {
		$this->assertSame(
			"a,\"b, c\",\"say \"\"hi\"\"\",two lines\nx,,,\n",
			SpreadsheetTable::toCsv([['a', 'b, c', 'say "hi"', "two\r\nlines"], ['x', '', '', '']])
		);
	}
}
