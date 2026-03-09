/**
 * Assets Module - Non-cash asset tracking with value history and projections
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError } from '../../utils/notifications.js';
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

    static get TYPE_LABELS() {
        return {
            real_estate: 'Real Estate',
            vehicle: 'Vehicle',
            jewelry: 'Jewelry',
            collectibles: 'Collectibles',
            other: 'Other'
        };
    }

    async loadAssetsView() {
        try {
            await this.loadAssets();
            this.renderAssets();
            this.setupAssetEventListeners();
        } catch (error) {
            console.error('Failed to load assets view:', error);
            showError('Failed to load assets');
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

    renderAssetCard(asset) {
        const currency = asset.currency || 'USD';
        const typeLabel = AssetsModule.TYPE_LABELS[asset.type] || asset.type;

        let valueDisplay = '--';
        if (asset.currentValue !== null) {
            valueDisplay = formatters.formatCurrency(asset.currentValue, currency, this.settings);
        }

        let rateDisplay = '';
        if (asset.annualChangeRate !== null && asset.annualChangeRate !== 0) {
            const ratePercent = (asset.annualChangeRate * 100).toFixed(1);
            const rateClass = asset.annualChangeRate > 0 ? 'positive' : 'negative';
            const rateSign = asset.annualChangeRate > 0 ? '+' : '';
            rateDisplay = `<span class="asset-rate ${rateClass}">${rateSign}${ratePercent}%/yr</span>`;
        }

        return `
            <div class="asset-card" data-id="${asset.id}">
                <div class="asset-card-header">
                    <h4 class="asset-name">${dom.escapeHtml(asset.name)}</h4>
                    <span class="asset-type-badge asset-type-${asset.type}">${typeLabel}</span>
                </div>
                <div class="asset-card-body">
                    <div class="asset-value">${valueDisplay}</div>
                    ${rateDisplay}
                    ${asset.description ? `<div class="asset-description">${dom.escapeHtml(asset.description)}</div>` : ''}
                </div>
                <div class="asset-card-actions">
                    <button class="asset-view-btn icon-button" title="View details" data-id="${asset.id}">
                        <span class="icon-info" aria-hidden="true"></span>
                    </button>
                    <button class="asset-edit-btn icon-button" title="Edit" data-id="${asset.id}">
                        <span class="icon-rename" aria-hidden="true"></span>
                    </button>
                    <button class="asset-delete-btn icon-button" title="Delete" data-id="${asset.id}">
                        <span class="icon-delete" aria-hidden="true"></span>
                    </button>
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
            heroAssetsCount.textContent = count === 1 ? '1 asset' : `${count} assets`;
        }

        // Show warning for unconvertible currencies
        const warningEl = document.getElementById('assets-conversion-warning');
        if (warningEl) {
            if (summary.unconvertedCurrencies && summary.unconvertedCurrencies.length > 0) {
                const currencies = summary.unconvertedCurrencies.join(', ');
                warningEl.textContent = `Some assets (${currencies}) are excluded from the total because exchange rates are unavailable. Add rates in Settings to include them.`;
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

        // Detail panel buttons
        const closeDetailBtn = document.getElementById('asset-close-btn');
        if (closeDetailBtn) {
            closeDetailBtn.onclick = () => this.closeAssetDetails();
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

        if (assetId) {
            const asset = this.assets.find(a => a.id === assetId);
            if (!asset) return;

            title.textContent = 'Edit Asset';
            document.getElementById('asset-id').value = asset.id;
            document.getElementById('asset-name').value = asset.name;
            document.getElementById('asset-type').value = asset.type;
            document.getElementById('asset-description').value = asset.description || '';
            document.getElementById('asset-currency').value = asset.currency || 'USD';
            document.getElementById('asset-current-value').value = asset.currentValue || '';
            document.getElementById('asset-purchase-price').value = asset.purchasePrice || '';
            document.getElementById('asset-purchase-date').value = asset.purchaseDate || '';
            document.getElementById('asset-annual-rate').value = asset.annualChangeRate !== null ? (asset.annualChangeRate * 100) : '';
        } else {
            title.textContent = 'Add Asset';
            document.getElementById('asset-id').value = '';
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
            currency: formData.get('currency') || 'USD',
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
                throw new Error(error.error || 'Failed to save asset');
            }

            this.closeAssetModal();
            await this.loadAssets();
            this.renderAssets();
            showSuccess(assetId ? 'Asset updated' : 'Asset added');
        } catch (error) {
            showError(error.message);
        }
    }

    async deleteAsset(assetId) {
        if (!confirm('Are you sure you want to delete this asset? This action cannot be undone.')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/assets/${assetId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to delete asset');
            }

            await this.loadAssets();
            this.renderAssets();
            this.closeAssetDetails();
            showSuccess('Asset deleted');
        } catch (error) {
            showError(error.message);
        }
    }

    async showAssetDetails(assetId) {
        const asset = this.assets.find(a => a.id === assetId);
        if (!asset) return;

        this.currentAsset = asset;

        const panel = document.getElementById('asset-detail-panel');
        const nameEl = document.getElementById('asset-detail-name');
        const currency = asset.currency || 'USD';

        nameEl.textContent = asset.name;

        // Update detail fields
        const detailValue = document.getElementById('asset-detail-value');
        const detailType = document.getElementById('asset-detail-type');
        const detailPurchasePrice = document.getElementById('asset-detail-purchase-price');
        const detailPurchaseDate = document.getElementById('asset-detail-purchase-date');
        const detailRate = document.getElementById('asset-detail-rate');

        if (detailValue) {
            detailValue.textContent = asset.currentValue !== null
                ? formatters.formatCurrency(asset.currentValue, currency, this.settings)
                : '--';
        }
        if (detailType) {
            detailType.textContent = AssetsModule.TYPE_LABELS[asset.type] || asset.type;
        }
        if (detailPurchasePrice) {
            detailPurchasePrice.textContent = asset.purchasePrice !== null
                ? formatters.formatCurrency(asset.purchasePrice, currency, this.settings)
                : '--';
        }
        if (detailPurchaseDate) {
            detailPurchaseDate.textContent = asset.purchaseDate
                ? formatters.formatDate(asset.purchaseDate, this.settings)
                : '--';
        }
        if (detailRate) {
            if (asset.annualChangeRate !== null && asset.annualChangeRate !== 0) {
                const ratePercent = (asset.annualChangeRate * 100).toFixed(1);
                const sign = asset.annualChangeRate > 0 ? '+' : '';
                detailRate.textContent = `${sign}${ratePercent}%/year`;
            } else {
                detailRate.textContent = '--';
            }
        }

        panel.classList.add('active');

        // Load charts
        await this.loadAssetValueChart(assetId);
        await this.loadAssetProjectionChart(assetId);
    }

    closeAssetDetails() {
        const panel = document.getElementById('asset-detail-panel');
        if (panel) {
            panel.classList.remove('active');
        }
        this.currentAsset = null;
    }

    async loadAssetValueChart(assetId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/assets/${assetId}/snapshots`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) return;

            const snapshots = await response.json();
            const canvas = document.getElementById('asset-value-chart');
            if (!canvas) return;

            const ctx = canvas.getContext('2d');

            // Destroy existing chart
            if (this.charts.assetValue) {
                this.charts.assetValue.destroy();
            }

            if (!snapshots || snapshots.length === 0) {
                canvas.style.display = 'none';
                return;
            }
            canvas.style.display = '';

            // Snapshots come DESC from API, reverse for chart
            const sortedSnapshots = [...snapshots].reverse();
            const currency = this.currentAsset?.currency || 'USD';

            this.charts.assetValue = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: sortedSnapshots.map(s => s.date),
                    datasets: [{
                        label: 'Value',
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
        } catch (error) {
            console.error('Failed to load asset value chart:', error);
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
            const currency = this.currentAsset?.currency || 'USD';

            this.charts.assetProjection = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.growthProjection.map(p => p.year.toString()),
                    datasets: [{
                        label: isAppreciating ? 'Projected Appreciation' : 'Projected Depreciation',
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
        document.getElementById('asset-value-date').value = new Date().toISOString().split('T')[0];

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
                throw new Error(error.error || 'Failed to update value');
            }

            this.closeValueModal();
            await this.loadAssets();
            this.renderAssets();
            await this.showAssetDetails(parseInt(assetId));
            showSuccess('Value updated');
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
