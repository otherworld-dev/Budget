/**
 * Bill edit form: auto-pay availability and unshared dropdown values (#370).
 *
 * Two bugs met in the same modal. The auto-pay checkbox needs a pay-from
 * account, but the only code that worked that out hung off the account
 * dropdown's `change` event — and assigning a select's value in JS fires no
 * change — so opening any existing bill showed auto-pay greyed out even when
 * the bill already had an account.
 *
 * The dropdowns also only list what the current user can see. Share
 * permissions are per entity type, so a bill can arrive shared while its
 * category and pay-from account were not. Assigning an id with no matching
 * <option> silently yields "", and saveBill submitted that as null — stripping
 * both off the owner's bill.
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

import BillsModule from '../../src/modules/bills/BillsModule.js';

const OWN_ACCOUNT = { id: 9, name: 'Current Account', currency: 'GBP' };
const OWN_CATEGORY = { id: 3, name: 'Utilities', type: 'expense' };

const bill = (overrides = {}) => ({
    id: 1,
    name: 'Comcast',
    amount: 80,
    frequency: 'monthly',
    dueDay: 12,
    autoPayEnabled: false,
    ...overrides,
});

function makeModule() {
    const mod = Object.create(BillsModule.prototype);
    mod.app = {
        settings: {},
        bills: [],
        accounts: [OWN_ACCOUNT],
        categories: [OWN_CATEGORY],
        categoryTree: [OWN_CATEGORY],
    };
    // Both hit the network and neither is under test here
    mod.loadBillTagSets = vi.fn();
    mod.getSelectedBillTagIds = vi.fn(() => []);
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="bill-modal">
            <h3 id="bill-modal-title"></h3>
            <form id="bill-form">
                <input type="hidden" id="bill-id">
                <input type="text" id="bill-name">
                <input type="text" id="bill-description">
                <input type="number" id="bill-amount">
                <select id="bill-frequency">
                    <option value="monthly">monthly</option>
                    <option value="yearly">yearly</option>
                </select>
                <div id="due-day-group">
                    <label for="bill-due-day">Due Day</label>
                    <input type="number" id="bill-due-day">
                    <small id="bill-due-day-help"></small>
                </div>
                <div id="due-month-group"><select id="bill-due-month"></select></div>
                <div id="custom-months-group"><span id="bill-custom-months"></span></div>
                <div id="start-date-group"><input type="date" id="bill-start-date"></div>
                <div id="end-date-group"><input type="date" id="bill-end-date"></div>
                <div id="remaining-payments-group"><input type="number" id="bill-remaining-payments"></div>
                <div class="form-group"><select id="bill-category"></select></div>
                <div class="form-group"><select id="bill-account"></select></div>
                <input type="text" id="bill-auto-pattern">
                <textarea id="bill-notes"></textarea>
                <select id="bill-reminder-days"><option value=""></option></select>
                <input type="checkbox" id="bill-create-transaction">
                <div id="transaction-date-group"><input type="date" id="bill-transaction-date"></div>
                <input type="checkbox" id="bill-auto-pay">
                <div id="auto-pay-failed-warning"></div>
                <input type="checkbox" id="bill-excluded-from-forecast">
                <div id="bill-tags-container"></div>
                <input type="checkbox" id="bill-split-enabled">
                <div id="bill-split-container"><div id="bill-split-rows"></div></div>
            </form>
        </div>
    `;
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.restoreAllMocks();
});

describe('auto-pay availability', () => {
    it('enables the checkbox when the edited bill already has an account', () => {
        const mod = makeModule();
        // The real load order: listeners bind against an empty form (which is
        // what used to leave auto-pay disabled), then the dropdowns fill
        mod.setupBillsEventListeners();
        mod.populateBillModalDropdowns();

        mod.showBillModal(bill({ accountId: OWN_ACCOUNT.id, autoPayEnabled: true }));

        const autoPay = document.getElementById('bill-auto-pay');
        expect(autoPay.disabled).toBe(false);
        expect(autoPay.checked).toBe(true);
    });

    it('disables and clears the checkbox for a bill with no account', () => {
        const mod = makeModule();
        // The real load order: listeners bind against an empty form (which is
        // what used to leave auto-pay disabled), then the dropdowns fill
        mod.setupBillsEventListeners();
        mod.populateBillModalDropdowns();

        mod.showBillModal(bill({ accountId: null, autoPayEnabled: true }));

        const autoPay = document.getElementById('bill-auto-pay');
        expect(autoPay.disabled).toBe(true);
        expect(autoPay.checked).toBe(false);
    });

    it('stays available for a bill whose account is not shared with the user', () => {
        const mod = makeModule();
        // The real load order: listeners bind against an empty form (which is
        // what used to leave auto-pay disabled), then the dropdowns fill
        mod.setupBillsEventListeners();
        mod.populateBillModalDropdowns();

        mod.showBillModal(bill({ accountId: 42, autoPayEnabled: true }));

        const autoPay = document.getElementById('bill-auto-pay');
        expect(autoPay.disabled).toBe(false);
        expect(autoPay.checked).toBe(true);
    });
});

describe('values the dropdowns cannot render', () => {
    it('keeps an unshared account and category selected', () => {
        const mod = makeModule();
        // The real load order: listeners bind against an empty form (which is
        // what used to leave auto-pay disabled), then the dropdowns fill
        mod.setupBillsEventListeners();
        mod.populateBillModalDropdowns();

        mod.showBillModal(bill({ accountId: 42, categoryId: 77 }));

        expect(document.getElementById('bill-account').value).toBe('42');
        expect(document.getElementById('bill-category').value).toBe('77');
    });

    it('marks the placeholder disabled so it cannot be chosen again', () => {
        const mod = makeModule();
        // The real load order: listeners bind against an empty form (which is
        // what used to leave auto-pay disabled), then the dropdowns fill
        mod.setupBillsEventListeners();
        mod.populateBillModalDropdowns();

        mod.showBillModal(bill({ accountId: 42 }));

        const placeholder = document.querySelector('#bill-account option[data-unavailable="1"]');
        expect(placeholder).not.toBeNull();
        expect(placeholder.disabled).toBe(true);
        expect(placeholder.value).toBe('42');
    });

    it('does not add a placeholder when the account is in the list', () => {
        const mod = makeModule();
        // The real load order: listeners bind against an empty form (which is
        // what used to leave auto-pay disabled), then the dropdowns fill
        mod.setupBillsEventListeners();
        mod.populateBillModalDropdowns();

        mod.showBillModal(bill({ accountId: OWN_ACCOUNT.id }));

        expect(document.querySelector('#bill-account option[data-unavailable="1"]')).toBeNull();
        expect(document.getElementById('bill-account').value).toBe(String(OWN_ACCOUNT.id));
    });

    it('drops the placeholder when the next bill opened does not need one', () => {
        const mod = makeModule();
        // The real load order: listeners bind against an empty form (which is
        // what used to leave auto-pay disabled), then the dropdowns fill
        mod.setupBillsEventListeners();
        mod.populateBillModalDropdowns();

        mod.showBillModal(bill({ accountId: 42 }));
        mod.showBillModal(bill({ id: 2, accountId: OWN_ACCOUNT.id }));

        expect(document.querySelectorAll('#bill-account option[data-unavailable="1"]')).toHaveLength(0);
        expect(document.getElementById('bill-account').value).toBe(String(OWN_ACCOUNT.id));
    });

    it('submits the real ids instead of nulling them out', async () => {
        const mod = makeModule();
        mod.loadBillsView = vi.fn();
        mod.populateBillModalDropdowns();
        mod.showBillModal(bill({ accountId: 42, categoryId: 77 }));

        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({}) }));

        await mod.saveBill();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        const body = JSON.parse(global.fetch.mock.calls[0][1].body);
        expect(body.accountId).toBe(42);
        expect(body.categoryId).toBe(77);
    });
});
