/**
 * The "2×" pill in a Bills by Month cell (#399). A paid cell is struck
 * through, and the text space between the pill and the amount was struck
 * with it, so the cell read "2× -969.50 CHF", a negative amount. The pill
 * keeps its distance with a margin instead (css/style.css), and nothing but
 * the amount follows it.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
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

describe('Bills by Month payments marker', () => {
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

    it('is followed by the amount, with no space for the line-through to run under', () => {
        const august = render([kpt])[7];
        const marker = august.querySelector('.payments-marker');

        expect(marker.textContent).toBe('2×');
        expect(marker.nextSibling.nodeType).toBe(Node.TEXT_NODE);
        expect(marker.nextSibling.textContent).toBe('969.50 CHF');
        expect(august.textContent).toBe('2×969.50 CHF');
    });

    it('leaves a cell without one as the amount alone', () => {
        const september = render([kpt])[8];

        expect(september.querySelector('.payments-marker')).toBeNull();
        expect(september.textContent).toBe('484.75 CHF');
    });
});
