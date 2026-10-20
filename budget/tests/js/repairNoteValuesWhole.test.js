/**
 * The dates and amounts inside Repair Data's notes stay whole (#399).
 *
 * Round 2 kept a row's own amount and date on one line, but the note
 * beside them is free text that wraps, and between about 440 and 760 px
 * Chrome broke the dates in it after their hyphens ('Also recorded as
 * Christian Uhrenschmuck24 on 2026-' / '03-28', 'for 2026-' / '09-28')
 * and an amount at its space ('41.70' / 'CHF'). Each date and amount in a
 * note now sits in a span of its own that the stylesheet keeps whole; the
 * note reads exactly as before, and the names in it stay text.
 *
 * This uses the real @nextcloud/l10n, whose sanitiser runs over the whole
 * note, so the spans and the escaping are checked as the page gets them.
 */

import { describe, it, expect, afterEach } from 'vitest';

import { duplicateItem, stuckIncomeItem } from '../../src/utils/repairFindings.js';
import { declarationsFor } from './helpers/stylesheet.js';

const chf = (amount) => `${Number(amount).toFixed(2)} CHF`;

const finding = (overrides = {}) => ({
    duplicateId: 1007, originalId: 1100, amount: 484.75, originalAmount: 484.75,
    date: '2026-08-29', originalDate: '2026-08-30', vendor: 'KPT Prämienrechnung', description: '',
    originalDescription: 'KPT Prämienrechnung', originalReconciled: false,
    reason: 'same_occurrence', occurrence: '2026-08-29', billName: 'KPT Prämienrechnung',
    transfer: false, removesDeposit: false, selected: false,
    ...overrides,
});

/** The note of a row as the modal shows it */
function note(html) {
    const modal = document.createElement('div');
    modal.id = 'repair-data-modal';
    modal.innerHTML = `<div class="repair-category-details">${html}</div>`;
    document.body.appendChild(modal);
    return modal.querySelector('.repair-item-note');
}

const DATE = /\d{4}-\d{2}-\d{2}/;
const AMOUNT = /\d+\.\d{2}\s*CHF|CHF\s*\d+\.\d{2}/;

/**
 * The note's text as the browser lays it out: each run of text with
 * whether it is kept on one line. Runs straight in the note may wrap.
 */
function runs(el) {
    const out = [];
    const walk = (node, whole) => {
        node.childNodes.forEach((child) => {
            if (child.nodeType === Node.TEXT_NODE) {
                out.push({ text: child.textContent, whole });
            } else if (child.nodeType === Node.ELEMENT_NODE) {
                walk(child, whole || declarationsFor(child)['white-space'] === 'nowrap');
            }
        });
    };
    walk(el, false);
    return out;
}

/** The dates and amounts in a note that can break, which should be none */
function breakable(el) {
    return runs(el).filter(run => !run.whole && (DATE.test(run.text) || AMOUNT.test(run.text))).map(run => run.text);
}

/** The values kept whole, in order */
function kept(el) {
    return runs(el).filter(run => run.whole).map(run => run.text);
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('a duplicate\'s note', () => {
    it('keeps the date it was due and the other payment\'s date whole', () => {
        const el = note(duplicateItem(finding({ occurrence: '2026-09-28', originalDate: '2026-09-27' }), chf));

        expect(el.textContent).toBe('Looks like a second payment for 2026-09-28 (other payment on 2026-09-27)');
        expect(breakable(el)).toEqual([]);
        expect(kept(el)).toEqual(['2026-09-28', '2026-09-27']);
    });

    it('keeps the other payment\'s amount whole too', () => {
        const el = note(duplicateItem(finding({ amount: 161.25, originalAmount: 155.25, occurrence: '2026-08-31' }), chf));

        expect(el.textContent).toBe('Looks like a second payment for 2026-08-31 (other payment of 155.25 CHF on 2026-08-30)');
        expect(breakable(el)).toEqual([]);
        expect(kept(el)).toEqual(['2026-08-31', '155.25 CHF', '2026-08-30']);
    });

    it('keeps the date of a row an imported one records too whole', () => {
        const el = note(duplicateItem(finding({
            reason: 'auto_gen_duplicates_manual', originalDescription: 'Christian Uhrenschmuck24',
            originalDate: '2026-03-28', originalReconciled: true,
        }), chf));

        expect(el.textContent).toBe('Also recorded as Christian Uhrenschmuck24 on 2026-03-28 (reconciled)');
        expect(breakable(el)).toEqual([]);
        expect(kept(el)).toEqual(['2026-03-28']);
    });

    it('keeps a deleted bill\'s other payment date whole, and the transfer\'s note as it was', () => {
        const el = note(duplicateItem(finding({
            reason: 'deleted_bill', occurrence: undefined, billName: 'Christian Uhrenschmuck24',
            originalDate: '2026-03-28', transfer: true, removesDeposit: true,
        }), chf));

        expect(el.textContent).toBe('Looks like a second payment of Christian Uhrenschmuck24 (other payment on 2026-03-28)'
            + ' The other side of the transfer is removed with it.');
        expect(breakable(el)).toEqual([]);
    });

    it('wraps only the dates and amounts, never a name', () => {
        // A name that looks like a date is the name, not a value to keep whole
        const el = note(duplicateItem(finding({
            reason: 'auto_gen_duplicates_manual', originalDescription: 'Rechnung 2026-03-01', originalDate: '2026-03-28',
        }), chf));

        expect(el.textContent).toBe('Also recorded as Rechnung 2026-03-01 on 2026-03-28');
        expect(kept(el)).toEqual(['2026-03-28']);
    });

    it.each([
        ['auto_gen_duplicates_manual', { originalDescription: 'Mr "B&Q" <b>Garden</b> \'Centre\'' }, 'Also recorded as Mr "B&Q" <b>Garden</b> \'Centre\' on 2026-08-30'],
        ['deleted_bill', { billName: 'Christian <b>Uhren</b> & Co', occurrence: undefined }, 'Looks like a second payment of Christian <b>Uhren</b> & Co (other payment on 2026-08-30)'],
    ])('shows a %s name as text', (reason, overrides, text) => {
        const el = note(duplicateItem(finding({ reason, ...overrides }), chf));

        expect(el.querySelector('b')).toBeNull();
        expect(el.textContent).toBe(text);
    });

    it('shows a value as text', () => {
        const el = note(duplicateItem(finding({ occurrence: '<i>2026-09-28</i>' }), chf));

        expect(el.querySelector('i')).toBeNull();
        expect(el.textContent).toBe('Looks like a second payment for <i>2026-09-28</i> (other payment on 2026-08-30)');
    });
});

describe('an early income\'s note', () => {
    it('keeps the date it moves on to whole', () => {
        const el = note(stuckIncomeItem({
            incomeId: 9, name: 'Gutschrift SBB Abo Leon', amount: 100, transactionId: 1293,
            lastReceivedDate: '2026-09-28', nextExpectedDate: '2026-09-29', settledNextExpectedDate: '2026-10-29',
        }, chf));

        expect(el.textContent).toBe('Moves on to 2026-10-29');
        expect(breakable(el)).toEqual([]);
        expect(kept(el)).toEqual(['2026-10-29']);
    });
});
