/**
 * After every import the screen pairs up transfers in the background. It used
 * to sweep the whole ledger, so any two unrelated rows of the same amount a
 * few days apart in different accounts were linked as a transfer the moment
 * anything at all was imported. It now starts only from the rows it imported.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (match, key) => (key in params ? params[key] : match)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

vi.mock('../../src/utils/api.js', () => ({
    apiFetch: vi.fn(),
    ApiError: class ApiError extends Error {},
}));

import ImportModule from '../../src/modules/import/ImportModule.js';
import { apiFetch } from '../../src/utils/api.js';

function makeModule() {
    const mod = Object.create(ImportModule.prototype);
    mod.app = { loadAccounts: vi.fn() };
    mod.loadTransactions = vi.fn();
    return mod;
}

beforeEach(() => {
    apiFetch.mockResolvedValue({ autoMatched: [] });
});

afterEach(() => vi.clearAllMocks());

describe('pairing transfers after an import', () => {
    it('starts only from the rows that were imported', async () => {
        await makeModule().autoMatchTransfers([41, 42]);

        expect(apiFetch).toHaveBeenCalledWith('/apps/budget/api/transactions/bulk-match', {
            method: 'POST',
            body: { dateWindow: 3, transactionIds: [41, 42] },
        });
    });

    it('does nothing when nothing was imported', async () => {
        await makeModule().autoMatchTransfers([]);
        await makeModule().autoMatchTransfers(undefined);

        expect(apiFetch).not.toHaveBeenCalled();
    });
});
