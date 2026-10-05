/**
 * An account shared with you read-only offers nothing that changes it.
 *
 * Its tile and list row offered Edit, and its page offered Edit, Reconcile,
 * Add Transaction and (for a card) a payment bill: all refused by the
 * server ("This shared item is read-only"). Import is left out on any
 * account shared with you: an import runs as you, not as the account's
 * owner, and fails there.
 *
 * Its reconciliation history is readable with the share alone, so the page
 * still shows it; but filtering the transactions to it looked for a
 * reconciliation in progress, which needs write access and got a 403.
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
import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';

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

    it('still shows the reconciliation history of an account shared read-only', async () => {
        // Reading it needs only the share; reconciling needs write
        global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
        global.fetch = vi.fn(async () => ({
            ok: true, status: 200, headers: { get: () => null },
            json: async () => [{ statementDate: '2026-09-30', statementBalance: 10, reconciledCount: 3, completedAt: '2026-10-01' }],
        }));
        const mod = makeModule();
        mod.currentAccount = readOnly;

        await mod.loadReconciliationHistory(readOnly.id);

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(shown('recon-history-section')).toBe(true);
    });
});

describe('the transactions page', () => {
    function makeTransactions(accounts) {
        const mod = Object.create(TransactionsModule.prototype);
        mod.app = { accounts };
        mod.reconcileMode = false;
        mod.showReconcileResumeBanner = vi.fn();
        return mod;
    }

    beforeEach(() => {
        global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
        global.fetch = vi.fn(async () => ({ ok: true, status: 200, headers: { get: () => null }, json: async () => ({ session: null }) }));
    });

    it('doesn\'t look for a reconciliation in progress on an account shared read-only', async () => {
        // Only someone who can write the account has one; asking is a 403
        await makeTransactions([own, write, readOnly]).checkActiveReconcileSession(readOnly.id);

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('looks on your own account and on one shared to write', async () => {
        const mod = makeTransactions([own, write, readOnly]);

        await mod.checkActiveReconcileSession(own.id);
        await mod.checkActiveReconcileSession(write.id);

        expect(global.fetch).toHaveBeenCalledTimes(2);
    });
});
