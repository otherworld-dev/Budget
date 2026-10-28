/**
 * With a budget start day the user chooses in Settings whether the dashboard
 * charts and the month-by-month reports count calendar months or budget
 * months (#457). Calendar months stay the default. The hero tiles compare
 * this budget period with the whole one before, either way.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';
import { reportMonthStartDay, showMonthGroupingNote } from '../../src/utils/formatters.js';

function makeDashboard(settings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings,
        dashboardConfig: { widgets: { tileSettings: {} } },
        widgetData: {},
        widgetDataLoaded: {},
        accounts: [],
    };
    return mod;
}

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
});

describe('reportMonthStartDay', () => {
    it('is 1, calendar months, unless budget months are chosen', () => {
        expect(reportMonthStartDay({ budget_start_day: '26' })).toBe(1);
        expect(reportMonthStartDay({ budget_start_day: '26', report_month_grouping: 'calendar' })).toBe(1);
        expect(reportMonthStartDay(undefined)).toBe(1);
    });

    it('is the budget start day when budget months are chosen', () => {
        expect(reportMonthStartDay({ budget_start_day: '26', report_month_grouping: 'budget' })).toBe(26);
        expect(reportMonthStartDay({ budget_start_day: '1', report_month_grouping: 'budget' })).toBe(1);
    });
});

describe('showMonthGroupingNote', () => {
    it('names the start day for budget months and hides itself for calendar months', () => {
        const el = document.createElement('p');

        showMonthGroupingNote(el, 26);
        expect(el.textContent).toContain('day 26');
        expect(el.style.display).toBe('');

        showMonthGroupingNote(el, 1);
        expect(el.textContent).toBe('');
        expect(el.style.display).toBe('none');
    });
});

describe('dashboard load', () => {
    async function summaryRequests(settings) {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 9, 27));
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({}) }));
        const dash = makeDashboard(settings);
        dash.app.getPrimaryCurrency = () => 'EUR';
        await dash.loadDashboard();
        return global.fetch.mock.calls
            .map(([url]) => String(url))
            .filter(url => url.includes('/api/reports/summary'))
            .map(url => {
                const q = new URL(url, 'http://x').searchParams;
                return [q.get('startDate'), q.get('endDate')];
            });
    }

    it('draws the charts over whole calendar months by default', async () => {
        const ranges = await summaryRequests({ budget_start_day: '26' });

        expect(ranges).toContainEqual(['2026-05-01', '2026-10-31']);
    });

    it('draws the charts over whole budget months when chosen', async () => {
        // 27 Oct with start day 26 is in November's budget month (26 Oct - 25 Nov)
        const ranges = await summaryRequests({ budget_start_day: '26', report_month_grouping: 'budget' });

        expect(ranges).toContainEqual(['2026-05-26', '2026-11-25']);
    });

    it('asks for the previous budget period for the hero comparison', async () => {
        const ranges = await summaryRequests({ budget_start_day: '26' });

        // Current period 26 Oct - 25 Nov, the one before 26 Sep - 25 Oct
        expect(ranges).toContainEqual(['2026-10-26', '2026-11-25']);
        expect(ranges).toContainEqual(['2026-09-26', '2026-10-25']);
    });
});

describe('hero comparison', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <span id="hero-income-value"></span><span id="hero-income-change"></span>
            <span id="hero-expenses-value"></span><span id="hero-expenses-change"></span>
            <span id="hero-cash-flow-value"></span><span id="hero-cash-flow-change"></span>`;
    });

    it('compares this period with the whole previous period, not calendar slices', () => {
        const dash = makeDashboard();
        dash.app.getPrimaryCurrency = () => 'EUR';
        const summary = {
            totals: { totalIncome: 3000, totalExpenses: 500 },
            previousTotals: { totalIncome: 1500, totalExpenses: 1000 },
            // Calendar slices of this period, which used to be compared
            trends: { income: [3000, 0], expenses: [100, 400] },
        };

        dash.updateDashboardHero(summary);
        dash.updateCashFlowHero(summary);

        expect(document.getElementById('hero-income-change').textContent).toContain('100.0% vs last month');
        expect(document.getElementById('hero-expenses-change').textContent).toContain('50.0% vs last month');
        expect(document.getElementById('hero-expenses-change').className).toContain('positive');
        // 2500 against 500
        expect(document.getElementById('hero-cash-flow-change').textContent).toContain('400.0% vs last month');
    });

    it('shows no change without the previous period', () => {
        const dash = makeDashboard();
        dash.app.getPrimaryCurrency = () => 'EUR';

        dash.updateDashboardHero({ totals: { totalIncome: 3000 }, trends: { income: [1000, 3000] } });

        expect(document.getElementById('hero-income-change').textContent).toBe('');
    });
});
