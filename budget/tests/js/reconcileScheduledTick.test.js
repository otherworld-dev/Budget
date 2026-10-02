/**
 * A scheduled row - a bill's payment due today, before it is paid - shows in
 * a reconciliation session when its date is on or before the statement date.
 * Ticking it never moved the difference, so the user balanced with an
 * adjustment, and Finish marked it reconciled anyway. The server now refuses
 * the tick; the page says why instead of showing a tick that does nothing.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/api.js', () => ({
    apiFetch: vi.fn(),
    ApiError: class ApiError extends Error {},
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import { showWarning } from '../../src/utils/notifications.js';

function makeModule() {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = {
        transactions: [
            { id: 1, type: 'debit', amount: '10.00', status: 'cleared' },
            { id: 2, type: 'debit', amount: '300.00', status: 'scheduled', billId: 9 },
        ],
    };
    mod.reconcileMode = true;
    mod.reconcileSession = {
        session: { accountId: 7, statementBalance: 990, startingBalance: 1000 },
        tickedSum: 0,
        tickedCount: 0,
    };
    mod.selectedTransactions = new Set();
    mod._reconTickQueue = new Set();
    mod._reconUntickQueue = new Set();
    mod.renderReconcileBar = vi.fn();
    mod.updateBulkActionsState = vi.fn();
    mod.refreshSelectAllBanner = vi.fn();
    mod.flushReconcileTicks = vi.fn();
    return mod;
}

beforeEach(() => {
    document.body.innerHTML = `
        <input type="checkbox" class="transaction-checkbox" data-transaction-id="1">
        <input type="checkbox" class="transaction-checkbox" data-transaction-id="2">
    `;
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

const box = (id) => document.querySelector(`.transaction-checkbox[data-transaction-id="${id}"]`);

describe('ticking a scheduled row in a reconciliation session', () => {
    it('is refused with a reason', () => {
        const mod = makeModule();
        box(2).checked = true;

        mod.handleTransactionCheckbox(box(2));

        expect(box(2).checked).toBe(false);
        expect(mod._reconTickQueue.has(2)).toBe(false);
        expect(mod.selectedTransactions.has(2)).toBe(false);
        expect(showWarning).toHaveBeenCalled();
    });

    it('still ticks a cleared row', () => {
        const mod = makeModule();
        box(1).checked = true;

        mod.handleTransactionCheckbox(box(1));

        expect(mod._reconTickQueue.has(1)).toBe(true);
        expect(mod.selectedTransactions.has(1)).toBe(true);
    });

    it('is skipped by tick-all on the page', () => {
        const mod = makeModule();

        mod.toggleAllTransactionSelection(true);

        expect(mod._reconTickQueue.has(1)).toBe(true);
        expect(mod._reconTickQueue.has(2)).toBe(false);
        expect(box(2).checked).toBe(false);
        expect(mod.selectedTransactions.has(2)).toBe(false);
    });
});
