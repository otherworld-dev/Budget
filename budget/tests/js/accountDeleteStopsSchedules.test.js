/**
 * Deleting an account now switches off the bills, transfers and recurring
 * income that pay into or out of it (they used to go on running against the
 * deleted account). The confirmation says so before anything happens.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(false)),
    promptDialog: vi.fn(() => Promise.resolve(null)),
    alertDialog: vi.fn(() => Promise.resolve()),
}));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';
import { confirmDialog } from '../../src/utils/dialogs.js';

beforeEach(() => {
    confirmDialog.mockResolvedValue(false);
});

afterEach(() => vi.clearAllMocks());

describe('the account delete confirmation', () => {
    it('says what happens to the bills that use the account', async () => {
        const mod = Object.create(AccountsModule.prototype);
        mod._sendAccountDelete = vi.fn();

        await mod.deleteAccount(7);

        expect(confirmDialog.mock.calls[0][0]).toContain('switched off');
        expect(mod._sendAccountDelete).not.toHaveBeenCalled();
    });

    it('says the same when deleting several', async () => {
        const mod = Object.create(AccountsModule.prototype);
        mod.selectedAccountIds = new Set([7, 8]);
        mod._sendBulkAccountDelete = vi.fn();

        await mod.bulkDeleteAccounts();

        expect(confirmDialog.mock.calls[0][0]).toContain('switched off');
        expect(mod._sendBulkAccountDelete).not.toHaveBeenCalled();
    });
});
