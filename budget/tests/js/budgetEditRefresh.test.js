/**
 * Editing a budget on the Budget page.
 *
 * Typing a new budget left the row's Remaining and %, its parent's total
 * and the summary cards on the old budget (and a first budget showed "No
 * budget set") until the page was opened again: every figure reads the
 * budget available as the server composed it, and the save only patched
 * the amount. A save now fetches the composed budgets again and redraws, as
 * the envelope toggle does.
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
import { showError } from '../../src/utils/notifications.js';

const tree = () => [{
    id: 11, name: 'Food', type: 'expense', budgetAmount: 0, budgetPeriod: 'monthly',
    children: [
        { id: 12, name: 'Groceries', type: 'expense', budgetAmount: 320, budgetPeriod: 'monthly', parentId: 11, children: [] },
        { id: 13, name: 'Clothing', type: 'expense', budgetAmount: 0, budgetPeriod: 'monthly', parentId: 11, children: [] },
    ],
}];

/** The server: composed budgets as saved so far, and this month's spending. */
let saved;

function serve() {
    apiFetch.mockImplementation(async (url, options = {}) => {
        if (options.method === 'PUT') {
            const id = parseInt(url.split('/').pop(), 10);
            saved[id] = options.body.budgetAmount;
            return {};
        }
        if (url.endsWith('/budget-snapshots/2026-10/budgets')) {
            // Every category, as the server lists them; no budget is null
            const budgets = {};
            for (const id of [11, 12, 13]) {
                const amount = saved[id] ?? null;
                budgets[id] = { amount, period: 'monthly', available: amount ?? 0, carried: 0, rollover: false };
            }
            return { budgets, hasSnapshot: false, readyToAssign: null };
        }
        if (url.endsWith('/budget-snapshots')) return [];
        if (url.includes('/categories/spending')) {
            return [{ categoryId: 12, spent: 79.55 }, { categoryId: 13, spent: 20 }];
        }
        throw new Error('unexpected ' + url);
    });
}

async function openBudgetPage() {
    const mod = Object.create(CategoriesModule.prototype);
    mod.app = { settings: {}, categories: [] };
    mod.categoryTree = tree();
    mod.budgetType = 'expense';
    mod.budgetMonth = '2026-10';
    mod._recurringBudgets = {};
    mod.formatCurrency = (v) => '£' + Number(v).toFixed(2);
    await mod.fetchEffectiveBudgets();
    await mod.calculateCategorySpending();
    mod.renderBudgetTree();
    mod.updateBudgetSummary();
    return mod;
}

const cell = (id, selector) => document.querySelector(`.budget-category-row[data-category-id="${id}"] ${selector}`)?.textContent.trim();

beforeEach(() => {
    window.location.hash = '#budget';
    saved = { 12: 320 };
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

describe('changing a budget on the Budget page', () => {
    it('updates the row, its parent and the summary straight away', async () => {
        const mod = await openBudgetPage();
        expect(cell(12, '.budget-remaining')).toBe('£240.45');

        await mod.saveCategoryBudget(12, { budgetAmount: '500' });

        expect(cell(12, '.budget-remaining')).toBe('£420.45');
        expect(cell(12, '.budget-progress-text')).toBe('16%');
        expect(cell(11, '.budget-aggregate-hint')).toBe('Total: £500.00');
        expect(document.getElementById('budget-total-budgeted').textContent).toBe('£500.00');
        expect(document.getElementById('budget-total-remaining').textContent).toBe('£400.45');
    });

    it('shows a first budget on a category straight away', async () => {
        const mod = await openBudgetPage();
        expect(cell(13, '.budget-remaining')).toBe('—');

        await mod.saveCategoryBudget(13, { budgetAmount: '40' });

        expect(cell(13, '.budget-remaining')).toBe('£20.00');
        expect(document.getElementById('budget-categories-count').textContent).toBe('2');
    });

    it('keeps what is being typed in the next budget box', async () => {
        const mod = await openBudgetPage();
        // Tab moved on to Clothing, and typing started there before the save came back
        const next = document.querySelector('.budget-input[data-category-id="13"]');
        next.focus();
        next.value = '4';

        await mod.saveCategoryBudget(12, { budgetAmount: '500' });

        const redrawn = document.querySelector('.budget-input[data-category-id="13"]');
        expect(redrawn.value).toBe('4');
        expect(document.activeElement).toBe(redrawn);
    });

    it('leaves the page as it was when the save fails', async () => {
        const mod = await openBudgetPage();
        apiFetch.mockRejectedValueOnce(new Error('Category not found'));

        await mod.saveCategoryBudget(12, { budgetAmount: '500' });

        expect(showError).toHaveBeenCalled();
        expect(cell(12, '.budget-remaining')).toBe('£240.45');
    });
});
