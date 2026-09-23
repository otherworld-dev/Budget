/**
 * A view whose first fetch fails.
 *
 * Several views wired their buttons only after the data came back, and the
 * `throw` on an HTTP error jumped straight past that: one failed first load
 * left Add, filters and tabs dead until a full page reload. The account
 * register instead fell through to "This account doesn't have any
 * transactions yet", which is simply wrong when the request failed.
 *
 * Listeners are now bound before fetching, and a failed load puts an inline
 * error with a Retry button where the list would be.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import BillsModule from '../../src/modules/bills/BillsModule.js';
import SavingsModule from '../../src/modules/savings/SavingsModule.js';
import AccountsModule from '../../src/modules/accounts/AccountsModule.js';
import { showLoadError } from '../../src/utils/loading.js';

const failingFetch = () => vi.fn().mockResolvedValue({ ok: false, status: 500, json: async () => ({}) });

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('showLoadError', () => {
    it('replaces the list with a message and a working Retry button', () => {
        document.body.innerHTML = '<div id="list"><div class="stale">old</div></div>';
        const retry = vi.fn();

        showLoadError('list', 'Failed to load things', retry);

        const list = document.getElementById('list');
        expect(list.querySelector('.stale')).toBeNull();
        expect(list.querySelector('[role="alert"]').textContent).toContain('Failed to load things');
        list.querySelector('button').click();
        expect(retry).toHaveBeenCalledTimes(1);
    });

    it('fills a table body with one full-width row', () => {
        document.body.innerHTML = '<table><thead><tr><th>a</th><th>b</th><th>c</th></tr></thead><tbody id="rows"></tbody></table>';

        showLoadError('rows', 'Nope', () => {});

        const cell = document.querySelector('#rows tr td');
        expect(cell.colSpan).toBe(3);
        expect(cell.querySelector('.budget-load-error')).not.toBeNull();
    });
});

describe('Bills view, first load fails', () => {
    function mount() {
        document.body.innerHTML = '<div id="bills-list"></div><div id="empty-bills" style="display: flex"></div>';
    }

    it('binds its buttons anyway and shows an inline error with Retry', async () => {
        mount();
        const mod = Object.create(BillsModule.prototype);
        mod.app = { settings: {} };
        mod._eventsSetup = false;
        mod.setupBillsEventListeners = vi.fn();
        global.fetch = failingFetch();
        vi.spyOn(console, 'error').mockImplementation(() => {});

        await mod.loadBillsView();

        expect(mod.setupBillsEventListeners).toHaveBeenCalledTimes(1);
        expect(document.querySelector('#bills-list .budget-load-error')).not.toBeNull();
        expect(document.getElementById('empty-bills').style.display).toBe('none');

        // Retry runs the load again, without binding the listeners twice
        const reload = vi.spyOn(mod, 'loadBillsView');
        document.querySelector('#bills-list .budget-load-error button').click();
        expect(reload).toHaveBeenCalledTimes(1);
        await reload.mock.results[0].value;
        expect(mod.setupBillsEventListeners).toHaveBeenCalledTimes(1);
    });
});

describe('Savings view, first load fails', () => {
    it('binds its buttons anyway and shows an inline error with Retry', async () => {
        document.body.innerHTML = '<div id="goals-list"></div><div id="empty-goals"></div>';
        const mod = Object.create(SavingsModule.prototype);
        mod.app = { settings: {} };
        mod._eventsSetup = false;
        mod.setupGoalsEventListeners = vi.fn();
        global.fetch = failingFetch();
        vi.spyOn(console, 'error').mockImplementation(() => {});

        await mod.loadSavingsGoalsView();

        expect(mod.setupGoalsEventListeners).toHaveBeenCalledTimes(1);
        const error = document.querySelector('#goals-list .budget-load-error');
        expect(error).not.toBeNull();
        expect(error.querySelector('button')).not.toBeNull();
    });
});

describe('Account register, transactions fail to load', () => {
    it('says the load failed instead of "no transactions yet"', async () => {
        document.body.innerHTML = '<table><thead><tr><th></th><th></th></tr></thead><tbody id="account-transactions-body"></tbody></table>';
        const mod = Object.create(AccountsModule.prototype);
        mod.app = { categories: [], accounts: [] };
        mod.accountCurrentPage = 1;
        mod.accountRowsPerPage = 25;
        mod.accountFilters = {};
        global.fetch = vi.fn().mockRejectedValue(new Error('network down'));
        vi.spyOn(console, 'error').mockImplementation(() => {});

        await mod.loadAccountTransactions(4);

        const body = document.getElementById('account-transactions-body');
        expect(body.textContent).not.toContain("doesn't have any transactions");
        expect(body.querySelector('.budget-load-error')).not.toBeNull();
        expect(body.querySelector('.budget-load-error button')).not.toBeNull();
    });
});
