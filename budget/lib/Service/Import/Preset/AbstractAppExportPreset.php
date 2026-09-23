<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Preset;

/**
 * Shared behaviour for presets that read another budgeting app's export.
 *
 * Metadata a preset may attach in postProcessRow(), all consumed by
 * ImportService:
 * - _accountName / _accountType / _currency: which account the row belongs
 *   to, and how to create that account if the user has none by that name
 * - _categoryName, plus _categoryParent for a two-level category
 * - _tagNames: tags, filed under a "Tags" tag set on the row's category
 * - _transfer / _transferPeer: this row is one side of a transfer between
 *   two of the user's accounts; the peer is the other account's name, or ''
 *   when the file does not say. Both sides are imported (each account's
 *   balance needs its own side) and linked to each other afterwards, which is
 *   what keeps a transfer out of income and spending totals.
 * - _hashDate / _hashDescription / _hashReference: the frozen identity the
 *   import ID is derived from. Always raw file text, never anything the user
 *   can choose on the import screen, so importing the same export twice
 *   recognises every row the second time (#338).
 */
abstract class AbstractAppExportPreset implements HeaderMappedPresetInterface {
    /**
     * Name keywords, most specific first: the first whole-word match wins,
     * so "Line of credit" must be tested before "credit" and "Cash ISA"
     * before "cash".
     */
    private const ACCOUNT_TYPE_KEYWORDS = [
        'line of credit' => 'line_of_credit',
        'credit card' => 'credit_card',
        'mortgage' => 'mortgage',
        'loan' => 'loan',
        'savings' => 'savings',
        'saving' => 'savings',
        'isa' => 'savings',
        'investment' => 'investment',
        'brokerage' => 'investment',
        'crypto' => 'cryptocurrency',
        'bitcoin' => 'cryptocurrency',
        'credit' => 'credit_card',
        'visa' => 'credit_card',
        'mastercard' => 'credit_card',
        'amex' => 'credit_card',
        'checking' => 'checking',
        'chequing' => 'checking',
        'current' => 'checking',
        'cash' => 'cash',
    ];

    /**
     * Characters a spreadsheet would read as the start of a formula. Firefly
     * III and Actual both guard their exports by prefixing a cell that starts
     * with one of these with an apostrophe.
     */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    public function getDelimiter(): string {
        return ',';
    }

    /**
     * Header-mapped presets are read by column name, never by position.
     */
    public function getExpectedHeaders(): ?array {
        return null;
    }

    public function getDateFormatHint(): ?string {
        return null;
    }

    public function getOptions(): array {
        return [
            'autoCreateCategories' => true,
            'accountColumn' => $this->getAccountColumn(),
        ];
    }

    public function expandRow(array $row): array {
        return [$row];
    }

    /**
     * The column naming each row's account. Every supported export covers
     * several accounts at once, so rows are routed per account and missing
     * accounts are created, the same way the Toshl preset works.
     */
    abstract protected function getAccountColumn(): string;

    public function inferAccountType(string $accountName): string {
        $lower = strtolower(trim($accountName));
        foreach (self::ACCOUNT_TYPE_KEYWORDS as $keyword => $type) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $lower) === 1) {
                return $type;
            }
        }
        return 'checking';
    }

    /**
     * One cell's text, trimmed, with an export's formula guard removed.
     *
     * @param array<string, string> $row
     */
    protected function cell(array $row, string $column): string {
        $value = trim((string) ($row[$column] ?? ''));
        if (strlen($value) > 1 && $value[0] === "'" && in_array($value[1], self::FORMULA_TRIGGERS, true)) {
            $value = substr($value, 1);
        }
        return $value;
    }

    /**
     * Split a tag cell into distinct, non-blank tag names.
     *
     * @return string[]
     */
    protected function splitTags(string $value): array {
        if ($value === '') {
            return [];
        }
        $tags = array_filter(array_map('trim', explode(',', $value)), static fn($tag) => $tag !== '');
        return array_values(array_unique($tags));
    }

    /**
     * Pin the fields the import ID is derived from to raw file text.
     *
     * @param array<string, mixed> $row Normalized row
     */
    protected function freezeIdentity(array $row, string $rawDate, string $description, string $reference = ''): array {
        $row['_hashDate'] = $rawDate;
        $row['_hashDescription'] = $description;
        $row['_hashReference'] = $reference;
        return $row;
    }

    /**
     * Put the account metadata on a row, when the file names one.
     *
     * @param array<string, mixed> $row
     */
    protected function withAccount(array $row, string $accountName): array {
        if ($accountName !== '') {
            $row['_accountName'] = $accountName;
        }
        return $row;
    }

    /**
     * Mark a row as one side of a transfer between two of the user's accounts.
     *
     * @param array<string, mixed> $row
     * @param string $peer The other account's name, '' when the file does not say
     */
    protected function markTransfer(array $row, string $peer): array {
        $row['_transfer'] = true;
        $row['_transferPeer'] = $peer;
        unset($row['_categoryName'], $row['_categoryParent'], $row['_tagNames']);
        return $row;
    }

    /**
     * Join note fragments, skipping blanks.
     */
    protected function joinNotes(string ...$parts): ?string {
        $parts = array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));
        return $parts === [] ? null : implode("\n", $parts);
    }
}
