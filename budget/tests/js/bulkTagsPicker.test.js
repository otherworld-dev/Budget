/**
 * Bulk add/remove of tags across selected transactions (#379).
 *
 * Global tags apply to every selected row. A category tag belongs to exactly
 * one category, so across a mixed selection it reaches only the rows in that
 * category — the picker says how many rather than leaving the user to guess,
 * and the server refuses a tag for a category the selection does not contain.
 *
 * The two lists are deltas, not a replace: an unchecked tag means "leave this
 * one alone". That is why hidden tags can simply stay hidden here, unlike the
 * single-transaction picker, which saves the full set of checked boxes and so
 * has to keep showing a hidden tag the transaction already carries.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    // Substitutes %n, as the real translatePlural does — the count is the
    // whole point of several strings under test here.
    translatePlural: (_app, singular, plural, count) =>
        String(count === 1 ? singular : plural).replace(/%n/g, count),
}));

vi.mock('../../src/utils/notifications.js', () => ({
    showSuccess: vi.fn(),
    showError: vi.fn(),
    showWarning: vi.fn(),
    showInfo: vi.fn(),
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import { showWarning, showSuccess } from '../../src/utils/notifications.js';

const HOLIDAY = { id: 10, name: 'Holiday', color: '#111111' };
const WORK = { id: 11, name: 'Work', color: '#222222' };
const FINISHED_TRIP = { id: 12, name: '2025 trip', color: '#333333', hidden: true };

const TESCO = { id: 30, name: 'Tesco', color: '#444444', tagSetId: 7 };
const COSTCO = { id: 31, name: 'Costco', color: '#555555', tagSetId: 7 };
const OLD_SHOP = { id: 32, name: 'Closed shop', color: '#666666', tagSetId: 7, hidden: true };

const STORE_SET = {
    id: 7,
    name: 'Store',
    categoryId: 5,
    categoryName: 'Groceries',
    matchingCount: 12,
    tags: [TESCO, COSTCO, OLD_SHOP],
};

const defaultOptions = () => ({
    totalSelected: 50,
    globalTags: [HOLIDAY, WORK, FINISHED_TRIP],
    tagSets: [STORE_SET],
    unaffectedCount: 38,
});

/** Every request made during a call, in order. */
let posted;
let applyResponse;

function makeModule({ options = defaultOptions(), selected = [1, 2] } = {}) {
    const mod = Object.create(TransactionsModule.prototype);
    mod.selectedTransactions = new Set(selected);
    mod.allMatchingSelection = null;
    mod.app = { currentPage: 3, loadTransactions: vi.fn() };

    global.fetch = vi.fn(async (url, init) => {
        const body = init && init.body ? JSON.parse(init.body) : null;
        posted.push({ url, body });
        if (String(url).includes('bulk-tag-options')) {
            return { ok: true, json: async () => options };
        }
        return { ok: true, json: async () => applyResponse };
    });

    return mod;
}

const boxes = (containerId) =>
    Array.from(document.querySelectorAll(`#${containerId} input[type="checkbox"]`));
const boxIds = (containerId) => boxes(containerId).map(b => parseInt(b.value));

const check = (containerId, tagId) => {
    const box = document.querySelector(`#${containerId} input[value="${tagId}"]`);
    box.checked = true;
    box.dispatchEvent(new Event('change'));
    return box;
};

const applyRequests = () => posted.filter(p => /\/bulk-tags($|\?)/.test(String(p.url)));

beforeEach(() => {
    posted = [];
    applyResponse = { success: 50, failed: 0, errors: [], applied: {} };
    vi.clearAllMocks();
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    document.body.innerHTML = `
        <div id="bulk-tags-modal" style="display: none;">
            <p id="bulk-tags-description"></p>
            <div id="bulk-tags-add"></div>
            <div id="bulk-tags-remove"></div>
            <p id="bulk-tags-unaffected"></p>
        </div>`;
});

afterEach(() => {
    delete global.OC;
    delete global.fetch;
});

describe('bulk tag picker', () => {
    it('asks the server what applies to this exact selection', async () => {
        const mod = makeModule({ selected: [7, 8, 9] });

        await mod.showBulkTagsModal();

        // The categories in a cross-page selection are only knowable server
        // side, so the picker must post the ids rather than read local rows.
        expect(posted[0].url).toContain('/apps/budget/api/transactions/bulk-tag-options');
        expect(posted[0].body).toEqual({ ids: [7, 8, 9] });
    });

    it('offers every visible global tag in both lists', async () => {
        const mod = makeModule();

        await mod.showBulkTagsModal();

        expect(boxIds('bulk-tags-add')).toContain(HOLIDAY.id);
        expect(boxIds('bulk-tags-add')).toContain(WORK.id);
        expect(boxIds('bulk-tags-remove')).toContain(HOLIDAY.id);
    });

    it('offers the tag sets the selection actually contains', async () => {
        const mod = makeModule();

        await mod.showBulkTagsModal();

        expect(boxIds('bulk-tags-add')).toContain(TESCO.id);
        expect(boxIds('bulk-tags-add')).toContain(COSTCO.id);
    });

    it('names the category a tag set belongs to and how far it reaches', async () => {
        const mod = makeModule();

        await mod.showBulkTagsModal();

        const text = document.getElementById('bulk-tags-add').textContent;
        expect(text).toContain('Groceries');
        expect(text).toContain('Store');
        expect(text).toContain('12 of 50');
    });

    it('says how many selected rows no category tag reaches', async () => {
        const mod = makeModule();

        await mod.showBulkTagsModal();

        expect(document.getElementById('bulk-tags-unaffected').textContent).toContain('38');
    });

    it('stays quiet about unaffected rows when every row is covered', async () => {
        const mod = makeModule({
            options: { ...defaultOptions(), unaffectedCount: 0 },
        });

        await mod.showBulkTagsModal();

        expect(document.getElementById('bulk-tags-unaffected').textContent).toBe('');
    });

    it('leaves hidden tags out, global and category alike', async () => {
        const mod = makeModule();

        await mod.showBulkTagsModal();

        expect(boxIds('bulk-tags-add')).not.toContain(FINISHED_TRIP.id);
        expect(boxIds('bulk-tags-add')).not.toContain(OLD_SHOP.id);
    });

    it('says so when there is nothing at all to offer', async () => {
        const mod = makeModule({
            options: { totalSelected: 3, globalTags: [], tagSets: [], unaffectedCount: 3 },
        });

        await mod.showBulkTagsModal();

        expect(boxes('bulk-tags-add')).toHaveLength(0);
        expect(document.getElementById('bulk-tags-add').textContent).toContain('No tags available');
    });

    it('does not open for an empty selection', async () => {
        const mod = makeModule({ selected: [] });

        await mod.showBulkTagsModal();

        expect(document.getElementById('bulk-tags-modal').style.display).toBe('none');
        expect(posted).toHaveLength(0);
    });

    it('unchecks a tag on the other side, because the server refuses both at once', async () => {
        const mod = makeModule();
        await mod.showBulkTagsModal();

        const removeTesco = check('bulk-tags-remove', TESCO.id);
        expect(removeTesco.checked).toBe(true);

        check('bulk-tags-add', TESCO.id);

        expect(removeTesco.checked).toBe(false);
    });
});

describe('bulk tag submit', () => {
    it('posts the selected ids with both tag deltas', async () => {
        const mod = makeModule({ selected: [7, 8] });
        await mod.showBulkTagsModal();
        check('bulk-tags-add', TESCO.id);
        check('bulk-tags-remove', WORK.id);

        await mod.submitBulkTags();

        expect(applyRequests()).toHaveLength(1);
        expect(applyRequests()[0].body).toEqual({
            ids: [7, 8],
            addTagIds: [TESCO.id],
            removeTagIds: [WORK.id],
        });
    });

    it('warns and posts nothing when no tag is checked', async () => {
        const mod = makeModule();
        await mod.showBulkTagsModal();

        await mod.submitBulkTags();

        expect(applyRequests()).toHaveLength(0);
        expect(showWarning).toHaveBeenCalled();
    });

    it('reports a category tag that only reached part of the selection', async () => {
        applyResponse = { success: 50, failed: 0, errors: [], applied: { [TESCO.id]: 12 } };
        const mod = makeModule();
        await mod.showBulkTagsModal();
        check('bulk-tags-add', TESCO.id);

        await mod.submitBulkTags();

        // Claiming all 50 got the tag would be a lie the user cannot check.
        const said = showSuccess.mock.calls.map(c => String(c[0])).join(' ');
        expect(said).toContain('12');
        expect(said).toContain('Tesco');
    });

    it('does not single out a tag that reached everything', async () => {
        applyResponse = { success: 50, failed: 0, errors: [], applied: { [HOLIDAY.id]: 50 } };
        const mod = makeModule();
        await mod.showBulkTagsModal();
        check('bulk-tags-add', HOLIDAY.id);

        await mod.submitBulkTags();

        const said = showSuccess.mock.calls.map(c => String(c[0])).join(' ');
        expect(said).not.toContain('Holiday');
    });

    it('clears the selection and reloads once the change lands', async () => {
        const mod = makeModule();
        await mod.showBulkTagsModal();
        check('bulk-tags-add', HOLIDAY.id);

        await mod.submitBulkTags();

        expect(mod.selectedTransactions.size).toBe(0);
        expect(mod.app.loadTransactions).toHaveBeenCalled();
        expect(document.getElementById('bulk-tags-modal').style.display).toBe('none');
    });
});
