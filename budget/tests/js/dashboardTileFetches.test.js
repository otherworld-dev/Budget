/**
 * Tiles that hardcoded their own window now read the tile's date-range setting.
 * These tests assert on the URL each tile asks the server for, because that is
 * where the window actually takes effect.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

const at = (y, m, d) => new Date(y, m - 1, d);

/** Every URL passed to fetch during a call, in order. */
let requested;

function makeDashboard(tileSettings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: {},
        dashboardConfig: { widgets: { tileSettings } },
        widgetData: {},
        widgetDataLoaded: {},
        accounts: [],
    };
    return mod;
}

beforeEach(() => {
    requested = [];
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    global.fetch = vi.fn(async (url) => {
        requested.push(url);
        return { ok: true, json: async () => [] };
    });
});

afterEach(() => {
    vi.useRealTimers();
    delete global.OC;
    delete global.fetch;
});

describe('Large Transactions', () => {
    it('scopes the fetch to the tile date range', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 8, 24));
        const dash = makeDashboard({ largeTransactions: { dateRange: '30d' } });

        await dash.loadWidgetData('largeTransactions', true);

        expect(requested[0]).toContain('dateFrom=2026-07-25');
        expect(requested[0]).toContain('dateTo=2026-08-24');
    });

    it('uses the tile default when no range is saved', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 8, 24));
        const dash = makeDashboard({});

        await dash.loadWidgetData('largeTransactions', true);

        expect(requested[0]).toContain('dateFrom=2026-02-24');
    });
});

describe('Weekly Spending', () => {
    it('scopes the fetch to the tile date range', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 8, 24));
        const dash = makeDashboard({ weeklyTrend: { dateRange: '30d' } });

        await dash.loadWidgetData('weeklyTrend', true);

        expect(requested[0]).toContain('startDate=2026-07-25');
        expect(requested[0]).toContain('endDate=2026-08-24');
    });

    it('records the span so the daily average is not divided by 7', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 8, 24));
        const dash = makeDashboard({ weeklyTrend: { dateRange: '30d' } });
        global.fetch = vi.fn(async (url) => {
            requested.push(url);
            return { ok: true, json: async () => ({ totalExpenses: 300 }) };
        });

        await dash.loadWidgetData('weeklyTrend', true);

        expect(dash.widgetData.weeklyTrend[0]).toMatchObject({ total: 300, days: 31 });
    });
});
