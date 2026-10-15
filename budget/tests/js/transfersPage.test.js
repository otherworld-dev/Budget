/**
 * The Transfers page against each transfer's own occurrences.
 *
 * - Rows follow the next occurrence (#399), and the filter tabs work: they
 *   looked for cards that no longer exist and hid nothing.
 * - The summary reads dates as dates (a transfer paid on the 1st counted as
 *   last month's west of UTC), and the monthly total leaves out one-time
 *   transfers and counts a daily one every day.
 * - Editing keeps the transfer's pre-booking (the form's "create now" box
 *   went out as the pre-booking setting and switched it off), offers every
 *   frequency it can hold, and loads the tag picker without a category.
 * - Mark Paid names the occurrence and warns when nothing was booked.
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

import TransfersModule from '../../src/modules/transfers/TransfersModule.js';
import { showMatchingTransactionDialog } from '../../src/utils/matchingDialog.js';
import { showWarning } from '../../src/utils/notifications.js';

function localDate(offset = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offset);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const transfer = (fields) => ({
    id: 1, name: 'Savings', amount: 100, frequency: 'monthly', isActive: true,
    accountId: 1, destinationAccountId: 2, nextDueDate: localDate(20), ...fields,
});

function makeModule(transfers = []) {
    const mod = Object.create(TransfersModule.prototype);
    mod.app = { settings: {}, accounts: [{ id: 1, name: 'Current' }, { id: 2, name: 'Savings' }], categories: [], categoryTree: [] };
    mod.transfers = transfers;
    mod.loadTransfers = vi.fn(async () => true);
    mod.loadTransferTagSets = vi.fn();
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="transfers-list"></div><div id="empty-transfers"></div>
        <span id="transfers-active-count"></span><span id="transfers-due-count"></span>
        <span id="transfers-monthly-total"></span><span id="transfers-completed-count"></span>
    `;
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('the transfer list', () => {
    it('filters by status', () => {
        const mod = makeModule([
            transfer({ id: 1, nextDueDate: localDate(-3) }),
            transfer({ id: 2, nextDueDate: localDate(2) }),
            transfer({ id: 3, lastPaidDate: localDate(-1), nextDueDate: localDate(29) }),
        ]);
        mod.renderTransfers();

        mod.filterTransfers('overdue');
        const shown = () => [...document.querySelectorAll('#transfers-list .bill-card')]
            .filter(c => c.style.display !== 'none').map(c => c.dataset.billId);
        expect(shown()).toEqual(['1']);

        mod.filterTransfers('due');
        expect(shown()).toEqual(['2']);

        mod.filterTransfers('completed');
        expect(shown()).toEqual(['3']);
    });

    it('shows the monthly total the server works out', async () => {
        // The server leaves out one-time transfers and converts each from its
        // account's currency; adding the amounts here mixed currencies
        const mod = makeModule([transfer({ id: 1, frequency: 'one-time', amount: 500 })]);
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ monthlyTotal: 30.42, baseCurrency: 'GBP' }) }));

        await mod.updateSummary();

        expect(global.fetch.mock.calls[0][0]).toContain('/apps/budget/api/bills/summary?isTransfer=true');
        expect(document.getElementById('transfers-monthly-total').textContent).toContain('30.42');
    });

    it('counts what is due this month by the date itself', async () => {
        const mod = makeModule([transfer({ nextDueDate: localDate(0) })]);
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ monthlyTotal: 0 }) }));

        await mod.updateSummary();

        expect(document.getElementById('transfers-due-count').textContent).toBe('1');
    });
});

describe('Mark Paid on a transfer', () => {
    it('names the occurrence and warns when nothing was booked', async () => {
        const mod = makeModule([transfer({ nextDueDate: '2026-10-15' })]);
        mod.renderTransfers = vi.fn();
        mod.updateSummary = vi.fn();
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ bill: { id: 1 }, paymentTransactionRecorded: false }) }));

        await mod.markTransferPaid(1);

        const paid = global.fetch.mock.calls.find(([url]) => url.endsWith('/paid'));
        const body = JSON.parse(paid[1].body);
        expect(body.dueDate).toBe('2026-10-15');
        expect(body.recordPayment).toBe(true);
        expect(showWarning).toHaveBeenCalled();
    });
});

describe('Mark Paid with the bank row already there', () => {
    it('offers the rows already in the account and links the one chosen', async () => {
        // Mark Paid always booked a new pair, so a transfer the statement
        // had already brought in moved the money twice
        const mod = makeModule([transfer({ nextDueDate: '2026-10-15' })]);
        mod.renderTransfers = vi.fn();
        mod.updateSummary = vi.fn();
        const candidates = [{ transaction: { id: 55, date: '2026-10-15', amount: 100 }, score: 80, matchReasons: [] }];
        global.fetch = vi.fn(async (url) => ({
            ok: true,
            json: async () => (url.includes('matching-transactions') ? candidates : { bill: { id: 1 }, paymentTransactionRecorded: true }),
        }));
        showMatchingTransactionDialog.mockResolvedValue({ action: 'link', transactionId: 55 });

        await mod.markTransferPaid(1);

        const paid = global.fetch.mock.calls.find(([url]) => url.endsWith('/paid'));
        const body = JSON.parse(paid[1].body);
        expect(body.existingTransactionId).toBe(55);
        expect(body.recordPayment).toBe(false);
    });

    it('does nothing when the dialog is cancelled', async () => {
        const mod = makeModule([transfer({ nextDueDate: '2026-10-15' })]);
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => [{ transaction: { id: 55 }, score: 80, matchReasons: [] }] }));
        showMatchingTransactionDialog.mockResolvedValue(null);

        await mod.markTransferPaid(1);

        expect(global.fetch.mock.calls.some(([url]) => url.endsWith('/paid'))).toBe(false);
    });
});

describe('the transfer form', () => {
    it('keeps the transfer\'s pre-booking when it is edited', async () => {
        const existing = transfer({ id: 9, createTransaction: true });
        const mod = makeModule([existing]);
        mod.renderTransfers = vi.fn();
        mod.updateSummary = vi.fn();
        mod.showTransferModal(existing);
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ id: 9 }) }));

        await mod.saveTransfer(existing);

        const body = JSON.parse(global.fetch.mock.calls[0][1].body);
        expect(body).not.toHaveProperty('createTransaction');
    });

    it('offers daily and half-yearly, and keeps a custom transfer custom', () => {
        const mod = makeModule();

        mod.showTransferModal(transfer({ frequency: 'custom' }));

        const options = [...document.querySelectorAll('#transfer-frequency option')].map(o => o.value);
        expect(options).toEqual(expect.arrayContaining(['daily', 'semi-annually', 'custom']));
        expect(document.getElementById('transfer-frequency').value).toBe('custom');
    });

    it('asks a yearly transfer for its month', () => {
        // Without one every yearly transfer fell in January
        const mod = makeModule();

        mod.showTransferModal(transfer({ frequency: 'yearly', dueMonth: 9 }));

        expect(document.getElementById('transfer-due-month-group').style.display).toBe('block');
        expect(document.getElementById('transfer-due-month').value).toBe('9');
    });

    it('loads the tag picker for a transfer with no category', () => {
        const mod = makeModule();

        mod.showTransferModal(transfer({ categoryId: null, tagIds: [4] }));

        expect(mod.loadTransferTagSets).toHaveBeenCalled();
    });
});
