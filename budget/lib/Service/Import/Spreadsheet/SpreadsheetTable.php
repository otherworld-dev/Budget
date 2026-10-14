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
	 * - one narrower (a header whose last column has no title), at least
	 *   three wide, made of words only, with the row after it at least as
	 *   wide and holding a number (data, not another header).
	 *
	 * The second case is narrow on purpose. A "label | value" line above a
	 * narrow table (Account | 12345678 over Date | Description | Amount) is
	 * one narrower than the table too, and taking it for the header would
	 * name the columns after it; such a line has a number in it, or is
	 * followed by the real header, which has none.
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

		$hasDigit = static fn (array $row): bool => preg_match('/\d/', implode('', $row)) === 1;
		$header = null;
		foreach ($rows as $i => $row) {
			if (count(array_filter($row, static fn (string $cell): bool => $cell !== '')) < 2) {
				continue;
			}
			if ($widths[$i] === $tableWidth
				|| ($widths[$i] === $tableWidth - 1 && $widths[$i] >= 3 && !$hasDigit($row)
					&& isset($rows[$i + 1]) && $widths[$i + 1] >= $widths[$i] && $hasDigit($rows[$i + 1]))) {
				$header = $i;
				break;
			}
		}
		if ($header === null) {
			return [];
		}

		$tableRows = [];
		for ($i = $header, $count = count($rows); $i < $count; $i++) {
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
