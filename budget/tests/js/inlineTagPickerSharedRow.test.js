/**
 * Tagging a transaction in an account someone shared with you.
 *
 * The row is tagged as its owner, who can't see your own tags, so the
 * server refuses them there ("This account belongs to someone else, who
 * cannot see one of the tags..."). The inline tag picker still offered your
 * own tags on such rows, and a refused save only went to the console. The
 * picker now offers the tags of the row's category there, and a refused
 * save says why.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import TagSetsModule from '../../src/modules/tagsets/TagSetsModule.js';
import { showError } from '../../src/utils/notifications.js';

const OWN = { id: 1, name: 'Current' };
const SHARED = { id: 3, name: 'Household', _shared: true, _canWrite: true };
const MY_TAG = { id: 30, name: 'Mine', color: '#a00' };
const SHOP = { id: 5, name: 'Shop', tags: [{ id: 21, name: 'Tesco', color: '#0a0' }] };
const REFUSAL = 'This account belongs to someone else, who cannot see one of the tags. Choose tags from a category shared with them, or no tags.';

let requests;

beforeEach(() => {
    global.OC = { generateUrl: (p) => p, requestToken: 'tok' };
    requests = [];
    global.fetch = vi.fn(async (url, init = {}) => {
        requests.push(`${init.method || 'GET'} ${url}`);
        if ((init.method || 'GET') === 'PUT') {
            return { ok: false, status: 400, statusText: '', json: async () => ({ error: REFUSAL }) };
        }
        return { ok: true, status: 200, headers: { get: () => null }, json: async () => (url.endsWith('/tags/global') ? [MY_TAG] : []) };
    });
    document.body.innerHTML = '<table><tr><td class="tags-column editable-cell editing"><span class="cell-display"></span></td></tr></table>';
});

afterEach(() => {
    delete global.fetch;
    delete global.OC;
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

function makeModule() {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = {
        accounts: [OWN, SHARED],
        getTransactionTagIds: () => [],
        loadTransactionTags: vi.fn(),
        renderTransactionTags: () => '',
    };
    mod.loadTagSetsForCategory = vi.fn(async () => [SHOP]);
    mod.cancelInlineEdit = vi.fn();
    return mod;
}

const offered = () => [...document.querySelectorAll('.tags-autocomplete-item')].map(el => parseInt(el.dataset.tagId, 10));

describe('the inline tag picker', () => {
    it('offers only the category\'s tags on a row in an account shared with you', async () => {
        const mod = makeModule();
        const cell = document.querySelector('td');

        await mod.createTagsEditor(cell, { id: 9, accountId: SHARED.id, categoryId: 12 });

        expect(requests).not.toContain('GET /apps/budget/api/tags/global');
        expect(offered()).toEqual([21]);
    });

    it('offers your own tags too on a row in your own account', async () => {
        const mod = makeModule();
        const cell = document.querySelector('td');

        await mod.createTagsEditor(cell, { id: 9, accountId: OWN.id, categoryId: 12 });

        expect(offered().sort()).toEqual([21, 30]);
    });

    it('says why when the server refuses the tags', async () => {
        const mod = makeModule();
        const cell = document.querySelector('td');

        await mod.saveTagsFromEditor(cell, new Set([30]), 9);

        expect(showError).toHaveBeenCalledWith(REFUSAL);
        expect(mod.cancelInlineEdit).toHaveBeenCalledWith(cell);
    });
});

describe('the edit form\'s tag boxes', () => {
    it('say why when the server refuses the tags', async () => {
        const mod = Object.create(TagSetsModule.prototype);
        mod.app = { transactionTags: {} };

        expect(await mod.saveTransactionTags(9, [30])).toBe(false);

        expect(showError).toHaveBeenCalledWith(REFUSAL);
    });
});
