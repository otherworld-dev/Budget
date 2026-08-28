/**
 * Mark Unpaid on the transfers list (#365 review).
 *
 * Transfer bills — including the #347 statement-amount card payments the
 * snapshot restore exists for — get canMarkUnpaid from the server, but only
 * BillsModule rendered the button. The transfers list now offers the same
 * action with the same confirm/warning copy, and never renders an actionable
 * Mark Paid on an inactive transfer.
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

import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import { showSuccess, showError } from '../../src/utils/notifications.js';

const transfer = (overrides = {}) => ({
    id: 1,
    name: 'Card payment',
    amount: 250,
    frequency: 'monthly',
    nextDueDate: '2099-06-15',
    isActive: true,
    isTransfer: true,
    canMarkUnpaid: false,
    ...overrides,
});

function makeModule(transfers = []) {
    const mod = Object.create(TransfersModule.prototype);
    mod.app = { settings: {} };
    mod.transfers = transfers;
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="transfers-list"></div>
        <div id="empty-transfers"></div>
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

describe('transfers list Mark Unpaid action', () => {
    it('appears only on transfers that carry a stored payment snapshot', () => {
        const mod = makeModule([
            transfer({ id: 1, name: 'Card payment', canMarkUnpaid: true }),
            transfer({ id: 2, name: 'Savings sweep' }),
        ]);

        mod.renderTransfers();

        const buttons = document.querySelectorAll('.transfer-unpaid-btn');
        expect(buttons).toHaveLength(1);
        expect(buttons[0].dataset.transferId).toBe('1');
    });

    it('never renders an actionable Mark Paid on an inactive transfer', () => {
        const mod = makeModule([
            transfer({ id: 3, name: 'Paid-off loan', isActive: false, nextDueDate: null, lastPaidDate: '2001-01-15', canMarkUnpaid: true }),
        ]);

        mod.renderTransfers();

        expect(document.querySelectorAll('.transfer-paid-btn')).toHaveLength(0);
        expect(document.querySelectorAll('.transfer-unpaid-btn')).toHaveLength(1);
    });
});

describe('markTransferUnpaid', () => {
    it('POSTs to the unpaid endpoint and refreshes the transfers view', async () => {
        const mod = makeModule([transfer({ id: 5, canMarkUnpaid: true })]);
        mod.loadTransfers = vi.fn(async () => {});
        mod.renderTransfers = vi.fn();
        mod.updateSummary = vi.fn();
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({}) }));

        await mod.markTransferUnpaid(5);

        expect(global.fetch).toHaveBeenCalledWith(
            '/apps/budget/api/bills/5/unpaid',
            expect.objectContaining({ method: 'POST' }),
        );
        expect(mod.loadTransfers).toHaveBeenCalled();
        expect(mod.renderTransfers).toHaveBeenCalled();
        expect(mod.updateSummary).toHaveBeenCalled();
        expect(showSuccess).toHaveBeenCalled();
    });

    it('uses the same confirm copy as the bills list, with the auto-pay warning', async () => {
        const mod = makeModule([transfer({ id: 5, canMarkUnpaid: true, autoPayEnabled: true })]);
        mod.loadTransfers = vi.fn(async () => {});
        mod.renderTransfers = vi.fn();
        mod.updateSummary = vi.fn();
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({}) }));

        await mod.markTransferUnpaid(5);

        expect(global.confirm).toHaveBeenCalledWith(expect.stringContaining('unlinked'));
        expect(global.confirm).toHaveBeenCalledWith(expect.stringContaining('Auto-pay is on for this bill'));
    });

    it('does nothing when the confirmation is declined', async () => {
        const mod = makeModule([transfer({ id: 5, canMarkUnpaid: true })]);
        global.confirm = vi.fn(() => false);
        global.fetch = vi.fn();

        await mod.markTransferUnpaid(5);

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('surfaces the server message on failure', async () => {
        const mod = makeModule([transfer({ id: 5, canMarkUnpaid: true })]);
        mod.loadTransfers = vi.fn(async () => {});
        mod.renderTransfers = vi.fn();
        mod.updateSummary = vi.fn();
        global.fetch = vi.fn(async () => ({
            ok: false,
            status: 400,
            json: async () => ({ error: 'This bill has no recorded payment to undo' }),
        }));

        await mod.markTransferUnpaid(5);

        expect(showError).toHaveBeenCalledWith(
            expect.stringContaining('This bill has no recorded payment to undo'),
        );
        expect(mod.loadTransfers).not.toHaveBeenCalled();
    });
});
