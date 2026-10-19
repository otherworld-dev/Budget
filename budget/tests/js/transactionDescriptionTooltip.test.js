/**
 * The full description on hover in the transaction lists (#399).
 *
 * The description cell is one line, cut with an ellipsis: at 1440px the
 * Transactions list gives it about 90px. The receipt lines the reporter
 * imported twice on 12 Oct, 'Wassertank Zubehör' and 'Wassertank Zubehör,
 * Diverses', both read 'Wassertank …', same category, same amount, and the
 * reply asked them to delete one of each. The cell now carries its full
 * text as a tooltip, in the main list and an account's register alike (the
 * same row markup), and so does the reference under it.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural).replace('%n', count),
}));

import { renderTransactionRow } from '../../src/modules/transactions/transactionRow.js';

function row(tx, variant) {
    const tbody = document.createElement('tbody');
    tbody.innerHTML = renderTransactionRow(tx, {
        variant,
        accounts: [{ id: 3, name: 'Lohnkonto' }],
        categories: [{ id: 7, name: 'Diverses' }],
        currency: 'CHF',
        formatCurrency: (v) => Number(v).toFixed(2) + ' CHF',
        formatDate: (d) => d,
    });
    return tbody.querySelector('tr');
}

const LINE = { id: 1501, accountId: 3, date: '2026-10-12', type: 'debit', amount: 30, categoryId: 7, description: 'Wassertank Zubehör' };
const text = (tr, variant) => tr.querySelector(variant === 'ledger' ? '.description-column .primary-text' : '.description-column .description-main');

describe.each(['ledger', 'register'])('%s: the description cell', (variant) => {
    it('tells the two copies of a doubled line apart on hover', () => {
        const plain = text(row(LINE, variant), variant);
        const copy = text(row({ ...LINE, id: 1555, description: 'Wassertank Zubehör, Diverses' }, variant), variant);

        expect(plain.getAttribute('title')).toBe('Wassertank Zubehör');
        expect(copy.getAttribute('title')).toBe('Wassertank Zubehör, Diverses');
    });

    it('keeps the text as typed, quotes and markup included', () => {
        const description = 'Mr "B&Q" <b>Garden</b> \'Centre\'';
        const tr = row({ ...LINE, description }, variant);

        expect(text(tr, variant).getAttribute('title')).toBe(description);
        expect(tr.querySelector('b')).toBeNull();
    });

    it('has no tooltip on a row with no description, which says so in full', () => {
        const cell = text(row({ ...LINE, description: '', vendor: 'SBB GA Leon' }, variant), variant);

        expect(cell.textContent).toBe('No description');
        expect(cell.hasAttribute('title')).toBe(false);
    });

    it('gives the reference under it its own tooltip', () => {
        const tr = row({ ...LINE, reference: 'QRR 21 00000 00003 13947 14300 09017' }, variant);

        expect(tr.querySelector('.description-column .secondary-text').getAttribute('title'))
            .toBe('QRR 21 00000 00003 13947 14300 09017');
    });
});
