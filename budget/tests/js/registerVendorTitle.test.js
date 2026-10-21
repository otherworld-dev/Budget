/**
 * A cut vendor in an account's register (#399, browser round 3). Round 3
 * gave the Transactions list's vendor cells the full name as a title; the
 * register's vendor cell had none, so 'Gutschrift Swisscom A…' could only
 * be read by opening the row. It now has the same title, escaped.
 */

import { describe, it, expect } from 'vitest';

import { renderTransactionRow } from '../../src/modules/transactions/transactionRow.js';

function registerRow(tx) {
    const tbody = document.createElement('tbody');
    tbody.innerHTML = renderTransactionRow(tx, {
        variant: 'register',
        accounts: [{ id: 3, name: 'Lohnkonto' }],
        categories: [{ id: 7, name: 'Diverses' }],
        currency: 'CHF',
        formatCurrency: (v) => Number(v).toFixed(2) + ' CHF',
        formatDate: (d) => d,
    });
    return tbody.querySelector('tr');
}

const LINE = { id: 1501, accountId: 3, date: '2026-10-12', type: 'credit', amount: 30, categoryId: 7, description: 'Abo' };
const vendor = (tr) => tr.querySelector('td.vendor-column');

describe('an account\'s register', () => {
    it('shows a cut vendor in full on the vendor cell', () => {
        expect(vendor(registerRow({ ...LINE, vendor: 'Gutschrift Swisscom Abo Leon' })).getAttribute('title'))
            .toBe('Gutschrift Swisscom Abo Leon');
    });

    it('keeps the vendor as typed, quotes and markup included', () => {
        const typed = 'Mr "B&Q" <b>Garden</b> \'Centre\'';
        const tr = registerRow({ ...LINE, vendor: typed });

        expect(vendor(tr).getAttribute('title')).toBe(typed);
        expect(vendor(tr).textContent).toBe(typed);
        expect(tr.querySelector('b')).toBeNull();
    });

    it('gives an empty vendor no title', () => {
        expect(vendor(registerRow({ ...LINE, vendor: null })).hasAttribute('title')).toBe(false);
        expect(vendor(registerRow({ ...LINE, vendor: '  ' })).hasAttribute('title')).toBe(false);
    });
});
