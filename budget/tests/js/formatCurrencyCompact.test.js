/**
 * Compact currency for chart axes: "£4K" rather than "£4,000.00", which
 * crowded every dashboard, forecast and report axis.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { formatCurrencyCompact } from '../../src/utils/formatters.js';

const settings = { number_format_decimal_sep: '.', number_format_thousands_sep: ',' };

describe('formatCurrencyCompact', () => {
    it('drops a trailing .0 on thousands and millions', () => {
        expect(formatCurrencyCompact(4000, 'GBP', settings)).toBe('£4K');
        expect(formatCurrencyCompact(2500, 'GBP', settings)).toBe('£2.5K');
        expect(formatCurrencyCompact(3000000, 'GBP', settings)).toBe('£3M');
    });

    it('shows round figures under a thousand without decimals', () => {
        expect(formatCurrencyCompact(500, 'GBP', settings)).toBe('£500');
        expect(formatCurrencyCompact(0, 'GBP', settings)).toBe('£0');
        expect(formatCurrencyCompact(-500, 'GBP', settings)).toBe('-£500');
    });

    it('keeps the decimals of an amount that has them', () => {
        expect(formatCurrencyCompact(12.5, 'GBP', settings)).toBe('£12.50');
    });

    it('uses the user\'s decimal separator', () => {
        expect(formatCurrencyCompact(2500, 'EUR', { number_format_decimal_sep: ',', number_format_thousands_sep: '.' }))
            .toMatch(/2,5K/);
    });

    it('works without settings', () => {
        expect(formatCurrencyCompact(4000, 'GBP')).toBe('£4K');
    });
});
