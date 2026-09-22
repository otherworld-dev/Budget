/**
 * Skip on the transfers list (#396).
 *
 * A scheduled transfer is a bill with is_transfer set, and the bill skip
 * endpoint already handles one - it drops both scheduled legs and pre-creates
 * the next pair - but the transfers list never offered the button, so the only
 * way past an occurrence was to mark it paid and then delete two transactions.
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

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(true)),
    promptDialog: vi.fn(() => Promise.resolve(null)),
    alertDialog: vi.fn(() => Promise.resolve()),
}));

import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import { showSuccess, showError, showUndoNotification } from '../../src/utils/notifications.js';
import { confirmDialog } from '../../src/utils/dialogs.js';

const transfer = (overrides = {}) => ({
    id: 1,
    name: 'Savings sweep',
    amount: 100,
    frequency: 'monthly',
    nextDueDate: '2099-06-15',
    isActive: true,
    isTransfer: true,
    canMarkUnpaid: false,
    ...overrides,
});

function today() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function makeModule(transfers = []) {
    const mod = Object.create(TransfersModule.prototype);
    mod.app = { settings: {} };
    mod.transfers = transfers;
    return mod;
}

function makeRefreshableModule(transfers) {
    const mod = makeModule(transfers);
    mod.loadTransfers = vi.fn(async () => {});
    mod.renderTransfers = vi.fn();
    mod.updateSummary = vi.fn();
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="transfers-list"></div>
        <div id="empty-transfers"></div>
    `;
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    confirmDialog.mockResolvedValue(true);
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('transfers list Skip action', () => {
    it('is offered on an active recurring transfer that is not yet paid', () => {
        const mod = makeModule([transfer({ id: 7 })]);

        mod.renderTransfers();

        const buttons = document.querySelectorAll('.transfer-skip-btn');
        expect(buttons).toHaveLength(1);
        expect(buttons[0].dataset.transferId).toBe('7');
    });

    it('is not offered on a one-time transfer', () => {
        const mod = makeModule([transfer({ frequency: 'one-time' })]);

        mod.renderTransfers();

        expect(document.querySelectorAll('.transfer-paid-btn')).toHaveLength(1);
        expect(document.querySelectorAll('.transfer-skip-btn')).toHaveLength(0);
    });

    it('is not offered on a transfer already paid this month', () => {
        const mod = makeModule([transfer({ lastPaidDate: today() })]);

        mod.renderTransfers();

        expect(document.querySelectorAll('.transfer-skip-btn')).toHaveLength(0);
    });

    it('is not offered on an inactive transfer', () => {
        const mod = makeModule([transfer({ isActive: false, nextDueDate: null })]);

        mod.renderTransfers();

        expect(document.querySelectorAll('.transfer-skip-btn')).toHaveLength(0);
    });
});

describe('skipTransfer', () => {
    it('confirms, POSTs to the skip endpoint and refreshes the list', async () => {
        const mod = makeRefreshableModule([transfer({ id: 5 })]);
        global.fetch = vi.fn(async () => ({
            ok: true,
            json: async () => ({ bill: { id: 5 }, previousNextDueDate: '2099-06-15' }),
        }));

        await mod.skipTransfer(5);

        expect(confirmDialog).toHaveBeenCalled();
        expect(global.fetch).toHaveBeenCalledWith(
            '/apps/budget/api/bills/5/skip',
            expect.objectContaining({ method: 'POST' }),
        );
        expect(mod.loadTransfers).toHaveBeenCalled();
        expect(mod.renderTransfers).toHaveBeenCalled();
        expect(mod.updateSummary).toHaveBeenCalled();
        expect(showError).not.toHaveBeenCalled();
    });

    it('offers an undo that puts the previous due date back', async () => {
        const mod = makeRefreshableModule([transfer({ id: 5 })]);
        global.fetch = vi.fn(async () => ({
            ok: true,
            json: async () => ({ bill: { id: 5 }, previousNextDueDate: '2099-06-15' }),
        }));

        await mod.skipTransfer(5);

        expect(showUndoNotification).toHaveBeenCalledTimes(1);
        const [message, undo] = showUndoNotification.mock.calls[0];
        expect(message).toContain('skipped');

        global.fetch.mockClear();
        await undo();

        expect(global.fetch).toHaveBeenCalledWith(
            '/apps/budget/api/bills/5/undo-skip',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ previousNextDueDate: '2099-06-15' }),
            }),
        );
        expect(mod.loadTransfers).toHaveBeenCalledTimes(2);
        expect(showSuccess).toHaveBeenCalled();
    });

    it('lets the undo expire so a stale one cannot fire later', async () => {
        const mod = makeRefreshableModule([transfer({ id: 5 })]);
        global.fetch = vi.fn(async () => ({
            ok: true,
            json: async () => ({ bill: { id: 5 }, previousNextDueDate: '2099-06-15' }),
        }));

        await mod.skipTransfer(5);
        const [, undo, onExpire] = showUndoNotification.mock.calls[0];
        onExpire();

        global.fetch.mockClear();
        await undo();

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('does nothing when the confirmation is declined', async () => {
        const mod = makeRefreshableModule([transfer({ id: 5 })]);
        confirmDialog.mockResolvedValue(false);
        global.fetch = vi.fn();

        await mod.skipTransfer(5);

        expect(global.fetch).not.toHaveBeenCalled();
        expect(mod.loadTransfers).not.toHaveBeenCalled();
    });

    it('reports a failure and leaves the list alone', async () => {
        const mod = makeRefreshableModule([transfer({ id: 5 })]);
        global.fetch = vi.fn(async () => ({
            ok: false,
            status: 400,
            json: async () => ({ error: 'Cannot skip an inactive bill' }),
        }));

        await mod.skipTransfer(5);

        expect(showError).toHaveBeenCalledWith(expect.stringContaining('Cannot skip an inactive bill'));
        expect(mod.loadTransfers).not.toHaveBeenCalled();
        expect(showUndoNotification).not.toHaveBeenCalled();
    });
});
