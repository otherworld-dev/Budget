/**
 * Auth Module - Authentication and session management
 */
export default class AuthModule {
    constructor(app) {
        this.app = app;

        // Auth state
        this.sessionToken = localStorage.getItem('budget_session_token');
        this.lastActivityTime = Date.now();
        this.inactivityTimer = null;

        // Shared session state
        this.sharedSessions = this.loadSharedSessions(); // { ownerUserId: sessionToken }
        this.currentOwnerUserId = null; // null = viewing own budget, otherwise owner user ID
    }

    /**
     * Load shared session tokens from localStorage
     */
    loadSharedSessions() {
        try {
            const stored = localStorage.getItem('budget_shared_sessions');
            return stored ? JSON.parse(stored) : {};
        } catch (error) {
            console.error('Failed to load shared sessions:', error);
            return {};
        }
    }

    /**
     * Save shared session tokens to localStorage
     */
    saveSharedSessions() {
        try {
            localStorage.setItem('budget_shared_sessions', JSON.stringify(this.sharedSessions));
        } catch (error) {
            console.error('Failed to save shared sessions:', error);
        }
    }

    // ============================================
    // State Proxies
    // ============================================

    get settings() { return this.app.settings; }

    // ============================================
    // Helper Method Proxies
    // ============================================

    setupNavigation() {
        return this.app.router.setupNavigation();
    }

    setupEventListeners() {
        return this.app.setupEventListeners();
    }

    async loadInitialData() {
        return this.app.loadInitialData();
    }

    showView(viewName) {
        return this.app.router.showView(viewName);
    }

    // ============================================
    // Auth Methods
    // ============================================

    async setupLockButton() {
        const lockBtn = document.getElementById('lock-app-btn');
        if (!lockBtn) return;

        try {
            // Check if password protection is enabled
            const response = await fetch(OC.generateUrl('/apps/budget/api/auth/status'), {
                headers: this.getAuthHeaders()
            });

            if (response.ok) {
                const status = await response.json();
                if (status.enabled && status.authenticated) {
                    lockBtn.style.display = 'block';
                }
            }
        } catch (error) {
            console.error('Failed to check lock button status:', error);
        }

        // Add click handler
        const lockLink = lockBtn.querySelector('a');
        if (lockLink) {
            lockLink.addEventListener('click', async (e) => {
                e.preventDefault();
                await this.lockApp();
            });
        }
    }

    async checkAuth() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/auth/status'), {
                headers: this.getAuthHeaders()
            });

            if (!response.ok) {
                return false;
            }

            const status = await response.json();

            // If password protection is not enabled, no auth required
            if (!status.enabled) {
                return false;
            }

            // If authenticated, no auth required
            if (status.authenticated) {
                return false;
            }

            // Password protection enabled but not authenticated
            return true;
        } catch (error) {
            console.error('Auth check failed:', error);
            return false;
        }
    }

    getAuthHeaders() {
        // Use current owner context if set, otherwise use own budget
        return this.getAuthHeadersForOwner(this.currentOwnerUserId);
    }

    showPasswordModal() {
        const modal = document.createElement('div');
        modal.id = 'budget-auth-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `
            <div class="budget-modal">
                <div class="budget-modal-header">
                    <h2>Password Required</h2>
                </div>
                <div class="budget-modal-body">
                    <p>This budget app is password protected. Please enter your password to continue.</p>
                    <form id="budget-auth-form">
                        <div class="form-group">
                            <label for="budget-auth-password">Password</label>
                            <input type="password" id="budget-auth-password" class="budget-input" required autocomplete="current-password">
                        </div>
                        <div id="budget-auth-error" class="error-message" style="display: none;"></div>
                        <div class="form-actions">
                            <button type="submit" class="budget-btn primary">Unlock</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        const form = document.getElementById('budget-auth-form');
        const passwordInput = document.getElementById('budget-auth-password');
        const errorDiv = document.getElementById('budget-auth-error');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const password = passwordInput.value;

            try {
                const response = await fetch(OC.generateUrl('/apps/budget/api/auth/verify'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken
                    },
                    body: JSON.stringify({ password })
                });

                const result = await response.json();

                if (result.success) {
                    // Store session token
                    this.sessionToken = result.sessionToken;
                    this.app.sessionToken = result.sessionToken; // Keep app in sync
                    localStorage.setItem('budget_session_token', result.sessionToken);

                    // Remove modal
                    modal.remove();

                    // Initialize app
                    this.setupNavigation();
                    this.setupEventListeners();
                    this.setupActivityMonitoring();
                    await this.loadInitialData();
                    await this.setupLockButton();
                    this.showView('dashboard');
                } else {
                    // Show error
                    errorDiv.textContent = result.error || 'Incorrect password';
                    errorDiv.style.display = 'block';
                    passwordInput.value = '';
                    passwordInput.focus();
                }
            } catch (error) {
                console.error('Password verification failed:', error);
                errorDiv.textContent = 'Failed to verify password. Please try again.';
                errorDiv.style.display = 'block';
            }
        });

        // Focus password input
        setTimeout(() => passwordInput.focus(), 100);
    }

    setupActivityMonitoring() {
        // Reset activity timer on user interaction
        const resetActivity = () => {
            this.lastActivityTime = Date.now();
            this.app.lastActivityTime = Date.now(); // Keep app in sync
        };

        // Listen to various user interactions
        ['mousedown', 'keydown', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, resetActivity, true);
        });

        // Check for inactivity every minute
        this.inactivityTimer = setInterval(() => {
            this.checkInactivity();
        }, 60000); // Check every minute

        // Keep app in sync
        this.app.inactivityTimer = this.inactivityTimer;
    }

    async checkInactivity() {
        // Only check if session exists and password protection is enabled
        if (!this.sessionToken) {
            return;
        }

        // Get session timeout from settings (default 30 minutes)
        const timeoutMinutes = parseInt(this.settings.session_timeout_minutes || '30');
        const timeoutMs = timeoutMinutes * 60 * 1000;
        const inactiveTime = Date.now() - this.lastActivityTime;

        if (inactiveTime >= timeoutMs) {
            // Session timed out due to inactivity
            await this.lockApp();
        }
    }

    async lockApp() {
        try {
            // Call lock endpoint
            await fetch(OC.generateUrl('/apps/budget/api/auth/lock'), {
                method: 'POST',
                headers: this.getAuthHeaders()
            });
        } catch (error) {
            console.error('Failed to lock session:', error);
        }

        // Clear session token
        this.sessionToken = null;
        this.app.sessionToken = null; // Keep app in sync
        localStorage.removeItem('budget_session_token');

        // Clear inactivity timer
        if (this.inactivityTimer) {
            clearInterval(this.inactivityTimer);
            this.inactivityTimer = null;
            this.app.inactivityTimer = null;
        }

        // Reload page to show password prompt
        window.location.reload();
    }

    // ============================================
    // Shared Budget Session Methods
    // ============================================

    /**
     * Get auth headers for accessing a specific owner's budget
     * @param {string|null} ownerUserId - Owner user ID, or null for own budget
     */
    getAuthHeadersForOwner(ownerUserId = null) {
        const headers = { 'requesttoken': OC.requestToken };

        // If viewing own budget, use regular session token
        if (!ownerUserId) {
            if (this.sessionToken) {
                headers['X-Budget-Session-Token'] = this.sessionToken;
            }
            return headers;
        }

        // If viewing shared budget, use shared session token
        const sharedToken = this.sharedSessions[ownerUserId];
        if (sharedToken) {
            headers['X-Budget-Shared-Session-Token'] = sharedToken;
        }

        return headers;
    }

    /**
     * Check if a shared budget requires password unlock
     * @param {string} ownerUserId - Owner user ID
     */
    async checkSharedBudgetAuth(ownerUserId) {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/auth/status'), {
                headers: this.getAuthHeadersForOwner(ownerUserId)
            });

            if (!response.ok) {
                return { requiresPassword: false };
            }

            const status = await response.json();

            // Check if the shared budget has a valid session
            const hasValidSession = this.sharedSessions[ownerUserId] != null;

            return {
                requiresPassword: status.enabled && !hasValidSession,
                hasPassword: status.hasPassword,
                enabled: status.enabled
            };
        } catch (error) {
            console.error('Failed to check shared budget auth:', error);
            return { requiresPassword: false };
        }
    }

    /**
     * Unlock a shared budget with password
     * @param {string} ownerUserId - Owner user ID
     * @param {string} password - Owner's password
     */
    async unlockSharedBudget(ownerUserId, password) {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/auth/unlock-shared'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ ownerUserId, password })
            });

            const result = await response.json();

            if (result.success) {
                // Store shared session token
                this.sharedSessions[ownerUserId] = result.sessionToken;
                this.saveSharedSessions();
                return { success: true };
            } else {
                return { success: false, error: result.error || 'Incorrect password' };
            }
        } catch (error) {
            console.error('Failed to unlock shared budget:', error);
            return { success: false, error: 'Failed to unlock shared budget' };
        }
    }

    /**
     * Lock (end) a shared budget session
     * @param {string} ownerUserId - Owner user ID
     */
    async lockSharedBudget(ownerUserId) {
        try {
            await fetch(OC.generateUrl('/apps/budget/api/auth/lock-shared'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ ownerUserId })
            });
        } catch (error) {
            console.error('Failed to lock shared budget:', error);
        }

        // Remove shared session token
        delete this.sharedSessions[ownerUserId];
        this.saveSharedSessions();

        // If we're currently viewing this shared budget, switch back to own budget
        if (this.currentOwnerUserId === ownerUserId) {
            this.currentOwnerUserId = null;
            await this.app.loadInitialData();
            this.app.showView('dashboard');
        }
    }

    /**
     * Show password modal for unlocking a shared budget
     * @param {string} ownerUserId - Owner user ID
     * @param {string} ownerDisplayName - Owner display name
     * @param {function} onSuccess - Callback on successful unlock
     */
    showSharedBudgetPasswordModal(ownerUserId, ownerDisplayName, onSuccess) {
        const modal = document.createElement('div');
        modal.id = 'budget-shared-auth-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `
            <div class="budget-modal">
                <div class="budget-modal-header">
                    <h2>Unlock Shared Budget</h2>
                </div>
                <div class="budget-modal-body">
                    <p>This budget is password protected. Enter <strong>${this.escapeHtml(ownerDisplayName)}</strong>'s password to access it.</p>
                    <form id="budget-shared-auth-form">
                        <div class="form-group">
                            <label for="budget-shared-auth-password">Password</label>
                            <input type="password" id="budget-shared-auth-password" class="budget-input" required autocomplete="off">
                        </div>
                        <div id="budget-shared-auth-error" class="error-message" style="display: none;"></div>
                        <div class="form-actions">
                            <button type="button" class="budget-btn secondary" id="budget-shared-auth-cancel">Cancel</button>
                            <button type="submit" class="budget-btn primary">Unlock</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        const form = document.getElementById('budget-shared-auth-form');
        const passwordInput = document.getElementById('budget-shared-auth-password');
        const errorDiv = document.getElementById('budget-shared-auth-error');
        const cancelBtn = document.getElementById('budget-shared-auth-cancel');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const password = passwordInput.value;

            const result = await this.unlockSharedBudget(ownerUserId, password);

            if (result.success) {
                modal.remove();
                if (onSuccess) onSuccess();
            } else {
                errorDiv.textContent = result.error;
                errorDiv.style.display = 'block';
                passwordInput.value = '';
                passwordInput.focus();
            }
        });

        cancelBtn.addEventListener('click', () => {
            modal.remove();
        });

        // Focus password input
        setTimeout(() => passwordInput.focus(), 100);
    }

    /**
     * Load active shared sessions from server
     */
    async loadActiveSharedSessions() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/auth/shared-sessions'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                return [];
            }

            return await response.json();
        } catch (error) {
            console.error('Failed to load active shared sessions:', error);
            return [];
        }
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
