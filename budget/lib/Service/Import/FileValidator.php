<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

use OCP\IL10N;

/**
 * Validates uploaded import files for security and format compliance.
 */
class FileValidator {
    private ?IL10N $l;

    public function __construct(?IL10N $l = null) {
        $this->l = $l;
    }

    /**
     * Translate a message if IL10N is available, otherwise return as-is.
     */
    private function t(string $text, array $parameters = []): string {
        if ($this->l !== null) {
            return empty($parameters)
                ? $this->l->t($text)
                : $this->l->t($text, $parameters);
        }
        if (!empty($parameters)) {
            foreach ($parameters as $i => $param) {
                $text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
            }
        }
        return $text;
    }

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const ALLOWED_EXTENSIONS = ['csv', 'ofx', 'qif', 'xml', 'txt'];

    private const MIME_TYPES = [
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'txt' => ['text/plain'],
        'ofx' => ['text/plain', 'application/x-ofx', 'application/xml', 'text/xml', 'application/sgml'],
        'qif' => ['text/plain', 'application/qif', 'application/x-qif'],
        'xml' => ['application/xml', 'text/xml', 'text/plain'],
    ];

    /**
     * Validate an uploaded file.
     *
     * @param string $fileName Original filename
     * @param int $fileSize File size in bytes
     * @param string|null $tmpPath Temporary file path for content validation
     * @throws \Exception If validation fails
     */
    public function validate(string $fileName, int $fileSize, ?string $tmpPath = null): void {
        $this->validateSize($fileSize);
        $extension = $this->validateExtension($fileName);

        if ($tmpPath !== null && file_exists($tmpPath)) {
            $this->validateMimeType($tmpPath, $extension);
            $this->validateContent($tmpPath, $extension);
        }
    }

    /**
     * Validate file size.
     */
    public function validateSize(int $fileSize): void {
        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new \Exception($this->t('File too large. Maximum size is 10MB.'));
        }
    }

    /**
     * Validate file extension.
     *
     * @return string The validated extension
     */
    public function validateExtension(string $fileName): string {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            throw new \Exception(
                $this->t('Unsupported file format. Supported formats: %1$s', [implode(', ', self::ALLOWED_EXTENSIONS)])
            );
        }

        return $extension;
    }

    /**
     * Validate MIME type matches expected type for extension.
     */
    public function validateMimeType(string $filePath, string $extension): void {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

        $allowed = self::MIME_TYPES[$extension] ?? ['text/plain'];

        if (!in_array($mimeType, $allowed)) {
            throw new \Exception(
                $this->t('Invalid file type. Expected %1$s for .%2$s file, got: %3$s', [implode(' or ', $allowed), $extension, $mimeType])
            );
        }
    }

    /**
     * Validate file content matches expected format.
     */
    public function validateContent(string $filePath, string $extension): void {
        $content = file_get_contents($filePath, false, null, 0, 4096);

        if ($content === false || strlen($content) === 0) {
            throw new \Exception($this->t('File is empty or unreadable.'));
        }

        if ($this->containsBinaryData($content)) {
            throw new \Exception($this->t('File appears to be binary. Only text-based financial files are supported.'));
        }

        match ($extension) {
            'csv', 'txt' => $this->validateCsvContent($content),
            'ofx' => $this->validateOfxContent($content),
            'qif' => $this->validateQifContent($content),
            'xml' => $this->validateCamtContent($content),
            default => null,
        };
    }

    /**
     * Check if content contains binary (non-printable) data.
     *
     * High bytes are NOT a binary signal (#369). The old test allowed UTF-8
     * lead bytes (\xC0-\xFF) but not continuation bytes (\x80-\xBF), and every
     * non-ASCII code point carries at least one continuation byte — so roughly
     * half the bytes of Cyrillic, Greek or Hebrew text, and around 2/3 of CJK,
     * counted as "non-printable". Measured ratios were 0.47 / 0.47 / 0.57
     * against a 0.1 threshold, and even French text with ordinary accents
     * reached 0.105, so statements from most non-English locales were refused
     * as binary files. High bytes are expected in UTF-8 and in the legacy
     * 8-bit encodings (Windows-1251, ISO-8859-*) banks still export.
     *
     * NUL and the C0/C1 control characters are the reliable signal, and they
     * are what real binaries are full of: random bytes measure 0.111 control
     * characters and carry a NUL with near-certainty over a 4 KB window. Text
     * measures 0.000, which is why the threshold can sit far below the old one
     * and still leave the binary case a wide margin.
     *
     * Deliberately no mb_check_encoding() short-circuit: the caller passes the
     * first 4 KB, which can cut a multi-byte character in half and make valid
     * UTF-8 look malformed.
     */
    public function containsBinaryData(string $content): bool {
        // Reject null bytes (common in binary files, and in UTF-16 text,
        // which this importer does not read)
        if (strpos($content, "\x00") !== false) {
            return true;
        }

        // Control characters other than tab, LF and CR
        $control = preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content);
        $ratio = $control / max(1, strlen($content));

        return $ratio > 0.03;
    }

    /**
     * Validate CSV content structure.
     */
    private function validateCsvContent(string $content): void {
        $lines = explode("\n", $content);
        $nonEmptyLines = array_filter($lines, fn($line) => trim($line) !== '');

        if (count($nonEmptyLines) < 2) {
            throw new \Exception($this->t('CSV file must contain at least a header row and one data row.'));
        }

        $firstLine = array_values($nonEmptyLines)[0] ?? '';
        $hasComma = strpos($firstLine, ',') !== false;
        $hasSemicolon = strpos($firstLine, ';') !== false;
        $hasTab = strpos($firstLine, "\t") !== false;

        if (!$hasComma && !$hasSemicolon && !$hasTab) {
            throw new \Exception($this->t('CSV file does not appear to have valid delimiters (comma, semicolon, or tab).'));
        }
    }

    /**
     * Validate OFX content structure.
     */
    private function validateOfxContent(string $content): void {
        $hasOfxHeader = stripos($content, 'OFXHEADER:') !== false;
        $hasOfxTag = stripos($content, '<OFX>') !== false || stripos($content, '<ofx>') !== false;
        $hasXmlOfx = stripos($content, '<?OFX') !== false;

        if (!$hasOfxHeader && !$hasOfxTag && !$hasXmlOfx) {
            throw new \Exception($this->t('File does not appear to be a valid OFX file. Missing OFX header or tags.'));
        }
    }

    /**
     * Validate QIF content structure.
     */
    private function validateQifContent(string $content): void {
        $hasTypeHeader = stripos($content, '!Type:') !== false;
        $hasAccountHeader = stripos($content, '!Account') !== false;
        $hasTransactionMarker = strpos($content, '^') !== false;

        if (!$hasTypeHeader && !$hasAccountHeader) {
            throw new \Exception($this->t('File does not appear to be a valid QIF file. Missing !Type: or !Account header.'));
        }

        if (!$hasTransactionMarker) {
            throw new \Exception($this->t('File does not appear to be a valid QIF file. Missing transaction end markers (^).'));
        }
    }

    /**
     * Validate that an XML upload is an ISO 20022 camt.053/052 statement (#350).
     * The root element and namespace sit in the first bytes, so the 4 KB
     * content window is enough.
     */
    private function validateCamtContent(string $content): void {
        $isCamt = stripos($content, 'BkToCstmrStmt') !== false
            || stripos($content, 'BkToCstmrAcctRpt') !== false
            || stripos($content, 'camt.05') !== false;

        if (!$isCamt) {
            throw new \Exception($this->t('File does not appear to be a camt.053 bank statement. Only ISO 20022 camt.053/camt.052 XML is supported.'));
        }
    }

    /**
     * Get allowed file extensions.
     */
    public function getAllowedExtensions(): array {
        return self::ALLOWED_EXTENSIONS;
    }

    /**
     * Get maximum file size in bytes.
     */
    public function getMaxFileSize(): int {
        return self::MAX_FILE_SIZE;
    }

    /**
     * Detect the most likely delimiter in a CSV file.
     *
     * @param string $content File content (at least first few lines)
     * @return string The detected delimiter (comma, semicolon, or tab)
     */
    public function detectDelimiter(string $content): string {
        $lines = explode("\n", $content);
        $firstLine = array_values(array_filter($lines, fn($line) => trim($line) !== ''))[0] ?? '';

        // Count occurrences of each delimiter
        $commaCount = substr_count($firstLine, ',');
        $semicolonCount = substr_count($firstLine, ';');
        $tabCount = substr_count($firstLine, "\t");

        // Return the most common delimiter
        if ($semicolonCount > $commaCount && $semicolonCount > $tabCount) {
            return ';';
        } elseif ($tabCount > $commaCount && $tabCount > $semicolonCount) {
            return "\t";
        }

        // Default to comma
        return ',';
    }
}
