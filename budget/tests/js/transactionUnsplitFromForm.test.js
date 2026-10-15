/**
 * Unsplitting a transaction from its edit form.
 *
 * Unticking "Split across categories" on a split transaction, picking a
 * category and saving said "Transaction updated", but nothing changed: the
 * form only sent the usual update, and the server keeps a split
 * transaction's category empty while its parts exist. Unticking Split now
 * unsplits it into the category picked, and a refusal is shown instead of
 * a success.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import { showSuccess, showError } from '../../src/utils/notifications.js';

const SPLIT = {
    id: 43, accountId: 5, date: '2026-10-02', type: 'debit', amount: 90,
    description: 'Costco run', vendor: 'Costco', categoryId: null, notes: null,
    isSplit: true, reconciled: false, excludedFromForecast: false,
};

function mountForm({ id = '43', categoryId = '56', split = false } = {}) {
    document.body.innerHTML = `
        <div id="transaction-modal" style="display: none;">
            <h2 id="transaction-modal-title"></h2>
            <form id="transaction-form">
                <input type="hidden" id="transaction-id" value="${id}">
                <input type="text" id="transaction-date" value="2026-10-02">
                <select id="transaction-account"><option value="5" selected>Everyday</option></select>
                <select id="transaction-type">
                    <option value="debit" selected>Expense</option>
                    <option value="credit">Income</option>
                </select>
                <input type="number" id="transaction-amount" value="90">
                <input type="text" id="transaction-description" value="Costco run">
                <input type="text" id="transaction-vendor" value="Costco">
                <div id="transaction-category-group">
                    <select id="transaction-category">
                        <option value="">Uncategorized</option>
                        <option value="56">Groceries</option>
                    </select>
                </div>
                <textarea id="transaction-notes"></textarea>
                <input type="checkbox" id="transaction-excluded-from-forecast">
                <div id="split-toggle-group">
                    <input type="checkbox" id="transaction-split-toggle" ${split ? 'checked' : ''}>
                </div>
                <div id="inline-splits-section" style="display: none;">
                    <span id="inline-split-remaining"></span>
                    <div id="inline-splits-container"></div>
                    <button type="button" id="inline-add-split-btn"></button>
                </div>
                <div id="transaction-tags-container"></div>
            </form>
        </div>`;
    document.getElementById('transaction-category').value = categoryId;
}

function makeModule({ transactions = [SPLIT] } = {}) {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = {
        transactions,
        accounts: [{ id: 5, name: 'Everyday', currency: 'GBP' }],
        hideModals: vi.fn(),
        loadTransactions: vi.fn(),
        loadAccounts: vi.fn(),
        refreshCurrentAccountView: vi.fn(),
        loadDashboard: vi.fn(),
        renderTransactionTagSelectors: vi.fn(),
        getCategoryOptions: () => '<option value="56">Groceries</option>',
        tagSetsModule: { saveTransactionTags: vi.fn() },
    };
    mod._pendingAttachments = [];
    mod._scannedReceiptFile = null;
    mod._allowNegativeRemainder = false;
    mod.flushPendingAttachments = vi.fn(async () => 0);
    return mod;
}

/** Every request, as "METHOD url" plus its parsed body. */
let requests;

function serve(handler = () => null) {
    requests = [];
    global.fetch = vi.fn(async (url, init = {}) => {
        const method = init.method || 'GET';
        const body = init.body ? JSON.parse(init.body) : null;
        requests.push({ call: `${method} ${url}`, body });
        const refused = handler(method, url);
        if (refused) {
            return { ok: false, status: refused.status, statusText: '', json: async () => refused.body };
        }
        return { ok: true, status: 200, headers: { get: () => null }, json: async () => (method === 'GET' ? [] : {}) };
    });
}

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
    vi.clearAllMocks();
});

afterEach(() => {
    delete global.fetch;
    delete global.OC;
    document.body.innerHTML = '';
});

describe('saving a split transaction with Split unticked', () => {
    it('unsplits it into the category picked, before the rest of the update', async () => {
        mountForm({ categoryId: '56', split: false });
        serve();
        const mod = makeModule();

        await mod.saveTransaction();

        const calls = requests.map(r => r.call);
        const unsplit = calls.indexOf('DELETE /apps/budget/api/transactions/43/splits');
        const update = calls.indexOf('PUT /apps/budget/api/transactions/43');
        expect(unsplit).toBeGreaterThan(-1);
        expect(requests[unsplit].body).toEqual({ categoryId: 56 });
        expect(update).toBeGreaterThan(unsplit);
        expect(showSuccess).toHaveBeenCalledWith('Transaction updated');
    });

    it('shows the refusal and stops when the unsplit is refused', async () => {
        mountForm({ categoryId: '56', split: false });
        serve((method) => (method === 'DELETE'
            ? { status: 403, body: { error: 'This shared item is read-only' } }
            : null));
        const mod = makeModule();

        await mod.saveTransaction();

        expect(requests.map(r => r.call)).not.toContain('PUT /apps/budget/api/transactions/43');
        expect(showError).toHaveBeenCalledWith('This shared item is read-only');
        expect(showSuccess).not.toHaveBeenCalled();
    });

    it('leaves the split alone while Split stays ticked', async () => {
        mountForm({ categoryId: '', split: true });
        serve();
        const mod = makeModule();
        mod.validateInlineSplits = () => ({ ok: true });
        mod.saveInlineSplits = vi.fn();

        await mod.saveTransaction();

        expect(requests.map(r => r.call)).not.toContain('DELETE /apps/budget/api/transactions/43/splits');
        expect(mod.saveInlineSplits).toHaveBeenCalledWith(43);
    });

    it('does not unsplit a transaction that was never split', async () => {
        mountForm({ categoryId: '56', split: false });
        serve();
        const mod = makeModule({ transactions: [{ ...SPLIT, isSplit: false, categoryId: 12 }] });

        await mod.saveTransaction();

        expect(requests.map(r => r.call)).toEqual(['PUT /apps/budget/api/transactions/43']);
    });
});

describe('opening a split transaction from outside the transactions list', () => {
    function openForm(mod, transaction) {
        mod.populateTransactionModalDropdowns = () => {};
        mod.setupAttachmentsSection = () => {};
        mod.setupReceiptScan = () => {};
        mod._bindTransferAmountFields = () => {};
        mod._updateAmountStep = () => {};
        mod.loadExistingSplits = vi.fn();
        mod.showTransactionModal(transaction);
    }

    it('shows it as split, so saving does not unsplit it', async () => {
        mountForm({ id: '', categoryId: '' });
        // An account's register: the transaction isn't in the list's page.
        const mod = makeModule({ transactions: [] });

        openForm(mod, SPLIT);

        expect(document.getElementById('transaction-split-toggle').checked).toBe(true);
        expect(mod.loadExistingSplits).toHaveBeenCalledWith(43);

        serve();
        mod.validateInlineSplits = () => ({ ok: true });
        mod.saveInlineSplits = vi.fn();
        await mod.saveTransaction();
        expect(requests.map(r => r.call)).not.toContain('DELETE /apps/budget/api/transactions/43/splits');
    });

    it('unsplits it when Split is unticked', async () => {
        mountForm({ id: '', categoryId: '' });
        const mod = makeModule({ transactions: [] });
        openForm(mod, SPLIT);

        const toggle = document.getElementById('transaction-split-toggle');
        toggle.checked = false;
        toggle.dispatchEvent(new Event('change'));
        document.getElementById('transaction-category').value = '56';
        serve();
        await mod.saveTransaction();

        expect(requests.map(r => r.call)).toContain('DELETE /apps/budget/api/transactions/43/splits');
    });
});
