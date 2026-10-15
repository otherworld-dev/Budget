/**
 * With a budget start day, the period running today can be named after last
 * or next calendar month: start day 10 on 5 September is still August's
 * period (10 Aug - 9 Sep), start day 28 on 29 September is already October's
 * (28 Sep - 27 Oct). The Budget page opens on that month and treats it as
 * "now" for the recurring fallback and the projected carryover, the same
 * month the dashboard and the budget alerts use.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

const charts = vi.hoisted(() => []);
vi.mock('../../src/utils/chart.js', () => ({
    default: class {
        constructor(_ctx, config) { charts.push(config); }
        destroy() {}
    },
}));

import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';
import { currentBudgetMonth, shiftMonth } from '../../src/utils/formatters.js';

const at = (y, m, d) => new Date(y, m - 1, d);

function makeModule(startDay) {
    const mod = Object.create(CategoriesModule.prototype);
    mod.app = { settings: startDay ? { budget_start_day: startDay } : {}, getAuthHeaders: () => ({}) };
    return mod;
}

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete global.fetch;
    delete global.OC;
    document.body.innerHTML = '';
});

describe('currentBudgetMonth', () => {
    it('is the calendar month without a start day', () => {
        expect(currentBudgetMonth(1, at(2026, 9, 30))).toBe('2026-09');
    });

    it('is last month before an early start day', () => {
        expect(currentBudgetMonth(10, at(2026, 9, 5))).toBe('2026-08');
        expect(currentBudgetMonth(10, at(2026, 9, 10))).toBe('2026-09');
    });

    it('is next month after a late start day', () => {
        expect(currentBudgetMonth(28, at(2026, 9, 27))).toBe('2026-09');
        expect(currentBudgetMonth(28, at(2026, 9, 29))).toBe('2026-10');
    });

    it('crosses the year end', () => {
        expect(currentBudgetMonth(25, at(2026, 12, 28))).toBe('2027-01');
        expect(currentBudgetMonth(10, at(2027, 1, 5))).toBe('2026-12');
    });
});

describe('shiftMonth', () => {
    it('moves across year ends both ways', () => {
        expect(shiftMonth('2026-01', -1)).toBe('2025-12');
        expect(shiftMonth('2026-12', 1)).toBe('2027-01');
        expect(shiftMonth('2026-10', -12)).toBe('2025-10');
    });
});

/**
 * The Category Details panel counts budget months too: its window starts
 * where a whole period begins, and its chart ends with the period running
 * today, keyed the way the server keys them.
 */
describe('Category Details panel', () => {
    it('opens its window at the start of a budget period', () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 9, 29));

        // Start day 28: this is October's period, so 12 back is October 2025,
        // which began on 28 September 2025
        expect(makeModule('28')._detailWindow(12)).toMatchObject({ startStr: '2025-09-28', endStr: '2026-09-29' });
    });

    it('keeps the calendar window without a start day', () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 9, 17));

        expect(makeModule()._detailWindow(12)).toMatchObject({ startStr: '2025-09-01', endStr: '2026-09-17' });
    });

    it('charts the budget months ending with the running one', () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 9, 29));
        document.body.innerHTML = '<canvas id="category-spending-chart"></canvas><select id="category-chart-period"><option value="3" selected>3</option></select>';
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null);
        const mod = makeModule('28');
        charts.length = 0;

        mod.renderCategorySpendingChartFromServer([
            { month: '2026-09', total: 50 },
            { month: '2026-10', total: 20 },
        ], '#123456');

        const expectedLabels = [8, 9, 10].map(m => new Date(2026, m - 1, 1).toLocaleDateString(undefined, { month: 'short' }));
        expect(charts[0].data.labels).toEqual(expectedLabels);
        expect(charts[0].data.datasets[0].data).toEqual([0, 50, 20]);
    });
});

describe('Budget view opening month', () => {
    function stubLoad(mod) {
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        global.fetch = vi.fn(async () => ({ ok: false }));
        for (const name of ['setupBudgetEventListeners', 'populateBudgetMonthSelector', 'fetchEffectiveBudgets',
            'fetchRecurringBudgets', 'calculateCategorySpending', 'renderBudgetTree', 'updateBudgetSummary',
            'renderSnapshotControls']) {
            mod[name] = vi.fn(async () => {});
        }
    }

    it('opens on the period running today, not the calendar month', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 9, 5));
        const mod = makeModule('10');
        stubLoad(mod);

        await mod.loadBudgetView();

        expect(mod.budgetMonth).toBe('2026-08');
    });

    it('opens on next month once a late start day has passed', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 9, 29));
        const mod = makeModule('28');
        stubLoad(mod);

        await mod.loadBudgetView();

        expect(mod.budgetMonth).toBe('2026-10');
    });

    it('keeps a month the user already picked', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 9, 29));
        const mod = makeModule('28');
        stubLoad(mod);
        mod.budgetMonth = '2026-06';

        await mod.loadBudgetView();

        expect(mod.budgetMonth).toBe('2026-06');
    });

    it('starts with no month until the view loads', () => {
        expect(new CategoriesModule({}).budgetMonth).toBeNull();
    });
});

describe('recurring fallback', () => {
    it('skips a period that has already ended, even in the current calendar month', () => {
        vi.useFakeTimers();
        vi.setSystemTime(at(2026, 9, 29));
        const mod = makeModule('28');
        mod._recurringBudgets = { 7: '120' };

        mod.budgetMonth = '2026-09';
        expect(mod._getRecurringBudgetAmount(7, 'monthly')).toBe(0);

        mod.budgetMonth = '2026-10';
        expect(mod._getRecurringBudgetAmount(7, 'monthly')).toBe(120);
    });
});

describe('snapshot controls', () => {
    const originalTz = process.env.TZ;

    beforeEach(() => {
        document.body.innerHTML = '<div id="budget-snapshot-controls"></div>';
    });

    afterEach(() => {
        if (originalTz === undefined) {
            delete process.env.TZ;
        } else {
            process.env.TZ = originalTz;
        }
    });

    it('names the selected month west of UTC', () => {
        process.env.TZ = 'America/Los_Angeles';
        const mod = makeModule();
        mod.budgetMonth = '2026-09';
        mod._currentMonthHasSnapshot = true;

        mod.renderSnapshotControls();

        const expected = new Date(2026, 8, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        expect(document.getElementById('budget-snapshot-controls').textContent).toContain(`Budgets adjusted from ${expected}`);
    });
});
