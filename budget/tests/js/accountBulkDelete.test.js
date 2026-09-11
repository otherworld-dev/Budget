/**
 * Bulk account delete (#381).
 *
 * A mis-mapped import column creates one account per distinct value — a date
 * column gives one per day — and the only way out was deleting them one at a
 * time, two confirmations each, against a 10-calls-a-minute rate limit on the
 * delete endpoint. Selecting them and deleting in one go is the way out.
 *
 * The destructive half is kept a separate decision. The first pass removes only
 * the accounts that are already empty; anything still holding transactions
 * comes back named and counted, and a second dialog asks about those ledgers
 * specifically before anything in them is touched.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count, params = {}) =>
        String(count === 1 ? singular : plural)
            .replace(/%n/g, count)
            .replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(true)),
    promptDialog: vi.fn(() => Promise.resolve(null)),
    alertDialog: vi.fn(() => Promise.resolve()),
}));

import AccountsModule from '../../src/modules/accounts/AccountsModule.js';
import { confirmDialog } from '../../src/utils/dialogs.js';
import { showSuccess, showError } from '../../src/utils/notifications.js';

const BULK_URL = '/apps/budget/api/accounts/bulk-delete';

function mountPage() {
    document.body.innerHTML = `
        <div id="accounts-bulk-toolbar" style="display: none;">
            <span id="accounts-bulk-count"></span>
            <button id="accounts-clear-selection-btn"></button>
            <button id="accounts-bulk-delete-btn"></button>
        </div>
        <div id="accounts-assets-grid"></div>
    `;
}

function makeModule(accounts = []) {
    const mod = Object.create(AccountsModule.prototype);
    mod.app = { accounts, loadDashboard: vi.fn(async () => {}) };
    mod.accounts = accounts;
    mod.loadAccounts = vi.fn(async () => {});
    mod.loadInitialData = vi.fn(async () => {});
    mod.selectedAccountIds = new Set();
    return mod;
}

/** Queue of fetch responses, oldest first. */
function respondWith(...payloads) {
    const fetchMock = vi.fn(async () => {
        const body = payloads.shift() ?? {};
        return { ok: true, status: 200, json: async () => body };
    });
    global.fetch = fetchMock;
    return fetchMock;
}

const bodyOf = (fetchMock, call) => JSON.parse(fetchMock.mock.calls[call][1].body);

beforeEach(() => {
    mountPage();
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    confirmDialog.mockResolvedValue(true);
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.clearAllMocks();
});

describe('selection', () => {
    it('puts a checkbox carrying the account id on every card', () => {
        const mod = makeModule();
        const html = mod.renderAccountSelectCheckbox(7);

        document.getElementById('accounts-assets-grid').innerHTML = html;
        const box = document.querySelector('.account-select-checkbox');
        expect(box).not.toBeNull();
        expect(box.dataset.accountId).toBe('7');
        expect(box.checked).toBe(false);
    });

    it('renders an already-selected account as ticked, so a re-render keeps the selection', () => {
        const mod = makeModule();
        mod.selectedAccountIds.add(7);

        document.getElementById('accounts-assets-grid').innerHTML = mod.renderAccountSelectCheckbox(7);
        expect(document.querySelector('.account-select-checkbox').checked).toBe(true);
    });

    it('shows the toolbar with a count only while something is selected', () => {
        const mod = makeModule();
        const toolbar = document.getElementById('accounts-bulk-toolbar');

        mod.updateBulkAccountActions();
        expect(toolbar.style.display).toBe('none');

        mod.selectedAccountIds.add(1);
        mod.selectedAccountIds.add(2);
        mod.updateBulkAccountActions();

        expect(toolbar.style.display).toBe('flex');
        expect(document.getElementById('accounts-bulk-count').textContent).toBe('2 selected');
    });

    it('clearing the selection empties the set and unticks the boxes', () => {
        const mod = makeModule();
        mod.selectedAccountIds.add(1);
        document.getElementById('accounts-assets-grid').innerHTML =
            '<input type="checkbox" class="account-select-checkbox" data-account-id="1" checked>';

        mod.clearAccountSelection();

        expect(mod.selectedAccountIds.size).toBe(0);
        expect(document.querySelector('.account-select-checkbox').checked).toBe(false);
        expect(document.getElementById('accounts-bulk-toolbar').style.display).toBe('none');
    });

    /**
     * The card and the row are both clickable. Ticking a box used to open the
     * account behind it, which replaces the accounts view — so the second tick
     * of a multi-account selection had nothing left to tick. Caught in a real
     * browser, not here, so it gets a test.
     */
    it('ticking a box does not open the account behind the card', () => {
        const mod = makeModule();
        mod.showAccountDetails = vi.fn();
        document.getElementById('accounts-assets-grid').innerHTML = `
            <div class="account-card" data-account-id="5">
                ${mod.renderAccountSelectCheckbox(5)}
                <span class="account-name">Current</span>
            </div>`;

        mod.setupAccountCardClickHandlers();

        document.querySelector('.account-select-checkbox').click();
        expect(mod.showAccountDetails).not.toHaveBeenCalled();

        // The rest of the card still opens it.
        document.querySelector('.account-name').click();
        expect(mod.showAccountDetails).toHaveBeenCalledWith(5);
    });
});

describe('bulkDeleteAccounts', () => {
    it('deletes empty accounts in one request rather than one per account', async () => {
        const mod = makeModule();
        mod.selectedAccountIds = new Set([1, 2, 3]);
        const fetchMock = respondWith({ deleted: [1, 2, 3], blocked: [], errors: [], deletedTransactions: 0 });

        await mod.bulkDeleteAccounts();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe(BULK_URL);
        expect(bodyOf(fetchMock, 0)).toEqual({ ids: [1, 2, 3], deleteTransactions: false });
        expect(showSuccess).toHaveBeenCalledWith(expect.stringContaining('3'));
    });

    it('asks a second time about the accounts that still hold transactions, naming and counting them', async () => {
        const mod = makeModule();
        mod.selectedAccountIds = new Set([1, 2, 3]);
        const fetchMock = respondWith(
            {
                deleted: [1],
                blocked: [
                    { id: 2, name: '2026-01-07', transactionCount: 17 },
                    { id: 3, name: '2026-01-08', transactionCount: 5 },
                ],
                errors: [],
                deletedTransactions: 0,
            },
            { deleted: [2, 3], blocked: [], errors: [], deletedTransactions: 22 },
        );

        await mod.bulkDeleteAccounts();

        // Second dialog names the accounts and totals their rows.
        const second = confirmDialog.mock.calls[1][0];
        expect(second).toContain('2026-01-07');
        expect(second).toContain('2026-01-08');
        expect(second).toContain('22');
        expect(confirmDialog.mock.calls[1][1]).toEqual({ destructive: true });

        // Second request covers only the blocked ids.
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(bodyOf(fetchMock, 1)).toEqual({ ids: [2, 3], deleteTransactions: true });
    });

    it('leaves the transactions alone when the second question is declined', async () => {
        const mod = makeModule();
        mod.selectedAccountIds = new Set([1, 2]);
        const fetchMock = respondWith({
            deleted: [1],
            blocked: [{ id: 2, name: 'Has rows', transactionCount: 4 }],
            errors: [],
            deletedTransactions: 0,
        });
        confirmDialog.mockResolvedValueOnce(true).mockResolvedValueOnce(false);

        await mod.bulkDeleteAccounts();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        // The empty account really did go, so say so rather than reporting nothing.
        expect(showSuccess).toHaveBeenCalledWith(expect.stringContaining('1'));
    });

    it('does nothing at all when the first confirmation is declined', async () => {
        const mod = makeModule();
        mod.selectedAccountIds = new Set([1]);
        const fetchMock = respondWith({});
        confirmDialog.mockResolvedValue(false);

        await mod.bulkDeleteAccounts();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('ignores an empty selection', async () => {
        const mod = makeModule();
        const fetchMock = respondWith({});

        await mod.bulkDeleteAccounts();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(confirmDialog).not.toHaveBeenCalled();
    });

    it('reports per-account failures without hiding what did get deleted', async () => {
        const mod = makeModule();
        mod.selectedAccountIds = new Set([1, 2]);
        respondWith({
            deleted: [1],
            blocked: [],
            errors: [{ id: 2, error: 'Account not found' }],
            deletedTransactions: 0,
        });

        await mod.bulkDeleteAccounts();

        expect(showSuccess).toHaveBeenCalledWith(expect.stringContaining('1'));
        expect(showError).toHaveBeenCalledWith(expect.stringContaining('Account not found'));
    });

    it('drops deleted accounts from the selection and refreshes the page', async () => {
        const mod = makeModule();
        mod.selectedAccountIds = new Set([1, 2]);
        respondWith({
            deleted: [1],
            blocked: [{ id: 2, name: 'Has rows', transactionCount: 4 }],
            errors: [],
            deletedTransactions: 0,
        });
        confirmDialog.mockResolvedValueOnce(true).mockResolvedValueOnce(false);

        await mod.bulkDeleteAccounts();

        expect([...mod.selectedAccountIds]).toEqual([2]);
        expect(mod.loadAccounts).toHaveBeenCalled();
        expect(mod.loadInitialData).toHaveBeenCalled();
    });

    it('surfaces a failed request instead of reporting success', async () => {
        const mod = makeModule();
        mod.selectedAccountIds = new Set([1]);
        global.fetch = vi.fn(async () => ({
            ok: false,
            status: 400,
            json: async () => ({ error: 'Nope' }),
        }));

        await mod.bulkDeleteAccounts();

        expect(showError).toHaveBeenCalled();
        expect(showSuccess).not.toHaveBeenCalled();
    });
});
