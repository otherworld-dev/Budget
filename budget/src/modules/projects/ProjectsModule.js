/**
 * Projects Module - project budgets: one total over a date range for a
 * category and everything under it (#391)
 */
import { translate as t } from '@nextcloud/l10n';
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError } from '../../utils/notifications.js';
import { confirmDialog } from '../../utils/dialogs.js';
import { groupProjects, progressFor } from './projectMath.js';

export default class ProjectsModule {
    constructor(app) {
        this.app = app;
        this._eventsSetup = false;
        // The project open in the detail modal
        this._current = null;
    }

    get settings() { return this.app.settings; }

    async loadProjectsView() {
        this.ensureEventListeners();
        try {
            this.app.projects = await this.fetchJson('/apps/budget/api/projects');
            this.renderProjects(this.app.projects);
        } catch (error) {
            console.error('Failed to load projects:', error);
            showError(t('budget', 'Failed to load projects'));
        }
    }

    async fetchJson(url, options = {}) {
        const response = await fetch(OC.generateUrl(url), {
            ...options,
            headers: { 'requesttoken': OC.requestToken, 'Content-Type': 'application/json' },
        });
        const data = await response.json().catch(() => null);
        if (!response.ok) {
            const error = new Error(data?.error || `HTTP ${response.status}`);
            error.userMessage = data?.error || null;
            throw error;
        }
        return data;
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
            <div class="budget-progress-bar"><div class="budget-progress-fill ${bar.status}" style="width: ${bar.width}%"></div></div>
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
            project = await this.fetchJson(`/apps/budget/api/projects/${id}`);
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
        document.getElementById('project-details-shared-note').style.display = project._shared ? 'block' : 'none';

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
                bar ? `<span class="budget-progress-bar"><span class="budget-progress-fill ${bar.status}" style="width: ${bar.width}%"></span></span>` : ''
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
            await this.fetchJson(`/apps/budget/api/projects/${project.id}`, { method: 'DELETE' });
            this.closeModal(document.getElementById('project-details-modal'));
            showSuccess(t('budget', 'Project deleted'));
            await this.loadProjectsView();
        } catch (error) {
            showError(error.userMessage || t('budget', 'Failed to delete the project'));
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
