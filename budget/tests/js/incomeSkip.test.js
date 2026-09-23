/**
 * Skip on the recurring income list (#396).
 *
 * Bills and transfers could skip an occurrence, income couldn't: the only way
 * past a payment that wasn't coming was to mark it received, which records a
 * receipt that never happened. Skip moves the expected date on one cycle and
 * records nothing, with the same confirm and short-lived undo.
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
import { showSuccess, showError, showUndoNotification } from '../../src/utils/notifications.js';
import { confirmDialog } from '../../src/utils/dialogs.js';

const income = (overrides = {}) => ({
    id: 1,
    name: 'Salary',
    amount: 3000,
    frequency: 'monthly',
    nextExpectedDate: '2099-06-25',
    lastReceivedDate: null,
    isActive: true,
    ...overrides,
});

function today() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function makeModule(items = []) {
    const mod = Object.create(IncomeModule.prototype);
    mod.app = { settings: {}, recurringIncome: items };
    mod.loadIncomeView = vi.fn(async () => {});
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div id="income-list"></div>
        <div id="empty-income"></div>
    `;
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    confirmDialog.mockResolvedValue(true);
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('income list Skip action', () => {
    it('is offered on active recurring income not yet received this month', () => {
        const mod = makeModule();

        mod.renderRecurringIncome([income({ id: 7 })]);

        const buttons = document.querySelectorAll('.income-skip-btn');
        expect(buttons).toHaveLength(1);
        expect(buttons[0].dataset.incomeId).toBe('7');
    });

    it('is not offered on one-time income', () => {
        const mod = makeModule();

        mod.renderRecurringIncome([income({ frequency: 'one-time' })]);

        expect(document.querySelectorAll('.income-received-btn')).toHaveLength(1);
        expect(document.querySelectorAll('.income-skip-btn')).toHaveLength(0);
    });

    it('is not offered on income already received this month', () => {
        const mod = makeModule();

        mod.renderRecurringIncome([income({ lastReceivedDate: today() })]);

        expect(document.querySelectorAll('.income-skip-btn')).toHaveLength(0);
    });

    it('is not offered on inactive income', () => {
        const mod = makeModule();

        mod.renderRecurringIncome([income({ isActive: false })]);

        expect(document.querySelectorAll('.income-skip-btn')).toHaveLength(0);
    });
});

describe('skipIncome', () => {
    const skipResponse = () => ({
        ok: true,
        json: async () => ({ income: { id: 5 }, previousNextExpectedDate: '2099-06-25' }),
    });

    it('confirms, POSTs to the skip endpoint and refreshes the list', async () => {
        const mod = makeModule([income({ id: 5 })]);
        global.fetch = vi.fn(async () => skipResponse());

        await mod.skipIncome(5);

        expect(confirmDialog).toHaveBeenCalled();
        expect(global.fetch).toHaveBeenCalledWith(
            '/apps/budget/api/recurring-income/5/skip',
            expect.objectContaining({ method: 'POST' }),
        );
        expect(mod.loadIncomeView).toHaveBeenCalled();
        expect(showError).not.toHaveBeenCalled();
    });

    it('offers an undo that puts the previous expected date back', async () => {
        const mod = makeModule([income({ id: 5 })]);
        global.fetch = vi.fn(async () => skipResponse());

        await mod.skipIncome(5);

        expect(showUndoNotification).toHaveBeenCalledTimes(1);
        const [message, undo] = showUndoNotification.mock.calls[0];
        expect(message).toContain('skipped');

        global.fetch.mockClear();
        await undo();

        expect(global.fetch).toHaveBeenCalledWith(
            '/apps/budget/api/recurring-income/5/undo-skip',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ previousNextExpectedDate: '2099-06-25' }),
            }),
        );
        expect(mod.loadIncomeView).toHaveBeenCalledTimes(2);
        expect(showSuccess).toHaveBeenCalled();
    });

    it('lets the undo expire so a stale one cannot fire later', async () => {
        const mod = makeModule([income({ id: 5 })]);
        global.fetch = vi.fn(async () => skipResponse());

        await mod.skipIncome(5);
        const [, undo, onExpire] = showUndoNotification.mock.calls[0];
        onExpire();

        global.fetch.mockClear();
        await undo();

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('does not let a skip undo be replayed as a mark-received undo', async () => {
        const mod = makeModule([income({ id: 5 })]);
        global.fetch = vi.fn(async () => skipResponse());

        await mod.skipIncome(5);
        global.fetch.mockClear();
        await mod.undoMarkReceived();

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('does nothing when the confirmation is declined', async () => {
        const mod = makeModule([income({ id: 5 })]);
        confirmDialog.mockResolvedValue(false);
        global.fetch = vi.fn();

        await mod.skipIncome(5);

        expect(global.fetch).not.toHaveBeenCalled();
        expect(mod.loadIncomeView).not.toHaveBeenCalled();
    });

    it('reports a failure and leaves the list alone', async () => {
        const mod = makeModule([income({ id: 5 })]);
        global.fetch = vi.fn(async () => ({
            ok: false,
            status: 400,
            json: async () => ({ error: 'Cannot skip an inactive income' }),
        }));

        await mod.skipIncome(5);

        expect(showError).toHaveBeenCalledWith(expect.stringContaining('Cannot skip an inactive income'));
        expect(mod.loadIncomeView).not.toHaveBeenCalled();
        expect(showUndoNotification).not.toHaveBeenCalled();
    });
});
