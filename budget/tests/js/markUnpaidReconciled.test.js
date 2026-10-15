/**
 * Mark Unpaid deletes the payment it reverts. One already reconciled against
 * a bank statement went with no warning, so the account stopped matching the
 * statement. The server now refuses until the user agrees (409, code
 * "reconciled"); the bills and transfers lists ask, then send it again.
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

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(true)),
    promptDialog: vi.fn(() => Promise.resolve(null)),
    alertDialog: vi.fn(() => Promise.resolve()),
}));

import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import { showSuccess } from '../../src/utils/notifications.js';
import { confirmDialog } from '../../src/utils/dialogs.js';

const RECONCILED = 'This payment has been reconciled against a bank statement.';

function refusedThenOk() {
    return vi.fn()
        .mockResolvedValueOnce({ ok: false, status: 409, json: async () => ({ error: RECONCILED, code: 'reconciled' }) })
        .mockResolvedValue({ ok: true, status: 200, json: async () => ({}) });
}

function billsModule() {
    const mod = Object.create(BillsModule.prototype);
    mod.app = { settings: {}, bills: [] };
    mod.loadBillsView = vi.fn();
    return mod;
}

function transfersModule() {
    const mod = Object.create(TransfersModule.prototype);
    mod.app = { settings: {} };
    mod.transfers = [];
    mod.loadTransfers = vi.fn();
    mod.renderTransfers = vi.fn();
    mod.updateSummary = vi.fn();
    return mod;
}

beforeEach(() => {
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    confirmDialog.mockResolvedValue(true);
});

afterEach(() => {
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe.each([
    ['bills', () => billsModule(), (mod) => mod.markBillUnpaid(5)],
    ['transfers', () => transfersModule(), (mod) => mod.markTransferUnpaid(5)],
])('Mark Unpaid on the %s list', (_name, make, markUnpaid) => {
    it('asks again when the payment was reconciled, then goes ahead', async () => {
        const mod = make();
        global.fetch = refusedThenOk();

        await markUnpaid(mod);

        expect(confirmDialog).toHaveBeenCalledTimes(2);
        expect(confirmDialog.mock.calls[1][0]).toContain(RECONCILED);
        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(JSON.parse(global.fetch.mock.calls[1][1].body)).toEqual({ confirmReconciled: true });
        expect(showSuccess).toHaveBeenCalled();
    });

    it('stops when the user keeps the reconciled payment', async () => {
        const mod = make();
        global.fetch = refusedThenOk();
        confirmDialog.mockResolvedValueOnce(true).mockResolvedValueOnce(false);

        await markUnpaid(mod);

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(showSuccess).not.toHaveBeenCalled();
    });
});
