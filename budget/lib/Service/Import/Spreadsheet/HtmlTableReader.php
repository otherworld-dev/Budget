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
		foreach ($document->getElementsByTagName('table') as $index => $table) {
			$builder = new SheetBuilder('Table ' . ($index + 1));
			$row = 0;
			foreach ($table->getElementsByTagName('tr') as $tr) {
				$column = 0;
				foreach ($tr->childNodes as $cell) {
					if (!$cell instanceof \DOMElement || !in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
						continue;
					}
					$builder->add($row, $column, trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $cell->textContent) ?? ''));
					$column += max(1, min((int)$cell->getAttribute('colspan'), SheetBuilder::MAX_COLUMNS));
				}
				$row++;
			}
			$sheets[] = $builder->build();
		}
		return $sheets;
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
