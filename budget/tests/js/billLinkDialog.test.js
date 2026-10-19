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

const open = (bills, transaction = undefined) => {
    const choice = chooseBill(bills, {}, transaction);
    return { choice, modal: document.getElementById('bill-link-modal') };
};
const names = (modal) => [...modal.querySelectorAll('.matching-tx-desc')].map(el => el.textContent.trim());
const ids = (modal) => [...modal.querySelectorAll('input[name="bill-link-choice"]')].map(el => Number(el.value));
const statusOf = (modal, billId) => modal.querySelector(`input[value="${billId}"]`).closest('label')
    .querySelector('.matching-tx-reasons')?.textContent.trim() ?? '';
const badgeOf = (modal, billId) => modal.querySelector(`input[value="${billId}"]`).closest('label')
    .querySelector('.matching-tx-badge')?.textContent.trim() ?? null;
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

    /**
     * The reporter files each invoice as its own one-time bill, so 60 bills
     * on Lohnkonto hold twins: Miele Geschirrspühler twice at 1,269.55, both
     * paid. Each takes its payments into its own month in Bills by Month, so
     * the dialog has to say which invoice is which.
     */
    it('tells bills of one name and amount apart by their dates, earliest first', () => {
        const { modal } = open([
            { id: 4600, name: 'Miele Geschirrspühler', frequency: 'one-time', accountId: LOHNKONTO, isActive: false, amount: 1269.55, startDate: '2026-06-25', nextDueDate: null, lastPaidDate: '2026-06-28' },
            { id: 4599, name: 'Miele Geschirrspühler', frequency: 'one-time', accountId: LOHNKONTO, isActive: false, amount: 1269.55, startDate: '2026-05-27', nextDueDate: null, lastPaidDate: '2026-05-31' },
        ]);

        expect(ids(modal)).toEqual([4599, 4600]);
        expect(statusOf(modal, 4599)).toBe('Due 2026-05-27 · Paid 2026-05-31 · Inactive');
        expect(statusOf(modal, 4600)).toBe('Due 2026-06-25 · Paid 2026-06-28 · Inactive');
    });

    it('dates a recurring bill by its next due date, or once ended by its last payment', () => {
        const { modal } = open([
            { id: 4640, name: 'Elektrizitätswerke', frequency: 'custom', accountId: LOHNKONTO, isActive: true, amount: 190, nextDueDate: '2027-09-29', lastPaidDate: '2026-09-27' },
            { id: 4612, name: 'Elektrizitätswerke', frequency: 'one-time', accountId: LOHNKONTO, isActive: false, amount: 190, startDate: '2026-09-30', nextDueDate: '2026-09-30', lastPaidDate: '2026-08-30' },
            { id: 13, name: 'Elektrizitätswerke', frequency: 'monthly', accountId: LOHNKONTO, isActive: false, amount: 190, nextDueDate: null, lastPaidDate: '2026-03-28', endDate: '2026-03-31' },
        ]);

        expect(ids(modal)).toEqual([13, 4612, 4640]);
        expect(statusOf(modal, 13)).toBe('Paid 2026-03-28 · Inactive');
        expect(statusOf(modal, 4612)).toBe('Due 2026-09-30 · Paid 2026-08-30 · Inactive');
        expect(statusOf(modal, 4640)).toBe('Due 2027-09-29 · Paid 2026-09-27');
    });

    it('puts the bills of the row\'s amount first and says so', () => {
        const { modal } = open(linkableBills(BILLS, PAYMENT), { ...PAYMENT, amount: 80 });

        expect(names(modal)).toEqual(['Elektrizitätswerke', 'Corner Card SA', 'CSX Pro Credit Cards']);
        expect(badgeOf(modal, 92)).toBe('Exact amount');
        expect(badgeOf(modal, 120)).toBeNull();
        expect(badgeOf(modal, 47)).toBeNull();
    });

    it('matches the amount to the cent', () => {
        const { modal } = open([
            { id: 1, name: 'A', accountId: LOHNKONTO, isActive: true, amount: 100.01 },
            { id: 2, name: 'B', accountId: LOHNKONTO, isActive: true, amount: '100.00' },
        ], { ...PAYMENT, amount: 100 });

        expect(ids(modal)).toEqual([2, 1]);
        expect(badgeOf(modal, 1)).toBeNull();
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
