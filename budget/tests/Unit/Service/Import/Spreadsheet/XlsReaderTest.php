<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Import\Spreadsheet;

use OCA\Budget\Service\Import\Spreadsheet\OleFile;
use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetReadError;
use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetTooLargeError;
use OCA\Budget\Service\Import\Spreadsheet\XlsReader;
use PHPUnit\Framework\TestCase;

class XlsReaderTest extends TestCase {
	private const FIXTURES = __DIR__ . '/../../../../fixtures/spreadsheets/';

	private static function record(int $id, string $data): string {
		return pack('vv', $id, strlen($data)) . $data;
	}

	private static function bof(int $type): string {
		return self::record(0x0809, pack('vvvvVV', 0x0600, $type, 0, 0, 0, 0));
	}

	private static function eof(): string {
		return self::record(0x000A, '');
	}

	/** A workbook stream of one visible sheet holding $cells */
	private static function workbook(array $globals, array $cells): string {
		$boundsheet = static fn (int $position): string => self::record(0x0085, pack('VCC', $position, 0, 0) . pack('CC', 9, 0) . 'Movimenti');
		$head = self::bof(0x0005) . implode('', $globals);
		$position = strlen($head) + strlen($boundsheet(0)) + strlen(self::eof());
		return $head . $boundsheet($position) . self::eof() . self::bof(0x0010) . implode('', $cells) . self::eof();
	}

	private static function compressed(string $latin1): string {
		return pack('vC', strlen($latin1), 0) . $latin1;
	}

	private static function xf(int $format): string {
		return self::record(0x00E0, pack('vv', 0, $format) . str_repeat("\0", 16));
	}

	/** @return list<list<string>> */
	private function rows(string $stream): array {
		return (new XlsReader())->readWorkbook($stream)[0]['rows'];
	}

	public function testReadsTheStatement(): void {
		$sheets = (new XlsReader())->read(new OleFile(file_get_contents(self::FIXTURES . 'statement.xls')));
		$this->assertSame('Movimenti', $sheets[0]['name']);
		$this->assertSame(XlsxReaderTest::STATEMENT_ROWS, $sheets[0]['rows']);
	}

	public function testReadsALargerWorkbook(): void {
		$rows = (new XlsReader())->read(new OleFile(file_get_contents(self::FIXTURES . 'statement-large.xls')))[0]['rows'];
		$this->assertCount(301, $rows);
		$this->assertSame(['2026-01-01', 'Movimento numero 1 con una descrizione abbastanza lunga', '-1.25'], $rows[1]);
		$this->assertSame(['2026-10-27', 'Movimento numero 300 con una descrizione abbastanza lunga', '-375'], $rows[300]);
	}

	public function testReadsXlwtWorkbook(): void {
		$rows = (new XlsReader())->read(new OleFile(file_get_contents(self::FIXTURES . 'xlwt.xls')))[0]['rows'];
		$this->assertCount(602, $rows);
		$this->assertSame(['Conto corrente'], $rows[0]);
		$this->assertSame(['Data', 'Descrizione', 'Importo'], $rows[1]);
		$this->assertSame(['2026-01-01', 'Operazione n. 1 - Caffè e più', '-0.5'], $rows[2]);
		$this->assertSame(['2026-08-23', 'Operazione n. 600 - Caffè e più', '-300'], $rows[601]);
	}

	public function testNumberRecords(): void {
		$rk = static fn (int $raw): string => pack('V', $raw);
		$stream = self::workbook([], [
			self::record(0x0203, pack('vvv', 0, 0, 0) . pack('e', -1234.5)),        // NUMBER
			self::record(0x027E, pack('vvv', 0, 1, 0) . $rk((25 << 2) | 0x02)),     // RK integer 25
			self::record(0x027E, pack('vvv', 0, 2, 0) . $rk((-150 << 2 & 0xFFFFFFFC) | 0x03)), // RK -1.50
			self::record(0x00BD, pack('vv', 1, 0) . pack('v', 0) . $rk((7 << 2) | 0x02) . pack('v', 0) . $rk((8 << 2) | 0x02) . pack('v', 1)), // MULRK
			self::record(0x0205, pack('vvv', 2, 0, 0) . pack('CC', 1, 0)),          // BOOLERR true
			self::record(0x0205, pack('vvv', 2, 1, 0) . pack('CC', 0x2A, 1)),       // BOOLERR #N/A
		]);
		$this->assertSame([['-1234.5', '25', '-1.5'], ['7', '8'], ['TRUE']], $this->rows($stream));
	}

	public function testDatesFromBuiltInAndCustomFormats(): void {
		$stream = self::workbook([
			self::record(0x041E, pack('v', 164) . self::compressed('dd/mm/yyyy')),
			self::xf(0), self::xf(14), self::xf(164),
		], [
			self::record(0x0203, pack('vvv', 0, 0, 1) . pack('e', 46296.0)),
			self::record(0x0203, pack('vvv', 0, 1, 2) . pack('e', 46296.0)),
			self::record(0x0203, pack('vvv', 0, 2, 0) . pack('e', 46296.0)),
		]);
		$this->assertSame([['2026-10-01', '2026-10-01', '46296']], $this->rows($stream));
	}

	public function testThe1904DateSystem(): void {
		$stream = self::workbook([self::record(0x0022, pack('v', 1)), self::xf(14)], [
			self::record(0x0203, pack('vvv', 0, 0, 0) . pack('e', 46296.0 - 1462)),
		]);
		$this->assertSame([['2026-10-01']], $this->rows($stream));
	}

	public function testSharedStringsSplitAcrossContinueRecords(): void {
		$sst = self::record(0x00FC, pack('VV', 3, 3) . pack('vC', 9, 0) . 'Pagam')
			// The characters carry on uncompressed, after their own flags byte
			. self::record(0x003C, "\x01" . mb_convert_encoding('ento', 'UTF-16LE', 'UTF-8')
				// A rich string: one formatting run after its characters
				. pack('vCv', 4, 0x08, 1) . 'Caff' . pack('vv', 0, 1)
				. pack('vC', 4, 1) . mb_convert_encoding('Più ', 'UTF-16LE', 'UTF-8'));
		$stream = self::workbook([$sst], [
			self::record(0x00FD, pack('vvvV', 0, 0, 0, 0)),
			self::record(0x00FD, pack('vvvV', 0, 1, 0, 1)),
			self::record(0x00FD, pack('vvvV', 0, 2, 0, 2)),
		]);
		$this->assertSame([['Pagamento', 'Caff', 'Più']], $this->rows($stream));
	}

	public function testFormulaResults(): void {
		$special = static fn (int $kind, int $value): string => pack('CCCCCC', $kind, 0, $value, 0, 0, 0) . "\xFF\xFF";
		$stream = self::workbook([], [
			self::record(0x0006, pack('vvv', 0, 0, 0) . pack('e', 12.5) . pack('vV', 0, 0)),
			self::record(0x0006, pack('vvv', 0, 1, 0) . $special(0, 0) . pack('vV', 0, 0)),
			self::record(0x0207, self::compressed('Saldo')),
			self::record(0x0006, pack('vvv', 0, 2, 0) . $special(1, 1) . pack('vV', 0, 0)),
			self::record(0x0006, pack('vvv', 0, 3, 0) . $special(3, 0) . pack('vV', 0, 0)),
			self::record(0x0204, pack('vvv', 0, 4, 0) . self::compressed(' Etichetta ')),
		]);
		$this->assertSame([['12.5', 'Saldo', 'TRUE', '', 'Etichetta']], $this->rows($stream));
	}

	public function testMulrkAfterTheFirstColumn(): void {
		// Excel writes MULRK for numbers side by side, such as debit, credit and balance
		$rk = static fn (int $value): string => pack('V', ($value << 2) | 0x02);
		$stream = self::workbook([], [
			self::record(0x0204, pack('vvv', 0, 0, 0) . self::compressed('Saldo')),
			self::record(0x00BD, pack('vv', 0, 2) . pack('v', 0) . $rk(7) . pack('v', 0) . $rk(8) . pack('v', 3)),
		]);
		$this->assertSame([['Saldo', '', '7', '8']], $this->rows($stream));
	}

	public function testASheetListedTwiceIsReadOnce(): void {
		$boundsheet = static fn (int $position, string $name): string => self::record(0x0085, pack('VCC', $position, 0, 0) . pack('CC', strlen($name), 0) . $name);
		$head = self::bof(0x0005);
		$position = strlen($head) + 2 * strlen($boundsheet(0, 'A')) + strlen(self::eof());
		$stream = $head . $boundsheet($position, 'A') . $boundsheet($position, 'B') . self::eof()
			. self::bof(0x0010) . self::record(0x0204, pack('vvv', 0, 0, 0) . self::compressed('x')) . self::eof();
		$this->assertSame(['A'], array_column((new XlsReader())->readWorkbook($stream), 'name'));
	}

	public function testTooManySheets(): void {
		$sheets = '';
		for ($i = 0; $i <= 256; $i++) {
			$sheets .= self::record(0x0085, pack('VCC', 1000 + $i, 0, 0) . pack('CC', 1, 0) . 'S');
		}
		$this->expectException(SpreadsheetTooLargeError::class);
		(new XlsReader())->readWorkbook(self::bof(0x0005) . $sheets . self::eof());
	}

	public function testTooManyCellStyles(): void {
		$styles = str_repeat(self::xf(0), 65537);
		$this->expectException(SpreadsheetTooLargeError::class);
		(new XlsReader())->readWorkbook(self::workbook([$styles], []));
	}

	public function testASharedStringTableClaimingTooManyStrings(): void {
		$sst = self::record(0x00FC, pack('VV', 1000001, 1000001) . self::compressed('a'));
		$this->expectException(SpreadsheetTooLargeError::class);
		(new XlsReader())->readWorkbook(self::workbook([$sst], []));
	}

	public function testTheLimitsCoverEverySheetOfAWorkbook(): void {
		// Two sheets of 600,000 cells: each is within the limit, the workbook is not
		$cells = '';
		for ($i = 0; $i < 600000; $i++) {
			$cells .= self::record(0x027E, pack('vvvV', intdiv($i, 10), $i % 10, 0, (1 << 2) | 0x02));
		}
		$sheet = self::bof(0x0010) . $cells . self::eof();
		$boundsheet = static fn (int $position, string $name): string => self::record(0x0085, pack('VCC', $position, 0, 0) . pack('CC', strlen($name), 0) . $name);
		$head = self::bof(0x0005);
		$position = strlen($head) + 2 * strlen($boundsheet(0, 'A')) + strlen(self::eof());
		$stream = $head . $boundsheet($position, 'A') . $boundsheet($position + strlen($sheet), 'B') . self::eof()
			. $sheet . $sheet;
		$this->expectException(SpreadsheetTooLargeError::class);
		(new XlsReader())->readWorkbook($stream);
	}

	public function testASheetEndsWhereTheNextOneStarts(): void {
		// Sheet A has no EOF record: its scan stops at B's BOF rather than taking B's cells as its own
		$boundsheet = static fn (int $position, string $name): string => self::record(0x0085, pack('VCC', $position, 0, 0) . pack('CC', strlen($name), 0) . $name);
		$label = static fn (string $text): string => self::record(0x0204, pack('vvv', 0, 0, 0) . self::compressed($text));
		$head = self::bof(0x0005);
		$a = self::bof(0x0010) . $label('a');
		$position = strlen($head) + 2 * strlen($boundsheet(0, 'A')) + strlen(self::eof());
		$stream = $head . $boundsheet($position, 'A') . $boundsheet($position + strlen($a), 'B') . self::eof()
			. $a . self::bof(0x0010) . $label('b') . self::eof();
		$sheets = (new XlsReader())->readWorkbook($stream);
		$this->assertSame([[['a']], [['b']]], array_column($sheets, 'rows'));
	}

	public function testManySheetsOverALongTailAreReadInOnePass(): void {
		// 256 sheets with no EOF used to each scan the whole rest of the stream
		$boundsheet = static fn (int $position): string => self::record(0x0085, pack('VCC', $position, 0, 0) . pack('CC', 1, 0) . 'S');
		$head = self::bof(0x0005);
		$start = strlen($head) + 256 * strlen($boundsheet(0)) + strlen(self::eof());
		$sheetBof = self::bof(0x0010);
		$stream = $head;
		for ($i = 0; $i < 256; $i++) {
			$stream .= $boundsheet($start + $i * strlen($sheetBof));
		}
		$stream .= self::eof() . str_repeat($sheetBof, 256) . str_repeat(self::record(0x0001, ''), 100000);
		$begin = microtime(true);
		$this->assertCount(256, (new XlsReader())->readWorkbook($stream));
		$this->assertLessThan(2.0, microtime(true) - $begin);
	}

	public function testRefusesAPasswordProtectedWorkbook(): void {
		$this->expectException(SpreadsheetReadError::class);
		(new XlsReader())->read(new OleFile(file_get_contents(self::FIXTURES . 'filepass.xls')));
	}

	public function testRefusesAnExcel95Workbook(): void {
		$this->expectException(SpreadsheetReadError::class);
		(new XlsReader())->read(new OleFile(file_get_contents(self::FIXTURES . 'biff5.xls')));
	}

	public function testRefusesATruncatedRecord(): void {
		$this->expectException(SpreadsheetReadError::class);
		(new XlsReader())->readWorkbook(substr(self::workbook([], [self::record(0x0203, pack('vvv', 0, 0, 0) . pack('e', 1.0))]), 0, -6));
	}
}
