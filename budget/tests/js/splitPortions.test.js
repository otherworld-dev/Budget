/**
 * Coverage for how a split transaction is shown when a view is scoped to one
 * category (#359).
 *
 * Splitting a transaction clears its own category_id and moves the categories
 * onto its budget_tx_splits rows, so a category filter matches it through those
 * parts. The row that comes back is still the whole transaction, and the server
 * marks it with the share that matched. Every surface that displays or totals
 * such a row has to use the share, not the transaction's own amount, or the
 * figures contradict the spending charts the list was opened from -- which is
 * the complaint #359 was reported as.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { hasSplitPortion, transactionDisplayAmount } from '../../src/utils/helpers.js';
import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';

describe('hasSplitPortion', () => {
    it('is true for a row carrying a share', () => {
        expect(hasSplitPortion({ amount: 82.4, matchedSplitAmount: 12.4 })).toBe(true);
    });

    it('is true for a zero share, which is a real value', () => {
        expect(hasSplitPortion({ amount: 82.4, matchedSplitAmount: 0 })).toBe(true);
    });

    it('is true for a negative share -- a receipt discount line', () => {
        expect(hasSplitPortion({ amount: 82.4, matchedSplitAmount: -3.5 })).toBe(true);
    });

    it('is false for an ordinary row', () => {
        expect(hasSplitPortion({ amount: 82.4 })).toBe(false);
    });

    it('is false when the server explicitly reported no match', () => {
        expect(hasSplitPortion({ amount: 82.4, matchedSplitAmount: null })).toBe(false);
    });

    it('survives a missing row', () => {
        expect(hasSplitPortion(undefined)).toBe(false);
    });
});

describe('transactionDisplayAmount', () => {
    it('reports the share when there is one', () => {
        expect(transactionDisplayAmount({ amount: 82.4, matchedSplitAmount: 12.4 })).toBe(12.4);
    });

    it('reports a zero share rather than falling back to the whole transaction', () => {
        expect(transactionDisplayAmount({ amount: 82.4, matchedSplitAmount: 0 })).toBe(0);
    });

    it('keeps a negative share negative', () => {
        expect(transactionDisplayAmount({ amount: 82.4, matchedSplitAmount: -3.5 })).toBe(-3.5);
    });

    it('reports the transaction amount for an ordinary row', () => {
        expect(transactionDisplayAmount({ amount: 82.4 })).toBe(82.4);
    });

    it('reports zero rather than undefined for a row with no amount', () => {
        expect(transactionDisplayAmount({})).toBe(0);
    });

    it('totals a page the way the footer does', () => {
        const page = [
            { type: 'debit', amount: 82.4, matchedSplitAmount: 12.4 },
            { type: 'debit', amount: 20 },
            { type: 'credit', amount: 100 },
        ];

        const total = page.reduce((sum, tx) => {
            const amount = transactionDisplayAmount(tx);
            return sum + (tx.type === 'credit' ? amount : -amount);
        }, 0);

        // 100 in, 12.40 of the split plus 20 out -- not the whole 82.40.
        expect(total).toBeCloseTo(67.6, 2);
    });
});

/**
 * The real method without the constructor, which wires the whole categories
 * view. Only the collaborators the renderer reaches for are provided.
 */
function makeModule() {
    const mod = Object.create(CategoriesModule.prototype);
    mod.escapeHtml = (v) => String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    mod.formatCurrency = (v) => '£' + Number(v).toFixed(2);
    mod.formatDate = (d) => d;
    return mod;
}

function mountPanel() {
    document.body.innerHTML = '<div id="category-recent-transactions"></div>';
}

function renderedAmounts() {
    return [...document.querySelectorAll('#category-recent-transactions .transaction-amount')]
        .map((el) => el.textContent.trim());
}

beforeEach(mountPanel);
afterEach(() => {
    document.body.innerHTML = '';
});

describe('Category Details recent transactions', () => {
    const splitRow = {
        id: 7,
        date: '2026-08-14',
        description: 'Tesco',
        type: 'debit',
        amount: 12.4,
        transactionAmount: 82.4,
        isSplit: true,
        splitCategories: [
            { categoryId: 2, categoryName: 'Groceries', amount: 12.4, inCategory: true },
            { categoryId: 9, categoryName: 'Household', amount: 70.0, inCategory: false },
        ],
    };

    it('lists a split at the share belonging to this category, not the whole receipt', () => {
        makeModule().renderRecentTransactions([splitRow]);

        expect(renderedAmounts()).toEqual(['-£12.40']);
    });

    it('names the whole transaction the share came out of', () => {
        makeModule().renderRecentTransactions([splitRow]);

        const whole = document.querySelector('.transaction-split-whole');
        expect(whole).not.toBeNull();
        expect(whole.textContent).toContain('£82.40');
    });

    it('marks the row as a split part', () => {
        makeModule().renderRecentTransactions([splitRow]);

        expect(document.querySelector('.split-indicator')).not.toBeNull();
    });

    it('lists every part in the tooltip so the whole split is legible', () => {
        makeModule().renderRecentTransactions([splitRow]);

        const title = document.querySelector('.transaction-item').getAttribute('title');
        expect(title).toContain('Groceries: £12.40');
        expect(title).toContain('Household: £70.00');
    });

    it('escapes a category name rather than letting it close the title attribute', () => {
        makeModule().renderRecentTransactions([{
            ...splitRow,
            splitCategories: [{ categoryId: 2, categoryName: '" onmouseover="x', amount: 12.4 }],
        }]);

        const item = document.querySelector('.transaction-item');
        expect(item.getAttribute('onmouseover')).toBeNull();
        expect(item.getAttribute('title')).toContain('onmouseover="x');
    });

    it('leaves an ordinary row exactly as it was', () => {
        makeModule().renderRecentTransactions([{
            id: 8, date: '2026-08-12', description: 'Bus fare', type: 'debit', amount: 2.5,
        }]);

        expect(renderedAmounts()).toEqual(['-£2.50']);
        expect(document.querySelector('.split-indicator')).toBeNull();
        expect(document.querySelector('.transaction-split-whole')).toBeNull();
    });

    it('omits the whole-transaction line when the split is entirely in this category', () => {
        // Two parts of one receipt, both in the filtered subtree: the share IS
        // the transaction, so saying "of £82.40" would be noise.
        makeModule().renderRecentTransactions([{ ...splitRow, amount: 82.4 }]);

        expect(document.querySelector('.transaction-split-whole')).toBeNull();
        expect(document.querySelector('.split-indicator')).not.toBeNull();
    });

    it('shows the empty state when the category has nothing in it', () => {
        makeModule().renderRecentTransactions([]);

        expect(document.querySelector('.empty-state')).not.toBeNull();
    });
});

describe('viewAllCategoryTransactions', () => {
    function moduleWithApp(scope, category) {
        const mod = makeModule();
        mod.app = { openTransactionsForCategory: vi.fn() };
        mod._detailScope = scope;
        mod._currentCategory = category;
        return mod;
    }

    it('opens the same categories the panel reported on', () => {
        const mod = moduleWithApp(
            { categoryIds: [1, 2, 3], type: 'expense' },
            { id: 1, type: 'expense' }
        );

        mod.viewAllCategoryTransactions();

        expect(mod.app.openTransactionsForCategory).toHaveBeenCalledWith([1, 2, 3], { type: 'debit' });
    });

    it('filters an income category to credits, or it would land on an empty list', () => {
        const mod = moduleWithApp(
            { categoryIds: [4], type: 'income' },
            { id: 4, type: 'income' }
        );

        mod.viewAllCategoryTransactions();

        expect(mod.app.openTransactionsForCategory).toHaveBeenCalledWith([4], { type: 'credit' });
    });

    it('falls back to the selected category when no scope was reported', () => {
        const mod = moduleWithApp(null, { id: 6, type: 'expense' });

        mod.viewAllCategoryTransactions();

        expect(mod.app.openTransactionsForCategory).toHaveBeenCalledWith([6], { type: 'debit' });
    });

    it('does nothing when no category is selected', () => {
        const mod = moduleWithApp(null, null);
        mod.selectedCategory = null;

        mod.viewAllCategoryTransactions();

        expect(mod.app.openTransactionsForCategory).not.toHaveBeenCalled();
    });
});

describe('Category Details with a negative split part', () => {
    it('shows a discount line as money back, not as spending', () => {
        // A receipt's savings line is a negative part of an expense, so the row
        // it produces under that category is inbound.
        makeModule().renderRecentTransactions([{
            id: 9, date: '2026-08-12', description: 'Tesco', type: 'debit',
            amount: -3.5, transactionAmount: 20.0, isSplit: true,
        }]);

        expect(renderedAmounts()).toEqual(['+£3.50']);
        expect(document.querySelector('.transaction-amount').className).toContain('credit');
    });
});
