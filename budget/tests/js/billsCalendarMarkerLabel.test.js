/**
 * What a screen reader hears for the "2×" pill in Bills by Month (#399). The
 * pill sits right against the amount, so the cell's text read "2×969.50 CHF":
 * two times 969.50, which suggests 1,939.00 when 969.50 is what was paid in
 * all. Words out of sight said "Paid 2 times" just before the amount, and
 * read together that was still "Paid 2 times969.50 CHF". The pill and the
 * amount are now hidden from assistive technology, and one sentence out of
 * sight stands in for both. What the cell looks like doesn't change.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count, params = {}) =>
        (count === 1 ? singular : plural).replace('%n', String(count))
            .replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
}));

import ReportsModule from '../../src/modules/reports/ReportsModule.js';

const months = (list) => Object.fromEntries(Array.from({ length: 12 }, (_, i) => [i + 1, list.includes(i + 1)]));

// KPT Prämienrechnung: 29 August paid on the 29th and again on the 30th
const kpt = {
    name: 'KPT Prämienrechnung', amount: 484.75, currency: 'CHF', frequency: 'monthly',
    occurrences: months([8, 9]),
    paidMonths: [8, 9],
    paidAmounts: { 8: 969.5, 9: 484.75 },
    paidDetails: {
        8: [{ date: '2026-08-29', amount: 484.75, due: '2026-08-29' }, { date: '2026-08-30', amount: 484.75, due: '2026-08-29' }],
        9: [{ date: '2026-09-27', amount: 484.75, due: '2026-09-29' }],
    },
    paidMoreThanOnce: { 8: 2 },
    unrecordedMonths: [],
    expectedAmounts: { 8: 484.75, 9: 484.75 },
    owedAmounts: [],
};

function render(rows) {
    const mod = Object.create(ReportsModule.prototype);
    mod.app = { accounts: [], settings: {} };
    mod.getPrimaryCurrency = () => 'CHF';
    mod.formatCurrency = (v, c) => `${Number(v).toFixed(2)} ${c}`;
    mod.renderBillsCalendarTable(rows, {}, null, null, null);
    return document.querySelectorAll('#bills-calendar-table-body tr')[0].querySelectorAll('td.month-cell');
}

/** The text a screen reader is given: everything but what is aria-hidden. */
function spokenText(el) {
    let text = '';
    el.childNodes.forEach((node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            text += node.textContent;
        } else if (node.getAttribute('aria-hidden') !== 'true') {
            text += spokenText(node);
        }
    });
    return text;
}

describe('the Bills by Month payments marker for screen readers', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <table>
                <tbody id="bills-calendar-table-body"></tbody>
                <tfoot id="bills-calendar-table-footer"></tfoot>
            </table>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('reads as one sentence: how many times, and what was paid in all', () => {
        const august = render([kpt])[7];

        expect(spokenText(august)).toBe('Paid 2 times, 969.50 CHF in all');
    });

    it('hides the pill and the amount it sits against from screen readers', () => {
        const august = render([kpt])[7];
        const marker = august.querySelector('.payments-marker');

        expect(marker.closest('[aria-hidden="true"]')).not.toBeNull();
        expect(marker.nextSibling.parentElement.closest('[aria-hidden="true"]')).not.toBeNull();
    });

    it('keeps the words out of sight', () => {
        const august = render([kpt])[7];
        const label = august.querySelector('.visually-hidden');

        expect(label.textContent).toBe('Paid 2 times, 969.50 CHF in all');
        expect(label.closest('[aria-hidden="true"]')).toBeNull();
    });

    it('still shows the pill right against the amount, with no space to strike through', () => {
        const august = render([kpt])[7];
        const marker = august.querySelector('.payments-marker');

        expect(marker.textContent).toBe('2×');
        expect(marker.nextSibling.nodeType).toBe(Node.TEXT_NODE);
        expect(marker.nextSibling.textContent).toBe('969.50 CHF');
    });

    it('adds nothing to a month paid once', () => {
        const september = render([kpt])[8];

        expect(september.querySelector('.visually-hidden')).toBeNull();
        expect(september.textContent).toBe('484.75 CHF');
        expect(spokenText(september)).toBe('484.75 CHF');
    });
});
