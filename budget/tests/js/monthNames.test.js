/**
 * Month names come from Intl in the user's Nextcloud locale, not from 24
 * hand-translated strings (or hard-coded English, as the debt payoff and
 * trend charts had). The trend data carries Y-m keys for this.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
    getCanonicalLocale: () => 'fr-FR',
    getLanguage: () => 'fr',
    getLocale: () => 'fr_FR',
}));

import { monthName, formatYearMonth, formatDate } from '../../src/utils/formatters.js';

describe('monthName', () => {
    it('names months in the user locale', () => {
        expect(monthName(1)).toBe('janvier');
        expect(monthName(8)).toBe('août');
    });

    it('has a short form', () => {
        expect(monthName(1, 'short')).toBe('janv.');
    });
});

describe('formatYearMonth', () => {
    it('formats a Y-m key', () => {
        expect(formatYearMonth('2026-02', { month: 'long', year: 'numeric' })).toBe('février 2026');
    });

    it('returns anything else unchanged', () => {
        expect(formatYearMonth('Feb 2026')).toBe('Feb 2026');
        expect(formatYearMonth(undefined)).toBeUndefined();
    });
});

describe('formatDate with a month-name format', () => {
    it('uses the locale month and does not re-read letters inside it', () => {
        // "janv." contains a j, which the old chained replace() turned into the day
        expect(formatDate('2026-01-05', { date_format: 'j M Y' })).toBe('5 janv. 2026');
    });

    it('leaves numeric formats alone', () => {
        expect(formatDate('2026-01-05', { date_format: 'd/m/Y' })).toBe('05/01/2026');
    });
});
