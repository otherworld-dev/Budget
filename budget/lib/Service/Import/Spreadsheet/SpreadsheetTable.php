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
	 * and starts at the first row nearly that wide with two or more cells
	 * filled, which allows for a header whose last column has no title.
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

		$header = null;
		foreach ($rows as $i => $row) {
			$filled = count(array_filter($row, static fn (string $cell): bool => $cell !== ''));
			if ($widths[$i] >= $tableWidth - 1 && $filled >= 2) {
				$header = $i;
				break;
			}
		}
		if ($header === null) {
			return [];
		}

		$table = [];
		for ($i = $header, $count = count($rows); $i < $count; $i++) {
			if ($widths[$i] === 0) {
				continue;
			}
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
