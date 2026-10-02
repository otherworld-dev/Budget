/**
 * What a recurring income row shows (#399). The badge said Received for
 * anything received in this calendar month, beside the NEXT expected date,
 * so an income received early read "Received" next to a date still to
 * come, weekly pay could only be marked once a month, and a payment that
 * never arrived just sat there as "Upcoming". The row now follows the next
 * occurrence, and says when the last one arrived.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { incomeRowState } from '../../src/utils/incomeStatus.js';

const settings = { date_format: 'Y-m-d' };
const TODAY = '2026-09-28';
const monthly = (fields) => ({ frequency: 'monthly', isActive: true, ...fields });

describe('incomeRowState', () => {
    it('says when the last payment arrived and when the next is expected', () => {
        const row = incomeRowState(monthly({ lastReceivedDate: '2026-09-22', nextExpectedDate: '2026-10-22' }), TODAY, settings);

        expect(row.status).toBe('received');
        expect(row.dateText).toBe('Received 2026-09-22, next expected 2026-10-22');
        expect(row.canReceive).toBe(false);
    });

    it('puts the next payment first once it is close, still saying when the last arrived', () => {
        // The reporter's case: received on the 22nd, the next due on the 3rd
        const row = incomeRowState(monthly({ lastReceivedDate: '2026-09-22', nextExpectedDate: '2026-10-03' }), TODAY, settings);

        expect(row.status).toBe('expected-soon');
        expect(row.dateText).toBe('Received 2026-09-22, next expected 2026-10-03');
        expect(row.canReceive).toBe(true);
    });

    it('shows a payment that has not arrived as overdue', () => {
        const row = incomeRowState(monthly({ lastReceivedDate: '2026-08-03', nextExpectedDate: '2026-09-03' }), TODAY, settings);

        expect(row.status).toBe('overdue');
        expect(row.canReceive).toBe(true);
        expect(row.canSkip).toBe(true);
    });

    it('lets weekly pay be marked every week', () => {
        // Received this month used to hide Mark Received until the 1st
        const row = incomeRowState(
            { frequency: 'weekly', isActive: true, lastReceivedDate: '2026-09-25', nextExpectedDate: '2026-10-02' },
            TODAY, settings
        );

        expect(row.status).toBe('expected-soon');
        expect(row.canReceive).toBe(true);
    });

    it('treats a date due today as expected, not overdue', () => {
        expect(incomeRowState(monthly({ nextExpectedDate: TODAY }), TODAY, settings).status).toBe('expected-soon');
    });

    it('shows an income far off with no recent payment as upcoming', () => {
        const row = incomeRowState(monthly({ lastReceivedDate: '2026-06-03', nextExpectedDate: '2026-12-03', frequency: 'quarterly' }), TODAY, settings);

        expect(row.status).toBe('upcoming');
        expect(row.dateText).toBe('2026-12-03');
    });

    it('shows a received one-time income as completed with the date it arrived', () => {
        // It read "Completed" beside "No date set"
        const row = incomeRowState(
            { frequency: 'one-time', isActive: false, nextExpectedDate: null, startDate: '2026-09-03', lastReceivedDate: '2026-09-04' },
            TODAY, settings
        );

        expect(row.status).toBe('completed');
        expect(row.dateText).toBe('Received 2026-09-04');
        expect(row.canReceive).toBe(false);
        expect(row.canSkip).toBe(false);
    });

    it('falls back to a completed one-time income\'s own date', () => {
        const row = incomeRowState(
            { frequency: 'one-time', isActive: false, nextExpectedDate: null, startDate: '2026-09-03' },
            TODAY, settings
        );

        expect(row.dateText).toBe('2026-09-03');
    });

    it('never offers Skip on a one-time income', () => {
        const row = incomeRowState({ frequency: 'one-time', isActive: true, nextExpectedDate: '2026-09-03', startDate: '2026-09-03' }, TODAY, settings);

        expect(row.status).toBe('overdue');
        expect(row.canReceive).toBe(true);
        expect(row.canSkip).toBe(false);
    });

    it('reads snake_case rows too', () => {
        const row = incomeRowState({ frequency: 'monthly', is_active: true, last_received_date: '2026-09-22', next_expected_date: '2026-10-22' }, TODAY, settings);

        expect(row.status).toBe('received');
    });
});
