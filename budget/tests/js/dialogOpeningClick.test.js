/**
 * A dialog opened by a double click stays open (#399).
 *
 * A double click on Skip or Mark Unpaid opened the "Please confirm" dialog on
 * the first click, and the second landed on its backdrop 2 ms later and
 * cancelled it: no request, no message, and the button looked dead. A click
 * on the backdrop that belongs to the double click that opened the dialog
 * (the browser numbers it 2, or it comes within moments of the dialog
 * appearing) is now ignored; a click to dismiss it still dismisses.
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

import { confirmDialog } from '../../src/utils/dialogs.js';
import { askAboutRecordedPayment } from '../../src/utils/paymentRecordedDialog.js';
import BillsModule from '../../src/modules/bills/BillsModule.js';

const OPENED = 1_000_000;

const backdrop = () => document.querySelector('.budget-dialog');
const paymentOverlay = () => document.getElementById('payment-recorded-modal');
const settled = (promise) => Promise.race([promise, new Promise(resolve => setTimeout(() => resolve('still open'), 20))]);

/** A real click: the press, then the click, numbered as the browser does */
function press(element, detail, target = element) {
    target.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, detail }));
    element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail }));
}

let now;

beforeEach(() => {
    now = OPENED;
    vi.spyOn(Date, 'now').mockImplementation(() => now);
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
    delete globalThis.OC;
    delete globalThis.fetch;
});

describe('the confirm dialog', () => {
    it('stays open when the second click of the double click that opened it lands on the backdrop', async () => {
        const answer = confirmDialog('Skip this payment and advance to the next due date?');
        now = OPENED + 2;
        press(backdrop(), 2);

        expect(await settled(answer)).toBe('still open');
        expect(backdrop()).not.toBeNull();
        document.querySelector('.budget-dialog-confirm').click();
        expect(await answer).toBe(true);
    });

    it('stays open for a click on the backdrop moments after it opened, even one the browser does not number as a double click', async () => {
        // The pointer moved between the two clicks, so the second counts as a single click
        const answer = confirmDialog('Skip this payment and advance to the next due date?');
        now = OPENED + 300;
        press(backdrop(), 1);

        expect(await settled(answer)).toBe('still open');
        document.querySelector('.budget-dialog-cancel').click();
        expect(await answer).toBe(false);
    });

    it('is still dismissed by a click on the backdrop once it has been open a moment', async () => {
        const answer = confirmDialog('Skip this payment and advance to the next due date?');
        now = OPENED + 800;
        press(backdrop(), 1);

        expect(await answer).toBe(false);
        expect(backdrop()).toBeNull();
    });

    it('is still dismissed by a click with no pointer behind it, however soon', async () => {
        // Assistive technology and scripted clicks are numbered 0
        const answer = confirmDialog('Skip this payment and advance to the next due date?');
        backdrop().click();

        expect(await answer).toBe(false);
    });

    it('is not dismissed by a drag that ends on the backdrop after an ignored second click', async () => {
        // Selecting the message text: the press inside the panel, the release
        // outside it. The ignored click before must not count as that press.
        const answer = confirmDialog('Skip this payment and advance to the next due date?');
        now = OPENED + 2;
        press(backdrop(), 2);
        now = OPENED + 2000;
        press(backdrop(), 1, document.querySelector('.budget-dialog-message'));

        expect(await settled(answer)).toBe('still open');
        document.querySelector('.budget-dialog-cancel').click();
        await answer;
    });

    it('times the moment from when it appears, not from when it was asked for', async () => {
        // A second question waits for the first to be answered
        const first = confirmDialog('First?');
        const second = confirmDialog('Second?');
        now = OPENED + 5000;
        document.querySelector('.budget-dialog-confirm').click();
        await first;

        now = OPENED + 5002;
        press(backdrop(), 1);
        expect(await settled(second)).toBe('still open');
        document.querySelector('.budget-dialog-cancel').click();
        await second;
    });
});

describe('the already-has-a-payment dialog', () => {
    const EXISTING = { id: 1, date: '2026-09-27', amount: 151.67 };

    it('stays open when the second click of a double click lands on its backdrop', async () => {
        const answer = askAboutRecordedPayment(EXISTING, {});
        now = OPENED + 2;
        press(paymentOverlay(), 2);

        expect(await settled(answer)).toBe('still open');
        paymentOverlay().querySelector('.confirm-btn').click();
        expect(await answer).toBe('use');
    });

    it('stays open for a click on its backdrop moments after it opened', async () => {
        const answer = askAboutRecordedPayment(EXISTING, {});
        now = OPENED + 300;
        press(paymentOverlay(), 1);

        expect(await settled(answer)).toBe('still open');
        paymentOverlay().querySelector('.cancel-btn').click();
        expect(await answer).toBeNull();
    });

    it('is still dismissed by a click on its backdrop once it has been open a moment', async () => {
        const answer = askAboutRecordedPayment(EXISTING, {});
        now = OPENED + 800;
        press(paymentOverlay(), 1);

        expect(await answer).toBeNull();
        expect(paymentOverlay()).toBeNull();
    });
});

describe('a double click on Skip', () => {
    it('skips once the dialog the first click opened is confirmed', async () => {
        globalThis.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        const requests = [];
        globalThis.fetch = vi.fn(async (url, init = {}) => {
            requests.push({ method: init.method || 'GET', url });
            return { ok: true, status: 200, headers: { get: () => null }, json: async () => ({ previousNextDueDate: '2026-10-29' }) };
        });
        document.body.innerHTML = '<div id="bills-list"><button class="bill-skip-btn" data-bill-id="5">Skip</button></div>';
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings: {}, bills: [{ id: 5, name: 'KPT', frequency: 'monthly', nextDueDate: '2026-10-29', accountId: 3, isActive: true }], accounts: [] };
        mod.loadBillsView = vi.fn(async () => {});
        mod.setupBillsEventListeners();

        const skip = document.querySelector('.bill-skip-btn');
        press(skip, 1);
        now = OPENED + 2;
        press(backdrop(), 2);
        expect(backdrop()).not.toBeNull();
        document.querySelector('.budget-dialog-confirm').click();

        await vi.waitFor(() => expect(requests.filter(r => r.url.endsWith('/skip'))).toHaveLength(1));
    });
});
