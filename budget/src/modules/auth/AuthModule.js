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
        const headers = { 'requesttoken': OC.requestToken };
        if (this.sessionToken) {
            headers['X-Budget-Session-Token'] = this.sessionToken;
        }
        return headers;
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
}
