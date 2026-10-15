/**
 * Two budget tiles that are off by default didn't work when switched on.
 * Budget Breakdown left its Category column empty: it read `name`, and the
 * budget report sends `categoryName`. The Budget Health hero always read
 * "--": it counted the rows of an element that doesn't exist. It counts the
 * report's spending budgets now.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural).replace('%n', count),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

function makeDashboard(tileSettings = {}) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: { default_currency: 'GBP' },
        accounts: [],
        categories: [],
        dashboardConfig: { widgets: { tileSettings } },
        getPrimaryCurrency: () => 'GBP',
    };
    return mod;
}

const REPORT = [
    { categoryId: 1, categoryName: 'Groceries', type: 'expense', budgeted: 300, spent: 155.25 },
    { categoryId: 2, categoryName: 'Fuel', type: 'expense', budgeted: 80, spent: 40 },
    { categoryId: 3, categoryName: 'Gym', type: 'expense', budgeted: 108.33, spent: 20 },
    { categoryId: 4, categoryName: 'Holidays', type: 'expense', budgeted: 200, spent: 120 },
    { categoryId: 5, categoryName: 'Car', type: 'expense', budgeted: 100, spent: 0 },
    { categoryId: 6, categoryName: 'Salary', type: 'income', budgeted: 2000, spent: -2000 },
];

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('the Budget Breakdown tile', () => {
    it('names each category', () => {
        document.body.innerHTML = '<div id="budget-breakdown-table"></div>';

        makeDashboard().updateBudgetBreakdownWidget(REPORT.slice(0, 2));

        const names = [...document.querySelectorAll('#budget-breakdown-table tbody tr')]
            .map(tr => tr.querySelector('td').textContent.trim());
        expect(names).toEqual(['Groceries', 'Fuel']);
    });
});

describe('the Budget Health hero', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="hero-budget-health-value"></div><div id="hero-budget-health-change"></div>';
    });

    const value = () => document.getElementById('hero-budget-health-value').textContent;
    const change = () => document.getElementById('hero-budget-health-change').textContent;

    it('counts the spending budgets in the budget report', () => {
        makeDashboard().updateBudgetHealthHero([], { categories: REPORT });

        expect(value()).toBe('100%');
        expect(change()).toBe('5/5 on track');
    });

    it('takes the alerted budgets off', () => {
        makeDashboard().updateBudgetHealthHero([{ categoryId: 1 }], { categories: REPORT });

        expect(value()).toBe('80%');
        expect(change()).toBe('4/5 on track');
    });

    it('shows -- without any spending budget', () => {
        makeDashboard().updateBudgetHealthHero([], { categories: [REPORT[5]] });

        expect(value()).toBe('--');
    });
});
