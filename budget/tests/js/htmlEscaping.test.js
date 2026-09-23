/**
 * Names the user (or an imported file) controls must render as text.
 *
 * Several lists built their markup with innerHTML and dropped a category,
 * vendor or account name straight in, so a name like `<img src=x onerror=...>`
 * became an element with a live handler. The opposite mistake shows up too:
 * @nextcloud/l10n escapes t() placeholders by itself, so a value escaped
 * before being passed in came out as "B&amp;Q".
 *
 * The l10n mock below escapes placeholders the way the real library does
 * (unless `{ escape: false }` is passed), so double escaping is visible here.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => {
    const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    const fill = (text, params = {}, options = {}) => String(text).replace(/\{(\w+)\}/g,
        (m, k) => (k in params ? (options?.escape === false ? String(params[k]) : esc(params[k])) : m));
    return {
        translate: (_app, text, params, _count, options) => fill(text, params, options),
        translatePlural: (_app, singular, plural, count, params, options) =>
            fill(count === 1 ? singular : plural, params, options),
        getLanguage: () => 'en',
        getLocale: () => 'en',
    };
});

import ReportsModule from '../../src/modules/reports/ReportsModule.js';
import ForecastModule from '../../src/modules/forecast/ForecastModule.js';
import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';
import AccountsModule from '../../src/modules/accounts/AccountsModule.js';
import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import { escapeHtml } from '../../src/utils/dom.js';

const EVIL = '<img src=x onerror="window.__xss=1">';

beforeEach(() => {
    window.__xss = undefined;
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('escapeHtml', () => {
    it('escapes quotes, so the result is safe inside an attribute', () => {
        expect(escapeHtml('a"b\'c')).toBe('a&quot;b&#39;c');
    });

    it('stringifies numbers instead of throwing, and keeps 0', () => {
        expect(escapeHtml(0)).toBe('0');
        expect(escapeHtml(4.5)).toBe('4.5');
        expect(escapeHtml(null)).toBe('');
        expect(escapeHtml(undefined)).toBe('');
    });
});

describe('user-controlled names render as text', () => {
    it('reports: a vendor name is not parsed as markup', () => {
        document.body.innerHTML = '<table id="report-vendors-table"><tbody></tbody></table>';
        const mod = Object.create(ReportsModule.prototype);
        mod.formatCurrency = (v) => String(v);

        mod.renderReportVendorTable([{ name: EVIL, total: 10, count: 1 }], 'GBP');

        const cell = document.querySelector('#report-vendors-table td');
        expect(document.querySelector('#report-vendors-table img')).toBeNull();
        expect(cell.textContent).toBe(EVIL);
    });

    it('reports: a category name in the spending table is not parsed as markup', () => {
        document.body.innerHTML = '<table id="report-categories-table"><tbody></tbody></table>';
        const mod = Object.create(ReportsModule.prototype);
        mod.formatCurrency = (v) => String(v);

        mod.renderReportCategoryTable([{ name: EVIL, total: 10, count: 1, color: '#123456' }], 10, 'GBP');

        expect(document.querySelector('#report-categories-table img')).toBeNull();
        expect(document.querySelector('#report-categories-table td').textContent).toContain(EVIL);
    });

    it('forecast: a category name in the trends list is not parsed as markup', () => {
        document.body.innerHTML = '<div id="category-trends-list"></div>';
        const mod = Object.create(ForecastModule.prototype);
        mod.forecastCurrency = 'GBP';
        mod.formatCurrency = (v) => String(v);

        mod.displayCategoryTrends([{ name: EVIL, avgMonthly: 5, trend: 'up' }]);

        expect(document.querySelector('#category-trends-list img')).toBeNull();
        expect(document.querySelector('.category-name').textContent).toBe(EVIL);
    });

    it('categories: the tree shows a category and sharer name as text', () => {
        const mod = Object.create(CategoriesModule.prototype);
        mod.app = { categories: [], transactions: [] };
        mod.buildCategoryTransactionCountMap = () => ({});
        document.body.innerHTML = `<div id="tree">${mod.renderCategoryNodes([
            { id: 1, name: EVIL, type: 'expense', _shared: true, _canWrite: false, _sharedByName: EVIL },
        ])}</div>`;

        expect(document.querySelector('#tree img')).toBeNull();
        expect(document.querySelector('.category-name').textContent).toBe(EVIL);
        expect(document.querySelector('.category-shared-badge').textContent).toContain(EVIL);
    });

    it('transactions: account filter options show the account name as text', () => {
        document.body.innerHTML = '<select id="filter-account"></select>';
        const mod = Object.create(TransactionsModule.prototype);
        mod.app = { accounts: [{ id: 3, name: EVIL }] };
        mod.syncFilterControlsFromState = () => {};

        mod.populateFilterDropdowns();

        const options = document.querySelectorAll('#filter-account option');
        expect(options[1].textContent).toBe(EVIL);
        expect(document.querySelector('#filter-account img')).toBeNull();
    });
});

describe('names passed through t() are escaped once, not twice', () => {
    it('account register: the transfer badge reads "B&Q", not "B&amp;Q"', () => {
        document.body.innerHTML = '<table><tbody id="account-transactions-body"></tbody></table>';
        const mod = Object.create(AccountsModule.prototype);
        mod.app = { categories: [], accounts: [], loadAndDisplayTransactionTags: vi.fn() };
        mod.currentAccount = { id: 1, currency: 'GBP' };
        mod.getPrimaryCurrency = () => 'GBP';
        mod.accountRunningBalances = null;
        mod.formatCurrency = (v) => String(v);
        mod.formatDate = (d) => d;
        mod.accountTransactions = [{
            id: 5, date: '2026-01-01', description: 'Move', type: 'debit', amount: 10,
            linkedTransactionId: 6, linkedAccountId: 2, linkedAccountName: 'B&Q <b>',
        }];

        mod.renderAccountTransactions();

        const badge = document.querySelector('.linked-indicator');
        expect(badge.textContent).toContain('B&Q <b>');
        expect(badge.textContent).not.toContain('&amp;');
        expect(badge.getAttribute('title')).toContain('B&Q <b>');
        expect(badge.querySelector('b')).toBeNull();
    });
});
