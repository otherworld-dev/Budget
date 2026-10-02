/**
 * The Budget page's "auto" budgets (#269) came from today's bills whatever
 * month was shown, so a subscription ending this month still budgeted next
 * month and its replacement didn't. They are now fetched for the month on
 * screen, again whenever the month changes.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';

const ok = (body) => ({ ok: true, json: async () => body });

function makeModule() {
    const mod = Object.create(CategoriesModule.prototype);
    mod.app = { settings: {}, getAuthHeaders: () => ({}) };
    return mod;
}

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
});

afterEach(() => {
    vi.restoreAllMocks();
    delete global.fetch;
    delete global.OC;
});

describe('recurring budgets for the month shown', () => {
    it('asks for the month the page shows', async () => {
        global.fetch = vi.fn(async () => ok({ budgets: { 7: 15 } }));
        const mod = makeModule();
        mod.budgetMonth = '2026-11';

        await mod.fetchRecurringBudgets();

        expect(global.fetch.mock.calls[0][0]).toBe('/apps/budget/api/categories/recurring-budgets?month=2026-11');
        expect(mod._recurringBudgets).toEqual({ 7: 15 });
    });

    it('fetches them again when the month changes', async () => {
        const mod = makeModule();
        const calls = [];
        mod.fetchEffectiveBudgets = vi.fn(async () => calls.push('effective'));
        mod.fetchRecurringBudgets = vi.fn(async () => calls.push(`recurring ${mod.budgetMonth}`));
        mod.calculateCategorySpending = vi.fn(async () => {});
        mod.renderBudgetTree = vi.fn();
        mod.updateBudgetSummary = vi.fn();
        mod.renderSnapshotControls = vi.fn();

        await mod.changeBudgetMonth('2026-12');

        expect(calls).toContain('recurring 2026-12');
        expect(mod.renderBudgetTree).toHaveBeenCalled();
    });
});
