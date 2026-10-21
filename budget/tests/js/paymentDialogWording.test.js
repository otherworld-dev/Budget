/**
 * The two dialogs on the Record another payment path say what each is about
 * (#399).
 *
 * Mark Paid on a bill that already has a payment asks about that payment
 * first, and Record another payment then opens the list of transactions
 * already in the account: both were titled "Existing Transaction Found", so
 * the second read like the first asking again. The list also told a one-time
 * bill that it "moves on to its next due date", which a one-time bill
 * doesn't have.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { askAboutRecordedPayment } from '../../src/utils/paymentRecordedDialog.js';
import { showMatchingTransactionDialog } from '../../src/utils/matchingDialog.js';

// CembraPay Temu 4.72, paid on 27 September, and one of the grocery rows the list offered
const PAYMENT = { id: 1350727, date: '2026-09-27', amount: 4.72 };
const BILL = { id: 20187, name: 'CembraPay Temu', amount: 4.72, currency: 'CHF' };
const CANDIDATES = [{ transaction: { id: 1560, date: '2026-10-12', description: 'Migros', amount: 4.80 }, score: 25, matchReasons: ['similar_amount'] }];

const paymentTitle = () => document.querySelector('#payment-recorded-modal h2').textContent.trim();
const listTitle = () => document.querySelector('#matching-tx-modal h2').textContent.trim();
const listText = () => document.querySelector('#matching-tx-modal .budget-modal-body').textContent;

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the dialog about the payment already recorded', () => {
    it('has a title of its own, not the one of the list that follows it', () => {
        askAboutRecordedPayment(PAYMENT, {}, { currency: 'CHF' });
        showMatchingTransactionDialog(BILL, CANDIDATES, {});

        expect(paymentTitle()).toBe('Payment already recorded');
        expect(listTitle()).toBe('Existing Transaction Found');
    });

    it('has it on the Income page too', () => {
        askAboutRecordedPayment(PAYMENT, {}, { kind: 'income', currency: 'CHF' });

        expect(paymentTitle()).toBe('Payment already recorded');
    });
});

describe('the list of transactions already in the account', () => {
    it('tells a one-time bill it is marked as paid, with no next due date to move on to', () => {
        showMatchingTransactionDialog({ ...BILL, frequency: 'one-time' }, CANDIDATES, {});

        expect(listText()).toContain('Whichever you choose, the bill is marked as paid.');
        expect(listText()).not.toContain('next due date');
    });

    it('still tells a recurring bill it moves on to its next due date', () => {
        showMatchingTransactionDialog({ ...BILL, frequency: 'monthly' }, CANDIDATES, {});

        expect(listText()).toContain('Whichever you choose, the bill is marked as paid and moves on to its next due date.');
    });
});
