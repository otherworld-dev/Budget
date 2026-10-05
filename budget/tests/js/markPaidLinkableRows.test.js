/**
 * Mark Paid's "Existing Transaction Found" dialog lists only rows the
 * payment can be linked to.
 *
 * Linking writes the payment onto an existing row, and the server refuses
 * that for a row in an account shared with you read-only. The dialog still
 * offered such rows, so picking one failed. It now offers rows in accounts
 * you can write to, as every picker for new activity does.
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

vi.mock('../../src/utils/matchingDialog.js', () => ({
    showMatchingTransactionDialog: vi.fn(),
}));

import { linkableCandidates } from '../../src/utils/accounts.js';
import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import { showMatchingTransactionDialog } from '../../src/utils/matchingDialog.js';

const OWN = { id: 1, name: 'Current' };
const WRITE = { id: 2, name: 'Joint', _shared: true, _canWrite: true };
const READ = { id: 3, name: 'Parents', _shared: true, _canWrite: false };
const CLOSED = { id: 4, name: 'Old', closed: true };
const ACCOUNTS = [OWN, WRITE, READ, CLOSED];

const candidate = (id, accountId) => ({ transaction: { id, accountId, date: '2026-10-15', amount: 25 }, score: 80, matchReasons: [] });

describe('linkableCandidates', () => {
    it('keeps rows in accounts you can write to', () => {
        const kept = linkableCandidates([candidate(10, 1), candidate(20, 2), candidate(30, 3), candidate(40, 4)], ACCOUNTS);
        expect(kept.map(c => c.transaction.id)).toEqual([10, 20]);
    });

    it('leaves a row whose account it does not know to the server', () => {
        expect(linkableCandidates([candidate(50, 99)], ACCOUNTS)).toHaveLength(1);
    });

    it('copes with no answer', () => {
        expect(linkableCandidates(null, ACCOUNTS)).toEqual([]);
    });
});

describe('Mark Paid', () => {
    let requests;

    beforeEach(() => {
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        requests = [];
    });

    afterEach(() => {
        delete global.OC;
        delete global.fetch;
        vi.clearAllMocks();
    });

    function serve(candidates) {
        global.fetch = vi.fn(async (url, init = {}) => {
            requests.push({ url, body: init.body ? JSON.parse(init.body) : null });
            return {
                ok: true,
                status: 200,
                headers: { get: () => null },
                json: async () => (url.includes('matching-transactions') ? candidates : { bill: { id: 5 }, paymentTransactionRecorded: true }),
            };
        });
    }

    const paidBody = () => requests.find(r => r.url.endsWith('/paid'))?.body;

    it('on a bill does not offer rows in an account shared read-only', async () => {
        serve([candidate(30, READ.id)]);
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings: {}, accounts: ACCOUNTS, bills: [{ id: 5, name: 'Phone', amount: 25, frequency: 'monthly', nextDueDate: '2026-10-15', accountId: READ.id }] };
        mod.loadBillsView = vi.fn(async () => {});

        await mod.markBillPaid(5);

        expect(showMatchingTransactionDialog).not.toHaveBeenCalled();
        expect(paidBody()).toMatchObject({ recordPayment: true });
    });

    it('on a bill still offers rows in an account you can write to', async () => {
        serve([candidate(30, READ.id), candidate(20, WRITE.id)]);
        showMatchingTransactionDialog.mockResolvedValue(null);
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings: {}, accounts: ACCOUNTS, bills: [{ id: 5, name: 'Phone', amount: 25, frequency: 'monthly', nextDueDate: '2026-10-15', accountId: WRITE.id }] };
        mod.loadBillsView = vi.fn(async () => {});

        await mod.markBillPaid(5);

        const offered = showMatchingTransactionDialog.mock.calls[0][1];
        expect(offered.map(c => c.transaction.id)).toEqual([20]);
    });

    it('on a transfer does not offer rows in an account shared read-only', async () => {
        serve([candidate(30, READ.id)]);
        const mod = Object.create(TransfersModule.prototype);
        mod.app = { settings: {}, accounts: ACCOUNTS };
        mod.transfers = [{ id: 5, name: 'Sweep', amount: 25, frequency: 'monthly', nextDueDate: '2026-10-15', accountId: READ.id, isTransfer: true }];
        mod.loadTransfers = vi.fn(async () => {});
        mod.renderTransfers = vi.fn();
        mod.updateSummary = vi.fn();

        await mod.markTransferPaid(5);

        expect(showMatchingTransactionDialog).not.toHaveBeenCalled();
        expect(paidBody()).toMatchObject({ recordPayment: true });
    });
});
