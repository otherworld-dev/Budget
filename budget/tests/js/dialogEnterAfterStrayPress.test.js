/**
 * Enter on a destructive dialog doesn't delete because a press took the focus
 * off Cancel (#399).
 *
 * A destructive confirm (Mark Unpaid, Delete bill) starts on Cancel, so a
 * stray Enter can't delete. The second press of the double click that opened
 * it lands on the backdrop, though, and a press on something that can't take
 * the focus moves it to the page; a press on the message text does the same.
 * Enter then confirmed, as it confirmed whenever Cancel wasn't focused, and
 * the tester deleted a payment and a bill that way. Enter now acts on the
 * focused button, and with the focus anywhere else gives the answer of the
 * button the dialog started on. The backdrop press that the dialog ignores no
 * longer moves the focus either, here or in the two payment dialogs.
 *
 * jsdom moves no focus on a press, so press() does what the browser does:
 * unless the page cancels the mousedown, the focus goes to what was pressed
 * when that can take it, and off whatever had it when it can't.
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

import { confirmDialog, promptDialog } from '../../src/utils/dialogs.js';
import { askAboutRecordedPayment } from '../../src/utils/paymentRecordedDialog.js';
import { showMatchingTransactionDialog } from '../../src/utils/matchingDialog.js';
import BillsModule from '../../src/modules/bills/BillsModule.js';

const OPENED = 1_000_000;
const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), select, textarea, a[href], [tabindex]';

const backdrop = () => document.querySelector('.budget-dialog');
const cancelBtn = () => document.querySelector('.budget-dialog-cancel');
const confirmBtn = () => document.querySelector('.budget-dialog-confirm');

let now;

/**
 * A press and its click, numbered as the browser numbers them, with the
 * browser's focus change on the press (see above).
 *
 * @return {MouseEvent} the mousedown
 */
function press(target, detail) {
    const down = new MouseEvent('mousedown', { bubbles: true, cancelable: true, detail });
    target.dispatchEvent(down);
    if (!down.defaultPrevented) {
        const focusable = target.closest(FOCUSABLE);
        if (focusable) {
            focusable.focus();
        } else {
            document.activeElement?.blur();
        }
    }
    target.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true, detail }));
    target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail }));
    return down;
}

/** A key, sent to whatever has the focus */
function key(name) {
    const event = new KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true });
    (document.activeElement || document.body).dispatchEvent(event);
    return event;
}

/** Tab, moving the focus on as the browser does unless the page stopped it */
function tab() {
    if (!key('Tab').defaultPrevented) {
        const all = [...document.querySelectorAll(FOCUSABLE)];
        (all[all.indexOf(document.activeElement) + 1] ?? all[0]).focus();
    }
}

beforeEach(() => {
    now = OPENED;
    vi.spyOn(Date, 'now').mockImplementation(() => now);
});

afterEach(() => {
    // A test that failed half way leaves its dialog open, and the next
    // test's dialog would wait behind it
    for (let i = 0; i < 5 && backdrop(); i++) {
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    }
    document.body.innerHTML = '';
    vi.restoreAllMocks();
    delete globalThis.OC;
    delete globalThis.fetch;
});

describe('a destructive confirm', () => {
    /** A button that asks, as Mark Unpaid does */
    function opener() {
        const button = document.createElement('button');
        button.textContent = 'Mark Unpaid';
        document.body.appendChild(button);
        const asked = {};
        button.addEventListener('click', () => {
            asked.answer = confirmDialog('Mark this bill as unpaid?', { destructive: true });
        });
        return { button, asked };
    }

    it('cancels on Enter after a double click on the button that opened it', async () => {
        const { button, asked } = opener();
        press(button, 1);
        // The second press of the double click lands on the backdrop
        now = OPENED + 380;
        press(backdrop(), 2);
        key('Enter');

        expect(await asked.answer).toBe(false);
    });

    it('keeps the focus on Cancel through the second press of that double click', async () => {
        const { button, asked } = opener();
        press(button, 1);
        now = OPENED + 380;
        const second = press(backdrop(), 2);

        expect(second.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(cancelBtn());
        cancelBtn().click();
        await asked.answer;
    });

    it('cancels on Enter after a press on its message took the focus off Cancel', async () => {
        const answer = confirmDialog('Are you sure you want to delete this bill?', { destructive: true });
        now = OPENED + 2000;
        press(document.querySelector('.budget-dialog-message p'), 1);
        expect(document.activeElement).toBe(document.body);
        key('Enter');

        expect(await answer).toBe(false);
    });

    it('confirms on Enter once the user has tabbed to the confirm button', async () => {
        const answer = confirmDialog('Are you sure you want to delete this bill?', { destructive: true });
        tab();
        expect(document.activeElement).toBe(confirmBtn());
        key('Enter');

        expect(await answer).toBe(true);
    });
});

describe('a dialog that starts on its confirm button', () => {
    it('still confirms on Enter with the focus on the page', async () => {
        const answer = confirmDialog('Skip this payment and advance to the next due date?');
        document.activeElement.blur();
        key('Enter');

        expect(await answer).toBe(true);
    });

    it('still gives a prompt\'s value on Enter, from its field or with the focus on the page', async () => {
        const typed = promptDialog('Enter tag name:', { defaultValue: 'Groceries' });
        key('Enter');
        expect(await typed).toBe('Groceries');

        const elsewhere = promptDialog('Enter tag name:', { defaultValue: 'Rent' });
        document.activeElement.blur();
        key('Enter');
        expect(await elsewhere).toBe('Rent');
    });
});

describe('the payment dialogs', () => {
    it('keep the focus on Use that payment through the second press of the opening double click', async () => {
        const answer = askAboutRecordedPayment({ id: 1, date: '2026-09-27', amount: 151.67 }, {});
        const use = document.querySelector('#payment-recorded-modal .confirm-btn');
        expect(document.activeElement).toBe(use);
        now = OPENED + 380;
        const second = press(document.getElementById('payment-recorded-modal'), 2);

        expect(second.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(use);
        use.click();
        expect(await answer).toBe('use');
    });

    it('keep the focus where it was in Existing Transaction Found through that press', async () => {
        const candidates = [{ transaction: { id: 9, date: '2026-10-14', description: 'Kiosk', amount: 2.90 }, score: 30, matchReasons: ['similar_amount'] }];
        const answer = showMatchingTransactionDialog({ id: 1, name: 'Remarkable', amount: 2.82, currency: 'CHF' }, candidates, {});
        const confirm = document.querySelector('#matching-tx-modal .confirm-btn');
        confirm.focus();
        now = OPENED + 380;
        const second = press(document.getElementById('matching-tx-modal'), 2);

        expect(second.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(confirm);
        document.querySelector('#matching-tx-modal .cancel-btn').click();
        expect(await answer).toBeNull();
    });
});

describe('a double click on Mark Unpaid', () => {
    // Last in the file: the Bills page's click listener stays on the document
    it('then Enter deletes nothing', async () => {
        globalThis.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        const sent = [];
        globalThis.fetch = vi.fn(async (url, init = {}) => {
            sent.push(`${init.method || 'GET'} ${url}`);
            return { ok: true, status: 200, headers: { get: () => null }, json: async () => ({}) };
        });
        document.body.innerHTML = '<div id="bills-list"><button class="bill-action-btn bill-unpaid-btn" data-bill-id="20186">Mark Unpaid</button></div>';
        const mod = Object.create(BillsModule.prototype);
        mod.app = {
            settings: {},
            accounts: [],
            bills: [{ id: 20186, name: 'CembraPay Temu', amount: 151.67, frequency: 'one-time', nextDueDate: null, isActive: false, canMarkUnpaid: true, accountId: 3 }],
        };
        mod.loadBillsView = vi.fn(async () => {});
        mod.setupBillsEventListeners();

        const button = document.querySelector('.bill-unpaid-btn');
        press(button, 1);
        now = OPENED + 380;
        press(backdrop(), 2);
        key('Enter');

        // Answered, the action ends and lets go of the button
        await vi.waitFor(() => expect(button.disabled).toBe(false));
        expect(sent).toEqual([]);
    });
});
