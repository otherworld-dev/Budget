/**
 * Custom and One-Time rows (#399). Neither had a cycle, so both fell back to
 * a month: a Custom bill due once a year turned Upcoming with Mark Paid 31
 * days after it was paid (one click paid next year's), and a one-time bill
 * an edit had put back on the date it paid read "Paid 27 Sep, next due
 * 28 Sep" beside its Overdue badge. A Custom bill's last payment is now
 * judged by the dates its pattern falls on; a one-time item has no cycle.
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

    it('paid early for its one month stays paid', () => {
        // Paid on 1 September for the 28th
        const row = billRowState(custom([9], { nextDueDate: '2027-09-28', lastPaidDate: '2026-09-01' }), '2027-01-10', settings);

        expect(row.status).toBe('paid');
        expect(row.canPay).toBe(false);
    });

    it('with an uneven pattern stays paid through its long gap', () => {
        // January and February: after February's payment nothing is due
        // until next January, and a year shared out evenly offered Mark Paid
        // for it from August
        const row = billRowState(custom([1, 2], { nextDueDate: '2027-01-28', lastPaidDate: '2026-02-27' }), '2026-09-15', settings);

        expect(row.status).toBe('paid');
        expect(row.canPay).toBe(false);
        expect(row.dateText).toBe('Paid 2026-02-27, next due 2027-01-28');
    });

    it('with the reporter\'s instalments stays paid from December to July', () => {
        // Staats und Gemeindesteuer: July, August, October, November, December
        const months = [7, 8, 10, 11, 12];
        const afterDecember = billRowState(custom(months, { nextDueDate: '2027-07-28', lastPaidDate: '2026-12-27' }), '2027-03-15', settings);
        expect(afterDecember.status).toBe('paid');
        expect(afterDecember.canPay).toBe(false);

        // August paid, October not yet due, then October overdue
        const afterAugust = custom(months, { nextDueDate: '2026-10-28', lastPaidDate: '2026-08-30' });
        expect(billRowState(afterAugust, '2026-10-19', settings).status).toBe('paid');
        expect(billRowState(afterAugust, '2026-10-24', settings).status).toBe('due-soon');
        expect(billRowState(afterAugust, '2026-11-05', settings).status).toBe('overdue');
    });

    it('with a day past a month\'s end reads its months\' last days', () => {
        const row = billRowState(
            custom([2, 3], { dueDay: 31, nextDueDate: '2027-02-28', lastPaidDate: '2026-03-30' }),
            '2026-12-01', settings
        );

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
    it('judges a Custom payment by the dates its pattern falls on', () => {
        const state = (pattern, dueDate, lastDate, today) => scheduleState(
            { frequency: 'custom', isActive: true, dueDate, lastDate, pattern, dueDay: 28 }, today
        );
        const quarters = '{"months":[3,6,9,12]}';

        // Paid on or after the occurrence before the next one
        expect(state(quarters, '2026-12-28', '2026-09-27', '2026-11-15').recentlySettled).toBe(true);
        // ...or nearer it than the one before that: September paid in August
        expect(state(quarters, '2026-12-28', '2026-08-15', '2026-11-15').recentlySettled).toBe(true);
        // A payment for June settles nothing since: September was skipped
        expect(state(quarters, '2026-12-28', '2026-06-27', '2026-11-15').recentlySettled).toBe(false);
        // A month listed twice falls once, and one stored as text is read
        expect(state('{"months":[9,9]}', '2027-09-28', '2026-09-27', '2027-09-20').recentlySettled).toBe(true);
        expect(state('{"months":["9"]}', '2027-09-28', '2026-09-27', '2027-09-20').recentlySettled).toBe(true);
        // Dates rather than months
        const dates = { dates: [{ month: 3, day: 1 }, { month: 9, day: 15 }] };
        expect(state(dates, '2027-03-01', '2026-09-14', '2027-01-20').recentlySettled).toBe(true);
        expect(state(dates, '2027-03-01', '2026-03-01', '2026-10-20').recentlySettled).toBe(false);
    });

    it('reads a Custom pattern\'s day off its next due date when not told it', () => {
        const state = scheduleState(
            { frequency: 'custom', isActive: true, dueDate: '2027-09-28', lastDate: '2026-09-27', pattern: '{"months":[9]}' },
            '2027-03-01'
        );

        expect(state.recentlySettled).toBe(true);
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
