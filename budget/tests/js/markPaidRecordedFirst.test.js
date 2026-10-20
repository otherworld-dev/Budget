/**
 * Mark Paid asks about the payment a bill already has before anything else
 * (#399).
 *
 * It opened the "Existing Transaction Found" dialog first, which listed
 * unrelated rows (five grocery lines for CembraPay's 4.72, a "Diverses 80.00"
 * for Corner Card's 100) and not the bill's own payment. Its Create new led
 * to the already-has-a-payment question; its other two choices never did.
 * Linking a row paid the bill a second time with it, and "just mark as paid"
 * marked it paid today with a warning that nothing was recorded, though the
 * payment was in the ledger. The page now looks the payment up first and
 * asks; "Record another payment" goes on to the usual dialog and says so on
 * whatever it ends in.
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

vi.mock('../../src/utils/matchingDialog.js', () => ({
    showMatchingTransactionDialog: vi.fn(),
}));

import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import { showMatchingTransactionDialog } from '../../src/utils/matchingDialog.js';
import { showError, showWarning, showSuccess, showUndoNotification } from '../../src/utils/notifications.js';

/** The Monatsrechnung linked to the new Corner Card SA bill */
const EXISTING = { id: 1599574, date: '2026-09-28', amount: 100 };
const DIVERSES = { transaction: { id: 1599001, accountId: 3, date: '2026-09-26', amount: 80, description: 'Diverses' }, score: 30, matchReasons: ['approximate_amount'] };

let requests;
/** What the lookup answers: [status, body] */
let lookup;
let candidates;
/** Each POST answers with the next of these, then 200 */
let answers;

beforeEach(() => {
    globalThis.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    requests = [];
    lookup = [200, { existing: EXISTING }];
    candidates = [DIVERSES];
    answers = [];
    globalThis.fetch = vi.fn(async (url, init = {}) => {
        const method = init.method || 'GET';
        requests.push({ method, url, body: init.body ? JSON.parse(init.body) : null });
        let status = 200;
        let data = { bill: { id: 5 }, paymentTransactionRecorded: true };
        if (url.includes('/recorded-payment')) {
            [status, data] = lookup;
        } else if (url.includes('/matching-transactions')) {
            data = candidates;
        } else if (method === 'POST' && answers.length > 0) {
            [status, data] = answers.shift();
        }
        return { ok: status < 300, status, headers: { get: () => null }, json: async () => data };
    });
});

afterEach(() => {
    document.body.innerHTML = '';
    delete globalThis.OC;
    delete globalThis.fetch;
    vi.clearAllMocks();
});

const dialog = () => document.getElementById('payment-recorded-modal');
const answer = async (selector) => {
    await vi.waitFor(() => expect(dialog()).not.toBeNull());
    dialog().querySelector(selector).click();
};
const posts = () => requests.filter(r => r.method === 'POST').map(r => r.body);
const asked = (path) => requests.some(r => r.url.includes(path));

function billsModule(bill = {}) {
    const mod = Object.create(BillsModule.prototype);
    mod.app = {
        settings: {},
        accounts: [{ id: 3, currency: 'CHF' }],
        bills: [{ id: 5, name: 'Corner Card SA', amount: 100, frequency: 'one-time', nextDueDate: '2026-09-28', accountId: 3, isActive: true, ...bill }],
    };
    mod.loadBillsView = vi.fn(async () => {});
    return mod;
}

function transfersModule() {
    const mod = Object.create(TransfersModule.prototype);
    mod.app = { settings: {}, accounts: [{ id: 3, currency: 'CHF' }, { id: 4, currency: 'CHF' }] };
    mod.transfers = [{ id: 7, name: 'CSX Pro', amount: 100, frequency: 'monthly', nextDueDate: '2026-10-28', accountId: 3, destinationAccountId: 4, isTransfer: true, isActive: true }];
    mod.loadTransfers = vi.fn(async () => {});
    mod.renderTransfers = vi.fn();
    mod.updateSummary = vi.fn();
    return mod;
}

describe('Mark Paid on a bill', () => {
    it('looks up the payment for the occurrence the row showed before anything else', async () => {
        lookup = [200, { existing: null }];
        showMatchingTransactionDialog.mockResolvedValue(null);

        await billsModule().markBillPaid(5);

        expect(requests[0]).toMatchObject({ method: 'GET', url: '/apps/budget/api/bills/5/recorded-payment?dueDate=2026-09-28' });
        expect(requests[1].url).toBe('/apps/budget/api/bills/5/matching-transactions');
    });

    it('asks about the payment already there before offering rows to link', async () => {
        const paying = billsModule().markBillPaid(5);

        await vi.waitFor(() => expect(dialog()).not.toBeNull());
        expect(dialog().textContent).toContain('This bill already has a payment on 2026-09-28');
        expect(asked('/matching-transactions')).toBe(false);
        expect(showMatchingTransactionDialog).not.toHaveBeenCalled();

        dialog().querySelector('.cancel-btn').click();
        await paying;
    });

    it('uses that payment without offering rows to link or warning that nothing was recorded', async () => {
        const paying = billsModule().markBillPaid(5);
        await answer('.confirm-btn');
        await paying;

        expect(asked('/matching-transactions')).toBe(false);
        expect(showMatchingTransactionDialog).not.toHaveBeenCalled();
        expect(posts()).toEqual([{ recordPayment: false, paidDate: '2026-09-28', dueDate: '2026-09-28' }]);
        expect(showUndoNotification.mock.calls[0][0]).toBe('Marked as paid with the payment of 2026-09-28');
        expect(showWarning).not.toHaveBeenCalled();
    });

    it('leaves the bill as it was when the user cancels', async () => {
        const mod = billsModule();
        const paying = mod.markBillPaid(5);
        await answer('.cancel-btn');
        await paying;

        expect(requests).toHaveLength(1);
        expect(mod.loadBillsView).not.toHaveBeenCalled();
        expect(showError).not.toHaveBeenCalled();
    });

    it.each([
        ['links the row chosen', { action: 'link', transactionId: 1599001 }, { existingTransactionId: 1599001, recordPayment: false }],
        ['books a new one', { action: 'create' }, { recordPayment: true }],
        ['marks it paid with no row', { action: 'skip' }, { recordPayment: false }],
    ])('goes on to the rows to link for another payment, and %s as a confirmed second payment', async (_name, choice, sent) => {
        showMatchingTransactionDialog.mockResolvedValue(choice);

        const paying = billsModule().markBillPaid(5);
        await answer('.another-btn');
        await paying;

        expect(showMatchingTransactionDialog).toHaveBeenCalledTimes(1);
        expect(posts()).toEqual([{ ...sent, dueDate: '2026-09-28', confirmSecondPayment: true }]);
        // One question about the payment, not a second after the first
        expect(document.querySelectorAll('#payment-recorded-modal')).toHaveLength(0);
    });

    it('books another payment straight away when there are no rows to link', async () => {
        candidates = [];

        const paying = billsModule().markBillPaid(5);
        await answer('.another-btn');
        await paying;

        expect(showMatchingTransactionDialog).not.toHaveBeenCalled();
        expect(posts()).toEqual([{ recordPayment: true, dueDate: '2026-09-28', confirmSecondPayment: true }]);
    });

    it('leaves it when the rows to link are dismissed after asking for another payment', async () => {
        showMatchingTransactionDialog.mockResolvedValue(null);

        const paying = billsModule().markBillPaid(5);
        await answer('.another-btn');
        await paying;

        expect(posts()).toEqual([]);
    });

    it('confirms nothing when there is no payment to ask about', async () => {
        lookup = [200, { existing: null }];
        showMatchingTransactionDialog.mockResolvedValue({ action: 'create' });

        await billsModule().markBillPaid(5);

        expect(dialog()).toBeNull();
        expect(posts()).toEqual([{ recordPayment: true, dueDate: '2026-09-28' }]);
    });

    it('carries on as before when the lookup fails, and the server still asks', async () => {
        lookup = [500, { error: 'Failed to mark bill as paid' }];
        candidates = [];
        answers = [[409, { error: 'This bill already has a payment on 2026-09-28.', code: 'payment_recorded', existing: EXISTING }]];

        const paying = billsModule().markBillPaid(5);
        await answer('.confirm-btn');
        await paying;

        expect(posts()).toEqual([
            { recordPayment: true, dueDate: '2026-09-28' },
            { recordPayment: false, paidDate: '2026-09-28', dueDate: '2026-09-28' },
        ]);
        expect(showError).not.toHaveBeenCalled();
    });

    it('asks about a bill with no account too', async () => {
        // It pays from anyone's account, and Mark Paid offers no rows for it
        const paying = billsModule({ accountId: null }).markBillPaid(5);
        await answer('.confirm-btn');
        await paying;

        expect(asked('/matching-transactions')).toBe(false);
        expect(posts()).toEqual([{ recordPayment: false, paidDate: '2026-09-28', dueDate: '2026-09-28' }]);
    });

    it('holds the bill from the lookup to the payment', async () => {
        const mod = billsModule();
        const button = document.createElement('button');
        const paying = mod.markBillPaid(5, button);
        await vi.waitFor(() => expect(dialog()).not.toBeNull());

        expect(button.disabled).toBe(true);
        await mod.markBillPaid(5, button);
        expect(requests.filter(r => r.url.includes('/recorded-payment'))).toHaveLength(1);

        dialog().querySelector('.confirm-btn').click();
        await paying;
        expect(button.disabled).toBe(false);
        expect(posts()).toHaveLength(1);
    });
});

describe('Mark Paid on a transfer', () => {
    it('asks about the payment already there before offering rows to link, and uses it', async () => {
        const paying = transfersModule().markTransferPaid(7);

        await vi.waitFor(() => expect(dialog()).not.toBeNull());
        expect(requests[0]).toMatchObject({ method: 'GET', url: '/apps/budget/api/bills/7/recorded-payment?dueDate=2026-10-28' });
        expect(asked('/matching-transactions')).toBe(false);
        dialog().querySelector('.confirm-btn').click();
        await paying;

        expect(showMatchingTransactionDialog).not.toHaveBeenCalled();
        expect(posts()).toEqual([{ recordPayment: false, paidDate: '2026-09-28', dueDate: '2026-10-28' }]);
        expect(showSuccess).toHaveBeenCalledWith('Marked as paid with the payment of 2026-09-28');
        expect(showWarning).not.toHaveBeenCalled();
    });

    it('goes on to the rows to link for another payment, and confirms it', async () => {
        showMatchingTransactionDialog.mockResolvedValue({ action: 'create' });

        const paying = transfersModule().markTransferPaid(7);
        await answer('.another-btn');
        await paying;

        expect(showMatchingTransactionDialog).toHaveBeenCalledTimes(1);
        const [sent] = posts();
        expect(sent).toMatchObject({ recordPayment: true, dueDate: '2026-10-28', confirmSecondPayment: true });
        expect(sent.paidDate).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });

    it('leaves the transfer as it was when the user cancels', async () => {
        const mod = transfersModule();
        const paying = mod.markTransferPaid(7);
        await answer('.cancel-btn');
        await paying;

        expect(requests).toHaveLength(1);
        expect(mod.loadTransfers).not.toHaveBeenCalled();
    });

    it('carries on as before when the lookup fails', async () => {
        lookup = [404, { error: 'Bill not found' }];
        showMatchingTransactionDialog.mockResolvedValue({ action: 'link', transactionId: 1599001 });

        await transfersModule().markTransferPaid(7);

        expect(dialog()).toBeNull();
        expect(posts()).toEqual([{ recordPayment: false, dueDate: '2026-10-28', existingTransactionId: 1599001 }]);
    });
});
