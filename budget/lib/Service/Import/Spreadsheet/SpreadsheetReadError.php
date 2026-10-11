<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

/**
 * A spreadsheet that cannot be read: malformed, encrypted, or a format this
 * reader does not handle. The message is for the log; the user is shown a
 * translated one by SpreadsheetConverter.
 */
class SpreadsheetReadError extends \RuntimeException {
}
