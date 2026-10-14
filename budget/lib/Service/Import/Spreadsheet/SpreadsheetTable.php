<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * The transaction table in a sheet, and the CSV it is written as.
 */
final class SpreadsheetTable {
	/**
	 * The header row and everything after it, each row padded to one width.
	 * Rows above the header (the bank's account holder, period and so on)
	 * are dropped.
	 *
	 * A spreadsheet row has no width the way a CSV line does, and the CSV
	 * parser leans on width: it takes the widest width that repeats as the
	 * table and skips lines of any other width as preamble. Written as they
	 * are, a data row with an empty last cell would come out narrower than
	 * the table and be dropped. So the table is as wide as the widest row,
	 * and starts at the first row with two or more cells filled that is
	 * either
	 *
	 * - that wide, or
	 * - one narrower (a header whose last column has no title) and at
	 *   least three wide, with data below it before any other header.
	 *
	 * Data here is a row at least as wide as such a header and holding a
	 * number. A header is a row of words only that is the table's full
	 * width, or one narrower with every cell filled; a row of words only
	 * with gaps (the second line of a two-line header) is neither, and is
	 * stepped over.
	 * A narrower row holding a number itself ("Ref 1" as a title) is a
	 * header only if no header follows it anywhere below.
	 *
	 * The second case is narrow on purpose. A "label | value" line above a
	 * narrow table (Account | 12345678 over Date | Description | Amount) is
	 * one narrower than the table too, and taking it for the header would
	 * name the columns after it; such a line is followed by the real
	 * header, before any data.
	 *
	 * The preamble is dropped rather than written narrow because the server
	 * picks the delimiter from the first line, and a preamble cell holding a
	 * semicolon would split the whole file on semicolons.
	 *
	 * @param list<list<string>> $rows
	 * @return list<list<string>> The table, or [] if there is none
	 */
	public static function extract(array $rows): array {
		$widths = array_map(static function (array $row): int {
			$width = count($row);
			while ($width > 0 && $row[$width - 1] === '') {
				$width--;
			}
			return $width;
		}, $rows);
		$tableWidth = $widths === [] ? 0 : max($widths);
		if ($tableWidth < 2) {
			return [];
		}

		$count = count($rows);
		$filled = [];
		$digit = [];
		foreach ($rows as $i => $row) {
			$filled[$i] = count(array_filter($row, static fn (string $cell): bool => $cell !== ''));
			$digit[$i] = preg_match('/\d/', implode('', $row)) === 1;
		}
		$narrow = $tableWidth - 1;
		// For each row, what the first row below it that settles a narrower
		// header is ('data' or 'header', null for none), and whether any
		// header is below it at all
		$settledBy = [];
		$headerBelow = [];
		$next = null;
		$anyHeader = false;
		for ($i = $count - 1; $i >= 0; $i--) {
			$settledBy[$i] = $next;
			$headerBelow[$i] = $anyHeader;
			if ($digit[$i]) {
				if ($widths[$i] >= $narrow) {
					$next = 'data';
				}
			} elseif ($filled[$i] >= 2 && ($widths[$i] === $tableWidth || ($widths[$i] === $narrow && $filled[$i] === $widths[$i]))) {
				$next = 'header';
				$anyHeader = true;
			}
		}

		$header = null;
		foreach ($rows as $i => $row) {
			if ($filled[$i] < 2) {
				continue;
			}
			if ($widths[$i] === $tableWidth
				|| ($widths[$i] === $narrow && $widths[$i] >= 3 && $settledBy[$i] === 'data' && (!$digit[$i] || !$headerBelow[$i]))) {
				$header = $i;
				break;
			}
		}
		if ($header === null) {
			return [];
		}

		$tableRows = [];
		for ($i = $header; $i < $count; $i++) {
			if ($widths[$i] !== 0) {
				$tableRows[] = $i;
			}
		}
		// Padding every row to the widest one can multiply the cells a sheet
		// held (one wide header over many narrow rows)
		if (count($tableRows) * $tableWidth > SheetBuilder::MAX_CELLS) {
			throw new SpreadsheetTooLargeError('Table has more than ' . SheetBuilder::MAX_CELLS . ' cells once padded');
		}

		$table = [];
		foreach ($tableRows as $i) {
			$table[] = array_pad(array_slice($rows[$i], 0, $tableWidth), $tableWidth, '');
		}
		return $table;
	}

	/**
	 * Rows as comma-separated text. A line break inside a cell (a wrapped
	 * description) becomes a space, so every row is one line.
	 *
	 * @param list<list<string>> $rows
	 */
	public static function toCsv(array $rows): string {
		$lines = [];
		foreach ($rows as $row) {
			$lines[] = implode(',', array_map(static function (string $cell): string {
				$cell = preg_replace('/\r\n|\r|\n/', ' ', $cell) ?? $cell;
				return strpbrk($cell, '",') !== false ? '"' . str_replace('"', '""', $cell) . '"' : $cell;
			}, $row));
		}
		return implode("\n", $lines) . "\n";
	}
}
