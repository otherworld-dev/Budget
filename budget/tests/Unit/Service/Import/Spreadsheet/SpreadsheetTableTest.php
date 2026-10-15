<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetTable;
use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetTooLargeError;
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

	public function testALabelAndValuePreambleOverANarrowTable(): void {
		// Two filled cells are as wide as a three-column table less one
		$rows = [
			['Account', '12345678'],
			['Period', '01/01/2024 - 31/01/2024'],
			['Date', 'Description', 'Amount'],
			['02/01/2024', 'Coffee', '-3.50'],
			['03/01/2024', 'Salary', '2000.00'],
		];
		$this->assertSame(array_slice($rows, 2), SpreadsheetTable::extract($rows));
	}

	public function testALabelValueAndCurrencyPreambleOverFourColumns(): void {
		$rows = [
			['Account', '12345678', 'GBP'],
			['Date', 'Description', 'Paid out', 'Paid in'],
			['02/01/2024', 'Coffee', '3.50'],
			['03/01/2024', 'Salary', '', '2000.00'],
		];
		$this->assertSame([
			['Date', 'Description', 'Paid out', 'Paid in'],
			['02/01/2024', 'Coffee', '3.50', ''],
			['03/01/2024', 'Salary', '', '2000.00'],
		], SpreadsheetTable::extract($rows));
	}

	public function testAPreambleOfWordsOnlyOverANarrowTable(): void {
		$rows = [
			['Account holder', 'Mario Rossi'],
			['Date', 'Description', 'Amount'],
			['02/01/2024', 'Coffee', '-3.50'],
		];
		$this->assertSame(array_slice($rows, 1), SpreadsheetTable::extract($rows));
	}

	public function testAnUntitledLastColumnOverAShortFirstRow(): void {
		$rows = [
			['Statement'],
			['Date', 'Description', 'Amount'],
			['2026-10-01', 'Coffee', '-1.5'],
			['2026-10-02', 'Salary', '2000', 'x'],
		];
		$this->assertSame([
			['Date', 'Description', 'Amount', ''],
			['2026-10-01', 'Coffee', '-1.5', ''],
			['2026-10-02', 'Salary', '2000', 'x'],
		], SpreadsheetTable::extract($rows));
	}

	public function testATwoLineHeaderOverAnUntitledLastColumn(): void {
		// The header's first line is the header; its second is a row of the table
		$rows = [
			['Date', 'Description', 'Amount'],
			['', 'ref', 'EUR'],
			['2026-10-01', 'Coffee', '-1.5', 'x'],
		];
		$this->assertSame([
			['Date', 'Description', 'Amount', ''],
			['', 'ref', 'EUR', ''],
			['2026-10-01', 'Coffee', '-1.5', 'x'],
		], SpreadsheetTable::extract($rows));
	}

	public function testAHeaderWithANumberInATitleOverAnUntitledLastColumn(): void {
		$rows = [
			['Date', 'Description', 'Ref 1'],
			['2026-10-01', 'Coffee', 'A1', 'x'],
			['2026-10-02', 'Salary', 'B2'],
		];
		$this->assertSame([
			['Date', 'Description', 'Ref 1', ''],
			['2026-10-01', 'Coffee', 'A1', 'x'],
			['2026-10-02', 'Salary', 'B2', ''],
		], SpreadsheetTable::extract($rows));
	}

	public function testLabelAndValueLinesWithNumbersOverFourColumns(): void {
		$rows = [
			['Account', '12345678', 'GBP'],
			['Period', '01/01/2024', '31/01/2024'],
			['Date', 'Description', 'Paid out', 'Paid in'],
			['02/01/2024', 'Coffee', '3.50'],
		];
		$this->assertSame([
			['Date', 'Description', 'Paid out', 'Paid in'],
			['02/01/2024', 'Coffee', '3.50', ''],
		], SpreadsheetTable::extract($rows));
	}

	public function testAPreambleOfWordsOverAHeaderWithAnUntitledLastColumn(): void {
		$rows = [
			['Account holder', 'Mario Rossi', 'Current account'],
			['Period', '01/2024'],
			['Date', 'Description', 'Amount'],
			['02/01/2024', 'Coffee', '-3.50', 'x'],
		];
		$this->assertSame([
			['Date', 'Description', 'Amount', ''],
			['02/01/2024', 'Coffee', '-3.50', 'x'],
		], SpreadsheetTable::extract($rows));
	}

	public function testAPreambleOfWordsOverAFullHeader(): void {
		$rows = [
			['Account holder', 'Mario Rossi', 'Current account'],
			['Date', 'Description', 'Paid out', 'Paid in'],
			['02/01/2024', 'Coffee', '3.50'],
		];
		$this->assertSame([
			['Date', 'Description', 'Paid out', 'Paid in'],
			['02/01/2024', 'Coffee', '3.50', ''],
		], SpreadsheetTable::extract($rows));
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

	public function testATableTooBigOncePadded(): void {
		// A wide header over many narrow rows: padding every row to the
		// header's width would make 1,001 x 1,000 cells
		$rows = [array_fill(0, 1000, 'h')];
		for ($i = 0; $i < 1000; $i++) {
			$rows[] = ['2026-10-01', '1'];
		}
		$this->expectException(SpreadsheetTooLargeError::class);
		SpreadsheetTable::extract($rows);
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
