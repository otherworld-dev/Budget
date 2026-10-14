<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * Reads an OpenDocument spreadsheet (.ods): every visible table, cells as text.
 *
 * ODS pads a sheet with repeated empty cells and rows (a million rows is
 * normal), so an empty repeat only moves the position on and is never
 * written out.
 */
final class OdsReader {
	private const TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
	private const OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
	private const TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
	private const STYLE = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';

	/** The most spaces one <text:s/> may stand for, far beyond any statement */
	public const MAX_SPACE_RUN = 1000;

	/**
	 * @return list<array{name: string, rows: list<list<string>>}>
	 */
	public function read(ZipPackage $zip): array {
		$reader = Xml::reader($zip->read('content.xml'));
		$sheets = [];
		$budget = new SheetBudget();
		$builder = null;
		$row = 0;
		$rowRepeat = 1;
		$column = 0;
		/** @var array<int, string> $cells */
		$cells = [];
		// A hidden sheet is a table whose style says table:display="false";
		// the automatic styles come before the tables in content.xml
		/** @var array<string, true> $hiddenStyles */
		$hiddenStyles = [];
		$tableStyle = null;

		while ($reader->read()) {
			if ($reader->namespaceURI === self::STYLE && $reader->nodeType === \XMLReader::ELEMENT) {
				if ($reader->localName === 'style') {
					$tableStyle = $reader->getAttributeNs('family', self::STYLE) === 'table' ? $reader->getAttributeNs('name', self::STYLE) : null;
				} elseif ($reader->localName === 'table-properties' && $tableStyle !== null
					&& $reader->getAttributeNs('display', self::TABLE) === 'false') {
					if (count($hiddenStyles) >= SheetBuilder::MAX_STYLES) {
						throw new SpreadsheetTooLargeError('More than ' . SheetBuilder::MAX_STYLES . ' table styles');
					}
					$hiddenStyles[$tableStyle] = true;
				}
				continue;
			}
			if ($reader->namespaceURI !== self::TABLE) {
				continue;
			}
			$start = $reader->nodeType === \XMLReader::ELEMENT;
			$end = $reader->nodeType === \XMLReader::END_ELEMENT || ($start && $reader->isEmptyElement);
			switch ($reader->localName) {
				case 'table':
					if ($start) {
						if (count($sheets) >= SheetBuilder::MAX_SHEETS) {
							throw new SpreadsheetTooLargeError('More than ' . SheetBuilder::MAX_SHEETS . ' sheets');
						}
						$hidden = isset($hiddenStyles[$reader->getAttributeNs('style-name', self::TABLE) ?? '']);
						$builder = $hidden ? null : new SheetBuilder($reader->getAttributeNs('name', self::TABLE) ?? '', $budget);
						$row = 0;
					}
					if ($end && $builder !== null) {
						$sheets[] = $builder->build();
						$builder = null;
					}
					break;
				case 'table-row':
					if ($start) {
						$rowRepeat = self::repeat($reader, 'number-rows-repeated', SheetBuilder::MAX_ROW_INDEX + 1);
						$column = 0;
						$cells = [];
					}
					if ($end) {
						if ($builder !== null && $cells !== []) {
							// Identical filled rows repeated: written out, up to the limit
							for ($i = 0, $times = min($rowRepeat, SheetBuilder::MAX_ROWS + 1); $i < $times; $i++) {
								foreach ($cells as $index => $text) {
									$builder->add($row + $i, $index, $text);
								}
							}
						}
						// Past the last row of any sheet the position stops,
						// and a filled row there is refused by the builder
						$row = min($row + $rowRepeat, SheetBuilder::MAX_ROW_INDEX + 1);
					}
					break;
				case 'table-cell':
				case 'covered-table-cell':
					if ($start) {
						$repeat = self::repeat($reader, 'number-columns-repeated', SheetBuilder::MAX_COLUMNS);
						$text = $this->cellText($reader);
						if ($text !== '') {
							for ($i = 0; $i < $repeat && $column + $i < SheetBuilder::MAX_COLUMNS; $i++) {
								$cells[$column + $i] = $text;
							}
						}
						// Columns from MAX_COLUMNS on are never read
						$column = min($column + $repeat, SheetBuilder::MAX_COLUMNS);
					}
					break;
			}
		}
		Xml::complete($reader);
		return $sheets;
	}

	/** A repeat count, from one up to $max: past it, nothing more is read */
	private static function repeat(\XMLReader $reader, string $attribute, int $max): int {
		return max(1, min((int)($reader->getAttributeNs($attribute, self::TABLE) ?? 1), $max));
	}

	/**
	 * A cell's value as text, the reader left on its end tag. A typed cell
	 * (a number, a date) is written from its value; any other from its own
	 * paragraphs, each trimmed and joined with a space. Inside a paragraph
	 * a run of spaces (<text:s text:c="N"/>, as LibreOffice writes one) is
	 * N spaces, as the cell holds and as LibreOffice's CSV export writes it.
	 * A tab or a line break is a single space: LibreOffice would write the
	 * tab or the line break itself, but a line break in any cell becomes a
	 * space in the CSV (SpreadsheetTable::toCsv), and a tab is kept out of
	 * it so it can never sway how the CSV's delimiter is detected.
	 *
	 * The cell is read node by node (Xml::inside), never expanded, and
	 * MAX_CELL_BYTES holds for the whole cell, not each paragraph: a cell
	 * of thousands of paragraphs each just under it (a few hundred bytes of
	 * file) once took gigabytes. A run of more than MAX_SPACE_RUN spaces is
	 * refused, so a few bytes of file can never stand for a megabyte of work.
	 */
	private function cellText(\XMLReader $reader): string {
		$typed = $this->typedText($reader);
		$parts = [];
		$paragraph = '';
		// Bytes of the cell's finished paragraphs, and the space after them
		$used = 0;
		// Inside one of the cell's own paragraphs while non-zero, and inside
		// a comment (office:annotation, which has paragraphs of its own)
		$paragraphDepth = 0;
		$annotationDepth = 0;
		foreach (Xml::inside($reader) as $depth) {
			if ($typed !== null) {
				continue;
			}
			$type = $reader->nodeType;
			if ($annotationDepth !== 0) {
				if ($type === \XMLReader::END_ELEMENT && $depth === $annotationDepth) {
					$annotationDepth = 0;
				}
			} elseif ($paragraphDepth === 0) {
				if ($type === \XMLReader::ELEMENT && $depth === 1 && $reader->namespaceURI === self::TEXT && $reader->localName === 'p' && !$reader->isEmptyElement) {
					$paragraphDepth = 1;
					$paragraph = '';
				}
			} elseif ($type === \XMLReader::END_ELEMENT && $depth === $paragraphDepth) {
				$paragraphDepth = 0;
				$paragraph = trim($paragraph);
				if ($paragraph !== '') {
					$parts[] = $paragraph;
					$used += strlen($paragraph) + 1;
				}
			} elseif (Xml::isText($reader)) {
				Xml::append($paragraph, $reader->value, $used);
			} elseif ($type === \XMLReader::ELEMENT) {
				if ($reader->namespaceURI === self::OFFICE && $reader->localName === 'annotation') {
					$annotationDepth = $reader->isEmptyElement ? 0 : $depth;
				} elseif ($reader->namespaceURI === self::TEXT) {
					switch ($reader->localName) {
						case 's':
							$count = max(1, (int)($reader->getAttributeNs('c', self::TEXT) ?? 1));
							if ($count > self::MAX_SPACE_RUN) {
								throw new SpreadsheetTooLargeError('A run of more than ' . self::MAX_SPACE_RUN . ' spaces');
							}
							Xml::append($paragraph, str_repeat(' ', $count), $used);
							break;
						case 'tab':
						case 'line-break':
							Xml::append($paragraph, ' ', $used);
							break;
					}
				}
			}
		}
		return $typed ?? implode(' ', $parts);
	}

	/** The text of a typed cell, from its value; null for a cell of text */
	private function typedText(\XMLReader $reader): ?string {
		switch ($reader->getAttributeNs('value-type', self::OFFICE)) {
			case 'float':
			case 'currency':
			case 'percentage':
				$value = $reader->getAttributeNs('value', self::OFFICE);
				return $value !== null && is_numeric($value) ? CellFormat::numberText((float)$value) : '';
			case 'date':
				return CellFormat::isoText($reader->getAttributeNs('date-value', self::OFFICE) ?? '');
			case 'time':
				$value = $reader->getAttributeNs('time-value', self::OFFICE) ?? '';
				return preg_match('/^PT(\d+)H(\d+)M(\d+)/', $value, $match) === 1
					? sprintf('%02d:%02d:%02d', (int)$match[1], (int)$match[2], (int)$match[3])
					: '';
			case 'boolean':
				return $reader->getAttributeNs('boolean-value', self::OFFICE) === 'true' ? 'TRUE' : 'FALSE';
		}
		return null;
	}
}
