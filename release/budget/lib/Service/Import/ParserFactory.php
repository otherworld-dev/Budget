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
     * @return array Parsed data
     */
    public function parse(string $content, string $format, ?int $limit = null): array {
        return match ($format) {
            'csv' => $this->parseCsv($content, $limit),
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
     */
    private function parseCsv(string $content, ?int $limit = null): array {
        $lines = explode("\n", $content);
        $data = [];
        $headers = null;
        $count = 0;

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $row = str_getcsv($line);

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
     * Count rows in content.
     */
    public function countRows(string $content, string $format): int {
        if ($format === 'csv') {
            $lines = explode("\n", $content);
            $count = 0;
            foreach ($lines as $line) {
                if (!empty(trim($line))) {
                    $count++;
                }
            }
            return max(0, $count - 1); // Subtract header row
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
