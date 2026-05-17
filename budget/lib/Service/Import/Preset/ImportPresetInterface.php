<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Preset;

interface ImportPresetInterface {
    public function getId(): string;
    public function getName(): string;
    public function getDescription(): string;
    public function getMapping(): array;
    public function getDateFormatHint(): ?string;
    public function getDelimiter(): string;
    public function getOptions(): array;

    /**
     * Post-process a normalized transaction row.
     * Return null to skip the row (e.g., transfers).
     * Attach metadata like _categoryName, _tagName for downstream processing.
     *
     * @param array $normalizedRow The normalized transaction row
     * @param array $rawCsvRow The original CSV row (column name => value)
     * @return array|null Processed row or null to skip
     */
    public function postProcessRow(array $normalizedRow, array $rawCsvRow): ?array;

    /**
     * Infer account type from account name. Returns a valid AccountType value.
     */
    public function inferAccountType(string $accountName): string;

    /**
     * Return canonical column headers for positional remapping.
     * When non-null, the importer will replace the file's actual headers
     * with these values (by position), making the preset language-independent.
     *
     * @return string[]|null Canonical headers indexed by position, or null to use file headers
     */
    public function getExpectedHeaders(): ?array;
}
