/**
 * The import's review step shows each row in the currency of the account it
 * goes into.
 *
 * Every amount was formatted in the user's default currency at its decimal
 * places, so a CSV going into a BTC account previewed 0.00012345, 117.5 and
 * -0.0015 as "£0.00", "£117.50" and "-£0.00", and a USD card row in an OFX
 * file as "-£64.99", although the import stored them right. The OFX account
 * list also showed each account's balance in the default currency.
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

const ACCOUNTS = [
    { id: 1, name: 'Current', currency: 'GBP' },
    { id: 5, name: 'Cold wallet', currency: 'BTC' },
    { id: 7, name: 'US card', currency: 'USD' },
    { id: 9, name: 'Euro savings', currency: 'EUR' },
];

function makeModule() {
    return new ImportModule({
        data: {},
        accounts: ACCOUNTS,
        categories: [],
        settings: { default_currency: 'GBP' },
        loadTransactions: vi.fn(),
        loadAccounts: vi.fn(),
    });
}

const row = (amount, type, extra = {}) => ({ date: '2026-10-01', description: 'Row', amount, type, ...extra });

function amounts() {
    return Array.from(document.querySelectorAll('#preview-table tbody tr'))
        .map(tr => tr.querySelectorAll('td')[3].textContent.trim());
}

beforeEach(() => {
    global.OC = { generateUrl: (url) => url, requestToken: 'tok' };
    document.body.innerHTML = `
        <select id="import-account"><option value=""></option><option value="5">Cold wallet</option></select>
        <input type="checkbox" id="show-duplicates" checked>
        <input type="checkbox" id="show-uncategorized" checked>
        <div id="import-preview-section"></div>
        <span id="preview-info"></span>
        <table id="preview-table"><thead><tr><th id="preview-th-notes"></th></tr></thead><tbody></tbody></table>
    `;
});

afterEach(() => {
    document.body.innerHTML = '';
    delete global.OC;
    delete global.fetch;
    vi.restoreAllMocks();
});

describe('the import review step', () => {
    it('shows rows going into a BTC account in BTC, at its 8 decimals', () => {
        const mod = makeModule();

        mod.showTransactionPreview(
            [row(0.00012345, 'credit'), row(117.5, 'credit'), row(0.0015, 'debit')],
            { accountId: 5 },
        );

        expect(amounts()).toEqual(['0.00012345 BTC', '117.50000000 BTC', '-0.00150000 BTC']);
    });

    it('shows an OFX row in the currency of the account it is routed to', () => {
        const mod = makeModule();

        mod.showTransactionPreview([
            row(64.99, 'debit', { sourceAccountId: 'CARD', destinationAccountId: 7 }),
            row(10, 'credit', { sourceAccountId: 'CURRENT', destinationAccountId: 1 }),
        ]);

        expect(amounts()).toEqual(['-$64.99', '£10.00']);
    });

    it('follows a file\'s account column: matched, new, or blank (the chosen account)', () => {
        const mod = makeModule();

        mod.showTransactionPreview([
            row(20, 'debit', { _accountName: 'Euro savings' }),
            row(0.5, 'credit', { _accountName: 'Exchange BTC' }),
            row(0.001, 'debit'),
        ], {
            accountId: 5,
            accountsToCreate: [
                { name: 'Euro savings', currency: 'EUR', exists: true, existingId: 9 },
                { name: 'Exchange BTC', currency: 'BTC', exists: false },
            ],
        });

        expect(amounts()).toEqual(['-€20.00', '0.50000000 BTC', '-0.00100000 BTC']);
    });

    it('keeps the default currency when nothing says otherwise', () => {
        const mod = makeModule();

        mod.showTransactionPreview([row(12.5, 'debit')]);

        expect(amounts()).toEqual(['-£12.50']);
    });

    it('formats the preview of an import into the chosen account in its currency', async () => {
        const mod = makeModule();
        mod.currentImportData = { fileId: 'f1' };
        mod.sourceAccounts = [];
        mod.presets = [];
        mod.getCurrentMapping = () => ({ date: 0, description: 1, amount: 2 });
        mod.applyRulesChosen = () => true;
        mod.updateImportSummary = vi.fn();
        document.getElementById('import-account').value = '5';
        global.fetch = vi.fn(async () => ({
            ok: true, status: 200, headers: { get: () => null },
            json: async () => ({ transactions: [row(0.00012345, 'credit')], validTransactions: 1 }),
        }));

        await mod.processImportData();

        expect(amounts()).toEqual(['0.00012345 BTC']);
    });
});

describe('the OFX/QIF account list', () => {
    it('shows each account\'s balance in the file\'s currency for it', () => {
        document.body.innerHTML = '<div id="multi-account-mapping"><div id="account-mapping-list"></div></div>';
        const mod = makeModule();
        mod.importFormat = 'csv'; // no routing-template bar
        mod.sourceAccounts = [{ accountId: 'CARD', currency: 'USD', ledgerBalance: -1234.5 }];

        mod.renderAccountMappingUI(ACCOUNTS);

        expect(document.querySelector('.source-account-details').textContent).toContain('Balance: -$1,234.50');
    });
});
