/**
 * The dashboard's Recent Transactions tile against split transactions (#360).
 *
 * Splitting a transaction clears its own category_id and moves the categories
 * onto its budget_tx_splits rows (#359), so resolving a row's category from
 * categoryId alone labels every split "Uncategorized" -- which is exactly how
 * #360 was reported: a transaction categorised into three categories, sitting
 * on the dashboard claiming to have none.
 *
 * The tile renders rows from /api/transactions, which has attached the parts
 * as `splitCategories` since #359, so the information was already in the
 * payload the tile was rendering from.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

const CATEGORIES = [
    { id: 2, name: 'Groceries', color: '#4caf50' },
    { id: 9, name: 'Household', color: '#2196f3' },
    { id: 11, name: 'Child Expenses', color: '#e91e63' },
];

/**
 * The real renderer without the constructor, which wires the whole dashboard.
 * escapeHtml stays real -- a category name is user input and the tooltip is an
 * attribute.
 */
function makeDashboard(categories = CATEGORIES) {
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        settings: {},
        categories,
        dashboardConfig: { widgets: { tileSettings: {}, instances: {} } },
    };
    mod.formatCurrency = (v) => '£' + Number(v).toFixed(2);
    mod.formatDate = (d) => d;
    return mod;
}

const splitRow = {
    id: 7,
    date: '2026-08-22',
    description: 'Walmart',
    type: 'debit',
    amount: 82.4,
    categoryId: null,
    isSplit: true,
    splitCategories: [
        { categoryId: 2, categoryName: 'Groceries', amount: 12.4 },
        { categoryId: 9, categoryName: 'Household', amount: 70.0 },
    ],
};

const categoryEl = () => document.querySelector('.recent-transaction-category');
const dotColours = () =>
    [...document.querySelectorAll('.recent-transaction-category-dot')]
        .map((el) => el.style.background);

beforeEach(() => {
    document.body.innerHTML = '<div id="recent-transactions"></div>';
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Recent Transactions tile: split transactions', () => {
    it('names the categories a split was sorted into instead of calling it uncategorized', () => {
        makeDashboard().updateRecentTransactions([splitRow]);

        const text = categoryEl().textContent;
        expect(text).toContain('Groceries');
        expect(text).toContain('Household');
        expect(text).not.toContain('Uncategorized');
    });

    it('marks one dot per category in the split, in each category colour', () => {
        makeDashboard().updateRecentTransactions([splitRow]);

        expect(dotColours()).toEqual(['rgb(76, 175, 80)', 'rgb(33, 150, 243)']);
    });

    it('names a category once when two parts of the receipt share it', () => {
        makeDashboard().updateRecentTransactions([{
            ...splitRow,
            splitCategories: [
                { categoryId: 2, categoryName: 'Groceries', amount: 12.4 },
                { categoryId: 2, categoryName: 'Groceries', amount: 5.0 },
                { categoryId: 9, categoryName: 'Household', amount: 65.0 },
            ],
        }]);

        const text = categoryEl().textContent;
        expect(text.match(/Groceries/g)).toHaveLength(1);
        expect(dotColours()).toHaveLength(2);
    });

    it('breaks the split down part by part in the tooltip, repeats included', () => {
        makeDashboard().updateRecentTransactions([{
            ...splitRow,
            splitCategories: [
                { categoryId: 2, categoryName: 'Groceries', amount: 12.4 },
                { categoryId: 2, categoryName: 'Groceries', amount: 5.0 },
                { categoryId: 9, categoryName: 'Household', amount: 65.0 },
            ],
        }]);

        const title = categoryEl().getAttribute('title');
        expect(title).toContain('Groceries: £12.40');
        expect(title).toContain('Groceries: £5.00');
        expect(title).toContain('Household: £65.00');
    });

    it('shows a part with no category of its own as uncategorized', () => {
        makeDashboard().updateRecentTransactions([{
            ...splitRow,
            splitCategories: [
                { categoryId: 2, categoryName: 'Groceries', amount: 12.4 },
                { categoryId: null, categoryName: null, amount: 70.0 },
            ],
        }]);

        expect(categoryEl().textContent).toContain('Groceries');
        expect(categoryEl().textContent).toContain('Uncategorized');
    });

    it('escapes a category name rather than letting it close the tooltip attribute', () => {
        makeDashboard().updateRecentTransactions([{
            ...splitRow,
            splitCategories: [{ categoryId: 2, categoryName: '" onmouseover="x', amount: 12.4 }],
        }]);

        expect(categoryEl().getAttribute('onmouseover')).toBeNull();
        expect(categoryEl().getAttribute('title')).toContain('onmouseover="x');
    });

    it('says Split, not Uncategorized, when the parts did not come with the row', () => {
        const { splitCategories, ...withoutParts } = splitRow;
        makeDashboard().updateRecentTransactions([withoutParts]);

        expect(categoryEl().textContent).toContain('Split');
        expect(categoryEl().textContent).not.toContain('Uncategorized');
    });

    it('reads is_split too, for a payload using the column name', () => {
        const { isSplit, ...row } = splitRow;
        makeDashboard().updateRecentTransactions([{ ...row, is_split: true }]);

        expect(categoryEl().textContent).toContain('Groceries');
    });
});

describe('Recent Transactions tile: ordinary transactions', () => {
    it('names the category of a plain row, in its colour', () => {
        makeDashboard().updateRecentTransactions([{
            id: 8, date: '2026-08-23', description: 'Harris Teeter',
            type: 'debit', amount: 31.2, categoryId: 2,
        }]);

        expect(categoryEl().textContent).toContain('Groceries');
        expect(dotColours()).toEqual(['rgb(76, 175, 80)']);
    });

    it('still calls a genuinely uncategorized row uncategorized', () => {
        makeDashboard().updateRecentTransactions([{
            id: 9, date: '2026-08-23', description: 'Cash', type: 'debit', amount: 20,
        }]);

        expect(categoryEl().textContent).toContain('Uncategorized');
    });

    it('shows the empty state when there is nothing to list', () => {
        makeDashboard().updateRecentTransactions([]);

        expect(document.querySelector('.empty-state-small')).not.toBeNull();
    });
});
