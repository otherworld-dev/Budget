<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * Reads a binary Excel 97-2003 workbook (BIFF8): every visible worksheet,
 * cells as text. Excel 95 (BIFF5) and password-protected workbooks are
 * refused.
 */
final class XlsReader {
	private const BOF = 0x0809;
	private const EOF = 0x000A;
	private const BOUNDSHEET = 0x0085;
	private const FILEPASS = 0x002F;
	private const DATEMODE = 0x0022;
	private const FORMAT = 0x041E;
	private const XF = 0x00E0;
	private const SST = 0x00FC;
	private const CONTINUE = 0x003C;
	private const LABELSST = 0x00FD;
	private const LABEL = 0x0204;
	private const NUMBER = 0x0203;
	private const RK = 0x027E;
	private const MULRK = 0x00BD;
	private const BOOLERR = 0x0205;
	private const FORMULA = 0x0006;
	private const STRING = 0x0207;

	private bool $date1904 = false;
	/** @var array<int, string> */
	private array $formats = [];
	/** @var list<int> */
	private array $xfFormats = [];
	/** @var list<string> */
	private array $strings = [];

	/**
	 * @return list<array{name: string, rows: list<list<string>>}>
	 */
	public function read(OleFile $ole): array {
		if (!$ole->hasStream('Workbook')) {
			throw new SpreadsheetReadError($ole->hasStream('Book') ? 'Excel 95 workbook' : 'No workbook stream');
		}
		return $this->readWorkbook($ole->stream('Workbook'));
	}

	/**
	 * @return list<array{name: string, rows: list<list<string>>}>
	 */
	public function readWorkbook(string $stream): array {
		$records = $this->records($stream, 0);
		if (!$records->valid()) {
			throw new SpreadsheetReadError('Empty workbook stream');
		}
		[$id, $data] = $records->current();
		if ($id !== self::BOF || Bytes::u16($data, 0) !== 0x0600) {
			throw new SpreadsheetReadError('Not a BIFF8 workbook');
		}

		$sheets = [];
		$sst = null;
		for ($records->next(); $records->valid(); $records->next()) {
			[$id, $data] = $records->current();
			if ($sst !== null) {
				if ($id === self::CONTINUE) {
					$sst[] = $data;
					continue;
				}
				$this->strings = array_map(trim(...), BiffStringTable::parse($sst));
				$sst = null;
			}
			switch ($id) {
				case self::FILEPASS:
					throw new SpreadsheetReadError('Password-protected workbook');
				case self::DATEMODE:
					$this->date1904 = Bytes::u16($data, 0) === 1;
					break;
				case self::FORMAT:
					$this->formats[Bytes::u16($data, 0)] = $this->unicodeString($data, 2, 2);
					break;
				case self::XF:
					$this->xfFormats[] = Bytes::u16($data, 2);
					break;
				case self::BOUNDSHEET:
					// Worksheets only (not charts or macros), and only visible ones
					if (Bytes::u8($data, 5) === 0 && (Bytes::u8($data, 4) & 0x03) === 0) {
						$sheets[] = [$this->unicodeString($data, 6, 1), Bytes::u32($data, 0)];
					}
					break;
				case self::SST:
					$sst = [$data];
					break;
				case self::EOF:
					break 2;
			}
		}
		if ($sst !== null) {
			$this->strings = array_map(trim(...), BiffStringTable::parse($sst));
		}

		$result = [];
		$budget = new SheetBudget();
		foreach ($sheets as [$name, $offset]) {
			$result[] = $this->sheet($stream, $name, $offset, $budget);
		}
		return $result;
	}

	/**
	 * @return array{name: string, rows: list<list<string>>}
	 */
	private function sheet(string $stream, string $name, int $offset, SheetBudget $budget): array {
		$builder = new SheetBuilder($name, $budget);
		$records = $this->records($stream, $offset);
		if (!$records->valid() || $records->current()[0] !== self::BOF) {
			throw new SpreadsheetReadError('Sheet does not start with BOF');
		}
		$formulaCell = null;
		for ($records->next(); $records->valid(); $records->next()) {
			[$id, $data] = $records->current();
			switch ($id) {
				case self::LABELSST:
					$builder->add(Bytes::u16($data, 0), Bytes::u16($data, 2), $this->strings[Bytes::u32($data, 6)] ?? '');
					break;
				case self::LABEL:
					$builder->add(Bytes::u16($data, 0), Bytes::u16($data, 2), trim($this->unicodeString($data, 6, 2)));
					break;
				case self::NUMBER:
					$builder->add(Bytes::u16($data, 0), Bytes::u16($data, 2), $this->numberCell(Bytes::f64($data, 6), Bytes::u16($data, 4)));
					break;
				case self::RK:
					$builder->add(Bytes::u16($data, 0), Bytes::u16($data, 2), $this->numberCell(self::rk(Bytes::u32($data, 6)), Bytes::u16($data, 4)));
					break;
				case self::MULRK:
					$row = Bytes::u16($data, 0);
					$first = Bytes::u16($data, 2);
					for ($i = 0, $count = intdiv(strlen($data) - 6, 6); $i < $count; $i++) {
						$builder->add($row, $first + $i, $this->numberCell(self::rk(Bytes::u32($data, 6 + 6 * $i)), Bytes::u16($data, 4 + 6 * $i)));
					}
					break;
				case self::BOOLERR:
					if (Bytes::u8($data, 7) === 0) {
						$builder->add(Bytes::u16($data, 0), Bytes::u16($data, 2), Bytes::u8($data, 6) !== 0 ? 'TRUE' : 'FALSE');
					}
					break;
				case self::FORMULA:
					$row = Bytes::u16($data, 0);
					$column = Bytes::u16($data, 2);
					if (Bytes::u16($data, 12) === 0xFFFF) {
						// Not a number: byte 6 says what the cached result is
						$kind = Bytes::u8($data, 6);
						if ($kind === 0) {
							$formulaCell = [$row, $column]; // the text follows in a STRING record
						} elseif ($kind === 1) {
							$builder->add($row, $column, Bytes::u8($data, 8) !== 0 ? 'TRUE' : 'FALSE');
						}
					} else {
						$builder->add($row, $column, $this->numberCell(Bytes::f64($data, 6), Bytes::u16($data, 4)));
					}
					break;
				case self::STRING:
					if ($formulaCell !== null) {
						$builder->add($formulaCell[0], $formulaCell[1], trim($this->unicodeString($data, 0, 2)));
						$formulaCell = null;
					}
					break;
				case self::EOF:
					break 2;
			}
		}
		return $builder->build();
	}

	/**
	 * Records from $offset on, as [id, data].
	 *
	 * @return \Generator<int, array{int, string}>
	 */
	private function records(string $stream, int $offset): \Generator {
		$length = strlen($stream);
		while ($offset + 4 <= $length) {
			$id = Bytes::u16($stream, $offset);
			$size = Bytes::u16($stream, $offset + 2);
			if ($offset + 4 + $size > $length) {
				throw new SpreadsheetReadError('Truncated record');
			}
			yield [$id, substr($stream, $offset + 4, $size)];
			$offset += 4 + $size;
		}
	}

	private function numberCell(float $value, int $xf): string {
		$format = $this->xfFormats[$xf] ?? 0;
		return CellFormat::isDateFormat($format, $this->formats[$format] ?? null)
			? CellFormat::serialDateText($value, $this->date1904)
			: CellFormat::numberText($value);
	}

	/**
	 * An RK number: a 30-bit integer or the top 30 bits of a double, either
	 * one possibly divided by 100.
	 */
	private static function rk(int $rk): float {
		if (($rk & 0x02) !== 0) {
			$integer = $rk >> 2;
			if (($integer & 0x20000000) !== 0) {
				$integer -= 0x40000000;
			}
			$value = (float)$integer;
		} else {
			$value = Bytes::f64("\0\0\0\0" . pack('V', $rk & 0xFFFFFFFC), 0);
		}
		return ($rk & 0x01) !== 0 ? $value / 100 : $value;
	}

	/**
	 * A BIFF8 string: a character count (one or two bytes), a flags byte,
	 * then the characters, one byte each or UTF-16.
	 */
	private function unicodeString(string $data, int $offset, int $countBytes): string {
		$count = $countBytes === 1 ? Bytes::u8($data, $offset) : Bytes::u16($data, $offset);
		$wide = (Bytes::u8($data, $offset + $countBytes) & 0x01) !== 0;
		$start = $offset + $countBytes + 1;
		$length = $count * ($wide ? 2 : 1);
		if ($start + $length > strlen($data)) {
			throw new SpreadsheetReadError('String runs past its record');
		}
		$bytes = substr($data, $start, $length);
		return $wide ? Bytes::utf16($bytes) : Bytes::latin1($bytes);
	}
}
