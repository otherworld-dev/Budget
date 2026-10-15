/**
 * The Budget page's summary cards add up to the penny.
 *
 * Budgets of different periods are turned monthly before they're added, and
 * a weekly 100 is 433.333... a month. The page summed those fractions and
 * subtracted the spending in floating point, so with 200 monthly, 100
 * weekly, 27.50 yearly and 90 quarterly it showed Budgeted £665.63, Spent
 * £41.10 and Remaining £624.52, a penny below Budgeted − Spent and below
 * what v1's /budget/status answers (624.53). The totals are now rounded to
 * the currency's smallest unit before Remaining is worked out.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';

function makeModule(categories, spending, settings = { default_currency: 'GBP' }) {
    const mod = Object.create(CategoriesModule.prototype);
    mod.app = { settings, categoryTree: categories };
    mod.budgetType = 'expense';
    mod._budgetTree = categories;
    mod._ownSpending = spending;
    mod.categorySpending = {};
    return mod;
}

const expense = (id, budgetAmount, budgetPeriod) => ({ id, type: 'expense', budgetAmount, budgetPeriod, children: [] });

function cards() {
    return ['budgeted', 'spent', 'remaining']
        .map(key => document.getElementById(`budget-total-${key}`).textContent);
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the Budget page summary cards', () => {
    function mount() {
        document.body.innerHTML = `
            <span id="budget-total-budgeted"></span>
            <span id="budget-total-spent"></span>
            <span id="budget-total-remaining"></span>
            <span id="budget-categories-count"></span>`;
    }

    it('show Remaining as Budgeted minus Spent when budget periods mix', () => {
        mount();
        const mod = makeModule(
            [expense(1, 200, 'monthly'), expense(2, 100, 'weekly'), expense(3, 27.5, 'yearly'), expense(4, 90, 'quarterly')],
            { 1: 20.05, 2: 21.05, 3: 0, 4: 0 },
        );

        mod.updateBudgetSummary();

        expect(cards()).toEqual(['£665.63', '£41.10', '£624.53']);
    });

    it('show a budget spent exactly as nothing left, not minus nothing', () => {
        mount();
        const mod = makeModule(
            [expense(1, 300, 'monthly'), expense(2, 241.8, 'monthly'), expense(3, 0, 'monthly')],
            { 1: 118.55, 2: 283.04, 3: 140.21 },
        );

        mod.updateBudgetSummary();

        // 118.55 + 283.04 + 140.21 is 541.8000000000001 in floating point
        expect(cards()).toEqual(['£541.80', '£541.80', '£0.00']);
    });

    it('round to whole units for a currency without minor units', () => {
        mount();
        const mod = makeModule(
            [expense(1, 1000, 'weekly'), expense(2, 500, 'monthly')],
            { 1: 1200, 2: 0 },
            { default_currency: 'JPY' },
        );

        mod.updateBudgetSummary();

        // 1000 weekly is 4333.33 a month: 4833 budgeted, 3633 left
        const [budgeted, spent, remaining] = cards();
        const amount = (s) => Number(s.replace(/[^\d.-]/g, ''));
        expect(amount(remaining)).toBe(amount(budgeted) - amount(spent));
        expect(amount(remaining)).toBe(3633);
    });
});
