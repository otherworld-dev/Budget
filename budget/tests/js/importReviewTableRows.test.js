/**
 * The Review & Import step's table and the toast after an import (#399,
 * browser round 2, csv bugs 3-6).
 *
 * - With Description mapped to the Category column and the product mapped to
 *   Vendor/Payee, every row read just the category: the table had no payee
 *   column, although the preview response carries it.
 * - A 1000-line file said "Showing 50 of 50": the filter pass recounted the
 *   rows of the 50-row sample and wrote over "50 of 1000".
 * - A rule that rewrote the description but set no category showed up as
 *   "Rule: <name>" in the Category column, and Show uncategorized kept it,
 *   although the row is stored without a category.
 * - "Successfully imported 1 transactions".
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const l10n = vi.hoisted(() => ({
    translatePlural: (_app, singular, plural, count, params = {}) =>
        String(count === 1 ? singular : plural).replace(/%n/g, count)
            .replace(/\{(\w+)\}/g, (match, key) => (key in params ? params[key] : match)),
}));

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (match, key) => (key in params ? params[key] : match)),
    translatePlural: (...args) => l10n.translatePlural(...args),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import ImportModule from '../../src/modules/import/ImportModule.js';

function makeApp(categories = []) {
    return {
        data: {},
        accounts: [],
        categories,
        settings: {},
        getPrimaryCurrency: () => 'USD',
        loadTransactions: vi.fn(),
        loadAccounts: vi.fn(),
    };
}

function row(overrides = {}) {
    return { date: '2026-10-08', description: 'Modellbau / Renovation', amount: 9.95, type: 'debit', ...overrides };
}

function visibleRows() {
    return Array.from(document.querySelectorAll('#preview-table tbody tr'))
        .filter(tr => tr.style.display !== 'none');
}

function cell(tr, name) {
    return tr.querySelector(`[data-preview-cell="${name}"]`);
}

function info() {
    return document.getElementById('preview-info').textContent;
}

beforeEach(() => {
    globalThis.OC = { generateUrl: (url) => url, requestToken: 'tok' };
    document.body.innerHTML = `
        <input type="checkbox" id="show-duplicates" checked>
        <input type="checkbox" id="show-uncategorized" checked>
        <span id="preview-info"></span>
        <table id="preview-table"><thead><tr>
            <th></th><th></th>
            <th id="preview-th-payee" style="display: none;"></th>
            <th id="preview-th-notes" style="display: none;"></th>
            <th></th><th></th><th></th>
        </tr></thead><tbody></tbody></table>
    `;
});

afterEach(() => {
    document.body.innerHTML = '';
    delete globalThis.OC;
    vi.restoreAllMocks();
});

describe('the payee in the review table', () => {
    it('shows the payee column when rows carry a payee', () => {
        const mod = new ImportModule(makeApp());

        mod.showTransactionPreview([row({ vendor: 'Wolfcraft' }), row({ vendor: 'Bosch' })]);

        expect(document.getElementById('preview-th-payee').style.display).toBe('');
        const payees = visibleRows().map(tr => cell(tr, 'payee'));
        expect(payees.map(td => td.textContent.trim())).toEqual(['Wolfcraft', 'Bosch']);
        expect(payees.every(td => td.style.display === '')).toBe(true);
        expect(payees[0].getAttribute('title')).toBe('Wolfcraft');
    });

    it('keeps the column hidden when no row has a payee', () => {
        const mod = new ImportModule(makeApp());

        mod.showTransactionPreview([row(), row({ vendor: '' })]);

        expect(document.getElementById('preview-th-payee').style.display).toBe('none');
        expect(visibleRows().every(tr => cell(tr, 'payee').style.display === 'none')).toBe(true);
    });

    it('escapes the payee', () => {
        const mod = new ImportModule(makeApp());

        mod.showTransactionPreview([row({ vendor: '<b>Tom & Jerry</b>' })]);

        const td = cell(visibleRows()[0], 'payee');
        expect(td.textContent).toBe('<b>Tom & Jerry</b>');
        expect(td.querySelector('b')).toBeNull();
    });

    it('spans the empty-file row over every column', () => {
        const mod = new ImportModule(makeApp());

        mod.showTransactionPreview([]);

        expect(document.querySelector('#preview-table tbody td').getAttribute('colspan')).toBe('7');
    });
});

describe('how many rows the table shows', () => {
    it('counts the sample against the whole file after the filter pass', () => {
        const mod = new ImportModule(makeApp());
        const sample = Array.from({ length: 50 }, (_, i) => row({ isDuplicate: true, description: `Line ${i}` }));
        mod.previewTotalValid = 1000;

        mod.showTransactionPreview(sample);
        mod.filterPreviewTransactions();

        expect(info()).toBe('Showing 50 of 1000');
    });

    it('still counts only the rows the filter leaves visible', () => {
        const mod = new ImportModule(makeApp());
        const sample = [
            ...Array.from({ length: 30 }, () => row({ isDuplicate: true })),
            ...Array.from({ length: 20 }, () => row()),
        ];
        mod.previewTotalValid = 1000;
        mod.showTransactionPreview(sample);

        document.getElementById('show-duplicates').checked = false;
        mod.filterPreviewTransactions();

        expect(visibleRows()).toHaveLength(20);
        expect(info()).toBe('Showing 20 of 1000');
    });

    it('counts a small file against its own length', () => {
        const mod = new ImportModule(makeApp());
        mod.previewTotalValid = 2;

        mod.showTransactionPreview([row(), row()]);
        mod.filterPreviewTransactions();

        expect(info()).toBe('Showing 2 of 2');
    });

    it('leaves the empty-file message alone', () => {
        const mod = new ImportModule(makeApp());
        mod.previewTotalValid = 0;

        mod.showTransactionPreview([]);
        mod.filterPreviewTransactions();

        expect(info()).toBe('No transactions found');
        expect(visibleRows()).toHaveLength(1);
    });
});

describe('a rule that sets no category', () => {
    it('reads Uncategorized, since the row is stored without one', () => {
        const mod = new ImportModule(makeApp());

        mod.showTransactionPreview([row({ description: 'Amazon', appliedRule: { id: 7, name: 'Amazon tidy' } })]);

        expect(cell(visibleRows()[0], 'category').textContent.trim()).toBe('Uncategorized');
    });

    it('still shows the category a rule did set', () => {
        const mod = new ImportModule(makeApp([{ id: 402, name: 'Shopping' }]));

        mod.showTransactionPreview([row({ categoryId: 402, appliedRule: { id: 7, name: 'Amazon tidy' } })]);

        expect(cell(visibleRows()[0], 'category').textContent.trim()).toBe('Shopping');
    });

    it('is hidden with the other uncategorized rows', () => {
        const mod = new ImportModule(makeApp([{ id: 402, name: 'Shopping' }]));
        mod.previewTotalValid = 4;
        mod.showTransactionPreview([
            row({ description: 'Amazon', appliedRule: { id: 7, name: 'Amazon tidy' } }),
            row({ description: 'Kiosk Zeitung' }),
            row({ description: 'Shop', categoryId: 402, appliedRule: { id: 8, name: 'Shop' } }),
            row({ description: 'Bier', _categoryName: 'Bier' }),
        ]);

        document.getElementById('show-uncategorized').checked = false;
        mod.filterPreviewTransactions();

        expect(visibleRows().map(tr => tr.children[1].textContent.trim())).toEqual(['Shop', 'Bier']);
        expect(info()).toBe('Showing 2 of 4');
    });

    it('decides by whether a category was set, not by the label text', () => {
        // A category the user happened to name "Uncategorized" is still a category
        const mod = new ImportModule(makeApp([{ id: 5, name: 'Uncategorized' }]));
        mod.showTransactionPreview([row({ categoryId: 5 })]);

        document.getElementById('show-uncategorized').checked = false;
        mod.filterPreviewTransactions();

        expect(visibleRows()).toHaveLength(1);
    });
});

describe('the toast after an import', () => {
    it('uses the singular for one imported row', () => {
        const mod = new ImportModule(makeApp());

        expect(mod.importResultMessage({ imported: 1, skipped: 2 })).toBe('Successfully imported 1 transaction (2 skipped)');
    });

    it('uses the plural for several', () => {
        const mod = new ImportModule(makeApp());

        expect(mod.importResultMessage({ imported: 3, skipped: 1 })).toBe('Successfully imported 3 transactions (1 skipped)');
    });

    it('pluralises the skipped count on its own', () => {
        // English says "skipped" either way; other languages do not
        const mod = new ImportModule(makeApp());
        const plural = vi.spyOn(l10n, 'translatePlural');

        mod.importResultMessage({ imported: 0, skipped: 5 });

        expect(plural.mock.calls.map(call => call[3]).sort()).toEqual([0, 5]);
    });

    it('leaves out the skipped count when nothing was skipped', () => {
        const mod = new ImportModule(makeApp());

        expect(mod.importResultMessage({ imported: 4, skipped: 0 })).toBe('Successfully imported 4 transactions');
    });
});
