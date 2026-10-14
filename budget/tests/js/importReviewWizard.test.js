/**
 * Opening a waiting file in the import wizard (#425): the wizard starts as
 * for an upload, with the folder's template chosen and its account ready.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text) => text,
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

vi.mock('../../src/utils/api.js', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, apiFetch: vi.fn() };
});

import ImportModule from '../../src/modules/import/ImportModule.js';
import { apiFetch } from '../../src/utils/api.js';

function module() {
    const mod = Object.create(ImportModule.prototype);
    mod.showImportMapping = vi.fn(async function (upload) { this.currentImportData = { ...upload }; });
    mod.onImportFormatChange = vi.fn(() => Promise.resolve());
    mod.reviewQueue = { finish: vi.fn(() => Promise.resolve()) };
    return mod;
}

const upload = (overrides = {}) => ({
    fileId: 'import_alice_abc.csv',
    format: 'csv',
    review: { fileId: 11, folderId: 1, accountId: 3, templateId: 5 },
    ...overrides,
});

beforeEach(() => {
    document.body.innerHTML = `
        <select id="import-preset"><option value="">Custom</option><option value="template:5">Banca</option></select>
        <label for="import-account"></label><select id="import-account"></select>
        <input type="file" id="import-file-input">`;
});

describe('openReviewedFile', () => {
    it('starts the wizard and chooses the folder\'s template', async () => {
        const mod = module();
        await mod.openReviewedFile(upload());

        expect(mod.showImportMapping).toHaveBeenCalledWith(upload());
        expect(document.getElementById('import-preset').value).toBe('template:5');
        expect(mod.onImportFormatChange).toHaveBeenCalledWith('template:5');
        expect(mod.currentImportData.review.fileId).toBe(11);
    });

    it('opens unmapped when the template has gone', async () => {
        const mod = module();
        await mod.openReviewedFile(upload({ review: { fileId: 11, folderId: 1, accountId: 3, templateId: 77 } }));

        expect(mod.showImportMapping).toHaveBeenCalled();
        expect(mod.onImportFormatChange).not.toHaveBeenCalled();
        expect(document.getElementById('import-preset').value).toBe('');
    });

    it('leaves the format alone for a file that is not a CSV', async () => {
        const mod = module();
        await mod.openReviewedFile(upload({ format: 'ofx' }));

        expect(mod.onImportFormatChange).not.toHaveBeenCalled();
    });
});

describe('the account for a reviewed file', () => {
    it('is the folder\'s, when nothing else was chosen', () => {
        const mod = module();
        mod.currentImportData = upload();
        mod.populateImportAccountSelect([{ id: 3, name: 'Conto Mario', type: 'checking' }, { id: 4, name: 'Carta', type: 'checking' }], false);

        expect(document.getElementById('import-account').value).toBe('3');
    });

    it('is not forced when the folder\'s account cannot take an import any more', () => {
        const mod = module();
        mod.currentImportData = upload();
        mod.populateImportAccountSelect([{ id: 4, name: 'Carta', type: 'checking' }], false);

        expect(document.getElementById('import-account').value).toBe('');
    });
});

describe('arriving at the review step', () => {
    const ACCOUNTS = [{ id: 3, name: 'Conto Mario', type: 'checking' }, { id: 4, name: 'Carta', type: 'checking' }];

    function atReviewStep(importData) {
        document.body.innerHTML += '<div id="single-account-selection"></div><div id="multi-account-mapping"></div>';
        apiFetch.mockResolvedValue(ACCOUNTS);
        const mod = module();
        mod.currentImportData = importData;
        mod.sourceAccounts = [];
        mod.presets = [];
        mod.selectedPreset = null;
        mod.getCurrentMapping = () => ({});
        mod.processImportData = vi.fn();
        return mod;
    }

    it('previews a reviewed file straight away, as its account is already chosen', async () => {
        // The preview otherwise runs when the account is picked, and nobody picks one here
        const mod = atReviewStep(upload());
        await mod.loadAccountsForImport();

        expect(document.getElementById('import-account').value).toBe('3');
        expect(mod.processImportData).toHaveBeenCalledTimes(1);
    });

    it('waits for an account to be picked for an ordinary upload', async () => {
        const mod = atReviewStep({ fileId: 'import_alice_abc.csv', format: 'csv' });
        await mod.loadAccountsForImport();

        expect(document.getElementById('import-account').value).toBe('');
        expect(mod.processImportData).not.toHaveBeenCalled();
    });
});

describe('one reviewed file after another', () => {
    const ACCOUNTS = [{ id: 3, name: 'Conto Mario', type: 'checking' }, { id: 4, name: 'Carta', type: 'checking' }];

    /** The wizard as the page has it: the account select outlives every reset. */
    function wizard() {
        document.body.innerHTML += `
            <div id="single-account-selection"></div><div id="multi-account-mapping"></div>
            <div id="account-mapping-list"></div>`;
        apiFetch.mockResolvedValue(ACCOUNTS);
        const mod = module();
        mod.sourceAccounts = [];
        mod.presets = [];
        mod.getCurrentMapping = () => ({});
        mod.processImportData = vi.fn();
        mod.showImportMapping = vi.fn(async function (upload) {
            this.currentImportData = { ...upload };
            this.sourceAccounts = upload.sourceAccounts || [];
        });
        return mod;
    }
    const carta = () => upload({ review: { fileId: 12, folderId: 2, accountId: 4, templateId: 5 } });

    it('goes to its own folder\'s account, not the account of the file reviewed before it', async () => {
        const mod = wizard();
        await mod.openReviewedFile(upload());
        await mod.loadAccountsForImport();
        expect(document.getElementById('import-account').value).toBe('3');

        await mod.openReviewedFile(carta());
        await mod.loadAccountsForImport();

        expect(document.getElementById('import-account').value).toBe('4');
    });

    it('is not sent to an account left chosen by an earlier upload', async () => {
        const mod = wizard();
        mod.currentImportData = { fileId: 'import_alice_old.csv', format: 'csv' };
        await mod.loadAccountsForImport();
        document.getElementById('import-account').value = '3';

        await mod.openReviewedFile(carta());
        await mod.loadAccountsForImport();

        expect(document.getElementById('import-account').value).toBe('4');
    });

    it('starts clean of the last file\'s template', async () => {
        const mod = wizard();
        mod.selectedTemplate = 5;
        mod.pendingPreset = 'template:5';
        await mod.openReviewedFile(upload({ review: { fileId: 12, folderId: 2, accountId: 4, templateId: null } }));

        expect(mod.selectedTemplate).toBeNull();
        expect(mod.pendingPreset).toBeNull();
        expect(document.getElementById('import-preset').value).toBe('');
        expect(mod.currentImportData.review.fileId).toBe(12);
    });

    it('keeps an account picked while reviewing when the step is opened again', async () => {
        const mod = wizard();
        await mod.openReviewedFile(carta());
        await mod.loadAccountsForImport();
        document.getElementById('import-account').value = '3';

        await mod.loadAccountsForImport();

        expect(document.getElementById('import-account').value).toBe('3');
    });
});

describe('a reviewed file that names its own accounts (OFX, QIF, camt)', () => {
    const ACCOUNTS = [{ id: 3, name: 'Conto Mario', type: 'checking', currency: 'EUR' }, { id: 4, name: 'Carta', type: 'credit_card', currency: 'EUR' }];
    const SOURCES = [
        { accountId: 'IT60X0542811101000000123456', currency: 'EUR', suggestedMatch: null },
        { accountId: 'IT60X0542811101000000654321', currency: 'EUR', suggestedMatch: 3 },
    ];

    function atReviewStep(importData, sources = SOURCES) {
        document.body.innerHTML += `
            <div id="single-account-selection"></div><div id="multi-account-mapping"></div>
            <div id="account-mapping-list"></div>`;
        apiFetch.mockResolvedValue(ACCOUNTS);
        const mod = module();
        mod.currentImportData = importData;
        mod.sourceAccounts = sources;
        mod.presets = [];
        mod.selectedPreset = null;
        mod.getCurrentMapping = () => ({});
        mod.renderRoutingTemplateBar = vi.fn();
        mod.processImportData = vi.fn();
        return mod;
    }
    const destinations = () => [...document.querySelectorAll('.destination-account-select')].map(s => s.value);
    const intoCarta = () => upload({ format: 'ofx', review: { fileId: 12, folderId: 2, accountId: 4, templateId: null } });

    it('goes to the folder\'s account when it names one account, whatever that is called', async () => {
        const mod = atReviewStep(intoCarta(), [SOURCES[1]]);
        await mod.loadAccountsForImport();

        expect(destinations()).toEqual(['4']);
        expect(mod.processImportData).toHaveBeenCalledTimes(1);
    });

    it('is mapped account by account when it names several, as any upload is', async () => {
        // A current account and a card in one file are not for one account
        const mod = atReviewStep(intoCarta());
        await mod.loadAccountsForImport();

        expect(destinations()).toEqual(['', '3']);
    });

    it('is not sent to the folder\'s account when it is in another currency', async () => {
        const mod = atReviewStep(intoCarta(), [{ accountId: '4111', currency: 'USD', suggestedMatch: null }]);
        await mod.loadAccountsForImport();

        expect(destinations()).toEqual(['']);
    });

    it('goes to the folder\'s account when it does not say its currency', async () => {
        const mod = atReviewStep(intoCarta(), [{ accountId: 'Conto', suggestedMatch: null }]);
        await mod.loadAccountsForImport();

        expect(destinations()).toEqual(['4']);
    });

    it('falls back to the suggested match when the folder\'s account cannot take an import', async () => {
        const mod = atReviewStep(upload({ format: 'ofx', review: { fileId: 12, folderId: 2, accountId: 99, templateId: null } }), [SOURCES[1]]);
        await mod.loadAccountsForImport();

        expect(destinations()).toEqual(['3']);
    });

    it('leaves an ordinary upload to the suggested match', async () => {
        const mod = atReviewStep({ fileId: 'import_alice_abc.ofx', format: 'ofx' });
        await mod.loadAccountsForImport();

        expect(destinations()).toEqual(['', '3']);
    });
});

describe('finishReviewedFile', () => {
    it('tells the queue the file was imported', async () => {
        const mod = module();
        await mod.finishReviewedFile({ fileId: 11 });

        expect(mod.reviewQueue.finish).toHaveBeenCalledWith(11, 'imported');
    });

    it('does nothing for an ordinary upload', async () => {
        const mod = module();
        await mod.finishReviewedFile(undefined);

        expect(mod.reviewQueue.finish).not.toHaveBeenCalled();
    });
});

describe('importing a reviewed file', () => {
    function readyToImport() {
        document.body.innerHTML += '<button id="import-btn">Import</button>';
        const select = document.getElementById('import-account');
        select.innerHTML = '<option value=""></option><option value="3">Conto Mario</option>';
        select.value = '3';
        const mod = module();
        mod.currentImportData = upload();
        mod.sourceAccounts = [];
        mod.presets = [];
        mod.getCurrentMapping = () => ({ date: 0, amount: 1 });
        mod.applyRulesChosen = () => true;
        mod.showImportErrors = vi.fn();
        mod.loadTransactions = vi.fn();
        mod.autoMatchTransfers = vi.fn();
        mod.app = { loadAccounts: vi.fn() };
        mod.reviewQueue = { finish: vi.fn(() => Promise.resolve()), load: vi.fn(() => Promise.resolve()) };
        return mod;
    }
    const serverSays = (result) => apiFetch.mockResolvedValue({ status: 200, text: () => Promise.resolve(JSON.stringify(result)) });

    beforeEach(() => {
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    it('files it under Imported once its rows are in', async () => {
        serverSays({ imported: 2, skipped: 0, errors: [], transactionIds: [1, 2] });
        const mod = readyToImport();
        await mod.executeImport();

        expect(mod.reviewQueue.finish).toHaveBeenCalledWith(11, 'imported');
    });

    it('files it under Imported when every row was there already', async () => {
        serverSays({ imported: 0, skipped: 4, errors: [], transactionIds: [] });
        const mod = readyToImport();
        await mod.executeImport();

        expect(mod.reviewQueue.finish).toHaveBeenCalledWith(11, 'imported');
    });

    it('leaves it waiting when no row could be imported', async () => {
        // Filed under Imported it would look dealt with, and it is not
        serverSays({ imported: 0, skipped: 0, errors: [{ row: 1, error: 'Invalid date' }], transactionIds: [] });
        const mod = readyToImport();
        await mod.executeImport();

        expect(mod.reviewQueue.finish).not.toHaveBeenCalled();
        expect(mod.reviewQueue.load).toHaveBeenCalled();
        expect(mod.showImportErrors).toHaveBeenCalled();
    });
});
