/**
 * Settings Module - User preferences and configuration
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError } from '../../utils/notifications.js';

export default class SettingsModule {
    constructor(app) {
        this.app = app;
    }

    // Getters for app state
    get settings() { return this.app.settings; }
    set settings(value) { this.app.settings = value; }

    async loadSettingsView() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/settings'), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (!response.ok) {
                throw new Error('Failed to load settings');
            }

            const settings = await response.json();
            await this.populateSettings(settings);
            this.updateNumberFormatPreview();
        } catch (error) {
            console.error('Error loading settings:', error);
            showError('Failed to load settings');
        }
    }

    async populateSettings(settings) {
        // Populate each setting input
        Object.keys(settings).forEach(key => {
            const element = document.getElementById(`setting-${key.replace(/_/g, '-')}`);

            if (!element) return;

            const value = settings[key];

            if (element.type === 'checkbox') {
                element.checked = value === 'true' || value === true;
            } else {
                element.value = value;
            }
        });

        // Check password protection status and update UI
        await this.updatePasswordProtectionUI();
    }

    async updatePasswordProtectionUI() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/auth/status'), {
                headers: this.app.getAuthHeaders()
            });

            if (!response.ok) {
                return;
            }

            const status = await response.json();
            const passwordToggle = document.getElementById('setting-password-protection-enabled');
            const passwordConfig = document.getElementById('password-protection-config');

            if (passwordToggle) {
                passwordToggle.checked = status.enabled;

                if (status.enabled && passwordConfig) {
                    passwordConfig.style.display = 'block';
                    this.updatePasswordButtons(status.hasPassword);
                } else if (passwordConfig) {
                    passwordConfig.style.display = 'none';
                }
            }
        } catch (error) {
            console.error('Failed to check password protection status:', error);
        }
    }

    updatePasswordButtons(hasPassword) {
        const setupBtn = document.getElementById('setup-password-btn');
        const changeBtn = document.getElementById('change-password-btn');
        const disableBtn = document.getElementById('disable-password-btn');

        if (setupBtn) setupBtn.style.display = hasPassword ? 'none' : 'inline-block';
        if (changeBtn) changeBtn.style.display = hasPassword ? 'inline-block' : 'none';
        if (disableBtn) disableBtn.style.display = hasPassword ? 'inline-block' : 'none';
    }

    async saveSettings() {
        try {
            const settings = this.gatherSettings();

            const response = await fetch(OC.generateUrl('/apps/budget/api/settings'), {
                method: 'PUT',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(settings)
            });

            if (!response.ok) {
                throw new Error('Failed to save settings');
            }

            const result = await response.json();
            showSuccess('Settings saved successfully');

            // Update stored settings to apply immediately
            Object.assign(this.settings, settings);

            // Update account form currency default if needed
            this.updateAccountFormDefaults(settings);

            // Reload current view to apply setting changes (e.g., date format)
            if (this.app.reloadCurrentView) {
                this.app.reloadCurrentView();
            }
        } catch (error) {
            console.error('Error saving settings:', error);
            showError('Failed to save settings');
        }
    }

    gatherSettings() {
        const settingElements = document.querySelectorAll('.setting-input');
        const settings = {};

        settingElements.forEach(element => {
            const key = element.id.replace('setting-', '').replace(/-/g, '_');

            if (element.type === 'checkbox') {
                settings[key] = element.checked ? 'true' : 'false';
            } else {
                settings[key] = element.value;
            }
        });

        return settings;
    }

    async resetSettings() {
        if (!confirm('Are you sure you want to reset all settings to defaults? This action cannot be undone.')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/settings/reset'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (!response.ok) {
                throw new Error('Failed to reset settings');
            }

            const result = await response.json();
            await this.populateSettings(result.defaults);
            this.updateNumberFormatPreview();
            showSuccess('Settings reset to defaults');
        } catch (error) {
            console.error('Error resetting settings:', error);
            showError('Failed to reset settings');
        }
    }

    updateNumberFormatPreview() {
        const decimals = parseInt(document.getElementById('setting-number-format-decimals')?.value || '2');
        const decimalSep = document.getElementById('setting-number-format-decimal-sep')?.value || '.';
        const thousandsSep = document.getElementById('setting-number-format-thousands-sep')?.value ?? ',';
        const defaultCurrency = document.getElementById('setting-default-currency')?.value || 'USD';

        // Get currency symbol
        const currencySymbols = {
            'USD': '$', 'CAD': 'C$', 'MXN': 'MX$', 'BRL': 'R$',
            'ARS': 'AR$', 'CLP': 'CL$', 'COP': 'CO$', 'PEN': 'S/',
            'EUR': '€', 'GBP': '£', 'CHF': 'CHF', 'SEK': 'kr',
            'NOK': 'kr', 'DKK': 'kr', 'PLN': 'zł', 'CZK': 'Kč',
            'HUF': 'Ft', 'RON': 'lei', 'UAH': '₴', 'ISK': 'kr',
            'RUB': '₽', 'TRY': '₺', 'JPY': '¥', 'CNY': '¥',
            'KRW': '₩', 'INR': '₹', 'IDR': 'Rp', 'THB': '฿',
            'PHP': '₱', 'MYR': 'RM', 'VND': '₫', 'TWD': 'NT$',
            'SGD': 'S$', 'HKD': 'HK$', 'PKR': 'Rs', 'BDT': '৳',
            'AUD': 'A$', 'NZD': 'NZ$', 'AED': 'AED', 'SAR': 'SAR',
            'ILS': '₪', 'EGP': 'E£', 'NGN': '₦', 'KES': 'KSh',
            'ZAR': 'R',
        };
        const symbol = currencySymbols[defaultCurrency] || '$';

        // Format number 1234.56
        const testNumber = 1234.56;
        const parts = testNumber.toFixed(decimals).split('.');
        const integerPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandsSep);
        const decimalPart = decimals > 0 ? decimalSep + parts[1] : '';

        const formatted = symbol + integerPart + decimalPart;

        const previewElement = document.getElementById('number-format-preview');
        if (previewElement) {
            previewElement.textContent = formatted;
        }
    }

    updateAccountFormDefaults(settings) {
        // Update default currency in account form when it opens
        if (settings.default_currency) {
            const accountCurrencySelect = document.getElementById('account-currency');
            if (accountCurrencySelect && !accountCurrencySelect.value) {
                accountCurrencySelect.value = settings.default_currency;
            }
        }
    }

    // Password Protection UI methods
    showSetupPasswordModal() {
        const modal = document.createElement('div');
        modal.id = 'setup-password-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `
            <div class="budget-modal">
                <div class="budget-modal-header">
                    <h2>Set Up Password Protection</h2>
                    <button class="close-btn">×</button>
                </div>
                <div class="budget-modal-body">
                    <p>Enter a password to protect your budget app. You will need to enter this password when accessing the app.</p>
                    <form id="setup-password-form">
                        <div class="form-group">
                            <label for="new-password">New Password</label>
                            <input type="password" id="new-password" class="budget-input" required minlength="6" autocomplete="new-password">
                            <small>Minimum 6 characters</small>
                        </div>
                        <div class="form-group">
                            <label for="confirm-password">Confirm Password</label>
                            <input type="password" id="confirm-password" class="budget-input" required autocomplete="new-password">
                        </div>
                        <div id="setup-password-error" class="error-message" style="display: none;"></div>
                        <div class="form-actions">
                            <button type="button" class="budget-btn secondary close-btn">Cancel</button>
                            <button type="submit" class="budget-btn primary">Set Password</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        const form = document.getElementById('setup-password-form');
        const newPasswordInput = document.getElementById('new-password');
        const confirmPasswordInput = document.getElementById('confirm-password');
        const errorDiv = document.getElementById('setup-password-error');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const newPassword = newPasswordInput.value;
            const confirmPassword = confirmPasswordInput.value;

            if (newPassword !== confirmPassword) {
                errorDiv.textContent = 'Passwords do not match';
                errorDiv.style.display = 'block';
                return;
            }

            try {
                const response = await fetch(OC.generateUrl('/apps/budget/api/auth/setup'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...this.app.getAuthHeaders()
                    },
                    body: JSON.stringify({ password: newPassword })
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    // Store session token
                    this.app.sessionToken = result.sessionToken;
                    localStorage.setItem('budget_session_token', result.sessionToken);

                    showSuccess('Password protection enabled');
                    modal.remove();

                    // Update UI
                    this.updatePasswordButtons(true);
                } else {
                    errorDiv.textContent = result.error || 'Failed to set password';
                    errorDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('Failed to set password:', error);
                errorDiv.textContent = 'Failed to set password. Please try again.';
                errorDiv.style.display = 'block';
            }
        });

        // Close modal handlers
        modal.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', () => modal.remove());
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.remove();
        });

        newPasswordInput.focus();
    }

    showChangePasswordModal() {
        const modal = document.createElement('div');
        modal.id = 'change-password-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `
            <div class="budget-modal">
                <div class="budget-modal-header">
                    <h2>Change Password</h2>
                    <button class="close-btn">×</button>
                </div>
                <div class="budget-modal-body">
                    <form id="change-password-form">
                        <div class="form-group">
                            <label for="current-password">Current Password</label>
                            <input type="password" id="current-password" class="budget-input" required autocomplete="current-password">
                        </div>
                        <div class="form-group">
                            <label for="new-password-change">New Password</label>
                            <input type="password" id="new-password-change" class="budget-input" required minlength="6" autocomplete="new-password">
                            <small>Minimum 6 characters</small>
                        </div>
                        <div class="form-group">
                            <label for="confirm-password-change">Confirm New Password</label>
                            <input type="password" id="confirm-password-change" class="budget-input" required autocomplete="new-password">
                        </div>
                        <div id="change-password-error" class="error-message" style="display: none;"></div>
                        <div class="form-actions">
                            <button type="button" class="budget-btn secondary close-btn">Cancel</button>
                            <button type="submit" class="budget-btn primary">Change Password</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        const form = document.getElementById('change-password-form');
        const currentPasswordInput = document.getElementById('current-password');
        const newPasswordInput = document.getElementById('new-password-change');
        const confirmPasswordInput = document.getElementById('confirm-password-change');
        const errorDiv = document.getElementById('change-password-error');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const currentPassword = currentPasswordInput.value;
            const newPassword = newPasswordInput.value;
            const confirmPassword = confirmPasswordInput.value;

            if (newPassword !== confirmPassword) {
                errorDiv.textContent = 'New passwords do not match';
                errorDiv.style.display = 'block';
                return;
            }

            try {
                const response = await fetch(OC.generateUrl('/apps/budget/api/auth/password'), {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        ...this.app.getAuthHeaders()
                    },
                    body: JSON.stringify({
                        currentPassword: currentPassword,
                        newPassword: newPassword
                    })
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    showSuccess('Password changed successfully');
                    modal.remove();
                } else {
                    errorDiv.textContent = result.error || 'Failed to change password';
                    errorDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('Failed to change password:', error);
                errorDiv.textContent = 'Failed to change password. Please try again.';
                errorDiv.style.display = 'block';
            }
        });

        // Close modal handlers
        modal.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', () => modal.remove());
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.remove();
        });

        currentPasswordInput.focus();
    }

    showDisablePasswordModal() {
        const modal = document.createElement('div');
        modal.id = 'disable-password-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `
            <div class="budget-modal">
                <div class="budget-modal-header">
                    <h2>Disable Password Protection</h2>
                    <button class="close-btn">×</button>
                </div>
                <div class="budget-modal-body">
                    <p>Enter your current password to disable password protection.</p>
                    <form id="disable-password-form">
                        <div class="form-group">
                            <label for="disable-current-password">Current Password</label>
                            <input type="password" id="disable-current-password" class="budget-input" required autocomplete="current-password">
                        </div>
                        <div id="disable-password-error" class="error-message" style="display: none;"></div>
                        <div class="form-actions">
                            <button type="button" class="budget-btn secondary close-btn">Cancel</button>
                            <button type="submit" class="budget-btn primary">Disable Protection</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        const form = document.getElementById('disable-password-form');
        const passwordInput = document.getElementById('disable-current-password');
        const errorDiv = document.getElementById('disable-password-error');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const password = passwordInput.value;

            try {
                const response = await fetch(OC.generateUrl('/apps/budget/api/auth/disable'), {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        ...this.app.getAuthHeaders()
                    },
                    body: JSON.stringify({ password: password })
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    // Update UI
                    const passwordToggle = document.getElementById('setting-password-protection-enabled');
                    if (passwordToggle) passwordToggle.checked = false;

                    const passwordConfig = document.getElementById('password-protection-config');
                    if (passwordConfig) passwordConfig.style.display = 'none';

                    showSuccess('Password protection disabled');
                    modal.remove();
                } else {
                    errorDiv.textContent = result.error || 'Failed to disable password protection';
                    errorDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('Failed to disable password protection:', error);
                errorDiv.textContent = 'Failed to disable password protection. Please try again.';
                errorDiv.style.display = 'block';
            }
        });

        // Close modal handlers
        modal.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', () => modal.remove());
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.remove();
        });

        passwordInput.focus();
    }

    // Factory Reset methods
    setupFactoryResetEventListeners() {
        const factoryResetBtn = document.getElementById('factory-reset-btn');
        const factoryResetModal = document.getElementById('factory-reset-modal');
        const factoryResetInput = document.getElementById('factory-reset-confirm-input');
        const factoryResetConfirmBtn = document.getElementById('factory-reset-confirm-btn');
        const modalCloseButtons = factoryResetModal ? factoryResetModal.querySelectorAll('.close-btn') : [];

        // Open modal
        if (factoryResetBtn) {
            factoryResetBtn.addEventListener('click', () => {
                this.openFactoryResetModal();
            });
        }

        // Enable/disable confirm button based on input value
        if (factoryResetInput && factoryResetConfirmBtn) {
            factoryResetInput.addEventListener('input', (e) => {
                // User must type exactly "DELETE" (case-sensitive)
                factoryResetConfirmBtn.disabled = e.target.value !== 'DELETE';
            });
        }

        // Confirm button
        if (factoryResetConfirmBtn) {
            factoryResetConfirmBtn.addEventListener('click', () => {
                this.executeFactoryReset();
            });
        }

        // Close modal buttons
        modalCloseButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                this.closeFactoryResetModal();
            });
        });

        // Close modal on background click
        if (factoryResetModal) {
            factoryResetModal.addEventListener('click', (e) => {
                if (e.target === factoryResetModal) {
                    this.closeFactoryResetModal();
                }
            });
        }
    }

    openFactoryResetModal() {
        const modal = document.getElementById('factory-reset-modal');
        const input = document.getElementById('factory-reset-confirm-input');
        const confirmBtn = document.getElementById('factory-reset-confirm-btn');

        if (modal) {
            // Reset input and button state
            if (input) {
                input.value = '';
                input.focus(); // Auto-focus the input field
            }
            if (confirmBtn) confirmBtn.disabled = true;

            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
        }
    }

    closeFactoryResetModal() {
        const modal = document.getElementById('factory-reset-modal');
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
    }

    async executeFactoryReset() {
        try {
            // Show loading state
            const confirmBtn = document.getElementById('factory-reset-confirm-btn');
            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<span class="icon-loading-small" aria-hidden="true"></span> Deleting...';
            }

            const response = await fetch(OC.generateUrl('/apps/budget/api/setup/factory-reset'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    confirmed: true
                })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Factory reset failed');
            }

            // Close modal
            this.closeFactoryResetModal();

            // Show success message
            showSuccess('Factory reset completed successfully. All data has been deleted.');

            // Reload the page to show empty state
            setTimeout(() => {
                window.location.reload();
            }, 1500);

        } catch (error) {
            console.error('Factory reset error:', error);

            // Reset button state
            const confirmBtn = document.getElementById('factory-reset-confirm-btn');
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<span class="icon-delete" aria-hidden="true"></span> Delete Everything';
            }

            showError(error.message || 'Failed to perform factory reset');
        }
    }
}
