/**
 * The Import folder section of the settings page (#425): the subfolders of
 * the user's import folder, each tied to an account and an import template.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    // Like the real one, placeholder values are HTML-escaped unless told not to
    translate: (_app, text, params = {}, _number, options = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => {
            if (!(k in params)) return m;
            return options.escape === false ? String(params[k]) : String(params[k]).replace(/&/g, '&amp;');
        }),
    translatePlural: (_app, singular, plural, count) =>
        String(count === 1 ? singular : plural).replace(/%n/g, count),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(() => Promise.resolve(true)),
}));

vi.mock('../../src/utils/api.js', async (importOriginal) => {
    const actual = await importOriginal();
    return { ...actual, apiFetch: vi.fn() };
});

import ImportFolders from '../../src/modules/settings/ImportFolders.js';
import { apiFetch, ApiError } from '../../src/utils/api.js';
import { showError } from '../../src/utils/notifications.js';
import { confirmDialog } from '../../src/utils/dialogs.js';

const tick = () => new Promise(resolve => setTimeout(resolve, 0));

const ACCOUNTS = [
    { id: 3, name: 'Conto Mario' },
    { id: 4, name: 'Carta' },
    { id: 8, name: 'Shared with me', _shared: true },
    { id: 9, name: 'Old account', closed: true },
];
const TEMPLATES = [{ id: 5, name: 'Banca', format: 'csv' }, { id: 6, name: 'Conti OFX', format: 'ofx' }];
const FOLDER = { id: 1, name: 'Conto Mario', accountId: 3, templateId: 5, mode: 'auto', enabled: true };

/** Answers each endpoint from `routes`, and records every call. */
function givenApi(routes = {}) {
    apiFetch.mockImplementation((url, options = {}) => {
        const key = `${options.method || 'GET'} ${url}`;
        if (key in routes) {
            const answer = routes[key];
            return answer instanceof Error ? Promise.reject(answer) : Promise.resolve(answer);
        }
        if (key === 'GET /apps/budget/api/import-folders') return Promise.resolve({ folder: 'Budget/Import', folders: [FOLDER] });
        if (key === 'GET /apps/budget/api/import-templates') return Promise.resolve(TEMPLATES);
        return Promise.resolve({});
    });
}

const calls = (method) => apiFetch.mock.calls.filter(([, options = {}]) => (options.method || 'GET') === method);

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    document.body.innerHTML = `
        <table id="import-folders-table" hidden><tbody id="import-folders-list"></tbody></table>
        <p id="import-folders-empty"></p>
        <form id="import-folder-add">
            <input id="import-folder-name">
            <select id="import-folder-account"></select>
            <select id="import-folder-template"></select>
            <button type="submit">Add</button>
        </form>
        <button id="import-folders-check"></button><span id="import-folders-status"></span>`;
});

const section = () => new ImportFolders({ getAccounts: () => ACCOUNTS });
const optionTexts = (id) => [...document.getElementById(id).options].map(o => o.textContent);

describe('ImportFolders', () => {
    it('lists the subfolders with their account and template', async () => {
        givenApi();
        await section().load();

        const row = document.querySelector('#import-folders-list tr');
        expect(row.querySelector('th').textContent).toBe('Conto Mario');
        expect(row.querySelector('select[data-field="accountId"]').value).toBe('3');
        expect(row.querySelector('select[data-field="templateId"]').value).toBe('5');
        expect(row.querySelector('input[type="checkbox"]').checked).toBe(true);
        expect(document.getElementById('import-folders-table').hidden).toBe(false);
        expect(document.getElementById('import-folders-empty').hidden).toBe(true);
    });

    it('offers only your own open accounts, and "no template" as a choice', async () => {
        givenApi();
        await section().load();

        expect(optionTexts('import-folder-account')).toEqual(['Conto Mario', 'Carta']);
        expect(optionTexts('import-folder-template')).toEqual(['None (OFX, QIF and camt files only)', 'Banca (CSV)', 'Conti OFX (OFX)']);
    });

    it('shows the empty message when there are none', async () => {
        givenApi({ 'GET /apps/budget/api/import-folders': { folder: 'Budget/Import', folders: [] } });
        await section().load();

        expect(document.getElementById('import-folders-table').hidden).toBe(true);
        expect(document.getElementById('import-folders-empty').hidden).toBe(false);
    });

    it('adds a subfolder', async () => {
        givenApi({ 'POST /apps/budget/api/import-folders': { ...FOLDER, id: 2, name: 'Carta', accountId: 4, templateId: null } });
        await section().load();
        document.getElementById('import-folder-name').value = 'Carta';
        document.getElementById('import-folder-account').value = '4';
        document.getElementById('import-folder-template').value = '';

        document.getElementById('import-folder-add').dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();

        expect(calls('POST')[0][1].body).toEqual({ name: 'Carta', accountId: 4, templateId: null });
        expect(document.querySelectorAll('#import-folders-list tr')).toHaveLength(2);
        expect(document.getElementById('import-folder-name').value).toBe('');
    });

    it('shows why a subfolder was refused', async () => {
        givenApi({ 'POST /apps/budget/api/import-folders': new ApiError('You already have an import folder called Carta.', { status: 400 }) });
        await section().load();
        document.getElementById('import-folder-name').value = 'Carta';

        document.getElementById('import-folder-add').dispatchEvent(new Event('submit', { cancelable: true }));
        await tick();

        expect(showError).toHaveBeenCalledWith('You already have an import folder called Carta.');
        expect(document.querySelectorAll('#import-folders-list tr')).toHaveLength(1);
    });

    it('saves a change to a row as soon as it is made', async () => {
        givenApi({ 'PUT /apps/budget/api/import-folders/1': { ...FOLDER, enabled: false } });
        await section().load();
        const toggle = document.querySelector('#import-folders-list input[type="checkbox"]');
        toggle.checked = false;

        toggle.dispatchEvent(new Event('change', { bubbles: true }));
        await tick();

        expect(calls('PUT')[0][0]).toBe('/apps/budget/api/import-folders/1');
        expect(calls('PUT')[0][1].body).toEqual({ enabled: false });
    });

    it('clears a row\'s template when "none" is picked', async () => {
        givenApi({ 'PUT /apps/budget/api/import-folders/1': { ...FOLDER, templateId: null } });
        await section().load();
        const select = document.querySelector('#import-folders-list select[data-field="templateId"]');
        select.value = '';

        select.dispatchEvent(new Event('change', { bubbles: true }));
        await tick();

        expect(calls('PUT')[0][1].body).toEqual({ templateId: null });
    });

    it('removes a subfolder only once confirmed, and says the files stay', async () => {
        givenApi();
        await section().load();
        confirmDialog.mockResolvedValueOnce(false);

        document.querySelector('#import-folders-list button').click();
        await tick();
        expect(calls('DELETE')).toHaveLength(0);

        document.querySelector('#import-folders-list button').click();
        await tick();
        expect(confirmDialog.mock.calls[1][0]).toContain('stays in your Files');
        expect(calls('DELETE')[0][0]).toBe('/apps/budget/api/import-folders/1');
        expect(document.querySelectorAll('#import-folders-list tr')).toHaveLength(0);
    });

    it('checks now and says what happened', async () => {
        givenApi({ 'POST /apps/budget/api/import-folders/check': { imported: 2, failed: 1, waiting: 0 } });
        await section().load();

        document.getElementById('import-folders-check').click();
        await tick();

        expect(document.getElementById('import-folders-status').textContent).toBe('2 files imported, 1 file could not be imported');
    });

    it('shows a name with an ampersand as it is, in labels and in the confirmation', async () => {
        // These go into attributes and a dialog's text, not into HTML
        givenApi({ 'GET /apps/budget/api/import-folders': { folder: 'Budget/Import', folders: [{ ...FOLDER, name: 'Joint & Savings' }] } });
        await section().load();

        expect(document.querySelector('#import-folders-list input[type="checkbox"]').getAttribute('aria-label')).toBe('Import files dropped into Joint & Savings');
        document.querySelector('#import-folders-list button').click();
        await tick();
        expect(confirmDialog.mock.calls[0][0]).toContain('Stop importing from Joint & Savings?');
    });

    it('reloads the rows after a check, as the server may have switched one off', async () => {
        let loads = 0;
        apiFetch.mockImplementation((url, options = {}) => {
            const key = `${options.method || 'GET'} ${url}`;
            if (key === 'GET /apps/budget/api/import-folders') {
                loads++;
                return Promise.resolve({ folder: 'Budget/Import', folders: [{ ...FOLDER, enabled: loads === 1 }] });
            }
            if (key === 'GET /apps/budget/api/import-templates') return Promise.resolve(TEMPLATES);
            if (key === 'POST /apps/budget/api/import-folders/check') return Promise.resolve({ imported: 0, failed: 1, waiting: 0 });
            return Promise.resolve({});
        });
        await section().load();
        expect(document.querySelector('#import-folders-list input[type="checkbox"]').checked).toBe(true);

        document.getElementById('import-folders-check').click();
        await tick();
        await tick();

        expect(document.querySelector('#import-folders-list input[type="checkbox"]').checked).toBe(false);
        expect(document.getElementById('import-folders-status').textContent).toBe('1 file could not be imported');
    });

    it('says so when there was nothing to import', async () => {
        givenApi({ 'POST /apps/budget/api/import-folders/check': { imported: 0, failed: 0, waiting: 0 } });
        await section().load();

        document.getElementById('import-folders-check').click();
        await tick();

        expect(document.getElementById('import-folders-status').textContent).toBe('Nothing to import');
    });
});
