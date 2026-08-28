/**
 * Budget bar with net-negative spending (#361).
 *
 * Spent is netted server-side, so a month whose refunds exceed its spending
 * arrives negative. A raw negative percentage would render as
 * `width: -27%` — invalid CSS, dropped by the browser, leaving the fill at
 * auto width, which paints the bar FULL for a category that is actually in
 * credit. The fill must clamp to zero instead.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';

function makeModule(spentByCategory) {
    const mod = Object.create(CategoriesModule.prototype);
    mod.categorySpending = spentByCategory;
    mod._ownSpending = {};
    mod.budgetMonth = '2026-08';
    mod._getEffectiveBudgetAmount = (_id, amount) => amount;
    mod._getEffectiveBudgetPeriod = (_id, period) => period || 'monthly';
    mod._getRecurringBudgetAmount = () => 0;
    mod._getRolloverEnabled = () => false;
    mod._getCarriedAmount = () => 0;
    mod._getEffectiveBudgetForCalc = (_id, amount) => amount;
    mod.formatCurrency = (v) => '£' + Number(v).toFixed(2);
    return mod;
}

const CATEGORY = {
    id: 7, name: 'Phone', type: 'expense',
    budgetAmount: 100, budgetPeriod: 'monthly', children: [],
};

describe('renderBudgetCategoryNodes', () => {
    it('clamps the progress fill to zero when net spending is negative', () => {
        const mod = makeModule({ 7: -50 });

        const html = mod.renderBudgetCategoryNodes([CATEGORY], 0);

        expect(html).toContain('width: 0%');
        expect(html).not.toContain('width: -');
    });

    it('still renders the negative net amount as the Spent figure', () => {
        const mod = makeModule({ 7: -50 });

        const html = mod.renderBudgetCategoryNodes([CATEGORY], 0);

        expect(html).toContain('£-50.00');
    });

    it('leaves ordinary spending untouched', () => {
        const mod = makeModule({ 7: 25 });

        const html = mod.renderBudgetCategoryNodes([CATEGORY], 0);

        expect(html).toContain('width: 25%');
    });
});
