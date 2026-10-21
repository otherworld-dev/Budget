/**
 * A skipped occurrence is not a payment (#399). "Settled" means "the last
 * payment was within the past cycle", so skipping the next date of a bill
 * paid a month ago gave "Paid 24 Sep, next due 24 Nov" with a green Paid
 * badge and no buttons, while October had been skipped, not paid.
 *
 * Dates alone can't tell that from a bill paid early: a monthly bill due on
 * the 28th and paid on the 5th looks just the same, and read as not settled
 * Mark Paid paid it a second time with no question asked. So the dates keep
 * their old rule, and the server says when the occurrence before the next
 * one was skipped (lastOccurrenceSkipped); only then is the row not settled.
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
            { frequency: 'monthly', isActive: true, dueDay: 24, lastPaidDate: '2026-09-24', nextDueDate: '2026-11-24', lastOccurrenceSkipped: true },
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
            { frequency: 'monthly', isActive: true, lastPaidDate: '2026-09-26', nextDueDate: '2026-11-26', destinationAccountId: 2, lastOccurrenceSkipped: true },
            TODAY, settings
        );

        expect(row.status).toBe('upcoming');
        expect(row.canPay).toBe(true);
    });

    it('is upcoming on Income, not received', () => {
        // Gutschrift SBB Abo Leon: received 28 Sep, the 29 Oct skipped
        const row = incomeRowState(
            { frequency: 'monthly', isActive: true, lastReceivedDate: '2026-09-28', nextExpectedDate: '2026-11-29', lastOccurrenceSkipped: true },
            TODAY, settings
        );

        expect(row.status).toBe('upcoming');
        expect(row.statusText).toBe('Upcoming');
        expect(row.dateText).toBe('2026-11-29');
        expect(row.canReceive).toBe(true);
    });

    it('is still due soon or overdue as its date comes', () => {
        const flagged = { frequency: 'monthly', isActive: true, lastDate: '2026-09-24', skipped: true };

        expect(scheduleState({ ...flagged, dueDate: '2026-10-24' }, TODAY).status).toBe('soon');
        expect(scheduleState({ ...flagged, dueDate: '2026-10-18' }, TODAY).status).toBe('overdue');
    });

    it('reads as settled without the flag, as before', () => {
        const row = billRowState(
            { frequency: 'monthly', isActive: true, dueDay: 24, lastPaidDate: '2026-09-24', nextDueDate: '2026-11-24' },
            TODAY, settings
        );

        expect(row.status).toBe('paid');
    });
});

describe('a row paid early', () => {
    // The reviewer's cases: each must stay settled, with no Mark Paid
    const EARLY = [
        ['monthly, due the 28th, paid on the 5th', { frequency: 'monthly', dueDay: 28, lastPaidDate: '2026-10-05', nextDueDate: '2026-11-28' }, '2026-10-05'],
        ['monthly, paid 18 days early', { frequency: 'monthly', dueDay: 20, lastPaidDate: '2026-10-02', nextDueDate: '2026-11-20' }, '2026-10-03'],
        ['weekly, paid 5 days early', { frequency: 'weekly', lastPaidDate: '2026-10-19', nextDueDate: '2026-10-31' }, '2026-10-19'],
        ['bi-weekly, paid 10 days early', { frequency: 'biweekly', lastPaidDate: '2026-10-14', nextDueDate: '2026-11-07' }, '2026-10-14'],
        ['quarterly, paid 50 days early', { frequency: 'quarterly', dueDay: 30, lastPaidDate: '2026-10-11', nextDueDate: '2027-02-28' }, '2026-10-11'],
        ['semi-monthly, paid 8 days early', { frequency: 'semi-monthly', dueDay: 1, lastPaidDate: '2026-10-08', nextDueDate: '2026-11-01' }, '2026-10-08'],
    ];

    it.each(EARLY)('stays paid on Bills and Transfers: %s', (_name, fields, today) => {
        for (const extra of [{}, { isTransfer: true, destinationAccountId: 2 }]) {
            const row = billRowState({ isActive: true, canMarkUnpaid: true, ...fields, ...extra }, today, settings);

            expect(row.status).toBe('paid');
            expect(row.canPay).toBe(false);
            expect(row.canSkip).toBe(false);
            expect(row.canUnpay).toBe(true);
        }
    });

    it.each(EARLY.filter(([name]) => !name.startsWith('semi')))('stays received on Income: %s', (_name, fields, today) => {
        const row = incomeRowState({
            frequency: fields.frequency, isActive: true,
            lastReceivedDate: fields.lastPaidDate, nextExpectedDate: fields.nextDueDate,
        }, today, settings);

        expect(row.status).toBe('received');
        expect(row.canReceive).toBe(false);
    });

    it('leaves a bill paid on time as it was', () => {
        const row = billRowState({ frequency: 'monthly', isActive: true, dueDay: 5, lastPaidDate: '2026-10-05', nextDueDate: '2026-11-05' }, '2026-10-06', settings);

        expect(row.status).toBe('paid');
        expect(row.dateText).toBe('Paid 2026-10-05, next due 2026-11-05');
        expect(row.canPay).toBe(false);
    });
});
