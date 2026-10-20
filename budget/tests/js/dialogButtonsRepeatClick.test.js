/**
 * A dialog's buttons don't answer the second click of the double click that
 * opened it (#399).
 *
 * On a phone a dialog is nearly as wide as the screen, so the second tap of a
 * double tap on Mark Paid can land on one of its buttons rather than on the
 * backdrop. On the "already has a payment" dialog that button can be Record
 * another payment, which books the second payment the dialog is there to stop.
 * The browser numbers that click 2; a click of its own (1) or a key press (0)
 * still answers.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { confirmDialog } from '../../src/utils/dialogs.js';
import { askAboutRecordedPayment } from '../../src/utils/paymentRecordedDialog.js';
import { showMatchingTransactionDialog } from '../../src/utils/matchingDialog.js';

const settled = (promise) => Promise.race([promise, new Promise(resolve => setTimeout(() => resolve('still open'), 20))]);

function click(element, detail) {
    element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail }));
}

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('the already-has-a-payment dialog', () => {
    const existing = { id: 5, date: '2026-09-27', amount: 151.67 };

    it('ignores a second click on Record another payment and answers a click of its own', async () => {
        const answer = askAboutRecordedPayment(existing, {});
        const another = () => document.querySelector('#payment-recorded-modal .another-btn');

        click(another(), 2);
        expect(await settled(answer)).toBe('still open');

        click(another(), 1);
        expect(await answer).toBe('another');
    });

    it('ignores a second click on Use that payment and Cancel', async () => {
        const answer = askAboutRecordedPayment(existing, {});

        click(document.querySelector('#payment-recorded-modal .confirm-btn'), 2);
        click(document.querySelector('#payment-recorded-modal .cancel-btn'), 2);
        expect(await settled(answer)).toBe('still open');

        click(document.querySelector('#payment-recorded-modal .confirm-btn'), 0);
        expect(await answer).toBe('use');
    });
});

describe('the Existing Transaction Found dialog', () => {
    const bill = { id: 1, name: 'Phone', amount: 30, currency: 'CHF' };
    const candidates = [{ transaction: { id: 9, date: '2026-10-02', description: 'Groceries', amount: 29 }, score: 20, matchReasons: ['similar_amount'] }];

    it('ignores a second click on Confirm and answers a click of its own', async () => {
        const answer = showMatchingTransactionDialog(bill, candidates, {});
        const confirm = () => document.querySelector('#matching-tx-modal .confirm-btn');

        click(confirm(), 2);
        expect(await settled(answer)).toBe('still open');

        click(confirm(), 1);
        expect(await answer).toEqual({ action: 'create' });
    });

    it('stays open when the second click of the opening double click lands on the backdrop', async () => {
        const answer = showMatchingTransactionDialog(bill, candidates, {});
        const overlay = document.getElementById('matching-tx-modal');

        click(overlay, 2);
        expect(await settled(answer)).toBe('still open');

        click(document.querySelector('#matching-tx-modal .cancel-btn'), 1);
        expect(await answer).toBeNull();
    });
});

describe('the confirm dialog', () => {
    it('ignores a second click on Confirm or Cancel', async () => {
        const answer = confirmDialog('Skip this payment and advance to the next due date?');

        click(document.querySelector('.budget-dialog-confirm'), 2);
        expect(await settled(answer)).toBe('still open');
        const cancel = document.querySelector('.budget-dialog-cancel');
        if (cancel) {
            click(cancel, 2);
            expect(await settled(answer)).toBe('still open');
        }

        click(document.querySelector('.budget-dialog-confirm'), 1);
        expect(await answer).toBe(true);
    });
});
