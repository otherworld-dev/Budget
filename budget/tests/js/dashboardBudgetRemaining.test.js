/**
 * The dashboard's Budget remaining counts a weekly or yearly budget's share
 * of the month. It took the whole yearly 600 as left this month (and a
 * weekly 50 as a month's budget), so it read 950 where the Budget page's
 * Remaining card for the same month read 466.67.
 *
 * The budget report now sends each budget as its share of the range asked
 * for, so the hero takes the figure as it comes: turning it monthly again
 * made a yearly 600's 50 for the month 4.17.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural).replace('%n', count),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

function makeDashboard() {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: { default_currency: 'GBP' },
        accounts: [],
        categories: [],
        dashboardConfig: { widgets: { tileSettings: {} } },
        getPrimaryCurrency: () => 'GBP',
    };
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="hero-budget-remaining-value"></div>
        <div id="hero-budget-remaining-change"></div>
    `;
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('budget remaining on the dashboard', () => {
    it('takes a weekly or yearly budget as the share of the month the report sends', () => {
        makeDashboard().updateBudgetRemainingHero({ categories: [
            { categoryName: 'Insurance', type: 'expense', period: 'yearly', budgeted: 50, spent: 50 },
            { categoryName: 'Transport', type: 'expense', period: 'weekly', budgeted: 216.67, spent: 10 },
            { categoryName: 'Bills', type: 'expense', period: 'monthly', budgeted: 150, spent: 40 },
            { categoryName: 'Food', type: 'expense', period: 'monthly', budgeted: 300, spent: 50 },
            { categoryName: 'Groceries', type: 'expense', period: 'monthly', budgeted: 200, spent: 260 },
        ] });

        // 0 + (216.67 - 10) + 110 + 250, and Groceries is over budget
        expect(document.getElementById('hero-budget-remaining-value').textContent).toBe('£566.67');
        expect(document.getElementById('hero-budget-remaining-change').textContent).toBe('3 categories under budget');
    });

    it('takes a row without a period as monthly', () => {
        makeDashboard().updateBudgetRemainingHero({ categories: [
            { categoryName: 'Food', type: 'expense', budgeted: 165, spent: 25 },
        ] });

        expect(document.getElementById('hero-budget-remaining-value').textContent).toBe('£140.00');
    });
});
