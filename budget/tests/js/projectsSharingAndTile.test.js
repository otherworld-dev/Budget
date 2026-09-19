/**
 * Project budgets outside their own page (#391): the share configuration
 * panel and the dashboard tile.
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

// The first-load test hands the dashboard a stand-in grid to see where tiles go
const gridstackInit = vi.hoisted(() => vi.fn());
vi.mock('gridstack', () => ({ GridStack: { init: (...args) => gridstackInit(...args) } }));

import SharingModule from '../../src/modules/sharing/SharingModule.js';
import DashboardModule from '../../src/modules/dashboard/DashboardModule.js';
import { HELP_TOPICS } from '../../src/modules/help/HelpModule.js';

describe('share configuration', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="share-config-3"></div>';
    });

    it('offers projects to share, at their saved permission', () => {
        const mod = Object.create(SharingModule.prototype);
        mod.app = {};
        const panel = document.getElementById('share-config-3');

        mod.renderConfigPanel(panel, 3, { project: { ids: [10], permission: 'write' } },
            { project: [{ id: 10, name: 'House renovation' }] }, { project: 'write' });

        const section = panel.querySelector('.share-config-section[data-type="project"]');
        expect(section.querySelector('h4').textContent).toBe('Projects');
        expect(section.querySelector('input[data-entity-id="10"]').checked).toBe(true);
        expect(section.querySelector('.share-config-permission').value).toBe('write');
    });

    it('saves the projects section with the rest', async () => {
        const mod = Object.create(SharingModule.prototype);
        mod.app = {};
        mod.fetchApi = vi.fn(async () => ({}));
        const panel = document.getElementById('share-config-3');
        mod.renderConfigPanel(panel, 3, {}, { project: [{ id: 10, name: 'House renovation' }] }, {});
        panel.querySelector('input[data-entity-id="10"]').checked = true;

        await mod.saveConfig(3);

        expect(mod.fetchApi).toHaveBeenCalledWith('/apps/budget/api/shares/3/items/project', expect.objectContaining({
            method: 'PUT',
            body: JSON.stringify({ entityIds: [10], permission: 'read' }),
        }));
    });
});

describe('dashboard tile', () => {
    let mod;
    const card = () => document.getElementById('projects-card');

    beforeEach(() => {
        document.body.innerHTML = `
            <div id="projects-card" style="display: none;">
                <div id="projects-widget"></div>
            </div>`;
        mod = Object.create(DashboardModule.prototype);
        mod.app = { settings: {} };
    });

    const p = (overrides) => ({ id: 1, name: 'Renovation', status: 'active', startDate: '2026-03-01', spent: 450, totalAmount: 900, ...overrides });

    it('shows running and upcoming projects, not finished ones', () => {
        mod.updateProjectsWidget([
            p({ id: 1 }),
            p({ id: 2, name: 'Wedding', status: 'finished', startDate: '2025-01-01' }),
            p({ id: 3, name: 'Garden', status: 'upcoming', startDate: '2026-10-01', spent: 0 }),
        ]);

        expect(card().style.display).toBe('');
        const names = [...document.querySelectorAll('.project-tile-name')].map(el => el.textContent);
        expect(names).toEqual(['Renovation', 'Garden']);
        expect(document.querySelector('.project-tile-item').textContent).toContain('£450.00 of £900.00');
        expect(document.querySelector('.project-tile-percent').textContent).toBe('50%');
    });

    it('shows at most five', () => {
        mod.updateProjectsWidget([1, 2, 3, 4, 5, 6].map(id => p({ id, name: `P${id}` })));
        expect(document.querySelectorAll('.project-tile-item')).toHaveLength(5);
    });

    it('stays hidden with nothing running', () => {
        mod.updateProjectsWidget([p({ status: 'finished' })]);
        expect(card().style.display).toBe('none');
    });

    it('stays hidden when the user hid the tile', () => {
        mod.app.dashboardConfig = { widgets: { visibility: { projects: false } } };
        mod.updateProjectsWidget([p({})]);
        expect(card().style.display).toBe('none');
    });

    it('repaints from fresh data instead of hiding when called with no argument', async () => {
        // A lazy-load path (applyDashboardVisibility/showWidget) calls the
        // generic update method with no argument once it thinks the tile's
        // data is loaded; that must mean "repaint", not "there is no data".
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        global.fetch = vi.fn(async () => ({ ok: true, json: async () => [p({})] }));

        try {
            mod.updateProjectsWidget();
            await new Promise(r => setTimeout(r, 0));

            expect(card().style.display).toBe('');
            expect(document.querySelector('.project-tile-name').textContent).toBe('Renovation');
            expect(global.fetch).toHaveBeenCalledWith('/apps/budget/api/projects', expect.anything());
        } finally {
            delete global.OC;
            delete global.fetch;
        }
    });
});

/**
 * Once Gridstack has built the grid, a repaint can find the tile parked in
 * #hidden-widgets: a project created or finished changes it without a page
 * reload. Showing or hiding the card alone left the tile parked, so it never
 * appeared.
 */
describe('dashboard tile in the grid', () => {
    let mod;
    let grid;
    let stash;
    const wrapper = () => document.querySelector('[gs-id="projects"]');
    const p = (overrides) => ({ id: 1, name: 'Renovation', status: 'active', startDate: '2026-03-01', spent: 450, totalAmount: 900, ...overrides });

    beforeEach(() => {
        document.body.innerHTML = `
            <div class="dashboard-grid"></div>
            <div id="hidden-widgets">
                <div class="grid-stack-item" gs-id="projects" style="display: none;">
                    <div class="grid-stack-item-content">
                        <div id="projects-card" data-widget-id="projects" style="display: none;">
                            <div id="projects-widget"></div>
                        </div>
                    </div>
                </div>
            </div>`;
        grid = document.querySelector('.dashboard-grid');
        stash = document.getElementById('hidden-widgets');
        mod = Object.create(DashboardModule.prototype);
        mod.app = { settings: {}, dashboardConfig: { widgets: { visibility: {} } }, dashboardLocked: true };
        mod.gridstack = {
            addWidget: vi.fn(({ el }) => grid.appendChild(el)),
            removeWidget: vi.fn(),
        };
    });

    it('brings a parked tile into the grid when a project turns up', () => {
        mod.updateProjectsWidget([p({})]);

        expect(mod.gridstack.addWidget).toHaveBeenCalledTimes(1);
        expect(mod.gridstack.addWidget.mock.calls[0][0].el).toBe(wrapper());
        expect(grid.contains(wrapper())).toBe(true);
        expect(wrapper().style.display).toBe('');
        expect(document.getElementById('projects-card').style.display).toBe('');
    });

    it('does not add it twice on a repaint', () => {
        mod.updateProjectsWidget([p({})]);
        mod.updateProjectsWidget([p({ spent: 500 })]);

        expect(mod.gridstack.addWidget).toHaveBeenCalledTimes(1);
    });

    it('parks the tile again once nothing is running', () => {
        mod.updateProjectsWidget([p({})]);
        mod.updateProjectsWidget([p({ status: 'finished' })]);

        expect(mod.gridstack.removeWidget).toHaveBeenCalledWith(wrapper(), false);
        expect(stash.contains(wrapper())).toBe(true);
        expect(wrapper().style.display).toBe('none');
    });

    it('leaves a tile the user hid where it is', () => {
        mod.app.dashboardConfig.widgets.visibility.projects = false;
        mod.updateProjectsWidget([p({})]);

        expect(mod.gridstack.addWidget).not.toHaveBeenCalled();
        expect(stash.contains(wrapper())).toBe(true);
    });

    it('leaves the grid alone before Gridstack starts, which reads the card itself', () => {
        mod.gridstack = null;
        mod.updateProjectsWidget([p({})]);

        expect(document.getElementById('projects-card').style.display).toBe('');
        expect(stash.contains(wrapper())).toBe(true);
    });
});

/**
 * On the first load the tile has to be showing before Gridstack builds the
 * grid, or it is left out of the saved layout and added later in the first
 * free slot, and that slot is then saved over its own place.
 */
describe('dashboard tile on the first load', () => {
    const p = (overrides) => ({ id: 1, name: 'Renovation', status: 'active', startDate: '2026-03-01', spent: 450, totalAmount: 900, ...overrides });
    let grid;
    let answerProjects;

    beforeEach(() => {
        document.body.innerHTML = `
            <div class="dashboard-grid">
                <div class="dashboard-card" id="projects-card" data-widget-id="projects" data-widget-category="widget" style="display: none;">
                    <div id="projects-widget"></div>
                </div>
            </div>
            <div id="hidden-widgets"></div>`;
        grid = {
            load: vi.fn(),
            addWidget: vi.fn(),
            removeWidget: vi.fn(),
            disable: vi.fn(),
            enable: vi.fn(),
            on: vi.fn(),
            getGridItems: () => [],
            getColumn: () => 3,
            column: vi.fn(),
            update: vi.fn(),
        };
        gridstackInit.mockReset();
        gridstackInit.mockReturnValue(grid);

        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        // The projects answer last, after everything else the dashboard asks for
        global.fetch = vi.fn((url) => {
            if (url === '/apps/budget/api/projects') {
                return new Promise(resolve => {
                    answerProjects = () => resolve({ ok: true, json: async () => [p({})] });
                });
            }
            return Promise.resolve({ ok: true, json: async () => [] });
        });
    });

    afterEach(() => {
        delete global.OC;
        delete global.fetch;
    });

    it('puts the tile back where it was saved when the projects answer after the rest', async () => {
        const mod = Object.create(DashboardModule.prototype);
        mod.app = {
            settings: {},
            accounts: [],
            widgetData: {},
            widgetDataLoaded: {},
            dashboardLocked: true,
            dashboardConfig: {
                hero: { visibility: {}, order: [] },
                widgets: {
                    visibility: { projects: true },
                    order: ['projects'],
                    positions: { projects: { x: 2, y: 4, w: 1, h: 4 } },
                },
            },
            needsLazyLoad: () => false,
            getPrimaryCurrency: () => 'GBP',
        };
        // Timed follow-ups that need a real grid
        mod.refreshAllInstances = vi.fn();
        mod._removeHiddenTilesFromGrid = vi.fn();

        const loading = mod.loadDashboard();
        await new Promise(resolve => setTimeout(resolve, 20));
        answerProjects();
        await loading;
        await new Promise(resolve => setTimeout(resolve, 0));

        expect(gridstackInit).toHaveBeenCalledTimes(1);
        const placed = grid.load.mock.calls[0][0].find(item => item.el.getAttribute('gs-id') === 'projects');
        expect(placed).toMatchObject({ x: 2, y: 4, w: 1, h: 4 });
        expect(grid.addWidget).not.toHaveBeenCalled();
        expect(document.querySelector('.project-tile-name').textContent).toBe('Renovation');
    });
});

describe('help', () => {
    it('has a topic for the Projects page', () => {
        expect(HELP_TOPICS.projects.doc).toBe('projects');
        expect(HELP_TOPICS.projects.title()).toBe('Projects');
    });
});
