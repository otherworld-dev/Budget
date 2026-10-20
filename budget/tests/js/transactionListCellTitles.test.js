/**
 * The full text of a cut cell on hover, in the Transactions list (#399).
 *
 * Round 2 put the full description and reference in title attributes on
 * the spans inside the description cell. In the Transactions list every
 * editable cell is covered by an invisible overlay that takes the clicks
 * for inline editing ('.editable-cell::after', absolutely positioned over
 * the whole cell), so the pointer only ever reaches the cell itself and no
 * tooltip came up: not for 'Wassertank Zubehör' against 'Wassertank
 * Zubehör, Diverses', nor for a cut reference. Cut vendors ('Gutschrift
 * Swisscom A…', the only name a row Mark Received booked has) had none at
 * all.
 *
 * The titles now sit on the cells: the description's with the reference on
 * a second line, and the vendor's. An account's register has no overlay,
 * so its spans keep theirs.
 */

import { describe, it, expect } from 'vitest';

import { renderTransactionRow } from '../../src/modules/transactions/transactionRow.js';
import { RULES } from './helpers/stylesheet.js';

function row(tx, variant = 'ledger', accounts = [{ id: 3, name: 'Lohnkonto' }]) {
    const tbody = document.createElement('tbody');
    tbody.innerHTML = renderTransactionRow(tx, {
        variant,
        accounts,
        categories: [{ id: 7, name: 'Diverses' }],
        currency: 'CHF',
        formatCurrency: (v) => Number(v).toFixed(2) + ' CHF',
        formatDate: (d) => d,
    });
    return tbody.querySelector('tr');
}

const LINE = { id: 1501, accountId: 3, date: '2026-10-12', type: 'debit', amount: 30, categoryId: 7, description: 'Wassertank Zubehör' };
const REFERENCE = 'Beleg "0412-7781" & <Kasse 3> 2026-10-12 Filiale Bahnhofstrasse 21';

const description = (tr) => tr.querySelector('td.description-column');
const vendor = (tr) => tr.querySelector('td.vendor-column');

describe('the Transactions list', () => {
    it('lays an overlay over each editable cell, so a title has to be on the cell', () => {
        const overlay = RULES.find(rule => rule.selectors.includes('.editable-cell::after'));

        expect(overlay.declarations).toMatchObject({ position: 'absolute', top: '0', left: '0', right: '0', bottom: '0' });
        expect(description(row(LINE)).classList.contains('editable-cell')).toBe(true);
        expect(vendor(row(LINE)).classList.contains('editable-cell')).toBe(true);
    });

    it('tells the two copies of a doubled line apart on the description cell', () => {
        expect(description(row(LINE)).getAttribute('title')).toBe('Wassertank Zubehör');
        expect(description(row({ ...LINE, id: 1555, description: 'Wassertank Zubehör, Diverses' })).getAttribute('title'))
            .toBe('Wassertank Zubehör, Diverses');
    });

    it('puts the reference on a second line', () => {
        expect(description(row({ ...LINE, reference: REFERENCE })).getAttribute('title'))
            .toBe(`Wassertank Zubehör\n${REFERENCE}`);
    });

    it('keeps the text as typed, quotes and markup included', () => {
        const typed = 'Mr "B&Q" <b>Garden</b> \'Centre\'';
        const tr = row({ ...LINE, description: typed, vendor: typed, reference: REFERENCE });

        expect(description(tr).getAttribute('title')).toBe(`${typed}\n${REFERENCE}`);
        expect(vendor(tr).getAttribute('title')).toBe(typed);
        expect(tr.querySelector('b')).toBeNull();
    });

    it('gives the placeholder of a row with no description no title', () => {
        const tr = row({ ...LINE, description: '', vendor: 'Gutschrift Swisscom Abo Leon' });

        expect(tr.querySelector('.description-column .primary-text').textContent).toBe('No description');
        expect(description(tr).hasAttribute('title')).toBe(false);
    });

    it('still shows a reference in full when there is no description', () => {
        const tr = row({ ...LINE, description: '   ', reference: 'QRR 21 00000 00003 13947 14300 09017' });

        expect(description(tr).getAttribute('title')).toBe('QRR 21 00000 00003 13947 14300 09017');
    });

    it('shows a cut vendor in full on the vendor cell', () => {
        const tr = row({ ...LINE, description: '', vendor: 'Gutschrift Swisscom Abo Leon' });

        expect(vendor(tr).getAttribute('title')).toBe('Gutschrift Swisscom Abo Leon');
    });

    it('gives an empty vendor no title', () => {
        expect(vendor(row({ ...LINE, vendor: null })).hasAttribute('title')).toBe(false);
        expect(vendor(row({ ...LINE, vendor: '' })).hasAttribute('title')).toBe(false);
    });

    it('has one title per cell, not a second one on the text inside', () => {
        const tr = row({ ...LINE, reference: REFERENCE });

        expect(tr.querySelectorAll('.description-column [title]:not(.linked-indicator):not([class$="-badge"]):not([class$="-indicator"])')).toHaveLength(0);
    });

    it('titles the cells of a row in an account shared read-only too, which have no overlay', () => {
        const tr = row({ ...LINE, vendor: 'Coop', reference: REFERENCE }, 'ledger',
            [{ id: 3, name: 'Lohnkonto', _shared: true, _canWrite: false }]);

        expect(tr.dataset.readOnly).toBe('1');
        expect(description(tr).getAttribute('title')).toBe(`Wassertank Zubehör\n${REFERENCE}`);
        expect(vendor(tr).getAttribute('title')).toBe('Coop');
    });
});

describe('an account\'s register', () => {
    it('keeps the titles on the description and the reference', () => {
        const tr = row({ ...LINE, reference: REFERENCE }, 'register');

        expect(tr.querySelector('.description-column .description-main').getAttribute('title')).toBe('Wassertank Zubehör');
        expect(tr.querySelector('.description-column .secondary-text').getAttribute('title')).toBe(REFERENCE);
    });
});
