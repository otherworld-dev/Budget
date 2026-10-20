/**
 * Unlink from bill on a bill's latest payment says why it can't (#399).
 *
 * The menu asked "Unlink this transaction from its bill?" and only after
 * Confirm did the server refuse: the latest payment is Mark Unpaid's to
 * undo. The list now says which rows those are (billLatestPayment), and the
 * reason comes instead of the question. The server's refusal stays behind
 * it, for a list loaded before the payment.
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
    confirmDialog: vi.fn(async () => true),
    alertDialog: vi.fn(async () => undefined),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import { alertDialog, confirmDialog } from '../../src/utils/dialogs.js';
import { showSuccess } from '../../src/utils/notifications.js';

const ROW = { id: 1786794, accountId: 3, type: 'debit', status: 'cleared', date: '2026-10-10', amount: 340, billId: 17046 };

describe('Unlink from bill', () => {
    let requests;
    let module;

    beforeEach(() => {
        globalThis.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        requests = [];
        globalThis.fetch = vi.fn(async (url, init = {}) => {
            requests.push({ url, method: init.method || 'GET' });
            return { ok: true, status: 200, headers: { get: () => null }, json: async () => ({ id: ROW.id, billId: null }) };
        });
        module = Object.create(TransactionsModule.prototype);
        module.app = { transactions: [], settings: {}, loadTransactions: vi.fn(async () => {}) };
    });

    afterEach(() => {
        delete globalThis.OC;
        delete globalThis.fetch;
        vi.clearAllMocks();
    });

    it('says why on the bill\'s latest payment, without asking or sending anything', async () => {
        module.app.transactions = [{ ...ROW, billLatestPayment: true }];

        await module.unlinkTransactionFromBill(ROW.id);

        expect(confirmDialog).not.toHaveBeenCalled();
        expect(alertDialog).toHaveBeenCalledWith(
            'This is the bill\'s latest payment. Use Mark Unpaid on the bill to undo it.',
            expect.objectContaining({ title: 'Unlink from bill' })
        );
        expect(requests).toEqual([]);
        expect(module.app.loadTransactions).not.toHaveBeenCalled();
    });

    it('still asks, and unlinks, on an earlier payment', async () => {
        module.app.transactions = [{ ...ROW, billLatestPayment: false }];

        await module.unlinkTransactionFromBill(ROW.id);

        expect(alertDialog).not.toHaveBeenCalled();
        expect(confirmDialog).toHaveBeenCalled();
        expect(requests).toEqual([{ url: `/apps/budget/api/transactions/${ROW.id}/bill`, method: 'DELETE' }]);
        expect(showSuccess).toHaveBeenCalled();
    });

    it('asks as before for a row the list doesn\'t hold', async () => {
        await module.unlinkTransactionFromBill(ROW.id);

        expect(alertDialog).not.toHaveBeenCalled();
        expect(confirmDialog).toHaveBeenCalled();
    });
});
