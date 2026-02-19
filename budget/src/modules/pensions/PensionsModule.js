/**
 * Pensions Module - Pension tracking and projection
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError } from '../../utils/notifications.js';
import Chart from 'chart.js/auto';

export default class PensionsModule {
    constructor(app) {
        this.app = app;
    }

    // Getters for app state
    get pensions() { return this.app.pensions; }
    set pensions(value) { this.app.pensions = value; }
    get currentPension() { return this.app.currentPension; }
    set currentPension(value) { this.app.currentPension = value; }
    get settings() { return this.app.settings; }
    get charts() { return this.app.charts; }

    async loadPensionsView() {
        try {
            await this.loadPensions();
            this.renderPensions();
            this.setupPensionEventListeners();
        } catch (error) {
            console.error('Failed to load pensions view:', error);
            showError('Failed to load pensions');
        }
    }

    async loadPensions() {
        const response = await fetch(OC.generateUrl('/apps/budget/api/pensions'), {
            headers: { 'requesttoken': OC.requestToken }
        });
        if (!response.ok) throw new Error('Failed to fetch pensions');
        this.pensions = await response.json();
    }

    async loadPensionSummary() {
        const response = await fetch(OC.generateUrl('/apps/budget/api/pensions/summary'), {
            headers: { 'requesttoken': OC.requestToken }
        });
        if (!response.ok) throw new Error('Failed to fetch pension summary');
        return await response.json();
    }

    async loadPensionProjection() {
        const response = await fetch(OC.generateUrl('/apps/budget/api/pensions/projection'), {
            headers: { 'requesttoken': OC.requestToken }
        });
        if (!response.ok) throw new Error('Failed to fetch pension projection');
        return await response.json();
    }

    renderPensions() {
        const list = document.getElementById('pensions-list');
        const emptyState = document.getElementById('empty-pensions');

        if (!this.pensions || this.pensions.length === 0) {
            list.innerHTML = '';
            emptyState.style.display = 'block';
            this.updatePensionsSummary({ totalPensionWorth: 0, pensionCount: 0 });
            return;
        }

        emptyState.style.display = 'none';
        list.innerHTML = this.pensions.map(pension => this.renderPensionCard(pension)).join('');

        // Load and update summary
        this.loadPensionSummary().then(summary => {
            this.updatePensionsSummary(summary);
        });

        // Load and update projections
        this.loadPensionProjection().then(projection => {
            this.updatePensionsProjection(projection);
        });
    }

    renderPensionCard(pension) {
        const currency = pension.currency || 'GBP';
        const typeLabels = {
            workplace: 'Workplace',
            personal: 'Personal',
            sipp: 'SIPP',
            defined_benefit: 'Defined Benefit',
            state: 'State Pension'
        };
        const typeLabel = typeLabels[pension.type] || pension.type;

        let valueDisplay = '--';
        if (pension.isDefinedContribution && pension.currentBalance !== null) {
            valueDisplay = formatters.formatCurrency(pension.currentBalance, currency, this.settings);
        } else if (pension.annualIncome !== null) {
            valueDisplay = formatters.formatCurrency(pension.annualIncome, currency, this.settings) + '/year';
        }

        return `
            <div class="pension-card" data-id="${pension.id}">
                <div class="pension-card-header">
                    <h4 class="pension-name">${dom.escapeHtml(pension.name)}</h4>
                    <span class="pension-type-badge pension-type-${pension.type}">${typeLabel}</span>
                </div>
                <div class="pension-card-body">
                    <div class="pension-value">${valueDisplay}</div>
                    ${pension.provider ? `<div class="pension-provider">${dom.escapeHtml(pension.provider)}</div>` : ''}
                    ${pension.monthlyContribution ? `<div class="pension-contribution">${formatters.formatCurrency(pension.monthlyContribution, currency, this.settings)}/month</div>` : ''}
                </div>
                <div class="pension-card-actions">
                    <button class="pension-view-btn icon-button" title="View details" data-id="${pension.id}">
                        <span class="icon-info" aria-hidden="true"></span>
                    </button>
                    <button class="pension-edit-btn icon-button" title="Edit" data-id="${pension.id}">
                        <span class="icon-rename" aria-hidden="true"></span>
                    </button>
                    <button class="pension-delete-btn icon-button" title="Delete" data-id="${pension.id}">
                        <span class="icon-delete" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
        `;
    }

    updatePensionsSummary(summary) {
        const currency = formatters.getPrimaryCurrency(this.app.accounts, this.settings);
        const pensionWorth = summary.totalPensionWorth || 0;
        const projectedIncome = summary.totalProjectedIncome || 0;
        const count = summary.pensionCount || 0;

        const worthEl = document.getElementById('pensions-total-worth');
        const countEl = document.getElementById('pensions-count');

        if (worthEl) {
            worthEl.textContent = formatters.formatCurrency(pensionWorth, currency, this.settings);
        }
        if (countEl) {
            countEl.textContent = count;
        }

        // Update dashboard hero card
        const heroPensionValue = document.getElementById('hero-pension-value');
        const heroPensionCount = document.getElementById('hero-pension-count');
        const heroPensionLabel = document.querySelector('.hero-pension .hero-label');

        if (heroPensionValue) {
            // Show pension worth if available, otherwise show projected income
            if (pensionWorth > 0) {
                heroPensionValue.textContent = formatters.formatCurrency(pensionWorth, currency, this.settings);
                if (heroPensionLabel) heroPensionLabel.textContent = 'Pension Worth';
            } else if (projectedIncome > 0) {
                heroPensionValue.textContent = formatters.formatCurrency(projectedIncome, currency, this.settings) + '/yr';
                if (heroPensionLabel) heroPensionLabel.textContent = 'Pension Income';
            } else {
                heroPensionValue.textContent = formatters.formatCurrency(0, currency, this.settings);
                if (heroPensionLabel) heroPensionLabel.textContent = 'Pension Worth';
            }
        }
        if (heroPensionCount) {
            let subtext = count === 1 ? '1 pension' : `${count} pensions`;
            // If showing income but also have some pot value, mention it
            if (pensionWorth > 0 && projectedIncome > 0) {
                subtext += ` · ${formatters.formatCurrency(projectedIncome, currency, this.settings)}/yr income`;
            }
            heroPensionCount.textContent = subtext;
        }
    }

    updatePensionsProjection(projection) {
        const currency = formatters.getPrimaryCurrency(this.app.accounts, this.settings);

        const projectedValueEl = document.getElementById('pensions-projected-value');
        const projectedIncomeEl = document.getElementById('pensions-projected-income');

        if (projectedValueEl) {
            projectedValueEl.textContent = formatters.formatCurrency(projection.totalProjectedValue || 0, currency, this.settings);
        }
        if (projectedIncomeEl) {
            projectedIncomeEl.textContent = formatters.formatCurrency(projection.totalProjectedAnnualIncome || 0, currency, this.settings);
        }
    }

    setupPensionEventListeners() {
        // Add pension button
        const addBtn = document.getElementById('add-pension-btn');
        const emptyAddBtn = document.getElementById('empty-pensions-add-btn');

        if (addBtn) {
            addBtn.onclick = () => this.showPensionModal();
        }
        if (emptyAddBtn) {
            emptyAddBtn.onclick = () => this.showPensionModal();
        }

        // Pension form
        const pensionForm = document.getElementById('pension-form');
        if (pensionForm) {
            pensionForm.onsubmit = (e) => {
                e.preventDefault();
                this.savePension();
            };
        }

        // Pension type change (toggle DC/DB fields)
        const pensionType = document.getElementById('pension-type');
        if (pensionType) {
            pensionType.onchange = () => this.togglePensionFields();
        }

        // Modal close buttons
        document.querySelectorAll('#pension-modal .cancel-btn').forEach(btn => {
            btn.onclick = () => this.closePensionModal();
        });

        // Balance form
        const balanceForm = document.getElementById('pension-balance-form');
        if (balanceForm) {
            balanceForm.onsubmit = (e) => {
                e.preventDefault();
                this.saveSnapshot();
            };
        }
        document.querySelectorAll('#pension-balance-modal .cancel-btn').forEach(btn => {
            btn.onclick = () => this.closeBalanceModal();
        });

        // Contribution form
        const contributionForm = document.getElementById('pension-contribution-form');
        if (contributionForm) {
            contributionForm.onsubmit = (e) => {
                e.preventDefault();
                this.saveContribution();
            };
        }
        document.querySelectorAll('#pension-contribution-modal .cancel-btn').forEach(btn => {
            btn.onclick = () => this.closeContributionModal();
        });

        // Pension card actions (delegated)
        const pensionsList = document.getElementById('pensions-list');
        if (pensionsList) {
            pensionsList.onclick = (e) => {
                const viewBtn = e.target.closest('.pension-view-btn');
                const editBtn = e.target.closest('.pension-edit-btn');
                const deleteBtn = e.target.closest('.pension-delete-btn');
                const card = e.target.closest('.pension-card');

                if (viewBtn) {
                    this.showPensionDetails(parseInt(viewBtn.dataset.id));
                } else if (editBtn) {
                    this.showPensionModal(parseInt(editBtn.dataset.id));
                } else if (deleteBtn) {
                    this.deletePension(parseInt(deleteBtn.dataset.id));
                } else if (card) {
                    this.showPensionDetails(parseInt(card.dataset.id));
                }
            };
        }

        // Detail panel buttons
        const closeDetailBtn = document.getElementById('pension-close-btn');
        if (closeDetailBtn) {
            closeDetailBtn.onclick = () => this.closePensionDetails();
        }

        const editDetailBtn = document.getElementById('pension-edit-btn');
        if (editDetailBtn) {
            editDetailBtn.onclick = () => {
                if (this.currentPension) {
                    this.showPensionModal(this.currentPension.id);
                }
            };
        }

        const updateBalanceBtn = document.getElementById('update-balance-btn');
        if (updateBalanceBtn) {
            updateBalanceBtn.onclick = () => this.showBalanceModal();
        }

        const addContributionBtn = document.getElementById('add-contribution-btn');
        if (addContributionBtn) {
            addContributionBtn.onclick = () => this.showContributionModal();
        }
    }

    togglePensionFields() {
        const type = document.getElementById('pension-type').value;
        const dcFields = document.getElementById('dc-pension-fields');
        const dbFields = document.getElementById('db-pension-fields');

        if (type === 'defined_benefit' || type === 'state') {
            dcFields.style.display = 'none';
            dbFields.style.display = 'block';
        } else {
            dcFields.style.display = 'block';
            dbFields.style.display = 'none';
        }
    }

    showPensionModal(pensionId = null) {
        const modal = document.getElementById('pension-modal');
        const form = document.getElementById('pension-form');
        const title = document.getElementById('pension-modal-title');

        form.reset();

        if (pensionId) {
            const pension = this.pensions.find(p => p.id === pensionId);
            if (!pension) return;

            title.textContent = 'Edit Pension';
            document.getElementById('pension-id').value = pension.id;
            document.getElementById('pension-name').value = pension.name;
            document.getElementById('pension-type').value = pension.type;
            document.getElementById('pension-provider').value = pension.provider || '';
            document.getElementById('pension-currency').value = pension.currency || 'GBP';

            if (pension.isDefinedContribution) {
                document.getElementById('pension-balance').value = pension.currentBalance || '';
                document.getElementById('pension-monthly').value = pension.monthlyContribution || '';
            } else {
                document.getElementById('pension-income').value = pension.annualIncome || '';
            }

            this.togglePensionFields();
        } else {
            title.textContent = 'Add Pension';
            document.getElementById('pension-id').value = '';
            this.togglePensionFields();
        }

        modal.style.display = 'flex';
    }

    closePensionModal() {
        document.getElementById('pension-modal').style.display = 'none';
    }

    async savePension() {
        const form = document.getElementById('pension-form');
        const formData = new FormData(form);
        const pensionId = formData.get('id');

        const type = formData.get('type');
        const isDefinedContribution = type !== 'defined_benefit' && type !== 'state';

        const data = {
            name: formData.get('name'),
            type: type,
            provider: formData.get('provider') || null,
            accountNumber: formData.get('accountNumber') || null,
            currency: formData.get('currency') || 'GBP',
            isDefinedContribution: isDefinedContribution
        };

        if (isDefinedContribution) {
            data.currentBalance = formData.get('currentBalance') ? parseFloat(formData.get('currentBalance')) : null;
            data.monthlyContribution = formData.get('monthlyContribution') ? parseFloat(formData.get('monthlyContribution')) : null;
        } else {
            data.annualIncome = formData.get('annualIncome') ? parseFloat(formData.get('annualIncome')) : null;
            data.startDate = formData.get('startDate') || null;
        }

        try {
            const url = pensionId
                ? OC.generateUrl(`/apps/budget/api/pensions/${pensionId}`)
                : OC.generateUrl('/apps/budget/api/pensions');

            const response = await fetch(url, {
                method: pensionId ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(data)
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to save pension');
            }

            this.closePensionModal();
            await this.loadPensions();
            this.renderPensions();
            showSuccess(pensionId ? 'Pension updated' : 'Pension added');
        } catch (error) {
            showError(error.message);
        }
    }

    async deletePension(pensionId) {
        if (!confirm('Are you sure you want to delete this pension? This action cannot be undone.')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to delete pension');
            }

            await this.loadPensions();
            this.renderPensions();
            this.closePensionDetails();
            showSuccess('Pension deleted');
        } catch (error) {
            showError(error.message);
        }
    }

    async showPensionDetails(pensionId) {
        const pension = this.pensions.find(p => p.id === pensionId);
        if (!pension) return;

        this.currentPension = pension;

        const panel = document.getElementById('pension-detail-panel');
        const nameEl = document.getElementById('pension-detail-name');
        const typeEl = document.getElementById('pension-detail-type');
        const providerEl = document.getElementById('pension-detail-provider');
        const valueEl = document.getElementById('pension-detail-value');

        nameEl.textContent = pension.name;
        typeEl.textContent = {
            workplace: 'Workplace',
            personal: 'Personal',
            sipp: 'SIPP',
            defined_benefit: 'Defined Benefit',
            state: 'State Pension'
        }[pension.type] || pension.type;

        if (pension.provider) {
            providerEl.textContent = pension.provider;
            providerEl.style.display = 'block';
        } else {
            providerEl.style.display = 'none';
        }

        const currency = pension.currency || 'GBP';
        if (pension.isDefinedContribution && pension.currentBalance !== null) {
            valueEl.textContent = formatters.formatCurrency(pension.currentBalance, currency, this.settings);
        } else if (pension.annualIncome !== null) {
            valueEl.textContent = formatters.formatCurrency(pension.annualIncome, currency, this.settings) + '/year';
        } else {
            valueEl.textContent = '--';
        }

        panel.classList.add('active');

        // Load charts and activity
        await this.loadPensionBalanceChart(pensionId);
        await this.loadPensionProjectionChart(pensionId);
        await this.loadPensionActivity(pensionId);
    }

    closePensionDetails() {
        document.getElementById('pension-detail-panel').classList.remove('active');
        this.currentPension = null;
    }

    async loadPensionBalanceChart(pensionId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/balance-history`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) return;

            const data = await response.json();
            const canvas = document.getElementById('pension-balance-chart');
            const ctx = canvas.getContext('2d');

            // Destroy existing chart
            if (this.charts.pensionBalance) {
                this.charts.pensionBalance.destroy();
            }

            this.charts.pensionBalance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Balance',
                        data: data.values,
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
                            beginAtZero: true,
                            ticks: {
                                callback: (value) => formatters.formatCurrencyCompact(value, data.currency, this.settings)
                            }
                        }
                    }
                }
            });
        } catch (error) {
            console.error('Failed to load pension balance chart:', error);
        }
    }

    async loadPensionProjectionChart(pensionId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/projection`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) return;

            const data = await response.json();
            const canvas = document.getElementById('pension-projection-chart');
            const ctx = canvas.getContext('2d');

            // Destroy existing chart
            if (this.charts.pensionProjection) {
                this.charts.pensionProjection.destroy();
            }

            this.charts.pensionProjection = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Projected Value',
                        data: data.values,
                        borderColor: '#46ba61',
                        backgroundColor: 'rgba(70, 186, 97, 0.1)',
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
                            beginAtZero: true,
                            ticks: {
                                callback: (value) => formatters.formatCurrencyCompact(value, data.currency, this.settings)
                            }
                        }
                    }
                }
            });
        } catch (error) {
            console.error('Failed to load pension projection chart:', error);
        }
    }

    async loadPensionActivity(pensionId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/activity`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) return;

            const data = await response.json();
            const container = document.getElementById('pension-activity-list');

            if (!data || data.length === 0) {
                container.innerHTML = '<div class="empty-state-small">No activity yet</div>';
                return;
            }

            const currency = this.currentPension.currency || 'GBP';

            container.innerHTML = data.map(activity => {
                const typeLabels = {
                    snapshot: 'Balance Update',
                    contribution: 'Contribution'
                };
                const typeLabel = typeLabels[activity.type] || activity.type;
                const icon = activity.type === 'contribution' ? 'icon-add' : 'icon-checkmark';

                return `
                    <div class="pension-activity-item">
                        <div class="activity-icon ${icon}"></div>
                        <div class="activity-details">
                            <div class="activity-type">${typeLabel}</div>
                            <div class="activity-date">${formatters.formatDate(activity.date, this.settings)}</div>
                            ${activity.note ? `<div class="activity-note">${dom.escapeHtml(activity.note)}</div>` : ''}
                        </div>
                        <div class="activity-amount">${formatters.formatCurrency(activity.amount, currency, this.settings)}</div>
                    </div>
                `;
            }).join('');
        } catch (error) {
            console.error('Failed to load pension activity:', error);
        }
    }

    showBalanceModal() {
        if (!this.currentPension) return;

        const modal = document.getElementById('pension-balance-modal');
        document.getElementById('pension-balance-form').reset();
        document.getElementById('snapshot-pension-id').value = this.currentPension.id;
        document.getElementById('snapshot-date').value = new Date().toISOString().split('T')[0];

        if (this.currentPension.currentBalance) {
            document.getElementById('snapshot-balance').value = this.currentPension.currentBalance;
        }

        modal.style.display = 'flex';
    }

    closeBalanceModal() {
        document.getElementById('pension-balance-modal').style.display = 'none';
    }

    async saveSnapshot() {
        const form = document.getElementById('pension-balance-form');
        const formData = new FormData(form);
        const pensionId = formData.get('pensionId');

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/snapshots`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    balance: parseFloat(formData.get('balance')),
                    date: formData.get('date')
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to update balance');
            }

            this.closeBalanceModal();
            await this.loadPensions();
            this.renderPensions();
            await this.showPensionDetails(parseInt(pensionId));
            showSuccess('Balance updated');
        } catch (error) {
            showError(error.message);
        }
    }

    showContributionModal() {
        if (!this.currentPension) return;

        const modal = document.getElementById('pension-contribution-modal');
        document.getElementById('pension-contribution-form').reset();
        document.getElementById('contribution-pension-id').value = this.currentPension.id;
        document.getElementById('contribution-date').value = new Date().toISOString().split('T')[0];

        modal.style.display = 'flex';
    }

    closeContributionModal() {
        document.getElementById('pension-contribution-modal').style.display = 'none';
    }

    async saveContribution() {
        const form = document.getElementById('pension-contribution-form');
        const formData = new FormData(form);
        const pensionId = formData.get('pensionId');

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/contributions`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    amount: parseFloat(formData.get('amount')),
                    date: formData.get('date'),
                    note: formData.get('note') || null
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to log contribution');
            }

            this.closeContributionModal();
            await this.showPensionDetails(parseInt(pensionId));
            showSuccess('Contribution logged');
        } catch (error) {
            showError(error.message);
        }
    }

    async loadDashboardPensionSummary() {
        try {
            const summary = await this.loadPensionSummary();
            this.updatePensionsSummary(summary);
        } catch (error) {
            console.error('Failed to load pension summary for dashboard:', error);
        }
    }
}
