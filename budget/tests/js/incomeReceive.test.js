/**
 * Mark Received on the income list (#399). It names the occurrence the row
 * showed, so the server refuses a second click instead of booking the money
 * twice, and its undo really reverts the receipt: it used to reset only the
 * last received date, leaving the credit in the ledger and the date moved on.
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

import IncomeModule from '../../src/modules/income/IncomeModule.js';
import { showError, showUndoNotification } from '../../src/utils/notifications.js';

function makeModule(items) {
    const mod = Object.create(IncomeModule.prototype);
    mod.app = { settings: {}, recurringIncome: items };
    mod.loadIncomeView = vi.fn(async () => {});
    return mod;
}

const ok = (body = {}) => ({ ok: true, json: async () => body });

beforeEach(() => {
    document.body.innerHTML = '<div id="income-list"></div><div id="empty-income"></div>';
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('markIncomeReceived', () => {
    it('names the occurrence the row showed', async () => {
        const mod = makeModule([{ id: 5, name: 'Salary', frequency: 'monthly', nextExpectedDate: '2026-10-03', accountId: 2 }]);
        global.fetch = vi.fn(async () => ok({ id: 5 }));

        await mod.markIncomeReceived(5);

        const [url, init] = global.fetch.mock.calls[0];
        expect(url).toBe('/apps/budget/api/recurring-income/5/received');
        const body = JSON.parse(init.body);
        expect(body.expectedDate).toBe('2026-10-03');
        expect(body.createTransaction).toBe(true);
        expect(body.receivedDate).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    });

    it('shows the server\'s reason when it refuses', async () => {
        const mod = makeModule([{ id: 5, name: 'Salary', nextExpectedDate: '2026-10-03' }]);
        global.fetch = vi.fn(async () => ({
            ok: false,
            status: 400,
            json: async () => ({ error: 'This payment was already recorded. Reload the page to see the next one.' }),
        }));

        await mod.markIncomeReceived(5);

        expect(showError).toHaveBeenCalledWith('This payment was already recorded. Reload the page to see the next one.');
    });

    it('undoes through the server, which reverts the dates and the credit', async () => {
        const mod = makeModule([{ id: 5, name: 'Salary', nextExpectedDate: '2026-10-03' }]);
        global.fetch = vi.fn(async () => ok({ id: 5 }));

        await mod.markIncomeReceived(5);
        const [, undo] = showUndoNotification.mock.calls[0];
        global.fetch.mockClear();
        await undo();

        expect(global.fetch).toHaveBeenCalledWith(
            '/apps/budget/api/recurring-income/5/unreceived',
            expect.objectContaining({ method: 'POST' }),
        );
    });
});
