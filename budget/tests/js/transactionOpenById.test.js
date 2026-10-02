/**
 * Opening a transaction that isn't in the loaded list: a link straight to
 * one (#transactions?id=42, which the Android app opens from its Activity
 * rows) arrives before the list has loaded, so the form has to fetch it.
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
import { apiFetch } from '../../src/utils/api.js';
import { showError } from '../../src/utils/notifications.js';

function makeModule() {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = { transactions: [] };
    mod.showTransactionModal = vi.fn();
    return mod;
}

beforeEach(() => vi.spyOn(console, 'error').mockImplementation(() => {}));
afterEach(() => vi.clearAllMocks());

describe('opening a transaction that is not in the list', () => {
    it('fetches it and opens the edit form', async () => {
        apiFetch.mockResolvedValue({ id: 5372, description: 'Market' });
        const mod = makeModule();

        await mod.editTransaction(5372);

        expect(apiFetch).toHaveBeenCalledWith('/apps/budget/api/transactions/5372');
        expect(mod.showTransactionModal).toHaveBeenCalledWith({ id: 5372, description: 'Market' });
    });

    it('says so when it cannot be loaded', async () => {
        apiFetch.mockRejectedValue(new Error('404'));
        const mod = makeModule();

        await mod.editTransaction(5372);

        expect(showError).toHaveBeenCalledWith('Failed to load transaction');
        expect(mod.showTransactionModal).not.toHaveBeenCalled();
    });

    it('uses the loaded copy when there is one', async () => {
        const mod = makeModule();
        mod.app.transactions = [{ id: 7, description: 'Rent' }];

        await mod.editTransaction(7);

        expect(apiFetch).not.toHaveBeenCalled();
        expect(mod.showTransactionModal).toHaveBeenCalledWith({ id: 7, description: 'Rent' });
    });

    it('duplicates one it had to fetch', async () => {
        apiFetch.mockResolvedValue({ id: 5372, description: 'Market' });
        const mod = makeModule();

        await mod.duplicateTransaction(5372);

        expect(mod.showTransactionModal).toHaveBeenCalledWith({ id: null, description: 'Market' });
    });
});
