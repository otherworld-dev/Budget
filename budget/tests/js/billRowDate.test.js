/**
 * Paid rows show when the payment was recorded (#399). Paying a recurring
 * bill moves next_due_date on to the next occurrence straight away, so the
 * Paid tab listed September's payments beside October's dates, and a user
 * who had marked one paid by mistake couldn't tell which it was.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
    showUndoNotification: vi.fn(),
}));

import { billRowDateText } from '../../src/utils/billDates.js';
import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';

const settings = { date_format: 'Y-m-d' };

describe('billRowDateText', () => {
    it('shows the due date alone when nothing is paid', () => {
        expect(billRowDateText({ isActive: true }, '2026-10-05', false, settings)).toBe('2026-10-05');
    });

    it('leads a paid recurring row with the payment date, then the next due date', () => {
        const text = billRowDateText(
            { isActive: true, lastPaidDate: '2026-09-19' }, '2026-10-05', true, settings
        );
        expect(text).toBe('Paid 2026-09-19, next due 2026-10-05');
    });

    it('shows only the payment date on an inactive row', () => {
        const text = billRowDateText(
            { isActive: false, frequency: 'one-time', lastPaidDate: '2026-09-22' }, '2026-09-28', true, settings
        );
        expect(text).toBe('Paid 2026-09-22');
    });

    it('falls back to the due date on a paid row with no payment date', () => {
        expect(billRowDateText({ isActive: false }, '2026-07-27', true, settings)).toBe('2026-07-27');
    });

    it('says so when there is no date at all', () => {
        expect(billRowDateText({ isActive: true }, null, false, settings)).toBe('No due date');
    });
});

describe('list rows', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 8, 22, 12));
        document.body.innerHTML = `
            <div id="bills-list"></div><div id="empty-bills"></div>
            <div id="transfers-list"></div><div id="empty-transfers"></div>
        `;
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('a bill paid this month shows its payment date beside the Paid badge', () => {
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings, bills: [] };

        mod.renderBills([{
            id: 41, name: 'Tinder', amount: 65.95, frequency: 'monthly', isActive: true,
            nextDueDate: '2026-10-05', lastPaidDate: '2026-09-19',
        }]);

        const card = document.querySelector('.bill-card');
        expect(card.dataset.status).toBe('paid');
        expect(card.querySelector('.bill-due-date').textContent.trim())
            .toBe('Paid 2026-09-19, next due 2026-10-05');
    });

    it('a transfer paid this month does the same', () => {
        const mod = Object.create(TransfersModule.prototype);
        mod.app = { settings };
        mod.transfers = [{
            id: 47, name: 'Card', amount: 100, frequency: 'monthly', isActive: true,
            nextDueDate: '2026-10-29', lastPaidDate: '2026-09-21',
        }];

        mod.renderTransfers();

        const card = document.querySelector('.bill-card');
        expect(card.dataset.status).toBe('paid');
        expect(card.querySelector('.bill-due-date').textContent.trim())
            .toBe('Paid 2026-09-21, next due 2026-10-29');
    });
});
