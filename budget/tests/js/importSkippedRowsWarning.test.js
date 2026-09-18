/**
 * The review step used to be silent about rows the import was going to drop.
 * The preview API has always returned a reason per unusable row, and only the
 * post-import modal ever read it - so in #388 a 75-row file previewed as
 * "46 duplicates, 0 new", the 29 rows with a blank account cell went
 * unmentioned, and the reporter found out they were missing by counting
 * transactions afterwards. The fallback account that would have saved them is
 * a select on this very step.
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

const NO_ACCOUNT = 'This row has no account, and no account was chosen for the import';

function blankAccountErrors(rows) {
    return rows.map(row => ({ row, error: NO_ACCOUNT, reason: 'no-account' }));
}

function warningText() {
    return document.getElementById('import-skipped-rows')?.textContent ?? '';
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

describe('ImportModule review step: rows that will not import', () => {
    it('says how many of the file\'s rows will be dropped, and why', () => {
        const mod = new ImportModule(makeApp());

        mod.updateImportSummary({
            totalRows: 75,
            validTransactions: 46,
            duplicates: 46,
            categorizedCount: 46,
            transactions: [],
            errors: blankAccountErrors([47, 48, 49]),
        });

        const text = warningText();
        expect(text).toContain('3 of 75');
        expect(text).toContain(NO_ACCOUNT);
        expect(text).toContain('47, 48, 49');
    });

    it('points at the fallback account select when the reason is a blank account cell', () => {
        const mod = new ImportModule(makeApp());

        mod.updateImportSummary({
            totalRows: 75,
            validTransactions: 46,
            errors: blankAccountErrors([47, 48]),
        });

        expect(warningText()).toContain('Account for rows without one');
    });

    it('leaves that hint out when the rows failed for some other reason', () => {
        const mod = new ImportModule(makeApp());

        mod.updateImportSummary({
            totalRows: 4,
            validTransactions: 3,
            errors: [{ row: 2, error: 'Invalid date format: Date:' }],
        });

        const text = warningText();
        expect(text).toContain('Invalid date format: Date:');
        expect(text).not.toContain('Account for rows without one');
    });

    it('abbreviates a long row list the same way the post-import modal does', () => {
        const mod = new ImportModule(makeApp());
        const rows = Array.from({ length: 29 }, (_, i) => 47 + i);

        mod.updateImportSummary({
            totalRows: 75,
            validTransactions: 46,
            errors: blankAccountErrors(rows),
        });

        const text = warningText();
        expect(text).toContain('47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58');
        expect(text).toContain('17 more');
        expect(text).not.toContain('59');
    });

    it('clears itself once a re-run of the preview has nothing to report', () => {
        const mod = new ImportModule(makeApp());

        mod.updateImportSummary({ totalRows: 75, validTransactions: 46, errors: blankAccountErrors([47]) });
        expect(warningText()).not.toBe('');

        mod.updateImportSummary({ totalRows: 75, validTransactions: 75, errors: [] });
        expect(warningText()).toBe('');
    });

    it('stays out of the way when the preview reports no errors at all', () => {
        const mod = new ImportModule(makeApp());

        mod.updateImportSummary({ totalRows: 3, validTransactions: 3, transactions: [] });

        expect(document.getElementById('import-skipped-rows')).toBeNull();
    });
});
