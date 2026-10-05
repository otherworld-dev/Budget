/**
 * The category of a bill, transfer or recurring income someone shared with
 * you, when that category itself isn't shared with you.
 *
 * The item is shared, so its category is named, as on a transaction in an
 * account shared with you: the server sends the name with the item, and the
 * forms show the kept category as "<name> (not shared with you)". The
 * pickers still don't offer it, and a save sends it back unchanged.
 * Accounts are different: one that wasn't shared with you is never named,
 * so its kept option still reads "Unavailable (not shared with you)".
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
    showUndoNotification: vi.fn(),
}));

vi.mock('../../src/utils/datepicker.js', () => ({
    initSingleDatePicker: vi.fn(),
    setDateValue: (id, value) => { const el = document.getElementById(id); if (el) el.value = value || ''; },
    clearDateValue: (id) => { const el = document.getElementById(id); if (el) el.value = ''; },
}));

import BillsModule from '../../src/modules/bills/BillsModule.js';
import IncomeModule from '../../src/modules/income/IncomeModule.js';

// wendy sees owen's Joint (3) and Bills (4) accounts and his Groceries (31)
// and Salary (32); his Private account (5), Secret Stuff (77) and Secret
// Income (78) weren't shared with her
const JOINT = { id: 3, name: 'Owen Joint', currency: 'GBP', type: 'checking', userId: 'owen', _shared: true, _canWrite: true };
const BILLS = { id: 4, name: 'Owen Bills', currency: 'GBP', type: 'checking', userId: 'owen', _shared: true, _canWrite: true };
const CATEGORIES = [
    { id: 31, name: 'Groceries', type: 'expense', parentId: null, userId: 'owen', _shared: true },
    { id: 32, name: 'Salary', type: 'income', parentId: null, userId: 'owen', _shared: true },
];
const app = () => ({ settings: {}, accounts: [JOINT, BILLS], categories: CATEGORIES, categoryTree: CATEGORIES, bills: [] });

const selected = (id) => {
    const select = document.getElementById(id);
    const option = select.selectedOptions[0];
    return { value: select.value, text: option?.textContent.trim(), disabled: !!option?.disabled };
};
const offered = (select) => [...select.options].filter(o => o.value && !o.disabled).map(o => o.value);

function captureFetch() {
    const calls = [];
    global.fetch = vi.fn(async (url, options = {}) => {
        calls.push({ url: String(url), method: options.method || 'GET', body: options.body ? JSON.parse(options.body) : null });
        return { ok: true, json: async () => ([]) };
    });
    return calls;
}

beforeEach(() => {
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('the bill form', () => {
    function mountBillForm() {
        document.body.innerHTML = `
            <div id="bill-modal"><h3 id="bill-modal-title"></h3>
                <form id="bill-form">
                    <input type="hidden" id="bill-id"><input id="bill-name"><input id="bill-description"><input id="bill-amount">
                    <select id="bill-frequency"><option value="monthly">monthly</option></select>
                    <div id="due-day-group"><label for="bill-due-day"></label><input id="bill-due-day"><small id="bill-due-day-help"></small></div>
                    <div id="due-month-group"><select id="bill-due-month"></select></div>
                    <div id="custom-months-group"><span id="bill-custom-months"></span></div>
                    <div id="start-date-group"><input id="bill-start-date"></div>
                    <div id="end-date-group"><input id="bill-end-date"></div>
                    <div id="remaining-payments-group"><input id="bill-remaining-payments"></div>
                    <div class="form-group"><select id="bill-category"></select></div>
                    <div class="form-group"><select id="bill-account"></select></div>
                    <input id="bill-auto-pattern"><textarea id="bill-notes"></textarea>
                    <select id="bill-reminder-days"><option value=""></option></select>
                    <input type="checkbox" id="bill-create-transaction">
                    <div id="transaction-date-group"><input id="bill-transaction-date"></div>
                    <input type="checkbox" id="bill-auto-pay"><div id="auto-pay-failed-warning"></div>
                    <input type="checkbox" id="bill-excluded-from-forecast">
                    <div id="bill-tags-container"></div>
                    <input type="checkbox" id="bill-split-enabled">
                    <div id="bill-split-container"><span id="bill-split-remaining"></span><div id="bill-split-rows"></div></div>
                </form>
            </div>`;
    }

    function makeBills() {
        const mod = Object.create(BillsModule.prototype);
        mod.app = app();
        mod.loadBillTagSets = vi.fn();
        mod.getSelectedBillTagIds = vi.fn(() => []);
        mod.setupBillsEventListeners();
        mod.populateBillModalDropdowns();
        return mod;
    }

    it('names the kept category, and still keeps it out of the choices', () => {
        mountBillForm();
        makeBills().showBillModal({ id: 1, name: 'Phone', amount: 25, frequency: 'monthly', dueDay: 25, accountId: JOINT.id, categoryId: 77, categoryName: 'Secret Stuff', _shared: true, _canWrite: true });

        expect(selected('bill-category')).toEqual({ value: '77', text: 'Secret Stuff (not shared with you)', disabled: true });
        expect(offered(document.getElementById('bill-category'))).toEqual(['31']);
    });

    it('names a kept split part', () => {
        mountBillForm();
        makeBills().showBillModal({
            id: 2, name: 'Council Tax', amount: 100, frequency: 'monthly', dueDay: 15, accountId: JOINT.id, categoryId: null,
            splitTemplate: [
                { categoryId: 31, amount: 70, description: 'food', categoryName: 'Groceries' },
                { categoryId: 77, amount: 30, description: 'secret', categoryName: 'Secret Stuff' },
            ],
            _shared: true, _canWrite: true,
        });

        const parts = [...document.querySelectorAll('.bill-split-category')];
        expect(parts.map(s => [s.value, s.selectedOptions[0].textContent.trim(), s.selectedOptions[0].disabled])).toEqual([
            ['31', 'Groceries', false],
            ['77', 'Secret Stuff (not shared with you)', true],
        ]);
    });

    it('still leaves an account that wasn\'t shared unnamed', () => {
        mountBillForm();
        makeBills().showBillModal({ id: 3, name: 'Gym', amount: 32, frequency: 'monthly', dueDay: 21, accountId: 5, categoryId: 31, _shared: true, _canWrite: true });

        expect(selected('bill-account')).toEqual({ value: '5', text: 'Unavailable (not shared with you)', disabled: true });
    });

    it('asks for tag sets only for a category you can see', async () => {
        const calls = captureFetch();
        document.body.innerHTML = '<div id="bill-tags-container"></div>';
        const mod = Object.create(BillsModule.prototype);
        mod.app = app();

        await mod.loadBillTagSets(77, { tagIds: [] });
        await mod.loadBillTagSets(31, { tagIds: [] });

        expect(calls.map(c => c.url).filter(u => u.includes('tag-sets'))).toEqual(['/apps/budget/api/tag-sets?categoryId=31']);
    });
});

describe('the recurring income form', () => {
    function mountIncomeForm() {
        document.body.innerHTML = `
            <div id="income-modal"><h3 id="income-modal-title"></h3>
                <form id="income-form">
                    <input type="hidden" id="income-id"><input id="income-name"><input id="income-description">
                    <input id="income-amount"><input id="income-source">
                    <select id="income-frequency"><option value="monthly">monthly</option></select>
                    <div id="expected-day-group"><label></label><input id="income-expected-day"><small id="income-expected-day-help"></small></div>
                    <div id="expected-month-group"><select id="income-expected-month"></select></div>
                    <select id="income-category"><option value="">No category</option><option value="32">Salary</option></select>
                    <select id="income-account"><option value="">None</option><option value="3">Owen Joint</option></select>
                    <input id="income-auto-pattern"><textarea id="income-notes"></textarea>
                    <div id="income-start-date-group"><label></label><input id="income-start-date"></div>
                    <input type="checkbox" id="income-auto-create"><input type="checkbox" id="income-excluded-from-forecast">
                </form>
            </div>`;
    }

    it('names the kept category, and leaves an unshared account unnamed', () => {
        mountIncomeForm();
        const mod = Object.create(IncomeModule.prototype);
        mod.app = app();

        mod.showIncomeModal({ id: 1, name: 'Owen Salary', amount: 2000, frequency: 'monthly', expectedDay: 28, accountId: 5, categoryId: 78, categoryName: 'Secret Income', _shared: true, _canWrite: true });

        expect(selected('income-category')).toEqual({ value: '78', text: 'Secret Income (not shared with you)', disabled: true });
        expect(selected('income-account')).toEqual({ value: '5', text: 'Unavailable (not shared with you)', disabled: true });
    });
});
