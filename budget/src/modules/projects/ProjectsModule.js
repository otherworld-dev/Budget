/**
 * Projects Module - project budgets: one total over a date range for a
 * category and everything under it (#391)
 */
import { translate as t } from '@nextcloud/l10n';
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError, showWarning } from '../../utils/notifications.js';
import { confirmDialog } from '../../utils/dialogs.js';
import { setDateValue } from '../../utils/datepicker.js';
import { groupProjects, progressFor, unallocated, subcategoriesOf, ownExpenseTree } from './projectMath.js';
import { progressBarAttrs, overBudgetText } from '../../utils/budgetProgress.js';
import { showLoading, showLoadError } from '../../utils/loading.js';
import { apiFetch, ApiError } from '../../utils/api.js';

export default class ProjectsModule {
    constructor(app) {
        this.app = app;
        this._eventsSetup = false;
        // The project open in the detail modal
        this._current = null;
        // The project the form is editing, or null for a new one
        this._editing = null;
        // Amounts typed in the form by category id, kept across category changes
        this._allocationValues = new Map();
        this._formListenersSetup = false;
    }

    get settings() { return this.app.settings; }

    async loadProjectsView() {
        this.ensureEventListeners();
        showLoading('projects-list');
        try {
            this.app.projects = await apiFetch('/apps/budget/api/projects');
            this.renderProjects(this.app.projects);
        } catch (error) {
            console.error('Failed to load projects:', error);
            showError(t('budget', 'Failed to load projects'));
            const emptyState = document.getElementById('empty-projects');
            if (emptyState) emptyState.style.display = 'none';
            showLoadError('projects-list', t('budget', 'Failed to load projects'), () => this.loadProjectsView());
        }
    }

    renderProjects(projects) {
        const { open, finished } = groupProjects(projects);
        document.getElementById('empty-projects').style.display = (projects || []).length === 0 ? 'block' : 'none';
        document.getElementById('projects-list').innerHTML = open.map(p => this.cardHtml(p)).join('');
        document.getElementById('projects-finished-list').innerHTML = finished.map(p => this.cardHtml(p)).join('');
        document.getElementById('projects-finished-section').style.display = finished.length > 0 ? 'block' : 'none';
    }

    cardHtml(project) {
        // t() escapes the name itself
        const shared = project._shared
            ? `<span class="project-shared-badge" title="${t('budget', 'Shared by {owner}', { owner: project._sharedByName || project.userId })}">${t('budget', 'Shared')}</span>`
            : '';
        return `
            <div class="project-card" role="button" tabindex="0" data-project-id="${project.id}">
                <div class="project-card-header">
                    <span class="project-name">${this.escape(project.name)}</span>
                    ${shared}
                    ${this.statusBadge(project.status)}
                </div>
                <div class="project-meta">${this.escape(project.categoryName || '')} · ${this.datesText(project)}</div>
                ${this.summaryHtml(project)}
            </div>`;
    }

    summaryHtml(project) {
        const bar = progressFor(project.spent, project.totalAmount);
        const time = this.timeText(project);
        return `
            <div class="budget-progress-bar" ${progressBarAttrs(bar.percent, project.name)}><div class="budget-progress-fill ${bar.status}" style="width: ${bar.width}%"></div></div>
            ${overBudgetText(project.remaining < 0)}
            <div class="project-amounts">
                <span>${t('budget', '{spent} of {total}', { spent: this.money(project.spent), total: this.money(project.totalAmount) })}</span>
                <span class="${project.remaining < 0 ? 'negative' : ''}">${this.remainingText(project.remaining)}</span>
            </div>
            ${time ? `<div class="project-time">${time}</div>` : ''}`;
    }

    statusBadge(status) {
        const labels = {
            upcoming: t('budget', 'Upcoming'),
            active: t('budget', 'Active'),
            finished: t('budget', 'Finished'),
        };
        return `<span class="project-status project-status-${this.escape(String(status))}">${labels[status] || this.escape(String(status))}</span>`;
    }

    datesText(project) {
        return project.endDate
            ? t('budget', '{start} to {end}', { start: this.day(project.startDate), end: this.day(project.endDate) })
            : t('budget', 'From {start}', { start: this.day(project.startDate) });
    }

    timeText(project) {
        if (project.timeElapsed === null || project.timeElapsed === undefined) return '';
        // The percentages go in as values: a literal percent sign in t() breaks vsprintf
        return t('budget', '{time} of the time gone, {spent} spent', {
            time: `${Math.round(project.timeElapsed * 100)}%`,
            spent: `${Math.round(project.percentage)}%`,
        });
    }

    remainingText(remaining) {
        return remaining < 0
            ? t('budget', '{amount} over', { amount: this.money(Math.abs(remaining)) })
            : t('budget', '{amount} left', { amount: this.money(remaining) });
    }

    async showProjectDetails(id) {
        this.ensureEventListeners();
        let project;
        try {
            project = await apiFetch(`/apps/budget/api/projects/${id}`);
        } catch (error) {
            console.error('Failed to load project:', error);
            showError(t('budget', 'Failed to load the project'));
            return;
        }
        this._current = project;
        const canWrite = !project._shared || project._canWrite === true;

        document.getElementById('project-details-title').textContent = project.name;
        document.getElementById('project-details-meta').innerHTML =
            `<span>${this.escape(project.categoryName || '')} · ${this.datesText(project)}</span>${this.statusBadge(project.status)}`;
        document.getElementById('project-details-summary').innerHTML = this.summaryHtml(project);
        document.getElementById('project-details-rows').innerHTML = this.breakdownHtml(project);
        document.getElementById('project-edit-btn').style.display = canWrite ? '' : 'none';
        document.getElementById('project-delete-btn').style.display = project._shared ? 'none' : '';

        const modal = document.getElementById('project-details-modal');
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    breakdownHtml(project) {
        const cell = (cls, label, content) => `<span class="${cls}" data-label="${label}">${content}</span>`;
        const row = (cls, depth, name, budget, spent, remaining, remainingCls, bar) => `
            <div class="project-row${cls}" style="--depth: ${depth}">
                <span class="project-row-name">${name}</span>
                ${cell('project-row-budget', t('budget', 'Budget'), budget)}
                ${cell('project-row-spent', t('budget', 'Spent'), spent)}
                ${cell(`project-row-remaining${remainingCls}`, t('budget', 'Remaining'), remaining)}
                <span class="project-row-bar">${bar}</span>
            </div>`;

        const rows = (project.breakdown || []).map(entry => {
            const hasAmount = entry.allocation !== null && entry.allocation !== undefined;
            const bar = hasAmount ? progressFor(entry.spent, entry.allocation) : null;
            const note = entry.outsideProject
                ? `<span class="project-row-note">${t('budget', 'Not counted in this project')}</span>`
                : '';
            return row(
                entry.outsideProject ? ' outside' : '',
                entry.depth,
                `${this.escape(entry.name)}${note}`,
                hasAmount ? this.money(entry.allocation) : '',
                this.money(entry.spent),
                hasAmount ? this.money(entry.remaining) : '',
                hasAmount && entry.remaining < 0 ? ' negative' : '',
                bar ? `<span class="budget-progress-bar" ${progressBarAttrs(bar.percent, entry.name)}><span class="budget-progress-fill ${bar.status}" style="width: ${bar.width}%"></span></span>${overBudgetText(hasAmount && entry.remaining < 0)}` : ''
            );
        });

        if (Math.abs(project.directSpent || 0) >= 0.005) {
            rows.push(row('', 0, t('budget', 'Directly in {category}', { category: project.categoryName || '' }), '', this.money(project.directSpent), '', '', ''));
        }
        if ((project.allocations || []).length > 0) {
            rows.push(row(' project-row-unallocated', 0, t('budget', 'Unallocated'), this.money(project.unallocated), '', '', '', ''));
        }

        return rows.length > 0
            ? rows.join('')
            : `<div class="empty-state-small">${t('budget', 'No subcategories or amounts yet')}</div>`;
    }

    viewTransactions() {
        const project = this._current;
        if (!project) return;
        this.closeModal(document.getElementById('project-details-modal'));
        const range = project.window || { from: project.startDate, to: project.endDate || formatters.getTodayDateString() };
        // No direction filter, like Category Details (#361): the figures are
        // net of refunds, so the list has to show those too
        this.app.openTransactionsForCategory(project.branchCategoryIds, {
            type: '', dateFrom: range.from, dateTo: range.to,
        });
    }

    async deleteProject() {
        const project = this._current;
        if (!project) return;
        const question = t('budget', 'Delete the project "{name}"? Its categories and transactions are not changed.', { name: project.name });
        if (!await confirmDialog(question, { destructive: true })) return;
        try {
            await apiFetch(`/apps/budget/api/projects/${project.id}`, {
                method: 'DELETE',
                errorMessage: t('budget', 'Failed to delete the project'),
            });
            this.closeModal(document.getElementById('project-details-modal'));
            showSuccess(t('budget', 'Project deleted'));
            await this.loadProjectsView();
        } catch (error) {
            showError(error instanceof ApiError ? error.message : t('budget', 'Failed to delete the project'));
        }
    }

    ensureEventListeners() {
        if (this._eventsSetup) return;
        this._eventsSetup = true;

        document.getElementById('add-project-btn')?.addEventListener('click', () => this.showProjectForm());
        document.getElementById('empty-add-project-btn')?.addEventListener('click', () => this.showProjectForm());

        for (const id of ['projects-list', 'projects-finished-list']) {
            const list = document.getElementById(id);
            if (!list) continue;
            list.addEventListener('click', (e) => {
                const card = e.target.closest('.project-card');
                if (card) this.showProjectDetails(parseInt(card.dataset.projectId, 10));
            });
            list.addEventListener('keydown', (e) => {
                const card = e.target.closest('.project-card');
                if (card && (e.key === 'Enter' || e.key === ' ')) {
                    e.preventDefault();
                    this.showProjectDetails(parseInt(card.dataset.projectId, 10));
                }
            });
        }

        document.getElementById('project-transactions-btn')?.addEventListener('click', () => this.viewTransactions());
        document.getElementById('project-delete-btn')?.addEventListener('click', () => this.deleteProject());
        document.getElementById('project-edit-btn')?.addEventListener('click', () => {
            const project = this._current;
            this.closeModal(document.getElementById('project-details-modal'));
            this.showProjectForm(project);
        });

        for (const id of ['project-details-modal', 'project-modal']) {
            const modal = document.getElementById(id);
            modal?.querySelectorAll('.cancel-btn, .close-btn').forEach(btn => {
                btn.addEventListener('click', () => this.closeModal(modal));
            });
        }

        this.ensureFormListeners?.();
    }

    showProjectForm(project = null) {
        this.ensureEventListeners();
        this._editing = project;
        const isOwner = !project || !project._shared;

        document.getElementById('project-modal-title').textContent = project ? t('budget', 'Edit Project') : t('budget', 'New Project');
        document.getElementById('project-name').value = project?.name || '';
        document.getElementById('project-total').value = project ? String(project.totalAmount) : '';
        setDateValue('project-start', project?.startDate || formatters.getTodayDateString());
        setDateValue('project-end', project?.endDate || '');

        const select = document.getElementById('project-category');
        if (isOwner) {
            select.innerHTML = `<option value="">${t('budget', 'Choose a category…')}</option>`
                + dom.buildCategoryOptionsHtml(ownExpenseTree(this.app.rawCategoryTree || []), { selectedId: project?.categoryId });
        } else {
            // A shared project stays on its owner's category, which the viewer's own picker cannot list
            select.innerHTML = `<option value="${project.categoryId}" selected>${this.escape(project.categoryName || '')}</option>`;
        }
        select.disabled = !isOwner;

        // Only when creating: the owner can untick Exclude from budgeting on the category itself later
        document.getElementById('project-exclude-group').style.display = project ? 'none' : '';
        document.getElementById('project-exclude-budget').checked = true;

        this._allocationValues = new Map((project?.allocations || []).map(a => [a.categoryId, String(a.amount)]));
        this.renderAllocationRows();

        const modal = document.getElementById('project-modal');
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    renderAllocationRows() {
        const categoryId = parseInt(document.getElementById('project-category').value, 10);
        const project = this._editing;
        let subcategories = [];
        if (!Number.isNaN(categoryId)) {
            subcategories = project && project.categoryId === categoryId
                ? (project.subcategories || [])
                : subcategoriesOf(this.app.rawCategoryTree || [], categoryId);
        }

        document.getElementById('project-allocations-group').style.display = subcategories.length > 0 ? '' : 'none';
        document.getElementById('project-allocations').innerHTML = subcategories.map(sub => `
            <div class="project-alloc-row" style="--depth: ${sub.depth}">
                <label for="project-alloc-${sub.id}">${this.escape(sub.name)}</label>
                <input type="number" id="project-alloc-${sub.id}" class="project-alloc-input" data-category-id="${sub.id}"
                       step="0.01" min="0" inputmode="decimal" placeholder="${t('budget', 'No amount')}"
                       value="${this.escape(this._allocationValues.get(sub.id) ?? '')}">
            </div>`).join('');
        this.updateUnallocated();
    }

    updateUnallocated() {
        const inputs = [...document.querySelectorAll('#project-allocations .project-alloc-input')];
        const result = unallocated(document.getElementById('project-total').value, inputs.map(i => i.value));
        const el = document.getElementById('project-unallocated');
        el.classList.toggle('error', result.valid && result.over);
        el.textContent = result.over && result.valid
            ? t('budget', 'The subcategory amounts are {amount} over the total', { amount: this.money(Math.abs(result.cents) / 100) })
            : t('budget', 'Unallocated: {amount}', { amount: this.money(Math.max(result.cents, 0) / 100) });
    }

    async saveProject() {
        const project = this._editing;
        const categoryId = parseInt(document.getElementById('project-category').value, 10);
        const inputs = [...document.querySelectorAll('#project-allocations .project-alloc-input')];
        const body = {
            name: document.getElementById('project-name').value.trim(),
            categoryId: Number.isNaN(categoryId) ? null : categoryId,
            totalAmount: parseFloat(document.getElementById('project-total').value),
            startDate: document.getElementById('project-start').value,
            endDate: document.getElementById('project-end').value || null,
            allocations: inputs
                .filter(input => input.value.trim() !== '')
                .map(input => ({ categoryId: parseInt(input.dataset.categoryId, 10), amount: input.value.trim() })),
        };

        let problem = null;
        if (!body.name) problem = t('budget', 'Enter a name for the project');
        else if (!body.categoryId) problem = t('budget', 'Choose a category for the project');
        else if (!(body.totalAmount > 0)) problem = t('budget', 'The total must be more than zero');
        else if (!body.startDate) problem = t('budget', 'Enter a start date for the project');
        else if (body.endDate && body.endDate < body.startDate) problem = t('budget', 'The end date cannot be before the start date');
        else if (unallocated(body.totalAmount, body.allocations.map(a => a.amount)).over) {
            problem = t('budget', 'The subcategory amounts add up to more than the total');
        }
        if (problem) {
            showWarning(problem);
            return;
        }

        if (!project) {
            body.excludeFromBudget = document.getElementById('project-exclude-budget').checked;
        }

        try {
            await apiFetch(project ? `/apps/budget/api/projects/${project.id}` : '/apps/budget/api/projects', {
                method: project ? 'PUT' : 'POST',
                body,
                errorMessage: t('budget', 'Failed to save the project'),
            });
            this.closeModal(document.getElementById('project-modal'));
            showSuccess(project ? t('budget', 'Project saved') : t('budget', 'Project created'));
            if (body.excludeFromBudget) {
                // The category's Exclude from budgeting flag changed on the server
                await this.app.loadCategories?.();
            }
            await this.loadProjectsView();
        } catch (error) {
            showError(error instanceof ApiError ? error.message : t('budget', 'Failed to save the project'));
        }
    }

    ensureFormListeners() {
        if (this._formListenersSetup) return;
        this._formListenersSetup = true;

        document.getElementById('project-form')?.addEventListener('submit', (e) => {
            e.preventDefault();
            this.saveProject();
        });
        document.getElementById('project-category')?.addEventListener('change', () => this.renderAllocationRows());
        document.getElementById('project-total')?.addEventListener('input', () => this.updateUnallocated());
        document.getElementById('project-allocations')?.addEventListener('input', (e) => {
            const input = e.target.closest('.project-alloc-input');
            if (!input) return;
            this._allocationValues.set(parseInt(input.dataset.categoryId, 10), input.value);
            this.updateUnallocated();
        });
    }

    money(amount) {
        return formatters.formatCurrency(amount, null, this.settings);
    }

    day(date) {
        return date ? formatters.formatDate(date, this.settings) : '';
    }

    escape(text) {
        return dom.escapeHtml(text);
    }

    closeModal(modal) {
        return dom.closeModal(modal);
    }
}
