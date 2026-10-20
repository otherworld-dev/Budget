/**
 * A Bills by Month date paid by a payment of the other year (#399). A bill
 * due on 5 January and paid on 28 December showed the payment in 2026 and
 * the date still owed in 2027, counted in both years. The server now sends
 * that date as paidElsewhere: the cell is neither paid nor owed, adds
 * nothing to the totals, and its tooltip names the payment and its date.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { calendarCell } from '../../src/utils/billsCalendarCell.js';

const garage2027 = (extra = {}) => ({
    name: 'Garage Miete', amount: 150, currency: 'CHF', frequency: 'monthly',
    occurrences: Object.fromEntries(Array.from({ length: 12 }, (_, i) => [i + 1, true])),
    paidMonths: [],
    paidAmounts: [],
    paidDetails: [],
    paidMoreThanOnce: [],
    paidElsewhere: { 1: [{ date: '2026-12-28', amount: 150, due: '2027-01-05' }] },
    unrecordedMonths: [],
    expectedAmounts: Object.fromEntries(Array.from({ length: 12 }, (_, i) => [i + 1, 150])),
    owedAmounts: Object.fromEntries(Array.from({ length: 11 }, (_, i) => [i + 2, 150])),
    ...extra,
});

describe('Bills by Month: a date paid in the other year', () => {
    it('is drawn like a month with nothing to add, and names the payment', () => {
        const cell = calendarCell(garage2027(), 1);

        expect(cell.state).toBe('unrecorded');
        expect(cell.amount).toBe(150);
        expect(cell.title).toMatch(/^Paid .*150.* on 2026-12-28 for 2027-01-05$/);
        expect(cell.title).not.toContain('No payment recorded');
        expect(cell.marker).toBeNull();
    });

    it('leaves the months after it owed', () => {
        expect(calendarCell(garage2027(), 2).state).toBe('owed');
    });

    it('is named in a month whose other dates are still owed (a weekly bill due on 1 January)', () => {
        const cell = calendarCell(garage2027({
            frequency: 'weekly', amount: 40,
            paidElsewhere: { 1: [{ date: '2026-12-30', amount: 40, due: '2027-01-01' }] },
            owedAmounts: { 1: 160 },
        }), 1);

        expect(cell.state).toBe('owed');
        expect(cell.amount).toBe(160);
        expect(cell.title.split('\n')).toEqual(['Due', expect.stringMatching(/on 2026-12-30 for 2027-01-01$/)]);
    });

    it('is listed beside the payments of a month that has them', () => {
        const cell = calendarCell(garage2027({
            paidMonths: [1],
            paidAmounts: { 1: 40 },
            paidDetails: { 1: [{ date: '2027-01-08', amount: 40, due: '2027-01-08' }] },
        }), 1);

        expect(cell.state).toBe('paid');
        expect(cell.title.split('\n')).toHaveLength(2);
        expect(cell.title).toContain('on 2026-12-28 for 2027-01-05');
    });
});
