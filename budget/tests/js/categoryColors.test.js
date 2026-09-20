/**
 * Category colours (#392).
 *
 * The category form always started on the same blue, so with twenty
 * categories every one had to be given a colour by hand, and a new one had to
 * be checked against all the others. A new category now starts on a colour
 * nothing else uses yet, and selected categories can be given new colours in
 * one go.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

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

const confirmDialog = vi.fn();
vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: (...args) => confirmDialog(...args),
}));

import { CATEGORY_PALETTE, nextCategoryColor, distinctCategoryColors } from '../../src/utils/colors.js';
import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';

describe('palette', () => {
    it('holds distinct six-digit colours', () => {
        expect(CATEGORY_PALETTE.length).toBeGreaterThanOrEqual(24);
        expect(new Set(CATEGORY_PALETTE).size).toBe(CATEGORY_PALETTE.length);
        CATEGORY_PALETTE.forEach(color => expect(color).toMatch(/^#[0-9a-f]{6}$/));
    });
});

describe('nextCategoryColor', () => {
    it('starts at the top of the palette', () => {
        expect(nextCategoryColor([])).toBe(CATEGORY_PALETTE[0]);
    });

    it('skips colours already in use, whatever their case', () => {
        const [first, second, third] = CATEGORY_PALETTE;

        expect(nextCategoryColor([first.toUpperCase(), second])).toBe(third);
    });

    it('ignores colours that are not from the palette, and missing ones', () => {
        expect(nextCategoryColor(['#123456', null, undefined, ''])).toBe(CATEGORY_PALETTE[0]);
    });

    it('reuses the least used colour once every one is taken', () => {
        const used = [...CATEGORY_PALETTE, ...CATEGORY_PALETTE];
        used.splice(used.indexOf(CATEGORY_PALETTE[5]), 1);

        expect(nextCategoryColor(used)).toBe(CATEGORY_PALETTE[5]);
    });
});

describe('distinctCategoryColors', () => {
    it('gives each category its own colour, avoiding the ones in use', () => {
        const colors = distinctCategoryColors(3, [CATEGORY_PALETTE[0]]);

        expect(colors).toEqual([CATEGORY_PALETTE[1], CATEGORY_PALETTE[2], CATEGORY_PALETTE[3]]);
    });
});

describe('category form', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <form id="category-form">
                <input id="category-id" value="">
                <input type="color" id="category-color" value="#3b82f6">
            </form>`;
    });

    it('starts a new category on a colour no other category uses', () => {
        const mod = Object.create(CategoriesModule.prototype);
        mod.app = { allCategories: [{ id: 1, color: CATEGORY_PALETTE[0] }, { id: 2, color: CATEGORY_PALETTE[1] }] };

        mod.resetCategoryForm();

        expect(document.getElementById('category-color').value).toBe(CATEGORY_PALETTE[2]);
    });
});

describe('recolouring selected categories', () => {
    let calls;
    let mod;

    beforeEach(() => {
        document.body.innerHTML = '<div id="category-bulk-toolbar"></div><span id="category-bulk-count"></span>';
        calls = [];
        global.OC = { generateUrl: url => url, requestToken: 'tok' };
        global.fetch = vi.fn(async (url, options) => {
            calls.push({ url, method: options?.method, body: options?.body ? JSON.parse(options.body) : null });
            return { ok: true, status: 200, json: async () => ({}) };
        });
        confirmDialog.mockReset();

        mod = Object.create(CategoriesModule.prototype);
        mod.app = {
            allCategories: [
                { id: 1, name: 'Food', color: CATEGORY_PALETTE[0] },
                { id: 2, name: 'Rent', color: '#3b82f6' },
                { id: 3, name: 'Fuel', color: '#3b82f6' },
                { id: 4, name: 'Gym', color: CATEGORY_PALETTE[1] },
            ],
            loadInitialData: vi.fn(async () => {}),
        };
        mod.selectedCategoryIds = new Set([2, 3]);
        mod.findCategoryById = id => mod.app.allCategories.find(c => c.id === id);
        mod.loadCategories = vi.fn(async () => {});
    });

    afterEach(() => {
        delete global.OC;
        delete global.fetch;
    });

    it('gives each selected category a different colour the others are not using', async () => {
        confirmDialog.mockResolvedValue(true);

        await mod.recolorSelectedCategories();

        const puts = calls.filter(call => call.method === 'PUT');
        expect(puts.map(call => call.url)).toEqual(['/apps/budget/api/categories/2', '/apps/budget/api/categories/3']);
        expect(puts.map(call => call.body)).toEqual([{ color: CATEGORY_PALETTE[2] }, { color: CATEGORY_PALETTE[3] }]);
        expect(mod.loadCategories).toHaveBeenCalled();
    });

    it('asks first, and changes nothing when the answer is no', async () => {
        confirmDialog.mockResolvedValue(false);

        await mod.recolorSelectedCategories();

        expect(confirmDialog).toHaveBeenCalledTimes(1);
        expect(calls).toEqual([]);
    });
});
