/**
 * Repair Data's notes for the doubles older versions left (#399): a
 * transfer paid twice goes with its deposit, a pair of different amounts
 * shows the other amount, a deleted bill's pair names the bill, and an
 * income received early lists the date it moves on to.
 *
 * The l10n mock escapes placeholders the way the real library does, so a
 * name is checked to come out as text, escaped once.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => {
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    const fill = (text, params = {}, options = {}) => String(text).replace(/\{(\w+)\}/g,
        (m, k) => (k in params ? (options?.escape === false ? String(params[k]) : esc(params[k])) : m));
    return {
        translate: (_app, text, params, _count, options) => fill(text, params, options),
        translatePlural: (_app, singular, plural, count, params, options) =>
            fill(count === 1 ? singular : plural, params, options),
        getLanguage: () => 'en',
        getLocale: () => 'en',
    };
});

import { duplicateNote, stuckIncomeItem } from '../../src/utils/repairFindings.js';

const chf = (amount) => `CHF ${Number(amount).toFixed(2)}`;

/**
 * A note as it reads on the page. Its dates and amounts sit in spans of
 * their own, which keep them on one line (repairNoteValuesWhole.test.js).
 */
function rendered(html) {
    const el = document.createElement('div');
    el.innerHTML = html;
    return el;
}
const text = (html) => rendered(html).textContent;

const sameOccurrence = (overrides = {}) => ({
    duplicateId: 1007, originalId: 1100, amount: 484.75, originalAmount: 484.75,
    date: '2026-08-29', originalDate: '2026-08-30', vendor: 'KPT Prämienrechnung',
    originalDescription: 'KPT Prämienrechnung', originalReconciled: false,
    reason: 'same_occurrence', occurrence: '2026-08-29', billName: 'KPT Prämienrechnung',
    transfer: false, removesDeposit: false, selected: false,
    ...overrides,
});

describe('duplicateNote', () => {
    it('names the occurrence and the other payment', () => {
        expect(text(duplicateNote(sameOccurrence(), chf)))
            .toBe('Looks like a second payment for 2026-08-29 (other payment on 2026-08-30)');
    });

    it('shows the other amount when the two differ', () => {
        const note = duplicateNote(sameOccurrence({ amount: 161.25, originalAmount: 155.25, occurrence: '2026-08-31' }), chf);

        expect(text(note)).toBe('Looks like a second payment for 2026-08-31 (other payment of CHF 155.25 on 2026-08-30)');
    });

    it('says a transfer goes with its deposit', () => {
        const note = duplicateNote(sameOccurrence({
            amount: 50, originalAmount: 50, transfer: true, removesDeposit: true, occurrence: '2026-08-28',
        }), chf);

        expect(text(note)).toBe('Looks like a second payment for 2026-08-28 (other payment on 2026-08-30)'
            + ' The other side of the transfer is removed with it.');
    });

    it('says nothing of a deposit a transfer goes without', () => {
        // Its deposit long gone (#399's July CSX payment)
        const note = duplicateNote(sameOccurrence({
            amount: 50, originalAmount: 50, transfer: true, removesDeposit: false, occurrence: '2026-08-28',
        }), chf);

        expect(text(note)).toBe('Looks like a second payment for 2026-08-28 (other payment on 2026-08-30)');
    });

    it('names a deleted bill by the name its payments carry, as text', () => {
        const note = rendered(duplicateNote(sameOccurrence({
            reason: 'deleted_bill', occurrence: undefined, billName: 'Christian <b>Uhren</b> & Co',
        }), chf));

        expect(note.querySelector('b')).toBeNull();
        expect(note.textContent).toBe('Looks like a second payment of Christian <b>Uhren</b> & Co (other payment on 2026-08-30)');
    });

    it('keeps the note for a payment an imported row records too', () => {
        const note = duplicateNote(sameOccurrence({
            reason: 'auto_gen_duplicates_manual', originalDescription: 'NETFLIX.COM', originalReconciled: true,
        }), chf);

        expect(text(note)).toBe('Also recorded as NETFLIX.COM on 2026-08-30 (reconciled)');
    });

    it('reads a finding without the other amount as one of equal amounts', () => {
        const finding = sameOccurrence();
        delete finding.originalAmount;

        expect(text(duplicateNote(finding, chf))).toBe('Looks like a second payment for 2026-08-29 (other payment on 2026-08-30)');
    });
});

describe('stuckIncomeItem', () => {
    it('lists the receipt and the date the income moves on to', () => {
        const html = stuckIncomeItem({
            incomeId: 9, name: 'Gutschrift SBB Abo Leon', amount: 100, transactionId: 1293,
            lastReceivedDate: '2026-09-28', nextExpectedDate: '2026-09-29', settledNextExpectedDate: '2026-10-29',
        }, chf);
        const row = document.createElement('div');
        row.innerHTML = html;

        expect([...row.querySelectorAll('.repair-item > span')].map(s => s.textContent)).toEqual([
            'Gutschrift SBB Abo Leon', 'CHF 100.00', 'Received 2026-09-28', 'Moves on to 2026-10-29',
        ]);
    });

    it('renders the name as text', () => {
        const row = document.createElement('div');
        row.innerHTML = stuckIncomeItem({
            name: '<img src=x onerror="window.__xss=1">', amount: 1,
            lastReceivedDate: '2026-09-28', settledNextExpectedDate: '2026-10-29',
        }, chf);

        expect(row.querySelector('img')).toBeNull();
        expect(row.querySelector('span').textContent).toBe('<img src=x onerror="window.__xss=1">');
    });
});
