/**
 * Leaving the Transactions list's inline tag and category pickers (#399,
 * browser round 6).
 *
 * Escape saved the ticked tags anyway: it drew the table again, but the
 * old cell kept its 'editing' class, so the picker's blur handler saved
 * 200 ms later. The category picker did the same with a category picked
 * by the arrow keys. Escape now changes nothing in either.
 *
 * The tag picker also had no keyboard: the arrow keys highlighted nothing,
 * and Enter, Escape and a click outside each sent the same tags again. Now
 * the arrows move through the tags, Space ticks or unticks the highlighted
 * one, Enter ticks a highlighted tag not yet touched and saves, and an
 * edit sends at most one request, none when nothing changed.
 *
 * A click outside saves what was picked, in both pickers, as the other
 * inline editors do when they lose focus.
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
import { declarationsFor } from './helpers/stylesheet.js';

const GLOBAL_TAGS = [
    { id: 2493, name: 'Arbeit', color: '#a00' },
    { id: 2488, name: 'Auto', color: '#0a0' },
    { id: 2486, name: 'Ferien', color: '#00a' },
];
const TX = { id: 9, accountId: 1, categoryId: null, type: 'debit', amount: 5 };

let puts;
let rendered;

beforeEach(() => {
    vi.useFakeTimers();
    // jsdom has no layout to scroll
    Element.prototype.scrollIntoView = vi.fn();
    puts = [];
    rendered = 0;
    globalThis.OC = { generateUrl: (p) => p, requestToken: 'tok' };
    globalThis.fetch = vi.fn(async (url, init = {}) => {
        if ((init.method || 'GET') !== 'GET') puts.push({ method: init.method, url, body: JSON.parse(init.body) });
        return {
            ok: true, status: 200, headers: { get: () => null },
            json: async () => (url.endsWith('/tags/global') ? GLOBAL_TAGS : (init.method === 'PUT' ? { ...TX } : [])),
        };
    });
    document.body.innerHTML = '<div id="outside"></div><table><tbody><tr class="transaction-row">'
        + '<td class="tags-column editable-cell editing" data-transaction-id="9"><span class="cell-display"></span></td>'
        + '<td class="category-column editable-cell editing" data-transaction-id="9" data-field="categoryId"></td>'
        + '</tr></tbody></table>';
});

afterEach(() => {
    vi.useRealTimers();
    delete Element.prototype.scrollIntoView;
    delete globalThis.fetch;
    delete globalThis.OC;
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

/**
 * A module whose redraw of the table does what the real one does to the
 * open editor: the cell is replaced, and the old one, still marked
 * 'editing', drops out of the page.
 */
function makeModule(currentTagIds = []) {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = {
        accounts: [{ id: 1, name: 'Current' }],
        categories: [{ id: 20, name: 'Food', type: 'expense', parentId: null }, { id: 21, name: 'Fuel', type: 'expense', parentId: null }],
        transactions: [TX],
        getTransactionTagIds: () => [...currentTagIds],
        loadTransactionTags: vi.fn(async () => {}),
        renderTransactionTags: () => '',
        renderEnhancedTransactionsTable: () => {
            rendered++;
            const tbody = document.querySelector('tbody');
            tbody.innerHTML = tbody.innerHTML.replace(/ editing/g, '');
        },
        applyColumnVisibility: () => {},
    };
    mod.loadTagSetsForCategory = vi.fn(async () => []);
    return mod;
}

const tagCell = () => document.querySelector('td.tags-column');

async function openTags(mod) {
    const cell = tagCell();
    await mod.createTagsEditor(cell, TX);
    return { cell, input: cell.querySelector('.tags-autocomplete-input'), dropdown: cell.querySelector('.tags-autocomplete-dropdown') };
}

const key = (el, k) => el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
const pick = (dropdown, id) => dropdown.querySelector(`.tags-autocomplete-item[data-tag-id="${id}"]`)
    .dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
const highlighted = (dropdown) => [...dropdown.querySelectorAll('.tags-autocomplete-item.highlighted')].map(el => Number(el.dataset.tagId));

/** What the browser does when the old input leaves the page or focus moves on */
async function blurAndWait(input) {
    input.dispatchEvent(new Event('blur'));
    await vi.advanceTimersByTimeAsync(500);
}

describe('the inline tag picker', () => {
    it('saves nothing on Escape, even with a tag ticked', async () => {
        const mod = makeModule();
        const { input, dropdown } = await openTags(mod);

        pick(dropdown, 2493);
        key(input, 'Escape');
        await blurAndWait(input);

        expect(puts).toEqual([]);
        expect(rendered).toBe(1);
    });

    it('saves once on Enter, not again when the input then loses focus', async () => {
        const mod = makeModule();
        const { input, dropdown } = await openTags(mod);

        pick(dropdown, 2493);
        key(input, 'Enter');
        await vi.advanceTimersByTimeAsync(0);
        await blurAndWait(input);

        expect(puts).toEqual([{ method: 'PUT', url: '/apps/budget/api/transactions/9/tags', body: { tagIds: [2493] } }]);
    });

    it('saves what was ticked once on a click outside', async () => {
        const mod = makeModule();
        const { input, dropdown } = await openTags(mod);

        pick(dropdown, 2488);
        // The list closes every open editor on a click outside it, then the input's blur follows
        mod.closeAllInlineEditors();
        await blurAndWait(input);

        expect(puts).toEqual([{ method: 'PUT', url: '/apps/budget/api/transactions/9/tags', body: { tagIds: [2488] } }]);
    });

    it('sends nothing when the tags didn\'t change', async () => {
        const mod = makeModule([2486]);
        const { input, dropdown } = await openTags(mod);

        pick(dropdown, 2493);
        pick(dropdown, 2493);
        key(input, 'Enter');
        await blurAndWait(input);

        expect(puts).toEqual([]);
        expect(rendered).toBe(1);
    });

    it('moves through the tags with the arrow keys, round from either end', async () => {
        const { input, dropdown } = await openTags(makeModule());

        key(input, 'ArrowDown');
        expect(highlighted(dropdown)).toEqual([2493]);
        key(input, 'ArrowDown');
        expect(highlighted(dropdown)).toEqual([2488]);
        key(input, 'ArrowUp');
        key(input, 'ArrowUp');
        expect(highlighted(dropdown)).toEqual([2486]);
    });

    it('ticks and unticks the highlighted tag with Space, keeping it open', async () => {
        const { input, dropdown } = await openTags(makeModule());

        key(input, 'ArrowDown');
        key(input, 'ArrowDown');
        const space = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true });
        input.dispatchEvent(space);

        expect(space.defaultPrevented).toBe(true);
        expect(dropdown.querySelector('[data-tag-id="2488"]').classList.contains('selected')).toBe(true);
        expect(highlighted(dropdown)).toEqual([2488]);
        expect(dropdown.style.display).toBe('block');

        key(input, ' ');
        expect(dropdown.querySelector('[data-tag-id="2488"]').classList.contains('selected')).toBe(false);
        expect(puts).toEqual([]);
    });

    it('types a space into the filter when no tag is highlighted', async () => {
        const { input } = await openTags(makeModule());
        const space = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true });

        input.dispatchEvent(space);

        expect(space.defaultPrevented).toBe(false);
    });

    it('ticks the highlighted tag and saves on Enter, as the category picker picks its highlighted one', async () => {
        const mod = makeModule([2486]);
        const { input } = await openTags(mod);

        key(input, 'ArrowDown');
        key(input, 'Enter');
        await vi.advanceTimersByTimeAsync(0);
        await blurAndWait(input);

        expect(puts).toHaveLength(1);
        expect(puts[0].body.tagIds.sort()).toEqual([2486, 2493]);
    });

    it('leaves a tag ticked or unticked with Space as it is on Enter', async () => {
        const mod = makeModule([2486]);
        const { input } = await openTags(mod);

        key(input, 'ArrowDown');
        key(input, 'ArrowDown');
        key(input, 'ArrowDown');
        key(input, ' ');
        key(input, 'Enter');
        await vi.advanceTimersByTimeAsync(0);

        expect(puts).toEqual([{ method: 'PUT', url: '/apps/budget/api/transactions/9/tags', body: { tagIds: [] } }]);
    });

    it('rings the highlighted tag, ticked or not, so it shows on a ticked one\'s fill too', async () => {
        const { input, dropdown } = await openTags(makeModule([2493]));

        key(input, 'ArrowDown');
        const item = dropdown.querySelector('.highlighted');

        expect(item.classList.contains('selected')).toBe(true);
        expect(declarationsFor(item)).toMatchObject({
            background: 'var(--color-primary-element-light)',
            outline: '2px solid var(--color-primary-element)',
        });
    });

    it('keeps the highlight when the typed filter draws the list again', async () => {
        const { input, dropdown } = await openTags(makeModule());

        key(input, 'ArrowDown');
        key(input, 'ArrowDown');
        input.value = 'Au';
        input.dispatchEvent(new Event('input'));

        expect(highlighted(dropdown)).toEqual([2488]);
    });
});

describe('the inline category picker', () => {
    const catCell = () => document.querySelector('td.category-column');

    function open(mod) {
        const cell = catCell();
        mod.createCategoryEditor(cell, '', TX);
        return { cell, input: cell.querySelector('.category-autocomplete-input') };
    }

    it('saves nothing on Escape after the arrow keys moved to a category', async () => {
        const mod = makeModule();
        const { input } = open(mod);

        key(input, 'ArrowDown');
        key(input, 'ArrowDown');
        key(input, 'Escape');
        await blurAndWait(input);

        expect(puts).toEqual([]);
        expect(rendered).toBe(1);
    });

    it('saves once on Enter', async () => {
        const mod = makeModule();
        const { input } = open(mod);

        key(input, 'ArrowDown');
        key(input, 'ArrowDown');
        key(input, 'Enter');
        await vi.advanceTimersByTimeAsync(0);
        await blurAndWait(input);

        expect(puts).toEqual([{ method: 'PUT', url: '/apps/budget/api/transactions/9', body: { categoryId: 20 } }]);
    });

    it('saves the highlighted category on a click outside', async () => {
        const mod = makeModule();
        const { input } = open(mod);

        key(input, 'ArrowDown');
        key(input, 'ArrowDown');
        mod.closeAllInlineEditors();
        await blurAndWait(input);

        expect(puts).toEqual([{ method: 'PUT', url: '/apps/budget/api/transactions/9', body: { categoryId: 20 } }]);
    });
});
