/**
 * What a bill or transfer row shows (#399). "Paid" meant "paid sometime
 * this calendar month": a weekly bill could be paid once a month, a bill
 * paid late in September for August read as paid for September's occurrence
 * too, and a transfer paid on the 1st read as last month's west of UTC. The
 * row now follows the bill's next occurrence.
 */

import { describe, it, expect, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import { billRowState } from '../../src/utils/billDates.js';

const settings = { date_format: 'Y-m-d' };
const TODAY = '2026-09-28';
const bill = (fields) => ({ frequency: 'monthly', isActive: true, ...fields });

describe('billRowState', () => {
    it('shows a bill paid for this cycle as paid, with when and what is next', () => {
        const row = billRowState(bill({ lastPaidDate: '2026-09-14', nextDueDate: '2026-10-15' }), TODAY, settings);

        expect(row.status).toBe('paid');
        expect(row.dateText).toBe('Paid 2026-09-14, next due 2026-10-15');
        expect(row.canPay).toBe(false);
    });

    it('lets a weekly bill be paid every week', () => {
        const row = billRowState(bill({ frequency: 'weekly', lastPaidDate: '2026-09-21', nextDueDate: '2026-09-28' }), TODAY, settings);

        expect(row.status).toBe('due-soon');
        expect(row.canPay).toBe(true);
        expect(row.canSkip).toBe(true);
    });

    it('keeps an overdue occurrence overdue after a payment for an earlier one', () => {
        // Paid this month for August: September was still owed
        const row = billRowState(bill({ lastPaidDate: '2026-09-05', nextDueDate: '2026-09-15' }), TODAY, settings);

        expect(row.status).toBe('overdue');
        expect(row.canPay).toBe(true);
        expect(row.dateText).toBe('Paid 2026-09-05, next due 2026-09-15');
    });

    it('shows a bill with nothing recent and nothing close as upcoming', () => {
        const row = billRowState(bill({ frequency: 'quarterly', nextDueDate: '2026-12-01' }), TODAY, settings);

        expect(row.status).toBe('upcoming');
        expect(row.dateText).toBe('2026-12-01');
    });

    it('keeps a yearly bill paid until its next date comes close', () => {
        const row = billRowState(bill({ frequency: 'yearly', lastPaidDate: '2025-12-01', nextDueDate: '2026-12-01' }), TODAY, settings);

        expect(row.status).toBe('paid');
        expect(row.dateText).toBe('Paid 2025-12-01, next due 2026-12-01');
    });

    it('shows an inactive bill as paid on its own date, with nothing to pay', () => {
        const row = billRowState(
            { frequency: 'one-time', isActive: false, nextDueDate: null, startDate: '2026-09-01', lastPaidDate: '2026-09-03' },
            TODAY, settings
        );

        expect(row.status).toBe('paid');
        expect(row.dateText).toBe('Paid 2026-09-03');
        expect(row.canPay).toBe(false);
        expect(row.canSkip).toBe(false);
    });

    it('never offers Skip on a one-time bill', () => {
        const row = billRowState({ frequency: 'one-time', isActive: true, nextDueDate: '2026-09-01', startDate: '2026-09-01' }, TODAY, settings);

        expect(row.status).toBe('overdue');
        expect(row.canPay).toBe(true);
        expect(row.canSkip).toBe(false);
    });
});
