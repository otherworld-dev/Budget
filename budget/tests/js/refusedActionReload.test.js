/**
 * A list shows what the server has once it refuses an action (#399).
 *
 * Migrol paid in one tab, the other tab's Mark Paid was refused with "This
 * bill has nothing left to pay", and the row went on reading Overdue with
 * Mark Paid until the page was reloaded by hand. A refusal of any payment
 * action now reloads its list, after saying why. A request that never
 * reached the server (no network) leaves the list alone: reloading it would
 * fail the same way.
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
}));

import BillsModule from '../../src/modules/bills/BillsModule.js';
import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import IncomeModule from '../../src/modules/income/IncomeModule.js';
import { showError } from '../../src/utils/notifications.js';
import { confirmDialog } from '../../src/utils/dialogs.js';

const NOTHING_LEFT = 'This bill has nothing left to pay';
const ALREADY_SETTLED = 'This date was already paid or skipped. Reload the page to see the next one.';

/** How each POST is answered: [status, body], or an Error to throw (no network) */
let refusal;

beforeEach(() => {
    globalThis.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    refusal = [400, { error: ALREADY_SETTLED }];
    globalThis.fetch = vi.fn(async (url, init = {}) => {
        if ((init.method || 'GET') === 'GET') {
            const data = url.includes('/recorded-payment') ? { existing: null } : [];
            return { ok: true, status: 200, headers: { get: () => null }, json: async () => data };
        }
        if (refusal instanceof Error) {
            throw refusal;
        }
        const [status, data] = refusal;
        return { ok: false, status, headers: { get: () => null }, json: async () => data };
    });
});

afterEach(() => {
    delete globalThis.OC;
    delete globalThis.fetch;
    vi.clearAllMocks();
});

function billsModule() {
    const mod = Object.create(BillsModule.prototype);
    mod.app = {
        settings: {},
        accounts: [{ id: 3, currency: 'CHF' }],
        bills: [{ id: 5, name: 'Migrol', amount: 670, frequency: 'monthly', nextDueDate: '2026-10-01', accountId: 3, isActive: true }],
    };
    mod.loadBillsView = vi.fn(async () => {});
    return mod;
}

function transfersModule() {
    const mod = Object.create(TransfersModule.prototype);
    mod.app = { settings: {}, accounts: [{ id: 3, currency: 'CHF' }] };
    mod.transfers = [{ id: 7, name: 'CSX Pro', amount: 100, frequency: 'monthly', nextDueDate: '2026-10-28', accountId: 3, isTransfer: true, isActive: true }];
    mod.loadTransfers = vi.fn(async () => true);
    mod.renderTransfers = vi.fn();
    mod.updateSummary = vi.fn();
    return mod;
}

function incomeModule() {
    const mod = Object.create(IncomeModule.prototype);
    mod.app = { settings: {}, accounts: [], recurringIncome: [{ id: 9, name: 'SBB Abo', frequency: 'monthly', nextExpectedDate: '2026-09-29', accountId: 3 }] };
    mod.loadIncomeView = vi.fn(async () => {});
    return mod;
}

/** Reloaded: the bills or income view, or the transfers list redrawn from a fresh load */
const reloaded = (mod) => (mod.loadBillsView ?? mod.loadIncomeView ?? mod.loadTransfers);

describe.each([
    ['Bills: Mark Paid', billsModule, (mod) => mod.markBillPaid(5), NOTHING_LEFT],
    ['Bills: Mark Paid', billsModule, (mod) => mod.markBillPaid(5), ALREADY_SETTLED],
    ['Bills: Skip', billsModule, (mod) => mod.skipBillPayment(5), ALREADY_SETTLED],
    ['Bills: Mark Unpaid', billsModule, (mod) => mod.markBillUnpaid(5), 'This bill has no recorded payment to undo'],
    ['Transfers: Mark Paid', transfersModule, (mod) => mod.markTransferPaid(7), ALREADY_SETTLED],
    ['Transfers: Skip', transfersModule, (mod) => mod.skipTransfer(7), ALREADY_SETTLED],
    ['Transfers: Mark Unpaid', transfersModule, (mod) => mod.markTransferUnpaid(7), 'This bill has no recorded payment to undo'],
    ['Income: Mark Received', incomeModule, (mod) => mod.markIncomeReceived(9), ALREADY_SETTLED],
    ['Income: Skip', incomeModule, (mod) => mod.skipIncome(9), ALREADY_SETTLED],
])('%s', (_name, makeModule, act, message) => {
    it(`reloads the list once refused with "${message}", after saying why`, async () => {
        refusal = [400, { error: message }];
        const mod = makeModule();

        await act(mod);

        expect(showError).toHaveBeenCalledWith(message);
        expect(reloaded(mod)).toHaveBeenCalledTimes(1);
        expect(showError.mock.invocationCallOrder[0]).toBeLessThan(reloaded(mod).mock.invocationCallOrder[0]);
        if (mod.renderTransfers) {
            expect(mod.renderTransfers).toHaveBeenCalledTimes(1);
            expect(mod.updateSummary).toHaveBeenCalledTimes(1);
        }
    });

    it('leaves the list alone when the request never reached the server', async () => {
        refusal = new TypeError('Failed to fetch');
        const mod = makeModule();

        await act(mod);

        expect(showError).toHaveBeenCalled();
        expect(reloaded(mod)).not.toHaveBeenCalled();
    });
});

describe('an action the user leaves', () => {
    it.each([
        ['Bills: Skip', billsModule, (mod) => mod.skipBillPayment(5)],
        ['Transfers: Mark Unpaid', transfersModule, (mod) => mod.markTransferUnpaid(7)],
        ['Income: Skip', incomeModule, (mod) => mod.skipIncome(9)],
    ])('%s reloads nothing', async (_name, makeModule, act) => {
        confirmDialog.mockResolvedValueOnce(false);
        const mod = makeModule();

        await act(mod);

        expect(globalThis.fetch).not.toHaveBeenCalled();
        expect(reloaded(mod)).not.toHaveBeenCalled();
    });
});
