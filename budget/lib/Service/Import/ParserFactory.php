<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

use OCA\Budget\Service\Parser\OfxParser;
use OCA\Budget\Service\Parser\QifParser;

/**
 * Factory for creating file format parsers.
 */
class ParserFactory {
    private ?OfxParser $ofxParser = null;
    private ?QifParser $qifParser = null;

    /**
     * Detect file format from filename.
     */
    public function detectFormat(string $fileName): string {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'txt' => 'csv',
            'ofx' => 'ofx',
            'qif' => 'qif',
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
     * Parse file content based on format.
     *
     * @param string $content File content
     * @param string $format File format (csv, ofx, qif)
     * @param int|null $limit Maximum number of records to parse
     * @param string $delimiter CSV delimiter (comma, semicolon, or tab)
     * @return array Parsed data
     */
    public function parse(string $content, string $format, ?int $limit = null, string $delimiter = ','): array {
        return match ($format) {
            'csv' => $this->parseCsv($content, $limit, $delimiter),
            'ofx' => $this->getOfxParser()->parseToTransactionList($content, $limit),
            'qif' => $this->getQifParser()->parseToTransactionList($content, $limit),
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
     * @return array Parsed data
     */
    private function parseCsv(string $content, ?int $limit = null, string $delimiter = ','): array {
        $content = $this->stripBom($content);
        $lines = explode("\n", $content);
        $dataWidth = $this->detectDataWidth($lines, $delimiter);

        if ($dataWidth === 0) {
            return [];
        }

        $data = [];
        $headers = null;
        $count = 0;

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $row = str_getcsv($line, $delimiter);

            // Skip rows that don't match the expected data width (metadata/preamble)
            if (count($row) !== $dataWidth) {
                continue;
            }

            if ($headers === null) {
                $headers = array_map('trim', $row);
                continue;
            }

            if ($limit !== null && $count >= $limit) {
                break;
            }

            $data[] = array_combine($headers, array_pad($row, count($headers), ''));
            $count++;
        }

        return $data;
    }

    /**
     * Strip UTF-8 BOM from content.
     */
    private function stripBom(string $content): string {
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
    private function detectDataWidth(array $lines, string $delimiter): int {
        $columnCounts = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }
            $colCount = count(str_getcsv($line, $delimiter));
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
        $repeating = array_filter($freq, fn($f) => $f >= 2);
        if (!empty($repeating)) {
            return max(array_keys($repeating));
        }

        // Fallback: most frequent count, preferring higher on tie
        $maxFreq = max($freq);
        $candidates = array_keys(array_filter($freq, fn($f) => $f === $maxFreq));
        return max($candidates);
    }

    /**
     * Count rows in content.
     */
    public function countRows(string $content, string $format, string $delimiter = ','): int {
        if ($format === 'csv') {
            $content = $this->stripBom($content);
            $lines = explode("\n", $content);
            $dataWidth = $this->detectDataWidth($lines, $delimiter);

            if ($dataWidth === 0) {
                return 0;
            }

            // Count non-empty lines matching the data width, minus 1 for the header
            $count = 0;
            foreach ($lines as $line) {
                if (empty(trim($line))) {
                    continue;
                }
                if (count(str_getcsv($line, $delimiter)) === $dataWidth) {
                    $count++;
                }
            }
            return max(0, $count - 1);
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
