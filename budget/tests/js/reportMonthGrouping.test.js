/**
 * With a budget start day the user chooses in Settings whether the dashboard
 * charts and the month-by-month reports count calendar months or budget
 * months (#457). Calendar months stay the default. The hero tiles compare
 * this budget period so far with the same days of the one before, either way.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';
import ReportsModule from '../../src/modules/reports/ReportsModule.js';
import ForecastModule from '../../src/modules/forecast/ForecastModule.js';
import { comparablePreviousRange, reportMonthStartDay, showMonthGroupingNote } from '../../src/utils/formatters.js';

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

    it('asks for the same days of the previous budget period for the hero comparison', async () => {
        const ranges = await summaryRequests({ budget_start_day: '26' });

        // Current period 26 Oct - 25 Nov, on its 2nd day; the one before
        // 26 Sep - 25 Oct, so its first 2 days
        expect(ranges).toContainEqual(['2026-10-26', '2026-11-25']);
        expect(ranges).toContainEqual(['2026-09-26', '2026-09-27']);
    });
});

describe('comparablePreviousRange', () => {
    const previous = { start: '2026-09-01', end: '2026-09-30' };
    const current = { start: '2026-10-01', end: '2026-10-31' };

    it('is the first day of the previous period on the first day of this one', () => {
        expect(comparablePreviousRange(previous, current, '2026-10-01')).toEqual({ start: '2026-09-01', end: '2026-09-01' });
    });

    it('covers as many days as this period has had so far', () => {
        expect(comparablePreviousRange(previous, current, '2026-10-10')).toEqual({ start: '2026-09-01', end: '2026-09-10' });
    });

    it('stops at the end of a shorter previous period', () => {
        expect(comparablePreviousRange(previous, current, '2026-10-30')).toEqual(previous);
        const february = { start: '2026-02-01', end: '2026-02-28' };
        const march = { start: '2026-03-01', end: '2026-03-31' };
        expect(comparablePreviousRange(february, march, '2026-03-29')).toEqual(february);
    });

    it('is the whole previous period on the last day of this one', () => {
        const october = { start: '2026-10-01', end: '2026-10-31' };
        const november = { start: '2026-11-01', end: '2026-11-30' };
        expect(comparablePreviousRange(october, november, '2026-11-30')).toEqual(october);
    });

    it('counts days across a month end with a start day', () => {
        const prev = { start: '2026-09-26', end: '2026-10-25' };
        const cur = { start: '2026-10-26', end: '2026-11-25' };
        expect(comparablePreviousRange(prev, cur, '2026-11-03')).toEqual({ start: '2026-09-26', end: '2026-10-04' });
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

describe('report period presets', () => {
    function rangeFor(preset, settings) {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 9, 28));
        document.body.innerHTML = '<input id="report-start-date"><input id="report-end-date">';
        const reports = Object.create(ReportsModule.prototype);
        reports.app = { settings };
        reports.setReportDateRange(preset);
        return [document.getElementById('report-start-date').value, document.getElementById('report-end-date').value];
    }

    const budget = { budget_start_day: '26', report_month_grouping: 'budget' };

    it('keeps calendar months by default', () => {
        expect(rangeFor('this-month', { budget_start_day: '26' })).toEqual(['2026-10-01', '2026-10-31']);
    });

    it('makes "This Month" the running budget month, not two halves', () => {
        // 28 Oct with start day 26 is in November's budget month
        expect(rangeFor('this-month', budget)).toEqual(['2026-10-26', '2026-11-25']);
    });

    it('makes the other presets whole budget months', () => {
        expect(rangeFor('last-3-months', budget)).toEqual(['2026-08-26', '2026-11-25']);
        expect(rangeFor('ytd', budget)).toEqual(['2025-12-26', '2026-10-28']);
        expect(rangeFor('last-year', budget)).toEqual(['2024-12-26', '2025-12-25']);
    });
});

describe('forecast', () => {
    it('says when it learns from budget months', () => {
        document.body.innerHTML = '<span id="forecast-confidence"></span><span id="data-info"></span><p id="forecast-month-note" style="display: none;"></p>';
        const forecast = Object.create(ForecastModule.prototype);
        const data = { confidence: 40, monthStartDay: 26, dataQuality: { monthsOfData: 1, transactionCount: 88 } };

        forecast.displayDataQuality(data);
        expect(document.getElementById('forecast-month-note').textContent).toContain('day 26');

        forecast.displayDataQuality({ ...data, monthStartDay: 1 });
        expect(document.getElementById('forecast-month-note').style.display).toBe('none');
    });
});
