/**
 * The Transactions search box filters as you type (#399, browser round 2,
 * csv bug 10).
 *
 * The debounced 'input' handler went only to type="text" and type="number"
 * controls. #filter-search is type="search", so it got the 'change' handler
 * alone and the list narrowed only on Enter or when the box lost focus.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) =>
        String(count === 1 ? singular : plural).replace(/%n/g, count),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';

function makeModule() {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = { transactionFilters: {}, currentPage: 1, loadTransactions: vi.fn() };
    mod.updateFilters = vi.fn();
    return mod;
}

function type(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = `
        <div id="transactions-filters">
            <select id="filter-type"><option value=""></option><option value="debit"></option></select>
            <input id="filter-amount-min" type="number">
            <input id="filter-search" type="search">
        </div>`;
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('Transactions search', () => {
    it('applies the search a moment after typing, without Enter', () => {
        const mod = makeModule();
        mod.setupTransactionEventListeners();

        type(document.getElementById('filter-search'), 'Trennscheibe');
        expect(mod.updateFilters).not.toHaveBeenCalled();

        vi.advanceTimersByTime(300);
        expect(mod.updateFilters).toHaveBeenCalledTimes(1);
    });

    it('waits for the typing to stop', () => {
        const mod = makeModule();
        mod.setupTransactionEventListeners();
        const search = document.getElementById('filter-search');

        type(search, 'Tr');
        vi.advanceTimersByTime(200);
        type(search, 'Tren');
        vi.advanceTimersByTime(200);
        expect(mod.updateFilters).not.toHaveBeenCalled();

        vi.advanceTimersByTime(100);
        expect(mod.updateFilters).toHaveBeenCalledTimes(1);
    });

    it('keeps number boxes debounced and selects immediate', () => {
        const mod = makeModule();
        mod.setupTransactionEventListeners();

        type(document.getElementById('filter-amount-min'), '10');
        vi.advanceTimersByTime(300);
        expect(mod.updateFilters).toHaveBeenCalledTimes(1);

        const select = document.getElementById('filter-type');
        select.value = 'debit';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        expect(mod.updateFilters).toHaveBeenCalledTimes(2);
    });
});
