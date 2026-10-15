/**
 * The category name of a row in an account someone shared with you.
 *
 * The row is filed in the owner's ledger, under one of the owner's
 * categories, and that category may not be shared with you. The row itself
 * is (its description, payee and amount), so its category's name is shown
 * wherever the row is: the server sends it with the row. Before, the list
 * named such a category on a split part ("Groceries / Secret Stuff") but
 * called a plain row "Uncategorized", and the form said "Unavailable (not
 * shared with you)".
 *
 * Only the display changed. The pickers still don't offer a category that
 * isn't shared with you, and the one the row has is kept unchanged on save.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

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

import { renderTransactionRow } from '../../src/modules/transactions/transactionRow.js';
import { categoryTreeOf } from '../../src/utils/accounts.js';
import { categoryOptionsHtml, selectPossiblyUnavailable } from '../../src/utils/formSelects.js';
import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

// wendy's own account and category; owen's joint account and the categories
// he shared with her. His "Secret Stuff" (77) is not shared with her.
const OWN = { id: 1, name: 'Wendy Current', userId: 'wendy' };
const JOINT = { id: 3, name: 'Owen Joint', userId: 'owen', _shared: true, _canWrite: true };
const CATEGORIES = [
    { id: 20, name: 'Wendy Food', type: 'expense', parentId: null, userId: 'wendy', color: '#f00' },
    { id: 30, name: 'Household', type: 'expense', parentId: null, userId: 'owen', _shared: true },
    { id: 31, name: 'Groceries', type: 'expense', parentId: 30, userId: 'owen', _shared: true, color: '#0f0' },
];
const SECRET_ROW = {
    id: 5, accountId: JOINT.id, date: '2026-10-02', description: 'Secret joint', type: 'debit', amount: 15,
    categoryId: 77, categoryName: 'Secret Stuff',
};

afterEach(() => {
    document.body.innerHTML = '';
});

function row(tx, variant, categories = CATEGORIES) {
    const tbody = document.createElement('tbody');
    tbody.innerHTML = renderTransactionRow(tx, {
        variant,
        accounts: [OWN, JOINT],
        categories,
        currency: 'GBP',
        formatCurrency: (v) => '£' + Number(v).toFixed(2),
        formatDate: (d) => d,
    });
    return tbody.querySelector('tr');
}

describe('the transactions list and an account\'s register', () => {
    it.each(['ledger', 'register'])('%s: name the owner\'s category on a row in a shared account', (variant) => {
        const cell = row(SECRET_ROW, variant).querySelector('.category-column');

        expect(cell.textContent.trim()).toBe('Secret Stuff');
        expect(cell.querySelector('.uncategorized')).toBeNull();
    });

    it.each(['ledger', 'register'])('%s: still call a row with no category Uncategorized', (variant) => {
        const cell = row({ ...SECRET_ROW, categoryId: null, categoryName: null }, variant).querySelector('.category-column');

        expect(cell.textContent.trim()).toBe('Uncategorized');
    });

    it.each(['ledger', 'register'])('%s: name a category in the list from the list, colour and all', (variant) => {
        const tr = row({ ...SECRET_ROW, categoryId: 31, categoryName: 'Old name' }, variant);

        expect(tr.querySelector('.category-column').textContent.trim()).toBe('Groceries');
    });

    it('escapes the name', () => {
        const cell = row({ ...SECRET_ROW, categoryName: '<b>B&Q</b>' }, 'ledger').querySelector('.category-column');

        expect(cell.querySelector('b')).toBeNull();
        expect(cell.textContent.trim()).toBe('<b>B&Q</b>');
    });
});

describe('the dashboard\'s Recent Transactions', () => {
    it('names the owner\'s category on a row in a shared account', () => {
        document.body.innerHTML = '<div id="recent-transactions"></div>';
        const mod = Object.create(DashboardModule.prototype);
        mod.app = { settings: {}, categories: CATEGORIES, dashboardConfig: { widgets: { tileSettings: {}, instances: {} } } };
        mod.formatCurrency = (v) => '£' + Number(v).toFixed(2);
        mod.formatDate = (d) => d;

        mod.updateRecentTransactions([SECRET_ROW]);

        expect(document.querySelector('.recent-transaction-category').textContent.trim()).toBe('Secret Stuff');
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
        getCategoryOptions: (selectedId, type, account, selectedName) => categoryOptionsHtml(CATEGORIES, selectedId, type, account, selectedName),
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

const enabledIds = (select) => [...select.options].filter(o => /^\d+$/.test(o.value) && !o.disabled).map(o => o.value);

describe('the transaction form', () => {
    it('names the kept category, says why it isn\'t offered, and still keeps it', () => {
        mountForm();
        makeTransactions().showTransactionModal(SECRET_ROW);

        const select = document.getElementById('transaction-category');
        expect(select.value).toBe('77');
        expect(select.selectedOptions[0].textContent).toBe('Secret Stuff (not shared with you)');
        expect(select.selectedOptions[0].disabled).toBe(true);
        // Not offered as a choice: only Household and Groceries are
        expect(enabledIds(select)).toEqual(['30', '31']);
    });

    it('keeps the name when the account picker is touched', () => {
        mountForm();
        makeTransactions().showTransactionModal(SECRET_ROW);
        document.getElementById('transaction-account').dispatchEvent(new Event('change'));

        const select = document.getElementById('transaction-category');
        expect(select.value).toBe('77');
        expect(select.selectedOptions[0].textContent).toBe('Secret Stuff (not shared with you)');
    });

    it('still says Unavailable when the row came without the name', () => {
        mountForm();
        makeTransactions().showTransactionModal({ ...SECRET_ROW, categoryName: undefined });

        expect(document.getElementById('transaction-category').selectedOptions[0].textContent)
            .toBe('Unavailable (not shared with you)');
    });

    it('names a split part\'s kept category, also after the account picker is touched', () => {
        mountForm();
        const mod = makeTransactions();
        mod.showTransactionModal({ ...SECRET_ROW, categoryId: null, categoryName: null, isSplit: true });
        mod.addInlineSplitRow(true, { amount: 10, categoryId: 77, categoryName: 'Secret Stuff' }, { defer: true });
        mod.addInlineSplitRow(false, { amount: 5, categoryId: 31, categoryName: 'Groceries' }, { defer: true });

        const parts = () => [...document.querySelectorAll('.inline-split-category')];
        expect(parts().map(s => [s.value, s.selectedOptions[0].textContent])).toEqual([
            ['77', 'Secret Stuff (not shared with you)'],
            ['31', 'Groceries'],
        ]);
        expect(enabledIds(parts()[0])).toEqual(['30', '31']);

        document.getElementById('transaction-account').dispatchEvent(new Event('change'));
        expect(parts().map(s => [s.value, s.selectedOptions[0].textContent])).toEqual([
            ['77', 'Secret Stuff (not shared with you)'],
            ['31', 'Groceries'],
        ]);
    });
});

describe('the list\'s inline category editor', () => {
    it('shows the kept category\'s name, as the cell does, without offering it', () => {
        document.body.innerHTML = '<table><tr><td class="category-column"></td></tr></table>';
        const mod = makeTransactions();
        mod.app.transactions = [SECRET_ROW];
        const cell = document.querySelector('td');

        mod.createCategoryEditor(cell, '77', SECRET_ROW);

        expect(cell.querySelector('.category-autocomplete-input').value).toBe('Secret Stuff');
        expect(cell.querySelector('.category-autocomplete-input').dataset.categoryId).toBe('77');
        expect([...cell.querySelectorAll('.category-autocomplete-item')].map(i => i.dataset.categoryId)).toEqual(['', '30', '31']);
    });
});

describe('the select helpers', () => {
    it('label a kept split part by its name, escaped', () => {
        document.body.innerHTML = `<select>${categoryOptionsHtml(CATEGORIES, 77, 'debit', JOINT, '<i>Secret</i>')}</select>`;
        const option = document.querySelector('select').selectedOptions[0];

        expect(option.value).toBe('77');
        expect(option.disabled).toBe(true);
        expect(option.textContent).toBe('<i>Secret</i> (not shared with you)');
        expect(document.querySelector('i')).toBeNull();
    });

    it('selectPossiblyUnavailable takes the label to show', () => {
        document.body.innerHTML = '<select><option value="">None</option></select>';
        const select = document.querySelector('select');

        selectPossiblyUnavailable(select, 77, 'Secret Stuff (not shared with you)');

        expect(select.value).toBe('77');
        expect(select.selectedOptions[0].textContent).toBe('Secret Stuff (not shared with you)');
        expect(select.selectedOptions[0].disabled).toBe(true);
    });
});
