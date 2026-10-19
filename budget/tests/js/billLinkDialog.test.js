/**
 * Link to bill and Unlink from bill in a transaction's row menu (#399).
 *
 * The Bills Calendar counts the rows that carry a bill, and only Mark Paid
 * gave a row one, for the occurrence due now. A payment typed in by hand
 * (the reporter's 'Monatsrechnung' of 28 Sep) or one of a deleted bill
 * showed under no bill. The row menu now files a payment under a bill on its
 * account, or takes it out of one.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { chooseBill, linkableBills } from '../../src/utils/billLinkDialog.js';
import { transactionMenuActions } from '../../src/modules/transactions/transactionRow.js';

const LOHNKONTO = 3;
const CARD = 8;

const BILLS = [
    { id: 47, name: 'CSX Pro Credit Cards', accountId: LOHNKONTO, destinationAccountId: CARD, isTransfer: true, isActive: true, amount: 100, nextDueDate: '2026-11-28', currency: 'CHF' },
    { id: 120, name: 'Corner Card SA', accountId: LOHNKONTO, isActive: false, amount: 100, nextDueDate: null, currency: 'CHF' },
    { id: 92, name: 'Elektrizitätswerke', accountId: LOHNKONTO, isActive: true, amount: 80, nextDueDate: '2027-09-28', currency: 'CHF' },
    { id: 30, name: 'Rent', accountId: 7, isActive: true, amount: 1500, currency: 'CHF' },
    { id: 31, name: 'Gym', accountId: null, isActive: true, amount: 50 },
    { id: 32, name: 'Joint savings', accountId: LOHNKONTO, isActive: true, amount: 200, _shared: true, _canWrite: true },
    { id: 33, name: 'Card top-up', accountId: CARD, destinationAccountId: LOHNKONTO, isTransfer: true, isActive: true, amount: 20 },
];

const PAYMENT = { id: 1586, accountId: LOHNKONTO, type: 'debit', status: 'cleared', date: '2026-09-28', amount: 100, description: 'Monatsrechnung', billId: null, linkedTransactionId: null };

const open = (bills) => {
    const choice = chooseBill(bills, {});
    return { choice, modal: document.getElementById('bill-link-modal') };
};
const names = (modal) => [...modal.querySelectorAll('.matching-tx-desc')].map(el => el.textContent.trim());
const pick = (modal, billId) => {
    const radio = modal.querySelector(`input[value="${billId}"]`);
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
};

afterEach(() => {
    document.body.innerHTML = '';
});

describe('linkableBills', () => {
    it('offers the user\'s own bills paid from the row\'s account, active and inactive', () => {
        expect(linkableBills(BILLS, PAYMENT).map(b => b.id)).toEqual([47, 120, 92]);
    });

    it('takes a transfer by the account it pays from, not the one it pays into', () => {
        expect(linkableBills(BILLS, { ...PAYMENT, accountId: CARD }).map(b => b.id)).toEqual([33]);
    });

    it('copes with no list', () => {
        expect(linkableBills(null, PAYMENT)).toEqual([]);
    });
});

describe('chooseBill', () => {
    it('lists the bills by name and says which are inactive', () => {
        const { modal } = open(linkableBills(BILLS, PAYMENT));

        expect(names(modal)).toEqual(['Corner Card SA', 'CSX Pro Credit Cards', 'Elektrizitätswerke']);
        expect(modal.querySelector('input[value="120"]').closest('label').textContent).toContain('Inactive');
        expect(modal.querySelector('input[value="92"]').closest('label').textContent).not.toContain('Inactive');
    });

    it('escapes bill names', async () => {
        const { choice, modal } = open([{ id: 5, name: '<img src=x onerror="window.pwned=1">B&Q', accountId: LOHNKONTO, isActive: true, amount: 10 }]);

        expect(modal.querySelector('img')).toBeNull();
        expect(names(modal)).toEqual(['<img src=x onerror="window.pwned=1">B&Q']);

        modal.querySelector('.cancel-btn').click();
        await expect(choice).resolves.toBeNull();
    });

    it('resolves with the bill picked, once one is', async () => {
        const { choice, modal } = open(linkableBills(BILLS, PAYMENT));
        const link = modal.querySelector('.confirm-btn');

        expect(link.disabled).toBe(true);
        pick(modal, 120);
        expect(link.disabled).toBe(false);
        link.click();

        await expect(choice).resolves.toBe(120);
        expect(document.getElementById('bill-link-modal')).toBeNull();
    });

    it.each([
        ['Cancel', (modal) => modal.querySelector('.cancel-btn').click()],
        ['the close button', (modal) => modal.querySelector('.close-btn').click()],
        ['a click outside', (modal) => modal.click()],
        ['Escape', () => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))],
    ])('resolves null on %s', async (_label, dismiss) => {
        const { choice, modal } = open(linkableBills(BILLS, PAYMENT));
        pick(modal, 92);

        dismiss(modal);

        await expect(choice).resolves.toBeNull();
        expect(document.getElementById('bill-link-modal')).toBeNull();
    });

    it('says so when no bill is paid from the account', async () => {
        const { choice, modal } = open([]);

        expect(modal.querySelector('input[type="radio"]')).toBeNull();
        expect(modal.querySelector('.confirm-btn')).toBeNull();
        expect(modal.textContent).toContain('There are no bills on this account yet');

        modal.querySelector('.cancel-btn').click();
        await expect(choice).resolves.toBeNull();
    });

    it('replaces a dialog still open from before', () => {
        open(BILLS.slice(0, 1));
        open(BILLS.slice(1, 3));

        expect(document.querySelectorAll('#bill-link-modal')).toHaveLength(1);
    });
});

describe('the row menu', () => {
    const OWN = { id: LOHNKONTO, name: 'Lohnkonto' };
    const READ_ONLY = { id: LOHNKONTO, name: 'Lohnkonto', _shared: true, _canWrite: false };

    it('offers Link to bill on a payment no bill holds', () => {
        expect(transactionMenuActions(PAYMENT, OWN)).toEqual(['duplicate', 'share', 'match', 'linkBill', 'delete']);
    });

    it('offers Link to bill on a payment of a bill that was deleted', () => {
        expect(transactionMenuActions({ ...PAYMENT, billId: 13, billDeleted: true }, OWN)).toContain('linkBill');
    });

    it('offers Unlink from bill on a payment of a bill that is still there', () => {
        const actions = transactionMenuActions({ ...PAYMENT, billId: 47, billDeleted: false, linkedTransactionId: 1301 }, OWN);

        expect(actions).toEqual(['duplicate', 'share', 'unlink', 'unlinkBill', 'delete']);
    });

    it('offers neither on money in, which no bill pays', () => {
        const actions = transactionMenuActions({ ...PAYMENT, type: 'credit' }, OWN);

        expect(actions).not.toContain('linkBill');
        expect(actions).not.toContain('unlinkBill');
    });

    it('offers neither on a scheduled row, a bill\'s upcoming payment included', () => {
        for (const row of [{ ...PAYMENT, status: 'scheduled' }, { ...PAYMENT, status: 'scheduled', billId: 47 }]) {
            const actions = transactionMenuActions(row, OWN);
            expect(actions).not.toContain('linkBill');
            expect(actions).not.toContain('unlinkBill');
        }
    });

    it('keeps a row shared read-only to Share expense', () => {
        expect(transactionMenuActions(PAYMENT, READ_ONLY)).toEqual(['share']);
    });
});
