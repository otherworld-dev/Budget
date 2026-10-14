<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * Reads the tables of a web page some banks save with an .xls name. Every
 * <table> is a sheet, and cells are kept exactly as written: an HTML table
 * has no types, so nothing is read as a number or a date here.
 */
final class HtmlTableReader {
	/**
	 * @return list<array{name: string, rows: list<list<string>>}>
	 */
	public function read(string $bytes): array {
		// Characters past ASCII as entities, so the page's own charset
		// declaration cannot make libxml decode the UTF-8 a second time
		$html = mb_encode_numericentity($this->toUtf8($bytes), [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
		$document = new \DOMDocument();
		if (!$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
			throw new SpreadsheetReadError('Unreadable HTML');
		}

		$sheets = [];
		$budget = new SheetBudget();
		$spanned = 0;
		foreach ($document->getElementsByTagName('table') as $index => $table) {
			// A table of one row can never be a header over transactions
			$rows = $table->getElementsByTagName('tr');
			if ($rows->length < 2) {
				continue;
			}
			if (count($sheets) >= SheetBuilder::MAX_SHEETS) {
				throw new SpreadsheetTooLargeError('More than ' . SheetBuilder::MAX_SHEETS . ' tables');
			}
			$builder = new SheetBuilder('Table ' . ($index + 1), $budget);
			// Column => the last row a cell above, spanning rows, covers it
			$coveredTo = [];
			// Column => [last row, text] for the first column of a cell
			// spanning rows that holds text
			$spanText = [];
			$row = 0;
			foreach ($rows as $tr) {
				$column = 0;
				foreach ($tr->childNodes as $cell) {
					if (!$cell instanceof \DOMElement || !in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
						continue;
					}
					// Positions a rowspan above covers are stepped over
					while ($column < SheetBuilder::MAX_COLUMNS && ($coveredTo[$column] ?? -1) >= $row) {
						self::spanned($spanned);
						if (isset($spanText[$column]) && $spanText[$column][0] >= $row) {
							$builder->add($row, $column, $spanText[$column][1]);
						}
						$column++;
					}
					if ($column >= SheetBuilder::MAX_COLUMNS) {
						break;
					}
					$text = self::cellText($cell, $budget->textLeft());
					$builder->add($row, $column, $text);
					$columns = max(1, min((int)$cell->getAttribute('colspan'), SheetBuilder::MAX_COLUMNS - $column));
					$rowspan = min((int)$cell->getAttribute('rowspan'), 65534);
					if ($rowspan > 1) {
						for ($i = 0; $i < $columns; $i++) {
							$coveredTo[$column + $i] = $row + $rowspan - 1;
						}
						if ($text !== '') {
							$spanText[$column] = [$row + $rowspan - 1, $text];
						} else {
							unset($spanText[$column]);
						}
					}
					$column += $columns;
				}
				// A cell spanning rows into the end of a row past its last cell
				foreach ($spanText as $spanColumn => [$lastRow, $text]) {
					if ($lastRow < $row) {
						unset($spanText[$spanColumn]);
					} elseif ($spanColumn >= $column && ($coveredTo[$spanColumn] ?? -1) >= $row) {
						self::spanned($spanned);
						$builder->add($row, $spanColumn, $text);
					}
				}
				$row++;
			}
			$sheets[] = $builder->build();
		}
		return $sheets;
	}

	/**
	 * A cell's text, each run of whitespace one space, refused past $limit
	 * (what is left of the page's text) before it is copied again.
	 *
	 * A cell holds the text of every table nested inside it, so each level
	 * of tables nested in each other's cells copies the text inside it once
	 * more. The one copy taken here is never more than the page's own text,
	 * which the page's bytes and its DOM already hold; measuring the text
	 * nodes first instead, without copying them, took ten times as long on
	 * a page of nested tables.
	 *
	 * @throws SpreadsheetTooLargeError
	 */
	private static function cellText(\DOMElement $cell, int $limit): string {
		$text = $cell->textContent;
		if (strlen($text) > $limit) {
			throw new SpreadsheetTooLargeError('Page has more than ' . SheetBuilder::MAX_TEXT_BYTES . ' bytes of text');
		}
		return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? '');
	}

	/**
	 * Count a position a cell above, spanning rows, covers: each is a cell
	 * of the table, and a page has at most MAX_CELLS of them.
	 *
	 * @throws SpreadsheetTooLargeError
	 */
	private static function spanned(int &$spanned): void {
		if (++$spanned > SheetBuilder::MAX_CELLS) {
			throw new SpreadsheetTooLargeError('Table has more than ' . SheetBuilder::MAX_CELLS . ' cells');
		}
	}

	/**
	 * The page as UTF-8: by its BOM, then its <meta> charset, then UTF-8 if
	 * the bytes are valid UTF-8, and Windows-1252 otherwise.
	 */
	private function toUtf8(string $bytes): string {
		if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
			return substr($bytes, 3);
		}
		if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([\w.:-]+)/i', substr($bytes, 0, 4096), $match) === 1) {
			try {
				return mb_convert_encoding($bytes, 'UTF-8', $match[1]);
			} catch (\ValueError) {
				// An encoding mbstring does not know: fall through to the guess
			}
		}
		return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
	}
}
