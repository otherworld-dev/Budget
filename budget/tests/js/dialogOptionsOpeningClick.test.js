/**
 * The click that opened a dialog picks none of its options (#399).
 *
 * A double click on Mark Paid opened "Existing Transaction Found" in time for
 * the second click to land on a candidate's label, and the browser passed it
 * on to the candidate's radio: the choice moved off "Create a new transaction
 * instead", the safe default for a weak match (#274), and Confirm linked an
 * unrelated 2.90 Kiosk purchase as the bill's payment. A click on an option
 * that comes with the opening click (the browser numbers it 2, or it comes
 * within moments of the dialog appearing) now changes nothing; a click of its
 * own, or a key, still picks.
 *
 * Link to bill opens from a row menu item, which a user may double-click as
 * well. It starts with no bill picked, so that a quick Link can't file a
 * payment under a bill nobody chose, and the same click must not pick one.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { showMatchingTransactionDialog } from '../../src/utils/matchingDialog.js';
import { chooseBill } from '../../src/utils/billLinkDialog.js';

const OPENED = 1_000_000;
let now;

/** A click as the browser numbers it: 2 for the second of a double click, 0 from the keyboard */
function click(element, detail) {
    element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail }));
}

beforeEach(() => {
    now = OPENED;
    vi.spyOn(Date, 'now').mockImplementation(() => now);
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('Existing Transaction Found', () => {
    // Remarkable, and the weak candidate the tester added for it
    const BILL = { id: 20349, name: 'Remarkable', amount: 2.82, currency: 'CHF', frequency: 'monthly' };
    const KIOSK = {
        transaction: { id: 1792035, date: '2026-10-14', description: 'Kiosk', vendor: 'Kiosk Bahnhof', amount: 2.90 },
        score: 30,
        matchReasons: ['similar_amount', 'within_7_days'],
    };

    const modal = () => document.getElementById('matching-tx-modal');
    const chosen = () => modal().querySelector('input[name="matching-tx-choice"]:checked')?.value ?? null;
    const kiosk = () => modal().querySelector('input[value="1792035"]').closest('label');
    const confirm = () => click(modal().querySelector('.confirm-btn'), 1);

    it.each([
        ['the candidate\'s amount', () => kiosk().querySelector('.matching-tx-amount')],
        ['the candidate\'s label', () => kiosk()],
        ['"just mark as paid"', () => modal().querySelector('.matching-tx-skip .matching-tx-desc')],
    ])('keeps the safe default when the second click of the opening double click lands on %s', async (_where, target) => {
        const answer = showMatchingTransactionDialog(BILL, [KIOSK], {});
        expect(chosen()).toBe('create');

        // Opened 70 ms after the first click, the second came 450 ms after it
        now = OPENED + 380;
        click(target(), 2);

        expect(chosen()).toBe('create');
        now = OPENED + 3000;
        confirm();
        expect(await answer).toEqual({ action: 'create' });
    });

    it('cancels the second click when it lands on the candidate\'s radio itself', () => {
        // The browser checks a radio before the click is dispatched and, the
        // click cancelled, checks again the one that was checked (HTML's
        // legacy-canceled-activation behaviour). jsdom unchecks the clicked
        // radio but checks none again, so this asserts what the page
        // decides: the click is cancelled and the candidate is not picked.
        showMatchingTransactionDialog(BILL, [KIOSK], {});
        now = OPENED + 380;
        const second = new MouseEvent('click', { bubbles: true, cancelable: true, detail: 2 });
        kiosk().querySelector('input').dispatchEvent(second);

        expect(second.defaultPrevented).toBe(true);
        expect(chosen()).not.toBe('1792035');
    });

    it('keeps it for a click moments after the dialog appeared that the browser counts as a single click', () => {
        // The pointer moved between the two clicks
        showMatchingTransactionDialog(BILL, [KIOSK], {});
        now = OPENED + 300;
        click(kiosk().querySelector('.matching-tx-desc'), 1);

        expect(chosen()).toBe('create');
    });

    it('picks the candidate for a click of its own', async () => {
        const answer = showMatchingTransactionDialog(BILL, [KIOSK], {});
        now = OPENED + 1500;
        click(kiosk().querySelector('.matching-tx-amount'), 1);

        expect(chosen()).toBe('1792035');
        confirm();
        expect(await answer).toEqual({ action: 'link', transactionId: 1792035 });
    });

    it('picks the candidate from the keyboard however soon', () => {
        // Space on a focused radio, or an arrow key, clicks it with no pointer behind it
        showMatchingTransactionDialog(BILL, [KIOSK], {});
        now = OPENED + 5;
        click(kiosk().querySelector('input'), 0);

        expect(chosen()).toBe('1792035');
    });
});

describe('Link to bill', () => {
    const BILLS = [
        { id: 120, name: 'Corner Card SA', accountId: 3, isActive: false, amount: 100, frequency: 'one-time', startDate: '2026-09-28', lastPaidDate: '2026-09-28', currency: 'CHF' },
        { id: 47, name: 'CSX Pro Credit Cards', accountId: 3, isActive: true, amount: 100, frequency: 'monthly', nextDueDate: '2026-10-28', currency: 'CHF' },
    ];

    const modal = () => document.getElementById('bill-link-modal');
    const picked = () => modal().querySelector('input[name="bill-link-choice"]:checked')?.value ?? null;
    const row = (id) => modal().querySelector(`input[value="${id}"]`).closest('label');
    const link = () => modal().querySelector('.confirm-btn');

    it('picks no bill for the second click of the double click that opened it', () => {
        chooseBill(BILLS, {});
        now = OPENED + 200;
        click(row(120).querySelector('.matching-tx-desc'), 2);

        expect(picked()).toBeNull();
        expect(link().disabled).toBe(true);
    });

    it('nor for a click moments after it appeared', () => {
        chooseBill(BILLS, {});
        now = OPENED + 300;
        click(row(120), 1);

        expect(picked()).toBeNull();
        expect(link().disabled).toBe(true);
    });

    it('picks the bill for a click of its own', async () => {
        const choice = chooseBill(BILLS, {});
        now = OPENED + 1500;
        click(row(120).querySelector('.matching-tx-desc'), 1);

        expect(picked()).toBe('120');
        expect(link().disabled).toBe(false);
        click(link(), 1);
        await expect(choice).resolves.toBe(120);
    });

    it('picks the bill from the keyboard however soon', () => {
        chooseBill(BILLS, {});
        now = OPENED + 5;
        click(row(47).querySelector('input'), 0);

        expect(picked()).toBe('47');
        expect(link().disabled).toBe(false);
    });
});
