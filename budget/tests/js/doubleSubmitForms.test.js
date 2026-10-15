/**
 * One click, one save, on every form that creates something.
 *
 * Double-clicking Save (or pressing Enter twice) on the transaction form
 * created two transactions, and on a transfer two linked pairs; accounts,
 * goals, assets, projects, categories, rules, tags, contacts, settlements
 * and import templates had no guard either. Bills, transfers, income and
 * pensions already ran once at a time; the rest now do the same.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
    showUndoNotification: vi.fn(),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import AccountsModule from '../../src/modules/accounts/AccountsModule.js';
import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';
import AssetsModule from '../../src/modules/assets/AssetsModule.js';
import ProjectsModule from '../../src/modules/projects/ProjectsModule.js';
import RulesModule from '../../src/modules/rules/RulesModule.js';
import SavingsModule from '../../src/modules/savings/SavingsModule.js';
import SharedExpensesModule from '../../src/modules/shared-expenses/SharedExpensesModule.js';
import TagSetsModule from '../../src/modules/tagsets/TagSetsModule.js';
import ImportModule from '../../src/modules/import/ImportModule.js';

afterEach(() => {
    document.body.innerHTML = '';
    delete global.fetch;
    delete global.OC;
});

describe('saving a new transaction twice at once', () => {
    let requests;
    let release;

    beforeEach(() => {
        document.body.innerHTML = `
            <div id="transaction-modal">
                <form id="transaction-form">
                    <input type="hidden" id="transaction-id" value="">
                    <input type="text" id="transaction-date" value="2026-10-02">
                    <select id="transaction-account"><option value="1" selected>Current</option><option value="2">Savings</option></select>
                    <select id="transaction-type">
                        <option value="debit" selected>Expense</option>
                        <option value="transfer">Transfer</option>
                    </select>
                    <input type="number" id="transaction-amount" value="7.77">
                    <input type="text" id="transaction-description" value="Lunch">
                    <input type="text" id="transaction-vendor" value="">
                    <select id="transaction-category"><option value="" selected></option></select>
                    <textarea id="transaction-notes"></textarea>
                    <select id="transfer-to-account"><option value="2" selected>Savings</option></select>
                    <div id="transaction-tags-container"></div>
                    <button type="submit">Save</button>
                </form>
            </div>`;
        global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
        requests = [];
        // The first request waits, so the second save starts while the first runs
        let gate = new Promise(resolve => { release = resolve; });
        global.fetch = vi.fn(async (url, init = {}) => {
            requests.push(`${init.method || 'GET'} ${url}`);
            await gate;
            return { ok: true, status: 200, headers: { get: () => null }, json: async () => ({ id: requests.length }) };
        });
    });

    function makeModule() {
        const mod = Object.create(TransactionsModule.prototype);
        mod.app = {
            transactions: [],
            hideModals: vi.fn(),
            loadTransactions: vi.fn(),
            loadAccounts: vi.fn(),
            refreshCurrentAccountView: vi.fn(),
            loadDashboard: vi.fn(),
            tagSetsModule: { saveTransactionTags: vi.fn() },
        };
        mod._pendingAttachments = [];
        mod._scannedReceiptFile = null;
        mod.flushPendingAttachments = vi.fn(async () => 0);
        return mod;
    }

    it('creates one transaction', async () => {
        const mod = makeModule();
        const button = document.querySelector('#transaction-form [type="submit"]');

        const first = mod.saveTransaction();
        const second = mod.saveTransaction();
        expect(button.disabled).toBe(true);
        release();
        await Promise.all([first, second]);

        expect(requests.filter(r => r.startsWith('POST'))).toEqual(['POST /apps/budget/api/transactions']);
        expect(button.disabled).toBe(false);
    });

    it('creates one transfer pair', async () => {
        document.getElementById('transaction-type').value = 'transfer';
        const mod = makeModule();

        const first = mod.saveTransaction();
        const second = mod.saveTransaction();
        release();
        await Promise.all([first, second]);

        expect(requests.filter(r => r.startsWith('POST'))).toEqual([
            'POST /apps/budget/api/transactions',
            'POST /apps/budget/api/transactions',
            'POST /apps/budget/api/transactions/1/link/2',
        ]);
    });

    it('can save again once the first save is done', async () => {
        const mod = makeModule();
        release();

        await mod.saveTransaction();
        await mod.saveTransaction();

        expect(requests.filter(r => r.startsWith('POST'))).toHaveLength(2);
    });
});

// Every other form's save runs its work once at a time, with the form's
// Save button disabled meanwhile.
const FORMS = [
    [AccountsModule, 'saveAccount', 'account-form'],
    [AccountsModule, 'saveQuickAddTransaction', 'quick-add-form'],
    [CategoriesModule, 'saveCategory', 'category-form'],
    [AssetsModule, 'saveAsset', 'asset-form'],
    [AssetsModule, 'saveValueUpdate', 'asset-value-form'],
    [ProjectsModule, 'saveProject', 'project-form'],
    [RulesModule, 'saveRule', 'rule-form'],
    [SavingsModule, 'saveGoal', 'goal-form'],
    [SavingsModule, 'addMoneyToGoal', 'add-to-goal-form'],
    [SharedExpensesModule, 'saveContact', 'contact-form'],
    [SharedExpensesModule, 'saveSettlement', 'settlement-form'],
    [SharedExpensesModule, 'saveShareExpense', 'share-expense-form'],
    [TagSetsModule, 'saveGlobalTag', 'global-tag-form'],
    [ImportModule, 'saveCurrentTemplate', 'import-save-template-form'],
    [TagSetsModule, 'saveTagSet', 'add-tag-set-form', true],
    [TagSetsModule, 'saveEditTagSet', 'edit-tag-set-form', true],
    [TagSetsModule, 'saveTag', 'add-tag-form', true],
    [TagSetsModule, 'saveEditTag', 'edit-tag-form', true],
];

describe.each(FORMS)('%o.%s', (Module, method, formId, takesEvent) => {
    it('runs once while a save is still going, and again after', async () => {
        document.body.innerHTML = `<form id="${formId}"><button type="submit">Save</button></form>`;
        const form = document.getElementById(formId);
        const button = form.querySelector('[type="submit"]');
        const mod = Object.create(Module.prototype);
        let release;
        const work = vi.fn(() => new Promise(resolve => { release = resolve; }));
        mod[`_${method}`] = work;
        const submit = () => {
            const event = new Event('submit', { cancelable: true });
            form.dispatchEvent(event);
            return { event, done: takesEvent ? mod[method](event) : mod[method]() };
        };

        const first = submit();
        const second = submit();
        expect(button.disabled).toBe(true);
        release();
        await Promise.all([first.done, second.done]);

        expect(work).toHaveBeenCalledTimes(1);
        expect(button.disabled).toBe(false);
        if (takesEvent) {
            // A refused second submit must not fall back to a page load
            expect(second.event.defaultPrevented).toBe(true);
        }

        const third = submit();
        release();
        await third.done;
        expect(work).toHaveBeenCalledTimes(2);
    });
});
