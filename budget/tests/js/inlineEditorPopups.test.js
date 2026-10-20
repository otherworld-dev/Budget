/**
 * The Transactions list's inline category and tag pickers (#399, round 6).
 *
 * Their dropdowns opened inside the cell, absolutely placed, so the box
 * that scrolls the table sideways cut them off: in the last rows of a
 * short list only the first item or two showed, behind a scrollbar. They
 * are fixed to the window now, placed from the cell as the row's ⋮ menu
 * is, above the cell when there's no room below, and they follow the cell
 * when anything scrolls or the window is resized. They stay in the cell's
 * DOM, so focus, the keyboard and a click outside work as before.
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
import { placeCellPopup, floatCellPopup } from '../../src/utils/cellPopup.js';
import { cascadedDeclarations, THEMES, useTheme } from './helpers/cascade.js';

const WIDE = { media: ['(min-width: 641px)'] };
const POPUP_HEIGHT = 200;
const POPUP_WIDTH = 250;

/** Where each element is on screen, as a test sets it */
let rects;

beforeEach(() => {
    rects = new Map();
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function () {
        const r = rects.get(this) ?? rects.get(this.closest('.editable-cell')) ?? { top: 0, left: 0, width: 0, height: 0 };
        return { ...r, right: r.left + r.width, bottom: r.top + r.height, x: r.left, y: r.top };
    });
    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(function () {
        return this.classList.contains('cell-popup') && this.style.display !== 'none' ? POPUP_HEIGHT : 0;
    });
    vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockImplementation(function () {
        return this.classList.contains('cell-popup') ? POPUP_WIDTH : 0;
    });
    window.innerHeight = 900;
    window.innerWidth = 1280;
    globalThis.OC = { generateUrl: (p) => p, requestToken: 'tok' };
    globalThis.fetch = vi.fn(async (url) => ({
        ok: true, status: 200, headers: { get: () => null },
        json: async () => (url.endsWith('/tags/global') ? [{ id: 30, name: 'Mine', color: '#a00' }] : []),
    }));
    document.body.innerHTML = `
        <div id="app-content"><div id="transactions-view"><div class="table-container"><div class="table-scroll">
            <table id="transactions-table" class="transactions-table enhanced"><tbody>
                <tr class="transaction-row"><td class="category-column editable-cell" id="cat1"></td><td class="tags-column editable-cell" id="tags1"></td></tr>
                <tr class="transaction-row"><td class="category-column editable-cell" id="cat2"></td><td class="tags-column editable-cell" id="tags2"></td></tr>
                <tr class="transaction-row scheduled-transaction"><td class="category-column editable-cell editing" id="cat3"></td><td class="tags-column editable-cell" id="tags3"></td></tr>
            </tbody></table>
        </div></div></div></div>`;
});

afterEach(() => {
    vi.restoreAllMocks();
    delete globalThis.fetch;
    delete globalThis.OC;
    document.body.innerHTML = '';
});

function makeModule() {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = {
        accounts: [{ id: 1, name: 'Current' }],
        categories: [{ id: 20, name: 'Food', type: 'expense', parentId: null }],
        getTransactionTagIds: () => [],
        loadTransactionTags: vi.fn(),
        renderTransactionTags: () => '',
    };
    mod.loadTagSetsForCategory = vi.fn(async () => [{ id: 5, name: 'Shop', tags: [{ id: 21, name: 'Tesco', color: '#0a0' }] }]);
    mod.cancelInlineEdit = vi.fn();
    mod.saveInlineEdit = vi.fn();
    return mod;
}

const TX = { id: 9, accountId: 1, categoryId: 20 };
const cell = (id) => document.getElementById(id);
const px = (value) => parseFloat(value);

describe('placing a cell\'s popup', () => {
    let popup;
    let anchor;

    beforeEach(() => {
        anchor = cell('cat1');
        popup = document.createElement('div');
        popup.className = 'category-autocomplete-dropdown cell-popup';
        anchor.appendChild(popup);
    });

    it('puts it under the cell, at the cell\'s left edge', () => {
        rects.set(anchor, { top: 300, left: 500, width: 120, height: 34 });
        placeCellPopup(popup, anchor);

        expect(px(popup.style.top)).toBe(334);
        expect(px(popup.style.left)).toBe(500);
        expect(popup.classList.contains('cell-popup--above')).toBe(false);
    });

    it('puts it above the cell when it would run off the bottom of the window', () => {
        rects.set(anchor, { top: 800, left: 500, width: 120, height: 34 });
        placeCellPopup(popup, anchor);

        expect(px(popup.style.top)).toBe(800 - POPUP_HEIGHT);
        expect(popup.classList.contains('cell-popup--above')).toBe(true);
    });

    it('stays under the cell when there is less room above than below', () => {
        rects.set(anchor, { top: 120, left: 500, width: 120, height: 34 });
        window.innerHeight = 300;
        placeCellPopup(popup, anchor);

        expect(px(popup.style.top)).toBe(154);
        expect(popup.classList.contains('cell-popup--above')).toBe(false);
    });

    it('keeps it inside the window at the right', () => {
        rects.set(anchor, { top: 300, left: 1200, width: 70, height: 34 });
        placeCellPopup(popup, anchor);

        expect(px(popup.style.left)).toBe(1280 - 8 - POPUP_WIDTH);
    });

    it('follows the cell when anything scrolls or the window is resized', () => {
        rects.set(anchor, { top: 300, left: 500, width: 120, height: 34 });
        floatCellPopup(popup, anchor);

        rects.set(anchor, { top: 250, left: 420, width: 120, height: 34 });
        document.querySelector('.table-scroll').dispatchEvent(new Event('scroll'));
        expect(px(popup.style.top)).toBe(284);
        expect(px(popup.style.left)).toBe(420);

        rects.set(anchor, { top: 820, left: 420, width: 120, height: 34 });
        window.dispatchEvent(new Event('resize'));
        expect(popup.classList.contains('cell-popup--above')).toBe(true);
    });

    it('lets go once the editor is gone', () => {
        const remove = vi.spyOn(window, 'removeEventListener');
        rects.set(anchor, { top: 300, left: 500, width: 120, height: 34 });
        floatCellPopup(popup, anchor);

        popup.remove();
        window.dispatchEvent(new Event('scroll'));

        expect(remove).toHaveBeenCalledWith('scroll', expect.any(Function), true);
        expect(remove).toHaveBeenCalledWith('resize', expect.any(Function));
    });
});

describe('the inline category picker', () => {
    it('opens fixed to the window, so the table\'s scroller can\'t cut it off', () => {
        rects.set(cell('cat1'), { top: 300, left: 500, width: 120, height: 34 });
        makeModule().createCategoryEditor(cell('cat1'), '20', TX);
        const dropdown = cell('cat1').querySelector('.category-autocomplete-dropdown');

        expect(dropdown.style.display).toBe('block');
        expect(dropdown.classList.contains('cell-popup')).toBe(true);
        expect(cascadedDeclarations(dropdown, 'light', WIDE).position).toBe('fixed');
        expect(cascadedDeclarations(dropdown, 'light').position).toBe('fixed');
        expect(px(dropdown.style.top)).toBe(334);
        expect(px(dropdown.style.left)).toBe(500);
    });

    it('opens above the cell in the last row near the bottom of the window', () => {
        rects.set(cell('cat3'), { top: 820, left: 500, width: 120, height: 34 });
        makeModule().createCategoryEditor(cell('cat3'), '20', TX);
        const dropdown = cell('cat3').querySelector('.category-autocomplete-dropdown');

        expect(dropdown.classList.contains('cell-popup--above')).toBe(true);
        expect(px(dropdown.style.top)).toBe(820 - POPUP_HEIGHT);
    });

    it('is placed again when the typed filter changes its height', () => {
        rects.set(cell('cat1'), { top: 300, left: 500, width: 120, height: 34 });
        makeModule().createCategoryEditor(cell('cat1'), '20', TX);
        const input = cell('cat1').querySelector('.category-autocomplete-input');
        const dropdown = cell('cat1').querySelector('.category-autocomplete-dropdown');

        rects.set(cell('cat1'), { top: 810, left: 500, width: 120, height: 34 });
        input.value = 'Fo';
        input.dispatchEvent(new Event('input'));

        expect(dropdown.classList.contains('cell-popup--above')).toBe(true);
    });

    it('still picks a category with the mouse and leaves the cell\'s own events alone', () => {
        rects.set(cell('cat1'), { top: 300, left: 500, width: 120, height: 34 });
        const mod = makeModule();
        mod.createCategoryEditor(cell('cat1'), '', TX);
        const item = cell('cat1').querySelector('.category-autocomplete-item[data-category-id="20"]');

        item.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));

        expect(mod.saveInlineEdit).toHaveBeenCalledWith(cell('cat1'), 'categoryId', '20');
        expect(cell('cat1').contains(item)).toBe(true);
    });
});

describe('the inline tag picker', () => {
    it('opens fixed to the window, placed from the cell', async () => {
        rects.set(cell('tags1'), { top: 300, left: 640, width: 100, height: 34 });
        await makeModule().createTagsEditor(cell('tags1'), TX);
        const dropdown = cell('tags1').querySelector('.tags-autocomplete-dropdown');

        expect(dropdown.style.display).toBe('block');
        expect(dropdown.classList.contains('cell-popup')).toBe(true);
        expect(cascadedDeclarations(dropdown, 'light', WIDE).position).toBe('fixed');
        expect(px(dropdown.style.top)).toBe(334);
        expect(px(dropdown.style.left)).toBe(640);
    });

    it('opens above the cell near the bottom of the window', async () => {
        rects.set(cell('tags3'), { top: 820, left: 640, width: 100, height: 34 });
        await makeModule().createTagsEditor(cell('tags3'), TX);
        const dropdown = cell('tags3').querySelector('.tags-autocomplete-dropdown');

        expect(dropdown.classList.contains('cell-popup--above')).toBe(true);
        expect(px(dropdown.style.top)).toBe(820 - POPUP_HEIGHT);
    });
});

describe('the popup\'s look', () => {
    for (const theme of THEMES) {
        it(`keeps its fill and border in ${theme}, closed off at the top when it opens above`, () => {
            useTheme(theme);
            const popup = document.createElement('div');
            popup.className = 'category-autocomplete-dropdown cell-popup cell-popup--above';
            cell('cat1').appendChild(popup);
            const declared = cascadedDeclarations(popup, theme, WIDE);

            expect(declared.background).toBe('var(--color-main-background)');
            expect(declared['border-top']).toBe('1px solid var(--color-border)');
            expect(declared['border-bottom']).toBe('none');
        });
    }

    it('isn\'t faded with a scheduled row\'s cells while its cell is being edited', () => {
        // A faded cell would show the rows through the popup, and keep it under the sticky Actions column
        expect(cascadedDeclarations(cell('cat3'), 'light', WIDE).opacity).toBe('1');
        expect(cascadedDeclarations(cell('cat3'), 'light', { ...WIDE, hover: true }).opacity).toBe('1');
    });
});
