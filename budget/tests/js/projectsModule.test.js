/**
 * Projects page (#391): one budget over a date range for a category and
 * everything under it, with optional amounts for its subcategories.
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

vi.mock('../../src/utils/dialogs.js', () => ({
    confirmDialog: vi.fn(async () => true),
}));

vi.mock('../../src/utils/datepicker.js', () => ({
    setDateValue: (id, value) => { document.getElementById(id).value = value || ''; },
    clearDateValue: (id) => { document.getElementById(id).value = ''; },
}));

import ProjectsModule from '../../src/modules/projects/ProjectsModule.js';

export const TREE = [
    { id: 1, name: 'Renovation', type: 'expense', children: [
        { id: 4, name: 'Bathroom', type: 'expense', children: [] },
        { id: 2, name: 'Kitchen', type: 'expense', children: [
            { id: 3, name: 'Appliances', type: 'expense', children: [] },
        ] },
        { id: 8, name: 'Skip hire', type: 'expense', excludedFromReports: true, children: [] },
    ] },
    { id: 6, name: 'Salary', type: 'income', children: [] },
    { id: 11, name: 'Their category', type: 'expense', _shared: true, children: [] },
];

export const project = (overrides = {}) => ({
    id: 10,
    userId: 'user1',
    name: 'House renovation',
    categoryId: 1,
    categoryName: 'Renovation',
    totalAmount: 900,
    startDate: '2026-03-01',
    endDate: '2026-12-31',
    status: 'active',
    spent: 650,
    remaining: 250,
    percentage: 72.2,
    timeElapsed: 0.55,
    directSpent: 50,
    allocated: 400,
    unallocated: 500,
    window: { from: '2026-03-01', to: '2026-09-19' },
    branchCategoryIds: [1, 4, 2, 3],
    subcategories: [
        { id: 4, name: 'Bathroom', depth: 1 },
        { id: 2, name: 'Kitchen', depth: 1 },
        { id: 3, name: 'Appliances', depth: 2 },
    ],
    allocations: [{ categoryId: 2, amount: 400 }],
    breakdown: [
        { categoryId: 4, name: 'Bathroom', depth: 0, allocation: null, spent: 300, remaining: null, percentage: null, outsideProject: false },
        { categoryId: 2, name: 'Kitchen', depth: 0, allocation: 400, spent: 300, remaining: 100, percentage: 75, outsideProject: false },
        { categoryId: 7, name: 'Garden', depth: 0, allocation: 50, spent: 20, remaining: 30, percentage: 40, outsideProject: true },
    ],
    ...overrides,
});

export function mountProjects() {
    document.body.innerHTML = `
        <div id="projects-view">
            <button id="add-project-btn">New Project</button>
            <div id="projects-list"></div>
            <div id="empty-projects" style="display: none;"><button id="empty-add-project-btn">New Project</button></div>
            <div id="projects-finished-section" style="display: none;"><div id="projects-finished-list"></div></div>
        </div>
        <div id="project-details-modal" style="display: none;" aria-hidden="true">
            <h3 id="project-details-title"></h3>
            <div id="project-details-meta"></div>
            <div id="project-details-summary"></div>
            <p id="project-details-shared-note" style="display: none;"></p>
            <div id="project-details-rows"></div>
            <button id="project-transactions-btn">View transactions</button>
            <button id="project-edit-btn">Edit</button>
            <button id="project-delete-btn">Delete</button>
            <button class="close-btn">Close</button>
        </div>
        <div id="project-modal" style="display: none;" aria-hidden="true">
            <h3 id="project-modal-title"></h3>
            <form id="project-form">
                <input id="project-name">
                <select id="project-category"></select>
                <input id="project-total">
                <input id="project-start">
                <input id="project-end">
                <div id="project-allocations-group"><div id="project-allocations"></div><p id="project-unallocated"></p></div>
                <div id="project-exclude-group"><input type="checkbox" id="project-exclude-budget"></div>
                <button type="submit">Save</button>
                <button type="button" class="cancel-btn">Cancel</button>
            </form>
        </div>`;
}

/** Answer the module's requests: the list, one project, and echo writes */
export function serve({ list = [], detail = project() } = {}) {
    global.fetch = vi.fn(async (url, options = {}) => {
        const method = options.method || 'GET';
        let data;
        if (method === 'GET') {
            data = url === '/apps/budget/api/projects' ? list : detail;
        } else {
            data = { ...detail, ...(options.body ? JSON.parse(options.body) : {}) };
        }
        return { ok: true, status: 200, json: async () => data };
    });
}

export function sent(method) {
    const call = global.fetch.mock.calls.find(([, options]) => options?.method === method);
    return call ? { url: call[0], body: call[1].body ? JSON.parse(call[1].body) : null } : null;
}

export function makeModule() {
    return new ProjectsModule({
        settings: {},
        categoryTree: TREE,
        openTransactionsForCategory: vi.fn(),
        loadCategories: vi.fn(async () => {}),
    });
}

const flush = () => new Promise(resolve => setTimeout(resolve, 0));
const rowNames = () => [...document.querySelectorAll('#project-details-rows .project-row-name')]
    .map(cell => cell.firstChild.textContent.trim());

describe('projects page', () => {
    let mod;

    beforeEach(() => {
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        mountProjects();
        mod = makeModule();
        vi.clearAllMocks();
    });

    afterEach(() => {
        delete global.OC;
        delete global.fetch;
    });

    it('lists running and upcoming projects first and finished ones apart', async () => {
        serve({ list: [
            project({ id: 11, name: 'Wedding', status: 'finished', startDate: '2025-01-01' }),
            project(),
            project({ id: 12, name: 'Garden', status: 'upcoming', startDate: '2026-10-01', spent: 0, remaining: 900, timeElapsed: 0 }),
        ] });

        await mod.loadProjectsView();

        const names = (selector) => [...document.querySelectorAll(`${selector} .project-name`)].map(el => el.textContent);
        expect(names('#projects-list')).toEqual(['House renovation', 'Garden']);
        expect(names('#projects-finished-list')).toEqual(['Wedding']);
        expect(document.getElementById('projects-finished-section').style.display).toBe('block');
        expect(document.getElementById('empty-projects').style.display).toBe('none');

        const card = document.querySelector('#projects-list .project-card');
        expect(card.textContent).toContain('£650.00 of £900.00');
        expect(card.textContent).toContain('£250.00 left');
        expect(card.textContent).toContain('55% of the time gone, 72% spent');
        expect(card.querySelector('.budget-progress-fill').classList.contains('warning')).toBe(true);
        expect(card.querySelector('.project-status').textContent).toBe('Active');
    });

    it('shows a project over its total as over', async () => {
        serve({ list: [project({ spent: 1000, remaining: -100, percentage: 111.1 })] });
        await mod.loadProjectsView();

        const card = document.querySelector('.project-card');
        expect(card.textContent).toContain('£100.00 over');
        expect(card.querySelector('.budget-progress-fill').classList.contains('over')).toBe(true);
    });

    it('explains projects when there are none', async () => {
        serve({ list: [] });
        await mod.loadProjectsView();
        expect(document.getElementById('empty-projects').style.display).toBe('block');
    });

    it('marks a project shared with you', async () => {
        serve({ list: [project({ _shared: true, _canWrite: false, _sharedByName: 'Justin' })] });
        await mod.loadProjectsView();
        expect(document.querySelector('.project-shared-badge').title).toBe('Shared by Justin');
    });

    it('opens the breakdown when a card is clicked', async () => {
        serve({ list: [project()] });
        await mod.loadProjectsView();

        document.querySelector('.project-card').click();
        await flush();

        expect(document.getElementById('project-details-modal').style.display).toBe('flex');
        expect(document.getElementById('project-details-title').textContent).toBe('House renovation');
        expect(rowNames()).toEqual(['Bathroom', 'Kitchen', 'Garden', 'Directly in Renovation', 'Unallocated']);

        const rows = document.querySelectorAll('#project-details-rows .project-row');
        expect(rows[1].textContent).toContain('£400.00');
        expect(rows[1].querySelector('.budget-progress-fill').classList.contains('warning')).toBe(true);
        expect(rows[2].classList.contains('outside')).toBe(true);
        expect(rows[2].textContent).toContain('Not counted in this project');
        expect(rows[3].textContent).toContain('£50.00');
        expect(rows[4].textContent).toContain('£500.00');
    });

    it('leaves out Unallocated when no subcategory has an amount', async () => {
        serve({ detail: project({ allocations: [], allocated: 0, unallocated: 900, directSpent: 0, breakdown: [] }) });
        await mod.showProjectDetails(10);
        expect(rowNames()).toEqual([]);
        expect(document.getElementById('project-details-rows').textContent).toContain('No subcategories');
    });

    it('lets the owner edit and delete', async () => {
        serve();
        await mod.showProjectDetails(10);
        expect(document.getElementById('project-edit-btn').style.display).toBe('');
        expect(document.getElementById('project-delete-btn').style.display).toBe('');
        expect(document.getElementById('project-details-shared-note').style.display).toBe('none');
    });

    it('a read-only shared project can only be looked at', async () => {
        serve({ detail: project({ _shared: true, _canWrite: false }) });
        await mod.showProjectDetails(10);
        expect(document.getElementById('project-edit-btn').style.display).toBe('none');
        expect(document.getElementById('project-delete-btn').style.display).toBe('none');
        expect(document.getElementById('project-details-shared-note').style.display).toBe('block');
    });

    it('a project shared at Read & Write can be edited but not deleted', async () => {
        serve({ detail: project({ _shared: true, _canWrite: true }) });
        await mod.showProjectDetails(10);
        expect(document.getElementById('project-edit-btn').style.display).toBe('');
        expect(document.getElementById('project-delete-btn').style.display).toBe('none');
    });

    it('opens the transactions behind the figures, refunds included', async () => {
        serve();
        await mod.showProjectDetails(10);

        document.getElementById('project-transactions-btn').click();

        expect(mod.app.openTransactionsForCategory).toHaveBeenCalledWith([1, 4, 2, 3], {
            type: '', dateFrom: '2026-03-01', dateTo: '2026-09-19',
        });
        expect(document.getElementById('project-details-modal').style.display).toBe('none');
    });

    it('deletes a project after asking, and reloads the list', async () => {
        serve({ list: [] });
        await mod.showProjectDetails(10);

        await mod.deleteProject();

        expect(sent('DELETE').url).toBe('/apps/budget/api/projects/10');
        expect(document.getElementById('empty-projects').style.display).toBe('block');
    });
});

describe('project form', () => {
    let mod;
    const byId = (id) => document.getElementById(id);
    const setValue = (id, value, event = 'input') => {
        byId(id).value = value;
        byId(id).dispatchEvent(new Event(event, { bubbles: true }));
    };
    const allocationInput = (categoryId) => document.querySelector(`.project-alloc-input[data-category-id="${categoryId}"]`);
    const typeAmount = (categoryId, value) => {
        const input = allocationInput(categoryId);
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    };

    beforeEach(() => {
        global.OC = { generateUrl: (u) => u, requestToken: 'tok' };
        mountProjects();
        mod = makeModule();
        vi.clearAllMocks();
    });

    afterEach(() => {
        delete global.OC;
        delete global.fetch;
    });

    it('offers only your own expense categories', () => {
        mod.showProjectForm();

        const values = [...byId('project-category').options].map(o => o.value);
        expect(values).toEqual(['', '1', '4', '2', '3', '8']);
        expect(byId('project-category').disabled).toBe(false);
        expect(byId('project-exclude-group').style.display).toBe('');
        expect(byId('project-exclude-budget').checked).toBe(true);
        expect(byId('project-modal').style.display).toBe('flex');
    });

    it('lists the chosen category\'s subcategories and keeps a running total', () => {
        mod.showProjectForm();
        setValue('project-total', '900');
        setValue('project-category', '1', 'change');

        const ids = [...document.querySelectorAll('.project-alloc-input')].map(i => i.dataset.categoryId);
        expect(ids).toEqual(['4', '2', '3']);
        expect(byId('project-unallocated').textContent).toBe('Unallocated: £900.00');

        typeAmount(2, '400');
        expect(byId('project-unallocated').textContent).toBe('Unallocated: £500.00');

        typeAmount(4, '600');
        expect(byId('project-unallocated').textContent).toBe('The subcategory amounts are £100.00 over the total');
        expect(byId('project-unallocated').classList.contains('error')).toBe(true);
    });

    it('keeps typed amounts when the category is changed and changed back', () => {
        mod.showProjectForm();
        setValue('project-category', '1', 'change');
        typeAmount(2, '400');
        setValue('project-category', '4', 'change');
        expect(document.querySelectorAll('.project-alloc-input')).toHaveLength(0);
        setValue('project-category', '1', 'change');
        expect(allocationInput(2).value).toBe('400');
    });

    it('creates a project with its amounts and the monthly-budget box', async () => {
        serve({ list: [] });
        mod.showProjectForm();
        setValue('project-name', 'House renovation');
        setValue('project-category', '1', 'change');
        setValue('project-total', '900');
        setValue('project-start', '2026-03-01');
        typeAmount(2, '400');

        await mod.saveProject();

        expect(sent('POST')).toEqual({
            url: '/apps/budget/api/projects',
            body: {
                name: 'House renovation',
                categoryId: 1,
                totalAmount: 900,
                startDate: '2026-03-01',
                endDate: null,
                allocations: [{ categoryId: 2, amount: '400' }],
                excludeFromBudget: true,
            },
        });
        // The category's flag changed on the server
        expect(mod.app.loadCategories).toHaveBeenCalled();
        expect(byId('project-modal').style.display).toBe('none');
    });

    it('does not ask the server when the amounts go over the total', async () => {
        const { showWarning } = await import('../../src/utils/notifications.js');
        serve();
        mod.showProjectForm();
        setValue('project-name', 'House renovation');
        setValue('project-category', '1', 'change');
        setValue('project-total', '900');
        setValue('project-start', '2026-03-01');
        typeAmount(2, '1000');

        await mod.saveProject();

        expect(showWarning).toHaveBeenCalledWith('The subcategory amounts add up to more than the total');
        expect(sent('POST')).toBeNull();
    });

    it('edits with PUT and never sends the monthly-budget box', async () => {
        serve({ list: [] });
        mod.showProjectForm(project());

        expect(byId('project-name').value).toBe('House renovation');
        expect(byId('project-exclude-group').style.display).toBe('none');
        expect(allocationInput(2).value).toBe('400');

        await mod.saveProject();

        const put = sent('PUT');
        expect(put.url).toBe('/apps/budget/api/projects/10');
        expect(put.body).not.toHaveProperty('excludeFromBudget');
        expect(put.body.endDate).toBe('2026-12-31');
        expect(mod.app.loadCategories).not.toHaveBeenCalled();
    });

    it('someone the project is shared with cannot move it to another category', () => {
        mod.showProjectForm(project({ _shared: true, _canWrite: true }));

        const select = byId('project-category');
        expect(select.disabled).toBe(true);
        expect([...select.options].map(o => o.textContent)).toEqual(['Renovation']);
        // Their subcategories come from the project, not the viewer's own tree
        expect([...document.querySelectorAll('.project-alloc-input')].map(i => i.dataset.categoryId)).toEqual(['4', '2', '3']);
    });

    it('New Project opens an empty form', () => {
        serve({ list: [] });
        mod.ensureEventListeners();
        byId('add-project-btn').click();
        expect(byId('project-modal-title').textContent).toBe('New Project');
        expect(byId('project-name').value).toBe('');
    });
});
