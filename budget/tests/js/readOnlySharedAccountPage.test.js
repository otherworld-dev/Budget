/**
 * An account shared with you read-only offers nothing that changes it.
 *
 * Its tile and list row offered Edit, and its page offered Edit, Reconcile,
 * Add Transaction and (for a card) a payment bill: all refused by the
 * server ("This shared item is read-only"). Opening its page also asked for
 * its reconciliation history and got a 403. Import is left out on any
 * account shared with you: an import runs as you, not as the account's
 * owner, and fails there.
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

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

const own = { id: 1, name: 'Mine', type: 'checking', balance: 10, currency: 'GBP' };
const write = { ...own, id: 8, name: 'Joint', _shared: true, _canWrite: true, userId: 'owen' };
const readOnly = { ...own, id: 9, name: 'Parents', _shared: true, _canWrite: false, userId: 'owen' };

function makeModule() {
    const mod = Object.create(AccountsModule.prototype);
    mod.app = { settings: {} };
    mod.selectedAccountIds = new Set();
    mod.getAccountTypeInfo = () => ({ label: 'Checking', color: '#000', icon: 'icon-folder' });
    mod.getAccountHealthStatus = () => ({ class: 'ok', tooltip: '' });
    mod.formatCurrency = (v) => String(v);
    mod.formatDate = (v) => String(v);
    mod.getPrimaryCurrency = () => 'GBP';
    mod.accountField = (account, key) => account[key];
    mod.formatLastReconciled = () => '';
    mod.visibleAccountColumns = () => [];
    return mod;
}

const getField = (account, key) => account[key];

afterEach(() => {
    document.body.innerHTML = '';
    delete global.fetch;
    delete global.OC;
});

describe.each([
    ['card', (mod, account) => mod.renderAccountCard(account, getField, { status: false, sparkline: false }, [])],
    ['row', (mod, account) => mod.renderAccountRow(account, getField, {}, [])],
])('account %s', (_name, renderOne) => {
    const editButton = (account) => {
        document.body.innerHTML = renderOne(makeModule(), account);
        return document.querySelector('.edit-account-btn');
    };

    it('has no Edit when shared read-only', () => {
        expect(editButton(readOnly)).toBeNull();
    });

    it('keeps Edit on your own account and on one shared to write', () => {
        expect(editButton(own)).not.toBeNull();
        expect(editButton(write)).not.toBeNull();
    });
});

describe('the account page', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <h2 id="account-details-title"></h2>
            <span id="account-type-icon"></span><span id="account-display-name"></span><span id="account-type-label"></span>
            <span id="account-closed-badge"></span><span id="account-institution"></span>
            <span id="account-health-indicator"></span>
            <button id="edit-account-btn"></button>
            <button id="reconcile-account-btn"></button>
            <button id="account-add-transaction-btn"></button>
            <button id="account-import-btn"></button>
            <span id="account-number"></span><span id="routing-number"></span><span id="account-iban"></span>
            <span id="sort-code"></span><span id="swift-bic"></span><span id="account-display-currency"></span>
            <span id="account-opened"></span><span id="last-reconciled"></span>
            <div id="recon-history-section"><table><tbody id="recon-history-body"></tbody></table></div>`;
    });

    const shown = (id) => document.getElementById(id).style.display !== 'none';

    function open(account) {
        const mod = makeModule();
        try {
            mod.populateAccountOverview(account);
        } catch (e) {
            // Header pieces this test doesn't mount; the buttons come first
        }
        return mod;
    }

    it('offers no edit, reconcile, new transaction or import when shared read-only', () => {
        open(readOnly);

        expect(['edit-account-btn', 'reconcile-account-btn', 'account-add-transaction-btn', 'account-import-btn'].map(shown))
            .toEqual([false, false, false, false]);
    });

    it('offers everything but import on an account shared to write', () => {
        open(write);

        expect(['edit-account-btn', 'reconcile-account-btn', 'account-add-transaction-btn', 'account-import-btn'].map(shown))
            .toEqual([true, true, true, false]);
    });

    it('offers everything on your own account', () => {
        open(own);

        expect(['edit-account-btn', 'reconcile-account-btn', 'account-add-transaction-btn', 'account-import-btn'].map(shown))
            .toEqual([true, true, true, true]);
    });

    it('doesn\'t ask for the reconciliation history of an account shared read-only', async () => {
        global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
        global.fetch = vi.fn(async () => ({ ok: true, status: 200, headers: { get: () => null }, json: async () => [] }));
        const mod = makeModule();
        mod.currentAccount = readOnly;

        await mod.loadReconciliationHistory(readOnly.id);

        expect(global.fetch).not.toHaveBeenCalled();
        expect(shown('recon-history-section')).toBe(false);
    });
});
