<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Spreadsheet;

use OCA\Budget\Exception\ValidationException;
use OCA\Budget\Service\Import\TransactionNormalizer;
use OCP\IL10N;
use OCP\ITempManager;

/**
 * Turns a spreadsheet statement (.xls, .xlsx, .ods) into the CSV the
 * importer reads (#424). The file is recognised by its contents, not its
 * name: banks name .xlsx files .xls, save web pages and plain text as .xls,
 * and a plain text file is handed back untouched so the CSV path's own
 * encoding and delimiter detection read it.
 */
class SpreadsheetConverter {
	public const EXTENSIONS = ['xls', 'xlsx', 'ods'];

	private const ODS_MIMETYPE = 'application/vnd.oasis.opendocument.spreadsheet';

	public function __construct(
		private IL10N $l,
		private ITempManager $tempManager,
	) {
	}

	public static function handles(string $fileName): bool {
		return in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), self::EXTENSIONS, true)
			&& str_contains($fileName, '.');
	}

	/**
	 * The sheet holding the statement, as CSV; or the bytes as they are
	 * when the file is really plain text.
	 *
	 * Of the sheets holding a table (a header and a row at least), the
	 * statement is taken to be the first whose table is three columns wide
	 * or more and has a column of dates; failing that, the first three
	 * columns wide or more; failing that, the first. A cover sheet of a few
	 * "label | value" lines is a table too, but a narrow one, and an
	 * overview of accounts (Account | IBAN | Balance) can be as wide as the
	 * statement but has no dates. How many rows a table has never counts:
	 * in a workbook of one sheet per account that would import whichever
	 * account was busiest that month, and a summary sheet after the
	 * statement could outnumber it.
	 *
	 * @throws ValidationException With a message for the user
	 */
	public function toCsv(string $bytes): string {
		$previous = libxml_use_internal_errors(true);
		try {
			$sheets = $this->readSheets($bytes);
			if ($sheets === null) {
				return $bytes;
			}
			$best = null;
			$wide = null;
			$first = null;
			$normalizer = new TransactionNormalizer();
			foreach ($sheets as $sheet) {
				$table = SpreadsheetTable::extract($sheet['rows']);
				if (count($table) < 2) {
					continue;
				}
				$first ??= $table;
				if (count($table[0]) < 3) {
					continue;
				}
				if (self::hasDateColumn($table, $normalizer)) {
					$best = $table;
					break;
				}
				$wide ??= $table;
			}
			$best ??= $wide ?? $first;
			if ($best !== null) {
				return SpreadsheetTable::toCsv($best);
			}
		} catch (SpreadsheetTooLargeError) {
			throw new ValidationException($this->l->t('This spreadsheet is too big to import in one go. Split it into smaller files and import them one at a time.'));
		} catch (SpreadsheetReadError|\Error $e) {
			// \Error too (a TypeError, a ValueError from mbstring): whatever a
			// malformed file trips in a reader, the user is told it could not
			// be read rather than shown a server error
			throw new ValidationException($this->l->t('This spreadsheet could not be read. If it is password-protected, remove the password, or save it as CSV and import that.'), 0, $e);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
		throw new ValidationException($this->l->t('This spreadsheet has no table of transactions to import.'));
	}

	/**
	 * Whether a column holds a date in at least half the table's rows below
	 * its header, by the importer's own date formats. A date cell of a
	 * spreadsheet is written as Y-m-d (CellFormat), which they read; a date
	 * written as text (or in an HTML table) is read as the import will read
	 * it. Half rather than most: a statement can have a line of its own for
	 * an opening and a closing balance, or a second line to a description.
	 *
	 * @param list<list<string>> $table
	 */
	private static function hasDateColumn(array $table, TransactionNormalizer $normalizer): bool {
		$rows = count($table) - 1;
		foreach (array_keys($table[0]) as $column) {
			$dates = 0;
			for ($i = 1; $i <= $rows; $i++) {
				if ($normalizer->isDate($table[$i][$column])) {
					if (2 * ++$dates >= $rows) {
						return true;
					}
				} elseif (2 * ($i - $dates) > $rows) {
					// Too few rows left for half to be dates
					break;
				}
			}
		}
		return false;
	}

	/**
	 * @return list<array{name: string, rows: list<list<string>>}>|null Null for plain text
	 */
	private function readSheets(string $bytes): ?array {
		if (str_starts_with($bytes, "PK\x03\x04")) {
			return $this->readZip($bytes);
		}
		if (str_starts_with($bytes, OleFile::SIGNATURE)) {
			$ole = new OleFile($bytes);
			if ($ole->hasStream('EncryptionInfo')) {
				throw new SpreadsheetReadError('Password-protected xlsx');
			}
			return (new XlsReader())->read($ole);
		}
		if (str_contains($bytes, "\0")) {
			throw new SpreadsheetReadError('Unknown binary file');
		}
		if (str_contains($bytes, 'urn:schemas-microsoft-com:office:spreadsheet')) {
			// Excel 2003 XML ("XML Spreadsheet"), which this does not read
			throw new SpreadsheetReadError('Excel 2003 XML workbook');
		}
		if (stripos($bytes, '<table') !== false) {
			return (new HtmlTableReader())->read($bytes);
		}
		return null;
	}

	/**
	 * @return list<array{name: string, rows: list<list<string>>}>
	 */
	private function readZip(string $bytes): array {
		$path = $this->tempManager->getTemporaryFile('.zip');
		if ($path === false || file_put_contents($path, $bytes) === false) {
			throw new SpreadsheetReadError('No temporary file');
		}
		try {
			$zip = new ZipPackage($path);
			try {
				if ($zip->has('xl/workbook.xml')) {
					return (new XlsxReader())->read($zip);
				}
				if ($zip->has('content.xml') && $zip->has('mimetype') && trim($zip->read('mimetype')) === self::ODS_MIMETYPE) {
					return (new OdsReader())->read($zip);
				}
				throw new SpreadsheetReadError('Zip file is not a spreadsheet');
			} finally {
				$zip->close();
			}
		} finally {
			if (file_exists($path)) {
				unlink($path);
			}
		}
	}
}
