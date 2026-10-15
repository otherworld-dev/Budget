/**
 * Income targets on the dashboard. The Budget remaining hero added an
 * unreceived salary's target to what was left to spend (3140 instead of
 * 140 until payday), and the Budget Progress tile showed a salary that
 * had fully arrived in red, as over budget once more came in.
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

const groceries = { categoryId: 1, categoryName: 'Groceries', type: 'expense', budgeted: 165, spent: 25 };
const salary = { categoryId: 2, categoryName: 'Salary', type: 'income', budgeted: 3000, spent: 3100 };

beforeEach(() => {
    document.body.innerHTML = `
        <div id="hero-budget-remaining-value"></div>
        <div id="hero-budget-remaining-change"></div>
        <div id="budget-progress"></div>
    `;
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('income targets on the dashboard', () => {
    it('leaves income out of the budget remaining', () => {
        makeDashboard().updateBudgetRemainingHero({ categories: [groceries, { ...salary, spent: 0 }] });

        expect(document.getElementById('hero-budget-remaining-value').textContent).toBe('£140.00');
        expect(document.getElementById('hero-budget-remaining-change').textContent).toBe('1 category under budget');
    });

    it('shows income that has arrived as on track, not over budget', () => {
        makeDashboard().updateBudgetProgressWidget([groceries, salary]);

        const fills = [...document.querySelectorAll('.budget-progress-fill')];
        expect(fills[1].classList.contains('good')).toBe(true);
        expect(document.getElementById('budget-progress').textContent).not.toContain('Over budget');
    });
});
