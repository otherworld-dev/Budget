/**
 * Changing page in the Transactions list or an account's register (#399,
 * round 7). Above phone width the rows scroll in a box of their own
 * (.table-scroll), so the header row can stick to its top, and the box
 * kept its place from one page to the next: page 2 opened scrolled to
 * where page 1 was left, its first rows out of view. A different page,
 * rows per page, filters or sort now start at the top of the box; the
 * same page drawn again (an inline edit, a reload after a save) keeps
 * its place, and so does the sideways position.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => String(text),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/api.js', async (importOriginal) => ({
    ...(await importOriginal()),
    apiFetch: vi.fn(async () => ({ transactions: [{ id: 1 }], total: 1, totalPages: 3 })),
}));

import { showRowsOf } from '../../src/utils/rowsScroll.js';
import AccountsModule from '../../src/modules/accounts/AccountsModule.js';

const MAIN = fs.readFileSync(
    path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../src/main.js'),
    'utf8'
).replace(/\r\n/g, '\n');

/** jsdom has no layout, so the box's scroll position is a plain value here */
function scrollable(el) {
    let top = 0;
    let left = 0;
    Object.defineProperty(el, 'scrollTop', { get: () => top, set: (v) => { top = v; }, configurable: true });
    Object.defineProperty(el, 'scrollLeft', { get: () => left, set: (v) => { left = v; }, configurable: true });
    return el;
}

beforeEach(() => {
    document.body.innerHTML = `
        <div class="table-scroll" id="list-box"><table id="transactions-table"><tbody></tbody></table></div>
        <div class="table-scroll" id="register-box"><table id="account-transactions-table"><tbody id="account-transactions-body"></tbody></table></div>`;
    scrollable(document.getElementById('list-box'));
    scrollable(document.getElementById('register-box'));
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('showRowsOf', () => {
    const table = () => document.getElementById('transactions-table');
    const box = () => document.getElementById('list-box');

    it('puts the box back at the top when a different query is shown, keeping the sideways position', () => {
        showRowsOf(table(), 'page=1');
        box().scrollTop = 900;
        box().scrollLeft = 78;

        showRowsOf(table(), 'page=2');

        expect(box().scrollTop).toBe(0);
        expect(box().scrollLeft).toBe(78);
    });

    it('leaves the box where it is when the same query is shown again', () => {
        showRowsOf(table(), 'page=2');
        box().scrollTop = 900;

        showRowsOf(table(), 'page=2');

        expect(box().scrollTop).toBe(900);
    });

    it('keeps each list\'s query to itself', () => {
        const register = document.getElementById('account-transactions-table');
        showRowsOf(table(), 'list');
        showRowsOf(register, 'register');
        box().scrollTop = 900;

        showRowsOf(register, 'register, page 2');
        showRowsOf(table(), 'list');

        expect(box().scrollTop).toBe(900);
    });

    it('does nothing without a table or a box', () => {
        expect(() => showRowsOf(null, 'x')).not.toThrow();
        document.body.innerHTML = '<table id="bare"></table>';
        expect(() => showRowsOf(document.getElementById('bare'), 'x')).not.toThrow();
    });
});

describe('the Transactions list', () => {
    const body = (() => {
        const start = MAIN.indexOf('async loadTransactions(accountId = null) {');
        const end = MAIN.indexOf('\n    }\n', start);
        return MAIN.slice(start, end);
    })();

    it('shows each load\'s rows by its request, page, rows per page, filters and sort included', () => {
        expect(body).toMatch(/showRowsOf\(\s*document\.getElementById\('transactions-table'\),\s*url\s*\)/);
        // the request carries everything that picks the rows
        expect(body).toMatch(/limit=' \+ this\.rowsPerPage \+ '&page=' \+ this\.currentPage/);
        expect(body).toMatch(/params\.append\('sort'/);
        expect(body).toMatch(/buildTransactionFilterParams/);
    });

    it('does so after the rows are drawn', () => {
        expect(body.indexOf('showRowsOf(')).toBeGreaterThan(body.indexOf('this.renderEnhancedTransactionsTable()'));
    });

    it('leaves the box alone when the same page is only drawn again (an inline edit)', () => {
        const start = MAIN.indexOf('renderEnhancedTransactionsTable() {');
        const render = MAIN.slice(start, MAIN.indexOf('\n    }\n', start));
        expect(render).not.toMatch(/showRowsOf|scrollTop/);
    });
});

describe('an account\'s register', () => {
    const box = () => document.getElementById('register-box');

    function makeModule() {
        const mod = Object.create(AccountsModule.prototype);
        mod.app = { categories: [], accounts: [] };
        mod.accountCurrentPage = 1;
        mod.accountRowsPerPage = 50;
        mod.accountSort = { field: 'date', direction: 'desc' };
        mod.accountFilters = {};
        mod.renderAccountTransactions = vi.fn();
        mod.updateAccountPagination = vi.fn();
        return mod;
    }

    async function scrolledThenReloaded(change) {
        const mod = makeModule();
        await mod.loadAccountTransactions(4);
        box().scrollTop = 900;
        box().scrollLeft = 30;
        change(mod);
        await mod.loadAccountTransactions(mod.accountId ?? 4);
        return box();
    }

    it.each([
        ['the next page', (mod) => { mod.accountCurrentPage = 2; }],
        ['another number of rows per page', (mod) => { mod.accountRowsPerPage = 100; }],
        ['another sort', (mod) => { mod.accountSort = { field: 'amount', direction: 'asc' }; }],
        ['new filters', (mod) => { mod.accountFilters = { search: 'migros' }; }],
        ['another account', (mod) => { mod.accountId = 5; }],
    ])('starts %s at the top, keeping the sideways position', async (_label, change) => {
        const after = await scrolledThenReloaded(change);

        expect(after.scrollTop).toBe(0);
        expect(after.scrollLeft).toBe(30);
    });

    it('keeps its place when the same page is loaded again (after an edit)', async () => {
        const after = await scrolledThenReloaded(() => {});

        expect(after.scrollTop).toBe(900);
    });
});
