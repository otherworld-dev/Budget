<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * Collects one sheet's non-empty cells and returns its rows as text.
 *
 * Only filled cells are kept, so a sheet whose formatting reaches far beyond
 * its data (a styled cell at XFD1048576, or ODS padding every sheet to a
 * million rows) costs nothing. Empty rows are left out, and each row ends at
 * its last filled cell.
 */
final class SheetBuilder {
	public const MAX_ROWS = 100000;

	/** Columns past this are never transaction data and are ignored */
	public const MAX_COLUMNS = 1000;

	/** @var array<int, array<int, string>> */
	private array $cells = [];

	public function __construct(
		private string $name,
	) {
	}

	/**
	 * @throws SpreadsheetTooLargeError When the sheet passes MAX_ROWS filled rows
	 */
	public function add(int $row, int $column, string $text): void {
		if ($text === '' || $row < 0 || $column < 0 || $column >= self::MAX_COLUMNS) {
			return;
		}
		if (!isset($this->cells[$row]) && count($this->cells) >= self::MAX_ROWS) {
			throw new SpreadsheetTooLargeError('Sheet has more than ' . self::MAX_ROWS . ' rows');
		}
		$this->cells[$row][$column] = $text;
	}

	/**
	 * @return array{name: string, rows: list<list<string>>}
	 */
	public function build(): array {
		ksort($this->cells);
		$rows = [];
		foreach ($this->cells as $cells) {
			$row = array_fill(0, max(array_keys($cells)) + 1, '');
			foreach ($cells as $column => $text) {
				$row[$column] = $text;
			}
			$rows[] = $row;
		}
		return ['name' => $this->name, 'rows' => $rows];
	}
}
