<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * The cells and text one workbook may grow into, shared by all its sheets:
 * limits per sheet alone would let a workbook of many sheets (or a page of
 * many tables) add up to any size.
 *
 * Text is spent as each cell is read, before anything holds it, and a cell
 * being read may grow no further than what is left (textLeft()): a reader
 * that kept a row's cells and spent them at the row's end held a thousand
 * cells of a megabyte each before anything counted them.
 */
final class SheetBudget {
	private int $cells = 0;
	private int $textBytes = 0;

	/** The bytes of text the workbook may still add */
	public function textLeft(): int {
		return max(0, SheetBuilder::MAX_TEXT_BYTES - $this->textBytes);
	}

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
