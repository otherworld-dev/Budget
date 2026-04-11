/**
 * Assets Module - Non-cash asset tracking with value history and projections
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError } from '../../utils/notifications.js';
import { setDateValue, clearDateValue } from '../../utils/datepicker.js';
import Chart from 'chart.js/auto';

export default class AssetsModule {
    constructor(app) {
        this.app = app;
    }

    // Getters for app state
    get assets() { return this.app.assets; }
    set assets(value) { this.app.assets = value; }
    get currentAsset() { return this.app.currentAsset; }
    set currentAsset(value) { this.app.currentAsset = value; }
    get settings() { return this.app.settings; }
    get charts() { return this.app.charts; }

    async loadAssetsView() {
        try {
            await this.loadAssets();
            this.renderAssets();
            this.setupAssetEventListeners();
        } catch (error) {
            console.error('Failed to load assets view:', error);
            showError(t('budget', 'Failed to load assets'));
        }
    }

    async loadAssets() {
        const response = await fetch(OC.generateUrl('/apps/budget/api/assets'), {
            headers: { 'requesttoken': OC.requestToken }
        });
        if (!response.ok) throw new Error('Failed to fetch assets');
        this.assets = await response.json();
    }

    async loadAssetSummary() {
        const response = await fetch(OC.generateUrl('/apps/budget/api/assets/summary'), {
            headers: { 'requesttoken': OC.requestToken }
        });
        if (!response.ok) throw new Error('Failed to fetch asset summary');
        return await response.json();
    }

    async loadAssetProjection() {
        const response = await fetch(OC.generateUrl('/apps/budget/api/assets/projection'), {
            headers: { 'requesttoken': OC.requestToken }
        });
        if (!response.ok) throw new Error('Failed to fetch asset projection');
        return await response.json();
    }

    renderAssets() {
        const list = document.getElementById('assets-list');
        const emptyState = document.getElementById('empty-assets');

        if (!this.assets || this.assets.length === 0) {
            list.innerHTML = '';
            emptyState.style.display = 'block';
            this.updateAssetsSummary({ totalAssetWorth: 0, assetCount: 0 });
            return;
        }

        emptyState.style.display = 'none';
        list.innerHTML = this.assets.map(asset => this.renderAssetCard(asset)).join('');

        // Load and update summary
        this.loadAssetSummary().then(summary => {
            this.updateAssetsSummary(summary);
        });

        // Load and update projections
        this.loadAssetProjection().then(projection => {
            this.updateAssetsProjection(projection);
        });
    }

    getAssetTypeInfo(type) {
        const typeMap = {
            real_estate: {
                label: t('budget', 'Real Estate'),
                color: '#2e7d32',
                icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M10,2V4.26L12,5.59V4H22V19H17V21H24V2H10M7.5,5L0,10V21H15V10L7.5,5M14,6V6.93L15.61,8H16V6H14M18,6V8H20V6H18M7.5,7.5L13,11V19H10V13H5V19H2V11L7.5,7.5M18,10V12H20V10H18M18,14V16H20V14H18Z"/></svg>'
            },
            vehicle: {
                label: t('budget', 'Vehicle'),
                color: '#1565c0',
                icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M5,11L6.5,6.5H17.5L19,11M17.5,16A1.5,1.5 0 0,1 16,14.5A1.5,1.5 0 0,1 17.5,13A1.5,1.5 0 0,1 19,14.5A1.5,1.5 0 0,1 17.5,16M6.5,16A1.5,1.5 0 0,1 5,14.5A1.5,1.5 0 0,1 6.5,13A1.5,1.5 0 0,1 8,14.5A1.5,1.5 0 0,1 6.5,16M18.92,6C18.72,5.42 18.16,5 17.5,5H6.5C5.84,5 5.28,5.42 5.08,6L3,12V20A1,1 0 0,0 4,21H5A1,1 0 0,0 6,20V19H18V20A1,1 0 0,0 19,21H20A1,1 0 0,0 21,20V12L18.92,6Z"/></svg>'
            },
            jewelry: {
                label: t('budget', 'Jewelry'),
                color: '#7b1fa2',
                icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M6,2L2,8L12,22L22,8L18,2H6M6.8,4H10.4L8.6,7.33L6.8,4M13.6,4H17.2L15.4,7.33L13.6,4M12,4.62L13.8,7.94H10.2L12,4.62M5.78,6.35L7.6,9.67L4.34,9.67L5.78,6.35M18.22,6.35L19.66,9.67H16.4L18.22,6.35M12,9.67L16.14,9.67L12,17.27L7.86,9.67H12Z"/></svg>'
            },
            collectibles: {
                label: t('budget', 'Collectibles'),
                color: '#f57f17',
                icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg>'
            },
            other: {
                label: t('budget', 'Other'),
                color: '#546e7a',
                icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M21,16.5C21,16.88 20.79,17.21 20.47,17.38L12.57,21.82C12.41,21.94 12.21,22 12,22C11.79,22 11.59,21.94 11.43,21.82L3.53,17.38C3.21,17.21 3,16.88 3,16.5V7.5C3,7.12 3.21,6.79 3.53,6.62L11.43,2.18C11.59,2.06 11.79,2 12,2C12.21,2 12.41,2.06 12.57,2.18L20.47,6.62C20.79,6.79 21,7.12 21,7.5V16.5Z"/></svg>'
            }
        };
        return typeMap[type] || typeMap.other;
    }

    renderAssetCard(asset) {
        const currency = asset.currency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);
        const typeInfo = this.getAssetTypeInfo(asset.type);

        let valueDisplay = '--';
        if (asset.currentValue !== null) {
            valueDisplay = formatters.formatCurrency(asset.currentValue, currency, this.settings);
        }

        let rateDisplay = '';
        if (asset.annualChangeRate !== null && asset.annualChangeRate !== 0) {
            const ratePercent = (asset.annualChangeRate * 100).toFixed(1);
            const rateClass = asset.annualChangeRate > 0 ? 'positive' : 'negative';
            const rateSign = asset.annualChangeRate > 0 ? '+' : '';
            const arrowIcon = asset.annualChangeRate > 0
                ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M7,15L12,10L17,15H7Z"/></svg>'
                : '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M7,10L12,15L17,10H7Z"/></svg>';
            rateDisplay = `<span class="asset-rate ${rateClass}">${arrowIcon} ${rateSign}${ratePercent}%/yr</span>`;
        }

        let purchaseInfo = '';
        const purchaseParts = [];
        if (asset.purchasePrice !== null && asset.purchasePrice !== undefined) {
            purchaseParts.push(t('budget', 'Purchased at {price}', { price: formatters.formatCurrency(asset.purchasePrice, currency, this.settings) }));
        }
        if (asset.purchaseDate) {
            purchaseParts.push(formatters.formatDate(asset.purchaseDate, this.settings));
        }
        if (purchaseParts.length > 0) {
            purchaseInfo = `<div class="asset-purchase-info">${purchaseParts.join(' &middot; ')}</div>`;
        }

        const descriptionHtml = asset.description
            ? `<div class="asset-description">${dom.escapeHtml(asset.description)}</div>`
            : '';

        return `
            <div class="asset-card" data-id="${asset.id}">
                <div class="asset-card-header">
                    <div class="asset-card-title">
                        <span class="asset-type-icon" style="background: ${typeInfo.color}15; color: ${typeInfo.color}">
                            ${typeInfo.icon}
                        </span>
                        <div>
                            <h4 class="asset-name">${dom.escapeHtml(asset.name)}</h4>
                            <span class="asset-type-badge">${typeInfo.label}</span>
                        </div>
                    </div>
                    <div class="asset-card-actions">
                        <button class="asset-edit-btn icon-button" title="${t('budget', 'Edit')}" data-id="${asset.id}">
                            <span class="icon-rename" aria-hidden="true"></span>
                        </button>
                        <button class="asset-delete-btn icon-button delete-btn" title="${t('budget', 'Delete')}" data-id="${asset.id}">
                            <span class="icon-delete" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
                <div class="asset-card-body">
                    <div class="asset-value-section">
                        <span class="asset-value-label">${t('budget', 'Current Value')}</span>
                        <div class="asset-value">${valueDisplay}</div>
                    </div>
                    ${rateDisplay}
                    ${purchaseInfo}
                </div>
                <div class="asset-card-footer">
                    ${descriptionHtml}
                    <button class="asset-view-btn" data-id="${asset.id}">${t('budget', 'View Details')} &rarr;</button>
                </div>
            </div>
        `;
    }

    updateAssetsSummary(summary) {
        const currency = summary.baseCurrency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);
        const assetWorth = summary.totalAssetWorth || 0;
        const count = summary.assetCount || 0;

        const worthEl = document.getElementById('assets-total-worth');
        const countEl = document.getElementById('assets-count');

        if (worthEl) {
            worthEl.textContent = formatters.formatCurrency(assetWorth, currency, this.settings);
        }
        if (countEl) {
            countEl.textContent = count;
        }

        // Update dashboard hero card
        const heroAssetsValue = document.getElementById('hero-assets-value');
        const heroAssetsCount = document.getElementById('hero-assets-count');

        if (heroAssetsValue) {
            heroAssetsValue.textContent = formatters.formatCurrency(assetWorth, currency, this.settings);
        }
        if (heroAssetsCount) {
            heroAssetsCount.textContent = n('budget', '%n asset', '%n assets', count);
        }

        // Show warning for unconvertible currencies
        const warningEl = document.getElementById('assets-conversion-warning');
        if (warningEl) {
            if (summary.unconvertedCurrencies && summary.unconvertedCurrencies.length > 0) {
                const currencies = summary.unconvertedCurrencies.join(', ');
                warningEl.textContent = t('budget', 'Some assets ({currencies}) are excluded from the total because exchange rates are unavailable. Add rates in Settings to include them.', { currencies });
                warningEl.style.display = 'block';
            } else {
                warningEl.style.display = 'none';
            }
        }
    }

    updateAssetsProjection(projection) {
        const currency = projection.baseCurrency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);

        const projectedValueEl = document.getElementById('assets-projected-value');
        if (projectedValueEl) {
            projectedValueEl.textContent = formatters.formatCurrency(projection.totalProjectedValue || 0, currency, this.settings);
        }
    }

    setupAssetEventListeners() {
        // Add asset button
        const addBtn = document.getElementById('add-asset-btn');
        const emptyAddBtn = document.getElementById('empty-assets-add-btn');

        if (addBtn) {
            addBtn.onclick = () => this.showAssetModal();
        }
        if (emptyAddBtn) {
            emptyAddBtn.onclick = () => this.showAssetModal();
        }

        // Asset form
        const assetForm = document.getElementById('asset-form');
        if (assetForm) {
            assetForm.onsubmit = (e) => {
                e.preventDefault();
                this.saveAsset();
            };
        }

        // Modal close buttons
        document.querySelectorAll('#asset-modal .cancel-btn').forEach(btn => {
            btn.onclick = () => this.closeAssetModal();
        });

        // Value update form
        const valueForm = document.getElementById('asset-value-form');
        if (valueForm) {
            valueForm.onsubmit = (e) => {
                e.preventDefault();
                this.saveValueUpdate();
            };
        }
        document.querySelectorAll('#asset-value-modal .cancel-btn').forEach(btn => {
            btn.onclick = () => this.closeValueModal();
        });

        // Asset card actions (delegated)
        const assetsList = document.getElementById('assets-list');
        if (assetsList) {
            assetsList.onclick = (e) => {
                const viewBtn = e.target.closest('.asset-view-btn');
                const editBtn = e.target.closest('.asset-edit-btn');
                const deleteBtn = e.target.closest('.asset-delete-btn');
                const card = e.target.closest('.asset-card');

                if (viewBtn) {
                    this.showAssetDetails(parseInt(viewBtn.dataset.id));
                } else if (editBtn) {
                    this.showAssetModal(parseInt(editBtn.dataset.id));
                } else if (deleteBtn) {
                    this.deleteAsset(parseInt(deleteBtn.dataset.id));
                } else if (card) {
                    this.showAssetDetails(parseInt(card.dataset.id));
                }
            };
        }

        // Detail view buttons
        const backBtn = document.getElementById('back-to-assets-btn');
        if (backBtn) {
            backBtn.onclick = () => this.closeAssetDetails();
        }

        const editDetailBtn = document.getElementById('asset-edit-detail-btn');
        if (editDetailBtn) {
            editDetailBtn.onclick = () => {
                if (this.currentAsset) {
                    this.showAssetModal(this.currentAsset.id);
                }
            };
        }

        const updateValueBtn = document.getElementById('update-value-btn');
        if (updateValueBtn) {
            updateValueBtn.onclick = () => this.showValueModal();
        }
    }

    showAssetModal(assetId = null) {
        const modal = document.getElementById('asset-modal');
        const form = document.getElementById('asset-form');
        const title = document.getElementById('asset-modal-title');

        form.reset();
        clearDateValue('asset-purchase-date');

        if (assetId) {
            const asset = this.assets.find(a => a.id === assetId);
            if (!asset) return;

            title.textContent = t('budget', 'Edit Asset');
            document.getElementById('asset-id').value = asset.id;
            document.getElementById('asset-name').value = asset.name;
            document.getElementById('asset-type').value = asset.type;
            document.getElementById('asset-description').value = asset.description || '';
            document.getElementById('asset-currency').value = asset.currency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);
            document.getElementById('asset-current-value').value = asset.currentValue || '';
            document.getElementById('asset-purchase-price').value = asset.purchasePrice || '';
            setDateValue('asset-purchase-date', asset.purchaseDate || '');
            document.getElementById('asset-annual-change-rate').value = asset.annualChangeRate !== null ? (asset.annualChangeRate * 100) : '';
        } else {
            title.textContent = t('budget', 'Add Asset');
            document.getElementById('asset-id').value = '';
            document.getElementById('asset-currency').value = formatters.getPrimaryCurrency(this.app.accounts, this.settings);
        }

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    closeAssetModal() {
        const modal = document.getElementById('asset-modal');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    async saveAsset() {
        const form = document.getElementById('asset-form');
        const formData = new FormData(form);
        const assetId = formData.get('id');

        const annualRatePercent = formData.get('annualChangeRate');
        const annualChangeRate = annualRatePercent ? parseFloat(annualRatePercent) / 100 : null;

        const data = {
            name: formData.get('name'),
            type: formData.get('type'),
            description: formData.get('description') || null,
            currency: formData.get('currency') || formatters.getPrimaryCurrency(this.app.accounts, this.settings),
            currentValue: formData.get('currentValue') ? parseFloat(formData.get('currentValue')) : null,
            purchasePrice: formData.get('purchasePrice') ? parseFloat(formData.get('purchasePrice')) : null,
            purchaseDate: formData.get('purchaseDate') || null,
            annualChangeRate: annualChangeRate
        };

        try {
            const url = assetId
                ? OC.generateUrl(`/apps/budget/api/assets/${assetId}`)
                : OC.generateUrl('/apps/budget/api/assets');

            const response = await fetch(url, {
                method: assetId ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(data)
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || t('budget', 'Failed to save asset'));
            }

            this.closeAssetModal();
            await this.loadAssets();
            this.renderAssets();
            showSuccess(assetId ? t('budget', 'Asset updated') : t('budget', 'Asset added'));
        } catch (error) {
            showError(error.message);
        }
    }

    async deleteAsset(assetId) {
        if (!confirm(t('budget', 'Are you sure you want to delete this asset? This action cannot be undone.'))) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/assets/${assetId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || t('budget', 'Failed to delete asset'));
            }

            await this.loadAssets();
            this.renderAssets();
            this.closeAssetDetails();
            showSuccess(t('budget', 'Asset deleted'));
        } catch (error) {
            showError(error.message);
        }
    }

    async showAssetDetails(assetId) {
        const asset = this.assets.find(a => a.id === assetId);
        if (!asset) return;

        this.currentAsset = asset;
        const currency = asset.currency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);
        const typeInfo = this.getAssetTypeInfo(asset.type);

        // Hide assets list, show detail view
        document.getElementById('assets-view').style.display = 'none';
        document.getElementById('asset-details-view').style.display = 'block';

        // Breadcrumb title
        const nameEl = document.getElementById('asset-detail-name');
        if (nameEl) nameEl.textContent = asset.name;

        // Overview card
        const iconEl = document.getElementById('asset-detail-icon');
        if (iconEl) {
            iconEl.style.background = `${typeInfo.color}15`;
            iconEl.style.color = typeInfo.color;
            iconEl.innerHTML = typeInfo.icon;
        }

        const displayName = document.getElementById('asset-detail-display-name');
        if (displayName) displayName.textContent = asset.name;

        const typeLabel = document.getElementById('asset-detail-type-label');
        if (typeLabel) typeLabel.textContent = typeInfo.label;

        const descEl = document.getElementById('asset-detail-description');
        if (descEl) {
            descEl.textContent = asset.description || '';
            descEl.style.display = asset.description ? 'inline' : 'none';
        }

        // Balance section
        const detailValue = document.getElementById('asset-detail-value');
        if (detailValue) {
            const val = asset.currentValue !== null
                ? formatters.formatCurrency(asset.currentValue, currency, this.settings)
                : '--';
            detailValue.textContent = val;
            detailValue.className = 'balance-amount' + (asset.currentValue >= 0 ? ' positive' : ' negative');
        }

        const detailPurchasePrice = document.getElementById('asset-detail-purchase-price');
        if (detailPurchasePrice) {
            detailPurchasePrice.textContent = asset.purchasePrice !== null
                ? formatters.formatCurrency(asset.purchasePrice, currency, this.settings)
                : '--';
        }

        // Gain/Loss
        const gainLossEl = document.getElementById('asset-detail-gain-loss');
        if (gainLossEl) {
            if (asset.currentValue !== null && asset.purchasePrice !== null && asset.purchasePrice > 0) {
                const gain = asset.currentValue - asset.purchasePrice;
                const pct = ((gain / asset.purchasePrice) * 100).toFixed(1);
                const sign = gain >= 0 ? '+' : '';
                gainLossEl.textContent = `${sign}${formatters.formatCurrency(gain, currency, this.settings)} (${sign}${pct}%)`;
                gainLossEl.className = `balance-amount ${gain >= 0 ? 'positive' : 'negative'}`;
            } else {
                gainLossEl.textContent = '--';
                gainLossEl.className = 'balance-amount';
            }
        }

        // Metrics
        const detailRate = document.getElementById('asset-detail-rate');
        if (detailRate) {
            if (asset.annualChangeRate !== null && asset.annualChangeRate !== 0) {
                const ratePercent = (asset.annualChangeRate * 100).toFixed(1);
                const sign = asset.annualChangeRate > 0 ? '+' : '';
                detailRate.textContent = `${sign}${ratePercent}%/year`;
            } else {
                detailRate.textContent = '--';
            }
        }

        const detailPurchaseDate = document.getElementById('asset-detail-purchase-date');
        if (detailPurchaseDate) {
            detailPurchaseDate.textContent = asset.purchaseDate
                ? formatters.formatDate(asset.purchaseDate, this.settings)
                : '--';
        }

        // Load charts and update projection/snapshot metrics
        const [snapshots] = await Promise.all([
            this.loadAssetValueChart(assetId),
            this.loadAssetProjectionChart(assetId)
        ]);

        // Update snapshot count
        const snapshotCountEl = document.getElementById('asset-detail-snapshots');
        if (snapshotCountEl && snapshots) {
            snapshotCountEl.textContent = snapshots.length;
        }
    }

    closeAssetDetails() {
        document.getElementById('asset-details-view').style.display = 'none';
        document.getElementById('assets-view').style.display = 'block';
        this.currentAsset = null;
    }

    async loadAssetValueChart(assetId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/assets/${assetId}/snapshots`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) return [];

            const snapshots = await response.json();
            const canvas = document.getElementById('asset-value-chart');
            if (!canvas) return snapshots || [];

            const ctx = canvas.getContext('2d');

            // Destroy existing chart
            if (this.charts.assetValue) {
                this.charts.assetValue.destroy();
            }

            if (!snapshots || snapshots.length === 0) {
                canvas.style.display = 'none';
                return snapshots || [];
            }
            canvas.style.display = '';

            // Snapshots come DESC from API, reverse for chart
            const sortedSnapshots = [...snapshots].reverse();
            const currency = this.currentAsset?.currency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);

            this.charts.assetValue = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: sortedSnapshots.map(s => s.date),
                    datasets: [{
                        label: t('budget', 'Value'),
                        data: sortedSnapshots.map(s => s.value),
                        borderColor: '#0082c9',
                        backgroundColor: 'rgba(0, 130, 201, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            ticks: {
                                callback: (value) => formatters.formatCurrencyCompact(value, currency, this.settings)
                            }
                        }
                    }
                }
            });
            return snapshots;
        } catch (error) {
            console.error('Failed to load asset value chart:', error);
            return [];
        }
    }

    async loadAssetProjectionChart(assetId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/assets/${assetId}/projection`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) return;

            const data = await response.json();
            const canvas = document.getElementById('asset-projection-chart');
            if (!canvas) return;

            const ctx = canvas.getContext('2d');

            // Destroy existing chart
            if (this.charts.assetProjection) {
                this.charts.assetProjection.destroy();
            }

            if (!data.growthProjection || data.growthProjection.length === 0) {
                canvas.style.display = 'none';
                return;
            }
            canvas.style.display = '';

            const isAppreciating = (data.annualChangeRate || 0) >= 0;
            const lineColor = isAppreciating ? '#46ba61' : '#e9322d';
            const bgColor = isAppreciating ? 'rgba(70, 186, 97, 0.1)' : 'rgba(233, 50, 45, 0.1)';
            const currency = this.currentAsset?.currency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);

            // Update 10yr projected value metric
            const lastProjection = data.growthProjection[data.growthProjection.length - 1];
            const projectedEl = document.getElementById('asset-detail-projected');
            if (projectedEl && lastProjection) {
                projectedEl.textContent = formatters.formatCurrency(lastProjection.value, currency, this.settings);
            }

            this.charts.assetProjection = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.growthProjection.map(p => p.year.toString()),
                    datasets: [{
                        label: isAppreciating ? t('budget', 'Projected Appreciation') : t('budget', 'Projected Depreciation'),
                        data: data.growthProjection.map(p => p.value),
                        borderColor: lineColor,
                        backgroundColor: bgColor,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            ticks: {
                                callback: (value) => formatters.formatCurrencyCompact(value, currency, this.settings)
                            }
                        }
                    }
                }
            });
        } catch (error) {
            console.error('Failed to load asset projection chart:', error);
        }
    }

    showValueModal() {
        if (!this.currentAsset) return;

        const modal = document.getElementById('asset-value-modal');
        document.getElementById('asset-value-form').reset();
        document.getElementById('asset-value-asset-id').value = this.currentAsset.id;
        setDateValue('asset-value-date', formatters.getTodayDateString());

        if (this.currentAsset.currentValue) {
            document.getElementById('asset-value-amount').value = this.currentAsset.currentValue;
        }

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    closeValueModal() {
        const modal = document.getElementById('asset-value-modal');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    async saveValueUpdate() {
        const form = document.getElementById('asset-value-form');
        const formData = new FormData(form);
        const assetId = formData.get('assetId');

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/assets/${assetId}/snapshots`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    value: parseFloat(formData.get('value')),
                    date: formData.get('date')
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || t('budget', 'Failed to update value'));
            }

            this.closeValueModal();
            await this.loadAssets();
            this.renderAssets();
            await this.showAssetDetails(parseInt(assetId));
            showSuccess(t('budget', 'Value updated'));
        } catch (error) {
            showError(error.message);
        }
    }

    async loadDashboardAssetSummary() {
        try {
            const summary = await this.loadAssetSummary();
            this.updateAssetsSummary(summary);
        } catch (error) {
            console.error('Failed to load asset summary for dashboard:', error);
        }
    }
}
