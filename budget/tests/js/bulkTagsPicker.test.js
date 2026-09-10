/**
 * Bulk add/remove of global tags across selected transactions (#379).
 *
 * Only global tags are offered. A category tag is validated against the
 * transaction's own category, so across a mixed selection it would apply to
 * only some of the selected rows — the server refuses them for that reason,
 * and the picker must never present one.
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
    // whole point of the string under test here.
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

/** Bodies of every POST made during a call, parsed. */
let posted;

function makeModule({ globalTags = [HOLIDAY, WORK, FINISHED_TRIP], selected = [1, 2] } = {}) {
    const mod = Object.create(TransactionsModule.prototype);
    mod.selectedTransactions = new Set(selected);
    mod.allMatchingSelection = null;
    mod.app = { currentPage: 3, loadTransactions: vi.fn() };

    global.fetch = vi.fn(async (url, options) => {
        if (options && options.method === 'POST') {
            posted.push({ url, body: JSON.parse(options.body) });
            return { ok: true, json: async () => ({ success: 2, failed: 0, errors: [] }) };
        }
        return { ok: true, json: async () => globalTags };
    });

    return mod;
}

const boxes = (containerId) =>
    Array.from(document.querySelectorAll(`#${containerId} input[type="checkbox"]`));

const check = (containerId, tagId) => {
    const box = document.querySelector(`#${containerId} input[value="${tagId}"]`);
    box.checked = true;
    box.dispatchEvent(new Event('change'));
    return box;
};

beforeEach(() => {
    posted = [];
    vi.clearAllMocks();
    global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
    document.body.innerHTML = `
        <div id="bulk-tags-modal" style="display: none;">
            <p id="bulk-tags-description"></p>
            <div id="bulk-tags-add"></div>
            <div id="bulk-tags-remove"></div>
        </div>`;
});

afterEach(() => {
    delete global.OC;
    delete global.fetch;
});

describe('bulk tag picker', () => {
    it('offers every visible global tag in both the add and remove lists', async () => {
        const mod = makeModule();

        await mod.showBulkTagsModal();

        expect(boxes('bulk-tags-add').map(b => parseInt(b.value))).toEqual([HOLIDAY.id, WORK.id]);
        expect(boxes('bulk-tags-remove').map(b => parseInt(b.value))).toEqual([HOLIDAY.id, WORK.id]);
    });

    it('leaves hidden tags out of both lists', async () => {
        const mod = makeModule();

        await mod.showBulkTagsModal();

        const offered = boxes('bulk-tags-add').map(b => parseInt(b.value));
        expect(offered).not.toContain(FINISHED_TRIP.id);
    });

    it('says how many transactions the change will apply to', async () => {
        const mod = makeModule({ selected: [1, 2, 3, 4] });

        await mod.showBulkTagsModal();

        // One whole sentence, not a fragment either side of the count:
        // a split string cannot be reordered by a translator.
        const text = document.getElementById('bulk-tags-description').textContent;
        expect(text).toContain('4 selected transactions');
    });

    it('says so when there are no global tags to offer', async () => {
        const mod = makeModule({ globalTags: [] });

        await mod.showBulkTagsModal();

        expect(boxes('bulk-tags-add')).toHaveLength(0);
        expect(document.getElementById('bulk-tags-add').textContent).toContain('No tags available');
    });

    it('does not open for an empty selection', async () => {
        const mod = makeModule({ selected: [] });

        await mod.showBulkTagsModal();

        expect(document.getElementById('bulk-tags-modal').style.display).toBe('none');
    });

    it('unchecks a tag on the other side, because the server refuses both at once', async () => {
        const mod = makeModule();
        await mod.showBulkTagsModal();

        const removeHoliday = check('bulk-tags-remove', HOLIDAY.id);
        expect(removeHoliday.checked).toBe(true);

        check('bulk-tags-add', HOLIDAY.id);

        expect(removeHoliday.checked).toBe(false);
        expect(document.querySelector('#bulk-tags-add input[value="10"]').checked).toBe(true);
    });
});

describe('bulk tag submit', () => {
    it('posts the selected ids with both tag deltas', async () => {
        const mod = makeModule({ selected: [7, 8] });
        await mod.showBulkTagsModal();
        check('bulk-tags-add', HOLIDAY.id);
        check('bulk-tags-remove', WORK.id);

        await mod.submitBulkTags();

        expect(posted).toHaveLength(1);
        expect(posted[0].url).toContain('/apps/budget/api/transactions/bulk-tags');
        expect(posted[0].body).toEqual({
            ids: [7, 8],
            addTagIds: [HOLIDAY.id],
            removeTagIds: [WORK.id],
        });
    });

    it('warns and posts nothing when no tag is checked', async () => {
        const mod = makeModule();
        await mod.showBulkTagsModal();

        await mod.submitBulkTags();

        expect(posted).toHaveLength(0);
        expect(showWarning).toHaveBeenCalled();
    });

    it('clears the selection and reloads once the change lands', async () => {
        const mod = makeModule();
        await mod.showBulkTagsModal();
        check('bulk-tags-add', HOLIDAY.id);

        await mod.submitBulkTags();

        expect(showSuccess).toHaveBeenCalled();
        expect(mod.selectedTransactions.size).toBe(0);
        expect(mod.app.loadTransactions).toHaveBeenCalled();
        expect(document.getElementById('bulk-tags-modal').style.display).toBe('none');
    });
});
