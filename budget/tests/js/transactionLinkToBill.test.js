/**
 * What Link to bill and Unlink from bill in a transaction's row menu send
 * (#399): the bills it offers, the request, and the server's reason when it
 * refuses (another bill's payment, the bill's latest payment).
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
}));

vi.mock('../../src/utils/billLinkDialog.js', async (importOriginal) => ({
    ...(await importOriginal()),
    chooseBill: vi.fn(),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import { chooseBill } from '../../src/utils/billLinkDialog.js';
import { confirmDialog } from '../../src/utils/dialogs.js';
import { showError, showSuccess } from '../../src/utils/notifications.js';

const BILLS = [
    { id: 47, name: 'CSX Pro Credit Cards', accountId: 3, isTransfer: true, destinationAccountId: 8, isActive: true, amount: 100 },
    { id: 120, name: 'Corner Card SA', accountId: 3, isActive: false, amount: 100 },
    { id: 30, name: 'Rent', accountId: 7, isActive: true, amount: 1500 },
];
const PAYMENT = { id: 1586, accountId: 3, type: 'debit', status: 'cleared', date: '2026-09-28', amount: 100, billId: null };

describe('Link to bill', () => {
    let requests;
    let module;
    let refusal;

    beforeEach(() => {
        globalThis.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        requests = [];
        refusal = null;
        globalThis.fetch = vi.fn(async (url, init = {}) => {
            requests.push({ url, method: init.method || 'GET', body: init.body ? JSON.parse(init.body) : null });
            if (refusal && init.method) {
                return { ok: false, status: 400, statusText: 'Bad Request', json: async () => ({ error: refusal }) };
            }
            return {
                ok: true,
                status: 200,
                headers: { get: () => null },
                json: async () => (url.endsWith('/api/bills') ? BILLS : { id: 1586, billId: 120 }),
            };
        });
        module = Object.create(TransactionsModule.prototype);
        module.app = { transactions: [PAYMENT], settings: { default_currency: 'CHF' }, loadTransactions: vi.fn(async () => {}) };
    });

    afterEach(() => {
        delete globalThis.OC;
        delete globalThis.fetch;
        vi.clearAllMocks();
    });

    it('offers the bills on the row\'s account and files it under the one picked', async () => {
        chooseBill.mockResolvedValue(120);

        await module.linkTransactionToBill(1586);

        expect(chooseBill).toHaveBeenCalledWith([BILLS[0], BILLS[1]], module.app.settings);
        expect(requests.at(-1)).toEqual({ url: '/apps/budget/api/transactions/1586/bill', method: 'POST', body: { billId: 120 } });
        expect(showSuccess).toHaveBeenCalledWith('Transaction linked to Corner Card SA');
        expect(module.app.loadTransactions).toHaveBeenCalled();
    });

    it('sends nothing when the dialog is cancelled', async () => {
        chooseBill.mockResolvedValue(null);

        await module.linkTransactionToBill(1586);

        expect(requests.map(r => r.method)).toEqual(['GET']);
        expect(module.app.loadTransactions).not.toHaveBeenCalled();
    });

    it('shows the server\'s reason when it refuses', async () => {
        chooseBill.mockResolvedValue(47);
        refusal = 'That transaction already pays another bill';

        await module.linkTransactionToBill(1586);

        expect(showError).toHaveBeenCalledWith('That transaction already pays another bill');
        expect(showSuccess).not.toHaveBeenCalled();
        expect(module.app.loadTransactions).not.toHaveBeenCalled();
    });

    it('takes a row out of its bill once confirmed', async () => {
        module.app.transactions = [{ ...PAYMENT, billId: 120 }];

        await module.unlinkTransactionFromBill(1586);

        expect(confirmDialog).toHaveBeenCalled();
        expect(requests).toEqual([{ url: '/apps/budget/api/transactions/1586/bill', method: 'DELETE', body: null }]);
        expect(showSuccess).toHaveBeenCalled();
        expect(module.app.loadTransactions).toHaveBeenCalled();
    });

    it('leaves the row alone when the confirmation is declined', async () => {
        confirmDialog.mockResolvedValueOnce(false);

        await module.unlinkTransactionFromBill(1586);

        expect(requests).toEqual([]);
    });

    it('points to Mark Unpaid when the row is the bill\'s latest payment', async () => {
        refusal = "This is the bill's latest payment. Use Mark Unpaid on the bill to undo it.";

        await module.unlinkTransactionFromBill(1586);

        expect(showError).toHaveBeenCalledWith(refusal);
        expect(module.app.loadTransactions).not.toHaveBeenCalled();
    });
});
