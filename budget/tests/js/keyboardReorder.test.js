/**
 * Reordering without a pointer.
 *
 * The category tree, the dashboard's hero tiles and grid tiles, and the
 * accounts tile's account list could only be reordered by dragging, which a
 * keyboard (and, for HTML5 drag, a touch screen) cannot do. The category tree
 * takes Alt+Arrow keys, like the accounts column list already did; the
 * dashboard gets Move earlier / Move later buttons in its unlocked tile
 * toolbar. Each saves through the same path a drop does.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
    getLanguage: () => 'en',
    getLocale: () => 'en',
}));

import CategoriesModule from '../../src/modules/categories/CategoriesModule.js';
import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

afterEach(() => {
    document.body.innerHTML = '';
});

const node = (id, children = '') => `
    <div class="category-node">
        <div class="category-item" data-category-id="${id}" tabindex="0"></div>
        ${children ? `<div class="category-children">${children}</div>` : ''}
    </div>`;

describe('category tree: Alt+Arrow targets', () => {
    function tree() {
        document.body.innerHTML = `<div id="categories-tree">${node(1)}${node(2, node(21) + node(22))}${node(3)}</div>`;
        const mod = Object.create(CategoriesModule.prototype);
        const item = (id) => document.querySelector(`.category-item[data-category-id="${id}"]`);
        return { mod, item };
    }

    it('moves up and down among siblings', () => {
        const { mod, item } = tree();
        expect(mod.keyboardReorderTarget(item(2), 'ArrowUp')).toEqual({ targetId: 1, position: 'above' });
        expect(mod.keyboardReorderTarget(item(2), 'ArrowDown')).toEqual({ targetId: 3, position: 'below' });
        expect(mod.keyboardReorderTarget(item(22), 'ArrowUp')).toEqual({ targetId: 21, position: 'above' });
    });

    it('stops at the ends of a sibling group', () => {
        const { mod, item } = tree();
        expect(mod.keyboardReorderTarget(item(1), 'ArrowUp')).toBeNull();
        expect(mod.keyboardReorderTarget(item(3), 'ArrowDown')).toBeNull();
        expect(mod.keyboardReorderTarget(item(21), 'ArrowUp')).toBeNull();
    });

    it('nests under the category above, and moves out to the parent level', () => {
        const { mod, item } = tree();
        expect(mod.keyboardReorderTarget(item(3), 'ArrowRight')).toEqual({ targetId: 2, position: 'child' });
        expect(mod.keyboardReorderTarget(item(21), 'ArrowLeft')).toEqual({ targetId: 2, position: 'below' });
        expect(mod.keyboardReorderTarget(item(1), 'ArrowLeft')).toBeNull();
    });

    it('ignores keys that are not a move', () => {
        const { mod, item } = tree();
        expect(mod.keyboardReorderTarget(item(2), 'Enter')).toBeUndefined();
    });

    it('Alt+ArrowUp on a focused item calls the same reorder a drop does', () => {
        const { mod, item } = tree();
        mod.reorderCategory = vi.fn().mockResolvedValue();
        mod.setupDragAndDrop();

        item(2).dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', altKey: true, bubbles: true }));

        expect(mod.reorderCategory).toHaveBeenCalledWith(2, 1, 'above');
    });
});

describe('dashboard hero tiles: Move earlier / later', () => {
    function hero() {
        document.body.innerHTML = `<div class="dashboard-hero">
            <div class="hero-card" data-widget-category="hero" data-widget-id="a"></div>
            <div class="hero-card" data-widget-category="hero" data-widget-id="b" style="display: none"></div>
            <div class="hero-card" data-widget-category="hero" data-widget-id="c"></div>
        </div>`;
        const mod = Object.create(DashboardModule.prototype);
        mod.app = { dashboardConfig: {} };
        mod.saveDashboardVisibility = vi.fn();
        return mod;
    }
    const order = () => Array.from(document.querySelectorAll('.hero-card')).map(c => c.dataset.widgetId);

    it('swaps with the next visible tile and saves the order', () => {
        const mod = hero();
        const a = document.querySelector('[data-widget-id="a"]');

        expect(mod.moveHeroTile(a, 1)).toBe(true);

        expect(order()).toEqual(['b', 'c', 'a']);
        expect(mod.dashboardConfig.hero.order).toEqual(['b', 'c', 'a']);
        expect(mod.saveDashboardVisibility).toHaveBeenCalledWith('hero');
    });

    it('does nothing at the start of the row', () => {
        const mod = hero();
        const a = document.querySelector('[data-widget-id="a"]');

        expect(mod.moveHeroTile(a, -1)).toBe(false);
        expect(mod.saveDashboardVisibility).not.toHaveBeenCalled();
    });
});
