/**
 * A budget cycle that ends next to a clocks-forward day.
 *
 * The cycle's last day was worked out as "the next cycle's start minus 24
 * hours". The day the clocks go forward is 23 hours long, so that landed on
 * the day before: with start day 30 in London, March's cycle ended on 28
 * March and April's began on 30 March, so spending on 29 March was in no
 * cycle at all. The last day is now the calendar day before.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
    getLanguage: () => 'en',
    getLocale: () => 'en',
}));

import { getPeriodDateRange } from '../../src/utils/formatters.js';

const originalTz = process.env.TZ;

afterEach(() => {
    if (originalTz === undefined) {
        delete process.env.TZ;
    } else {
        process.env.TZ = originalTz;
    }
});

describe('a monthly budget cycle with a start day', () => {
    it('ends the day before the next one starts, across the spring clock change in London', () => {
        process.env.TZ = 'Europe/London';
        // British Summer Time starts on Sunday 29 March 2026
        const march = getPeriodDateRange('monthly', 30, '2026-03-15');
        const april = getPeriodDateRange('monthly', 30, '2026-04-15');

        expect(march).toMatchObject({ start: '2026-02-28', end: '2026-03-29' });
        expect(april.start).toBe('2026-03-30');
    });

    it('ends the day before in New York too', () => {
        process.env.TZ = 'America/New_York';
        // Daylight saving starts on Sunday 8 March 2026
        expect(getPeriodDateRange('monthly', 9, '2026-03-05')).toMatchObject({ start: '2026-02-09', end: '2026-03-08' });
        expect(getPeriodDateRange('monthly', 9, '2026-02-20').end).toBe('2026-03-08');
    });
});
