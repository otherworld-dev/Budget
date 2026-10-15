/**
 * Starting a reconciliation from an account's page.
 *
 * The account page opens the Transactions view and enters the session
 * before anything has filled the transactions filter, so setting the
 * account filter matched no option and was dropped: the reconcile list
 * showed every account's transactions, and ticking one from another
 * account was ignored by the server.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';

const STATE = {
    session: { accountId: 7, statementDate: '2026-09-30' },
    tickedIds: [],
    difference: 0,
    isBalanced: true,
};

function mount(accountOptions = '') {
    document.body.innerHTML = `
        <select id="filter-account">${accountOptions}</select>
        <input type="text" id="filter-date-to">
        <select id="filter-reconciled"><option value="">All</option><option value="no">No</option></select>
        <div id="reconcile-panel" style="display: block;"></div>`;
}

function makeModule() {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = { accounts: [{ id: 3, name: 'Savings' }, { id: 7, name: 'Bills' }] };
    mod.renderReconcileBar = vi.fn();
    mod.populateFilterDropdowns = vi.fn(() => {
        document.getElementById('filter-account').innerHTML = '<option value="">All Accounts</option>'
            + mod.app.accounts.map(a => `<option value="${a.id}">${a.name}</option>`).join('');
    });
    mod.updateFilters = vi.fn(() => {
        mod.filteredAccount = document.getElementById('filter-account').value;
    });
    return mod;
}

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
});

afterEach(() => {
    delete global.OC;
    document.body.innerHTML = '';
});

describe('entering a reconciliation session', () => {
    it('fills the account filter first when it has no options, so the list is the one account', async () => {
        mount();
        const mod = makeModule();

        await mod.enterReconcileSession(STATE);

        expect(mod.populateFilterDropdowns).toHaveBeenCalled();
        expect(mod.filteredAccount).toBe('7');
    });

    it('leaves an already filled filter alone', async () => {
        mount('<option value="">All Accounts</option><option value="3">Savings</option><option value="7">Bills</option>');
        const mod = makeModule();

        await mod.enterReconcileSession(STATE);

        expect(mod.populateFilterDropdowns).not.toHaveBeenCalled();
        expect(mod.filteredAccount).toBe('7');
    });
});
