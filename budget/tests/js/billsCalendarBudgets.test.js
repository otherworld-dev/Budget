/**
 * Budgets in the Bills Calendar (#459). A budget row on the Budget page has a
 * toggle that puts it in the Bills Calendar, where it is a row of its own,
 * its month's share in each month, drawn apart from the bills as an
 * estimate. The report's Include budgeted spending checkbox asks for them.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { RULES } from './helpers/stylesheet.js';

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

import { calendarCell, budgetTotalsByMonth } from '../../src/utils/billsCalendarCell.js';
import ReportsModule from '../../src/modules/reports/ReportsModule.js';
import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';

const months = list => Object.fromEntries(Array.from({ length: 12 }, (_, i) => [i + 1, list.includes(i + 1)]));
const all = Array.from({ length: 12 }, (_, i) => i + 1);

// As BillService::budgetCalendarRows() sends it
const food = {
    id: null, isBudget: true, name: 'Lebensmittel', amount: 700, currency: 'CHF', frequency: 'monthly',
    categoryId: 9, occurrences: months(all),
    paidMonths: [], paidAmounts: [], paidDetails: [], paidMoreThanOnce: [], paidElsewhere: [],
    unrecordedMonths: [], owedAmounts: [], inTotals: true,
    expectedAmounts: Object.fromEntries(all.map(m => [m, 700])),
};
const coffee = {
    ...food, name: 'Coffee', categoryId: 10, amount: 20, frequency: 'weekly',
    occurrences: months([7, 8, 9]),
    expectedAmounts: { 7: 86.67, 8: 86.67, 9: 86.67 },
};
const rent = {
    name: 'Miete', amount: 1500, currency: 'CHF', frequency: 'monthly',
    occurrences: months(all),
    paidMonths: [], paidAmounts: {}, paidDetails: {}, paidMoreThanOnce: {},
    unrecordedMonths: [], expectedAmounts: {}, owedAmounts: [], inTotals: true,
};

describe('a budget row\'s cells', () => {
    it('are budget estimates of the month\'s share, never due', () => {
        const cell = calendarCell(food, 3, {}, 'CHF');
        expect(cell.state).toBe('budget');
        expect(cell.amount).toBe(700);
        expect(cell.title).toBe('Budgeted spending (estimate)');
        expect(cell.marker).toBeNull();
    });

    it('are empty in a month the budget has nothing for', () => {
        expect(calendarCell(coffee, 3).state).toBe('none');
        expect(calendarCell(coffee, 7).amount).toBe(86.67);
    });
});

describe('budgetTotalsByMonth', () => {
    it('adds up the budget rows of each month, and leaves the bills out', () => {
        const totals = budgetTotalsByMonth([rent, food, coffee]);
        expect(totals[1]).toBe(700);
        expect(totals[7]).toBe(786.67);
        expect(Object.keys(totals)).toHaveLength(12);
    });

    it('adds tenths of a franc without float drift', () => {
        const thirds = { ...food, expectedAmounts: Object.fromEntries(all.map(m => [m, 0.1])) };
        const totals = budgetTotalsByMonth([thirds, thirds, thirds]);
        expect(totals[5]).toBe(0.3);
    });

    it('is all zero with no budget rows', () => {
        expect(Object.values(budgetTotalsByMonth([rent])).every(v => v === 0)).toBe(true);
        expect(budgetTotalsByMonth(undefined)[1]).toBe(0);
    });
});

function reports() {
    const mod = Object.create(ReportsModule.prototype);
    mod.app = { accounts: [], settings: {} };
    mod.reportCharts = {};
    mod.getPrimaryCurrency = () => 'CHF';
    mod.formatCurrency = (v, c) => `${Number(v).toFixed(2)} ${c}`;
    mod.renderBillsCalendarChart = vi.fn();
    return mod;
}

describe('the Bills Calendar report', () => {
    beforeEach(() => {
        apiFetch.mockReset();
        document.body.innerHTML = `
            <div id="report-loading"></div>
            <select id="bills-calendar-year"><option value="2026" selected>2026</option></select>
            <select id="bills-calendar-account"><option value="">All Accounts</option><option value="4">Giro</option></select>
            <select id="bills-calendar-status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
            <input type="checkbox" id="bills-calendar-include-transfers" checked>
            <input type="checkbox" id="bills-calendar-include-budgets" checked>
            <select id="bills-calendar-view"><option value="table" selected>Table</option><option value="heatmap">Heatmap</option></select>
            <p id="bills-calendar-budget-hint" style="display: none;"></p>
            <div id="bills-calendar-chart-container"></div>
            <div id="bills-calendar-table-container">
                <table><tbody id="bills-calendar-table-body"></tbody><tfoot id="bills-calendar-table-footer"></tfoot></table>
            </div>
            <div id="bills-calendar-heatmap-container" style="display: none;">
                <div id="bills-calendar-heatmap"></div>
            </div>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    const overview = {
        year: 2026,
        bills: [rent, food],
        monthlyTotals: Object.fromEntries(all.map(m => [m, 2200])),
        account: null,
    };

    it('asks for the budgets while the checkbox is ticked, and not once it is cleared', async () => {
        apiFetch.mockResolvedValue(overview);
        const mod = reports();

        await mod.generateBillsCalendar();
        expect(apiFetch.mock.calls[0][0]).toContain('includeBudgets=true');

        document.getElementById('bills-calendar-include-budgets').checked = false;
        await mod.generateBillsCalendar();
        expect(apiFetch.mock.calls[1][0]).toContain('includeBudgets=false');
    });

    it('passes the checkbox to the export', async () => {
        apiFetch.mockResolvedValue(new Blob());
        await reports().exportBillsCalendarReport('csv');
        expect(apiFetch.mock.calls[0][1].body.includeBudgets).toBe('true');
    });

    it('says why there are none for one account or inactive bills', async () => {
        apiFetch.mockResolvedValue(overview);
        const mod = reports();
        const hint = document.getElementById('bills-calendar-budget-hint');

        await mod.generateBillsCalendar();
        expect(hint.style.display).toBe('none');

        document.getElementById('bills-calendar-account').value = '4';
        await mod.generateBillsCalendar();
        expect(hint.style.display).toBe('');

        document.getElementById('bills-calendar-account').value = '';
        document.getElementById('bills-calendar-status').value = 'inactive';
        await mod.generateBillsCalendar();
        expect(hint.style.display).toBe('');

        // Nothing asked for, nothing to explain
        document.getElementById('bills-calendar-include-budgets').checked = false;
        await mod.generateBillsCalendar();
        expect(hint.style.display).toBe('none');
    });

    it('draws a budget row with a Budget badge and estimate cells', async () => {
        apiFetch.mockResolvedValue(overview);
        await reports().generateBillsCalendar();

        const rows = document.querySelectorAll('#bills-calendar-table-body tr');
        expect(rows).toHaveLength(2);
        expect(rows[0].classList.contains('budget-estimate-row')).toBe(false);
        expect(rows[0].querySelector('.budget-estimate-badge')).toBeNull();
        expect(rows[1].classList.contains('budget-estimate-row')).toBe(true);
        expect(rows[1].querySelector('.budget-estimate-badge').textContent).toBe('Budget');
        const cells = rows[1].querySelectorAll('.month-cell');
        expect(cells).toHaveLength(12);
        expect(cells[0].classList.contains('budget-estimate')).toBe(true);
        expect(cells[0].textContent).toBe('700.00 CHF');
        expect(cells[0].title).toBe('Budgeted spending (estimate)');
    });

    it('counts only bills in the heatmap, and says what was budgeted', async () => {
        apiFetch.mockResolvedValue(overview);
        document.getElementById('bills-calendar-view').value = 'heatmap';
        await reports().generateBillsCalendar();

        const box = document.querySelector('.heatmap-month');
        expect(box.querySelector('.heatmap-month-count').textContent).toBe('%n bill');
        expect(box.querySelector('.heatmap-month-total').textContent).toBe('2200.00 CHF');
        expect(box.querySelector('.heatmap-month-budget').textContent).toBe('700.00 CHF budgeted');
    });

    it('styles an estimate apart from a bill', () => {
        const estimate = RULES.find(r => !r.media && r.selectors.some(s => s.includes('.month-cell.has-bill.budget-estimate')));
        expect(estimate).toBeTruthy();
        expect(estimate.declarations['font-style']).toBe('italic');
        expect(estimate.declarations.outline).toMatch(/dashed/);
    });
});

function budgetPage(categories) {
    const mod = Object.create(CategoriesModule.prototype);
    mod.app = { settings: {} };
    mod.categorySpending = {};
    mod._ownSpending = {};
    mod.budgetMonth = '2026-11';
    mod._getEffectiveBudgetAmount = (_id, amount) => amount;
    mod._getEffectiveBudgetPeriod = (_id, period) => period || 'monthly';
    mod._getRecurringBudgetAmount = () => 0;
    mod._getRolloverEnabled = () => false;
    mod._getCarriedAmount = () => 0;
    mod._getEffectiveBudgetForCalc = (_id, amount) => amount;
    mod.formatCurrency = (v) => 'CHF ' + Number(v).toFixed(2);
    mod.categoryTree = categories;
    mod.findCategoryById = (id) => categories.find(c => c.id === id);
    return mod;
}

const toggleOf = (html) => (html.match(/<button class="budget-calendar-toggle[\s\S]*?<\/button>/) || [null])[0];
const groceries = { id: 9, name: 'Lebensmittel', type: 'expense', budgetAmount: 700, budgetPeriod: 'monthly', children: [] };

describe('the Budget page\'s Bills Calendar toggle', () => {
    beforeEach(() => apiFetch.mockReset());

    it('sits on an expense budget, off until turned on', () => {
        const html = budgetPage([groceries]).renderBudgetCategoryNodes([groceries], 0);
        const toggle = toggleOf(html);
        expect(toggle).toContain('data-enabled="0"');
        expect(toggle).toContain('aria-pressed="false"');
        expect(toggle).toContain('title="Show in Bills Calendar"');
        expect(toggle).not.toContain(' active');
    });

    it('shows when it is on', () => {
        const on = { ...groceries, showInCalendar: true };
        const toggle = toggleOf(budgetPage([on]).renderBudgetCategoryNodes([on], 0));
        expect(toggle).toContain('budget-calendar-toggle active');
        expect(toggle).toContain('aria-pressed="true"');
    });

    it('is not offered on an income category, or one with no budget set', () => {
        const salary = { ...groceries, id: 2, type: 'income' };
        const none = { ...groceries, id: 3, budgetAmount: 0 };
        expect(toggleOf(budgetPage([salary]).renderBudgetCategoryNodes([salary], 0))).toBeNull();
        expect(toggleOf(budgetPage([none]).renderBudgetCategoryNodes([none], 0))).toBeNull();
    });

    it('stays offered once on with the budget cleared, so it can be turned off', () => {
        const cleared = { ...groceries, budgetAmount: 0, showInCalendar: true };
        expect(toggleOf(budgetPage([cleared]).renderBudgetCategoryNodes([cleared], 0))).toContain('active');
    });

    it('is read-only on a category shared without Full control', () => {
        const shared = { ...groceries, _shared: true, _canManage: false };
        expect(toggleOf(budgetPage([shared]).renderBudgetCategoryNodes([shared], 0))).toContain('disabled');
    });

    it('saves the flag and redraws the row with it', async () => {
        apiFetch.mockResolvedValue({});
        const category = { ...groceries };
        const mod = budgetPage([category]);
        mod._budgetTree = [{ ...category, children: [] }];
        mod.renderBudgetTree = vi.fn();
        const button = document.createElement('button');
        button.dataset.categoryId = '9';
        button.dataset.enabled = '0';

        await mod.toggleCalendarBudget(button);

        expect(apiFetch).toHaveBeenCalledWith('/apps/budget/api/categories/9', { method: 'PUT', body: { showInCalendar: true } });
        expect(category.showInCalendar).toBe(true);
        expect(mod._budgetTree[0].showInCalendar).toBe(true);
        expect(mod.renderBudgetTree).toHaveBeenCalled();
    });

    it('turns it off again', async () => {
        apiFetch.mockResolvedValue({});
        const category = { ...groceries, showInCalendar: true };
        const mod = budgetPage([category]);
        mod._budgetTree = [category];
        mod.renderBudgetTree = vi.fn();
        const button = document.createElement('button');
        button.dataset.categoryId = '9';
        button.dataset.enabled = '1';

        await mod.toggleCalendarBudget(button);

        expect(apiFetch.mock.calls[0][1].body).toEqual({ showInCalendar: false });
        expect(category.showInCalendar).toBe(false);
    });
});
