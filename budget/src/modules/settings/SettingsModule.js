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
            this.setupReceiptFolderPicker();
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
                // This view's DOM is permanent and this loader runs on every
                // navigation to Settings — bind once or handlers stack and a
                // single toggle fires one PUT per visit.
                if (!this.bankSyncToggleBound) {
                    this.bankSyncToggleBound = true;
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
            }

            this.setupOcrSettings(adminSettings.ocr || {});
        } catch (error) {
            // Silently ignore — non-admin users won't see admin settings
        }
    }

    /**
     * Receipt scanning (admin, server-wide). The API key is deliberately never
     * sent to the browser — the server reports only whether one is stored, so
     * a blank field means "keep what is saved" rather than "clear it".
     */
    setupOcrSettings(ocr) {
        const provider = document.getElementById('setting-ocr-provider');
        const saveButton = document.getElementById('setting-ocr-save');
        if (!provider || !saveButton) return;

        const endpoint = document.getElementById('setting-ocr-endpoint');
        const model = document.getElementById('setting-ocr-model');
        const apiKey = document.getElementById('setting-ocr-api-key');
        const clearKey = document.getElementById('setting-ocr-clear-key');

        provider.value = ocr.provider || 'none';
        endpoint.value = ocr.endpoint || '';
        model.value = ocr.model || '';
        apiKey.value = '';

        this.ocrState = {
            apiKeySet: !!ocr.apiKeySet,
            nextcloudAiAvailable: !!ocr.nextcloudAiAvailable,
            relayBillingBase: ocr.relayBillingBase || 'https://ocr.otherworld.dev/billing',
        };
        this.renderOcrFields();

        // Values repopulate on every visit to Settings; listeners must not,
        // or each visit adds another handler and one click on Save fires one
        // PUT (and one toast, and one confirm dialog on Remove key) per visit.
        if (this.ocrListenersBound) return;
        this.ocrListenersBound = true;

        provider.addEventListener('change', () => this.renderOcrFields());

        clearKey.addEventListener('click', async () => {
            if (!confirm(t('budget', 'Remove the stored key? Receipt scanning stops working until a new one is saved.'))) return;
            await this.saveOcrSettings({ apiKey: '' });
        });

        saveButton.addEventListener('click', () => this.saveOcrSettings());

        // Checkout is a plain redirect on the relay, so a new tab is all a
        // subscription needs. The key comes back by success page and email.
        document.getElementById('setting-ocr-subscribe-btn')?.addEventListener('click', () => {
            const plan = document.getElementById('setting-ocr-plan').value;
            window.open(`${this.ocrState.relayBillingBase}/checkout?price=${encodeURIComponent(plan)}`, '_blank', 'noopener');
        });

        // The portal URL is minted server-side from the stored key — the key
        // itself never reaches this page.
        document.getElementById('setting-ocr-portal-btn')?.addEventListener('click', async () => {
            try {
                const response = await fetch(OC.generateUrl('/apps/budget/api/admin/settings/ocr/portal'), {
                    method: 'POST',
                    headers: { 'requesttoken': OC.requestToken }
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.url) {
                    throw new Error(data.error || t('budget', 'The billing portal could not be opened. Try again shortly.'));
                }
                window.open(data.url, '_blank', 'noopener');
            } catch (error) {
                showError(error.message);
            }
        });
    }

    /** Show only the fields the selected provider actually uses. */
    renderOcrFields() {
        const provider = document.getElementById('setting-ocr-provider').value;
        const show = (id, visible) => {
            const row = document.getElementById(id);
            if (row) row.style.display = visible ? '' : 'none';
        };

        show('setting-ocr-endpoint-row', provider === 'custom');
        show('setting-ocr-model-row', provider === 'custom');
        show('setting-ocr-key-row', provider === 'custom' || provider === 'relay');
        show('setting-ocr-privacy', provider !== 'none');

        // Relay billing: no key yet → the way to get one; key saved → the way
        // to manage the subscription behind it.
        show('setting-ocr-relay-billing', provider === 'relay');
        show('setting-ocr-subscribe-row', !this.ocrState?.apiKeySet);
        show('setting-ocr-portal-btn', !!this.ocrState?.apiKeySet);
        show('setting-ocr-portal-hint', !!this.ocrState?.apiKeySet);

        const keyLabel = document.getElementById('setting-ocr-key-label');
        if (keyLabel) {
            keyLabel.textContent = provider === 'relay'
                ? t('budget', 'License key')
                : t('budget', 'API key (leave blank if the endpoint needs none)');
        }

        const clearKey = document.getElementById('setting-ocr-clear-key');
        if (clearKey) {
            clearKey.style.display = this.ocrState?.apiKeySet ? '' : 'none';
        }

        const hint = document.getElementById('setting-ocr-provider-hint');
        if (hint) {
            hint.textContent = provider === 'nextcloud' && !this.ocrState?.nextcloudAiAvailable
                ? t('budget', 'No AI provider on this Nextcloud can read images yet, so this option will not work until one is set up. Install an AI app with image support and configure it in the Nextcloud admin settings.')
                : '';
        }

        const privacy = document.getElementById('setting-ocr-privacy-text');
        if (privacy) {
            privacy.textContent = this.ocrPrivacyText(provider);
        }
    }

    /** Plain-language statement of where receipt images actually go. */
    ocrPrivacyText(provider) {
        switch (provider) {
            case 'nextcloud':
                return t('budget', 'The receipt image is passed to whichever AI provider this Nextcloud is configured to use. Where it goes from there depends on that provider — if it is a local model, the image never leaves this server; if it is a cloud service, the image is sent to that company.');
            case 'custom':
                return t('budget', 'The receipt image is sent from this server to the endpoint below, along with the API key if you set one. Nothing else is sent: no account names, balances, or other transactions. If the endpoint is a machine on your own network, the image never leaves your network.');
            case 'relay':
                return t('budget', 'The receipt image is sent from this server to Otherworld\'s hosted service, which reads it and returns the figures. Images are processed and discarded, not stored or used for training. Nothing else is sent: no account names, balances, or other transactions.');
            default:
                return '';
        }
    }

    async saveOcrSettings(overrides = null) {
        const ocr = overrides || {
            provider: document.getElementById('setting-ocr-provider').value,
            endpoint: document.getElementById('setting-ocr-endpoint').value.trim(),
            model: document.getElementById('setting-ocr-model').value.trim(),
        };

        // An untouched key field means "keep the stored one", so it is only
        // sent when the admin actually typed something. Trimmed, because the
        // server trims too and a whitespace-only value would arrive there as
        // the empty string — which is the CLEAR sentinel. A stray space must
        // not delete a credential the deliberate path guards with a confirm.
        if (!overrides) {
            const typed = document.getElementById('setting-ocr-api-key').value;
            if (typed.trim() !== '') ocr.apiKey = typed;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/admin/settings'), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({ ocr })
            });

            // Check ok before parsing: an error body is not always JSON (a
            // maintenance-mode page, a proxy 502), and a JSON parse error
            // must not replace the translated failure message in the toast.
            if (!response.ok) {
                let message = '';
                try {
                    message = (await response.json()).error || '';
                } catch (parseError) {
                    // Non-JSON error body — fall through to the generic text.
                }
                throw new Error(message || t('budget', 'Failed to save admin settings'));
            }

            const body = await response.json();

            this.ocrState = {
                apiKeySet: !!body.ocr?.apiKeySet,
                nextcloudAiAvailable: !!body.ocr?.nextcloudAiAvailable,
                relayBillingBase: body.ocr?.relayBillingBase
                    || this.ocrState?.relayBillingBase
                    || 'https://ocr.otherworld.dev/billing',
            };
            document.getElementById('setting-ocr-api-key').value = '';
            this.renderOcrFields();

            showSuccess(body.ocr?.configured
                ? t('budget', 'Receipt scanning is on')
                : t('budget', 'Receipt scanning settings saved'));
        } catch (error) {
            showError(error.message || t('budget', 'Failed to save admin settings'));
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

    /**
     * "Browse…" next to the receipts folder opens Nextcloud's own folder
     * picker, so the path never has to be typed (#352). The picker returns
     * an absolute Files path; the setting is stored relative to the root.
     * Bound once — this view's DOM is permanent.
     */
    setupReceiptFolderPicker() {
        const btn = document.getElementById('setting-receipt-folder-browse');
        const input = document.getElementById('setting-receipt-folder');
        if (!btn || !input || btn.dataset.bound) return;
        btn.dataset.bound = '1';

        btn.addEventListener('click', () => {
            const current = input.value.trim() || 'Budget/Receipts';
            OC.dialogs.filepicker(
                t('budget', 'Select receipts folder'),
                (path) => {
                    input.value = String(path || '').replace(/^\/+|\/+$/g, '');
                },
                false,
                'httpd/unix-directory',
                true,
                OC.dialogs.FILEPICKER_TYPE_CHOOSE,
                '/' + current
            );
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
            'RUB': '₽', 'BYN': 'Br', 'TRY': '₺', 'JPY': '¥', 'CNY': '¥',
            'KRW': '₩', 'INR': '₹', 'IDR': 'Rp', 'THB': '฿',
            'PHP': '₱', 'MYR': 'RM', 'VND': '₫', 'TWD': 'NT$',
            'SGD': 'S$', 'HKD': 'HK$', 'PKR': 'Rs', 'BDT': '৳',
            'AUD': 'A$', 'NZD': 'NZ$', 'AED': 'AED', 'SAR': 'SAR',
            'QAR': 'QAR', 'JOD': 'JOD',
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
