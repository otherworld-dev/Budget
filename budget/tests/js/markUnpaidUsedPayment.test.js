/**
 * Mark Unpaid's question after "Use that payment" (#399).
 *
 * The question says transactions created for the payment are deleted and a
 * linked imported transaction unlinked. A payment Mark Paid took from the
 * ledger ("Use that payment") is neither: Mark Unpaid leaves it in the
 * account, still filed under the bill. The bill now says so
 * (lastPaymentUsedExisting), and so does the question.
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
    confirmDialog: vi.fn(() => Promise.resolve(false)),
    promptDialog: vi.fn(() => Promise.resolve(null)),
    alertDialog: vi.fn(() => Promise.resolve()),
}));

import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import { confirmDialog } from '../../src/utils/dialogs.js';
import { markUnpaidQuestion } from '../../src/utils/billUnpaid.js';

const GENERAL = 'Mark this bill as unpaid? Transactions created for the payment will be deleted, a linked imported transaction will be unlinked, and the bill schedule rolled back.';
const USED = 'Mark this bill as unpaid? The bill schedule will be rolled back, and any transaction created for the payment deleted. The transaction the payment used was already in the account: it stays there, still filed under the bill.';

beforeEach(() => {
    globalThis.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    globalThis.fetch = vi.fn();
});

afterEach(() => {
    delete globalThis.OC;
    delete globalThis.fetch;
    vi.clearAllMocks();
});

describe('markUnpaidQuestion', () => {
    it('keeps the general question for a payment Mark Paid booked or linked', () => {
        expect(markUnpaidQuestion({ canMarkUnpaid: true })).toBe(GENERAL);
        expect(markUnpaidQuestion({ canMarkUnpaid: true, lastPaymentUsedExisting: false })).toBe(GENERAL);
        expect(markUnpaidQuestion(undefined)).toBe(GENERAL);
    });

    it('says the payment it used stays when Mark Paid used one', () => {
        expect(markUnpaidQuestion({ canMarkUnpaid: true, lastPaymentUsedExisting: true })).toBe(USED);
    });
});

describe('Mark Unpaid asks it', () => {
    it('on Bills', async () => {
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings: {}, bills: [{ id: 20890, name: 'Corner Card SA', canMarkUnpaid: true, lastPaymentUsedExisting: true }] };

        await mod.markBillUnpaid(20890);

        expect(confirmDialog).toHaveBeenCalledWith(USED, { destructive: true });
        expect(globalThis.fetch).not.toHaveBeenCalled();
    });

    it('on Transfers', async () => {
        const mod = Object.create(TransfersModule.prototype);
        mod.app = { settings: {} };
        mod.transfers = [{ id: 47, name: 'CSX Pro', isTransfer: true, canMarkUnpaid: true, lastPaymentUsedExisting: true }];

        await mod.markTransferUnpaid(47);

        expect(confirmDialog).toHaveBeenCalledWith(USED, { destructive: true });
    });
});
