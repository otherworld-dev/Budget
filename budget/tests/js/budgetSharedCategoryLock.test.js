/**
 * Budget page rows for categories shared with you. Their budget is the
 * owner's, so without Full control the amount, period and envelope controls
 * are read-only rather than failing on save; with it, an edit goes to the
 * owner's budget for the month shown.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

const apiFetch = vi.fn();
vi.mock('../../src/utils/api.js', () => ({
    apiFetch: (...args) => apiFetch(...args),
    ApiError: class ApiError extends Error {},
}));

import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';

function makeModule(categories) {
    const mod = Object.create(CategoriesModule.prototype);
    mod.app = { settings: {} };
    mod.categorySpending = {};
    mod._ownSpending = {};
    mod.budgetMonth = '2026-08';
    mod._getEffectiveBudgetAmount = (_id, amount) => amount;
    mod._getEffectiveBudgetPeriod = (_id, period) => period || 'monthly';
    mod._getRecurringBudgetAmount = () => 0;
    mod._getRolloverEnabled = () => false;
    mod._getCarriedAmount = () => 0;
    mod._getEffectiveBudgetForCalc = (_id, amount) => amount;
    mod.formatCurrency = (v) => '£' + Number(v).toFixed(2);
    mod.findCategoryById = (id) => categories.find(c => c.id === id);
    return mod;
}

const base = { name: 'Food', type: 'expense', budgetAmount: 100, budgetPeriod: 'monthly', children: [] };
const own = { ...base, id: 1 };
const writeShared = { ...base, id: 2, _shared: true, _canWrite: true, _canManage: false };
const fullShared = { ...base, id: 3, _shared: true, _canWrite: true, _canManage: true };

const inputOf = (html) => html.match(/<input type="number"[\s\S]*?>/)[0];
const selectOf = (html) => html.match(/<select class="budget-period-select"[^>]*>/)[0];
const rolloverOf = (html) => html.match(/<button class="budget-rollover-toggle[\s\S]*?>/)[0];

describe('Budget page controls on shared categories', () => {
    it('are read-only on a category shared at Read & Write', () => {
        const html = makeModule([writeShared]).renderBudgetCategoryNodes([writeShared], 0);
        expect(inputOf(html)).toContain('disabled');
        expect(selectOf(html)).toContain('disabled');
        expect(rolloverOf(html)).toContain('disabled');
    });

    it('stay editable on your own and Full control categories', () => {
        for (const category of [own, fullShared]) {
            const html = makeModule([category]).renderBudgetCategoryNodes([category], 0);
            expect(inputOf(html)).not.toContain('disabled');
            expect(selectOf(html)).not.toContain('disabled');
            expect(rolloverOf(html)).not.toContain('disabled');
        }
    });
});

describe('saveCategoryBudget on a Full control category', () => {
    beforeEach(() => apiFetch.mockReset());

    function saving(category) {
        const mod = makeModule([category]);
        mod._currentMonthHasSnapshot = true;
        mod._effectiveBudgets = {};
        mod.fetchEffectiveBudgets = async () => {};
        mod.calculateCategorySpending = async () => {};
        mod.renderBudgetTree = () => {};
        mod.updateBudgetSummary = () => {};
        return mod;
    }

    it('goes to the owner\'s budget for the month, never your own adjustment', async () => {
        apiFetch.mockResolvedValue({ target: 'adjustment' });
        const category = { ...fullShared };

        await saving(category).saveCategoryBudget(3, { budgetAmount: '50' });

        expect(apiFetch).toHaveBeenCalledTimes(1);
        expect(apiFetch.mock.calls[0][0]).toBe('/apps/budget/api/categories/3/budget/2026-08');
        expect(apiFetch.mock.calls[0][1].body).toEqual({ amount: 50 });
        // An adjustment isn't the category's own budget
        expect(category.budgetAmount).toBe(100);
    });

    it('updates the local category when the owner had no adjustment', async () => {
        apiFetch.mockResolvedValue({ target: 'category' });
        const category = { ...fullShared };

        await saving(category).saveCategoryBudget(3, { budgetAmount: '50' });

        expect(category.budgetAmount).toBe(50);
    });

    it('leaves your own categories on your own adjustment', async () => {
        apiFetch.mockResolvedValue({});

        await saving({ ...own }).saveCategoryBudget(1, { budgetAmount: '50' });

        expect(apiFetch.mock.calls[0][0]).toBe('/apps/budget/api/budget-snapshots/2026-08/categories/1');
    });
});
