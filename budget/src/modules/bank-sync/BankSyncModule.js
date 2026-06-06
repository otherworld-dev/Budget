import { translate as t } from '@nextcloud/l10n';
import { showSuccess, showError } from '../../utils/notifications.js';

/**
 * Bank Sync Module — manages bank connections, account mappings, and sync operations.
 * Only visible when the admin has enabled bank sync.
 *
 * GoCardless flow: 3-step wizard (credentials → bank selection → authorization).
 * SimpleFIN flow: single-step (credentials → connect → done).
 */
export default class BankSyncModule {
    constructor(app) {
        this.app = app;
        this.connections = [];
        this.selectedConnectionId = null;

        // Wizard state
        this._wizardStep = 1;
        this._wizardCredentials = null;
        this._wizardConnectionId = null;
        this._wizardAuthUrl = null;
        this._selectedInstitutionId = null;
        this._institutions = [];
        this._reauthorizeConnectionId = null;

        // GoCardless supported country codes
        this._countryCodes = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IS', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT',
            'NL', 'NO', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'GB',
        ];
    }

    async init() {
        await this.checkStatus();
        this.setupEventListeners();
    }

    async checkStatus() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bank-sync/status'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            const data = await response.json();
            const navItem = document.getElementById('bank-sync-nav');
            if (navItem) {
                navItem.style.display = data.enabled ? '' : 'none';
            }
            return data;
        } catch (error) {
            console.error('Failed to check bank sync status:', error);
            return { enabled: false };
        }
    }

    setupEventListeners() {
        if (this._listenersSetup) return;
        this._listenersSetup = true;

        document.addEventListener('click', (e) => {
            if (e.target.closest('#add-bank-connection-btn')) {
                e.preventDefault();
                this.showConnectModal();
            } else if (e.target.closest('#bank-sync-step1-next')) {
                e.preventDefault();
                this.handleStep1Next();
            } else if (e.target.closest('#bank-sync-step2-connect')) {
                e.preventDefault();
                this.handleStep2Connect();
            } else if (e.target.closest('#bank-sync-step2-back')) {
                e.preventDefault();
                this.showWizardStep(1);
            } else if (e.target.closest('#bank-sync-check-auth')) {
                e.preventDefault();
                this.handleCheckAuth();
            } else if (e.target.closest('#sync-all-connections-btn')) {
                e.preventDefault();
                this.syncAll();
            } else if (e.target.closest('#refresh-accounts-btn')) {
                e.preventDefault();
                this.refreshAccounts();
            }
        });

        document.addEventListener('change', (e) => {
            if (e.target.id === 'bank-sync-provider') {
                const provider = e.target.value;
                document.getElementById('simplefin-fields').style.display = provider === 'simplefin' ? 'block' : 'none';
                document.getElementById('gocardless-fields').style.display = provider === 'gocardless' ? 'block' : 'none';
            } else if (e.target.id === 'bank-sync-country') {
                this.loadInstitutions(e.target.value);
            }
        });

        document.addEventListener('input', (e) => {
            if (e.target.id === 'bank-sync-institution-search') {
                this.filterInstitutions(e.target.value);
            }
        });
    }

    async loadBankSyncView() {
        const status = await this.checkStatus();
        const disabledNotice = document.getElementById('bank-sync-disabled-notice');
        const content = document.getElementById('bank-sync-content');

        if (!status.enabled) {
            if (disabledNotice) disabledNotice.style.display = 'block';
            if (content) content.style.display = 'none';
            return;
        }

        if (disabledNotice) disabledNotice.style.display = 'none';
        if (content) content.style.display = 'block';
        this.setupEventListeners();
        await this.loadConnections();
    }

    // ── Connections ─────────────────────────────────────────────

    async loadConnections() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bank-sync/connections'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to fetch connections');
            this.connections = await response.json();
            this.renderConnections();
        } catch (error) {
            console.error('Failed to load bank connections:', error);
        }
    }

    renderConnections() {
        const container = document.getElementById('bank-connections-list');
        if (!container) return;

        const syncAllBtn = document.getElementById('sync-all-connections-btn');
        const activeCount = this.connections.filter(c => c.connection.status === 'active').length;
        if (syncAllBtn) {
            syncAllBtn.style.display = activeCount >= 2 ? '' : 'none';
        }

        if (!this.connections.length) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No bank connections yet. Click "Add Connection" to get started.')}</div>`;
            return;
        }

        container.innerHTML = this.connections.map(({ connection, mappings }) => {
            const statusClass = connection.status === 'active' ? 'positive' : (connection.status === 'error' ? 'negative' : (connection.status === 'pending_auth' ? 'warning' : ''));
            const statusLabel = {
                active: t('budget', 'Active'),
                pending_auth: t('budget', 'Awaiting Authorization'),
                error: t('budget', 'Error'),
                expired: t('budget', 'Expired'),
            }[connection.status] || connection.status;

            const providerLabel = connection.provider === 'gocardless' ? 'GoCardless' : 'SimpleFIN';
            const lastSync = connection.lastSyncAt
                ? t('budget', 'Last sync: {date}', { date: new Date(connection.lastSyncAt).toLocaleString() })
                : t('budget', 'Never synced');
            const mappedCount = mappings.filter(m => m.budgetAccountId && m.enabled).length;
            const isExpired = connection.status === 'expired';
            const isGoCardless = connection.provider === 'gocardless';

            const actionBtn = (isExpired && isGoCardless)
                ? `<button class="btn btn-sm btn-warning bank-reauth-btn" data-connection-id="${connection.id}" title="${t('budget', 'Re-authorize')}">${t('budget', 'Re-authorize')}</button>`
                : `<button class="btn btn-sm bank-sync-btn" data-connection-id="${connection.id}" title="${t('budget', 'Sync now')}">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.65,6.35C16.2,4.9 14.21,4 12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20C15.73,20 18.84,17.45 19.73,14H17.65C16.83,16.33 14.61,18 12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6C13.66,6 15.14,6.69 16.22,7.78L13,11H20V4L17.65,6.35Z"/></svg>
                </button>
                <button class="btn btn-sm bank-force-sync-btn" data-connection-id="${connection.id}" title="${t('budget', 'Re-import previously deleted transactions')}" style="font-size: 11px; padding: 2px 8px; opacity: 0.7;">
                    ${t('budget', 'Force')}
                </button>`;

            return `
                <div class="bank-connection-card" data-connection-id="${connection.id}">
                    <div class="bank-connection-header">
                        <div class="bank-connection-info">
                            <strong>${this.escapeHtml(connection.name)}</strong>
                            <span class="bank-connection-provider">${providerLabel}</span>
                            <span class="bank-connection-status ${statusClass}">${statusLabel}</span>
                        </div>
                        <div class="bank-connection-actions">
                            ${actionBtn}
                            <button class="btn btn-sm bank-mappings-btn" data-connection-id="${connection.id}" title="${t('budget', 'Account mappings')}">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M3,6H21V8H3V6M3,11H21V13H3V11M3,16H21V18H3V16Z"/></svg>
                            </button>
                            <button class="btn btn-sm btn-danger bank-disconnect-btn" data-connection-id="${connection.id}" title="${t('budget', 'Disconnect')}">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="bank-connection-meta">
                        <span>${lastSync}</span>
                        <span>${t('budget', '{count} account(s) mapped', { count: mappedCount })}</span>
                        <label class="bank-connection-toggle" title="${t('budget', 'Auto-apply import rules to synced transactions')}">
                            <input type="checkbox" class="apply-rules-checkbox" data-connection-id="${connection.id}" ${connection.applyRules ? 'checked' : ''}>
                            <span>${t('budget', 'Apply rules')}</span>
                        </label>
                        ${connection.provider === 'simplefin' ? `
                        <label class="bank-connection-toggle" title="${t('budget', 'Also pull pending (not yet posted) transactions. They are marked Pending and reconciled when they post.')}">
                            <input type="checkbox" class="include-pending-checkbox" data-connection-id="${connection.id}" ${connection.includePending ? 'checked' : ''}>
                            <span>${t('budget', 'Include pending')}</span>
                        </label>` : ''}
                        ${connection.lastError ? `<span class="bank-connection-error">${this.escapeHtml(connection.lastError)}</span>` : ''}
                    </div>
                </div>
            `;
        }).join('');

        container.querySelectorAll('.bank-sync-btn').forEach(btn => {
            btn.addEventListener('click', () => this.syncConnection(parseInt(btn.dataset.connectionId)));
        });
        container.querySelectorAll('.bank-force-sync-btn').forEach(btn => {
            btn.addEventListener('click', () => this.syncConnection(parseInt(btn.dataset.connectionId), true));
        });
        container.querySelectorAll('.bank-reauth-btn').forEach(btn => {
            btn.addEventListener('click', () => this.startReauthorize(parseInt(btn.dataset.connectionId)));
        });
        container.querySelectorAll('.bank-mappings-btn').forEach(btn => {
            btn.addEventListener('click', () => this.showMappings(parseInt(btn.dataset.connectionId)));
        });
        container.querySelectorAll('.apply-rules-checkbox').forEach(cb => {
            cb.addEventListener('change', () => this.updateConnectionApplyRules(parseInt(cb.dataset.connectionId), cb.checked));
        });
        container.querySelectorAll('.include-pending-checkbox').forEach(cb => {
            cb.addEventListener('change', () => this.updateConnectionIncludePending(parseInt(cb.dataset.connectionId), cb.checked));
        });
        container.querySelectorAll('.bank-disconnect-btn').forEach(btn => {
            btn.addEventListener('click', () => this.disconnect(parseInt(btn.dataset.connectionId)));
        });
    }

    // ── Wizard: Modal & Step Navigation ─────────────────────────

    showConnectModal() {
        this._reauthorizeConnectionId = null;
        this._resetWizardState();
        const modal = document.getElementById('bank-sync-modal');
        if (!modal) return;

        document.getElementById('bank-sync-provider').value = '';
        document.getElementById('bank-sync-name').value = '';
        document.getElementById('bank-sync-setup-token').value = '';
        document.getElementById('bank-sync-secret-id').value = '';
        document.getElementById('bank-sync-secret-key').value = '';
        document.getElementById('simplefin-fields').style.display = 'none';
        document.getElementById('gocardless-fields').style.display = 'none';
        document.getElementById('bank-sync-provider').disabled = false;
        document.getElementById('bank-sync-name').disabled = false;

        this.showWizardStep(1);
        modal.style.display = 'flex';
    }

    showWizardStep(step) {
        this._wizardStep = step;
        for (let i = 1; i <= 3; i++) {
            const el = document.getElementById(`bank-sync-step-${i}`);
            if (el) el.style.display = i === step ? 'block' : 'none';
        }
        for (let i = 1; i <= 3; i++) {
            const err = document.getElementById(`bank-sync-step${i}-error`);
            if (err) { err.style.display = 'none'; err.textContent = ''; }
        }
        const success = document.getElementById('bank-sync-step3-success');
        if (success) { success.style.display = 'none'; success.textContent = ''; }
    }

    _resetWizardState() {
        this._wizardStep = 1;
        this._wizardCredentials = null;
        this._wizardConnectionId = null;
        this._wizardAuthUrl = null;
        this._selectedInstitutionId = null;
        this._institutions = [];
        this._reauthorizeConnectionId = null;
        this._authComplete = false;
        this._busy = false;
        const fallback = document.getElementById('bank-sync-auth-link-fallback');
        if (fallback) fallback.style.display = 'none';
    }

    _showStepError(step, message) {
        const el = document.getElementById(`bank-sync-step${step}-error`);
        if (el) {
            el.textContent = message;
            el.style.display = 'block';
        }
    }

    // ── Wizard Step 1: Credentials ──────────────────────────────

    async handleStep1Next() {
        if (this._busy) return;

        const provider = document.getElementById('bank-sync-provider').value;
        const name = document.getElementById('bank-sync-name').value.trim();

        if (!provider) {
            this._showStepError(1, t('budget', 'Please select a provider'));
            return;
        }
        if (!name) {
            this._showStepError(1, t('budget', 'Please enter a connection name'));
            return;
        }

        if (provider === 'simplefin') {
            await this._connectSimpleFIN(name);
            return;
        }

        const secretId = document.getElementById('bank-sync-secret-id').value.trim();
        const secretKey = document.getElementById('bank-sync-secret-key').value.trim();

        if (!secretId || !secretKey) {
            this._showStepError(1, t('budget', 'Please enter your API credentials'));
            return;
        }

        this._wizardCredentials = { secretId, secretKey, name, provider };

        const btn = document.getElementById('bank-sync-step1-next');
        this._busy = true;
        btn.disabled = true;
        btn.textContent = t('budget', 'Validating...');

        try {
            this._populateCountryDropdown();
            const country = document.getElementById('bank-sync-country').value;
            await this.loadInstitutions(country);
            this.showWizardStep(2);
        } catch (error) {
            this._showStepError(1, t('budget', 'Invalid credentials: {error}', { error: error.message }));
        } finally {
            this._busy = false;
            btn.disabled = false;
            btn.textContent = t('budget', 'Next');
        }
    }

    async _connectSimpleFIN(name) {
        const setupToken = document.getElementById('bank-sync-setup-token').value.trim();
        if (!setupToken) {
            this._showStepError(1, t('budget', 'Please enter a setup token'));
            return;
        }

        const btn = document.getElementById('bank-sync-step1-next');
        this._busy = true;
        btn.disabled = true;
        btn.textContent = t('budget', 'Connecting...');

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bank-sync/connections'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({ provider: 'simplefin', name, setupToken })
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            document.getElementById('bank-sync-modal').style.display = 'none';
            showSuccess(t('budget', 'Bank connected successfully'));
            await this.loadConnections();
        } catch (error) {
            this._showStepError(1, t('budget', 'Failed to connect: {error}', { error: error.message }));
        } finally {
            this._busy = false;
            btn.disabled = false;
            btn.textContent = t('budget', 'Next');
        }
    }

    // ── Wizard Step 2: Institution Selection ────────────────────

    _populateCountryDropdown() {
        const select = document.getElementById('bank-sync-country');
        if (!select || select.options.length > 1) return;

        select.innerHTML = '';
        const lang = OC.getLanguage ? OC.getLanguage() : 'en';
        const localeParts = lang.split(/[-_]/);
        const detectedCountry = (localeParts[1] || 'gb').toUpperCase();

        let displayNames;
        try {
            displayNames = new Intl.DisplayNames([lang], { type: 'region' });
        } catch (e) {
            displayNames = new Intl.DisplayNames(['en'], { type: 'region' });
        }

        const countries = this._countryCodes
            .map(code => ({ code, name: displayNames.of(code) || code }))
            .sort((a, b) => a.name.localeCompare(b.name));

        countries.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.code;
            opt.textContent = c.name;
            if (c.code === detectedCountry) opt.selected = true;
            select.appendChild(opt);
        });

        if (!select.value) {
            select.value = 'GB';
        }
    }

    async loadInstitutions(country) {
        const grid = document.getElementById('bank-sync-institutions-grid');
        const loading = document.getElementById('bank-sync-institutions-loading');
        const searchInput = document.getElementById('bank-sync-institution-search');

        if (loading) loading.style.display = 'block';
        if (grid) grid.innerHTML = '';
        if (searchInput) searchInput.value = '';
        this._selectedInstitutionId = null;
        this._updateConnectButton();

        const creds = this._wizardCredentials || {};

        try {
            const url = OC.generateUrl('/apps/budget/api/bank-sync/providers/gocardless/institutions');
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({
                    country,
                    secretId: creds.secretId || '',
                    secretKey: creds.secretKey || '',
                })
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            this._institutions = await response.json();
            this.renderInstitutions(this._institutions);
        } catch (error) {
            this._showStepError(2, t('budget', 'Failed to load banks: {error}', { error: error.message }));
            throw error;
        } finally {
            if (loading) loading.style.display = 'none';
        }
    }

    renderInstitutions(institutions) {
        const grid = document.getElementById('bank-sync-institutions-grid');
        if (!grid) return;

        if (!institutions.length) {
            grid.innerHTML = `<div class="empty-state-small">${t('budget', 'No banks found for this country.')}</div>`;
            return;
        }

        grid.innerHTML = institutions.map(inst => `
            <div class="bank-institution-tile" data-institution-id="${this.escapeHtml(inst.id)}" tabindex="0" role="button">
                ${inst.logo ? `<img src="${this.escapeHtml(inst.logo)}" alt="" class="bank-institution-logo" loading="lazy">` : '<div class="bank-institution-logo-placeholder"></div>'}
                <span class="bank-institution-name">${this.escapeHtml(inst.name)}</span>
            </div>
        `).join('');

        grid.querySelectorAll('.bank-institution-tile').forEach(tile => {
            tile.addEventListener('click', () => this._selectInstitution(tile));
            tile.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this._selectInstitution(tile);
                }
            });
        });
    }

    _selectInstitution(tile) {
        const grid = document.getElementById('bank-sync-institutions-grid');
        grid.querySelectorAll('.bank-institution-tile.selected').forEach(t => t.classList.remove('selected'));
        tile.classList.add('selected');
        this._selectedInstitutionId = tile.dataset.institutionId;
        this._updateConnectButton();
    }

    _updateConnectButton() {
        const btn = document.getElementById('bank-sync-step2-connect');
        if (btn) btn.disabled = !this._selectedInstitutionId;
    }

    filterInstitutions(query) {
        const lower = query.toLowerCase();
        const filtered = lower
            ? this._institutions.filter(inst => inst.name.toLowerCase().includes(lower))
            : this._institutions;
        this.renderInstitutions(filtered);
    }

    // ── Wizard Step 2 → 3: Connect & Open Auth ──────────────────

    async handleStep2Connect() {
        if (!this._selectedInstitutionId || this._busy) return;

        const btn = document.getElementById('bank-sync-step2-connect');
        this._busy = true;
        btn.disabled = true;
        btn.textContent = t('budget', 'Connecting...');

        const redirectUrl = window.location.href.split('#')[0];

        try {
            let result;

            if (this._reauthorizeConnectionId) {
                const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${this._reauthorizeConnectionId}/reauthorize`), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                    body: JSON.stringify({
                        institutionId: this._selectedInstitutionId,
                        redirectUrl,
                    })
                });

                if (!response.ok) {
                    const error = await response.json().catch(() => ({}));
                    throw new Error(error.error || `HTTP ${response.status}`);
                }

                result = await response.json();
                this._wizardConnectionId = this._reauthorizeConnectionId;
            } else {
                const creds = this._wizardCredentials;
                const response = await fetch(OC.generateUrl('/apps/budget/api/bank-sync/connections'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                    body: JSON.stringify({
                        provider: 'gocardless',
                        name: creds.name,
                        secretId: creds.secretId,
                        secretKey: creds.secretKey,
                        institutionId: this._selectedInstitutionId,
                        redirectUrl,
                    })
                });

                if (!response.ok) {
                    const error = await response.json().catch(() => ({}));
                    throw new Error(error.error || `HTTP ${response.status}`);
                }

                result = await response.json();
                this._wizardConnectionId = result.connection?.id;
            }

            this._wizardAuthUrl = result.authorizationUrl;
            if (this._wizardAuthUrl) {
                const authWindow = window.open(this._wizardAuthUrl, '_blank');
                if (!authWindow) {
                    const fallback = document.getElementById('bank-sync-auth-link-fallback');
                    const link = document.getElementById('bank-sync-auth-link');
                    if (fallback) fallback.style.display = 'block';
                    if (link) link.href = this._wizardAuthUrl;
                }
            }

            this.showWizardStep(3);
        } catch (error) {
            this._showStepError(2, t('budget', 'Failed to connect: {error}', { error: error.message }));
        } finally {
            this._busy = false;
            btn.disabled = false;
            btn.textContent = t('budget', 'Connect');
        }
    }

    // ── Wizard Step 3: Check Authorization ──────────────────────

    async handleCheckAuth() {
        if (!this._wizardConnectionId) return;

        // If auth already completed, close and reload
        if (this._authComplete) {
            document.getElementById('bank-sync-modal').style.display = 'none';
            this.loadConnections();
            return;
        }

        if (this._busy) return;

        const btn = document.getElementById('bank-sync-check-auth');
        this._busy = true;
        btn.disabled = true;
        btn.textContent = t('budget', 'Checking...');

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${this._wizardConnectionId}/refresh`), {
                method: 'POST',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            const mappings = await response.json();

            if (Array.isArray(mappings) && mappings.length > 0) {
                this._authComplete = true;
                const successEl = document.getElementById('bank-sync-step3-success');
                if (successEl) {
                    successEl.textContent = t('budget', 'Authorization successful! {count} account(s) found. You can now close this dialog and set up your account mappings.', { count: mappings.length });
                    successEl.style.display = 'block';
                }
                btn.textContent = t('budget', 'Done');
                btn.disabled = false;
            } else {
                this._showStepError(3, t('budget', 'Authorization not yet complete. Please finish the authorization in the bank window and try again.'));
            }
        } catch (error) {
            this._showStepError(3, t('budget', 'Authorization check failed: {error}', { error: error.message }));
        } finally {
            this._busy = false;
            if (!this._authComplete) {
                btn.disabled = false;
                btn.textContent = t('budget', "I've Completed Authorization");
            }
        }
    }

    // ── Re-Authorization ────────────────────────────────────────

    startReauthorize(connectionId) {
        const conn = this.connections.find(c => c.connection.id === connectionId);
        if (!conn) return;

        this._resetWizardState();
        this._reauthorizeConnectionId = connectionId;

        const modal = document.getElementById('bank-sync-modal');
        if (!modal) return;

        document.getElementById('bank-sync-provider').value = 'gocardless';
        document.getElementById('bank-sync-provider').disabled = true;
        document.getElementById('bank-sync-name').value = conn.connection.name;
        document.getElementById('bank-sync-name').disabled = true;
        document.getElementById('gocardless-fields').style.display = 'block';
        document.getElementById('simplefin-fields').style.display = 'none';
        document.getElementById('bank-sync-secret-id').value = '';
        document.getElementById('bank-sync-secret-key').value = '';

        this.showWizardStep(1);
        modal.style.display = 'flex';
    }

    // ── Sync ────────────────────────────────────────────────────

    async syncConnection(connectionId, force = false) {
        try {
            showSuccess(t('budget', force ? 'Force syncing...' : 'Syncing...'));
            const url = OC.generateUrl(`/apps/budget/api/bank-sync/connections/${connectionId}/sync`);
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ force })
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            const result = await response.json();

            if (result.message) {
                showError(result.message);
                this.showMappings(connectionId);
            } else {
                showSuccess(t('budget', 'Sync complete: {imported} imported, {skipped} skipped', {
                    imported: result.imported,
                    skipped: result.skipped
                }));
            }

            await this.loadConnections();
        } catch (error) {
            console.error('Failed to sync:', error);
            showError(t('budget', 'Sync failed: {error}', { error: error.message }));
            await this.loadConnections();
        }
    }

    // ── Sync All ────────────────────────────────────────────────

    async syncAll() {
        const activeConnections = this.connections.filter(c => c.connection.status === 'active');
        if (!activeConnections.length) return;

        const btn = document.getElementById('sync-all-connections-btn');
        if (btn) btn.disabled = true;

        let totalImported = 0;
        let totalSkipped = 0;
        let synced = 0;
        let errors = 0;

        for (let i = 0; i < activeConnections.length; i++) {
            const conn = activeConnections[i].connection;
            showSuccess(t('budget', 'Syncing {current} of {total}...', { current: i + 1, total: activeConnections.length }));

            try {
                const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${conn.id}/sync`), {
                    method: 'POST',
                    headers: { 'requesttoken': OC.requestToken }
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const result = await response.json();
                totalImported += result.imported || 0;
                totalSkipped += result.skipped || 0;
                synced++;
            } catch (error) {
                console.error(`Failed to sync connection ${conn.id}:`, error);
                errors++;
            }
        }

        if (errors > 0) {
            showError(t('budget', 'Synced {synced} of {total} connections: {imported} imported, {skipped} skipped, {errors} failed', {
                synced, total: activeConnections.length, imported: totalImported, skipped: totalSkipped, errors
            }));
        } else {
            showSuccess(t('budget', 'Synced {synced} connections: {imported} imported, {skipped} skipped', {
                synced, imported: totalImported, skipped: totalSkipped
            }));
        }

        if (btn) btn.disabled = false;
        await this.loadConnections();
    }

    // ── Disconnect ──────────────────────────────────────────────

    async disconnect(connectionId) {
        if (!confirm(t('budget', 'Are you sure you want to disconnect this bank? This will remove the connection and all account mappings.'))) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${connectionId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to disconnect');

            showSuccess(t('budget', 'Bank disconnected'));
            const mappingsSection = document.getElementById('bank-mappings-section');
            if (mappingsSection) mappingsSection.style.display = 'none';
            await this.loadConnections();
        } catch (error) {
            showError(t('budget', 'Failed to disconnect bank'));
        }
    }

    // ── Account Mappings ────────────────────────────────────────

    async showMappings(connectionId) {
        this.selectedConnectionId = connectionId;
        const section = document.getElementById('bank-mappings-section');
        if (!section) return;

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${connectionId}/mappings`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to fetch mappings');

            const mappings = await response.json();
            section.style.display = 'block';

            const conn = this.connections.find(c => c.connection.id === connectionId);
            const title = document.getElementById('bank-mappings-title');
            if (title && conn) {
                title.textContent = t('budget', 'Account Mappings — {name}', { name: conn.connection.name });
            }

            this.renderMappings(mappings, connectionId);
        } catch (error) {
            showError(t('budget', 'Failed to load account mappings'));
        }
    }

    renderMappings(mappings, connectionId) {
        const container = document.getElementById('bank-mappings-list');
        if (!container) return;

        const accounts = this.app.accounts || [];
        const accountOptions = accounts.map(a =>
            `<option value="${a.id}">${this.escapeHtml(a.name)} (${a.currency})</option>`
        ).join('');

        container.innerHTML = mappings.map(mapping => {
            const enabled = mapping.enabled ? 'checked' : '';
            const balance = mapping.lastBalance ? `${mapping.lastCurrency || ''} ${mapping.lastBalance}` : '';

            return `
                <div class="bank-mapping-row" data-mapping-id="${mapping.id}">
                    <div class="bank-mapping-info">
                        <label class="bank-mapping-enable">
                            <input type="checkbox" class="mapping-enabled-checkbox"
                                   data-mapping-id="${mapping.id}" data-connection-id="${connectionId}" ${enabled}>
                        </label>
                        <div>
                            <strong>${this.escapeHtml(mapping.externalAccountName || mapping.externalAccountId)}</strong>
                            ${balance ? `<small>${balance}</small>` : ''}
                            ${mapping.consentExpires ? `<small class="consent-warning">${t('budget', 'Consent expires: {date}', { date: new Date(mapping.consentExpires).toLocaleDateString() })}</small>` : ''}
                        </div>
                    </div>
                    <div class="bank-mapping-target">
                        <select class="mapping-account-select" data-mapping-id="${mapping.id}" data-connection-id="${connectionId}">
                            <option value="">${t('budget', '— Not mapped —')}</option>
                            ${accountOptions}
                        </select>
                    </div>
                </div>
            `;
        }).join('');

        mappings.forEach(mapping => {
            if (mapping.budgetAccountId) {
                const select = container.querySelector(`.mapping-account-select[data-mapping-id="${mapping.id}"]`);
                if (select) select.value = mapping.budgetAccountId;
            }
        });

        container.querySelectorAll('.mapping-enabled-checkbox').forEach(cb => {
            cb.addEventListener('change', () => this.updateMapping(
                parseInt(cb.dataset.connectionId),
                parseInt(cb.dataset.mappingId),
                null,
                cb.checked
            ));
        });

        container.querySelectorAll('.mapping-account-select').forEach(sel => {
            sel.addEventListener('change', () => this.updateMapping(
                parseInt(sel.dataset.connectionId),
                parseInt(sel.dataset.mappingId),
                sel.value ? parseInt(sel.value) : null,
                null
            ));
        });
    }

    async updateMapping(connectionId, mappingId, budgetAccountId, enabled) {
        try {
            const body = {};
            if (budgetAccountId !== null) body.budgetAccountId = budgetAccountId;
            if (enabled !== null) body.enabled = enabled;

            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${connectionId}/mappings/${mappingId}`), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify(body)
            });

            if (!response.ok) throw new Error('Failed to update mapping');
            showSuccess(t('budget', 'Mapping updated'));
        } catch (error) {
            showError(t('budget', 'Failed to update mapping'));
        }
    }

    // ── Refresh Accounts ────────────────────────────────────────

    async refreshAccounts() {
        if (!this.selectedConnectionId) return;

        const btn = document.getElementById('refresh-accounts-btn');
        if (btn) btn.disabled = true;

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${this.selectedConnectionId}/refresh`), {
                method: 'POST',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            const mappings = await response.json();
            this.renderMappings(mappings, this.selectedConnectionId);
            showSuccess(t('budget', 'Accounts refreshed'));
        } catch (error) {
            showError(t('budget', 'Failed to refresh accounts: {error}', { error: error.message }));
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // ── Utilities ───────────────────────────────────────────────

    async updateConnectionApplyRules(connectionId, applyRules) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${connectionId}`), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({ applyRules })
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            showSuccess(t('budget', applyRules ? 'Import rules will be applied during sync' : 'Import rules disabled for this connection'));
        } catch (error) {
            showError(t('budget', 'Failed to update connection settings'));
        }
    }

    async updateConnectionIncludePending(connectionId, includePending) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bank-sync/connections/${connectionId}`), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({ includePending })
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            showSuccess(t('budget', includePending ? 'Pending transactions will be included during sync' : 'Pending transactions excluded for this connection'));
        } catch (error) {
            showError(t('budget', 'Failed to update connection settings'));
        }
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
