/**
 * A skipped occurrence is not a payment (#399). "Settled" meant "the last
 * payment was within the past cycle", so skipping the next date of a bill
 * paid a month ago gave "Paid 24 Sep, next due 24 Nov" with a green Paid
 * badge and no buttons, while October had been skipped, not paid. A row is
 * settled only when its last payment paid the occurrence just before its
 * next due date.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { scheduleState } from '../../src/utils/scheduleStatus.js';
import { billRowState } from '../../src/utils/billDates.js';
import { incomeRowState } from '../../src/utils/incomeStatus.js';

const settings = { date_format: 'Y-m-d' };
const TODAY = '2026-10-20';

describe('a row whose occurrence before the next was skipped', () => {
    it('is upcoming on Bills, with nothing claiming it was paid', () => {
        // Claude by Anthropic: paid 24 Sep, October skipped
        const row = billRowState(
            { frequency: 'monthly', isActive: true, dueDay: 24, lastPaidDate: '2026-09-24', nextDueDate: '2026-11-24' },
            TODAY, settings
        );

        expect(row.status).toBe('upcoming');
        expect(row.statusText).toBe('Upcoming');
        expect(row.dateText).toBe('2026-11-24');
        expect(row.canPay).toBe(true);
        expect(row.canSkip).toBe(true);
    });

    it('is upcoming on Transfers too', () => {
        const row = billRowState(
            { frequency: 'monthly', isActive: true, lastPaidDate: '2026-09-26', nextDueDate: '2026-11-26', destinationAccountId: 2 },
            TODAY, settings
        );

        expect(row.status).toBe('upcoming');
        expect(row.canPay).toBe(true);
    });

    it('is upcoming on Income, not received', () => {
        // Gutschrift SBB Abo Leon: received 28 Sep, the 29 Oct skipped
        const row = incomeRowState(
            { frequency: 'monthly', isActive: true, lastReceivedDate: '2026-09-28', nextExpectedDate: '2026-11-29' },
            TODAY, settings
        );

        expect(row.status).toBe('upcoming');
        expect(row.statusText).toBe('Upcoming');
        expect(row.dateText).toBe('2026-11-29');
        expect(row.canReceive).toBe(true);
    });

    it('works for weekly and yearly schedules', () => {
        expect(scheduleState({ frequency: 'weekly', isActive: true, lastDate: '2026-10-07', dueDate: '2026-10-28' }, TODAY).status)
            .toBe('upcoming');
        expect(scheduleState({ frequency: 'yearly', isActive: true, lastDate: '2025-11-30', dueDate: '2027-11-30' }, TODAY).status)
            .toBe('upcoming');
    });
});

describe('a row whose last payment paid the occurrence before the next', () => {
    it('is still settled when paid on the day', () => {
        const state = scheduleState({ frequency: 'monthly', isActive: true, lastDate: '2026-10-05', dueDate: '2026-11-05' }, TODAY);

        expect(state).toEqual({ status: 'settled', recentlySettled: true });
    });

    it('is still settled when paid late', () => {
        // Due 5 October, paid on the 12th
        const state = scheduleState({ frequency: 'monthly', isActive: true, lastDate: '2026-10-12', dueDate: '2026-11-05' }, TODAY);

        expect(state.status).toBe('settled');
    });

    it('is still settled when paid early, within half a cycle', () => {
        // Due 15 October, paid on 2 October; a yearly bill a month early
        expect(scheduleState({ frequency: 'monthly', isActive: true, lastDate: '2026-10-02', dueDate: '2026-11-15' }, '2026-10-08').status)
            .toBe('settled');
        expect(scheduleState({ frequency: 'yearly', isActive: true, lastDate: '2026-09-01', dueDate: '2027-10-01' }, TODAY).status)
            .toBe('settled');
    });

    it('reads a month that has fewer days than the due day', () => {
        // Due on the 31st: February's occurrence is the 28th
        const state = scheduleState(
            { frequency: 'monthly', isActive: true, dueDay: 31, lastDate: '2027-02-28', dueDate: '2027-03-31' },
            '2027-03-10'
        );

        expect(state.status).toBe('settled');
    });

    it('handles a semi-monthly schedule', () => {
        // On the 1st and the 16th: paid on the 16th, next the 1st
        expect(scheduleState({ frequency: 'semi-monthly', isActive: true, dueDay: 1, lastDate: '2026-10-16', dueDate: '2026-11-01' }, '2026-10-17').status)
            .toBe('settled');
        // Paid on the 1st, the 16th skipped
        expect(scheduleState({ frequency: 'semi-monthly', isActive: true, dueDay: 1, lastDate: '2026-10-01', dueDate: '2026-11-01' }, '2026-10-17').status)
            .toBe('upcoming');
    });
});
