/**
 * The count above the Review & Import table with a filter applied (#399
 * review, L5).
 *
 * The table holds the first 50 rows of the file. Counting the rows a filter
 * left visible against the whole file read "Showing 3 of 1000", as if only 3
 * of the 1000 lines passed. With rows hidden from a sample smaller than the
 * file, the count names the sample too.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (match, key) => (key in params ? params[key] : match)),
    translatePlural: (_app, singular, plural, count) =>
        String(count === 1 ? singular : plural).replace(/%n/g, count),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import ImportModule from '../../src/modules/import/ImportModule.js';

function makeApp() {
    return { data: {}, accounts: [], categories: [], settings: {}, getPrimaryCurrency: () => 'USD', loadTransactions: vi.fn(), loadAccounts: vi.fn() };
}

const row = (overrides = {}) => ({ date: '2026-09-01', description: 'Line', amount: 9.99, type: 'debit', ...overrides });

/** 47 uncategorized rows and 3 with a category from a mapped column */
const sample = () => [
    ...Array.from({ length: 47 }, () => row()),
    ...Array.from({ length: 3 }, () => row({ _categoryName: 'Bier' })),
];

const info = () => document.getElementById('preview-info').textContent;

beforeEach(() => {
    globalThis.OC = { generateUrl: (url) => url, requestToken: 'tok' };
    document.body.innerHTML = `
        <input type="checkbox" id="show-duplicates" checked>
        <input type="checkbox" id="show-uncategorized" checked>
        <span id="preview-info"></span>
        <table id="preview-table"><thead><tr>
            <th></th><th></th><th id="preview-th-payee"></th><th id="preview-th-notes"></th><th></th><th></th><th></th>
        </tr></thead><tbody></tbody></table>
    `;
});

afterEach(() => {
    document.body.innerHTML = '';
    delete globalThis.OC;
});

describe('the review count with a filter applied', () => {
    it('names the sample when a filter hides rows of a longer file', () => {
        const mod = new ImportModule(makeApp());
        mod.previewTotalValid = 1000;
        mod.showTransactionPreview(sample());

        document.getElementById('show-uncategorized').checked = false;
        mod.filterPreviewTransactions();

        expect(info()).toBe('Showing 3 of the first 50 of 1000 rows');
    });

    it('stays "50 of 1000" with nothing filtered out', () => {
        const mod = new ImportModule(makeApp());
        mod.previewTotalValid = 1000;
        mod.showTransactionPreview(sample());

        mod.filterPreviewTransactions();

        expect(info()).toBe('Showing 50 of 1000');
    });

    it('goes back to "50 of 1000" when the filter is ticked again', () => {
        const mod = new ImportModule(makeApp());
        mod.previewTotalValid = 1000;
        mod.showTransactionPreview(sample());
        const box = document.getElementById('show-uncategorized');

        box.checked = false;
        mod.filterPreviewTransactions();
        box.checked = true;
        mod.filterPreviewTransactions();

        expect(info()).toBe('Showing 50 of 1000');
    });

    it('counts against the file when the table holds all of it', () => {
        const mod = new ImportModule(makeApp());
        mod.previewTotalValid = 4;
        mod.showTransactionPreview([row(), row(), row({ _categoryName: 'Bier' }), row()]);

        document.getElementById('show-uncategorized').checked = false;
        mod.filterPreviewTransactions();

        expect(info()).toBe('Showing 1 of 4');
    });
});
