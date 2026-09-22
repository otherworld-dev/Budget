/**
 * The "Payments without a recorded transaction" card (#274) lists bills
 * marked paid with no transaction to show for it. Until #394 its only way
 * out was Record transaction, so someone who had deleted the payment on
 * purpose was nagged for the card's whole 60-day window. Each row now
 * offers Dismiss (acknowledge this one payment) and, when the bill still
 * carries its payment snapshot, the same Mark Unpaid the list offers.
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

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(true)),
    promptDialog: vi.fn(() => Promise.resolve(null)),
    alertDialog: vi.fn(() => Promise.resolve()),
}));

import BillsModule from '../../src/modules/bills/BillsModule.js';
import { showError } from '../../src/utils/notifications.js';
import { confirmDialog } from '../../src/utils/dialogs.js';

const item = (overrides = {}) => ({
    billId: 7,
    name: 'Garage',
    amount: 33.5,
    lastPaidDate: '2026-07-25',
    accountId: 1,
    currency: 'CHF',
    canMarkUnpaid: false,
    ...overrides,
});

const jsonResponse = (body, ok = true) =>
    Promise.resolve({ ok, status: ok ? 200 : 400, json: () => Promise.resolve(body) });

// A click handler awaits fetch → json → reload; a macrotask lets all of
// those settled promises run.
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

function makeModule() {
    const mod = Object.create(BillsModule.prototype);
    mod.app = { settings: {}, loadAccounts: vi.fn(() => Promise.resolve()) };
    mod.bills = [];
    mod.loadBillsView = vi.fn(() => Promise.resolve());
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="unrecorded-payments-card" style="display: none;">
            <span id="unrecorded-payments-count"></span>
            <div id="unrecorded-payments-list"></div>
        </div>
    `;
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    confirmDialog.mockResolvedValue(true);
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('unrecorded payments card actions (#394)', () => {
    it('offers Dismiss on every row and Mark Unpaid only where a payment snapshot exists', async () => {
        global.fetch = vi.fn(() => jsonResponse({
            items: [item({ billId: 7, canMarkUnpaid: true }), item({ billId: 8 })],
            count: 2,
        }));
        const mod = makeModule();

        await mod.loadUnrecordedPayments();

        expect(document.querySelectorAll('.unrecorded-payment-record')).toHaveLength(2);
        expect(document.querySelectorAll('.unrecorded-payment-dismiss')).toHaveLength(2);
        const unpaid = document.querySelectorAll('.unrecorded-payment-unpaid');
        expect(unpaid).toHaveLength(1);
        expect(unpaid[0].dataset.billId).toBe('7');
    });

    it('Dismiss posts the dismissal for that bill and the row leaves the card', async () => {
        global.fetch = vi.fn()
            .mockImplementationOnce(() => jsonResponse({ items: [item()], count: 1 }))
            .mockImplementationOnce(() => jsonResponse({ status: 'success' }))
            .mockImplementationOnce(() => jsonResponse({ items: [], count: 0 }));
        const mod = makeModule();
        await mod.loadUnrecordedPayments();

        document.querySelector('.unrecorded-payment-dismiss').click();
        await flush();

        const [url, opts] = global.fetch.mock.calls[1];
        expect(url).toBe('/apps/budget/api/bills/7/dismiss-unrecorded');
        expect(opts.method).toBe('POST');
        expect(document.getElementById('unrecorded-payments-card').style.display).toBe('none');
        expect(showError).not.toHaveBeenCalled();
    });

    it('a failed dismissal reports the error and keeps the row', async () => {
        global.fetch = vi.fn()
            .mockImplementationOnce(() => jsonResponse({ items: [item()], count: 1 }))
            .mockImplementationOnce(() => jsonResponse({ error: 'nope' }, false));
        const mod = makeModule();
        await mod.loadUnrecordedPayments();

        document.querySelector('.unrecorded-payment-dismiss').click();
        await flush();

        expect(showError).toHaveBeenCalled();
        expect(document.querySelectorAll('.unrecorded-payment-dismiss')).toHaveLength(1);
    });

    it('Mark Unpaid on the card runs the same confirmed revert as the list', async () => {
        global.fetch = vi.fn()
            .mockImplementationOnce(() => jsonResponse({ items: [item({ canMarkUnpaid: true })], count: 1 }))
            .mockImplementationOnce(() => jsonResponse({}));
        const mod = makeModule();
        await mod.loadUnrecordedPayments();

        document.querySelector('.unrecorded-payment-unpaid').click();
        await flush();

        expect(confirmDialog).toHaveBeenCalled();
        const [url, opts] = global.fetch.mock.calls[1];
        expect(url).toBe('/apps/budget/api/bills/7/unpaid');
        expect(opts.method).toBe('POST');
        expect(mod.loadBillsView).toHaveBeenCalled();
    });
});
