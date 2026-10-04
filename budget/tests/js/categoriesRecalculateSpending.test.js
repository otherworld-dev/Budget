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

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('calculateCategorySpending', () => {
    it('ignores an older month request that finishes last', async () => {
        const mod = Object.create(CategoriesModule.prototype);
        mod.app = {
            settings: {},
            categoryTree: [{ id: 7, type: 'expense', budgetPeriod: 'monthly', children: [] }],
        };
        mod.budgetMonth = '2026-05';

        const pending = [];
        global.fetch = vi.fn(() => new Promise(resolve => pending.push(resolve)));

        const may = mod.calculateCategorySpending();
        mod.budgetMonth = '2026-06';
        const june = mod.calculateCategorySpending();

        pending[1]({ ok: true, json: async () => [{ categoryId: 7, spent: 20 }] });
        await june;
        pending[0]({ ok: true, json: async () => [{ categoryId: 7, spent: 10 }] });
        await may;

        expect(mod.categorySpending[7]).toBe(20);
    });
});

describe('the spending a budget period change reloads', () => {
    // A period change saves and reloads the page's spending through
    // calculateCategorySpending(), which no longer depends on the period:
    // every row is the selected month's, in its category's direction
    function load(categoryTree, responses) {
        const mod = Object.create(CategoriesModule.prototype);
        mod.app = { settings: {}, categoryTree };
        mod.categoryTree = categoryTree;
        mod.budgetMonth = '2026-05';
        global.fetch = vi.fn(async (url) => ({ ok: true, json: async () => responses(url) }));
        return mod;
    }

    it('asks for the credit direction for an income category', async () => {
        const mod = load([{ id: 3, type: 'income', budgetPeriod: 'yearly', children: [] }],
            (url) => (url.includes('transactionType=credit') ? [{ categoryId: 3, spent: 3000 }] : []));

        await mod.calculateCategorySpending();

        expect(global.fetch.mock.calls.every(([url]) => url.includes('transactionType=credit'))).toBe(true);
        expect(mod.categorySpending[3]).toBe(3000);
    });

    it('asks for the debit direction for an expense category', async () => {
        const mod = load([{ id: 7, type: 'expense', budgetPeriod: 'weekly', children: [] }], () => []);

        await mod.calculateCategorySpending();

        expect(global.fetch.mock.calls[0][0]).toContain('transactionType=debit');
    });

    it('scopes the window to the selected budget month whatever the period', async () => {
        const mod = load([{ id: 7, type: 'expense', budgetPeriod: 'weekly', children: [] }], () => []);

        await mod.calculateCategorySpending();

        expect(global.fetch.mock.calls[0][0]).toContain('startDate=2026-05-01');
        expect(global.fetch.mock.calls[0][0]).toContain('endDate=2026-05-31');
    });
});
