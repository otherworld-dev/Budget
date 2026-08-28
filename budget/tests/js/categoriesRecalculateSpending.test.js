/**
 * recalculateCategorySpending() after a budget-period change (#361).
 *
 * The bulk load (calculateCategorySpending) asks for the credit direction
 * on income categories and the debit direction on expense ones. This
 * single-category refresh sent no transactionType at all, which the
 * server's netting now answers debit-primary — for an income category
 * that is the negated sum of its credits, so changing Salary's budget
 * period flipped its Spent to a large negative until a full reload. The
 * refresh must ask for the same direction the bulk load would, and use
 * the selected budget month as its reference date like the bulk load does.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';

function makeModule(categories, spendingResponse) {
    const mod = Object.create(CategoriesModule.prototype);
    mod.app = { settings: {}, categories };
    mod.categorySpending = {};
    mod.budgetMonth = '2026-05';
    mod.renderBudgetTree = vi.fn();
    mod.updateBudgetSummary = vi.fn();
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => spendingResponse });
    return mod;
}

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('recalculateCategorySpending', () => {
    it('asks for the credit direction for an income category', async () => {
        const mod = makeModule([{ id: 3, type: 'income' }], [{ categoryId: 3, spent: 3000 }]);

        await mod.recalculateCategorySpending(3, 'monthly');

        expect(global.fetch.mock.calls[0][0]).toContain('transactionType=credit');
        expect(mod.categorySpending[3]).toBe(3000);
    });

    it('asks for the debit direction for an expense category', async () => {
        const mod = makeModule([{ id: 7, type: 'expense' }], [{ categoryId: 7, spent: 120 }]);

        await mod.recalculateCategorySpending(7, 'monthly');

        expect(global.fetch.mock.calls[0][0]).toContain('transactionType=debit');
    });

    it('scopes the window to the selected budget month like the bulk load', async () => {
        const mod = makeModule([{ id: 7, type: 'expense' }], []);

        await mod.recalculateCategorySpending(7, 'monthly');

        expect(global.fetch.mock.calls[0][0]).toContain('startDate=2026-05-01');
    });
});
