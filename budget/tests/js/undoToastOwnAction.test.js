/**
 * Each Undo toast undoes the action it was shown for.
 *
 * The bills, transfers and income lists kept one shared "last action" for
 * their Undo toasts. Marking Netflix paid and then Home Internet, Undo on
 * the Netflix toast reverted Home Internet; after one Undo, the older
 * toast's Undo did nothing; and a skip toast running out wiped a newer
 * payment's undo, so that Undo silently did nothing too.
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

import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import IncomeModule from '../../src/modules/income/IncomeModule.js';
import { showUndoNotification } from '../../src/utils/notifications.js';

/** The [undo, onExpire] callbacks of the n-th toast shown. */
const toast = (n) => {
    const [, undo, onExpire] = showUndoNotification.mock.calls[n];
    return { undo, onExpire };
};

/** "METHOD url" of every request after the actions themselves. */
let requests;

beforeEach(() => {
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    requests = [];
    global.fetch = vi.fn(async (url, init = {}) => {
        requests.push(`${init.method || 'GET'} ${url}`);
        const body = /\/skip$/.test(url) ? { previousNextDueDate: '2026-10-16', previousNextExpectedDate: '2026-10-25' } : {};
        return { ok: true, status: 200, headers: { get: () => null }, json: async () => body };
    });
});

afterEach(() => {
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('bills', () => {
    const BILLS = [
        { id: 1, name: 'Netflix', frequency: 'monthly', nextDueDate: '2026-10-16', isActive: true },
        { id: 2, name: 'Home Internet', frequency: 'monthly', nextDueDate: '2026-10-20', isActive: true },
    ];

    function makeModule() {
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings: {}, bills: BILLS.map(b => ({ ...b })), accounts: [], categories: [] };
        mod.loadBillsView = vi.fn(async () => {});
        return mod;
    }

    it('undoes the payment the older toast was shown for', async () => {
        const mod = makeModule();
        await mod.markBillPaid(1);
        await mod.markBillPaid(2);
        requests.length = 0;

        await toast(0).undo();

        expect(requests).toEqual(['POST /apps/budget/api/bills/1/undo-paid']);
    });

    it('still undoes the older payment after the newer one was undone', async () => {
        const mod = makeModule();
        await mod.markBillPaid(1);
        await mod.markBillPaid(2);
        await toast(1).undo();
        requests.length = 0;

        await toast(0).undo();

        expect(requests).toEqual(['POST /apps/budget/api/bills/1/undo-paid']);
    });

    it('keeps a payment\'s undo when an older skip toast runs out', async () => {
        const mod = makeModule();
        await mod.skipBillPayment(2);
        await mod.markBillPaid(1);
        toast(0).onExpire();
        requests.length = 0;

        await toast(1).undo();

        expect(requests).toEqual(['POST /apps/budget/api/bills/1/undo-paid']);
    });

    it('undoes a skip, not a later payment', async () => {
        const mod = makeModule();
        await mod.skipBillPayment(2);
        await mod.markBillPaid(1);
        requests.length = 0;

        await toast(0).undo();

        expect(requests).toEqual(['POST /apps/budget/api/bills/2/undo-skip']);
        const [, init] = global.fetch.mock.calls.at(-1);
        expect(JSON.parse(init.body)).toEqual({ previousNextDueDate: '2026-10-16' });
    });

    it('does nothing for a toast that ran out', async () => {
        const mod = makeModule();
        await mod.markBillPaid(1);
        toast(0).onExpire();
        requests.length = 0;

        await toast(0).undo();

        expect(requests).toEqual([]);
    });
});

describe('transfers', () => {
    function makeModule() {
        const mod = Object.create(TransfersModule.prototype);
        mod.app = { settings: {} };
        mod.transfers = [
            { id: 1, name: 'Savings sweep', frequency: 'monthly', nextDueDate: '2026-10-16', isActive: true, isTransfer: true },
            { id: 2, name: 'Card payment', frequency: 'monthly', nextDueDate: '2026-10-20', isActive: true, isTransfer: true },
        ];
        mod.loadTransfers = vi.fn(async () => {});
        mod.renderTransfers = vi.fn();
        mod.updateSummary = vi.fn();
        return mod;
    }

    it('keeps a newer skip\'s undo when an older skip toast runs out', async () => {
        const mod = makeModule();
        await mod.skipTransfer(1);
        await mod.skipTransfer(2);
        toast(0).onExpire();
        requests.length = 0;

        await toast(1).undo();

        expect(requests).toEqual(['POST /apps/budget/api/bills/2/undo-skip']);
    });

    it('undoes the skip the older toast was shown for', async () => {
        const mod = makeModule();
        await mod.skipTransfer(1);
        await mod.skipTransfer(2);
        requests.length = 0;

        await toast(0).undo();

        expect(requests).toEqual(['POST /apps/budget/api/bills/1/undo-skip']);
    });
});

describe('income', () => {
    function makeModule() {
        const mod = Object.create(IncomeModule.prototype);
        mod.app = {
            settings: {},
            recurringIncome: [
                { id: 1, name: 'Salary', frequency: 'monthly', nextExpectedDate: '2026-10-25', isActive: true },
                { id: 2, name: 'Rent from lodger', frequency: 'monthly', nextExpectedDate: '2026-10-01', isActive: true },
            ],
        };
        mod.loadIncomeView = vi.fn(async () => {});
        return mod;
    }

    it('undoes the receipt the older toast was shown for', async () => {
        const mod = makeModule();
        await mod.markIncomeReceived(1);
        await mod.skipIncome(2);
        requests.length = 0;

        await toast(0).undo();

        expect(requests).toEqual(['POST /apps/budget/api/recurring-income/1/unreceived']);
    });

    it('undoes the skip, not an older receipt', async () => {
        const mod = makeModule();
        await mod.markIncomeReceived(1);
        await mod.skipIncome(2);
        requests.length = 0;

        await toast(1).undo();

        expect(requests).toEqual(['POST /apps/budget/api/recurring-income/2/undo-skip']);
    });
});
