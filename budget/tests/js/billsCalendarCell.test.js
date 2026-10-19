/**
 * A Bills by Month cell, from the row the server sends (#399). Two payments
 * for one invoice read as one large paid amount, with nothing to say the
 * cell held two, and a month the bill moved past with no payment recorded
 * was struck through like a payment, with a dashed line nobody could tell
 * apart. A cell now says what it holds: each payment and its date in the
 * tooltip, a count when one of its dates was paid more than once, and an
 * unrecorded month drawn muted, never struck through.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { calendarCell } from '../../src/utils/billsCalendarCell.js';
import ReportsModule from '../../src/modules/reports/ReportsModule.js';

const months = (list) => Object.fromEntries(Array.from({ length: 12 }, (_, i) => [i + 1, list.includes(i + 1)]));

// The reporter's CembraPay invoice, paid on 27 September and again on 15 October
const cembraPay = {
    name: 'CembraPay Temu', amount: 151.67, currency: 'CHF', frequency: 'one-time',
    occurrences: months([9]),
    paidMonths: [9],
    paidAmounts: { 9: 303.34 },
    paidDetails: { 9: [
        { date: '2026-09-27', amount: 151.67, due: '2026-09-28' },
        { date: '2026-10-15', amount: 151.67, due: '2026-09-28' },
    ] },
    paidMoreThanOnce: { 9: 2 },
    unrecordedMonths: [],
    expectedAmounts: { 9: 151.67 },
    owedAmounts: [],
};

// CSX Pro: April and May moved past with nothing recorded, 28 August paid
// twice, November still owed
const csx = {
    name: 'CSX Pro Credit Cards', amount: 100, currency: 'CHF', frequency: 'monthly', isTransfer: true,
    occurrences: months([4, 5, 6, 7, 8, 9, 10, 11, 12]),
    paidMonths: [6, 7, 8, 9, 10],
    paidAmounts: { 6: 50, 7: 50, 8: 100, 9: 100, 10: 100 },
    paidDetails: {
        6: [{ date: '2026-06-28', amount: 50, due: '2026-06-28' }],
        7: [{ date: '2026-07-25', amount: 50, due: '2026-07-28' }],
        8: [{ date: '2026-08-29', amount: 50, due: '2026-08-28' }, { date: '2026-08-30', amount: 50, due: '2026-08-28' }],
        9: [{ date: '2026-09-30', amount: 100, due: '2026-09-28' }],
        10: [{ date: '2026-09-30', amount: 100, due: '2026-10-28' }],
    },
    paidMoreThanOnce: { 8: 2 },
    unrecordedMonths: [4, 5],
    expectedAmounts: { 4: 100, 5: 100, 6: 100, 7: 100, 8: 100, 9: 100, 10: 100, 11: 100, 12: 100 },
    owedAmounts: { 11: 100, 12: 100 },
};

// Due on the 28th and paid a few days late: December's payment went out on
// 3 January, so January holds it as well as January's own
const latePayer = {
    name: 'Phone', amount: 80, currency: 'CHF', frequency: 'monthly',
    occurrences: months([1]),
    paidMonths: [1],
    paidAmounts: { 1: 160 },
    paidDetails: { 1: [
        { date: '2026-01-03', amount: 80, due: '2025-12-28' },
        { date: '2026-02-02', amount: 80, due: '2026-01-28' },
    ] },
    paidMoreThanOnce: [],
    unrecordedMonths: [],
    expectedAmounts: { 1: 80 },
    owedAmounts: [],
};

describe('calendarCell', () => {
    it('a cell with two payments is paid, counts them and lists both dates', () => {
        const cell = calendarCell(cembraPay, 9);

        expect(cell.state).toBe('paid');
        expect(cell.amount).toBeCloseTo(303.34);
        expect(cell.payments).toBe(2);
        expect(cell.marker).toBe('2×');
        expect(cell.title).toContain('2026-09-27');
        expect(cell.title).toContain('2026-10-15');
        expect(cell.title.split('\n')).toHaveLength(2);
    });

    it('a month moved past with no payment recorded shows its amount and says so', () => {
        const cell = calendarCell(csx, 4);

        expect(cell.state).toBe('unrecorded');
        expect(cell.amount).toBe(100);
        expect(cell.payments).toBe(0);
        expect(cell.marker).toBeNull();
        expect(cell.title).toContain('No payment recorded');
    });

    it('a month still to pay is owed', () => {
        const cell = calendarCell(csx, 11);

        expect(cell.state).toBe('owed');
        expect(cell.amount).toBe(100);
        expect(cell.title).toBe('Due');
    });

    it('one payment in a month is paid without a count', () => {
        const cell = calendarCell(csx, 6);

        expect(cell.state).toBe('paid');
        expect(cell.amount).toBe(50);
        expect(cell.payments).toBe(1);
        expect(cell.marker).toBeNull();
        expect(cell.title).toContain('2026-06-28');
    });

    it('counts the payments of a date paid more than once, and nothing else', () => {
        // Two invoices of one name due in the same month, each paid once
        const twoInvoices = {
            ...cembraPay,
            paidAmounts: { 9: 156.39 },
            paidDetails: { 9: [
                { date: '2026-09-27', amount: 151.67, due: '2026-09-28' },
                { date: '2026-09-27', amount: 4.72, due: '2026-09-28' },
            ] },
            paidMoreThanOnce: [],
        };
        expect(calendarCell(twoInvoices, 9).marker).toBeNull();

        const weekly = {
            name: 'Cleaner', amount: 10, currency: 'CHF', frequency: 'weekly',
            occurrences: months([1]), paidMonths: [1], paidAmounts: { 1: 60 },
            paidDetails: { 1: ['02', '09', '16', '16', '23', '30'].map(day => ({ date: `2026-01-${day}`, amount: 10, due: `2026-01-${day}` })) },
            paidMoreThanOnce: [], unrecordedMonths: [], expectedAmounts: { 1: 50 }, owedAmounts: [],
        };
        expect(calendarCell(weekly, 1).marker).toBeNull();
        expect(calendarCell(weekly, 1).payments).toBe(6);

        // One week paid twice: the count is how often that date was paid
        expect(calendarCell({ ...weekly, paidMoreThanOnce: { 1: 2 } }, 1).marker).toBe('2×');
        expect(calendarCell({ ...cembraPay, paidMoreThanOnce: { 9: 3 } }, 9).marker).toBe('3×');
    });

    it('a payment for a date of another year is no second payment, and says which date it paid', () => {
        const cell = calendarCell(latePayer, 1);

        expect(cell.state).toBe('paid');
        expect(cell.amount).toBe(160);
        expect(cell.payments).toBe(2);
        expect(cell.marker).toBeNull();
        const [december, january] = cell.title.split('\n');
        expect(december).toContain('2026-01-03');
        expect(december).toContain('for 2025-12-28');
        expect(january).toContain('2026-02-02');
        expect(january).not.toContain('for');
    });

    it('a month part paid and part still owed comes to both, and is still due', () => {
        const weekly = {
            name: 'Cleaner', amount: 10, currency: 'CHF', frequency: 'weekly',
            occurrences: months([2]), paidMonths: [2], paidAmounts: { 2: 20 },
            paidDetails: { 2: [{ date: '2026-02-06', amount: 10, due: '2026-02-06' }, { date: '2026-02-13', amount: 10, due: '2026-02-13' }] },
            paidMoreThanOnce: [], unrecordedMonths: [], expectedAmounts: { 2: 40 }, owedAmounts: { 2: 20 },
        };

        const cell = calendarCell(weekly, 2);

        expect(cell.state).toBe('owed');
        expect(cell.amount).toBe(40);
        expect(cell.payments).toBe(2);
        expect(cell.title.split('\n')).toHaveLength(3);
        expect(cell.title).toContain('still due');
        expect(cell.title).toContain('2026-02-13');
    });

    it('a row that says nothing about payments shows its amount as due', () => {
        const cell = calendarCell({ name: 'Rent', amount: 1200, occurrences: months([3]) }, 3);

        expect(cell.state).toBe('expected');
        expect(cell.amount).toBe(1200);
        expect(cell.title).toBe('Due');
    });

    it('a month the bill does not fall in is empty', () => {
        expect(calendarCell(csx, 2)).toEqual({ state: 'none', amount: null, payments: 0, title: '', marker: null });
    });

    it('dates and amounts follow the user settings', () => {
        const cell = calendarCell(cembraPay, 9, { date_format: 'd.m.Y', number_format_decimal_sep: ',', number_format_thousands_sep: '.' });

        expect(cell.title).toContain('27.09.2026');
        expect(cell.title).toContain('151,67');
    });
});

function makeModule() {
    const mod = Object.create(ReportsModule.prototype);
    mod.app = { accounts: [], settings: {} };
    mod.getPrimaryCurrency = () => 'CHF';
    mod.formatCurrency = (v, c) => `${Number(v).toFixed(2)} ${c}`;
    return mod;
}

describe('Bills Calendar heatmap', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('counts only the bills that make up the month\'s total', () => {
        document.body.innerHTML = '<div id="bills-calendar-heatmap"></div>';
        const paidInApril = {
            name: 'Dentist', amount: 166.70, currency: 'CHF', frequency: 'one-time', isActive: false,
            occurrences: months([4]), paidMonths: [4], paidAmounts: { 4: 166.70 },
            paidDetails: { 4: [{ date: '2026-04-26', amount: 166.70, due: '2026-04-27' }] }, paidMoreThanOnce: [],
            unrecordedMonths: [], expectedAmounts: { 4: 166.70 }, owedAmounts: [],
        };

        // CSX moved past April with nothing recorded, so April is the dentist alone
        makeModule().renderBillsCalendarHeatmap([csx, paidInApril], { 4: 166.70, 6: 50 });

        const tiles = document.querySelectorAll('.heatmap-month');
        expect(tiles[3].querySelector('.heatmap-month-count').textContent).toBe('%n bill');
        expect(tiles[5].querySelector('.heatmap-month-count').textContent).toBe('%n bill');
        expect(tiles[10].querySelector('.heatmap-month-count').textContent).toBe('%n bill');
        expect(tiles[0].querySelector('.heatmap-month-count').textContent).toBe('%n bills');
    });
});

describe('Bills Calendar table cells', () => {
    const cells = () => document.querySelectorAll('#bills-calendar-table-body tr')[0].querySelectorAll('td.month-cell');

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

    it('draws paid, unrecorded and owed months apart, and counts a double payment', () => {
        makeModule().renderBillsCalendarTable([csx], {}, null, null, null);

        const april = cells()[3];
        expect(april.classList.contains('unrecorded')).toBe(true);
        expect(april.classList.contains('paid')).toBe(false);
        expect(april.textContent).toContain('100.00 CHF');

        const august = cells()[7];
        expect(august.classList.contains('paid')).toBe(true);
        expect(august.querySelector('.payments-marker').textContent).toBe('2×');
        expect(august.getAttribute('title')).toContain('2026-08-29');
        expect(august.getAttribute('title')).toContain('2026-08-30');

        const november = cells()[10];
        expect(november.classList.contains('has-bill')).toBe(true);
        expect(november.classList.contains('paid')).toBe(false);
        expect(november.classList.contains('unrecorded')).toBe(false);

        expect(cells()[0].classList.contains('no-bill')).toBe(true);
    });

    it('escapes the tooltip', () => {
        const odd = { ...cembraPay, currency: '"><img src=x>' };

        makeModule().renderBillsCalendarTable([odd], {}, null, null, null);

        expect(document.querySelector('#bills-calendar-table-body img')).toBeNull();
        expect(cells()[8].getAttribute('title')).toContain('"><img src=x>');
    });
});
