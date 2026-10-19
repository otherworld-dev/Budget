/**
 * Custom and One-Time rows (#399). Neither had a cycle, so both fell back to
 * a month: a Custom bill due once a year turned Upcoming with Mark Paid 31
 * days after it was paid (one click paid next year's), and a one-time bill
 * an edit had put back on the date it paid read "Paid 27 Sep, next due
 * 28 Sep" beside its Overdue badge. A Custom schedule's cycle is now a year
 * shared out among the dates it falls on; a one-time item has none.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { scheduleState } from '../../src/utils/scheduleStatus.js';
import { billRowState, billRowDateText } from '../../src/utils/billDates.js';
import { incomeRowState } from '../../src/utils/incomeStatus.js';

const settings = { date_format: 'Y-m-d' };
const custom = (months, fields) => ({
    frequency: 'custom', isActive: true, dueDay: 28,
    customRecurrencePattern: JSON.stringify({ months }), ...fields,
});

describe('a Custom row', () => {
    it('stays paid after its one month until next year comes close', () => {
        // EKZ, Garage, UBS Card: paid 27 September, due again in 2027
        const row = billRowState(custom([9], { nextDueDate: '2027-09-28', lastPaidDate: '2026-09-27' }), '2026-10-29', settings);

        expect(row.status).toBe('paid');
        expect(row.canPay).toBe(false);
        expect(row.dateText).toBe('Paid 2026-09-27, next due 2027-09-28');
    });

    it('turns due again as its date comes close', () => {
        const row = billRowState(custom([9], { nextDueDate: '2027-09-28', lastPaidDate: '2026-09-27' }), '2027-09-24', settings);

        expect(row.status).toBe('due-soon');
        expect(row.canPay).toBe(true);
    });

    it('with two months stays paid for half a year, like a half-yearly bill', () => {
        const row = billRowState(custom([3, 9], { nextDueDate: '2027-03-28', lastPaidDate: '2026-09-27' }), '2027-02-20', settings);

        expect(row.status).toBe('paid');
        expect(row.canPay).toBe(false);
    });

    it('with two months is not held as paid for a whole year', () => {
        // Paid in March, September skipped: nothing settled since spring
        const row = billRowState(custom([3, 9], { nextDueDate: '2027-03-28', lastPaidDate: '2026-03-27' }), '2026-10-20', settings);

        expect(row.status).toBe('upcoming');
        expect(row.canPay).toBe(true);
    });

    it('reads a pattern already parsed', () => {
        const row = billRowState(
            { ...custom([9], { nextDueDate: '2027-09-28', lastPaidDate: '2026-09-27' }), customRecurrencePattern: { months: [9] } },
            '2026-10-29', settings
        );

        expect(row.status).toBe('paid');
    });

    it('reads a snake_case row', () => {
        const row = billRowState({
            frequency: 'custom', is_active: true, next_due_date: '2027-09-28', last_paid_date: '2026-09-27',
            custom_recurrence_pattern: '{"months":[9]}',
        }, '2026-10-29', settings);

        expect(row.status).toBe('paid');
    });

    it('with no usable pattern runs monthly, as the server schedules it', () => {
        for (const pattern of [null, '', 'not json', '{}', '{"months":[]}']) {
            const row = billRowState(
                { ...custom([9], { nextDueDate: '2027-09-28', lastPaidDate: '2026-09-27' }), customRecurrencePattern: pattern },
                '2026-10-29', settings
            );
            expect(row.status, String(pattern)).toBe('upcoming');
        }
    });
});

describe('scheduleState', () => {
    it('shares a year out among the dates a Custom pattern falls on', () => {
        const state = (pattern, lastDate, today) => scheduleState(
            { frequency: 'custom', isActive: true, dueDate: '2027-09-28', lastDate, pattern }, today
        );

        // One month: a year
        expect(state('{"months":[9]}', '2026-09-27', '2027-09-20').recentlySettled).toBe(true);
        // Four months: 92 days
        expect(state('{"months":[3,6,9,12]}', '2026-09-27', '2026-12-27').recentlySettled).toBe(true);
        expect(state('{"months":[3,6,9,12]}', '2026-09-27', '2026-12-29').recentlySettled).toBe(false);
        // A month listed twice falls once
        expect(state('{"months":[9,9]}', '2026-09-27', '2027-09-20').recentlySettled).toBe(true);
        // Dates rather than months
        expect(state({ dates: [{ month: 3, day: 1 }, { month: 9, day: 15 }] }, '2026-09-27', '2027-03-20').recentlySettled).toBe(true);
        expect(state({ dates: [{ month: 3, day: 1 }, { month: 9, day: 15 }] }, '2026-09-27', '2027-03-30').recentlySettled).toBe(false);
    });

    it('gives a one-time item no cycle: a payment beside it never settles its date', () => {
        const state = scheduleState(
            { frequency: 'one-time', isActive: true, dueDate: '2026-09-28', lastDate: '2026-09-27' }, '2026-10-15'
        );

        expect(state.status).toBe('overdue');
        expect(state.recentlySettled).toBe(false);
    });
});

describe('a one-time row', () => {
    it('reopened on the date it paid shows the date alone beside Overdue', () => {
        // The CembraPay bills after the switch to One-Time 28 September
        const row = billRowState(
            { frequency: 'one-time', isActive: true, nextDueDate: '2026-09-28', startDate: '2026-09-28', lastPaidDate: '2026-09-27' },
            '2026-10-15', settings
        );

        expect(row.status).toBe('overdue');
        expect(row.dateText).toBe('2026-09-28');
    });

    it('still to come shows its date alone, not as paid', () => {
        const row = billRowState(
            { frequency: 'one-time', isActive: true, nextDueDate: '2026-11-20', startDate: '2026-11-20', lastPaidDate: '2026-10-19' },
            '2026-10-25', settings
        );

        expect(row.status).toBe('upcoming');
        expect(row.canPay).toBe(true);
        expect(row.dateText).toBe('2026-11-20');
    });

    it('once paid says when, never what is next', () => {
        const row = billRowState(
            { frequency: 'one-time', isActive: false, nextDueDate: null, startDate: '2026-09-28', lastPaidDate: '2026-09-27' },
            '2026-10-15', settings
        );

        expect(row.status).toBe('paid');
        expect(row.canPay).toBe(false);
        expect(row.dateText).toBe('Paid 2026-09-27');
    });

    it('never reads "next due", whatever it is told', () => {
        expect(billRowDateText(
            { frequency: 'one-time', isActive: true, lastPaidDate: '2026-09-27' }, '2026-09-28', true, settings
        )).toBe('2026-09-28');
    });

    it('of income still expected shows its date alone', () => {
        const row = incomeRowState(
            { frequency: 'one-time', isActive: true, nextExpectedDate: '2026-10-20', startDate: '2026-10-20', lastReceivedDate: '2026-10-19' },
            '2026-10-25', settings
        );

        expect(row.status).toBe('overdue');
        expect(row.canReceive).toBe(true);
        expect(row.dateText).toBe('2026-10-20');
    });
});
