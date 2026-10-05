/**
 * Weekly, quarterly and yearly budgets on the monthly Budget page.
 *
 * The page measured each budget over its own period: a weekly one over the
 * week holding the month's 15th, so on 2 October a gym budget of 20 a week
 * showed nothing spent although 10 went this week, and a yearly one over
 * the whole year, so a car budget of 1,200 counted June's 400 in October's
 * Spent card against October's 100. Every row now counts the month's
 * spending against its budget's share of the month (weekly x 52 / 12,
 * quarterly / 3, yearly / 12), and a quarterly or yearly row also says how
 * much of its whole period has gone.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
    getLanguage: () => 'en',
    getLocale: () => 'en',
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

const apiFetch = vi.fn();
vi.mock('../../src/utils/api.js', () => ({
    apiFetch: (...args) => apiFetch(...args),
    ApiError: class ApiError extends Error {},
}));

import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';
import { periodToDateRange } from '../../src/utils/formatters.js';

const category = (id, name, budgetAmount, budgetPeriod, extra = {}) => ({
    id, name, type: 'expense', budgetAmount, budgetPeriod, parentId: null, children: [], ...extra,
});

/**
 * R7's s6: Gym weekly 20 with 10 spent on 2 October, Car yearly 1,200 with
 * 400 spent in June and a monthly Fuel of 100 under it, and Groceries
 * monthly 400.
 */
const tree = () => [
    category(1, 'Gym', 20, 'weekly'),
    category(2, 'Car', 1200, 'yearly', {
        children: [category(3, 'Fuel', 100, 'monthly', { parentId: 2 })],
    }),
    category(4, 'Groceries', 400, 'monthly'),
    category(5, 'Holiday', 900, 'quarterly'),
];

/** Spending the server holds, by date, for the spending endpoint to sum. */
const TRANSACTIONS = [
    { categoryId: 2, date: '2026-06-01', amount: 400 },
    { categoryId: 4, date: '2026-03-05', amount: 70 },
    { categoryId: 3, date: '2026-09-10', amount: 40 },
    { categoryId: 5, date: '2026-09-10', amount: 200 },
    { categoryId: 1, date: '2026-10-02', amount: 10 },
    { categoryId: 3, date: '2026-10-03', amount: 60 },
    { categoryId: 4, date: '2026-10-03', amount: 50 },
    { categoryId: 5, date: '2026-10-01', amount: 300 },
];

let requests;
let budgets;

function serve() {
    apiFetch.mockImplementation(async (url, options = {}) => {
        requests.push(url);
        if (options.method === 'PUT') {
            const id = parseInt(url.split('/').pop(), 10);
            if ('budgetPeriod' in options.body) budgets[id].period = options.body.budgetPeriod;
            if ('budgetAmount' in options.body) budgets[id].amount = budgets[id].available = options.body.budgetAmount;
            return {};
        }
        if (/\/budget-snapshots\/\d{4}-\d{2}\/budgets$/.test(url)) {
            return { budgets: structuredClone(budgets), hasSnapshot: false, readyToAssign: null };
        }
        if (url.endsWith('/budget-snapshots')) return [];
        if (url.includes('/categories/spending')) {
            const query = new URLSearchParams(url.split('?')[1]);
            if (query.get('transactionType') !== 'debit') return [];
            const totals = {};
            for (const tx of TRANSACTIONS) {
                if (tx.date >= query.get('startDate') && tx.date <= query.get('endDate')) {
                    totals[tx.categoryId] = (totals[tx.categoryId] || 0) + tx.amount;
                }
            }
            return Object.entries(totals).map(([categoryId, spent]) => ({ categoryId: Number(categoryId), spent }));
        }
        throw new Error('unexpected ' + url);
    });
}

async function openBudgetPage(month = '2026-10') {
    const mod = Object.create(CategoriesModule.prototype);
    mod.app = { settings: { budget_start_day: '1' }, categories: [] };
    mod.categoryTree = tree();
    mod.budgetType = 'expense';
    mod.budgetMonth = month;
    mod._recurringBudgets = {};
    mod.formatCurrency = (v) => '£' + Number(v).toFixed(2);
    await mod.fetchEffectiveBudgets();
    await mod.calculateCategorySpending();
    mod.renderBudgetTree();
    mod.updateBudgetSummary();
    return mod;
}

const row = (id) => document.querySelector(`.budget-category-row[data-category-id="${id}"]`);
const cell = (id, selector) => row(id)?.querySelector(selector)?.textContent.replace(/\s+/g, ' ').trim();
const card = (key) => document.getElementById(`budget-total-${key}`).textContent;

beforeEach(() => {
    window.location.hash = '#budget';
    requests = [];
    budgets = {
        1: { amount: 20, period: 'weekly', available: 20, carried: 0, rollover: false },
        2: { amount: 1200, period: 'yearly', available: 1200, carried: 0, rollover: false },
        3: { amount: 100, period: 'monthly', available: 100, carried: 0, rollover: false },
        4: { amount: 400, period: 'monthly', available: 400, carried: 0, rollover: false },
        5: { amount: 900, period: 'quarterly', available: 900, carried: 0, rollover: false },
    };
    serve();
    document.body.innerHTML = `
        <div class="budget-tree-header"></div>
        <div id="budget-tree"></div>
        <div id="empty-budget"></div>
        <span id="budget-total-budgeted"></span>
        <span id="budget-total-spent"></span>
        <span id="budget-total-remaining"></span>
        <span id="budget-categories-count"></span>
        <span id="budget-ready-to-assign"></span>`;
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('a weekly budget on the Budget page', () => {
    it("counts the month's spending against 52 weeks over 12", async () => {
        await openBudgetPage();

        // 20 a week is 86.67 a month; 10 went on 2 October
        expect(cell(1, '.budget-spent')).toBe('£10.00');
        expect(cell(1, '.budget-remaining')).toBe('£76.67');
        expect(cell(1, '.budget-progress-text')).toBe('12%');
        expect(cell(1, '.budget-monthly-hint')).toBe('£86.67 a month');
        // The input still holds the weekly amount
        expect(row(1).querySelector('.budget-input').value).toBe('20');
        expect(row(1).querySelector('.budget-period-to-date')).toBeNull();
    });
});

describe('a yearly budget on the Budget page', () => {
    it("counts only this month's spending and shows the year so far", async () => {
        await openBudgetPage();

        // Car: 100 a month, plus Fuel's 100 under it; October's spending is Fuel's 60
        expect(cell(2, '.budget-spent')).toBe('£60.00');
        expect(cell(2, '.budget-remaining')).toBe('£140.00');
        expect(cell(2, '.budget-aggregate-hint')).toBe('Total: £200.00 a month');
        // The year so far: Car's 400 in June and Fuel's 40 + 60, against
        // 1,200 + 12 x 100
        expect(cell(2, '.budget-period-to-date')).toBe('£500.00 of £2400.00 this year');
        expect(row(2).querySelector('.budget-period-to-date').title).toContain('2026');
    });

    it('shows the year so far on a row of its own', async () => {
        budgets[3].amount = budgets[3].available = 0;
        await openBudgetPage();

        expect(cell(2, '.budget-period-to-date')).toBe('£500.00 of £1200.00 this year');
        expect(cell(2, '.budget-monthly-hint')).toBe('£100.00 a month');
    });

    it("leaves June's spending out of October's cards", async () => {
        await openBudgetPage();

        // Budgeted: 86.67 + 100 + 100 + 400 + 300. Spent: 10 + 60 + 50 + 300
        expect(card('budgeted')).toBe('£986.67');
        expect(card('spent')).toBe('£420.00');
        expect(card('remaining')).toBe('£566.67');
    });

    it('ends the year so far with the month shown', async () => {
        await openBudgetPage('2026-06');

        expect(cell(2, '.budget-spent')).toBe('£400.00');
        expect(cell(2, '.budget-period-to-date')).toBe('£400.00 of £2400.00 this year');
    });
});

describe('a quarterly budget on the Budget page', () => {
    it('shows a third of it for the month and the quarter so far', async () => {
        await openBudgetPage();

        // October opens the October to December quarter: September's 200 is
        // the quarter before
        expect(cell(5, '.budget-spent')).toBe('£300.00');
        expect(cell(5, '.budget-remaining')).toBe('£0.00');
        expect(cell(5, '.budget-period-to-date')).toBe('£300.00 of £900.00 this quarter');
    });
});

describe('the spending the page asks for', () => {
    it('is the month for every row, and the quarter and year so far', async () => {
        await openBudgetPage();

        const spending = requests.filter(url => url.includes('/categories/spending'))
            .map(url => new URLSearchParams(url.split('?')[1]).get('startDate') + '..' + new URLSearchParams(url.split('?')[1]).get('endDate'));
        // October, which is also the quarter so far, and the year so far;
        // never the week holding the 15th or the whole year
        expect(spending).toEqual(['2026-10-01..2026-10-31', '2026-01-01..2026-10-31']);
    });

    it("stays the month's after the period is changed", async () => {
        await openBudgetPage();
        expect(cell(4, '.budget-spent')).toBe('£50.00');

        // Groceries from monthly to yearly: 400 a month is 4,800 a year
        const select = row(4).querySelector('.budget-period-select');
        select.value = 'yearly';
        select.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(cell(4, '.budget-period-to-date')).toBe('£120.00 of £4800.00 this year'));
        await new Promise(resolve => setTimeout(resolve, 0));

        // October's 50, not the year's 120 (March's 70 as well)
        expect(cell(4, '.budget-spent')).toBe('£50.00');
        expect(cell(4, '.budget-remaining')).toBe('£350.00');
    });
});

describe('periodToDateRange', () => {
    it('runs from the first budget month of the year or quarter to the end of the month', () => {
        expect(periodToDateRange('yearly', '2026-10', 1)).toMatchObject({ start: '2026-01-01', end: '2026-10-31' });
        expect(periodToDateRange('quarterly', '2026-11', 1)).toMatchObject({ start: '2026-10-01', end: '2026-11-30' });
        expect(periodToDateRange('quarterly', '2026-09', 1)).toMatchObject({ start: '2026-07-01', end: '2026-09-30' });
    });

    it('is made of whole budget months with a start day', () => {
        // January 2026 with the 25th began on 25 December
        expect(periodToDateRange('yearly', '2026-10', 25)).toMatchObject({ start: '2025-12-25', end: '2026-10-24' });
        expect(periodToDateRange('quarterly', '2026-11', 10)).toMatchObject({ start: '2026-10-10', end: '2026-12-09' });
    });

    it('is null for a weekly or monthly budget', () => {
        expect(periodToDateRange('weekly', '2026-10', 1)).toBeNull();
        expect(periodToDateRange('monthly', '2026-10', 1)).toBeNull();
    });
});
