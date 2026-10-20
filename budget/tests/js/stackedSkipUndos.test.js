/**
 * Two skips on one bill, and the older toast's Undo first (#399, browser
 * round 3).
 *
 * Undoing a skip puts the bill back on the date it skipped, which undoes
 * every skip after it too. So after Skip, Skip, the older toast's Undo put
 * the bill back past both, and the newer toast's Undo then said 'Action
 * undone' followed by a red 'Failed to undo action: That date is after the
 * bill's next due date' (the server's refusal of a skip already undone).
 * An Undo that goes through now settles the item's later skip toasts too:
 * they close, and their Undo does nothing. Undoing the newer one first
 * still leaves the older one to undo. Income and transfers work the same.
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
    showUndoNotification: vi.fn(() => ({ dismiss: vi.fn() })),
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(true)),
    promptDialog: vi.fn(() => Promise.resolve(null)),
    alertDialog: vi.fn(() => Promise.resolve()),
}));

import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import IncomeModule from '../../src/modules/income/IncomeModule.js';
import { showUndoNotification, showError } from '../../src/utils/notifications.js';

const toast = (n) => ({
    undo: showUndoNotification.mock.calls[n][1],
    handle: showUndoNotification.mock.results[n].value,
});

/** "METHOD url" of every request after the skips themselves */
let requests;
/** The date each skip answers it skipped, in turn */
let skipped;

beforeEach(() => {
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    requests = [];
    skipped = ['2026-10-24', '2026-11-24'];
    global.fetch = vi.fn(async (url, init = {}) => {
        requests.push(`${init.method || 'GET'} ${url}`);
        const body = /\/skip$/.test(url)
            ? (date => ({ previousNextDueDate: date, previousNextExpectedDate: date }))(skipped.shift())
            : {};
        return { ok: true, status: 200, headers: { get: () => null }, json: async () => body };
    });
});

afterEach(() => {
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

const KINDS = {
    bill: {
        undoUrl: id => `POST /apps/budget/api/bills/${id}/undo-skip`,
        make() {
            const mod = Object.create(BillsModule.prototype);
            mod.app = { settings: {}, bills: [], accounts: [], categories: [] };
            mod.bills = [
                { id: 1, name: 'Claude by Anthropic', frequency: 'monthly', nextDueDate: '2026-10-24', isActive: true },
                { id: 2, name: 'Netflix', frequency: 'monthly', nextDueDate: '2026-10-16', isActive: true },
            ];
            mod.app.bills = mod.bills;
            mod.loadBillsView = vi.fn(async () => {});
            return mod;
        },
        skip: (mod, id) => mod.skipBillPayment(id),
    },
    transfer: {
        undoUrl: id => `POST /apps/budget/api/bills/${id}/undo-skip`,
        make() {
            const mod = Object.create(TransfersModule.prototype);
            mod.app = { settings: {} };
            mod.transfers = [
                { id: 1, name: 'Savings sweep', frequency: 'monthly', nextDueDate: '2026-10-24', isActive: true, isTransfer: true },
                { id: 2, name: 'Card payment', frequency: 'monthly', nextDueDate: '2026-10-16', isActive: true, isTransfer: true },
            ];
            mod.loadTransfers = vi.fn(async () => {});
            mod.renderTransfers = vi.fn();
            mod.updateSummary = vi.fn();
            return mod;
        },
        skip: (mod, id) => mod.skipTransfer(id),
    },
    income: {
        undoUrl: id => `POST /apps/budget/api/recurring-income/${id}/undo-skip`,
        make() {
            const mod = Object.create(IncomeModule.prototype);
            mod.app = {
                settings: {},
                recurringIncome: [
                    { id: 1, name: 'Salary', frequency: 'monthly', nextExpectedDate: '2026-10-24', isActive: true },
                    { id: 2, name: 'Rent from lodger', frequency: 'monthly', nextExpectedDate: '2026-10-16', isActive: true },
                ],
            };
            mod.loadIncomeView = vi.fn(async () => {});
            return mod;
        },
        skip: (mod, id) => mod.skipIncome(id),
    },
};

describe.each(Object.keys(KINDS))('two skips on one %s', (kind) => {
    const { make, skip, undoUrl } = KINDS[kind];

    it('closes the newer toast once the older one\'s Undo undid both', async () => {
        const mod = make();
        await skip(mod, 1);
        await skip(mod, 1);
        requests.length = 0;

        await toast(0).undo();

        expect(requests).toEqual([undoUrl(1)]);
        expect(toast(1).handle.dismiss).toHaveBeenCalled();
    });

    it('reports nothing for the newer toast\'s Undo after that', async () => {
        const mod = make();
        await skip(mod, 1);
        await skip(mod, 1);
        await toast(0).undo();
        requests.length = 0;

        await toast(1).undo();

        expect(requests).toEqual([]);
        expect(showError).not.toHaveBeenCalled();
    });

    it('still undoes the older skip after the newer one was undone', async () => {
        const mod = make();
        await skip(mod, 1);
        await skip(mod, 1);
        await toast(1).undo();
        requests.length = 0;

        expect(toast(0).handle.dismiss).not.toHaveBeenCalled();
        await toast(0).undo();

        expect(requests).toEqual([undoUrl(1)]);
    });

    it('leaves another item\'s skip toast alone', async () => {
        const mod = make();
        await skip(mod, 1);
        await skip(mod, 2);
        await toast(0).undo();
        requests.length = 0;

        expect(toast(1).handle.dismiss).not.toHaveBeenCalled();
        await toast(1).undo();

        expect(requests).toEqual([undoUrl(2)]);
    });
});
