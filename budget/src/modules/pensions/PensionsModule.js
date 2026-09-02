/**
 * Pensions Module - Pension tracking and projection
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError } from '../../utils/notifications.js';
import { setDateValue } from '../../utils/datepicker.js';
import Chart from 'chart.js/auto';
import { serverErrorMessage } from '../../utils/helpers.js';
import { openAccounts } from '../../utils/accounts.js';

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
            showError(t('budget', 'Failed to load pensions'));
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
        const currency = pension.currency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);
        const typeLabels = {
            workplace: t('budget', 'Workplace'),
            personal: t('budget', 'Personal'),
            sipp: t('budget', 'SIPP'),
            defined_benefit: t('budget', 'Defined Benefit'),
            state: t('budget', 'State Pension')
        };
        const typeLabel = typeLabels[pension.type] || pension.type;

        let valueDisplay = '--';
        if (pension.isDefinedContribution && pension.currentBalance !== null) {
            valueDisplay = formatters.formatCurrency(pension.currentBalance, currency, this.settings);
        } else if (pension.annualIncome !== null) {
            valueDisplay = t('budget', '{amount}/year', { amount: formatters.formatCurrency(pension.annualIncome, currency, this.settings) });
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
                    ${pension.monthlyContribution ? `<div class="pension-contribution">${t('budget', '{amount}/month', { amount: formatters.formatCurrency(pension.monthlyContribution, currency, this.settings) })}</div>` : ''}
                </div>
                <div class="pension-card-actions">
                    <button class="pension-view-btn icon-button" title="${t('budget', 'View details')}" data-id="${pension.id}">
                        <span class="icon-info" aria-hidden="true"></span>
                    </button>
                    <button class="pension-edit-btn icon-button" title="${t('budget', 'Edit')}" data-id="${pension.id}">
                        <span class="icon-rename" aria-hidden="true"></span>
                    </button>
                    <button class="pension-delete-btn icon-button" title="${t('budget', 'Delete')}" data-id="${pension.id}">
                        <span class="icon-delete" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
        `;
    }

    updatePensionsSummary(summary) {
        const currency = summary.baseCurrency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);
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
                if (heroPensionLabel) heroPensionLabel.textContent = t('budget', 'Pension Worth');
            } else if (projectedIncome > 0) {
                heroPensionValue.textContent = t('budget', '{amount}/yr', { amount: formatters.formatCurrency(projectedIncome, currency, this.settings) });
                if (heroPensionLabel) heroPensionLabel.textContent = t('budget', 'Pension Income');
            } else {
                heroPensionValue.textContent = formatters.formatCurrency(0, currency, this.settings);
                if (heroPensionLabel) heroPensionLabel.textContent = t('budget', 'Pension Worth');
            }
        }
        if (heroPensionCount) {
            let subtext = n('budget', '%n pension', '%n pensions', count);
            // If showing income but also have some pot value, mention it
            if (pensionWorth > 0 && projectedIncome > 0) {
                subtext += ` · ${t('budget', '{amount}/yr income', { amount: formatters.formatCurrency(projectedIncome, currency, this.settings) })}`;
            }
            heroPensionCount.textContent = subtext;
        }
    }

    updatePensionsProjection(projection) {
        const currency = projection.baseCurrency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);

        const projectedValueEl = document.getElementById('pensions-projected-value');
        const projectedIncomeEl = document.getElementById('pensions-projected-income');

        if (projectedValueEl) {
            projectedValueEl.textContent = formatters.formatCurrency(projection.totalProjectedValue || 0, currency, this.settings);
        }
        if (projectedIncomeEl) {
            projectedIncomeEl.textContent = formatters.formatCurrency(projection.totalProjectedAnnualIncome || 0, currency, this.settings);
        }

        // Show hint if DOB is not set in Nextcloud profile
        const hintEl = document.getElementById('pensions-age-hint');
        if (hintEl) {
            if (projection.currentAge === null || projection.currentAge === undefined) {
                hintEl.textContent = t('budget', 'Set your date of birth in your Nextcloud profile for accurate retirement projections.');
                hintEl.style.display = '';
            } else {
                hintEl.style.display = 'none';
            }
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

        // Detail modal: close button + backdrop click
        const detailPanel = document.getElementById('pension-detail-panel');
        const closeDetailBtn = document.getElementById('pension-close-btn');
        if (closeDetailBtn) {
            closeDetailBtn.onclick = () => this.closePensionDetails();
        }
        if (detailPanel) {
            detailPanel.onclick = (e) => {
                if (e.target === detailPanel) this.closePensionDetails();
            };
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

        // Withdrawal modal
        const recordWithdrawalBtn = document.getElementById('record-withdrawal-btn');
        if (recordWithdrawalBtn) {
            recordWithdrawalBtn.onclick = () => this.showWithdrawalModal();
        }
        const withdrawalForm = document.getElementById('pension-withdrawal-form');
        if (withdrawalForm) {
            withdrawalForm.onsubmit = (e) => { e.preventDefault(); this.saveWithdrawal(); };
        }
        document.querySelectorAll('#pension-withdrawal-modal .cancel-btn').forEach(btn => {
            btn.onclick = () => this.closeWithdrawalModal();
        });

        // Recurring contribution modal
        const addRecurringBtn = document.getElementById('add-recurring-btn');
        if (addRecurringBtn) {
            addRecurringBtn.onclick = () => this.showRecurringModal();
        }
        const recurringForm = document.getElementById('pension-recurring-form');
        if (recurringForm) {
            recurringForm.onsubmit = (e) => { e.preventDefault(); this.saveRecurring(); };
        }
        document.querySelectorAll('#pension-recurring-modal .cancel-btn').forEach(btn => {
            btn.onclick = () => this.closeRecurringModal();
        });

        // Real-terms projection toggle
        const realToggle = document.getElementById('pension-projection-realterms');
        if (realToggle) {
            realToggle.onchange = () => this.renderProjectionChart();
        }

        // Activity delete (delegated)
        const activityList = document.getElementById('pension-activity-list');
        if (activityList) {
            activityList.onclick = (e) => {
                const btn = e.target.closest('.activity-delete-btn');
                if (btn) {
                    this.deleteActivityItem(btn.dataset.type, parseInt(btn.dataset.id, 10));
                }
            };
        }

        // Recurring post/delete (delegated)
        const recurringList = document.getElementById('pension-recurring-list');
        if (recurringList) {
            recurringList.onclick = (e) => {
                const postBtn = e.target.closest('.recurring-post-btn');
                const deleteBtn = e.target.closest('.recurring-delete-btn');
                if (postBtn) {
                    this.postRecurringNow(parseInt(postBtn.dataset.id, 10));
                } else if (deleteBtn) {
                    this.deleteRecurring(parseInt(deleteBtn.dataset.id, 10));
                }
            };
        }
    }

    togglePensionFields() {
        const type = document.getElementById('pension-type').value;
        const dcFields = document.getElementById('dc-pension-fields');
        const dbFields = document.getElementById('db-pension-fields');
        const isDB = type === 'defined_benefit' || type === 'state';

        if (isDB) {
            dcFields.style.display = 'none';
            dcFields.classList.add('hidden');
            dbFields.style.display = '';
            dbFields.classList.remove('hidden');
        } else {
            dcFields.style.display = '';
            dcFields.classList.remove('hidden');
            dbFields.style.display = 'none';
            dbFields.classList.add('hidden');
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

            title.textContent = t('budget', 'Edit Pension');
            document.getElementById('pension-id').value = pension.id;
            document.getElementById('pension-name').value = pension.name;
            document.getElementById('pension-type').value = pension.type;
            document.getElementById('pension-provider').value = pension.provider || '';
            document.getElementById('pension-currency').value = pension.currency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);

            if (pension.isDefinedContribution) {
                document.getElementById('pension-balance').value = pension.currentBalance || '';
                document.getElementById('pension-monthly').value = pension.monthlyContribution || '';
                document.getElementById('pension-return').value = pension.expectedReturnRate ? (pension.expectedReturnRate * 100) : '';
                document.getElementById('pension-retirement-age').value = pension.retirementAge || '';
                const targetEl = document.getElementById('pension-target');
                if (targetEl) targetEl.value = pension.projectionTarget || '';
            } else {
                document.getElementById('pension-income').value = pension.annualIncome || '';
                document.getElementById('pension-transfer').value = pension.transferValue || '';
                document.getElementById('pension-db-retirement-age').value = pension.retirementAge || '';
            }

            this.togglePensionFields();
        } else {
            title.textContent = t('budget', 'Add Pension');
            document.getElementById('pension-id').value = '';
            document.getElementById('pension-currency').value = formatters.getPrimaryCurrency(this.app.accounts, this.settings);
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
            currency: formData.get('currency') || formatters.getPrimaryCurrency(this.app.accounts, this.settings),
            isDefinedContribution: isDefinedContribution
        };

        if (isDefinedContribution) {
            data.currentBalance = formData.get('currentBalance') ? parseFloat(formData.get('currentBalance')) : null;
            data.monthlyContribution = formData.get('monthlyContribution') ? parseFloat(formData.get('monthlyContribution')) : null;
            data.expectedReturnRate = formData.get('expectedReturnRate') ? parseFloat(formData.get('expectedReturnRate')) / 100 : null;
            data.retirementAge = formData.get('retirementAge') ? parseInt(formData.get('retirementAge'), 10) : null;
            data.projectionTarget = formData.get('projectionTarget') ? parseFloat(formData.get('projectionTarget')) : null;
        } else {
            data.annualIncome = formData.get('annualIncome') ? parseFloat(formData.get('annualIncome')) : null;
            data.transferValue = formData.get('transferValue') ? parseFloat(formData.get('transferValue')) : null;
            data.retirementAge = formData.get('retirementAge') ? parseInt(formData.get('retirementAge'), 10) : null;
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
                throw new Error(serverErrorMessage(error, t('budget', 'Failed to save pension')));
            }

            this.closePensionModal();
            await this.loadPensions();
            this.renderPensions();
            showSuccess(pensionId ? t('budget', 'Pension updated') : t('budget', 'Pension added'));
        } catch (error) {
            showError(error.message);
        }
    }

    async deletePension(pensionId) {
        if (!confirm(t('budget', 'Are you sure you want to delete this pension? This action cannot be undone.'))) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(serverErrorMessage(error, t('budget', 'Failed to delete pension')));
            }

            await this.loadPensions();
            this.renderPensions();
            this.closePensionDetails();
            showSuccess(t('budget', 'Pension deleted'));
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
            workplace: t('budget', 'Workplace'),
            personal: t('budget', 'Personal'),
            sipp: t('budget', 'SIPP'),
            defined_benefit: t('budget', 'Defined Benefit'),
            state: t('budget', 'State Pension')
        }[pension.type] || pension.type;

        const providerRow = document.getElementById('pension-detail-provider-row');
        if (pension.provider) {
            providerEl.textContent = pension.provider;
            if (providerRow) providerRow.style.display = '';
        } else {
            if (providerRow) providerRow.style.display = 'none';
        }

        const currency = pension.currency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);
        if (pension.isDefinedContribution && pension.currentBalance !== null) {
            valueEl.textContent = formatters.formatCurrency(pension.currentBalance, currency, this.settings);
        } else if (pension.annualIncome !== null) {
            valueEl.textContent = t('budget', '{amount}/year', { amount: formatters.formatCurrency(pension.annualIncome, currency, this.settings) });
        } else {
            valueEl.textContent = '--';
        }

        panel.style.display = 'flex';

        // Reset the real-terms toggle for each pension opened.
        const realToggle = document.getElementById('pension-projection-realterms');
        if (realToggle) realToggle.checked = false;

        // Withdrawals only make sense for DC pensions (they have a pot).
        const withdrawalBtn = document.getElementById('record-withdrawal-btn');
        if (withdrawalBtn) withdrawalBtn.style.display = pension.isDefinedContribution ? '' : 'none';
        const recurringSection = document.getElementById('pension-recurring-section');
        if (recurringSection) recurringSection.style.display = pension.isDefinedContribution ? '' : 'none';

        // Load charts, activity and schedules
        await this.loadPensionBalanceChart(pensionId);
        await this.loadPensionProjectionChart(pensionId);
        await this.loadPensionActivity(pensionId);
        if (pension.isDefinedContribution) {
            await this.loadPensionRecurring(pensionId);
        }
    }

    closePensionDetails() {
        const panel = document.getElementById('pension-detail-panel');
        panel.style.display = 'none';
        this.currentPension = null;
    }

    /** Toggle a chart canvas vs an inline hint message. */
    _setChartHint(canvasId, hintId, hintText) {
        const canvas = document.getElementById(canvasId);
        const hint = document.getElementById(hintId);
        if (hintText) {
            if (canvas) canvas.style.display = 'none';
            if (hint) { hint.textContent = hintText; hint.style.display = ''; }
        } else {
            if (canvas) canvas.style.display = '';
            if (hint) hint.style.display = 'none';
        }
    }

    async loadPensionBalanceChart(pensionId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/balance-history`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                this._setChartHint('pension-balance-chart', 'pension-balance-hint', t('budget', 'Balance history is unavailable.'));
                return;
            }

            const data = await response.json();
            if (!data.values || data.values.length < 2) {
                this._setChartHint('pension-balance-chart', 'pension-balance-hint', t('budget', 'Add at least two balance updates to see history.'));
                return;
            }
            this._setChartHint('pension-balance-chart', 'pension-balance-hint', null);

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
                        label: t('budget', 'Balance'),
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

            if (!response.ok) {
                this._setChartHint('pension-projection-chart', 'pension-projection-hint', t('budget', 'Projection is unavailable.'));
                return;
            }

            const data = await response.json();
            this.currentProjection = data;
            this.renderProjectionSummary(data);

            // Only DC pensions have a year-by-year growth projection.
            if (!data.growthProjection || data.growthProjection.length < 2) {
                this._setChartHint('pension-projection-chart', 'pension-projection-hint', t('budget', 'No projection available for this pension type.'));
                return;
            }
            this._setChartHint('pension-projection-chart', 'pension-projection-hint', null);
            this.renderProjectionChart();
        } catch (error) {
            console.error('Failed to load pension projection chart:', error);
            this._setChartHint('pension-projection-chart', 'pension-projection-hint', t('budget', 'Projection is unavailable.'));
        }
    }

    /** (Re)draw the projection chart from this.currentProjection, honouring the real-terms toggle. */
    renderProjectionChart() {
        const data = this.currentProjection;
        if (!data || !data.growthProjection) return;
        const canvas = document.getElementById('pension-projection-chart');
        if (!canvas) return;

        const realTerms = !!(document.getElementById('pension-projection-realterms')?.checked);
        const labels = data.growthProjection.map(p => p.year);
        const values = data.growthProjection.map(p => realTerms ? (p.valueReal ?? p.value) : p.value);
        const currency = this.currentPension?.currency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);

        const datasets = [{
            label: realTerms ? t('budget', "Projected value (today's money)") : t('budget', 'Projected value'),
            data: values,
            borderColor: '#46ba61',
            backgroundColor: 'rgba(70, 186, 97, 0.1)',
            fill: true,
            tension: 0.4
        }];

        // Target line (flat dataset) when a target is set.
        if (data.projectionTarget) {
            datasets.push({
                label: t('budget', 'Target'),
                data: labels.map(() => data.projectionTarget),
                borderColor: '#e9967a',
                borderDash: [6, 4],
                borderWidth: 1,
                pointRadius: 0,
                fill: false
            });
        }

        if (this.charts.pensionProjection) {
            this.charts.pensionProjection.destroy();
        }
        this.charts.pensionProjection = new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: !!data.projectionTarget } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => formatters.formatCurrencyCompact(value, currency, this.settings)
                        }
                    }
                }
            }
        });
    }

    /** Text summary above the projection chart: target + progress. */
    renderProjectionSummary(data) {
        const el = document.getElementById('pension-projection-target-summary');
        if (!el) return;
        if (!data.projectionTarget || data.projectedValue === undefined) {
            el.style.display = 'none';
            return;
        }
        const currency = this.currentPension?.currency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);
        const projected = formatters.formatCurrency(data.projectedValue, currency, this.settings);
        const target = formatters.formatCurrency(data.projectionTarget, currency, this.settings);
        const pct = data.progressPercent ?? 0;
        el.textContent = t('budget', 'Projected {projected} of {target} target ({pct} there)', {
            projected, target, pct: `${pct}%`
        });
        el.style.display = '';
    }

    async loadPensionActivity(pensionId) {
        const container = document.getElementById('pension-activity-list');
        if (!container) return;
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/activity`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                container.innerHTML = `<div class="no-data">${t('budget', 'Activity is unavailable.')}</div>`;
                return;
            }

            const data = await response.json();

            if (!data || data.length === 0) {
                container.innerHTML = `<div class="no-data">${t('budget', 'No activity yet')}</div>`;
                return;
            }

            const currency = this.currentPension.currency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);
            const accountName = (id) => {
                const acc = (this.app.accounts || []).find(a => a.id === id);
                return acc ? acc.name : t('budget', 'an account');
            };

            container.innerHTML = data.map(activity => {
                // type: contribution | transfer_in | withdrawal | snapshot
                let typeLabel;
                let icon;
                let amountClass = '';
                let sign = '';
                switch (activity.type) {
                    case 'transfer_in':
                        typeLabel = t('budget', 'Contribution from {account}', { account: accountName(activity.sourceAccountId) });
                        icon = 'icon-category-organization';
                        amountClass = 'positive';
                        sign = '+';
                        break;
                    case 'withdrawal':
                        typeLabel = activity.sourceAccountId
                            ? t('budget', 'Withdrawal to {account}', { account: accountName(activity.sourceAccountId) })
                            : t('budget', 'Withdrawal');
                        icon = 'icon-history';
                        amountClass = 'negative';
                        sign = '−';
                        break;
                    case 'snapshot':
                        typeLabel = t('budget', 'Balance Update');
                        icon = 'icon-checkmark';
                        break;
                    case 'contribution':
                    default:
                        typeLabel = t('budget', 'Contribution');
                        icon = 'icon-add';
                        amountClass = 'positive';
                        sign = '+';
                        break;
                }

                const linkedBadge = activity.transactionId
                    ? `<span class="activity-link-badge" title="${t('budget', 'Linked to a bank transaction')}">${t('budget', 'linked')}</span>`
                    : '';
                // Snapshots are corrections, not deletable through here without losing history;
                // contributions/withdrawals get a delete affordance.
                const deletable = activity.type !== 'snapshot';
                const deleteBtn = deletable
                    ? `<button class="icon-button activity-delete-btn" data-type="contribution" data-id="${activity.id}" title="${t('budget', 'Delete')}"><span class="icon-delete" aria-hidden="true"></span></button>`
                    : `<button class="icon-button activity-delete-btn" data-type="snapshot" data-id="${activity.id}" title="${t('budget', 'Delete')}"><span class="icon-delete" aria-hidden="true"></span></button>`;

                return `
                    <div class="activity-item">
                        <div class="activity-icon ${icon}"></div>
                        <div class="activity-details">
                            <div class="activity-type">${typeLabel} ${linkedBadge}</div>
                            <div class="activity-date">${formatters.formatDate(activity.date, this.settings)}</div>
                            ${activity.note ? `<div class="activity-note">${dom.escapeHtml(activity.note)}</div>` : ''}
                        </div>
                        <div class="activity-amount ${amountClass}">${sign}${formatters.formatCurrency(activity.amount, currency, this.settings)}</div>
                        ${deleteBtn}
                    </div>
                `;
            }).join('');
        } catch (error) {
            console.error('Failed to load pension activity:', error);
            container.innerHTML = `<div class="no-data">${t('budget', 'Activity is unavailable.')}</div>`;
        }
    }

    /** Delete a contribution/withdrawal or snapshot from the activity list. */
    async deleteActivityItem(type, id) {
        const isSnapshot = type === 'snapshot';
        const message = isSnapshot
            ? t('budget', 'Delete this balance update?')
            : t('budget', 'Delete this entry? If it is linked to a bank transaction, that transaction will be removed too.');
        if (!confirm(message)) return;

        const url = isSnapshot
            ? OC.generateUrl(`/apps/budget/api/pensions/snapshots/${id}`)
            : OC.generateUrl(`/apps/budget/api/pensions/contributions/${id}`);

        try {
            const response = await fetch(url, {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(serverErrorMessage(error, t('budget', 'Failed to delete')));
            }
            await this.loadPensions();
            this.renderPensions();
            if (this.currentPension) {
                await this.showPensionDetails(this.currentPension.id);
            }
            if (this.app.loadAccounts) await this.app.loadAccounts();
            showSuccess(t('budget', 'Deleted'));
        } catch (error) {
            showError(error.message);
        }
    }

    showBalanceModal() {
        if (!this.currentPension) return;

        const modal = document.getElementById('pension-balance-modal');
        document.getElementById('pension-balance-form').reset();
        document.getElementById('snapshot-pension-id').value = this.currentPension.id;
        setDateValue('snapshot-date', formatters.getTodayDateString());

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
                throw new Error(serverErrorMessage(error, t('budget', 'Failed to update balance')));
            }

            this.closeBalanceModal();
            await this.loadPensions();
            this.renderPensions();
            await this.showPensionDetails(parseInt(pensionId));
            showSuccess(t('budget', 'Balance updated'));
        } catch (error) {
            showError(error.message);
        }
    }

    /**
     * Populate a <select> with the user's accounts. The first option is a
     * blank "no account" choice (for the optional source-account selectors).
     */
    _populateAccountSelect(selectId, blankLabel) {
        const select = document.getElementById(selectId);
        if (!select) return;
        const accounts = openAccounts(this.app.accounts); // closed ones take nothing new (#372)
        const opts = [`<option value="">${dom.escapeHtml(blankLabel)}</option>`];
        accounts.forEach(a => {
            opts.push(`<option value="${a.id}">${dom.escapeHtml(a.name)}</option>`);
        });
        select.innerHTML = opts.join('');
    }

    showContributionModal() {
        if (!this.currentPension) return;

        const modal = document.getElementById('pension-contribution-modal');
        document.getElementById('pension-contribution-form').reset();
        document.getElementById('contribution-pension-id').value = this.currentPension.id;
        this._populateAccountSelect('contribution-source-account', t('budget', 'Not from a tracked account'));
        setDateValue('contribution-date', formatters.getTodayDateString());

        modal.style.display = 'flex';
    }

    closeContributionModal() {
        document.getElementById('pension-contribution-modal').style.display = 'none';
    }

    async saveContribution() {
        const form = document.getElementById('pension-contribution-form');
        const formData = new FormData(form);
        const pensionId = formData.get('pensionId');
        const sourceAccountId = formData.get('sourceAccountId');

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
                    note: formData.get('note') || null,
                    sourceAccountId: sourceAccountId ? parseInt(sourceAccountId, 10) : null
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(serverErrorMessage(error, t('budget', 'Failed to log contribution')));
            }

            this.closeContributionModal();
            await this.loadPensions();
            this.renderPensions();
            await this.showPensionDetails(parseInt(pensionId));
            // The linked bank transaction changes account balances.
            if (this.app.loadAccounts) await this.app.loadAccounts();
            showSuccess(t('budget', 'Contribution logged'));
        } catch (error) {
            showError(error.message);
        }
    }

    // ===== Withdrawals / drawdown =====

    showWithdrawalModal() {
        if (!this.currentPension) return;
        const modal = document.getElementById('pension-withdrawal-modal');
        document.getElementById('pension-withdrawal-form').reset();
        document.getElementById('withdrawal-pension-id').value = this.currentPension.id;
        this._populateAccountSelect('withdrawal-dest-account', t('budget', 'Not into a tracked account'));
        setDateValue('withdrawal-date', formatters.getTodayDateString());
        modal.style.display = 'flex';
    }

    closeWithdrawalModal() {
        document.getElementById('pension-withdrawal-modal').style.display = 'none';
    }

    async saveWithdrawal() {
        const form = document.getElementById('pension-withdrawal-form');
        const formData = new FormData(form);
        const pensionId = formData.get('pensionId');
        const destAccountId = formData.get('destAccountId');

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/withdrawals`), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({
                    amount: parseFloat(formData.get('amount')),
                    date: formData.get('date'),
                    note: formData.get('note') || null,
                    destAccountId: destAccountId ? parseInt(destAccountId, 10) : null
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(serverErrorMessage(error, t('budget', 'Failed to record withdrawal')));
            }

            this.closeWithdrawalModal();
            await this.loadPensions();
            this.renderPensions();
            await this.showPensionDetails(parseInt(pensionId));
            if (this.app.loadAccounts) await this.app.loadAccounts();
            showSuccess(t('budget', 'Withdrawal recorded'));
        } catch (error) {
            showError(error.message);
        }
    }

    // ===== Recurring contributions (#251) =====

    async loadPensionRecurring(pensionId) {
        const container = document.getElementById('pension-recurring-list');
        if (!container) return;
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/recurring`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) {
                container.innerHTML = '';
                return;
            }
            const schedules = await response.json();
            this.renderPensionRecurring(schedules);
        } catch (error) {
            console.error('Failed to load recurring contributions:', error);
        }
    }

    renderPensionRecurring(schedules) {
        const container = document.getElementById('pension-recurring-list');
        if (!container) return;
        if (!schedules || schedules.length === 0) {
            container.innerHTML = `<div class="no-data">${t('budget', 'No scheduled contributions')}</div>`;
            return;
        }
        const currency = this.currentPension?.currency || formatters.getPrimaryCurrency(this.app.accounts, this.settings);
        const freqLabels = {
            monthly: t('budget', 'Monthly'),
            quarterly: t('budget', 'Quarterly'),
            yearly: t('budget', 'Yearly'),
        };
        container.innerHTML = schedules.map(s => {
            const freq = freqLabels[s.frequency] || s.frequency;
            const amount = formatters.formatCurrency(s.amount, currency, this.settings);
            const next = formatters.formatDate(s.nextDueDate, this.settings);
            const auto = s.autoPostEnabled ? t('budget', 'auto') : t('budget', 'manual');
            return `
                <div class="recurring-item" data-id="${s.id}">
                    <div class="recurring-details">
                        <div class="recurring-main">${amount} · ${freq} <span class="recurring-badge">${auto}</span></div>
                        <div class="recurring-sub">${t('budget', 'Next: {date}', { date: next })}</div>
                    </div>
                    <div class="recurring-actions">
                        <button class="icon-button recurring-post-btn" data-id="${s.id}" title="${t('budget', 'Post now')}"><span class="icon-confirm" aria-hidden="true"></span></button>
                        <button class="icon-button recurring-delete-btn" data-id="${s.id}" title="${t('budget', 'Delete')}"><span class="icon-delete" aria-hidden="true"></span></button>
                    </div>
                </div>
            `;
        }).join('');
    }

    showRecurringModal() {
        if (!this.currentPension) return;
        const modal = document.getElementById('pension-recurring-modal');
        document.getElementById('pension-recurring-form').reset();
        document.getElementById('recurring-pension-id').value = this.currentPension.id;
        this._populateAccountSelect('recurring-source-account', t('budget', 'Not from a tracked account'));
        setDateValue('recurring-next-date', formatters.getTodayDateString());
        modal.style.display = 'flex';
    }

    closeRecurringModal() {
        document.getElementById('pension-recurring-modal').style.display = 'none';
    }

    async saveRecurring() {
        const form = document.getElementById('pension-recurring-form');
        const formData = new FormData(form);
        const pensionId = formData.get('pensionId');
        const sourceAccountId = formData.get('sourceAccountId');

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/recurring`), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({
                    amount: parseFloat(formData.get('amount')),
                    frequency: formData.get('frequency'),
                    nextDueDate: formData.get('nextDueDate'),
                    sourceAccountId: sourceAccountId ? parseInt(sourceAccountId, 10) : null,
                    autoPostEnabled: !!form.querySelector('[name="autoPostEnabled"]')?.checked,
                    note: formData.get('note') || null
                })
            });
            if (!response.ok) {
                const error = await response.json();
                throw new Error(serverErrorMessage(error, t('budget', 'Failed to save recurring contribution')));
            }
            this.closeRecurringModal();
            await this.loadPensionRecurring(parseInt(pensionId));
            showSuccess(t('budget', 'Recurring contribution saved'));
        } catch (error) {
            showError(error.message);
        }
    }

    async deleteRecurring(recurId) {
        if (!confirm(t('budget', 'Delete this scheduled contribution?'))) return;
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/recurring/${recurId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error(t('budget', 'Failed to delete'));
            if (this.currentPension) await this.loadPensionRecurring(this.currentPension.id);
            showSuccess(t('budget', 'Deleted'));
        } catch (error) {
            showError(error.message);
        }
    }

    async postRecurringNow(recurId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/recurring/${recurId}/post`), {
                method: 'POST',
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(serverErrorMessage(error, t('budget', 'Failed to post contribution')));
            }
            await this.loadPensions();
            this.renderPensions();
            if (this.currentPension) await this.showPensionDetails(this.currentPension.id);
            if (this.app.loadAccounts) await this.app.loadAccounts();
            showSuccess(t('budget', 'Contribution posted'));
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
