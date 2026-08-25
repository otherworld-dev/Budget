/**
 * Coverage for the date window a dashboard tile actually charts (#333).
 *
 * The tile settings offer "Last 30 days" / "Last year", but both code paths
 * that turned those settings into dates produced calendar-to-date windows
 * instead: '30d' became the 1st of the current month and '1y' became 1 January.
 * On the 1st of a month "Last 30 days" charted a single day, and a user whose
 * pay cycle starts on the 25th saw the tile disagree with the period-aware
 * budget alerts sitting directly above it -- which is how #333 was reported.
 *
 * These ranges are now literal rolling windows, and a tile that is really
 * asking "how much have I spent this cycle" can follow the user's budget
 * period instead.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { resolveTileDateRange, getPeriodDateRange } from '../../src/utils/formatters.js';
import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';
import { DASHBOARD_WIDGETS } from '../../src/config/dashboardWidgets.js';

const at = (y, m, d) => new Date(y, m - 1, d);

describe('resolveTileDateRange -- rolling windows', () => {
    it('spans a real 30 days back from today', () => {
        const range = resolveTileDateRange('30d', { referenceDate: at(2026, 8, 24) });

        expect(range).toMatchObject({ start: '2026-07-25', end: '2026-08-24' });
    });

    it('still spans 30 days on the 1st of a month, not one day', () => {
        const range = resolveTileDateRange('30d', { referenceDate: at(2026, 9, 1) });

        expect(range).toMatchObject({ start: '2026-08-02', end: '2026-09-01' });
    });

    it('spans a real 90 days back from today', () => {
        const range = resolveTileDateRange('90d', { referenceDate: at(2026, 8, 24) });

        expect(range).toMatchObject({ start: '2026-05-26', end: '2026-08-24' });
    });

    it('goes back six calendar months', () => {
        const range = resolveTileDateRange('6m', { referenceDate: at(2026, 8, 24) });

        expect(range).toMatchObject({ start: '2026-02-24', end: '2026-08-24' });
    });

    it('clamps six months back from the 31st to the last day of that month', () => {
        // Naive setMonth(-6) on 31 March overflows into 1 October.
        const range = resolveTileDateRange('6m', { referenceDate: at(2026, 3, 31) });

        expect(range.start).toBe('2025-09-30');
    });

    it('spans a real year back from today, not the year to date', () => {
        const range = resolveTileDateRange('1y', { referenceDate: at(2026, 1, 3) });

        expect(range).toMatchObject({ start: '2025-01-03', end: '2026-01-03' });
    });

    it('clamps a year back from 29 February to the 28th', () => {
        const range = resolveTileDateRange('1y', { referenceDate: at(2028, 2, 29) });

        expect(range.start).toBe('2027-02-28');
    });

    it('leaves the rolling ranges without a date-span label', () => {
        const range = resolveTileDateRange('30d', { referenceDate: at(2026, 8, 24) });

        expect(range.label).toBeNull();
    });
});

describe('resolveTileDateRange -- current budget period', () => {
    it('follows a budget cycle that starts on the 25th', () => {
        const range = resolveTileDateRange('period', {
            budgetStartDay: 25,
            referenceDate: at(2026, 8, 24),
        });

        expect(range).toMatchObject({ start: '2026-07-25', end: '2026-08-24' });
    });

    it('rolls to the new cycle on the start day itself', () => {
        const range = resolveTileDateRange('period', {
            budgetStartDay: 25,
            referenceDate: at(2026, 8, 25),
        });

        expect(range).toMatchObject({ start: '2026-08-25', end: '2026-09-24' });
    });

    it('clamps a start day of 31 to a short month', () => {
        const range = resolveTileDateRange('period', {
            budgetStartDay: 31,
            referenceDate: at(2026, 2, 15),
        });

        expect(range).toMatchObject({ start: '2026-01-31', end: '2026-02-27' });
    });

    it('is a whole calendar month when the cycle starts on the 1st', () => {
        const range = resolveTileDateRange('period', {
            budgetStartDay: 1,
            referenceDate: at(2026, 8, 24),
        });

        expect(range).toMatchObject({ start: '2026-08-01', end: '2026-08-31' });
    });

    it('carries a date-span label so the tile can say which days it charts', () => {
        const range = resolveTileDateRange('period', {
            budgetStartDay: 25,
            referenceDate: at(2026, 8, 24),
        });

        expect(range.label).toEqual(expect.any(String));
        expect(range.label.length).toBeGreaterThan(0);
    });
});

describe('resolveTileDateRange -- unknown values', () => {
    it('falls back to the range the caller nominates', () => {
        const range = resolveTileDateRange(undefined, {
            fallback: '30d',
            referenceDate: at(2026, 8, 24),
        });

        expect(range).toMatchObject({ start: '2026-07-25', end: '2026-08-24' });
    });

    it('falls back for a value no longer offered', () => {
        const range = resolveTileDateRange('3months', {
            fallback: '6m',
            referenceDate: at(2026, 8, 24),
        });

        expect(range).toMatchObject({ start: '2026-02-24', end: '2026-08-24' });
    });

    it('does not recurse forever when the fallback is itself unknown', () => {
        const range = resolveTileDateRange('nonsense', {
            fallback: 'also-nonsense',
            referenceDate: at(2026, 8, 24),
        });

        expect(range.start).toBe('2026-02-24');
    });
});

function makeDashboard(tileSettings = {}, settings = {}) {
    // The module reads config and settings through its app host, not off itself.
    const mod = Object.create(DashboardModule.prototype);
    mod.app = { settings, dashboardConfig: { widgets: { tileSettings } } };
    return mod;
}

afterEach(() => {
    vi.useRealTimers();
});

describe('DashboardModule._tileRangeParams', () => {
    it('gives a spending tile a real 30-day window', () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 8, 24));
        const dash = makeDashboard({ spendingChart: { dateRange: '30d' } });

        expect(dash._tileRangeParams('spendingChart')).toMatchObject({
            startDate: '2026-07-25',
            endDate: '2026-08-24',
        });
    });

    it('follows the budget cycle when the tile is set to the current period', () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 8, 24));
        const dash = makeDashboard(
            { budgetProgress: { dateRange: 'period' } },
            { budget_start_day: '25' },
        );

        expect(dash._tileRangeParams('budgetProgress')).toMatchObject({
            startDate: '2026-07-25',
            endDate: '2026-08-24',
        });
    });

    it("falls back to the tile's own default when nothing is saved", () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 8, 24));
        const dash = makeDashboard({});

        expect(dash._tileRangeParams('spendingChart')).toMatchObject({
            startDate: '2026-02-24',
            endDate: '2026-08-24',
        });
    });

    it('resolves a duplicated tile instance by its base type', () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 8, 24));
        const dash = makeDashboard({ 'spendingChart__2': { dateRange: '30d' } });

        expect(dash._tileRangeParams('spendingChart__2')).toMatchObject({
            startDate: '2026-07-25',
        });
    });

    it('treats a missing budget start day as the 1st', () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 8, 24));
        const dash = makeDashboard({ topCategories: { dateRange: 'period' } });

        expect(dash._tileRangeParams('topCategories')).toMatchObject({
            startDate: '2026-08-01',
            endDate: '2026-08-31',
        });
    });
});

describe('DashboardModule._dateRangeField', () => {
    it('offers the budget period to a tile that declares it', () => {
        const dash = makeDashboard();

        const html = dash._dateRangeField({ dateRange: true, budgetPeriodRange: true }, '30d');

        expect(html).toContain('value="period"');
    });

    it('withholds it from a tile that does not', () => {
        const dash = makeDashboard();

        const html = dash._dateRangeField({ dateRange: true }, '30d');

        expect(html).not.toContain('value="period"');
    });

    it('marks the saved range as selected', () => {
        const dash = makeDashboard();

        const html = dash._dateRangeField({ dateRange: true, budgetPeriodRange: true }, 'period');

        expect(html).toMatch(/value="period"\s+selected/);
    });
});

describe('budget-period option coverage', () => {
    const schemaFor = (id) => {
        for (const group of Object.values(DASHBOARD_WIDGETS)) {
            if (group[id]) return group[id].settingsSchema || {};
        }
        throw new Error(`no widget definition for ${id}`);
    };

    it.each(['spendingChart', 'topCategories', 'budgetProgress'])(
        'offers the budget period on %s, which answers "spent this cycle"',
        (id) => {
            expect(schemaFor(id).budgetPeriodRange).toBe(true);
        },
    );

    it.each(['netWorthHistory', 'assetValueHistory', 'trendChart'])(
        'withholds it from %s, which charts a series rather than one period',
        (id) => {
            expect(schemaFor(id).budgetPeriodRange).toBeUndefined();
        },
    );
});

describe('DashboardModule._tilePeriodLabel', () => {
    it('names the actual days when the tile follows the budget cycle', () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 8, 24));
        const dash = makeDashboard(
            { spendingChart: { dateRange: 'period' } },
            { budget_start_day: '25' },
        );

        const label = dash._tilePeriodLabel('spendingChart', 'spendingChart');

        expect(label).toBe(getPeriodDateRange('monthly', 25, at(2026, 8, 24)).label);
        expect(label).not.toBe('Current budget period');
    });

    it('keeps the plain wording for a rolling range', () => {
        const dash = makeDashboard({ spendingChart: { dateRange: '30d' } });

        expect(dash._tilePeriodLabel('spendingChart', 'spendingChart')).toBe('Last 30 days');
    });
});
