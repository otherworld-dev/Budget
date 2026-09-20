/**
 * Deleting a category a project uses (#391).
 *
 * The server refuses the delete and names the project, before anything is
 * moved. The client checks the transaction counts up front (#332), so for a
 * project category, which nearly always has transactions, it used to ask
 * "Move them to Uncategorized and delete?" first: a question whose answer
 * cannot help, followed by the refusal. When a project uses the category or
 * one below it, the plain delete goes straight to the server instead.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
}));

const confirmDialog = vi.fn();
vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: (...args) => confirmDialog(...args),
}));

import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';

const REFUSAL = 'This category is used by the project "House renovation". Change or delete the project first.';

// Renovation (449) > Kitchen (450) > Appliances (451), and Bathroom (452)
const TREE = {
    id: 449, name: 'Renovation', children: [
        { id: 450, name: 'Kitchen', children: [{ id: 451, name: 'Appliances', children: [] }] },
        { id: 452, name: 'Bathroom', children: [] },
    ],
};

function jsonResponse(status, body) {
    return { ok: status >= 200 && status < 300, status, json: async () => body };
}

function makeModule(counts) {
    const mod = Object.create(CategoriesModule.prototype);
    mod.serverTransactionCounts = counts;
    mod.findCategoryById = (id) => (id === 449 ? TREE : null);
    return mod;
}

/** fetch stub: GET projects answers `projects`, DELETE is refused like the server does for a project category */
function stubServer(projects, { projectsFail = false } = {}) {
    const calls = [];
    global.fetch = vi.fn(async (url, options = {}) => {
        calls.push({ url, method: options.method || 'GET' });
        if (url === '/apps/budget/api/projects') {
            return projectsFail ? jsonResponse(500, { error: 'boom' }) : jsonResponse(200, projects);
        }
        if (url.startsWith('/apps/budget/api/categories/449')) {
            return projects.length > 0
                ? jsonResponse(400, { error: REFUSAL })
                : jsonResponse(200, { status: 'success' });
        }
        throw new Error('unexpected fetch ' + url);
    });
    return calls;
}

beforeEach(() => {
    confirmDialog.mockReset();
    confirmDialog.mockResolvedValue(true);
    global.OC = { generateUrl: (path) => path, requestToken: 'token' };
});

describe('_deleteCategoryWithReassign with a project on the category', () => {
    it('skips the move-transactions prompt and shows the server refusal when a project uses the category', async () => {
        const calls = stubServer([{ id: 1, categoryId: 449, allocations: [{ categoryId: 450, amount: 400 }] }]);
        const mod = makeModule({ 450: 2, 451: 1, 452: 1 });

        await expect(mod._deleteCategoryWithReassign(449, 'Renovation')).rejects.toThrow(REFUSAL);

        expect(confirmDialog).not.toHaveBeenCalled();
        const deletes = calls.filter(c => c.method === 'DELETE');
        expect(deletes).toEqual([{ url: '/apps/budget/api/categories/449', method: 'DELETE' }]);
    });

    it('does the same when only a subcategory amount below it is on a project', async () => {
        const calls = stubServer([{ id: 2, categoryId: 999, allocations: [{ categoryId: 451, amount: 50 }] }]);
        const mod = makeModule({ 451: 1 });

        await expect(mod._deleteCategoryWithReassign(449, 'Renovation')).rejects.toThrow(REFUSAL);

        expect(confirmDialog).not.toHaveBeenCalled();
        expect(calls.filter(c => c.method === 'DELETE').map(c => c.url))
            .toEqual(['/apps/budget/api/categories/449']);
    });

    it('still offers to move the transactions when no project uses the branch', async () => {
        const calls = stubServer([]);
        const mod = makeModule({ 450: 2 });

        const result = await mod._deleteCategoryWithReassign(449, 'Renovation');

        expect(confirmDialog).toHaveBeenCalledTimes(1);
        expect(result).toEqual({ deleted: true, reassigned: true });
        expect(calls.filter(c => c.method === 'DELETE').map(c => c.url))
            .toEqual(['/apps/budget/api/categories/449?reassign=true']);
    });

    it('does not ask about projects when there are no transactions to move', async () => {
        const calls = stubServer([]);
        const mod = makeModule({});

        const result = await mod._deleteCategoryWithReassign(449, 'Renovation');

        expect(result).toEqual({ deleted: true, reassigned: false });
        expect(calls.map(c => c.url)).toEqual(['/apps/budget/api/categories/449']);
    });

    it('falls back to the prompt when the projects cannot be loaded', async () => {
        stubServer([], { projectsFail: true });
        const mod = makeModule({ 450: 2 });

        const result = await mod._deleteCategoryWithReassign(449, 'Renovation');

        expect(confirmDialog).toHaveBeenCalledTimes(1);
        expect(result).toEqual({ deleted: true, reassigned: true });
    });
});
