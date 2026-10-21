/**
 * The second click of a double click acts on nothing (#399).
 *
 * Mark Paid on SBB GA Bruno finished in about 370 ms and the list
 * re-rendered: Bruno moved down and SBB GA Leon's Mark Paid slid under the
 * cursor, so the second click of a slow double click (450 ms, inside the
 * system's double-click time) paid Leon too. The lock on Bruno was no help:
 * it was another bill's button. A click the browser counts as the second of
 * a double click (event.detail 2 or more) is now ignored by the payment
 * buttons; a single click (1) and a key press on the button (0) still act.
 */

import { describe, it, expect, beforeAll, beforeEach, vi } from 'vitest';

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
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import IncomeModule from '../../src/modules/income/IncomeModule.js';

/** Two rows, as the list shows them: the second is the one that slides up */
function rows(prefix, idAttr, classes) {
    return [1, 2].map(id => `
        <div class="${prefix}-card" data-${idAttr}="${id}">
            ${classes.map(cls => `<button class="${cls}" data-${idAttr}="${id}"><span class="icon-checkmark" aria-hidden="true"></span>Act</button>`).join('')}
        </div>`).join('');
}

const button = (cls, id, attr) => document.querySelector(`.${cls}[data-${attr}="${id}"]`);

/** A click the browser numbers: 1 a single click, 2 the second of a double click, 0 a key press */
function click(element, detail) {
    element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail }));
}

let bills;
let transfers;
let income;

beforeAll(() => {
    document.body.innerHTML = `
        <div id="bills-list">${rows('bill', 'bill-id', ['bill-paid-btn', 'bill-skip-btn', 'bill-unpaid-btn'])}</div>
        <div id="transfers-list">${rows('transfer', 'transfer-id', ['transfer-paid-btn', 'transfer-skip-btn', 'transfer-unpaid-btn'])}</div>
        <div id="income-list">${rows('income', 'income-id', ['income-received-btn', 'income-skip-btn'])}</div>
    `;

    bills = Object.create(BillsModule.prototype);
    bills.app = { settings: {}, bills: [], accounts: undefined };
    bills.markBillPaid = vi.fn();
    bills.skipBillPayment = vi.fn();
    bills.markBillUnpaid = vi.fn();
    bills.recordMissedPayment = vi.fn();
    bills.dismissUnrecordedPayment = vi.fn();
    bills.setupBillsEventListeners();

    transfers = Object.create(TransfersModule.prototype);
    transfers.app = { settings: {} };
    transfers.transfers = [];
    transfers.markTransferPaid = vi.fn();
    transfers.skipTransfer = vi.fn();
    transfers.markTransferUnpaid = vi.fn();
    transfers.setupEventListeners();

    income = Object.create(IncomeModule.prototype);
    income.app = { settings: {}, recurringIncome: [] };
    income.markIncomeReceived = vi.fn();
    income.skipIncome = vi.fn();
    income.setupIncomeEventListeners();
});

beforeEach(() => {
    vi.clearAllMocks();
});

describe.each([
    ['Bills: Mark Paid', () => bills.markBillPaid, 'bill-paid-btn', 'bill-id'],
    ['Bills: Skip', () => bills.skipBillPayment, 'bill-skip-btn', 'bill-id'],
    ['Bills: Mark Unpaid', () => bills.markBillUnpaid, 'bill-unpaid-btn', 'bill-id'],
    ['Transfers: Mark Paid', () => transfers.markTransferPaid, 'transfer-paid-btn', 'transfer-id'],
    ['Transfers: Skip', () => transfers.skipTransfer, 'transfer-skip-btn', 'transfer-id'],
    ['Transfers: Mark Unpaid', () => transfers.markTransferUnpaid, 'transfer-unpaid-btn', 'transfer-id'],
    ['Income: Mark Received', () => income.markIncomeReceived, 'income-received-btn', 'income-id'],
    ['Income: Skip', () => income.skipIncome, 'income-skip-btn', 'income-id'],
])('%s', (_name, action, cls, attr) => {
    it('acts once when the second click of a double click lands on the next row', () => {
        click(button(cls, 1, attr), 1);
        // The list re-rendered and row 2's button is under the cursor now
        click(button(cls, 2, attr), 2);

        expect(action()).toHaveBeenCalledTimes(1);
        expect(action()).toHaveBeenCalledWith(1, button(cls, 1, attr));
    });

    it('ignores the later clicks of a triple click too, on the button or its icon', () => {
        click(button(cls, 2, attr).querySelector('span'), 3);
        click(button(cls, 2, attr), 2);

        expect(action()).not.toHaveBeenCalled();
    });

    it('still acts on a single click', () => {
        click(button(cls, 2, attr).querySelector('span'), 1);

        expect(action()).toHaveBeenCalledWith(2, button(cls, 2, attr));
    });

    it('still acts on the button pressed from the keyboard', () => {
        // Enter or Space on a focused button clicks it with detail 0
        click(button(cls, 2, attr), 0);

        expect(action()).toHaveBeenCalledWith(2, button(cls, 2, attr));
    });
});

describe('the payments-without-a-transaction card', () => {
    // Record transaction books with no question, and the card re-renders
    // under the cursor as each payment leaves it
    beforeAll(async () => {
        document.body.insertAdjacentHTML('beforeend', `
            <div id="unrecorded-payments-card" style="display: none;">
                <span id="unrecorded-payments-count"></span>
                <div id="unrecorded-payments-list"></div>
            </div>`);
        globalThis.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        globalThis.fetch = vi.fn(async () => ({
            ok: true,
            status: 200,
            headers: { get: () => null },
            json: async () => ({
                count: 2,
                items: [1, 2].map(billId => ({ billId, name: `Bill ${billId}`, amount: 10, lastPaidDate: '2026-10-01', accountId: 3, canMarkUnpaid: true })),
            }),
        }));
        await bills.loadUnrecordedPayments();
        delete globalThis.fetch;
        delete globalThis.OC;
    });

    it.each([
        ['Record transaction', () => bills.recordMissedPayment, 'unrecorded-payment-record'],
        ['Mark Unpaid', () => bills.markBillUnpaid, 'unrecorded-payment-unpaid'],
        ['Dismiss', () => bills.dismissUnrecordedPayment, 'unrecorded-payment-dismiss'],
    ])('%s acts once on a double click and on a single click or key press', (_name, action, cls) => {
        click(button(cls, 1, 'bill-id'), 1);
        click(button(cls, 2, 'bill-id'), 2);
        expect(action()).toHaveBeenCalledTimes(1);
        expect(action().mock.calls[0][0]).toBe(1);

        click(button(cls, 2, 'bill-id'), 0);
        expect(action()).toHaveBeenCalledTimes(2);
        expect(action().mock.calls[1][0]).toBe(2);
    });
});
