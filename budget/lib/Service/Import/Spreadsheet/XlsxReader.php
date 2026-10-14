<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * Reads an Excel 2007+ workbook (.xlsx): every visible worksheet, cells as text.
 */
final class XlsxReader {
	/**
	 * @return list<array{name: string, rows: list<list<string>>}>
	 */
	public function read(ZipPackage $zip): array {
		[$sheets, $date1904] = $this->workbook($zip->read('xl/workbook.xml'));
		$targets = $zip->has('xl/_rels/workbook.xml.rels')
			? $this->relationships($zip->read('xl/_rels/workbook.xml.rels'))
			: [];
		$strings = $zip->has('xl/sharedStrings.xml') ? $this->sharedStrings($zip->read('xl/sharedStrings.xml')) : [];
		[$cellFormats, $formatCodes] = $zip->has('xl/styles.xml') ? $this->styles($zip->read('xl/styles.xml')) : [[], []];

		$result = [];
		$budget = new SheetBudget();
		$read = [];
		foreach ($sheets as [$name, $relationshipId]) {
			$path = isset($targets[$relationshipId]) ? self::partPath($targets[$relationshipId]) : null;
			// Each sheet part is read once, however many entries point at it
			if ($path === null || isset($read[$path]) || !$zip->has($path)) {
				continue;
			}
			$read[$path] = true;
			$result[] = $this->sheet($name, $zip->read($path), $strings, $cellFormats, $formatCodes, $date1904, $budget);
		}
		return $result;
	}

	/**
	 * Visible sheets in workbook order, and whether dates count from 1904.
	 *
	 * @return array{list<array{string, string}>, bool}
	 */
	private function workbook(string $xml): array {
		$reader = Xml::reader($xml);
		$sheets = [];
		$date1904 = false;
		while ($reader->read()) {
			if ($reader->nodeType !== \XMLReader::ELEMENT) {
				continue;
			}
			if ($reader->localName === 'workbookPr') {
				$date1904 = in_array(Xml::attr($reader, 'date1904'), ['1', 'true'], true);
			} elseif ($reader->localName === 'sheet') {
				$state = Xml::attr($reader, 'state');
				if ($state === 'hidden' || $state === 'veryHidden') {
					continue;
				}
				if (count($sheets) >= SheetBuilder::MAX_SHEETS) {
					throw new SpreadsheetTooLargeError('More than ' . SheetBuilder::MAX_SHEETS . ' sheets');
				}
				$sheets[] = [Xml::attr($reader, 'name') ?? '', Xml::attr($reader, 'id') ?? ''];
			}
		}
		Xml::complete($reader);
		return [$sheets, $date1904];
	}

	/**
	 * @return array<string, string> Relationship id => target
	 */
	private function relationships(string $xml): array {
		$reader = Xml::reader($xml);
		$targets = [];
		while ($reader->read()) {
			if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'Relationship'
				&& Xml::attr($reader, 'TargetMode') !== 'External') {
				$targets[Xml::attr($reader, 'Id') ?? ''] = Xml::attr($reader, 'Target') ?? '';
			}
		}
		Xml::complete($reader);
		return $targets;
	}

	/** A relationship target as a path inside the zip */
	private static function partPath(string $target): string {
		$path = str_starts_with($target, '/') ? substr($target, 1) : 'xl/' . $target;
		$parts = [];
		foreach (explode('/', $path) as $part) {
			if ($part === '..') {
				array_pop($parts);
			} elseif ($part !== '.' && $part !== '') {
				$parts[] = $part;
			}
		}
		return implode('/', $parts);
	}

	/**
	 * @return list<string>
	 */
	private function sharedStrings(string $xml): array {
		$reader = Xml::reader($xml);
		$strings = [];
		while ($reader->read()) {
			if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
				if (count($strings) >= SheetBuilder::MAX_CELLS) {
					throw new SpreadsheetTooLargeError('More than ' . SheetBuilder::MAX_CELLS . ' shared strings');
				}
				// Trimmed here, once: a cell then shares the string rather
				// than copying it, however many cells use it
				$strings[] = trim(self::content($reader)[1]);
			}
		}
		Xml::complete($reader);
		return $strings;
	}

	/**
	 * What a string item (<si>) or a cell (<c>) holds, read node by node:
	 * the text of its first <v> child, if it has one, and the text of its
	 * <t> elements (a rich-text string has one per run) leaving out
	 * phonetic runs (<rPh>), with Excel's _xHHHH_ escapes decoded. Excel
	 * writes a line break in a cell as _x000D_, and a literal "_x" as
	 * _x005F_x; replacing left to right without going back over a
	 * replacement handles both.
	 *
	 * The reader is left on the element's end tag.
	 *
	 * @return array{?string, string}
	 * @throws SpreadsheetTooLargeError
	 */
	private static function content(\XMLReader $reader): array {
		$value = '';
		$hasValue = false;
		$text = '';
		// The element being collected ('v' or 't') and the depth it ends at
		$collecting = null;
		$collectingDepth = 0;
		// Inside a phonetic run while non-zero: the depth it ends at
		$phoneticDepth = 0;
		foreach (Xml::inside($reader) as $depth) {
			$end = $reader->nodeType === \XMLReader::END_ELEMENT;
			if ($phoneticDepth !== 0) {
				if ($end && $depth === $phoneticDepth) {
					$phoneticDepth = 0;
				}
			} elseif ($collecting !== null) {
				if ($end && $depth === $collectingDepth) {
					$collecting = null;
				} elseif (Xml::isText($reader)) {
					if ($collecting === 'v') {
						Xml::append($value, $reader->value);
					} else {
						Xml::append($text, $reader->value);
					}
				}
			} elseif ($reader->nodeType === \XMLReader::ELEMENT) {
				$empty = $reader->isEmptyElement;
				switch ($reader->localName) {
					case 'rPh':
						$phoneticDepth = $empty ? 0 : $depth;
						break;
					case 't':
						if (!$empty) {
							[$collecting, $collectingDepth] = ['t', $depth];
						}
						break;
					case 'v':
						if ($depth === 1 && !$hasValue) {
							$hasValue = true;
							if (!$empty) {
								[$collecting, $collectingDepth] = ['v', $depth];
							}
						}
						break;
				}
			}
		}
		$text = preg_replace_callback(
			'/_x([0-9A-Fa-f]{4})_/',
			static fn (array $match): string => mb_chr((int)hexdec($match[1]), 'UTF-8') ?: '',
			$text
		) ?? $text;
		return [$hasValue ? $value : null, $text];
	}

	/**
	 * Each cell style's number format id, and the workbook's own format codes.
	 *
	 * @return array{list<int>, array<int, string>}
	 */
	private function styles(string $xml): array {
		$reader = Xml::reader($xml);
		$cellFormats = [];
		$codes = [];
		$inCellXfs = false;
		while ($reader->read()) {
			if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'cellXfs') {
				$inCellXfs = false;
				continue;
			}
			if ($reader->nodeType !== \XMLReader::ELEMENT) {
				continue;
			}
			switch ($reader->localName) {
				case 'numFmt':
					if (count($codes) >= SheetBuilder::MAX_STYLES) {
						throw new SpreadsheetTooLargeError('More than ' . SheetBuilder::MAX_STYLES . ' number formats');
					}
					$codes[(int)Xml::attr($reader, 'numFmtId')] = Xml::attr($reader, 'formatCode') ?? '';
					break;
				case 'cellXfs':
					$inCellXfs = !$reader->isEmptyElement;
					break;
				case 'xf':
					if ($inCellXfs) {
						if (count($cellFormats) >= SheetBuilder::MAX_STYLES) {
							throw new SpreadsheetTooLargeError('More than ' . SheetBuilder::MAX_STYLES . ' cell styles');
						}
						$cellFormats[] = (int)(Xml::attr($reader, 'numFmtId') ?? 0);
					}
					break;
			}
		}
		Xml::complete($reader);
		return [$cellFormats, $codes];
	}

	/**
	 * @param list<string> $strings
	 * @param list<int> $cellFormats
	 * @param array<int, string> $formatCodes
	 * @return array{name: string, rows: list<list<string>>}
	 */
	private function sheet(string $name, string $xml, array $strings, array $cellFormats, array $formatCodes, bool $date1904, SheetBudget $budget): array {
		$builder = new SheetBuilder($name, $budget);
		$reader = Xml::reader($xml);
		$row = -1;
		$column = -1;
		while ($reader->read()) {
			if ($reader->nodeType !== \XMLReader::ELEMENT) {
				continue;
			}
			if ($reader->localName === 'row') {
				$number = Xml::attr($reader, 'r');
				if ($number === null || !ctype_digit($number)) {
					$row++;
				} elseif (strlen($number) > 9) {
					// Too long to be a row of any sheet, and past an int
					throw new SpreadsheetReadError('Row number ' . substr($number, 0, 20) . ' is past the end of any sheet');
				} else {
					$row = (int)$number - 1;
				}
				$column = -1;
			} elseif ($reader->localName === 'c') {
				$reference = Xml::attr($reader, 'r');
				$column = $reference !== null && preg_match('/^([A-Z]{1,3})\d+$/i', $reference, $match) === 1
					? self::columnIndex($match[1])
					: $column + 1;
				$type = Xml::attr($reader, 't') ?? 'n';
				$style = (int)(Xml::attr($reader, 's') ?? 0);
				[$value, $text] = self::content($reader);
				$builder->add($row, $column, $this->cellText($type, $style, $value, $text, $strings, $cellFormats, $formatCodes, $date1904));
			}
		}
		Xml::complete($reader);
		return $builder->build();
	}

	/**
	 * @param ?string $value The cell's <v>
	 * @param string $text The text of its <t> elements (an inline string)
	 * @param list<string> $strings
	 * @param list<int> $cellFormats
	 * @param array<int, string> $formatCodes
	 */
	private function cellText(string $type, int $style, ?string $value, string $text, array $strings, array $cellFormats, array $formatCodes, bool $date1904): string {
		switch ($type) {
			case 's':
				return $value === null ? '' : ($strings[(int)$value] ?? '');
			case 'inlineStr':
				return trim($text);
			case 'str':
				return trim($value ?? '');
			case 'b':
				return $value === null || $value === '' ? '' : ($value === '1' ? 'TRUE' : 'FALSE');
			case 'e':
				return '';
			case 'd':
				return CellFormat::isoText($value ?? '');
		}
		if ($value === null || !is_numeric($value)) {
			return trim($value ?? '');
		}
		$formatId = $cellFormats[$style] ?? 0;
		return CellFormat::isDateFormat($formatId, $formatCodes[$formatId] ?? null)
			? CellFormat::serialDateText((float)$value, $date1904)
			: CellFormat::numberText((float)$value);
	}

	/** "A" is 0, "Z" 25, "AA" 26 */
	private static function columnIndex(string $letters): int {
		$index = 0;
		foreach (str_split(strtoupper($letters)) as $letter) {
			$index = $index * 26 + (ord($letter) - 64);
		}
		return $index - 1;
	}
}
