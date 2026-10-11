<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * The cells and text one workbook may grow into, shared by all its sheets:
 * limits per sheet alone would let a workbook of many sheets (or a page of
 * many tables) add up to any size.
 */
final class SheetBudget {
	private int $cells = 0;
	private int $textBytes = 0;

	/**
	 * @param int $cells Cells added, empty padding included
	 * @param int $textBytes Text added (negative when a cell's text is replaced by shorter text)
	 * @throws SpreadsheetTooLargeError
	 */
	public function spend(int $cells, int $textBytes): void {
		$this->cells += $cells;
		$this->textBytes += $textBytes;
		if ($this->cells > SheetBuilder::MAX_CELLS) {
			throw new SpreadsheetTooLargeError('Workbook has more than ' . SheetBuilder::MAX_CELLS . ' cells');
		}
		if ($this->textBytes > SheetBuilder::MAX_TEXT_BYTES) {
			throw new SpreadsheetTooLargeError('Workbook has more than ' . SheetBuilder::MAX_TEXT_BYTES . ' bytes of text');
		}
	}
}
