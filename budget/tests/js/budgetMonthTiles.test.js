/**
 * The Monthly Comparison tile and the account page's "This Month" tiles
 * count budget months: with a budget start day they compare and total whole
 * budget periods, and say which days those are.
 *
 * The comparison tile also read income and expenses from the top of the
 * /reports/summary response, which nests them under totals, so it showed
 * nothing but zeros whatever the data.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';
import AccountsModule from '../../src/modules/accounts/AccountsModule.js';
import { getPeriodDateRange } from '../../src/utils/formatters.js';

const at = (y, m, d) => new Date(y, m - 1, d);

function makeDashboard(settings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings,
        dashboardConfig: { widgets: { tileSettings: {} } },
        widgetData: {},
        widgetDataLoaded: {},
        accounts: [],
    };
    mod.formatCurrency = (v) => 'CHF ' + Number(v).toFixed(2);
    return mod;
}

let requested;

beforeEach(() => {
    requested = [];
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    global.fetch = vi.fn(async (url) => {
        requested.push(url);
        return { ok: true, json: async () => ({ totals: {} }) };
    });
});

afterEach(() => {
    vi.useRealTimers();
    delete global.OC;
    delete global.fetch;
    document.body.innerHTML = '';
});

describe('Monthly Comparison', () => {
    it('compares the running budget period with the one before', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 9, 29));
        const dash = makeDashboard({ budget_start_day: '28' });

        await dash.loadWidgetData('monthlyComparison', true);

        expect(requested[0]).toContain('startDate=2026-09-28&endDate=2026-10-27');
        expect(requested[1]).toContain('startDate=2026-08-28&endDate=2026-09-27');
        expect(dash.widgetData.monthlyComparison.periodLabel)
            .toBe(getPeriodDateRange('monthly', 28, at(2026, 9, 29)).label);
    });

    it('compares calendar months without a start day', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 9, 17));
        const dash = makeDashboard();

        await dash.loadWidgetData('monthlyComparison', true);

        expect(requested[0]).toContain('startDate=2026-09-01&endDate=2026-09-30');
        expect(requested[1]).toContain('startDate=2026-08-01&endDate=2026-08-31');
        expect(dash.widgetData.monthlyComparison.periodLabel).toBeNull();
    });

    it('shows the totals the summary returns', () => {
        document.body.innerHTML = '<div id="monthly-comparison-content"></div>';
        const dash = makeDashboard();
        dash.widgetData.monthlyComparison = {
            current: { totals: { totalIncome: 1000, totalExpenses: 500 } },
            previous: { totals: { totalIncome: 800, totalExpenses: 600 } },
            periodLabel: '28 Sept – 27 Oct',
        };

        dash.updateMonthlyComparisonWidget();

        const text = document.getElementById('monthly-comparison-content').textContent.replace(/\s+/g, ' ');
        expect(text).toContain('28 Sept – 27 Oct');
        expect(text).toContain('Income CHF 1000.00 ↑ 25%');
        expect(text).toContain('Expenses CHF 500.00 ↓ 16.7%');
    });
});

describe('account page month tiles', () => {
    function renderTiles() {
        document.body.innerHTML = `
            <div class="metric-content"><div id="total-income"></div><div class="metric-label">This Month Income</div></div>
            <div class="metric-content"><div id="total-expenses"></div><div class="metric-label">This Month Expenses</div></div>
            <div class="metric-content"><div id="avg-transaction"></div><div class="metric-label">Average</div></div>`;
    }

    function makeAccounts(settings) {
        const mod = Object.create(AccountsModule.prototype);
        mod.app = { settings };
        return mod;
    }

    it('shows the budget period under both month tiles', () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 9, 17));
        renderTiles();

        makeAccounts({ budget_start_day: '25' })._updateMetricPeriodHints();

        const expected = getPeriodDateRange('monthly', 25, at(2026, 9, 17)).label;
        const hints = Array.from(document.querySelectorAll('.metric-period')).map(h => h.textContent);
        expect(hints).toEqual([expected, expected]);
        expect(document.getElementById('avg-transaction').parentElement.querySelector('.metric-period')).toBeNull();
    });

    it('adds nothing for calendar months and clears an earlier hint', () => {
        renderTiles();
        makeAccounts({ budget_start_day: '25' })._updateMetricPeriodHints();

        makeAccounts({})._updateMetricPeriodHints();

        expect(document.querySelectorAll('.metric-period')).toHaveLength(0);
    });
});
