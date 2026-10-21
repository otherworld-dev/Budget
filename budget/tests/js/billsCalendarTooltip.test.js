/**
 * Which due date a Bills by Month payment paid (#399). The tooltip said so
 * only when the due date fell in another month than the cell, so the cells
 * the reporter asked about stayed unexplained: CSX's October, paid on
 * 30 September for 28 October, read "Paid 100.00 CHF on 2026-09-30", and
 * KPT's second August payment never said it paid 29 August again. A line
 * names the due date whenever the payment was made on another day.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { calendarCell } from '../../src/utils/billsCalendarCell.js';

const months = (list) => Object.fromEntries(Array.from({ length: 12 }, (_, i) => [i + 1, list.includes(i + 1)]));

const row = (month, payments, extra = {}) => ({
    name: 'Bill', amount: payments[0]?.amount ?? 0, currency: 'CHF', frequency: 'monthly',
    occurrences: months([month]),
    paidMonths: [month],
    paidAmounts: { [month]: payments.reduce((sum, p) => sum + p.amount, 0) },
    paidDetails: { [month]: payments },
    paidMoreThanOnce: [],
    unrecordedMonths: [],
    expectedAmounts: { [month]: payments[0]?.amount ?? 0 },
    owedAmounts: [],
    ...extra,
});

describe('Bills by Month tooltip names the due date a payment paid', () => {
    it('KPT: the 30 August payment paid 29 August, the 29 August one paid its own day', () => {
        const kpt = row(8, [
            { date: '2026-08-29', amount: 484.75, due: '2026-08-29' },
            { date: '2026-08-30', amount: 484.75, due: '2026-08-29' },
        ], { paidMoreThanOnce: { 8: 2 } });

        const [first, second] = calendarCell(kpt, 8).title.split('\n');

        expect(first).toMatch(/on 2026-08-29$/);
        expect(first).not.toContain('for');
        expect(second).toMatch(/on 2026-08-30 for 2026-08-29$/);
    });

    it('CSX: October paid on 30 September says it was for 28 October', () => {
        const csx = row(10, [{ date: '2026-09-30', amount: 100, due: '2026-10-28' }]);

        expect(calendarCell(csx, 10).title).toMatch(/on 2026-09-30 for 2026-10-28$/);
    });

    it('a payment made on its due date names no other date', () => {
        const cell = calendarCell(row(6, [{ date: '2026-06-28', amount: 50, due: '2026-06-28' }]), 6);

        expect(cell.title).toMatch(/on 2026-06-28$/);
        expect(cell.title).not.toContain('for');
    });

    it('a payment that paid no date names none', () => {
        const cell = calendarCell(row(8, [{ date: '2026-08-27', amount: 1368.35, due: null }]), 8);

        expect(cell.title).toMatch(/on 2026-08-27$/);
        expect(cell.title).not.toContain('for');
    });

    it('the due date follows the user\'s date format', () => {
        const csx = row(10, [{ date: '2026-09-30', amount: 100, due: '2026-10-28' }]);

        expect(calendarCell(csx, 10, { date_format: 'd.m.Y' }).title).toMatch(/on 30\.09\.2026 for 28\.10\.2026$/);
    });
});
