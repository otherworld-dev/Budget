/**
 * The transaction form with a vendor and no description (#399).
 *
 * Mark Received books its credit with the income's name as the vendor and
 * no description, and Mark Paid does the same for a bill's payment. The
 * reporter was told to move the Swisscom credit to 3 Oct in the edit form,
 * and Save did nothing: Description was required and empty. A vendor now
 * names the transaction well enough, so Description is required only while
 * the vendor is empty, on a new transaction and an edit alike.
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

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import { showSuccess } from '../../src/utils/notifications.js';

const LOHNKONTO = { id: 3, name: 'Lohnkonto', currency: 'CHF' };

/** The credit Mark Received booked for Gutschrift Swisscom Abo Leon */
const SWISSCOM = {
    id: 1700, accountId: 3, date: '2026-10-19', type: 'credit', amount: 14.9,
    description: '', vendor: 'Gutschrift Swisscom Abo Leon', categoryId: null,
    notes: 'Auto-generated from income: Gutschrift Swisscom Abo Leon',
    reconciled: false, excludedFromForecast: false,
};

function mountForm() {
    document.body.innerHTML = `
        <div id="transaction-modal" style="display: none;">
            <h2 id="transaction-modal-title"></h2>
            <form id="transaction-form">
                <input type="hidden" id="transaction-id">
                <input type="date" id="transaction-date" required>
                <select id="transaction-account" required><option value="">Choose</option></select>
                <select id="transaction-type" required>
                    <option value="">Choose</option>
                    <option value="debit">Expense</option>
                    <option value="credit">Income</option>
                </select>
                <input type="number" id="transaction-amount" step="0.01" required min="0">
                <label for="transaction-description">Description <span class="required">*</span></label>
                <input type="text" id="transaction-description" required maxlength="255">
                <label for="transaction-vendor">Vendor</label>
                <input type="text" id="transaction-vendor" maxlength="255">
                <div id="transaction-category-group"><select id="transaction-category"><option value="">No category</option></select></div>
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

function makeModule() {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = {
        transactions: [SWISSCOM],
        accounts: [LOHNKONTO],
        categories: [],
        categoryTree: [],
        renderTransactionTagSelectors: vi.fn(),
        getCategoryOptions: vi.fn(() => ''),
        hideModals: vi.fn(),
        loadTransactions: vi.fn(),
        loadAccounts: vi.fn(),
        refreshCurrentAccountView: vi.fn(),
        loadDashboard: vi.fn(),
        tagSetsModule: { saveTransactionTags: vi.fn() },
    };
    mod._pendingAttachments = [];
    mod._scannedReceiptFile = null;
    mod._allowNegativeRemainder = false;
    mod.setupAttachmentsSection = () => {};
    mod.setupReceiptScan = () => {};
    mod._bindTransferAmountFields = () => {};
    mod._updateAmountStep = () => {};
    mod.loadExistingSplits = vi.fn();
    mod.flushPendingAttachments = vi.fn(async () => 0);
    return mod;
}

const form = () => document.getElementById('transaction-form');
const description = () => document.getElementById('transaction-description');
const asterisk = () => document.querySelector('label[for="transaction-description"] .required');
const type = (id, value) => {
    const input = document.getElementById(id);
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
};

let requests;

beforeEach(() => {
    mountForm();
    requests = [];
    globalThis.OC = { generateUrl: (p) => p, requestToken: 'tok' };
    globalThis.fetch = vi.fn(async (url, init = {}) => {
        requests.push({ call: `${init.method || 'GET'} ${url}`, body: init.body ? JSON.parse(init.body) : null });
        return { ok: true, status: 200, headers: { get: () => null }, json: async () => ({ id: 1700 }) };
    });
    window.localStorage.clear();
    vi.clearAllMocks();
});

afterEach(() => {
    delete globalThis.fetch;
    delete globalThis.OC;
    document.body.innerHTML = '';
});

describe('Description on the transaction form', () => {
    it('lets the Swisscom credit be saved with a new date and no description', async () => {
        const mod = makeModule();
        mod.showTransactionModal(SWISSCOM);
        document.getElementById('transaction-date').value = '2026-10-03';

        expect(description().value).toBe('');
        expect(description().required).toBe(false);
        expect(asterisk().hidden).toBe(true);
        expect(form().checkValidity()).toBe(true);

        await mod.saveTransaction();

        const put = requests.find(r => r.call === 'PUT /apps/budget/api/transactions/1700');
        expect(put.body).toMatchObject({ date: '2026-10-03', description: '', vendor: 'Gutschrift Swisscom Abo Leon' });
        expect(showSuccess).toHaveBeenCalledWith('Transaction updated');
    });

    it('still asks for one on a new transaction with no vendor', () => {
        makeModule().showTransactionModal(null);

        expect(description().required).toBe(true);
        expect(asterisk().hidden).toBe(false);
        expect(description().validity.valueMissing).toBe(true);
    });

    it('follows the vendor as it is typed', () => {
        makeModule().showTransactionModal(null);

        type('transaction-vendor', 'Coop');
        expect(description().required).toBe(false);
        expect(asterisk().hidden).toBe(true);

        type('transaction-vendor', '   ');
        expect(description().required).toBe(true);

        type('transaction-vendor', '');
        expect(description().required).toBe(true);
        expect(asterisk().hidden).toBe(false);
    });

    it('takes a vendor a receipt scan filled in', () => {
        makeModule().showTransactionModal(null);
        const vendor = document.getElementById('transaction-vendor');

        // applyReceiptDraft() fills a field and fires change on it
        vendor.value = 'Migros';
        vendor.dispatchEvent(new Event('change', { bubbles: true }));

        expect(description().required).toBe(false);
    });

    it('asks again on a new transaction opened after editing one with a vendor', () => {
        const mod = makeModule();
        mod.showTransactionModal(SWISSCOM);
        expect(description().required).toBe(false);

        mod.showTransactionModal(null);

        expect(document.getElementById('transaction-vendor').value).toBe('');
        expect(description().required).toBe(true);
        expect(asterisk().hidden).toBe(false);
    });

    it('asks for one when editing a row with neither', () => {
        makeModule().showTransactionModal({ ...SWISSCOM, vendor: null });

        expect(description().required).toBe(true);
        expect(form().checkValidity()).toBe(false);
    });
});
