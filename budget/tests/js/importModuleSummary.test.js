import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (match, key) => (key in params ? params[key] : match)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
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

beforeEach(() => {
    global.OC = { generateUrl: (url) => url, requestToken: 'tok' };
    // The account page's counter comes first in the real document, which is
    // what made it swallow the import summary's total
    document.body.innerHTML = `
        <div class="account-metrics-grid"><div id="total-transactions">0</div></div>
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

describe('ImportModule review summary', () => {
    it('writes the file total to the import summary, not the account page counter', () => {
        const mod = new ImportModule(makeApp());

        mod.updateImportSummary({
            totalRows: 3,
            validTransactions: 3,
            duplicates: 1,
            categorizedCount: 0,
            transactions: [],
        });

        expect(document.getElementById('import-total-transactions').textContent).toBe('3');
        expect(document.getElementById('new-transactions').textContent).toBe('2');
        expect(document.getElementById('duplicate-transactions').textContent).toBe('1');
        expect(document.getElementById('total-transactions').textContent).toBe('0');
    });
});
