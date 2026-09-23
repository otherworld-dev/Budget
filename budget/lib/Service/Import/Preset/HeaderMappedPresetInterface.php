<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Preset;

/**
 * A preset for another budgeting app's export, read by column NAME.
 *
 * Toshl's preset maps columns by position because Toshl translates its
 * headers. The apps behind these presets write fixed English headers but add
 * and reorder columns between versions (Firefly III moved its currency
 * columns in 6.x), so a positional mapping would silently read the wrong
 * column after an upgrade. ImportService instead keys each row by its header
 * text and refuses a file that lacks the required columns.
 *
 * Rows produced this way are also parsed as real CSV records, so a note that
 * spans several lines stays one transaction instead of being dropped.
 */
interface HeaderMappedPresetInterface extends ImportPresetInterface {
    /**
     * Columns that must all be present (compared case-insensitively). Used to
     * validate the file and to recognise it from its header row on upload.
     *
     * @return string[]
     */
    public function getRequiredHeaders(): array;

    /**
     * Turn one file row into the rows to import.
     *
     * Nearly always [$row]. Firefly III writes a transfer between two of the
     * user's accounts as ONE row, but it is two movements here (money out of
     * one account, into the other), so its preset returns one row per side.
     * An empty list drops the row.
     *
     * @param array<string, string> $row File row keyed by header
     * @return array<int, array<string, string>>
     */
    public function expandRow(array $row): array;
}
