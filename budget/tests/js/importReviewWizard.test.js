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

import ImportModule from '../../src/modules/import/ImportModule.js';

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
        <label for="import-account"></label><select id="import-account"></select>`;
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
