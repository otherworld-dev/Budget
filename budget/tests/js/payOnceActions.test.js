/**
 * Mark Paid, Mark Received, Skip and Mark Unpaid run once at a time per bill
 * or income, and ask before paying an occurrence twice (#399).
 *
 * Two clicks on Mark Paid 150 ms apart both booked a payment, and a refused
 * second click still showed a success toast beside its error. Each action
 * now holds its bill (or income) from the first request to the last, with
 * the button disabled, and Skip names the occurrence the row showed.
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

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(true)),
}));

import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import IncomeModule from '../../src/modules/income/IncomeModule.js';
import { showError, showWarning, showSuccess, showUndoNotification } from '../../src/utils/notifications.js';

const EXISTING = { id: 1350727, date: '2026-09-27', amount: 151.67 };

let requests;
/** Each POST answers with the next of these, then 200 {} */
let answers;
/** Holds every response until released, to click again while one runs */
let gate;

beforeEach(() => {
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    requests = [];
    answers = [];
    gate = null;
    global.fetch = vi.fn(async (url, init = {}) => {
        requests.push({ method: init.method || 'GET', url, body: init.body ? JSON.parse(init.body) : null });
        if (gate) {
            await gate.promise;
        }
        const [status, data] = (init.method === 'POST' && answers.shift()) || [200, url.includes('matching-transactions') ? [] : {}];
        return { ok: status < 300, status, headers: { get: () => null }, json: async () => data };
    });
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

function holdResponses() {
    let release;
    const promise = new Promise(resolve => { release = resolve; });
    gate = { promise, release: () => { gate = null; release(); } };
    return gate;
}

const posts = (suffix) => requests.filter(r => r.method === 'POST' && r.url.endsWith(suffix));
const dialog = () => document.getElementById('payment-recorded-modal');
const conflict = (code = 'payment_recorded') => [409, { error: 'This bill already has a payment on 2026-09-27.', code, existing: EXISTING }];

function billsModule(bill = {}) {
    const mod = Object.create(BillsModule.prototype);
    mod.app = {
        settings: {},
        accounts: [{ id: 3, currency: 'CHF' }],
        bills: [{ id: 5, name: 'CembraPay Temu', amount: 151.67, frequency: 'one-time', nextDueDate: '2026-09-28', accountId: 3, isActive: true, ...bill }],
    };
    mod.loadBillsView = vi.fn(async () => {});
    return mod;
}

function transfersModule() {
    const mod = Object.create(TransfersModule.prototype);
    mod.app = { settings: {}, accounts: [{ id: 3, currency: 'CHF' }] };
    mod.transfers = [{ id: 7, name: 'CSX Pro', amount: 100, frequency: 'monthly', nextDueDate: '2026-10-28', accountId: 3, isTransfer: true, isActive: true }];
    mod.loadTransfers = vi.fn(async () => {});
    mod.renderTransfers = vi.fn();
    mod.updateSummary = vi.fn();
    return mod;
}

function incomeModule() {
    const mod = Object.create(IncomeModule.prototype);
    mod.app = { settings: {}, accounts: [], recurringIncome: [{ id: 9, name: 'SBB Abo', frequency: 'monthly', nextExpectedDate: '2026-09-29', accountId: 3 }] };
    mod.loadIncomeView = vi.fn(async () => {});
    return mod;
}

describe('bills', () => {
    it('pays once however often Mark Paid is clicked while it runs', async () => {
        const mod = billsModule();
        const button = document.createElement('button');
        const held = holdResponses();

        const first = mod.markBillPaid(5, button);
        await vi.waitFor(() => expect(requests).toHaveLength(1));
        expect(button.disabled).toBe(true);
        await mod.markBillPaid(5, button);
        held.release();
        await first;

        expect(posts('/paid')).toHaveLength(1);
        expect(button.disabled).toBe(false);
        expect(showError).not.toHaveBeenCalled();
    });

    it('asks about the payment already there, and uses it', async () => {
        const mod = billsModule();
        answers = [conflict(), [200, { bill: { id: 5 }, paymentTransactionRecorded: true }]];

        const paying = mod.markBillPaid(5);
        await vi.waitFor(() => expect(dialog()).not.toBeNull());
        dialog().querySelector('.confirm-btn').click();
        await paying;

        expect(posts('/paid').map(r => r.body)).toEqual([
            { recordPayment: true, dueDate: '2026-09-28' },
            { recordPayment: false, paidDate: '2026-09-27', dueDate: '2026-09-28' },
        ]);
        expect(showUndoNotification.mock.calls[0][0]).toContain('Marked as paid with the payment of');
        expect(showWarning).not.toHaveBeenCalled();
    });

    it('records a second payment when asked to', async () => {
        const mod = billsModule();
        answers = [conflict(), [200, { bill: { id: 5 }, paymentTransactionRecorded: true }]];

        const paying = mod.markBillPaid(5);
        await vi.waitFor(() => expect(dialog()).not.toBeNull());
        dialog().querySelector('.another-btn').click();
        await paying;

        expect(posts('/paid')[1].body).toEqual({ recordPayment: true, dueDate: '2026-09-28', confirmSecondPayment: true });
    });

    it('leaves the bill as it was when the user cancels', async () => {
        const mod = billsModule();
        answers = [conflict()];

        const paying = mod.markBillPaid(5);
        await vi.waitFor(() => expect(dialog()).not.toBeNull());
        dialog().querySelector('.cancel-btn').click();
        await paying;

        expect(posts('/paid')).toHaveLength(1);
        expect(mod.loadBillsView).not.toHaveBeenCalled();
        expect(showUndoNotification).not.toHaveBeenCalled();
        expect(showError).not.toHaveBeenCalled();
    });

    it('holds the bill while Mark Paid asks, so Skip waits', async () => {
        const mod = billsModule({ frequency: 'monthly' });
        answers = [conflict()];

        const paying = mod.markBillPaid(5);
        await vi.waitFor(() => expect(dialog()).not.toBeNull());
        await mod.skipBillPayment(5);
        dialog().querySelector('.cancel-btn').click();
        await paying;

        expect(posts('/skip')).toHaveLength(0);
    });

    it('skips the occurrence the row showed, once', async () => {
        const mod = billsModule({ frequency: 'monthly', nextDueDate: '2026-10-28' });
        const held = holdResponses();

        const first = mod.skipBillPayment(5);
        await vi.waitFor(() => expect(requests).toHaveLength(1));
        await mod.skipBillPayment(5);
        held.release();
        await first;

        expect(posts('/skip').map(r => r.body)).toEqual([{ dueDate: '2026-10-28' }]);
    });

    it('says why a skip was refused', async () => {
        const mod = billsModule({ frequency: 'monthly' });
        answers = [[400, { error: 'This payment was already recorded. Reload the page to see the next one.' }]];

        await mod.skipBillPayment(5);

        expect(showError).toHaveBeenCalledWith('This payment was already recorded. Reload the page to see the next one.');
    });

    it('marks a bill unpaid once however often it is clicked', async () => {
        const mod = billsModule();
        const held = holdResponses();

        const first = mod.markBillUnpaid(5);
        await vi.waitFor(() => expect(requests).toHaveLength(1));
        await mod.markBillUnpaid(5);
        held.release();
        await first;

        expect(posts('/unpaid')).toHaveLength(1);
    });
});

describe('transfers', () => {
    it('pays once however often Mark Paid is clicked while it runs', async () => {
        const mod = transfersModule();
        const held = holdResponses();

        const first = mod.markTransferPaid(7);
        await vi.waitFor(() => expect(requests).toHaveLength(1));
        await mod.markTransferPaid(7);
        held.release();
        await first;

        expect(posts('/paid')).toHaveLength(1);
    });

    it('uses the payment already there, without the nothing-recorded warning', async () => {
        const mod = transfersModule();
        answers = [conflict(), [200, { bill: { id: 7 }, paymentTransactionRecorded: true }]];

        const paying = mod.markTransferPaid(7);
        await vi.waitFor(() => expect(dialog()).not.toBeNull());
        dialog().querySelector('.confirm-btn').click();
        await paying;

        expect(posts('/paid')[1].body).toEqual({ recordPayment: false, paidDate: '2026-09-27', dueDate: '2026-10-28' });
        expect(showWarning).not.toHaveBeenCalled();
        expect(showSuccess.mock.calls[0][0]).toContain('Marked as paid with the payment of');
    });

    it('skips the occurrence the row showed', async () => {
        const mod = transfersModule();
        answers = [[200, { bill: { id: 7 }, previousNextDueDate: '2026-10-28' }]];

        await mod.skipTransfer(7);

        expect(posts('/skip').map(r => r.body)).toEqual([{ dueDate: '2026-10-28' }]);
    });
});

describe('income', () => {
    it('receives once however often Mark Received is clicked while it runs', async () => {
        const mod = incomeModule();
        const held = holdResponses();

        const first = mod.markIncomeReceived(9);
        await vi.waitFor(() => expect(requests).toHaveLength(1));
        await mod.markIncomeReceived(9);
        held.release();
        await first;

        expect(posts('/received')).toHaveLength(1);
    });

    it('asks about the credit already there, and uses it', async () => {
        const mod = incomeModule();
        answers = [conflict('receipt_recorded'), [200, { id: 9 }]];

        const receiving = mod.markIncomeReceived(9);
        await vi.waitFor(() => expect(dialog()).not.toBeNull());
        expect(dialog().textContent).toContain('This income already has a payment on');
        dialog().querySelector('.confirm-btn').click();
        await receiving;

        expect(posts('/received')[1].body).toEqual({ createTransaction: false, receivedDate: '2026-09-27', expectedDate: '2026-09-29' });
        expect(showUndoNotification.mock.calls[0][0]).toContain('Marked as received with the payment of');
    });

    it('skips the payment the row showed, once', async () => {
        const mod = incomeModule();
        const held = holdResponses();

        const first = mod.skipIncome(9);
        await vi.waitFor(() => expect(requests).toHaveLength(1));
        await mod.skipIncome(9);
        held.release();
        await first;

        expect(posts('/skip').map(r => r.body)).toEqual([{ expectedDate: '2026-09-29' }]);
    });
});
