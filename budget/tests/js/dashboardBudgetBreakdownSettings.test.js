/**
 * The Budget Breakdown tile had no settings (#480): it always showed the
 * current budget period for every account, though it reads the same budget
 * report as Budget Progress, which can be scoped. It now takes the same
 * settings. Both tiles also default to the current budget period, which is
 * what they show before anything is chosen; the default used to be 6 months,
 * so saving only an account quietly switched the tile to 6 months.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';
import { DASHBOARD_WIDGETS } from '../../src/config/dashboardWidgets.js';

const at = (y, m, d) => new Date(y, m - 1, d);

let requested;

function makeDashboard(tileSettings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: { default_currency: 'GBP' },
        dashboardConfig: { widgets: { tileSettings } },
        widgetData: {},
        widgetDataLoaded: {},
        accounts: [],
        categories: [],
        getPrimaryCurrency: () => 'GBP',
    };
    return mod;
}

beforeEach(() => {
    requested = [];
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    global.fetch = vi.fn(async (url) => {
        requested.push(url);
        return { ok: true, json: async () => ({ categories: [] }) };
    });
});

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
});

describe('Budget Breakdown settings', () => {
    it('offers the same settings as Budget Progress', () => {
        expect(DASHBOARD_WIDGETS.widgets.budgetBreakdown.settingsSchema)
            .toEqual(DASHBOARD_WIDGETS.widgets.budgetProgress.settingsSchema);
    });

    it('fetches the budget report for its own range, account and shared choice', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 11, 10));
        const dash = makeDashboard({
            budgetBreakdown: { dateRange: '90d', accountId: 7, excludeShared: true },
            budgetProgress: { accountId: 2 },
        });
        dash.updateBudgetBreakdownWidget = vi.fn();

        await dash.refreshBudgetBreakdownWidget('budgetBreakdown');

        expect(requested).toHaveLength(1);
        expect(requested[0]).toContain('/reports/budget?startDate=2026-08-12&endDate=2026-11-10');
        expect(requested[0]).toContain('accountId=7');
        expect(requested[0]).toContain('excludeShared=1');
        expect(requested[0]).not.toContain('snapshotMonth');
        expect(dash.updateBudgetBreakdownWidget).toHaveBeenCalledWith([]);
    });

    it('is re-fetched after a reload when it has saved settings', async () => {
        const dash = makeDashboard({ budgetBreakdown: { accountId: 3 } });
        dash.refreshBudgetBreakdownWidget = vi.fn(async () => {});

        await dash.refreshSavedWidgetSelections();

        expect(dash.refreshBudgetBreakdownWidget).toHaveBeenCalledWith('budgetBreakdown');
    });

    it('is re-fetched when a setting changes', () => {
        const dash = makeDashboard({});
        dash.refreshBudgetBreakdownWidget = vi.fn(async () => {});

        dash.refreshTileAfterSettingsChange('budgetBreakdown', 'widgets');

        expect(dash.refreshBudgetBreakdownWidget).toHaveBeenCalledWith('budgetBreakdown');
    });

    it('rolls subcategories up into their parent with its own top-level setting', () => {
        document.body.innerHTML = '<div id="budget-breakdown-table"></div>';
        const dash = makeDashboard({ budgetBreakdown: { topLevelOnly: true } });
        dash.app.categories = [
            { id: 1, name: 'Food', parentId: null },
            { id: 2, name: 'Groceries', parentId: 1 },
            { id: 3, name: 'Takeaway', parentId: 1 },
        ];

        dash.updateBudgetBreakdownWidget([
            { categoryId: 2, categoryName: 'Groceries', type: 'expense', budgeted: 300, spent: 100 },
            { categoryId: 3, categoryName: 'Takeaway', type: 'expense', budgeted: 50, spent: 20 },
        ]);

        const rows = [...document.querySelectorAll('#budget-breakdown-table tbody tr')]
            .map(tr => tr.querySelector('td').textContent.trim());
        expect(rows).toEqual(['Food']);
    });

    it("ignores Budget Progress's top-level setting", () => {
        document.body.innerHTML = '<div id="budget-breakdown-table"></div>';
        const dash = makeDashboard({ budgetProgress: { topLevelOnly: true } });
        dash.app.categories = [
            { id: 1, name: 'Food', parentId: null },
            { id: 2, name: 'Groceries', parentId: 1 },
        ];

        dash.updateBudgetBreakdownWidget([
            { categoryId: 2, categoryName: 'Groceries', type: 'expense', budgeted: 300, spent: 100 },
        ]);

        const rows = [...document.querySelectorAll('#budget-breakdown-table tbody tr')]
            .map(tr => tr.querySelector('td').textContent.trim());
        expect(rows).toEqual(['Groceries']);
    });
});

describe('the budget tiles default to the current budget period', () => {
    it.each(['budgetProgress', 'budgetBreakdown'])('%s defaults to period', (id) => {
        expect(makeDashboard()._defaultDateRange(id)).toBe('period');
    });

    it.each([
        ['budgetProgress', 'refreshBudgetProgressWidget'],
        ['budgetBreakdown', 'refreshBudgetBreakdownWidget'],
    ])('%s keeps the budget period when only an account is saved', async (id, method) => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 11, 10));
        const dash = makeDashboard({ [id]: { accountId: 4 } });
        dash.app.settings.budget_start_day = '25';
        dash.updateBudgetProgressWidget = vi.fn();
        dash.updateBudgetBreakdownWidget = vi.fn();

        await dash[method](id);

        expect(requested[0]).toContain('startDate=2026-10-25');
        expect(requested[0]).toContain('endDate=2026-11-24');
        expect(requested[0]).toContain('snapshotMonth=2026-11');
        expect(requested[0]).toContain('accountId=4');
    });
});
