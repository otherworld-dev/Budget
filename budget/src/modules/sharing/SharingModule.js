/**
 * Sharing Module - Granular budget sharing management
 *
 * Manages share invitations and per-entity sharing configuration.
 * Owners configure which accounts, categories, bills, income, and
 * goals to share (with read or write permission) via a settings panel.
 */
import { translate as t } from '@nextcloud/l10n';
import { showSuccess, showError } from '../../utils/notifications.js';

export default class SharingModule {
    constructor(app) {
        this.app = app;
        this.outgoingShares = [];
        this.incomingShares = [];
        this.pendingShares = [];
        this.expandedConfigId = null; // which share's config panel is open
    }

    async fetchApi(url, options = {}) {
        const { headers: extraHeaders, ...rest } = options;
        const response = await fetch(OC.generateUrl(url), {
            headers: { ...this.app.getAuthHeaders(), ...extraHeaders },
            ...rest,
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.error || `HTTP ${response.status}`);
        }
        return response.json();
    }

    async loadSharingView() {
        const container = document.getElementById('sharing-content');
        if (!container) return;

        container.innerHTML = `<div class="loading-indicator">${t('budget', 'Loading...')}</div>`;

        try {
            await Promise.all([
                this.loadOutgoingShares(),
                this.loadIncomingShares(),
                this.loadPendingShares(),
            ]);
            this.renderSharingView(container);
        } catch (error) {
            console.error('Error loading sharing view:', error);
            container.innerHTML = `<div class="empty-content"><p>${t('budget', 'Failed to load sharing data')}</p></div>`;
        }
    }

    async loadOutgoingShares() {
        this.outgoingShares = await this.fetchApi('/apps/budget/api/shares/outgoing');
    }

    async loadIncomingShares() {
        this.incomingShares = await this.fetchApi('/apps/budget/api/shares/incoming');
    }

    async loadPendingShares() {
        this.pendingShares = await this.fetchApi('/apps/budget/api/shares/pending');
    }

    renderSharingView(container) {
        const acceptedOutgoing = this.outgoingShares.filter(s => s.status === 'accepted');
        const pendingOutgoing = this.outgoingShares.filter(s => s.status === 'pending');
        const acceptedIncoming = this.incomingShares.filter(s => s.status === 'accepted');

        container.innerHTML = `
            <div class="sharing-page">
                <div class="sharing-intro">
                    <p>${t('budget', 'Share parts of your budget with other Nextcloud users. You choose exactly which accounts, categories, bills, income, and savings goals to share, and whether the other person can view or also edit them.')}</p>
                    <p>${t('budget', 'Shared categories merge with the other user\'s own categories — matching names are combined so you both work from the same budgets and spending totals. Transactions in shared accounts count toward shared category budgets for both users.')}</p>
                </div>

                ${this.pendingShares.length > 0 ? `
                <div class="sharing-section">
                    <h3>${t('budget', 'Pending Invitations')}</h3>
                    <div class="sharing-list">
                        ${this.pendingShares.map(share => `
                            <div class="sharing-item sharing-item-pending" data-share-id="${share.id}">
                                <div class="sharing-item-info">
                                    <span class="sharing-item-user">${this.esc(share.ownerUserId)}</span>
                                    <span class="sharing-item-status badge-pending">${t('budget', 'Pending')}</span>
                                </div>
                                <div class="sharing-item-actions">
                                    <button class="btn btn-primary btn-accept-share" data-id="${share.id}">${t('budget', 'Accept')}</button>
                                    <button class="btn btn-secondary btn-decline-share" data-id="${share.id}">${t('budget', 'Decline')}</button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
                ` : ''}

                <div class="sharing-section">
                    <h3>${t('budget', 'Share Your Budget')}</h3>
                    <p class="sharing-description">${t('budget', 'Invite a Nextcloud user and then configure which parts of your budget they can access.')}</p>
                    <div class="sharing-add-form">
                        <input type="text" id="share-username-input" placeholder="${t('budget', 'Enter Nextcloud username...')}" class="sharing-input" />
                        <button id="share-add-btn" class="btn btn-primary">${t('budget', 'Invite')}</button>
                    </div>

                    ${pendingOutgoing.length > 0 ? `
                    <div class="sharing-list">
                        ${pendingOutgoing.map(share => `
                            <div class="sharing-item" data-share-id="${share.id}">
                                <div class="sharing-item-info">
                                    <span class="sharing-item-user">${this.esc(share.sharedWithUserId)}</span>
                                    <span class="sharing-item-status badge-pending">${t('budget', 'Pending')}</span>
                                </div>
                                <div class="sharing-item-actions">
                                    <button class="btn btn-danger btn-revoke-share" data-id="${share.id}">${t('budget', 'Revoke')}</button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                    ` : ''}

                    ${acceptedOutgoing.length > 0 ? `
                    <div class="sharing-list">
                        ${acceptedOutgoing.map(share => `
                            <div class="sharing-item-block" data-share-id="${share.id}">
                                <div class="sharing-item">
                                    <div class="sharing-item-info">
                                        <span class="sharing-item-user">${this.esc(share.sharedWithUserId)}</span>
                                        <span class="sharing-item-status badge-accepted">${t('budget', 'Active')}</span>
                                    </div>
                                    <div class="sharing-item-actions">
                                        <button class="btn btn-secondary btn-configure-share" data-id="${share.id}">
                                            ${this.expandedConfigId === share.id ? t('budget', 'Close') : t('budget', 'Configure')}
                                        </button>
                                        <button class="btn btn-danger btn-revoke-share" data-id="${share.id}">${t('budget', 'Revoke')}</button>
                                    </div>
                                </div>
                                <div class="share-config-panel" id="share-config-${share.id}"
                                     style="display: ${this.expandedConfigId === share.id ? 'block' : 'none'}">
                                    <div class="share-config-loading">${t('budget', 'Loading configuration...')}</div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                    ` : ''}

                    ${this.outgoingShares.length === 0 ? `<p class="sharing-empty">${t('budget', 'You have not shared your budget with anyone yet.')}</p>` : ''}
                </div>

                ${acceptedIncoming.length > 0 ? `
                <div class="sharing-section">
                    <h3>${t('budget', 'Shared With Me')}</h3>
                    <p class="sharing-description">${t('budget', 'Budgets shared with you by other users.')}</p>
                    <div class="sharing-list">
                        ${acceptedIncoming.map(share => `
                            <div class="sharing-item" data-share-id="${share.id}">
                                <div class="sharing-item-info">
                                    <span class="sharing-item-user">${this.esc(share.ownerUserId)}</span>
                                    <span class="sharing-item-status badge-accepted">${t('budget', 'Active')}</span>
                                </div>
                                <div class="sharing-item-actions">
                                    <button class="btn btn-danger btn-leave-share" data-id="${share.id}">${t('budget', 'Leave')}</button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
                ` : ''}
            </div>
        `;

        this.bindEvents(container);

        // If a config panel was open, reload it
        if (this.expandedConfigId) {
            this.loadConfigPanel(this.expandedConfigId);
        }
    }

    bindEvents(container) {
        const addBtn = container.querySelector('#share-add-btn');
        const input = container.querySelector('#share-username-input');
        if (addBtn && input) {
            addBtn.addEventListener('click', () => this.handleShare(input.value.trim()));
            input.addEventListener('keydown', (e) => { if (e.key === 'Enter') this.handleShare(input.value.trim()); });
        }

        container.querySelectorAll('.btn-accept-share').forEach(btn =>
            btn.addEventListener('click', () => this.handleAccept(parseInt(btn.dataset.id))));
        container.querySelectorAll('.btn-decline-share').forEach(btn =>
            btn.addEventListener('click', () => this.handleDecline(parseInt(btn.dataset.id))));
        container.querySelectorAll('.btn-revoke-share').forEach(btn =>
            btn.addEventListener('click', () => this.handleRevoke(parseInt(btn.dataset.id))));
        container.querySelectorAll('.btn-leave-share').forEach(btn =>
            btn.addEventListener('click', () => this.handleLeave(parseInt(btn.dataset.id))));
        container.querySelectorAll('.btn-configure-share').forEach(btn =>
            btn.addEventListener('click', () => this.toggleConfigPanel(parseInt(btn.dataset.id))));
    }

    // ==================== Configuration Panel ====================

    async toggleConfigPanel(shareId) {
        if (this.expandedConfigId === shareId) {
            this.expandedConfigId = null;
        } else {
            this.expandedConfigId = shareId;
        }
        await this.loadSharingView();
    }

    async loadConfigPanel(shareId) {
        const panel = document.getElementById(`share-config-${shareId}`);
        if (!panel) return;

        try {
            // Only fetch the share config — use cached app state for entity lists
            const config = await this.fetchApi(`/apps/budget/api/shares/${shareId}/items`);

            // Use app state for entity lists (already loaded by loadInitialData)
            const accounts = this.app.accounts || [];
            const categoryTree = this.app.categoryTree || [];
            const flatCategories = this.flattenCategoryTree(Array.isArray(categoryTree) ? categoryTree : [], 0);
            // Filter to own categories only (owner configures their own entities)
            const ownCategories = flatCategories.filter(c => !c._shared);

            this.renderConfigPanel(panel, shareId, config, {
                account: accounts,
                category: ownCategories,
                bill: this.app.bills || [],
                recurring_income: this.app.recurringIncome || [],
                savings_goal: this.app.savingsGoals || [],
            });
        } catch (error) {
            console.error('Failed to load config:', error);
            panel.innerHTML = `<p class="sharing-error">${t('budget', 'Failed to load configuration')}</p>`;
        }
    }

    renderConfigPanel(panel, shareId, config, entities) {
        const sections = [
            { type: 'account', label: t('budget', 'Accounts'), nameField: 'name' },
            { type: 'category', label: t('budget', 'Categories'), nameField: 'name' },
            { type: 'bill', label: t('budget', 'Bills'), nameField: 'name' },
            { type: 'recurring_income', label: t('budget', 'Recurring Income'), nameField: 'name' },
            { type: 'savings_goal', label: t('budget', 'Savings Goals'), nameField: 'name' },
        ];

        panel.innerHTML = `
            <div class="share-config-content">
                ${sections.map(section => {
                    const items = entities[section.type] || [];
                    const currentConfig = config[section.type] || { ids: [], permission: 'read' };

                    if (items.length === 0) return '';

                    return `
                        <div class="share-config-section" data-type="${section.type}">
                            <div class="share-config-section-header">
                                <h4>${section.label}</h4>
                                <div class="share-config-header-actions">
                                    <button class="btn btn-small btn-select-all" data-type="${section.type}">${t('budget', 'Select All')}</button>
                                    <select class="share-config-permission" data-type="${section.type}">
                                        <option value="read" ${currentConfig.permission === 'read' ? 'selected' : ''}>${t('budget', 'Read only')}</option>
                                        <option value="write" ${currentConfig.permission === 'write' ? 'selected' : ''}>${t('budget', 'Read & Write')}</option>
                                    </select>
                                </div>
                            </div>
                            <div class="share-config-checklist">
                                ${items.map(item => {
                                    const id = item.id;
                                    const name = item[section.nameField] || `#${id}`;
                                    const checked = currentConfig.ids.includes(id);
                                    const depth = item._depth || 0;
                                    const indent = depth > 0 ? ` style="padding-left: ${depth * 20}px;"` : '';
                                    return `
                                        <label class="share-config-item"${indent}>
                                            <input type="checkbox" data-type="${section.type}" data-entity-id="${id}"
                                                   ${checked ? 'checked' : ''} />
                                            <span>${this.esc(name)}</span>
                                        </label>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    `;
                }).join('')}
                <div class="share-config-actions">
                    <button class="btn btn-primary btn-save-config" data-share-id="${shareId}">
                        ${t('budget', 'Save Configuration')}
                    </button>
                </div>
            </div>
        `;

        // Bind save button
        panel.querySelector('.btn-save-config')?.addEventListener('click', () => this.saveConfig(shareId));

        // Bind select all buttons
        panel.querySelectorAll('.btn-select-all').forEach(btn => {
            btn.addEventListener('click', () => {
                const type = btn.dataset.type;
                const checkboxes = panel.querySelectorAll(`input[data-type="${type}"]`);
                const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                checkboxes.forEach(cb => cb.checked = !allChecked);
                btn.textContent = allChecked ? t('budget', 'Select All') : t('budget', 'Deselect All');
            });
        });
    }

    async saveConfig(shareId) {
        const panel = document.getElementById(`share-config-${shareId}`);
        if (!panel) return;

        const types = ['account', 'category', 'bill', 'recurring_income', 'savings_goal'];
        const errors = [];

        for (const type of types) {
            const section = panel.querySelector(`.share-config-section[data-type="${type}"]`);
            if (!section) continue;

            const permSelect = section.querySelector('.share-config-permission');
            const permission = permSelect ? permSelect.value : 'read';

            const checkboxes = section.querySelectorAll(`input[data-type="${type}"]:checked`);
            const entityIds = Array.from(checkboxes).map(cb => parseInt(cb.dataset.entityId));

            try {
                await this.fetchApi(`/apps/budget/api/shares/${shareId}/items/${type}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ entityIds, permission }),
                });
            } catch (e) {
                errors.push(type);
            }
        }

        if (errors.length > 0) {
            showError(t('budget', 'Failed to save some sections: {types}', { types: errors.join(', ') }));
        } else {
            showSuccess(t('budget', 'Share configuration saved'));
        }
    }

    // ==================== Share Actions ====================

    async handleShare(username) {
        if (!username) { showError(t('budget', 'Please enter a username')); return; }
        try {
            await this.fetchApi('/apps/budget/api/shares', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sharedWithUserId: username }),
            });
            showSuccess(t('budget', 'Invitation sent to {user}', { user: username }));
            await this.loadSharingView();
        } catch (error) { showError(error.message || t('budget', 'Failed to share budget')); }
    }

    async handleAccept(shareId) {
        try {
            await this.fetchApi(`/apps/budget/api/shares/${shareId}/accept`, { method: 'POST' });
            showSuccess(t('budget', 'Share accepted — reloading budget data'));
            await this.app.loadInitialData();
            await this.loadSharingView();
        } catch (error) { showError(error.message || t('budget', 'Failed to accept share')); }
    }

    async handleDecline(shareId) {
        try {
            await this.fetchApi(`/apps/budget/api/shares/${shareId}/decline`, { method: 'POST' });
            showSuccess(t('budget', 'Share declined'));
            await this.loadSharingView();
        } catch (error) { showError(error.message || t('budget', 'Failed to decline share')); }
    }

    async handleRevoke(shareId) {
        if (!confirm(t('budget', 'Are you sure? The user will lose access to your budget.'))) return;
        try {
            await this.fetchApi(`/apps/budget/api/shares/${shareId}`, { method: 'DELETE' });
            showSuccess(t('budget', 'Share revoked'));
            if (this.expandedConfigId === shareId) this.expandedConfigId = null;
            await this.loadSharingView();
        } catch (error) { showError(error.message || t('budget', 'Failed to revoke share')); }
    }

    async handleLeave(shareId) {
        if (!confirm(t('budget', 'Are you sure you want to leave this shared budget?'))) return;
        try {
            await this.fetchApi(`/apps/budget/api/shares/${shareId}/leave`, { method: 'POST' });
            showSuccess(t('budget', 'Left shared budget — reloading your data'));
            await this.app.loadInitialData();
            await this.loadSharingView();
        } catch (error) { showError(error.message || t('budget', 'Failed to leave share')); }
    }

    // ==================== Helpers ====================

    /**
     * Flatten a category tree into a flat array with _depth for indentation.
     */
    flattenCategoryTree(tree, depth) {
        const result = [];
        for (const node of tree) {
            result.push({ ...node, _depth: depth });
            if (node.children && node.children.length > 0) {
                result.push(...this.flattenCategoryTree(node.children, depth + 1));
            }
        }
        return result;
    }

    esc(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}
