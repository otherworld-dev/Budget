/**
 * Deleting a scheduled row told the user the balance would go up by its
 * amount and, for a bill's pre-booked row, warned that "a real payment"
 * would make the balance diverge from the bank. Scheduled rows are in no
 * balance, and a bill's pre-booked row is a payment nobody has made yet.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
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

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(false)),
    promptDialog: vi.fn(() => Promise.resolve(null)),
    alertDialog: vi.fn(() => Promise.resolve()),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import { confirmDialog } from '../../src/utils/dialogs.js';
import { apiFetch } from '../../src/utils/api.js';

function makeModule(transaction) {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = {
        transactions: [transaction],
        accounts: [{ id: 10, currency: 'GBP' }],
        settings: {},
    };
    return mod;
}

async function confirmTextFor(transaction) {
    await makeModule(transaction).deleteTransaction(transaction.id);
    return confirmDialog.mock.calls[0][0];
}

beforeEach(() => {
    confirmDialog.mockResolvedValue(false);
});
afterEach(() => vi.clearAllMocks());

describe('the delete confirmation', () => {
    it('calls a bill\'s pre-booked row an upcoming payment that moves no money', async () => {
        const text = await confirmTextFor({ id: 1, accountId: 10, amount: '25.00', type: 'debit', status: 'scheduled', billId: 9 });

        expect(text).toContain('upcoming payment');
        expect(text).toContain('balance does not change');
        expect(text).not.toContain('increase the account balance');
        expect(text).not.toContain('real payment');
        expect(apiFetch).not.toHaveBeenCalled();
    });

    it('says a future-dated row is not in the balance yet', async () => {
        const text = await confirmTextFor({ id: 2, accountId: 10, amount: '40.00', type: 'credit', status: 'scheduled' });

        expect(text).toContain('balance does not change');
        expect(text).not.toContain('decrease the account balance');
    });

    it('keeps the balance warning for a recorded bill payment', async () => {
        const text = await confirmTextFor({ id: 3, accountId: 10, amount: '25.00', type: 'debit', status: 'cleared', billId: 9 });

        expect(text).toContain('increase the account balance');
        expect(text).toContain('real payment');
    });
});
