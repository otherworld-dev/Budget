/**
 * Category pickers on a row in an account someone shared with you.
 *
 * The row is filed in the account owner's ledger, and the server takes only
 * a category the owner can see. The transaction form, its split rows, bulk
 * edit, the list's category cell and the Quick Add tile offered your own
 * categories there too, and saving was refused ("Category not found"). They
 * now offer the owner's categories shared with you. A row's existing
 * category is kept even when it isn't shared with you: the server lets a
 * row keep it, and an empty picker would have cleared it on save.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import { usableCategories, categoryTreeOf } from '../../src/utils/accounts.js';
import { categoryOptionsHtml } from '../../src/utils/formSelects.js';
import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

// wendy's own account and categories; owen's joint account and the
// categories he shared with her
const OWN = { id: 1, name: 'Wendy Current', userId: 'wendy' };
const JOINT = { id: 3, name: 'Owen Joint', userId: 'owen', _shared: true, _canWrite: true };
const OTHER = { id: 9, name: 'Rita Joint', userId: 'rita', _shared: true, _canWrite: true };
const CATEGORIES = [
    { id: 20, name: 'Wendy Food', type: 'expense', parentId: null, userId: 'wendy' },
    { id: 21, name: 'Wendy Snacks', type: 'expense', parentId: 20, userId: 'wendy' },
    { id: 30, name: 'Household', type: 'expense', parentId: null, userId: 'owen', _shared: true },
    { id: 31, name: 'Groceries', type: 'expense', parentId: 30, userId: 'owen', _shared: true },
    { id: 32, name: 'Salary', type: 'income', parentId: null, userId: 'owen', _shared: true },
];
const ids = (list) => list.map(c => c.id);

describe('usableCategories', () => {
    it('leaves your own accounts unrestricted', () => {
        expect(usableCategories(CATEGORIES, [OWN])).toBeNull();
        expect(usableCategories(CATEGORIES, [null])).toBeNull();
    });

    it('offers the owner\'s categories in an account shared with you', () => {
        expect(ids(usableCategories(CATEGORIES, [JOINT]))).toEqual([30, 31, 32]);
        // a bulk edit of your rows and the owner's: what both ledgers take
        expect(ids(usableCategories(CATEGORIES, [OWN, JOINT]))).toEqual([30, 31, 32]);
    });

    it('offers none for rows in two other people\'s accounts at once', () => {
        expect(usableCategories(CATEGORIES, [JOINT, OTHER])).toEqual([]);
    });

    it('keeps the category a record already has', () => {
        expect(ids(usableCategories(CATEGORIES, [JOINT], [20]))).toEqual([20, 30, 31, 32]);
    });
});

describe('categoryTreeOf', () => {
    it('nests by parent, with an unlisted parent\'s child at the top', () => {
        const tree = categoryTreeOf([CATEGORIES[1], CATEGORIES[2], CATEGORIES[3]]);
        expect(tree.map(c => [c.id, c.children.map(k => k.id)])).toEqual([[21, []], [30, [31]]]);
    });

    it('keeps categories caught in a parent loop', () => {
        const tree = categoryTreeOf([{ id: 1, parentId: 2 }, { id: 2, parentId: 1 }]);
        expect(ids(tree)).toEqual([1, 2]);
    });
});

function mountForm() {
    document.body.innerHTML = `
        <div id="transaction-modal" style="display: none;">
            <h2 id="transaction-modal-title"></h2>
            <form id="transaction-form">
                <input type="hidden" id="transaction-id">
                <input type="text" id="transaction-date">
                <select id="transaction-account"></select>
                <select id="transaction-type"><option value="debit">Expense</option><option value="credit">Income</option></select>
                <input type="number" id="transaction-amount">
                <input type="text" id="transaction-description">
                <input type="text" id="transaction-vendor">
                <div id="transaction-category-group"><select id="transaction-category"></select></div>
                <textarea id="transaction-notes"></textarea>
                <input type="checkbox" id="transaction-excluded-from-forecast">
                <div id="split-toggle-group"><input type="checkbox" id="transaction-split-toggle"></div>
                <div id="inline-splits-section" style="display: none;">
                    <span id="inline-split-remaining"></span>
                    <div id="inline-splits-container"></div>
                    <button type="button" id="inline-add-split-btn"></button>
                </div>
                <div id="transaction-tags-container"></div>
                <button type="submit">Save</button>
            </form>
        </div>`;
}

function makeTransactions() {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = {
        transactions: [],
        accounts: [OWN, JOINT],
        categories: CATEGORIES,
        categoryTree: categoryTreeOf(CATEGORIES),
        renderTransactionTagSelectors: vi.fn(),
        getCategoryOptions: vi.fn(() => ''),
    };
    mod._pendingAttachments = [];
    mod._allowNegativeRemainder = false;
    mod.setupAttachmentsSection = () => {};
    mod.setupReceiptScan = () => {};
    mod._bindTransferAmountFields = () => {};
    mod._updateAmountStep = () => {};
    mod.loadExistingSplits = vi.fn();
    return mod;
}

const offeredIn = (selector) => [...document.querySelectorAll(`${selector} option`)]
    .filter(o => /^\d+$/.test(o.value) && !o.disabled).map(o => parseInt(o.value, 10));

describe('the transaction form', () => {
    beforeEach(mountForm);
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('offers only the owner\'s categories on a row in a shared account', () => {
        const mod = makeTransactions();
        mod.showTransactionModal({ id: 5, accountId: JOINT.id, type: 'debit', amount: 4, description: 'Gym', date: '2026-10-01', categoryId: 31 });

        expect(offeredIn('#transaction-category')).toEqual([30, 31, 32]);
        expect(document.getElementById('transaction-category').value).toBe('31');
    });

    it('offers every category again when the account changes to your own', () => {
        const mod = makeTransactions();
        mod.showTransactionModal({ id: 5, accountId: JOINT.id, type: 'debit', amount: 4, description: 'Gym', date: '2026-10-01', categoryId: null });
        const account = document.getElementById('transaction-account');
        account.value = String(OWN.id);
        account.dispatchEvent(new Event('change'));

        expect(offeredIn('#transaction-category')).toEqual([20, 21, 30, 31, 32]);
    });

    it('keeps a category the owner didn\'t share with you, so a save sends it back', () => {
        const mod = makeTransactions();
        mod.showTransactionModal({ id: 5, accountId: JOINT.id, type: 'debit', amount: 4, description: 'Gym', date: '2026-10-01', categoryId: 77 });

        const select = document.getElementById('transaction-category');
        expect(select.value).toBe('77');
        expect(select.selectedOptions[0].textContent).toBe('Unavailable (not shared with you)');
        expect(select.selectedOptions[0].disabled).toBe(true);
        // ... and still after the account picker is touched
        document.getElementById('transaction-account').dispatchEvent(new Event('change'));
        expect(select.value).toBe('77');
    });

    it('doesn\'t carry an unshared category into a duplicate', () => {
        const mod = makeTransactions();
        mod.showTransactionModal({ id: null, accountId: JOINT.id, type: 'debit', amount: 4, description: 'Gym', date: '2026-10-01', categoryId: 77 });

        expect(document.getElementById('transaction-category').value).toBe('');
    });
});

describe('split part categories', () => {
    function options(selectedId, account, type = 'debit') {
        document.body.innerHTML = `<select>${categoryOptionsHtml(CATEGORIES, selectedId, type, account)}</select>`;
        const select = document.querySelector('select');
        return {
            offered: [...select.options].filter(o => !o.disabled).map(o => parseInt(o.value, 10)),
            value: select.value,
            label: select.selectedOptions[0]?.textContent,
        };
    }

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('are the owner\'s on a row in a shared account', () => {
        expect(options(31, JOINT)).toMatchObject({ offered: [30, 31], value: '31' });
        expect(options(null, JOINT, 'credit').offered).toEqual([32]);
    });

    it('are every category of the type on your own row', () => {
        expect(options(null, OWN).offered).toEqual([20, 21, 30, 31]);
    });

    it('keep a part\'s category that isn\'t shared with you', () => {
        expect(options(77, JOINT)).toMatchObject({ value: '77', label: 'Unavailable (not shared with you)' });
    });
});

describe('a shared bill\'s split parts', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('keep a part\'s category that isn\'t shared with you', async () => {
        const { default: BillsModule } = await import('../../src/modules/bills/BillsModule.js');
        document.body.innerHTML = '<div id="bill-split-rows"></div><span id="bill-split-remaining"></span><input id="bill-amount" value="100">';
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings: {}, categories: CATEGORIES, categoryTree: categoryTreeOf(CATEGORIES), accounts: [] };

        mod.addBillSplitRow({ amount: 40, categoryId: 77 });
        mod.addBillSplitRow({ amount: 60, categoryId: 31 });

        const selects = [...document.querySelectorAll('.bill-split-category')];
        expect(selects.map(s => s.value)).toEqual(['77', '31']);
    });
});

describe('bulk edit', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('offers only what every selected row\'s ledger takes', () => {
        document.body.innerHTML = `
            <div id="bulk-edit-modal"><span id="bulk-edit-count"></span>
                <select id="bulk-edit-category"></select>
                <input id="bulk-edit-vendor"><input id="bulk-edit-reference"><textarea id="bulk-edit-notes"></textarea>
            </div>`;
        const mod = makeTransactions();
        mod.app.transactions = [{ id: 1, accountId: OWN.id }, { id: 2, accountId: JOINT.id }];
        mod.selectedTransactions = new Set([1, 2]);

        mod.showBulkEditModal();

        expect(offeredIn('#bulk-edit-category')).toEqual([30, 31, 32]);
    });
});

describe('the Quick Add tile', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('follows the account picked', () => {
        document.body.innerHTML = `
            <select id="quick-add-account"></select>
            <select id="quick-add-category"></select>
            <input id="quick-add-date">`;
        const mod = Object.create(AccountsModule.prototype);
        mod.app = { accounts: [OWN, JOINT], categories: CATEGORIES, categoryTree: categoryTreeOf(CATEGORIES), settings: {} };
        document.getElementById('quick-add-date').value = '2026-10-04';

        mod.initQuickAddForm();
        expect(offeredIn('#quick-add-category')).toEqual([20, 21, 30, 31, 32]);

        const account = document.getElementById('quick-add-account');
        account.value = String(JOINT.id);
        account.dispatchEvent(new Event('change'));
        expect(offeredIn('#quick-add-category')).toEqual([30, 31, 32]);
    });
});
