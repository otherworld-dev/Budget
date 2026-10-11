<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * A sheet with more rows than an import takes (SheetBuilder::MAX_ROWS).
 */
class SpreadsheetTooLargeError extends SpreadsheetReadError {
}
