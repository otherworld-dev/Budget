/**
 * Durable "mark as unpaid" (#365). Marking a bill paid mutates six fields and
 * creates up to three transactions, but the only revert was a ~10-second undo
 * toast whose state lived in browser memory — gone on reload, and never shown
 * at all for auto-paid or import-matched bills. The server now persists the
 * undo snapshot and serializes a `canMarkUnpaid` hint; the bills list offers
 * a Mark Unpaid action on any bill carrying one. Deactivated one-time bills
 * drop off the active list when paid (#333), so the list keeps inactive bills
 * that still hold a snapshot — otherwise the action could never be reached.
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
}));

import BillsModule from '../../src/modules/bills/BillsModule.js';
import { showSuccess, showError } from '../../src/utils/notifications.js';

const bill = (overrides = {}) => ({
    id: 1,
    name: 'Rent',
    amount: 100,
    frequency: 'monthly',
    nextDueDate: '2099-06-15',
    isActive: true,
    canMarkUnpaid: false,
    ...overrides,
});

function makeModule(bills = []) {
    const mod = Object.create(BillsModule.prototype);
    mod.app = { settings: {}, bills };
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="bills-list"></div>
        <div id="empty-bills"></div>
    `;
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    global.confirm = vi.fn(() => true);
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    delete global.confirm;
    vi.clearAllMocks();
});

describe('Mark Unpaid action', () => {
    it('appears only on bills that carry a stored payment snapshot', () => {
        const mod = makeModule();

        mod.renderBills([
            bill({ id: 1, name: 'Rent', canMarkUnpaid: true }),
            bill({ id: 2, name: 'Water' }),
        ]);

        const buttons = document.querySelectorAll('.bill-unpaid-btn');
        expect(buttons).toHaveLength(1);
        expect(buttons[0].dataset.billId).toBe('1');
    });

    it('is offered on a deactivated one-time bill (paid, so off the active list)', () => {
        const mod = makeModule();

        mod.renderBills([
            bill({ id: 3, name: 'Deposit', frequency: 'one-time', isActive: false, nextDueDate: null, canMarkUnpaid: true }),
        ]);

        expect(document.querySelectorAll('.bill-unpaid-btn')).toHaveLength(1);
        // Rendered as paid — the Mark Paid button is not offered alongside
        expect(document.querySelectorAll('.bill-paid-btn')).toHaveLength(0);
    });

    it('never renders actionable Mark Paid or Skip on an inactive bill', () => {
        // An inactive recurring bill (remaining payments exhausted months ago)
        // stays in the list only to be reverted — markPaid would still execute
        // on it, so the buttons must not be offered.
        const mod = makeModule();

        mod.renderBills([
            bill({ id: 4, name: 'Old loan', isActive: false, nextDueDate: null, lastPaidDate: '2001-01-15', canMarkUnpaid: true }),
        ]);

        expect(document.querySelectorAll('.bill-paid-btn')).toHaveLength(0);
        expect(document.querySelectorAll('.bill-skip-btn')).toHaveLength(0);
        expect(document.querySelectorAll('.bill-unpaid-btn')).toHaveLength(1);
        // Classified as paid: kept off the Due Soon / Overdue tabs
        expect(document.querySelector('.bill-card').dataset.status).toBe('paid');
    });
});

describe('loadBillsView keeps revertible inactive bills', () => {
    it('keeps active bills plus inactive ones with a snapshot, drops the rest', async () => {
        const mod = makeModule();
        mod.loadBillsSummary = vi.fn();
        mod.populateBillModalDropdowns = vi.fn();
        mod.loadBillSuggestions = vi.fn();
        mod.loadUnrecordedPayments = vi.fn();
        mod._eventsSetup = true;

        global.fetch = vi.fn(async () => ({
            ok: true,
            json: async () => [
                bill({ id: 1, name: 'Rent' }),
                bill({ id: 2, name: 'Old deposit', frequency: 'one-time', isActive: false, nextDueDate: null, canMarkUnpaid: true }),
                bill({ id: 3, name: 'Cancelled gym', isActive: false }),
            ],
        }));

        await mod.loadBillsView();

        expect(mod.bills.map((b) => b.id)).toEqual([1, 2]);
        const html = document.getElementById('bills-list').innerHTML;
        expect(html).toContain('Old deposit');
        expect(html).not.toContain('Cancelled gym');
    });
});

describe('markBillUnpaid', () => {
    it('POSTs to the unpaid endpoint, refreshes and confirms', async () => {
        const mod = makeModule();
        mod.loadBillsView = vi.fn();
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({}) }));

        await mod.markBillUnpaid(5);

        expect(global.fetch).toHaveBeenCalledWith(
            '/apps/budget/api/bills/5/unpaid',
            expect.objectContaining({ method: 'POST' }),
        );
        expect(mod.loadBillsView).toHaveBeenCalled();
        expect(showSuccess).toHaveBeenCalled();
    });

    it('tells the truth about linked transactions in the confirmation', async () => {
        // Link-existing payments delete nothing — the linked imported
        // transaction is unlinked, and the confirm copy must say so.
        const mod = makeModule([bill({ id: 5, canMarkUnpaid: true })]);
        mod.loadBillsView = vi.fn();
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({}) }));

        await mod.markBillUnpaid(5);

        expect(global.confirm).toHaveBeenCalledWith(expect.stringContaining('unlinked'));
        expect(global.confirm).toHaveBeenCalledWith(expect.stringContaining('deleted'));
    });

    it('warns that auto-pay may pay the bill again', async () => {
        const mod = makeModule([bill({ id: 5, canMarkUnpaid: true, autoPayEnabled: true })]);
        mod.loadBillsView = vi.fn();
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({}) }));

        await mod.markBillUnpaid(5);

        expect(global.confirm).toHaveBeenCalledWith(expect.stringContaining('Auto-pay is on for this bill'));
    });

    it('does not warn about auto-pay when it is off', async () => {
        const mod = makeModule([bill({ id: 5, canMarkUnpaid: true, autoPayEnabled: false })]);
        mod.loadBillsView = vi.fn();
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({}) }));

        await mod.markBillUnpaid(5);

        expect(global.confirm).not.toHaveBeenCalledWith(expect.stringContaining('Auto-pay is on for this bill'));
    });

    it('does nothing when the confirmation is declined', async () => {
        const mod = makeModule();
        mod.loadBillsView = vi.fn();
        global.confirm = vi.fn(() => false);
        global.fetch = vi.fn();

        await mod.markBillUnpaid(5);

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('surfaces the server message on failure', async () => {
        const mod = makeModule();
        mod.loadBillsView = vi.fn();
        global.fetch = vi.fn(async () => ({
            ok: false,
            status: 400,
            json: async () => ({ error: 'This bill has no recorded payment to undo' }),
        }));

        await mod.markBillUnpaid(5);

        expect(showError).toHaveBeenCalledWith(
            expect.stringContaining('This bill has no recorded payment to undo'),
        );
        expect(mod.loadBillsView).not.toHaveBeenCalled();
    });
});
