/**
 * The Bills page against the bill's own occurrences (#399).
 *
 * - A row's status follows its next occurrence, so a weekly bill can be
 *   paid every week rather than once a month.
 * - Mark Paid names the occurrence the row showed, so the server refuses a
 *   double click.
 * - A one-time bill opens with its due date even when it was stored only as
 *   the next due date, and can't be saved without one.
 * - The tag picker loads for a bill with no category of its own.
 * - The toast only says a future transaction was created when one was.
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

import BillsModule from '../../src/modules/bills/BillsModule.js';
import { showError, showUndoNotification } from '../../src/utils/notifications.js';

function today() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function daysFromToday(n) {
    const d = new Date();
    d.setDate(d.getDate() + n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function makeModule(bills = []) {
    const mod = Object.create(BillsModule.prototype);
    mod.app = { settings: {}, bills, accounts: [], categories: [], categoryTree: [] };
    mod.loadBillsView = vi.fn(async () => {});
    mod.loadBillTagSets = vi.fn();
    mod.getSelectedBillTagIds = vi.fn(() => []);
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="bills-list"></div><div id="empty-bills"></div>
        <div id="bill-modal">
            <h3 id="bill-modal-title"></h3>
            <form id="bill-form">
                <input type="hidden" id="bill-id">
                <input type="text" id="bill-name">
                <input type="text" id="bill-description">
                <input type="number" id="bill-amount">
                <select id="bill-frequency">
                    <option value="monthly">monthly</option>
                    <option value="weekly">weekly</option>
                    <option value="quarterly">quarterly</option>
                    <option value="one-time">one-time</option>
                </select>
                <div id="due-day-group"><label>Due Day</label><input type="number" id="bill-due-day"><small id="bill-due-day-help"></small></div>
                <div id="due-month-group"><select id="bill-due-month"></select></div>
                <div id="custom-months-group"><span id="bill-custom-months"></span></div>
                <div id="start-date-group"><label>Start</label><input type="date" id="bill-start-date"><small id="bill-start-date-help"></small></div>
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
    vi.clearAllMocks();
});

describe('the bill list', () => {
    it('offers Mark Paid on a weekly bill paid last week and due today', () => {
        const mod = makeModule();

        mod.renderBills([{ id: 4, name: 'Cleaner', amount: 40, frequency: 'weekly', isActive: true,
            lastPaidDate: daysFromToday(-7), nextDueDate: today() }]);

        expect(document.querySelectorAll('.bill-paid-btn')).toHaveLength(1);
    });

    it('shows a monthly bill paid for this cycle as paid', () => {
        const mod = makeModule();

        mod.renderBills([{ id: 5, name: 'Rent', amount: 800, frequency: 'monthly', isActive: true,
            lastPaidDate: daysFromToday(-2), nextDueDate: daysFromToday(28) }]);

        expect(document.querySelector('.bill-card').dataset.status).toBe('paid');
        expect(document.querySelectorAll('.bill-paid-btn')).toHaveLength(0);
    });
});

describe('Mark Paid', () => {
    it('names the occurrence the row showed', async () => {
        const mod = makeModule([{ id: 5, name: 'Rent', frequency: 'monthly', nextDueDate: '2026-10-15', accountId: null }]);
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ bill: { id: 5 }, paymentTransactionRecorded: false }) }));

        await mod.markBillPaid(5);

        const [, init] = global.fetch.mock.calls[0];
        expect(JSON.parse(init.body).dueDate).toBe('2026-10-15');
    });

    it('says a future transaction was created only when the bill pre-books', async () => {
        const mod = makeModule([{ id: 5, name: 'Rent', frequency: 'monthly', nextDueDate: '2026-10-15', accountId: null, createTransaction: false }]);
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ bill: { id: 5 }, paymentTransactionRecorded: true }) }));

        await mod._executeMarkPaid(5, mod.app.bills[0], { action: 'create' });

        expect(showUndoNotification.mock.calls[0][0]).not.toContain('Future transaction');
    });

    it('lets an older toast expire without dropping a newer action\'s undo', async () => {
        const mod = makeModule([{ id: 5, name: 'Rent', frequency: 'monthly', nextDueDate: '2026-10-15' }]);
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ bill: { id: 5 }, paymentTransactionRecorded: true }) }));

        await mod._executeMarkPaid(5, mod.app.bills[0], { action: 'create' });
        const [, , expireFirst] = showUndoNotification.mock.calls[0];
        await mod._executeMarkPaid(5, mod.app.bills[0], { action: 'create' });
        const newer = mod._undoData;
        expireFirst();

        expect(mod._undoData).toBe(newer);
    });

    it('shows the server\'s reason when it refuses', async () => {
        const mod = makeModule([{ id: 5, name: 'Rent', frequency: 'monthly', nextDueDate: '2026-10-15' }]);
        global.fetch = vi.fn(async () => ({ ok: false, status: 400, json: async () => ({ error: 'This bill has nothing left to pay' }) }));

        await mod.markBillPaid(5);

        expect(showError).toHaveBeenCalledWith('This bill has nothing left to pay');
    });
});

describe('the bill form', () => {
    it('opens a one-time bill with its due date when only the next due date holds it', () => {
        // The form read the start date only, so the date showed empty (#399)
        const mod = makeModule();

        mod.showBillModal({ id: 7, name: 'Garage', amount: 33.5, frequency: 'one-time', startDate: null, nextDueDate: '2027-01-01' });

        expect(document.getElementById('bill-start-date').value).toBe('2027-01-01');
    });

    it('refuses to save a one-time bill with no due date', async () => {
        // The date input sits in a datepicker that hides the native one, so
        // its required flag was never enforced
        const mod = makeModule();
        global.fetch = vi.fn();
        mod.showBillModal(null);
        document.getElementById('bill-frequency').value = 'one-time';
        document.getElementById('bill-name').value = 'Garage';
        document.getElementById('bill-amount').value = '33.5';

        await mod.saveBill();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(showError).toHaveBeenCalled();
    });

    it('takes a weekly bill weekday from its start date and locks it', () => {
        // The start date decided the weekday on the server while the weekday
        // field stayed editable, and whatever it said was ignored
        const mod = makeModule();
        mod.showBillModal({ id: 9, name: 'Cleaner', amount: 40, frequency: 'weekly', dueDay: 1, startDate: '2026-10-02' });

        const dueDay = document.getElementById('bill-due-day');
        expect(dueDay.value).toBe('5');
        expect(dueDay.disabled).toBe(true);
    });

    it('asks a quarterly bill for its month', () => {
        // Hidden, a quarterly bill always fell on the January grid
        const mod = makeModule();
        mod.showBillModal({ id: 10, name: 'Water', amount: 90, frequency: 'quarterly', dueDay: 1, dueMonth: 2 });

        expect(document.getElementById('due-month-group').style.display).toBe('block');
    });

    it('loads the tag picker for a bill with no category', () => {
        // A bill split across categories has none of its own, and saving it
        // stripped its tags because the picker never loaded
        const mod = makeModule();

        mod.showBillModal({ id: 8, name: 'Shop', amount: 50, frequency: 'monthly', categoryId: null, tagIds: [3] });

        expect(mod.loadBillTagSets).toHaveBeenCalledWith(null, expect.objectContaining({ id: 8 }));
    });
});
