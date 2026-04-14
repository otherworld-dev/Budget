/**
 * Sharing Module - Joint budget sharing management
 *
 * Manages sharing invitations. Once a share is accepted, the backend
 * transparently resolves both users to the same budget — no frontend
 * mode switching needed.
 */
import { translate as t } from '@nextcloud/l10n';
import { showSuccess, showError } from '../../utils/notifications.js';

export default class SharingModule {
    constructor(app) {
        this.app = app;
        this.outgoingShares = [];
        this.incomingShares = [];
        this.pendingShares = [];
    }

    /**
     * Make a fetch request with auth headers
     */
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

    /**
     * Load the sharing management view
     */
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
        try {
            this.outgoingShares = await this.fetchApi('/apps/budget/api/shares/outgoing');
        } catch (e) {
            this.outgoingShares = [];
        }
    }

    async loadIncomingShares() {
        try {
            this.incomingShares = await this.fetchApi('/apps/budget/api/shares/incoming');
        } catch (e) {
            this.incomingShares = [];
        }
    }

    async loadPendingShares() {
        try {
            this.pendingShares = await this.fetchApi('/apps/budget/api/shares/pending');
        } catch (e) {
            this.pendingShares = [];
        }
    }

    renderSharingView(container) {
        const acceptedIncoming = this.incomingShares.filter(s => s.status === 'accepted');

        container.innerHTML = `
            <div class="sharing-page">
                ${this.pendingShares.length > 0 ? `
                <div class="sharing-section">
                    <h3>${t('budget', 'Pending Invitations')}</h3>
                    <div class="sharing-list" id="pending-shares-list">
                        ${this.pendingShares.map(share => `
                            <div class="sharing-item sharing-item-pending" data-share-id="${share.id}">
                                <div class="sharing-item-info">
                                    <span class="sharing-item-user">${this.escapeHtml(share.ownerUserId)}</span>
                                    <span class="sharing-item-status badge-pending">${t('budget', 'Pending')}</span>
                                </div>
                                <div class="sharing-item-actions">
                                    <button class="btn btn-primary btn-accept-share" data-id="${share.id}">
                                        ${t('budget', 'Accept')}
                                    </button>
                                    <button class="btn btn-secondary btn-decline-share" data-id="${share.id}">
                                        ${t('budget', 'Decline')}
                                    </button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
                ` : ''}

                <div class="sharing-section">
                    <h3>${t('budget', 'Share Your Budget')}</h3>
                    <p class="sharing-description">${t('budget', 'Invite another Nextcloud user to share your budget. Both of you will see and manage the same accounts, transactions, and reports.')}</p>
                    <div class="sharing-add-form">
                        <input type="text"
                               id="share-username-input"
                               placeholder="${t('budget', 'Enter Nextcloud username...')}"
                               class="sharing-input" />
                        <button id="share-add-btn" class="btn btn-primary">
                            ${t('budget', 'Invite')}
                        </button>
                    </div>
                    ${this.outgoingShares.length > 0 ? `
                    <div class="sharing-list" id="outgoing-shares-list">
                        ${this.outgoingShares.map(share => `
                            <div class="sharing-item" data-share-id="${share.id}">
                                <div class="sharing-item-info">
                                    <span class="sharing-item-user">${this.escapeHtml(share.sharedWithUserId)}</span>
                                    <span class="sharing-item-status badge-${share.status}">${this.getStatusLabel(share.status)}</span>
                                </div>
                                <div class="sharing-item-actions">
                                    <button class="btn btn-danger btn-revoke-share" data-id="${share.id}">
                                        ${t('budget', 'Revoke')}
                                    </button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                    ` : `<p class="sharing-empty">${t('budget', 'You have not shared your budget with anyone yet.')}</p>`}
                </div>

                ${acceptedIncoming.length > 0 ? `
                <div class="sharing-section">
                    <h3>${t('budget', 'Joint Budgets')}</h3>
                    <p class="sharing-description">${t('budget', 'You are sharing a budget with these users. All data is shared — you both see the same accounts and transactions.')}</p>
                    <div class="sharing-list" id="incoming-shares-list">
                        ${acceptedIncoming.map(share => `
                            <div class="sharing-item" data-share-id="${share.id}">
                                <div class="sharing-item-info">
                                    <span class="sharing-item-user">${this.escapeHtml(share.ownerUserId)}</span>
                                    <span class="sharing-item-status badge-accepted">${t('budget', 'Active')}</span>
                                </div>
                                <div class="sharing-item-actions">
                                    <button class="btn btn-danger btn-leave-share" data-id="${share.id}">
                                        ${t('budget', 'Leave')}
                                    </button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
                ` : ''}
            </div>
        `;

        this.bindEvents(container);
    }

    bindEvents(container) {
        // Share button
        const addBtn = container.querySelector('#share-add-btn');
        const input = container.querySelector('#share-username-input');
        if (addBtn && input) {
            addBtn.addEventListener('click', () => this.handleShare(input.value.trim()));
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') this.handleShare(input.value.trim());
            });
        }

        // Accept/decline pending
        container.querySelectorAll('.btn-accept-share').forEach(btn => {
            btn.addEventListener('click', () => this.handleAccept(parseInt(btn.dataset.id)));
        });
        container.querySelectorAll('.btn-decline-share').forEach(btn => {
            btn.addEventListener('click', () => this.handleDecline(parseInt(btn.dataset.id)));
        });

        // Revoke outgoing
        container.querySelectorAll('.btn-revoke-share').forEach(btn => {
            btn.addEventListener('click', () => this.handleRevoke(parseInt(btn.dataset.id)));
        });

        // Leave share
        container.querySelectorAll('.btn-leave-share').forEach(btn => {
            btn.addEventListener('click', () => this.handleLeave(parseInt(btn.dataset.id)));
        });
    }

    async handleShare(username) {
        if (!username) {
            showError(t('budget', 'Please enter a username'));
            return;
        }

        try {
            await this.fetchApi('/apps/budget/api/shares', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sharedWithUserId: username }),
            });
            showSuccess(t('budget', 'Invitation sent to {user}', { user: username }));
            await this.loadSharingView();
        } catch (error) {
            showError(error.message || t('budget', 'Failed to share budget'));
        }
    }

    async handleAccept(shareId) {
        try {
            await this.fetchApi(`/apps/budget/api/shares/${shareId}/accept`, { method: 'POST' });
            showSuccess(t('budget', 'Share accepted — reloading budget data'));
            // Reload all data since we now see the owner's budget
            await this.app.loadInitialData();
            await this.loadSharingView();
        } catch (error) {
            showError(error.message || t('budget', 'Failed to accept share'));
        }
    }

    async handleDecline(shareId) {
        try {
            await this.fetchApi(`/apps/budget/api/shares/${shareId}/decline`, { method: 'POST' });
            showSuccess(t('budget', 'Share declined'));
            await this.loadSharingView();
        } catch (error) {
            showError(error.message || t('budget', 'Failed to decline share'));
        }
    }

    async handleRevoke(shareId) {
        if (!confirm(t('budget', 'Are you sure you want to revoke this share? The user will lose access to your budget.'))) {
            return;
        }

        try {
            await this.fetchApi(`/apps/budget/api/shares/${shareId}`, { method: 'DELETE' });
            showSuccess(t('budget', 'Share revoked'));
            await this.loadSharingView();
        } catch (error) {
            showError(error.message || t('budget', 'Failed to revoke share'));
        }
    }

    async handleLeave(shareId) {
        if (!confirm(t('budget', 'Are you sure you want to leave this shared budget? You will only see your own data.'))) {
            return;
        }

        try {
            await this.fetchApi(`/apps/budget/api/shares/${shareId}/leave`, { method: 'POST' });
            showSuccess(t('budget', 'Left shared budget — reloading your data'));
            // Reload all data since we now see only our own budget
            await this.app.loadInitialData();
            await this.loadSharingView();
        } catch (error) {
            showError(error.message || t('budget', 'Failed to leave share'));
        }
    }

    getStatusLabel(status) {
        switch (status) {
            case 'pending': return t('budget', 'Pending');
            case 'accepted': return t('budget', 'Active');
            case 'declined': return t('budget', 'Declined');
            default: return status;
        }
    }

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}
