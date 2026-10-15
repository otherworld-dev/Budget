/**
 * Rearranging the dashboard on a phone.
 *
 * A phone shows the tile grid in one column. Moving a tile there used to
 * save that one-column layout as the dashboard's layout, so every tile
 * came back a third of the width, stacked in one column, on the desktop.
 * A phone now saves only the order, shows its tiles in that order, and
 * leaves the desktop layout alone.
 *
 * These run the real gridstack.
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

const realInnerWidth = window.innerWidth;

const DESKTOP = {
    trendChart: { x: 0, y: 0, w: 3, h: 4 },
    spendingChart: { x: 0, y: 4, w: 2, h: 4 },
    accounts: { x: 2, y: 4, w: 1, h: 4 },
    upcomingBills: { x: 0, y: 8, w: 1, h: 4 },
};

afterEach(() => {
    document.body.innerHTML = '';
    Object.defineProperty(window, 'innerWidth', { value: realInnerWidth, configurable: true, writable: true });
});

function setWidth(width) {
    Object.defineProperty(window, 'innerWidth', { value: width, configurable: true, writable: true });
}

function dashboard({ width, order = Object.keys(DESKTOP), positions = DESKTOP }) {
    setWidth(width);
    const ids = Object.keys(DESKTOP);
    document.body.innerHTML = `
        <div class="dashboard-grid">
            ${ids.map(id => `<div class="dashboard-card" data-widget-id="${id}" data-widget-category="widget"><div class="card-header"><h3>${id}</h3></div></div>`).join('')}
        </div>
        <div id="hidden-widgets"></div>`;
    const mod = Object.create(DashboardModule.prototype);
    mod.app = {
        dashboardLocked: false,
        settings: {},
        charts: {},
        dashboardConfig: {
            hero: {},
            widgets: {
                visibility: Object.fromEntries(ids.map(id => [id, true])),
                order: [...order],
                sizes: { trendChart: 'l', spendingChart: 'm', accounts: 's', upcomingBills: 's' },
                positions: JSON.parse(JSON.stringify(positions)),
            },
        },
    };
    mod.gridColumns = 3;
    mod.saveDashboardVisibility = vi.fn();
    mod.resizeAllCharts = () => {};
    mod._tilesDraggable = () => false;
    mod.initGridstack();
    // The grid ignores changes for a moment after it is built.
    mod._gridstackReady = true;
    return mod;
}

function card(id) {
    return document.querySelector(`[data-widget-id="${id}"]`);
}

/** Tile ids top to bottom, left to right, as the grid shows them now. */
function shown(mod) {
    return mod.gridstack.getGridItems()
        .map(el => el.gridstackNode)
        .sort((a, b) => a.y - b.y || a.x - b.x)
        .map(node => node.el.getAttribute('gs-id'));
}

describe('the dashboard on a phone', () => {
    it('shows the tiles in one column', () => {
        const mod = dashboard({ width: 390 });

        expect(mod.gridstack.getColumn()).toBe(1);
        expect(shown(mod)).toEqual(['trendChart', 'spendingChart', 'accounts', 'upcomingBills']);
        mod.gridstack.getGridItems().forEach(el => expect(el.gridstackNode).toMatchObject({ x: 0, w: 1 }));
    });

    it('keeps the desktop layout when a tile is moved', () => {
        const mod = dashboard({ width: 390 });

        expect(mod.moveGridTile(card('trendChart'), 1)).toBe(true);

        expect(mod.saveDashboardVisibility).toHaveBeenCalled();
        expect(mod.dashboardConfig.widgets.positions).toEqual(DESKTOP);
        expect(mod.dashboardConfig.widgets.order).toEqual(['spendingChart', 'trendChart', 'accounts', 'upcomingBills']);
    });

    it('shows the tiles in the order saved by a move on a phone', () => {
        const mod = dashboard({ width: 390, order: ['upcomingBills', 'trendChart', 'accounts', 'spendingChart'] });

        expect(shown(mod)).toEqual(['upcomingBills', 'trendChart', 'accounts', 'spendingChart']);
    });

    it('keeps a size picked on a phone for the desktop layout', () => {
        const mod = dashboard({ width: 390 });

        mod.changeTileSize('accounts', 'l', card('accounts'));

        expect(mod.dashboardConfig.widgets.sizes.accounts).toBe('l');
        expect(mod.dashboardConfig.widgets.positions.accounts).toMatchObject({ w: 3, h: 4 });
        expect(mod.dashboardConfig.widgets.positions.trendChart).toEqual(DESKTOP.trendChart);
    });

    it('lays the desktop layout out again when the window is widened', () => {
        const mod = dashboard({ width: 390 });

        setWidth(1400);
        mod._applyResponsiveColumns();

        expect(mod.gridstack.getColumn()).toBe(3);
        for (const [id, pos] of Object.entries(DESKTOP)) {
            expect(card(id).closest('.grid-stack-item').gridstackNode).toMatchObject(pos);
        }
        expect(mod.saveDashboardVisibility).not.toHaveBeenCalled();
    });
});

describe('the dashboard on a desktop', () => {
    it('saves the layout when a tile is moved', () => {
        const mod = dashboard({ width: 1400 });

        expect(mod.gridstack.getColumn()).toBe(3);
        mod.moveGridTile(card('upcomingBills'), -1);

        const positions = mod.dashboardConfig.widgets.positions;
        expect(positions.upcomingBills).toMatchObject({ x: 2, y: 4, w: 1 });
        expect(positions.trendChart).toEqual(DESKTOP.trendChart);
        expect(mod.saveDashboardVisibility).toHaveBeenCalled();
    });
});
