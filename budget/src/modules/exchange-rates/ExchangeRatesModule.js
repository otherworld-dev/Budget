/**
 * Exchange Rates Module - View and manage currency exchange rates
 *
 * Shows automatic rates from FloatRates/CoinGecko and allows
 * per-user manual rate overrides for currencies without auto-rates
 * or when the user wants a specific rate (e.g. Argentina's blue dollar).
 */
import { translate as t } from '@nextcloud/l10n';
import { showSuccess, showError } from '../../utils/notifications.js';
import * as dom from '../../utils/dom.js';

export default class ExchangeRatesModule {
    constructor(app) {
        this.app = app;
        this._eventsSetup = false;
        this.data = null; // { baseCurrency, autoRates, manualRates, currencies }
        this.currentFilter = 'all';
    }

    get settings() { return this.app.settings; }

    async loadExchangeRatesView() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/exchange-rates'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            this.data = await response.json();
            this.renderRatesPage();

            if (!this._eventsSetup) {
                this.setupEventListeners();
                this._eventsSetup = true;
            }
        } catch (error) {
            console.error('Failed to load exchange rates:', error);
            showError(t('budget', 'Failed to load exchange rates'));
        }
    }

    renderRatesPage() {
        const list = document.getElementById('exchange-rates-list');
        if (!list || !this.data) return;

        const { baseCurrency, autoRates, manualRates, currencies } = this.data;
        const baseRate = this.getBaseRatePerEur(baseCurrency, autoRates);

        // Build currency items with computed data
        const items = [];
        let autoCount = 0;
        let manualCount = 0;

        for (const [code, info] of Object.entries(currencies)) {
            if (code === baseCurrency) continue;

            const auto = autoRates[code] || null;
            const manual = manualRates[code] || null;
            const hasManual = manual !== null;

            let effectiveRatePerEur = null;
            let source = 'none';

            if (hasManual) {
                effectiveRatePerEur = parseFloat(manual.ratePerEur);
                source = 'manual';
                manualCount++;
            } else if (auto) {
                effectiveRatePerEur = parseFloat(auto.ratePerEur);
                source = auto.source || 'auto';
                autoCount++;
            }

            items.push({
                code,
                name: info.name,
                isCrypto: info.isCrypto,
                decimals: info.decimals,
                effectiveRatePerEur,
                source,
                hasManual,
            });
        }

        // Update summary cards
        this.updateSummary(items.length, autoCount, manualCount);

        // Render cards
        list.innerHTML = items.map(curr => {
            let displayRate = null;
            if (curr.effectiveRatePerEur !== null && baseRate !== null) {
                const ratePerBase = curr.effectiveRatePerEur / baseRate;
                displayRate = this.formatRate(ratePerBase, curr.decimals);
            }

            let currentDisplayRate = '';
            if (curr.hasManual && curr.effectiveRatePerEur !== null && baseRate !== null) {
                currentDisplayRate = (curr.effectiveRatePerEur / baseRate).toString();
            }

            const statusClass = curr.hasManual ? 'manual' : (curr.source === 'none' ? 'no-rate' : 'auto');
            const typeClass = curr.isCrypto ? 'crypto' : 'fiat';

            return `
                <div class="rate-card ${statusClass}" data-currency="${curr.code}" data-type="${typeClass}" data-source="${curr.source}" data-has-manual="${curr.hasManual}">
                    <div class="rate-card-header">
                        <div class="rate-info">
                            <h4 class="rate-currency-name">${dom.escapeHtml(curr.name)}</h4>
                            <span class="rate-currency-code">${curr.code}</span>
                        </div>
                        <div class="rate-value">
                            ${displayRate !== null
                                ? `1 ${baseCurrency} = ${displayRate} ${curr.code}`
                                : `<span class="no-rate-text">${t('budget', 'No rate available')}</span>`}
                        </div>
                    </div>
                    <div class="rate-details">
                        <div class="rate-source rate-source-${curr.source}">
                            <span class="status-badge">${this.getSourceLabel(curr.source)}</span>
                        </div>
                    </div>
                    <div class="rate-actions">
                        ${curr.hasManual ? `
                            <button class="edit-manual-rate-btn icon-button" data-currency="${curr.code}" data-rate="${currentDisplayRate}" title="${t('budget', 'Edit manual rate')}">
                                <span class="icon-rename" aria-hidden="true"></span>
                            </button>
                            <button class="remove-manual-rate-btn icon-button" data-currency="${curr.code}" title="${t('budget', 'Remove manual rate')}">
                                <span class="icon-delete" aria-hidden="true"></span>
                            </button>
                        ` : `
                            <button class="set-manual-rate-btn icon-button" data-currency="${curr.code}" title="${t('budget', 'Set manual rate')}">
                                <span class="icon-rename" aria-hidden="true"></span>
                            </button>
                        `}
                    </div>
                </div>
            `;
        }).join('');

        // Apply current filter
        this.applyFilter(this.currentFilter);
    }

    updateSummary(total, auto, manual) {
        const totalEl = document.getElementById('rates-total-count');
        const autoEl = document.getElementById('rates-auto-count');
        const manualEl = document.getElementById('rates-manual-count');
        if (totalEl) totalEl.textContent = total;
        if (autoEl) autoEl.textContent = auto;
        if (manualEl) manualEl.textContent = manual;
    }

    getBaseRatePerEur(baseCurrency, autoRates) {
        if (baseCurrency === 'EUR') return 1.0;
        const baseAuto = autoRates[baseCurrency];
        return baseAuto ? parseFloat(baseAuto.ratePerEur) : null;
    }

    formatRate(rate, decimals) {
        if (rate >= 1000) return rate.toFixed(2);
        if (rate >= 1) return rate.toFixed(Math.min(decimals, 4));
        return rate.toPrecision(6);
    }

    getSourceLabel(source) {
        const labels = {
            'floatrates': 'FloatRates',
            'ecb': 'ECB',
            'coingecko': 'CoinGecko',
            'reference': t('budget', 'Reference'),
            'manual': t('budget', 'Manual'),
            'none': t('budget', 'None'),
        };
        return labels[source] || t('budget', 'Unknown');
    }

    applyFilter(filter) {
        this.currentFilter = filter;
        const cards = document.querySelectorAll('.rate-card');

        cards.forEach(card => {
            const type = card.dataset.type;
            const source = card.dataset.source;
            const hasManual = card.dataset.hasManual === 'true';
            let show = false;

            switch (filter) {
                case 'all':
                    show = true;
                    break;
                case 'fiat':
                    show = type === 'fiat';
                    break;
                case 'crypto':
                    show = type === 'crypto';
                    break;
                case 'manual':
                    show = hasManual;
                    break;
                case 'no-rate':
                    show = source === 'none';
                    break;
            }

            card.style.display = show ? '' : 'none';
        });

        // Update active tab
        document.querySelectorAll('.exchange-rates-tabs .tab-button').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.filter === filter);
        });
    }

    setupEventListeners() {
        // Delegate click events on the rates list
        const list = document.getElementById('exchange-rates-list');
        if (list) {
            list.addEventListener('click', (e) => {
                const setBtn = e.target.closest('.set-manual-rate-btn');
                const editBtn = e.target.closest('.edit-manual-rate-btn');
                const removeBtn = e.target.closest('.remove-manual-rate-btn');

                if (setBtn) {
                    this.showManualRateModal(setBtn.dataset.currency);
                } else if (editBtn) {
                    this.showManualRateModal(editBtn.dataset.currency, editBtn.dataset.rate);
                } else if (removeBtn) {
                    this.confirmRemoveManualRate(removeBtn.dataset.currency);
                }
            });
        }

        // Filter tabs
        document.querySelectorAll('.exchange-rates-tabs .tab-button').forEach(btn => {
            btn.addEventListener('click', () => {
                this.applyFilter(btn.dataset.filter);
            });
        });

        // Refresh button
        document.getElementById('refresh-rates-btn')?.addEventListener('click', () => {
            this.refreshRates();
        });

        // Manual rate modal buttons
        document.getElementById('manual-rate-save-btn')?.addEventListener('click', () => {
            this.saveManualRate();
        });
        document.getElementById('manual-rate-cancel-btn')?.addEventListener('click', () => {
            this.hideManualRateModal();
        });
        document.getElementById('manual-rate-close-btn')?.addEventListener('click', () => {
            this.hideManualRateModal();
        });
    }

    showManualRateModal(currency, currentRate = null) {
        const modal = document.getElementById('manual-rate-modal');
        if (!modal || !this.data) return;

        const baseCurrency = this.data.baseCurrency;
        const currInfo = this.data.currencies[currency];
        const currName = currInfo ? currInfo.name : currency;
        const isEdit = currentRate !== null;

        const titleEl = document.getElementById('manual-rate-modal-title');
        if (titleEl) titleEl.textContent = isEdit ? t('budget', 'Edit Manual Rate') : t('budget', 'Set Manual Rate');

        document.getElementById('manual-rate-currency').textContent = `${currency} \u2014 ${currName}`;
        document.getElementById('manual-rate-base-label').textContent = `1 ${baseCurrency} =`;
        document.getElementById('manual-rate-target-label').textContent = currency;
        document.getElementById('manual-rate-currency-input').value = currency;

        const rateInput = document.getElementById('manual-rate-value');
        rateInput.value = currentRate || '';
        rateInput.placeholder = '0.00';

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
        rateInput.focus();
    }

    hideManualRateModal() {
        const modal = document.getElementById('manual-rate-modal');
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
    }

    async saveManualRate() {
        const currency = document.getElementById('manual-rate-currency-input')?.value;
        const rate = document.getElementById('manual-rate-value')?.value?.trim();

        if (!currency || !rate) {
            showError(t('budget', 'Please enter a rate value'));
            return;
        }

        if (isNaN(parseFloat(rate)) || parseFloat(rate) <= 0) {
            showError(t('budget', 'Rate must be a positive number'));
            return;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/exchange-rates/manual'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ currency, rate })
            });
            if (!response.ok) {
                const err = await response.json();
                throw new Error(err.error || 'Failed to save');
            }
            showSuccess(t('budget', 'Manual rate set for {currency}', { currency }));
            this.hideManualRateModal();
            await this.loadExchangeRatesView();
        } catch (error) {
            console.error('Failed to save manual rate:', error);
            showError(t('budget', 'Failed to save manual rate'));
        }
    }

    confirmRemoveManualRate(currency) {
        if (confirm(t('budget', 'Remove manual rate for {currency}? It will revert to the automatic rate.', { currency }))) {
            this.removeManualRate(currency);
        }
    }

    async removeManualRate(currency) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/exchange-rates/manual/${currency}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            showSuccess(t('budget', 'Manual rate removed for {currency}', { currency }));
            await this.loadExchangeRatesView();
        } catch (error) {
            console.error('Failed to remove manual rate:', error);
            showError(t('budget', 'Failed to remove manual rate'));
        }
    }

    async refreshRates() {
        const btn = document.getElementById('refresh-rates-btn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = t('budget', 'Refreshing…');
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/exchange-rates/refresh'), {
                method: 'POST',
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            showSuccess(t('budget', 'Exchange rates refreshed'));
            await this.loadExchangeRatesView();
        } catch (error) {
            console.error('Failed to refresh rates:', error);
            showError(t('budget', 'Failed to refresh exchange rates'));
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = t('budget', 'Refresh Rates');
            }
        }
    }
}
