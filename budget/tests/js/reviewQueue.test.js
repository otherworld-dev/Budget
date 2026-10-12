/**
 * The "Waiting for review" list on the Import page (#425): statements an
 * import folder is holding until someone has looked at them.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}, _number, options = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => {
            if (!(k in params)) return m;
            return options.escape === false ? String(params[k]) : String(params[k]).replace(/&/g, '&amp;');
        }),
    translatePlural: (_app, singular, plural, count) => String(count === 1 ? singular : plural).replace(/%n/g, count),
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

import ReviewQueue from '../../src/modules/import/ReviewQueue.js';
import { apiFetch, ApiError } from '../../src/utils/api.js';
import { showError } from '../../src/utils/notifications.js';
import { confirmDialog } from '../../src/utils/dialogs.js';

const tick = () => new Promise(resolve => setTimeout(resolve, 0));
const ACCOUNTS = [{ id: 3, name: 'Conto Mario' }];
const WAITING = [
    { fileId: 11, name: 'ottobre.csv', size: 230, modified: 1791799700, folderId: 1, folderName: 'Joint & Savings', accountId: 3, templateId: 5 },
    { fileId: 12, name: 'carta.ofx', size: 80, modified: 1791799800, folderId: 2, folderName: 'Carta', accountId: 99, templateId: null },
];

function givenApi(routes = {}) {
    apiFetch.mockImplementation((url, options = {}) => {
        const key = `${options.method || 'GET'} ${url}`;
        if (key in routes) {
            const answer = routes[key];
            return answer instanceof Error ? Promise.reject(answer) : Promise.resolve(answer);
        }
        if (key === 'GET /apps/budget/api/import-folders/review') return Promise.resolve(WAITING);
        return Promise.resolve({ status: 'success' });
    });
}
const calls = (method) => apiFetch.mock.calls.filter(([, options = {}]) => (options.method || 'GET') === method);

let openInWizard;
const queue = () => new ReviewQueue({ getAccounts: () => ACCOUNTS, openInWizard });

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    openInWizard = vi.fn(() => Promise.resolve());
    document.body.innerHTML = '<div id="import-review-queue" hidden><ul id="import-review-list"></ul></div>';
});

describe('ReviewQueue', () => {
    it('stays hidden when nothing is waiting', async () => {
        givenApi({ 'GET /apps/budget/api/import-folders/review': [] });
        await queue().load();

        expect(document.getElementById('import-review-queue').hidden).toBe(true);
    });

    it('lists each waiting file with its folder and account', async () => {
        givenApi();
        await queue().load();

        expect(document.getElementById('import-review-queue').hidden).toBe(false);
        const items = [...document.querySelectorAll('#import-review-list li')];
        expect(items).toHaveLength(2);
        expect(items[0].querySelector('strong').textContent).toBe('ottobre.csv');
        expect(items[0].querySelector('span').textContent).toBe('Joint & Savings, into Conto Mario');
        // An account that has gone since: the folder is still named
        expect(items[1].querySelector('span').textContent).toBe('Carta');
        expect([...items[0].querySelectorAll('button')].map(b => b.textContent)).toEqual(['Review', 'Skip']);
    });

    it('says when each file arrived', async () => {
        givenApi({ 'GET /apps/budget/api/import-folders/review': [WAITING[0], { ...WAITING[1], modified: 0 }] });
        await queue().load();

        const items = [...document.querySelectorAll('#import-review-list li')];
        const arrived = items[0].querySelector('time');
        expect(arrived.getAttribute('datetime')).toBe(new Date(1791799700 * 1000).toISOString());
        expect(arrived.textContent).toBe(new Date(1791799700 * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }));
        // No time on record: nothing is made up
        expect(items[1].querySelector('time')).toBeNull();
    });

    it('opens a file in the wizard', async () => {
        const upload = { fileId: 'import_alice_abc.csv', format: 'csv', review: { fileId: 11, folderId: 1, accountId: 3, templateId: 5 } };
        givenApi({ 'POST /apps/budget/api/import-folders/review/11/open': upload });
        await queue().load();

        document.querySelector('#import-review-list button[data-action="review"]').click();
        await tick();

        expect(openInWizard).toHaveBeenCalledWith(upload);
    });

    it('shows why a file could not be opened', async () => {
        givenApi({ 'POST /apps/budget/api/import-folders/review/11/open': new ApiError('This spreadsheet could not be read.', { status: 400 }) });
        await queue().load();

        document.querySelector('#import-review-list button[data-action="review"]').click();
        await tick();

        expect(showError).toHaveBeenCalledWith('This spreadsheet could not be read.');
        expect(openInWizard).not.toHaveBeenCalled();
    });

    it('skips a file only once confirmed, then reloads', async () => {
        givenApi();
        await queue().load();
        confirmDialog.mockResolvedValueOnce(false);

        document.querySelector('#import-review-list button[data-action="skip"]').click();
        await tick();
        expect(calls('POST')).toHaveLength(0);

        document.querySelector('#import-review-list button[data-action="skip"]').click();
        await tick();
        await tick();
        expect(confirmDialog.mock.calls[1][0]).toContain('Skipped folder');
        expect(calls('POST')[0][0]).toBe('/apps/budget/api/import-folders/review/11/finish');
        expect(calls('POST')[0][1].body).toEqual({ outcome: 'skipped' });
        expect(calls('GET')).toHaveLength(2);
    });

    it('finishes a file after its import and reloads', async () => {
        givenApi();
        const q = queue();
        await q.load();

        await q.finish(11, 'imported');

        expect(calls('POST')[0][0]).toBe('/apps/budget/api/import-folders/review/11/finish');
        expect(calls('POST')[0][1].body).toEqual({ outcome: 'imported' });
        expect(calls('GET')).toHaveLength(2);
    });

    it('says so when the imported file could not be moved, without failing the import', async () => {
        givenApi({ 'POST /apps/budget/api/import-folders/review/11/finish': new ApiError('The file could not be moved. Move it out of the Review folder yourself.', { status: 400 }) });
        const q = queue();
        await q.load();

        await expect(q.finish(11, 'imported')).resolves.toBeUndefined();
        expect(showError).toHaveBeenCalledWith('The file could not be moved. Move it out of the Review folder yourself.');
    });
});
