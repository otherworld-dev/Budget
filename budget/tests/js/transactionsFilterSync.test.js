/**
 * Filter panel vs. applied filters (#361).
 *
 * The panel's controls were only written from `app.transactionFilters` on
 * the panel's hidden-to-shown toggle, and even then only for values that
 * were truthy — so arriving via a programmatic navigation (a category's
 * "View All Transactions", a chart drill-down, a linked-transaction jump)
 * left the dropdowns showing one thing while the query applied another.
 * Worse, updateFilters() reads every control back from the DOM on any
 * change, so a stale control silently re-entered the applied filters the
 * moment the user touched any other one. The sync must therefore
 * clear-or-set EVERY control — without firing the controls' own change
 * events, which would run updateFilters() against a half-synced panel.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';

function mountFilterPanel(display = 'block') {
    document.body.innerHTML = `
        <div id="transactions-filters" style="display: ${display}">
            <select id="filter-account"><option value=""></option><option value="1"></option><option value="2"></option></select>
            <select id="filter-category"><option value=""></option><option value="1"></option><option value="2"></option><option value="7"></option></select>
            <select id="filter-type"><option value=""></option><option value="credit"></option><option value="debit"></option><option value="split"></option></select>
            <select id="filter-status"><option value=""></option><option value="pending"></option><option value="cleared"></option></select>
            <select id="filter-reconciled"><option value=""></option><option value="yes"></option><option value="no"></option></select>
            <input id="filter-date-from" type="text">
            <input id="filter-date-to" type="text">
            <input id="filter-created-from" type="text">
            <input id="filter-created-to" type="text">
            <input id="filter-amount-min" type="number">
            <input id="filter-amount-max" type="number">
            <input id="filter-search" type="text">
        </div>`;
}

function makeModule(transactionFilters) {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = {
        transactionFilters,
        currentPage: 1,
        loadTransactions: vi.fn(),
    };
    mod.selectedFilterTags = new Set();
    mod.populateFilterTagsDropdown = vi.fn();
    mod.loadFilterTags = vi.fn().mockResolvedValue();
    mod._maybeCheckReconcileSession = vi.fn();
    return mod;
}

const el = (id) => document.getElementById(id);

describe('syncFilterControlsFromState', () => {
    beforeEach(() => mountFilterPanel());

    it('clears every stale control the applied filters do not carry', () => {
        // Panel left over from earlier browsing…
        el('filter-type').value = 'debit';
        el('filter-status').value = 'pending';
        el('filter-amount-min').value = '5';
        el('filter-search').value = 'coffee';
        el('filter-account').value = '2';

        // …then a category drill-down replaces the applied filters wholesale.
        const mod = makeModule({
            category: '7', type: '', dateFrom: '2026-08-01', dateTo: '2026-08-28', account: '',
        });
        mod.selectedFilterTags = new Set([3]);

        mod.syncFilterControlsFromState();

        expect(el('filter-type').value).toBe('');
        expect(el('filter-status').value).toBe('');
        expect(el('filter-amount-min').value).toBe('');
        expect(el('filter-search').value).toBe('');
        expect(el('filter-account').value).toBe('');
        expect(el('filter-category').value).toBe('7');
        expect(el('filter-date-from').value).toBe('2026-08-01');
        expect(el('filter-date-to').value).toBe('2026-08-28');
        expect(mod.selectedFilterTags.size).toBe(0);
    });

    it('shows the first id of a comma id-list category filter', () => {
        const mod = makeModule({ category: '1,2,7', type: '' });

        mod.syncFilterControlsFromState();

        expect(el('filter-category').value).toBe('1');
    });

    it('suppresses flatpickr change events so updateFilters cannot re-enter mid-sync', () => {
        // Real flatpickr's clear()/setDate() default to firing 'change', and
        // the date filters' change listeners run updateFilters() -> a fetch.
        const from = el('filter-date-from');
        const to = el('filter-date-to');
        from._flatpickr = { clear: vi.fn(), setDate: vi.fn() };
        to._flatpickr = { clear: vi.fn(), setDate: vi.fn() };

        const mod = makeModule({ dateFrom: '2026-08-01', dateTo: '' });
        mod.syncFilterControlsFromState();

        expect(from._flatpickr.setDate).toHaveBeenCalledWith('2026-08-01', false);
        expect(to._flatpickr.clear).toHaveBeenCalledWith(false);
    });

    it('does not render the tag dropdown itself', () => {
        // Rendering stays owned by the async tag loader (refreshFilterTags /
        // populateFilterDropdowns' tail) — a sync render here would draw from
        // stale tag data and stack up document-level click listeners.
        const mod = makeModule({ tagIds: [4] });

        mod.syncFilterControlsFromState();

        expect(mod.populateFilterTagsDropdown).not.toHaveBeenCalled();
        expect([...mod.selectedFilterTags]).toEqual([4]);
    });

    it('skips the DOM but still applies the tag state when the panel is hidden', () => {
        mountFilterPanel('none');
        el('filter-type').value = 'debit';

        const mod = makeModule({ type: '', tagIds: [] });
        mod.selectedFilterTags = new Set([3]);

        mod.syncFilterControlsFromState();

        // Hidden controls re-sync on the next panel open; touching them now
        // is wasted work (flatpickr redraws an off-screen calendar).
        expect(el('filter-type').value).toBe('debit');
        // But the tag set feeds updateFilters() regardless of visibility.
        expect(mod.selectedFilterTags.size).toBe(0);
    });
});

describe('populateFilterDropdowns', () => {
    beforeEach(() => mountFilterPanel());

    it('clears a stale type selection when the applied filters have none', () => {
        el('filter-type').value = 'debit';

        // accounts/categories are prototype getters proxying this.app
        const mod = makeModule({ category: '7', type: '' });
        mod.app.accounts = [{ id: 1, name: 'Current' }];
        mod.app.categories = [{ id: 7, name: 'Phone' }];
        mod.app.categoryTree = [{ id: 7, name: 'Phone', children: [] }];

        mod.populateFilterDropdowns();

        expect(el('filter-type').value).toBe('');
    });
});

describe('updateFilters', () => {
    beforeEach(() => mountFilterPanel());

    it('keeps a multi-id category drill-down intact while its first id is still selected', () => {
        // The single-value select can only display the first id of '1,2,7'
        // (a parent plus its subcategories, #317); as long as the user has
        // not picked a different category, the full applied list survives.
        const mod = makeModule({ category: '1,2,7' });
        el('filter-category').value = '1';

        mod.updateFilters();

        expect(mod.app.transactionFilters.category).toBe('1,2,7');
    });

    it('narrows to the category the user actually picked', () => {
        const mod = makeModule({ category: '1,2,7' });
        el('filter-category').value = '2';

        mod.updateFilters();

        expect(mod.app.transactionFilters.category).toBe('2');
    });
});

describe('clearFilters', () => {
    beforeEach(() => mountFilterPanel());

    it('clears the applied filters and the panel in one pass', () => {
        el('filter-type').value = 'debit';
        el('filter-search').value = 'coffee';

        const mod = makeModule({ type: 'debit', search: 'coffee', tagIds: [3] });
        mod.selectedFilterTags = new Set([3]);

        mod.clearFilters();

        expect(mod.app.transactionFilters).toEqual({});
        expect(el('filter-type').value).toBe('');
        expect(el('filter-search').value).toBe('');
        expect(mod.selectedFilterTags.size).toBe(0);
        expect(mod.app.currentPage).toBe(1);
        expect(mod.app.loadTransactions).toHaveBeenCalled();
    });
});
