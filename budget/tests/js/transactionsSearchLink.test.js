/**
 * Arriving from a Nextcloud unified search result.
 *
 * A result links to #/transactions?search=<term>. The link only typed the
 * term into the search box of the Filters panel, which starts closed, so the
 * list showed every transaction and nothing said why. The term is now
 * applied as the list's search, and the panel opens to show it.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';

beforeEach(() => {
    document.body.innerHTML = `
        <button id="toggle-filters-btn"></button>
        <div id="transactions-filters" style="display: none">
            <select id="filter-account"></select>
            <select id="filter-category"></select>
            <select id="filter-type"><option value=""></option></select>
            <select id="filter-status"><option value=""></option></select>
            <select id="filter-reconciled"><option value=""></option></select>
            <input id="filter-date-from" type="text">
            <input id="filter-date-to" type="text">
            <input id="filter-created-from" type="text">
            <input id="filter-created-to" type="text">
            <input id="filter-amount-min" type="number">
            <input id="filter-amount-max" type="number">
            <input id="filter-search" type="search">
        </div>`;
});

afterEach(() => {
    document.body.innerHTML = '';
});

function makeModule(transactionFilters = {}) {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = { transactionFilters, currentPage: 3, accounts: [], categories: [] };
    mod.selectedFilterTags = new Set();
    mod.loadFilterTags = vi.fn().mockResolvedValue();
    mod.populateFilterTagsDropdown = vi.fn();
    return mod;
}

describe('a link with a search term', () => {
    it('filters the list by it, from the first page', () => {
        const mod = makeModule();

        mod.applySearchLink('netflix');

        expect(mod.app.transactionFilters.search).toBe('netflix');
        expect(mod.app.currentPage).toBe(1);
    });

    it('opens the Filters panel with the term in the search box', () => {
        const mod = makeModule();

        mod.applySearchLink('netflix');

        expect(document.getElementById('transactions-filters').style.display).toBe('block');
        expect(document.getElementById('toggle-filters-btn').classList.contains('active')).toBe(true);
        expect(document.getElementById('filter-search').value).toBe('netflix');
    });

    it('leaves an open panel open', () => {
        document.getElementById('transactions-filters').style.display = 'block';
        const mod = makeModule();

        mod.applySearchLink('rent');

        expect(document.getElementById('transactions-filters').style.display).toBe('block');
        expect(document.getElementById('filter-search').value).toBe('rent');
    });
});
