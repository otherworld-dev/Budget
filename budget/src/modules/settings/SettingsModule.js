/**
 * Settings Module - User preferences and configuration
 */
import { translate as t } from '@nextcloud/l10n';
import { showSuccess, showError } from '../../utils/notifications.js';
import { initDatePickers } from '../../utils/datepicker.js';

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
                throw new Error(t('budget', 'Failed to load settings'));
            }

            const settings = await response.json();
            await this.populateSettings(settings);
            this.updateNumberFormatPreview();
            await this.loadAdminSettings();
        } catch (error) {
            console.error('Error loading settings:', error);
            showError(t('budget', 'Failed to load settings'));
        }
    }

    async loadAdminSettings() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/admin/settings'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            // Non-admin users get a 403 — hide the section
            if (response.status === 403 || !response.ok) {
                return;
            }

            const adminSettings = await response.json();
            const section = document.getElementById('admin-settings-section');
            if (section) {
                section.style.display = 'block';
            }

            const toggle = document.getElementById('setting-bank-sync-enabled');
            if (toggle) {
                toggle.checked = adminSettings.bankSyncEnabled || false;
                toggle.addEventListener('change', async () => {
                    try {
                        await fetch(OC.generateUrl('/apps/budget/api/admin/settings'), {
                            method: 'PUT',
                            headers: {
                                'Content-Type': 'application/json',
                                'requesttoken': OC.requestToken
                            },
                            body: JSON.stringify({ bankSyncEnabled: toggle.checked })
                        });
                        showSuccess(t('budget', 'Admin settings saved'));
                        // Update bank sync nav visibility
                        if (this.app.bankSyncModule) {
                            this.app.bankSyncModule.checkStatus();
                        }
                    } catch (error) {
                        showError(t('budget', 'Failed to save admin settings'));
                        toggle.checked = !toggle.checked;
                    }
                });
            }
        } catch (error) {
            // Silently ignore — non-admin users won't see admin settings
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
                throw new Error(t('budget', 'Failed to save settings'));
            }

            await response.json();
            showSuccess(t('budget', 'Settings saved successfully'));

            // Update stored settings to apply immediately
            Object.assign(this.settings, settings);

            // Update account form currency default if needed
            this.updateAccountFormDefaults(settings);

            // Re-initialize date pickers with updated format
            initDatePickers(this.app.settings);

            // Reload current view to apply setting changes (e.g., date format)
            if (this.app.reloadCurrentView) {
                this.app.reloadCurrentView();
            }
        } catch (error) {
            console.error('Error saving settings:', error);
            showError(t('budget', 'Failed to save settings'));
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
        if (!confirm(t('budget', 'Are you sure you want to reset all settings to defaults? This action cannot be undone.'))) {
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
                throw new Error(t('budget', 'Failed to reset settings'));
            }

            const result = await response.json();
            await this.populateSettings(result.defaults);
            this.updateNumberFormatPreview();
            showSuccess(t('budget', 'Settings reset to defaults'));
        } catch (error) {
            console.error('Error resetting settings:', error);
            showError(t('budget', 'Failed to reset settings'));
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
                confirmBtn.innerHTML = '<span class="icon-loading-small" aria-hidden="true"></span> ' + t('budget', 'Deleting...');
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
                throw new Error(data.error || t('budget', 'Factory reset failed'));
            }

            // Close modal
            this.closeFactoryResetModal();

            // Show success message
            showSuccess(t('budget', 'Factory reset completed successfully. All data has been deleted.'));

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
                confirmBtn.innerHTML = '<span class="icon-delete" aria-hidden="true"></span> ' + t('budget', 'Delete Everything');
            }

            showError(error.message || t('budget', 'Failed to perform factory reset'));
        }
    }
}
