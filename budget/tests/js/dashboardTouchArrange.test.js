/**
 * Arranging the dashboard on a touch screen.
 *
 * On a phone the unlocked tile toolbar was laid over the tile's header, so
 * it hid the title ("Upco…"), and gridstack took every swipe over a tile as
 * a drag, so the page would hardly scroll. On a device that cannot hover the
 * toolbar now sits above the header, and tiles are reordered with the
 * toolbar's Move earlier / Move later buttons instead of by dragging.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('@nextcloud/l10n', () => ({
    translate: (_app, text, params = {}) =>
        String(text).replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
    translatePlural: (_app, singular, plural, count) => (count === 1 ? singular : plural),
    getLanguage: () => 'en',
    getLocale: () => 'en',
}));

import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';

const realMatchMedia = window.matchMedia;

afterEach(() => {
    document.body.innerHTML = '';
    window.matchMedia = realMatchMedia;
});

function setTouch(touch) {
    window.matchMedia = vi.fn(query => ({
        matches: query === '(hover: none)' ? touch : false,
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
    }));
}

function dashboard({ locked }) {
    document.body.innerHTML = `
        <div id="dashboard-view">
            <div id="dashboard-hint"><span></span><span></span></div>
            <button id="toggle-dashboard-lock-btn"><span id="lock-btn-icon"></span><span id="lock-btn-text"></span></button>
            <div class="dashboard-hero">
                <div class="hero-card" data-widget-id="netWorth" data-widget-category="hero"></div>
            </div>
            <div class="dashboard-card" data-widget-id="upcomingBills" data-widget-category="widget">
                <div class="card-header"><h3>Upcoming Bills</h3></div>
            </div>
        </div>`;
    const mod = Object.create(DashboardModule.prototype);
    mod.app = { dashboardLocked: locked, dashboardConfig: { hero: {}, widgets: { sizes: {} } } };
    mod.gridstack = { enable: vi.fn(), disable: vi.fn() };
    mod._saveSettings = vi.fn().mockResolvedValue();
    mod.updateAddTilesMenu = () => {};
    mod._compactEmptyTiles = () => {};
    return mod;
}

describe('unlocking the dashboard', () => {
    it('lets tiles be dragged with a mouse', async () => {
        setTouch(false);
        const mod = dashboard({ locked: true });

        await mod.toggleDashboardLock();

        expect(mod.gridstack.enable).toHaveBeenCalled();
        expect(mod.gridstack.disable).not.toHaveBeenCalled();
        expect(document.querySelector('.hero-card').getAttribute('draggable')).toBe('true');
        expect(document.querySelector('#dashboard-hint span:last-child').textContent)
            .toBe('Drag tiles to reorder your dashboard');
    });

    it('keeps dragging off on a touch screen, so a swipe scrolls the page', async () => {
        setTouch(true);
        const mod = dashboard({ locked: true });

        await mod.toggleDashboardLock();

        expect(mod.gridstack.enable).not.toHaveBeenCalled();
        expect(mod.gridstack.disable).toHaveBeenCalled();
        expect(document.querySelector('.hero-card').hasAttribute('draggable')).toBe(false);
        expect(document.querySelector('#dashboard-hint span:last-child').textContent)
            .toBe('Use the arrows on each tile to reorder your dashboard');
    });

    it('puts the tile toolbar before the header, not on top of it', async () => {
        setTouch(true);
        const mod = dashboard({ locked: true });

        await mod.toggleDashboardLock();

        const card = document.querySelector('.dashboard-card');
        expect(card.firstElementChild.classList.contains('widget-tile-controls')).toBe(true);
        expect(card.querySelector('.card-header h3').textContent).toBe('Upcoming Bills');
    });
});

describe('locking the dashboard', () => {
    it('turns dragging off on any device', async () => {
        setTouch(false);
        const mod = dashboard({ locked: false });

        await mod.toggleDashboardLock();

        expect(mod.gridstack.disable).toHaveBeenCalled();
        expect(mod.gridstack.enable).not.toHaveBeenCalled();
        expect(document.querySelector('.widget-tile-controls')).toBeNull();
    });
});
