/**
 * Coverage for the account register's category cell against split transactions
 * (#359/#360).
 *
 * Splitting a transaction clears its own category_id and moves the categories
 * onto its budget_tx_splits rows, so a category filter on the register matches
 * a split through its parts rather than the transaction itself. The row that
 * comes back still represents the whole transaction, and the server marks it
 * with `matchedSplitAmount` -- the share that matched -- plus `splitCategories`,
 * the full part breakdown. `renderAccountTransactions()` is one of the two
 * reference implementations the rest of the app was fixed against (#360), so a
 * regression here (e.g. falling back to "Uncategorized" or totalling the whole
 * transaction instead of the matched share) would look identical to the
 * original bug reports.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

const CATEGORIES = [
    { id: 2, name: 'Groceries' },
    { id: 9, name: 'Household' },
];

/**
 * The real method without the constructor, which wires the whole accounts
 * view. escapeHtml is NOT stubbed here -- renderAccountTransactions() imports
 * it as a module function (`dom.escapeHtml`), not `this.escapeHtml`, so the
 * real implementation runs automatically. Only the collaborators reached via
 * `this` are provided.
 */
function makeModule(accountTransactions) {
    const mod = Object.create(AccountsModule.prototype);
    mod.accountTransactions = accountTransactions;
    // `categories` is a getter on the prototype that proxies to `this.app.categories`.
    mod.app = { categories: CATEGORIES, accounts: [], loadAndDisplayTransactionTags: vi.fn() };
    mod.currentAccount = { id: 1, currency: 'GBP' };
    mod.getPrimaryCurrency = () => 'GBP';
    mod.accountRunningBalances = null;
    mod.formatCurrency = (v) => '£' + Number(v).toFixed(2);
    mod.formatDate = (d) => d;
    return mod;
}

function mountRegister() {
    document.body.innerHTML = '<table><tbody id="account-transactions-body"></tbody></table>';
}

const categoryCell = () => document.querySelector('.category-column');
const splitCell = () => document.querySelector('.category-name.split-category');
const amountEl = () => document.querySelector('.transaction-amount');

beforeEach(mountRegister);
afterEach(() => {
    document.body.innerHTML = '';
});

const splitRow = {
    id: 7,
    date: '2026-08-14',
    description: 'Tesco',
    type: 'debit',
    amount: 12.4,
    isSplit: true,
    splitCategories: [
        { categoryId: 2, categoryName: 'Groceries', amount: 12.4, matched: true },
        { categoryId: 9, categoryName: 'Household', amount: 70.0, matched: false },
    ],
};

describe('Account register: split transaction with parts', () => {
    it('joins each part name with " / " inside the split category cell', () => {
        makeModule([splitRow]).renderAccountTransactions();

        const cell = splitCell();
        expect(cell).not.toBeNull();
        expect(cell.textContent).toBe('Groceries / Household');
    });

    it('marks only the part in the filtered category with is-match', () => {
        makeModule([splitRow]).renderAccountTransactions();

        const items = document.querySelectorAll('.split-cat-item');
        expect(items).toHaveLength(2);
        expect(items[0].className).toContain('is-match');
        expect(items[1].className).not.toContain('is-match');
    });

    it('lists every part in the tooltip as "Name: amount"', () => {
        makeModule([splitRow]).renderAccountTransactions();

        const title = splitCell().getAttribute('title');
        expect(title).toContain('Groceries: £12.40');
        expect(title).toContain('Household: £70.00');
    });

    it('separates the tooltip parts with newlines rather than running them together', () => {
        makeModule([splitRow]).renderAccountTransactions();

        const title = splitCell().getAttribute('title');
        expect(title.split('\n')).toHaveLength(2);
    });

    it('escapes a category name rather than letting it close the title attribute', () => {
        makeModule([{
            ...splitRow,
            splitCategories: [{ categoryId: 2, categoryName: '" onmouseover="x', amount: 12.4 }],
        }]).renderAccountTransactions();

        const cell = splitCell();
        expect(cell.getAttribute('onmouseover')).toBeNull();
        expect(cell.getAttribute('title')).toContain('onmouseover="x');
        expect(cell.querySelector('.split-cat-item').textContent).toContain('" onmouseover="x');
    });
});

describe('Account register: split transaction without parts', () => {
    it('renders the literal "Split" label when the row has no splitCategories', () => {
        const { splitCategories, ...withoutParts } = splitRow;
        makeModule([withoutParts]).renderAccountTransactions();

        expect(categoryCell().textContent).toContain('Split');
        expect(document.querySelector('.split-cat-item')).toBeNull();
    });
});

describe('Account register: ordinary rows', () => {
    it('leaves a categorized row unchanged', () => {
        makeModule([{
            id: 11, date: '2026-08-11', description: 'Salary', type: 'credit', amount: 100, categoryId: 2,
        }]).renderAccountTransactions();

        const el = document.querySelector('.category-name');
        expect(el.className).not.toContain('split-category');
        expect(el.textContent.trim()).toBe('Groceries');
    });

    it('renders Uncategorized for a plain row with no category', () => {
        makeModule([{
            id: 12, date: '2026-08-11', description: 'Cash', type: 'debit', amount: 20,
        }]).renderAccountTransactions();

        const el = document.querySelector('.category-name');
        expect(el.className).toContain('uncategorized');
        expect(el.textContent.trim()).toBe('Uncategorized');
    });
});

describe('Account register: matched split amount vs. the whole transaction', () => {
    it('shows the matched share, not the whole transaction, in the amount cell', () => {
        makeModule([{ ...splitRow, amount: 82.4, matchedSplitAmount: 12.4 }]).renderAccountTransactions();

        expect(amountEl().textContent.trim()).toBe('-£12.40');
    });

    it('adds an "of {total}" line when the share differs from the whole', () => {
        makeModule([{ ...splitRow, amount: 82.4, matchedSplitAmount: 12.4 }]).renderAccountTransactions();

        const whole = document.querySelector('.amount-whole');
        expect(whole).not.toBeNull();
        expect(whole.textContent).toContain('£82.40');
    });

    it('omits the "of" line when the matched share IS the whole transaction', () => {
        // A receipt split entirely within the filtered category subtree: the
        // share equals the transaction total, so "of £12.40" would be noise.
        makeModule([{ ...splitRow, amount: 12.4, matchedSplitAmount: 12.4 }]).renderAccountTransactions();

        expect(document.querySelector('.amount-whole')).toBeNull();
        expect(amountEl().textContent.trim()).toBe('-£12.40');
    });

    it('leaves an ordinary row without a matched share unaffected', () => {
        makeModule([{
            id: 13, date: '2026-08-11', description: 'Bus fare', type: 'debit', amount: 2.5,
        }]).renderAccountTransactions();

        expect(amountEl().textContent.trim()).toBe('-£2.50');
        expect(document.querySelector('.amount-whole')).toBeNull();
    });
});
