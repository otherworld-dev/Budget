/**
 * Closed accounts (#372).
 *
 * An old bank account keeps its history but must stop turning up wherever the
 * user picks an account for NEW activity — the transaction form first of all.
 * Two properties keep that honest:
 *
 *   1. A picker for new activity lists only open accounts. The transactions
 *      filter, reports and the other history views are deliberately NOT
 *      pickers in this sense and keep listing everything.
 *   2. Editing a record that already points at a closed account keeps that
 *      account selected, labelled as closed. Assigning an id with no matching
 *      <option> silently yields "" and the save would strip the account off
 *      the record — the exact failure #370 fixed for unshared accounts.
 *
 * The accounts page moves closed accounts into their own collapsed section so
 * they stay reachable without cluttering the live ones.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count, params = {}) =>
        String(count === 1 ? singular : plural).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import { openAccounts, pickableAccounts, accountOptionLabel, selectAccountValue } from '../../src/utils/accounts.js';
import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

const CURRENT = { id: 1, name: 'Current', type: 'checking', balance: 100, currency: 'GBP' };
const OLD_SAVINGS = { id: 2, name: 'Old Halifax', type: 'savings', balance: 0, currency: 'GBP', closed: true };
const VISA = { id: 3, name: 'Visa', type: 'credit_card', balance: -50, currency: 'GBP' };
const OLD_CARD = { id: 4, name: 'Old Amex', type: 'credit_card', balance: 0, currency: 'GBP', closed: true };
const ALL = [CURRENT, OLD_SAVINGS, VISA, OLD_CARD];

const values = (select) => Array.from(select.options).map(o => o.value);

// ── the helper ─────────────────────────────────────────────────────

describe('account picker helpers', () => {
    it('openAccounts drops closed accounts and tolerates a missing list', () => {
        expect(openAccounts(ALL).map(a => a.id)).toEqual([1, 3]);
        expect(openAccounts(undefined)).toEqual([]);
    });

    it('pickableAccounts keeps only the closed account the edited record already points at', () => {
        expect(pickableAccounts(ALL, 2).map(a => a.id)).toEqual([1, 2, 3]);
        expect(pickableAccounts(ALL, [2, 4]).map(a => a.id)).toEqual([1, 2, 3, 4]);
        expect(pickableAccounts(ALL, '2').map(a => a.id)).toEqual([1, 2, 3]);
        expect(pickableAccounts(ALL, null).map(a => a.id)).toEqual([1, 3]);
        expect(pickableAccounts(ALL, '').map(a => a.id)).toEqual([1, 3]);
    });

    it('accountOptionLabel says when an account is closed', () => {
        expect(accountOptionLabel(CURRENT)).toBe('Current');
        expect(accountOptionLabel(OLD_SAVINGS)).toBe('Old Halifax (closed)');
    });

    describe('selectAccountValue', () => {
        let select;

        beforeEach(() => {
            document.body.innerHTML = `
                <select id="s">
                    <option value="">Choose</option>
                    <option value="1">Current</option>
                    <option value="3">Visa</option>
                </select>`;
            select = document.getElementById('s');
        });

        it('selects an option that is already there', () => {
            expect(selectAccountValue(select, ALL, 3)).toBe(true);
            expect(select.value).toBe('3');
            expect(values(select)).toEqual(['', '1', '3']);
        });

        it('appends a labelled option for a closed account so the value survives', () => {
            expect(selectAccountValue(select, ALL, 2)).toBe(true);
            expect(select.value).toBe('2');
            const added = select.querySelector('option[value="2"]');
            expect(added.textContent).toBe('Old Halifax (closed)');
            expect(added.disabled).toBe(false);
        });

        it('clears the select for an empty value', () => {
            select.value = '3';
            expect(selectAccountValue(select, ALL, null)).toBe(true);
            expect(select.value).toBe('');
        });

        it('reports an id it cannot resolve at all', () => {
            expect(selectAccountValue(select, ALL, 99)).toBe(false);
            expect(select.value).toBe('');
        });
    });
});

// ── the transaction form ───────────────────────────────────────────

describe('transaction form account pickers', () => {
    function mountForm() {
        document.body.innerHTML = `
            <select id="transaction-account"></select>
            <select id="transfer-to-account"></select>`;
    }

    function makeModule() {
        const mod = Object.create(TransactionsModule.prototype);
        mod.app = { accounts: ALL, categories: [], categoryTree: [] };
        return mod;
    }

    beforeEach(() => mountForm());

    it('offers only open accounts for a new transaction', () => {
        makeModule().populateTransactionModalDropdowns();

        expect(values(document.getElementById('transaction-account'))).toEqual(['', '1', '3']);
        expect(values(document.getElementById('transfer-to-account'))).toEqual(['', '1', '3']);
    });

    it('keeps a closed account selected across a rebuild when a transaction in it is being edited', () => {
        const mod = makeModule();
        const select = document.getElementById('transaction-account');
        mod.populateTransactionModalDropdowns();
        selectAccountValue(select, ALL, 2);

        mod.populateTransactionModalDropdowns();

        expect(select.value).toBe('2');
        expect(select.querySelector('option[value="2"]').textContent).toBe('Old Halifax (closed)');
        expect(values(select)).not.toContain('4');
    });

    it('inline account editor lists open accounts plus the row\'s own closed one', () => {
        const mod = makeModule();
        const cell = document.createElement('td');

        mod.createAccountEditor(cell, 2);

        const select = cell.querySelector('select');
        expect(values(select)).toEqual(['1', '2', '3']);
        expect(select.value).toBe('2');
    });
});

// ── the accounts page ──────────────────────────────────────────────

describe('accounts page closed section', () => {
    function mountPage() {
        document.body.innerHTML = `
            <div id="accounts-assets-section" class="accounts-section">
                <div id="accounts-assets-grid"></div>
            </div>
            <div id="accounts-liabilities-section" class="accounts-section">
                <div id="accounts-liabilities-grid"></div>
            </div>
            <details id="accounts-closed-section" class="accounts-section" style="display: none;">
                <summary><span id="accounts-closed-title"></span></summary>
                <div id="accounts-closed-grid"></div>
            </details>`;
    }

    function makeModule(accounts) {
        const mod = Object.create(AccountsModule.prototype);
        mod.app = { accounts, settings: {} };
        mod.getPrimaryCurrency = () => 'GBP';
        mod.getAccountsViewMode = () => 'grid';
        mod.getAccountsDisplayConfig = () => ({ attributes: { sparkline: false }, order: [], sort: { field: 'name', direction: 'asc' } });
        mod.sortAccountsForDisplay = (list) => list;
        mod.buildAccountRowColumns = () => ({ full: '', mobile: '' });
        mod.loadAccountSparklines = vi.fn();
        mod.renderAccountCard = (account) => `<div class="account-card" data-account-id="${account.id}"></div>`;
        return mod;
    }

    const idsIn = (gridId) => Array.from(document.querySelectorAll(`#${gridId} [data-account-id]`)).map(el => el.dataset.accountId);

    beforeEach(() => mountPage());

    it('moves closed accounts into their own section, out of assets and liabilities', () => {
        makeModule(ALL).renderAccountsPage(ALL);

        expect(idsIn('accounts-assets-grid')).toEqual(['1']);
        expect(idsIn('accounts-liabilities-grid')).toEqual(['3']);
        expect(idsIn('accounts-closed-grid')).toEqual(['2', '4']);
        expect(document.getElementById('accounts-closed-section').style.display).toBe('block');
        expect(document.getElementById('accounts-closed-title').textContent).toBe('Closed accounts (2)');
    });

    it('hides the closed section again when nothing is closed', () => {
        // Left open by a previous render that had closed accounts
        document.getElementById('accounts-closed-section').style.display = 'block';
        document.getElementById('accounts-closed-grid').innerHTML = '<div data-account-id="2"></div>';

        makeModule([CURRENT, VISA]).renderAccountsPage([CURRENT, VISA]);

        expect(document.getElementById('accounts-closed-section').style.display).toBe('none');
        expect(idsIn('accounts-closed-grid')).toEqual([]);
    });
});

// ── the form control ───────────────────────────────────────────────

describe('account form closed control', () => {
    function mountControl() {
        document.body.innerHTML = `
            <div id="account-closed-group">
                <input type="checkbox" id="account-closed">
                <small id="account-closed-hint" style="display: none;"></small>
            </div>`;
    }

    function makeModule() {
        const mod = Object.create(AccountsModule.prototype);
        mod.formatCurrency = (amount, currency) => `${currency} ${Number(amount).toFixed(2)}`;
        return mod;
    }

    beforeEach(() => mountControl());

    it('ticks the box for a closed account and shows no hint', () => {
        makeModule().syncClosedControl(OLD_SAVINGS);

        expect(document.getElementById('account-closed').checked).toBe(true);
        expect(document.getElementById('account-closed-hint').style.display).toBe('none');
    });

    it('warns before submit when an open account is not at zero', () => {
        makeModule().syncClosedControl({ ...CURRENT, balance: 12.34 });

        const hint = document.getElementById('account-closed-hint');
        expect(document.getElementById('account-closed').checked).toBe(false);
        expect(hint.style.display).toBe('block');
        expect(hint.textContent).toContain('GBP 12.34');
    });

    it('shows no hint for an open account already at zero', () => {
        makeModule().syncClosedControl({ ...CURRENT, balance: 0 });

        expect(document.getElementById('account-closed-hint').style.display).toBe('none');
    });

    it('hides the whole control for a new account, which cannot be born closed', () => {
        makeModule().syncClosedControl(null);

        expect(document.getElementById('account-closed-group').style.display).toBe('none');
        expect(document.getElementById('account-closed').checked).toBe(false);
    });
});
