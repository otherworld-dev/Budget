/**
 * The review table and the Auto-categorized counter disagreed about a row
 * whose category comes from a column mapped to Category. The counter has
 * always counted such a row, and the import files it under a category of that
 * name, however the table only looked at a rule's category and said
 * "Uncategorized". In #388 a six-row file read "Auto-categorized: 3" above a
 * table showing one category.
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
    return { date: '2026-09-18', description: '', amount: 13.8, type: 'debit', ...overrides };
}

function categoryCells() {
    return Array.from(document.querySelectorAll('[data-preview-cell="category"]'))
        .map(cell => cell.textContent.trim());
}

beforeEach(() => {
    global.OC = { generateUrl: (url) => url, requestToken: 'tok' };
    document.body.innerHTML = `
        <input type="checkbox" id="show-duplicates" checked>
        <input type="checkbox" id="show-uncategorized" checked>
        <span id="preview-info"></span>
        <table id="preview-table"><thead><tr><th id="preview-th-notes"></th></tr></thead><tbody></tbody></table>
    `;
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    vi.restoreAllMocks();
});

describe('ImportModule review step: the category a row will get', () => {
    it('shows the category named by a column mapped to Category', () => {
        const mod = new ImportModule(makeApp());

        mod.showTransactionPreview([row({ notes: 'Bier', _categoryName: 'Bier' })]);

        expect(categoryCells()).toEqual(['Bier']);
    });

    it('keeps a rule\'s category ahead of the column, as the import does', () => {
        const mod = new ImportModule(makeApp([{ id: 402, name: 'Alkohol' }]));

        mod.showTransactionPreview([row({ categoryId: 402, _categoryName: 'Bier' })]);

        expect(categoryCells()).toEqual(['Alkohol']);
    });

    it('does not hide such a row when uncategorized rows are filtered out', () => {
        const mod = new ImportModule(makeApp());
        mod.showTransactionPreview([row({ _categoryName: 'Bier' }), row()]);

        document.getElementById('show-uncategorized').checked = false;
        mod.filterPreviewTransactions();

        const visible = Array.from(document.querySelectorAll('#preview-table tbody tr'))
            .filter(tr => tr.style.display !== 'none')
            .map(tr => tr.querySelector('[data-preview-cell="category"]').textContent.trim());
        expect(visible).toEqual(['Bier']);
    });
});
