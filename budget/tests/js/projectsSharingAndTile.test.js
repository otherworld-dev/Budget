/**
 * Project budgets outside their own page (#391): the share configuration
 * panel and the dashboard tile.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

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

describe('help', () => {
    it('has a topic for the Projects page', () => {
        expect(HELP_TOPICS.projects.doc).toBe('projects');
        expect(HELP_TOPICS.projects.title()).toBe('Projects');
    });
});
