/**
 * Imports go only into your own accounts.
 *
 * An import runs as you, not as an account's owner, so a statement imported
 * into an account someone shared with you, even to write, always failed
 * ("Failed to preview import"), and so would a bank sync mapped to one. The
 * import screen's account pickers and the bank sync mappings offered every
 * open account you could write, shared ones included. They now offer your
 * own open accounts only; a bank mapping keeps showing the account it
 * already points at.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

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
}));

import { importTargetAccounts } from '../../src/utils/accounts.js';
import ImportModule from '../../src/modules/import/ImportModule.js';
import BankSyncModule from '../../src/modules/bank-sync/BankSyncModule.js';

const OWN = { id: 1, name: 'Current', type: 'checking', currency: 'GBP' };
const CLOSED = { id: 2, name: 'Old', type: 'savings', currency: 'GBP', closed: true };
const WRITE = { id: 3, name: 'Household', type: 'checking', currency: 'GBP', _shared: true, _canWrite: true, userId: 'owen' };
const READ = { id: 4, name: 'Parents', type: 'checking', currency: 'GBP', _shared: true, _canWrite: false, userId: 'owen' };
const ACCOUNTS = [OWN, CLOSED, WRITE, READ];

const offered = (select) => [...select.options].map(o => o.value).filter(Boolean);

afterEach(() => {
    document.body.innerHTML = '';
});

describe('importTargetAccounts', () => {
    it('lists your own open accounts only', () => {
        expect(importTargetAccounts(ACCOUNTS).map(a => a.id)).toEqual([1]);
    });

    it('keeps an account a mapping already points at', () => {
        expect(importTargetAccounts(ACCOUNTS, [3]).map(a => a.id)).toEqual([1, 3]);
    });
});

describe('the import screen', () => {
    it('offers your own open accounts to import into', () => {
        document.body.innerHTML = '<label for="import-account"></label><select id="import-account"></select>';
        const mod = Object.create(ImportModule.prototype);

        mod.populateImportAccountSelect(ACCOUNTS, false);

        expect(offered(document.getElementById('import-account'))).toEqual(['1']);
    });

    it('offers your own open accounts for each account in an OFX file', () => {
        document.body.innerHTML = '<div id="multi-account-mapping"><div id="account-mapping-list"></div></div>';
        const mod = Object.create(ImportModule.prototype);
        mod.importFormat = 'csv'; // no routing-template bar
        mod.sourceAccounts = [{ accountId: 'ACC-1', suggestedMatch: 3 }];
        mod.formatCurrency = (v) => String(v);

        mod.renderAccountMappingUI(ACCOUNTS);

        const select = document.querySelector('.destination-account-select');
        expect(offered(select)).toEqual(['1']);
        // The server's suggestion of a shared account isn't taken up
        expect(select.value).toBe('');
    });
});

describe('bank sync mappings', () => {
    it('offer your own open accounts, plus the one a mapping points at', () => {
        document.body.innerHTML = '<div id="bank-mappings-list"></div>';
        const mod = Object.create(BankSyncModule.prototype);
        mod.app = { accounts: ACCOUNTS };

        mod.renderMappings([
            { id: 10, externalAccountId: 'ext-a', enabled: true, budgetAccountId: null },
            { id: 11, externalAccountId: 'ext-b', enabled: true, budgetAccountId: 3 },
        ], 5);

        const [first, second] = document.querySelectorAll('.mapping-account-select');
        expect(offered(first)).toEqual(['1', '3']);
        expect(second.value).toBe('3');
    });

    it('offer only your own when no mapping points at a shared account', () => {
        document.body.innerHTML = '<div id="bank-mappings-list"></div>';
        const mod = Object.create(BankSyncModule.prototype);
        mod.app = { accounts: ACCOUNTS };

        mod.renderMappings([{ id: 10, externalAccountId: 'ext-a', enabled: true, budgetAccountId: null }], 5);

        expect(offered(document.querySelector('.mapping-account-select'))).toEqual(['1']);
    });
});
