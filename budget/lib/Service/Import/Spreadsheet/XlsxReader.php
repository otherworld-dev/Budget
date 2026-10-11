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
		foreach ($sheets as [$name, $relationshipId]) {
			$path = isset($targets[$relationshipId]) ? self::partPath($targets[$relationshipId]) : null;
			if ($path === null || !$zip->has($path)) {
				continue;
			}
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
				$sheets[] = [Xml::attr($reader, 'name') ?? '', Xml::attr($reader, 'id') ?? ''];
			}
		}
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
		$document = new \DOMDocument();
		$strings = [];
		$ok = $reader->read();
		while ($ok) {
			if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
				$node = $reader->expand($document);
				// Trimmed here, once: a cell then shares the string rather
				// than copying it, however many cells use it
				$strings[] = $node instanceof \DOMElement ? trim(self::text($node)) : '';
				$ok = $reader->next();
				continue;
			}
			$ok = $reader->read();
		}
		return $strings;
	}

	/** The text of a string item: its <t> elements, leaving out phonetic runs (<rPh>) */
	private static function text(\DOMElement $element): string {
		$text = '';
		foreach ($element->getElementsByTagNameNS('*', 't') as $t) {
			if ($t->parentNode?->localName !== 'rPh') {
				$text .= $t->textContent;
			}
		}
		return $text;
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
					$codes[(int)Xml::attr($reader, 'numFmtId')] = Xml::attr($reader, 'formatCode') ?? '';
					break;
				case 'cellXfs':
					$inCellXfs = !$reader->isEmptyElement;
					break;
				case 'xf':
					if ($inCellXfs) {
						$cellFormats[] = (int)(Xml::attr($reader, 'numFmtId') ?? 0);
					}
					break;
			}
		}
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
		$document = new \DOMDocument();
		$row = -1;
		$column = -1;
		$ok = $reader->read();
		while ($ok) {
			if ($reader->nodeType === \XMLReader::ELEMENT) {
				if ($reader->localName === 'row') {
					$number = Xml::attr($reader, 'r');
					$row = $number !== null && ctype_digit($number) ? (int)$number - 1 : $row + 1;
					$column = -1;
				} elseif ($reader->localName === 'c') {
					$reference = Xml::attr($reader, 'r');
					$column = $reference !== null && preg_match('/^([A-Z]{1,3})\d+$/i', $reference, $match) === 1
						? self::columnIndex($match[1])
						: $column + 1;
					$type = Xml::attr($reader, 't') ?? 'n';
					$style = (int)(Xml::attr($reader, 's') ?? 0);
					$node = $reader->isEmptyElement ? null : $reader->expand($document);
					if ($node instanceof \DOMElement) {
						$builder->add($row, $column, $this->cellText($node, $type, $style, $strings, $cellFormats, $formatCodes, $date1904));
					}
					$ok = $reader->next();
					continue;
				}
			}
			$ok = $reader->read();
		}
		return $builder->build();
	}

	/**
	 * @param list<string> $strings
	 * @param list<int> $cellFormats
	 * @param array<int, string> $formatCodes
	 */
	private function cellText(\DOMElement $cell, string $type, int $style, array $strings, array $cellFormats, array $formatCodes, bool $date1904): string {
		$value = null;
		foreach ($cell->childNodes as $child) {
			if ($child instanceof \DOMElement && $child->localName === 'v') {
				$value = $child->textContent;
				break;
			}
		}
		switch ($type) {
			case 's':
				return $value === null ? '' : ($strings[(int)$value] ?? '');
			case 'inlineStr':
				return trim(self::text($cell));
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
