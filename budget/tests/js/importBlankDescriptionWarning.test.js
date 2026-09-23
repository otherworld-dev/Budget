/**
 * Description is a required mapping, however a row whose description cell is
 * empty went through the review step without a word. In #388 a Nextcloud
 * Tables export left the Description cell out of five rows in six. The
 * reporter's import rules all match on the description, so those rows came
 * through uncategorized, and the only sign of it was a blank cell in the
 * preview table.
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
    return {
        data: {},
        accounts: [],
        categories: [],
        settings: {},
        getPrimaryCurrency: () => 'USD',
        loadTransactions: vi.fn(),
        loadAccounts: vi.fn(),
    };
}

function warningText() {
    return document.getElementById('import-blank-descriptions')?.textContent ?? '';
}

beforeEach(() => {
    global.OC = { generateUrl: (url) => url, requestToken: 'tok' };
    document.body.innerHTML = `
        <div class="import-summary">
            <span id="import-total-transactions">0</span>
            <span id="new-transactions">0</span>
            <span id="duplicate-transactions">0</span>
            <span id="categorized-transactions">0</span>
        </div>
    `;
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    vi.restoreAllMocks();
});

describe('ImportModule review step: rows with no description', () => {
    it('says how many rows have no description, and which', () => {
        const mod = new ImportModule(makeApp());

        mod.updateImportSummary({
            totalRows: 6,
            validTransactions: 6,
            blankDescriptionRows: [2, 3, 4, 5, 6],
        });

        const text = warningText();
        expect(text).toContain('5 of 6');
        expect(text).toContain('2, 3, 4, 5, 6');
    });

    it('says what it costs: rules that match on the description cannot categorize them', () => {
        const mod = new ImportModule(makeApp());

        mod.updateImportSummary({ totalRows: 6, validTransactions: 6, blankDescriptionRows: [2] });

        expect(warningText()).toContain('match on the description');
    });

    it('abbreviates a long row list the way the other warnings do', () => {
        const mod = new ImportModule(makeApp());
        const rows = Array.from({ length: 29 }, (_, i) => 47 + i);

        mod.updateImportSummary({ totalRows: 75, validTransactions: 75, blankDescriptionRows: rows });

        const text = warningText();
        expect(text).toContain('47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58');
        expect(text).toContain('17 more');
    });

    it('clears itself once a re-run of the preview has nothing to report', () => {
        const mod = new ImportModule(makeApp());

        mod.updateImportSummary({ totalRows: 6, validTransactions: 6, blankDescriptionRows: [2] });
        expect(warningText()).not.toBe('');

        mod.updateImportSummary({ totalRows: 6, validTransactions: 6 });
        expect(warningText()).toBe('');
    });

    it('stays out of the way when every row has a description', () => {
        const mod = new ImportModule(makeApp());

        mod.updateImportSummary({ totalRows: 3, validTransactions: 3, transactions: [] });

        expect(document.getElementById('import-blank-descriptions')).toBeNull();
    });
});
