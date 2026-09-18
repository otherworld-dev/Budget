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

describe('Budget Progress', () => {
    it('uses the cycle month for snapshots when the period crosses months', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 9, 15));
        const dash = makeDashboard({ budgetProgress: { dateRange: 'period' } });
        dash.app.settings.budget_start_day = '28';
        dash.updateBudgetProgressWidget = vi.fn();
        global.fetch = vi.fn(async (url) => {
            requested.push(url);
            return { ok: true, json: async () => ({ categories: [] }) };
        });

        await dash.refreshBudgetProgressWidget();

        expect(requested[0]).toContain('startDate=2026-08-28');
        expect(requested[0]).toContain('endDate=2026-09-27');
        expect(requested[0]).toContain('snapshotMonth=2026-09');
    });

    it('names a cycle starting on the 10th after the month it starts in', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 9, 20));
        const dash = makeDashboard({ budgetProgress: { dateRange: 'period' } });
        dash.app.settings.budget_start_day = '10';
        dash.updateBudgetProgressWidget = vi.fn();

        await dash.refreshBudgetProgressWidget();

        // The Budget page calls 10 Sep - 9 Oct "September", so that is the
        // snapshot to load, not October's.
        expect(requested[0]).toContain('startDate=2026-09-10');
        expect(requested[0]).toContain('endDate=2026-10-09');
        expect(requested[0]).toContain('snapshotMonth=2026-09');
    });
});

describe('initial dashboard load', () => {
    async function loadWithStartDay(startDay, today) {
        vi.useFakeTimers();
        vi.setSystemTime(today);
        const dash = makeDashboard();
        dash.app.settings.budget_start_day = startDay;
        await dash.loadDashboard();
        return dash;
    }

    it('asks for the budget cycle and its snapshot month for the hero stats', async () => {
        await loadWithStartDay('10', at(2026, 9, 20));

        const summary = requested.find(u => u.includes('/reports/summary?startDate=2026-09-10'));
        const budget = requested.find(u => u.includes('/reports/budget'));
        expect(summary).toContain('endDate=2026-10-09');
        expect(budget).toContain('startDate=2026-09-10');
        expect(budget).toContain('endDate=2026-10-09');
        expect(budget).toContain('snapshotMonth=2026-09');
    });

    it('keeps the six-month trend on calendar months when the cycle runs into next month', async () => {
        await loadWithStartDay('10', at(2026, 9, 20));

        // The trend request is the one starting on a 1st; ending it at the
        // cycle end (9 Oct) would chart an empty October.
        const trend = requested.find(u => u.includes('/reports/summary?startDate=2026-04-01'));
        expect(trend).toContain('endDate=2026-09-30');
    });
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
            // /reports/summary nests its figures under totals
            return { ok: true, json: async () => ({ totals: { totalExpenses: 300 } }) };
        });

        await dash.loadWidgetData('weeklyTrend', true);

        expect(dash.widgetData.weeklyTrend[0]).toMatchObject({ total: 300, days: 31 });
    });
});

describe('Category Trends', () => {
    it('asks for the chosen window and the one before it', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 8, 24));
        const dash = makeDashboard({ categoryTrends: { dateRange: '30d' } });

        await dash.loadWidgetData('categoryTrends', true);

        expect(requested[0]).toContain('startDate=2026-07-25');
        expect(requested[0]).toContain('endDate=2026-08-24');
        expect(requested[1]).toContain('startDate=2026-06-24');
        expect(requested[1]).toContain('endDate=2026-07-24');
    });

    /**
     * categories/spending has returned netted, signed totals for ALL
     * categories since v2.44.2 — income categories included. Their netted
     * total is usually negative and fell to the >0 filter only by accident:
     * a month where an income category's debits exceed its credits netted
     * positive and rendered as "spending". The tile must scope itself to
     * expense-type categories explicitly.
     */
    it('excludes income categories even when their netted total is positive', async () => {
        const dash = makeDashboard({});
        dash.app.categories = [
            { id: 1, name: 'Food', type: 'expense' },
            { id: 2, name: 'Salary', type: 'income' },
        ];
        global.fetch = vi.fn(async (url) => ({
            ok: true,
            json: async () => [
                { categoryId: 1, name: 'Food', color: '#111111', spent: '120' },
                // Refund-heavy month: the income category netted positive.
                { categoryId: 2, name: 'Salary', color: '#222222', spent: '75' },
            ],
        }));

        await dash.loadWidgetData('categoryTrends', true);

        expect(dash.widgetData.categoryTrends.map(c => c.name)).toEqual(['Food']);
    });

    it('keeps netted values and drops expense categories netting to zero or below', async () => {
        const dash = makeDashboard({});
        dash.app.categories = [
            { id: 1, name: 'Food', type: 'expense' },
            { id: 3, name: 'Refunds', type: 'expense' },
        ];
        global.fetch = vi.fn(async (url) => ({
            ok: true,
            json: async () => [
                { categoryId: 1, name: 'Food', color: '#111111', spent: '120' },
                // A refund-heavy expense category netting <= 0 drops out —
                // accepted behaviour, the netting is intended.
                { categoryId: 3, name: 'Refunds', color: '#333333', spent: '-40' },
            ],
        }));

        await dash.loadWidgetData('categoryTrends', true);

        expect(dash.widgetData.categoryTrends.map(c => c.name)).toEqual(['Food']);
        expect(dash.widgetData.categoryTrends[0].currentTotal).toBe(120);
    });

    it('treats a category missing from the client list as expense', async () => {
        // Same resolution as the Budget page (CategoriesModule): anything
        // not known to be income counts as expense.
        const dash = makeDashboard({});
        dash.app.categories = [];
        global.fetch = vi.fn(async (url) => ({
            ok: true,
            json: async () => [
                { categoryId: 99, name: 'Orphan', color: '#999999', spent: '10' },
            ],
        }));

        await dash.loadWidgetData('categoryTrends', true);

        expect(dash.widgetData.categoryTrends.map(c => c.name)).toEqual(['Orphan']);
    });
});
