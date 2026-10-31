<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

use OCA\Budget\Service\Parser\CamtParser;
use OCA\Budget\Service\Parser\OfxParser;
use OCA\Budget\Service\Parser\QifParser;

/**
 * Factory for creating file format parsers.
 */
class ParserFactory {
	private ?OfxParser $ofxParser = null;
	private ?QifParser $qifParser = null;
	private ?CamtParser $camtParser = null;

	/**
	 * Detect file format from filename.
	 */
	public function detectFormat(string $fileName): string {
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		return match ($extension) {
			'csv', 'txt', 'tsv' => 'csv',
			'ofx' => 'ofx',
			'qif' => 'qif',
			// ISO 20022 camt.053/052 statements are the only XML banks export (#350)
			'xml' => 'camt',
			default => 'csv',
		};
	}

	/**
	 * Get the OFX parser instance.
	 */
	public function getOfxParser(): OfxParser {
		if ($this->ofxParser === null) {
			$this->ofxParser = new OfxParser();
		}
		return $this->ofxParser;
	}

	/**
	 * Get the QIF parser instance.
	 */
	public function getQifParser(): QifParser {
		if ($this->qifParser === null) {
			$this->qifParser = new QifParser();
		}
		return $this->qifParser;
	}

	/**
	 * Get the camt.053/camt.052 parser instance.
	 */
	public function getCamtParser(): CamtParser {
		if ($this->camtParser === null) {
			$this->camtParser = new CamtParser();
		}
		return $this->camtParser;
	}

	/**
	 * Parse file content based on format.
	 *
	 * @param string $content File content
	 * @param string $format File format (csv, ofx, qif, camt)
	 * @param int|null $limit Maximum number of records to parse
	 * @param string $delimiter CSV delimiter (comma, semicolon, or tab)
	 * @return array Parsed data
	 */
	public function parse(string $content, string $format, ?int $limit = null, string $delimiter = ',', bool $skipFirstRow = true): array {
		return match ($format) {
			'csv' => $this->parseCsv($content, $limit, $delimiter, $skipFirstRow),
			'ofx' => $this->getOfxParser()->parseToTransactionList($content, $limit),
			'qif' => $this->getQifParser()->parseToTransactionList($content, $limit),
			'camt' => $this->getCamtParser()->parseToTransactionList($content, $limit),
			default => throw new \Exception('Unsupported format: ' . $format),
		};
	}

	/**
	 * Parse file content and return full structured data (for OFX/QIF with multiple accounts).
	 */
	public function parseFull(string $content, string $format): array {
		return match ($format) {
			'ofx' => $this->getOfxParser()->parse($content),
			'qif' => $this->getQifParser()->parse($content),
			'camt' => $this->getCamtParser()->parse($content),
			default => ['accounts' => [], 'transactions' => $this->parse($content, $format)],
		};
	}

	/**
	 * Parse CSV content.
	 *
	 * Uses two-pass detection to handle bank exports with metadata preamble rows
	 * before the actual column headers (e.g. Swiss bank CSVs with key-value pairs).
	 *
	 * @param string $content CSV file content
	 * @param int|null $limit Maximum number of records to parse
	 * @param string $delimiter CSV delimiter character
	 * @param bool $skipFirstRow Whether the first matching-width line is a header to discard
	 * @return array Parsed data, rows always keyed by plain 0-based column index
	 */
	private function parseCsv(string $content, ?int $limit = null, string $delimiter = ',', bool $skipFirstRow = true): array {
		$content = $this->stripBom($content);
		$lines = explode("\n", $content);
		$dataWidth = $this->detectDataWidth($lines, $delimiter);

		if ($dataWidth === 0) {
			return [];
		}

		$data = [];
		$droppedHeader = false;
		$count = 0;

		foreach (self::csvRows($lines, $delimiter, $dataWidth) as $row) {
			if ($skipFirstRow && !$droppedHeader) {
				$droppedHeader = true; // this line held the header text — discard it
				continue;
			}

			if ($limit !== null && $count >= $limit) {
				break;
			}

			$data[] = array_values(array_pad($row, $dataWidth, ''));
			$count++;
		}

		return $data;
	}

	/** Lines a quoted field may span before the record is given up on */
	private const MAX_FIELD_LINES = 50;

	/**
	 * The rows of a CSV that have the data width, in file order. Rows of any
	 * other width are metadata or preamble and are skipped.
	 *
	 * A line is a row, as it always has been, with one exception: a quoted
	 * field spanning lines (a multi-line note) broke its record into
	 * fragments of the wrong width, and the whole row was dropped without a
	 * word (R5-8). Those fragments are joined back into one record. Only
	 * lines that were dropped anyway are ever joined: a line that is a row
	 * on its own is never swallowed, so every row imported before reads
	 * exactly as it did, the trailing "\r" of a CRLF file included (it can
	 * feed the import id).
	 *
	 * The other exception is a row that leaves its empty trailing cells off.
	 * Some exporters write only up to the last filled cell, so with separate
	 * debit and credit columns every debit row came out a cell short and was
	 * dropped as preamble: a Fortuneo file imported its credits only (#462).
	 * A narrower row after the first full-width one is padded with empty
	 * cells instead. Preamble comes before that first row, so it is still
	 * skipped, and the padded cells are empty, so no row that imported
	 * before reads any differently.
	 *
	 * @param string[] $lines The content split on "\n"
	 * @return \Generator<int, list<?string>, mixed, void> Rows exactly $dataWidth wide
	 */
	public static function csvRows(array $lines, string $delimiter, int $dataWidth): \Generator {
		$total = count($lines);
		$seenFullRow = false;
		// A row read on its own line left a quoted field open (its last
		// cell spans lines): the lines up to the closing quote are the rest
		// of that cell, never rows short of trailing cells
		$openField = false;
		for ($i = 0; $i < $total; $i++) {
			$line = $lines[$i];
			if (empty(trim($line))) {
				continue;
			}

			$oddQuotes = substr_count($line, '"') % 2 === 1;
			$insideField = $openField;
			if ($insideField && $oddQuotes) {
				$openField = false;
			}

			$row = str_getcsv($line, $delimiter, '"', '');
			if (count($row) === $dataWidth) {
				$seenFullRow = true;
				if ($oddQuotes && !$insideField) {
					$openField = true;
				}
				yield $row;
				continue;
			}

			// Balanced quotes: not a field left open, just a line of another width
			if (!$oddQuotes) {
				if ($seenFullRow && !$insideField && count($row) >= 2 && count($row) < $dataWidth) {
					yield array_pad($row, $dataWidth, '');
				}
				continue;
			}
			$record = $line;
			for ($j = $i + 1; $j < $total && $j <= $i + self::MAX_FIELD_LINES; $j++) {
				$next = $lines[$j];
				if (trim($next) !== '' && count(str_getcsv($next, $delimiter, '"', '')) === $dataWidth) {
					break;
				}
				$record .= "\n" . $next;
				if (substr_count($record, '"') % 2 === 0) {
					$joined = str_getcsv($record, $delimiter, '"', '');
					if (count($joined) === $dataWidth) {
						$seenFullRow = true;
						yield $joined;
						$i = $j;
					}
					break;
				}
			}
		}
	}

	/**
	 * Parse CSV content into raw records, header row included.
	 *
	 * Unlike parseCsv() this reads real CSV records rather than lines, so a
	 * quoted field that spans several lines (a multi-line note) stays in its
	 * row instead of breaking it into fragments that get dropped. Used by the
	 * header-mapped app-export presets, which read columns by name.
	 *
	 * @param string $content CSV file content (UTF-8)
	 * @param string $delimiter CSV delimiter character
	 * @return array<int, array<int, string>> Records in file order, blank lines skipped
	 */
	public function parseCsvRecords(string $content, string $delimiter = ','): array {
		$content = $this->stripBom($content);
		$stream = fopen('php://temp', 'r+');
		if ($stream === false) {
			return [];
		}
		fwrite($stream, $content);
		rewind($stream);

		$records = [];
		while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
			// A blank line comes back as [null]
			if ($record === [null]) {
				continue;
			}
			$records[] = array_map(static fn ($cell) => (string)$cell, $record);
		}
		fclose($stream);

		return $records;
	}

	/**
	 * Strip UTF-8 BOM from content.
	 */
	public function stripBom(string $content): string {
		if (str_starts_with($content, "\xEF\xBB\xBF")) {
			return substr($content, 3);
		}
		return $content;
	}

	/**
	 * Detect the column width of actual data rows in a CSV.
	 *
	 * Picks the highest column count that appears at least twice (the header row
	 * plus at least one data row). This correctly skips metadata preamble rows
	 * even when they outnumber data rows (e.g. Swiss bank exports with 5 metadata
	 * rows and 2 data rows). Falls back to the most frequent count if no count
	 * appears twice.
	 */
	public function detectDataWidth(array $lines, string $delimiter): int {
		$columnCounts = [];

		foreach ($lines as $line) {
			if (empty(trim($line))) {
				continue;
			}
			$colCount = count(str_getcsv($line, $delimiter, '"', ''));
			if ($colCount <= 1) {
				continue;
			}
			$columnCounts[] = $colCount;
		}

		if (empty($columnCounts)) {
			return 0;
		}

		$freq = array_count_values($columnCounts);

		// Prefer the highest column count appearing at least twice (header + data)
		$repeating = array_filter($freq, fn ($f) => $f >= 2);
		if (!empty($repeating)) {
			return self::headerWidth($columnCounts, max(array_keys($repeating)));
		}

		// Fallback: most frequent count, preferring higher on tie
		$maxFreq = max($freq);
		$candidates = array_keys(array_filter($freq, fn ($f) => $f === $maxFreq));
		return max($candidates);
	}

	/**
	 * The header's width, when every row under it leaves trailing cells off.
	 *
	 * An exporter that writes rows only up to their last filled cell (#462)
	 * can leave no row as wide as its header: a statement holding only
	 * debits never fills the credit column. The rows' own width then wins
	 * the count, and the header was dropped as preamble and the first row
	 * taken for it. When the line just before the first row of that width
	 * is wider, and nothing after it is wider still, that line is the
	 * header and its width is the file's; csvRows() pads the rows to it.
	 *
	 * @param int[] $columnCounts Widths of the multi-column lines, in file order
	 */
	private static function headerWidth(array $columnCounts, int $width): int {
		$first = array_search($width, $columnCounts, true);
		if ($first === false || $first === 0) {
			return $width;
		}
		$header = $columnCounts[$first - 1];
		if ($header <= $width || max(array_slice($columnCounts, $first)) > $header) {
			return $width;
		}
		return $header;
	}

	/**
	 * Count rows in content.
	 */
	public function countRows(string $content, string $format, string $delimiter = ',', bool $skipFirstRow = true): int {
		if ($format === 'csv') {
			$content = $this->stripBom($content);
			$lines = explode("\n", $content);
			$dataWidth = $this->detectDataWidth($lines, $delimiter);

			if ($dataWidth === 0) {
				return 0;
			}

			// Count the rows matching the data width, minus 1 for the header (if any)
			$count = iterator_count(self::csvRows($lines, $delimiter, $dataWidth));
			return $skipFirstRow ? max(0, $count - 1) : $count;
		}

		// For other formats, use parsed array count
		return count($this->parse($content, $format));
	}

	/**
	 * Get supported formats.
	 */
	public function getSupportedFormats(): array {
		return ['csv', 'ofx', 'qif'];
	}
}
