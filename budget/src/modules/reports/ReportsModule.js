/**
 * Reports Module - Financial reporting and analysis
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import Chart from 'chart.js/auto';

export default class ReportsModule {
    constructor(app) {
        this.app = app;
        this.reportCharts = {};
        this.reportEventListenersSetup = false;
        this.yoyControlsSetup = false;
    }

    // Getters for app state
    get accounts() {
        return this.app.accounts;
    }

    get settings() {
        return this.app.settings;
    }

    get allTagSetsForReports() {
        return this.app.allTagSetsForReports;
    }

    set allTagSetsForReports(value) {
        this.app.allTagSetsForReports = value;
    }

    get categories() {
        return this.app.categories;
    }

    // Helper method delegations
    formatCurrency(amount, currency = null) {
        return formatters.formatCurrency(amount, currency, this.settings);
    }

    formatCurrencyCompact(amount, currency = null) {
        return formatters.formatCurrencyCompact(amount, currency, this.settings);
    }

    getPrimaryCurrency() {
        return formatters.getPrimaryCurrency(this.accounts, this.settings);
    }

    escapeHtml(text) {
        return dom.escapeHtml(text);
    }
    setupReportEventListeners() {
        // Period preset change
        const presetSelect = document.getElementById('report-period-preset');
        if (presetSelect) {
            presetSelect.addEventListener('change', (e) => {
                const customRange = document.getElementById('custom-date-range');
                if (customRange) {
                    customRange.style.display = e.target.value === 'custom' ? 'flex' : 'none';
                }
                if (e.target.value !== 'custom') {
                    this.setReportDateRange(e.target.value);
                }
            });
        }

        // Generate report button
        const generateBtn = document.getElementById('generate-report-btn');
        if (generateBtn) {
            generateBtn.addEventListener('click', () => this.generateReport());
        }

        // Report type change
        const typeSelect = document.getElementById('report-type');
        if (typeSelect) {
            typeSelect.addEventListener('change', () => this.generateReport());
        }

        // Export buttons
        document.getElementById('export-csv-btn')?.addEventListener('click', () => this.exportReport('csv'));
        document.getElementById('export-pdf-btn')?.addEventListener('click', () => this.exportReport('pdf'));

        // Initialize charts object for reports
        this.reportCharts = {};
    }

    setReportDateRange(preset) {
        const now = new Date();
        let startDate, endDate;

        switch (preset) {
            case 'this-month':
                startDate = new Date(now.getFullYear(), now.getMonth(), 1);
                endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                break;
            case 'last-3-months':
                startDate = new Date(now.getFullYear(), now.getMonth() - 2, 1);
                endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                break;
            case 'ytd':
                startDate = new Date(now.getFullYear(), 0, 1);
                endDate = now;
                break;
            case 'last-year':
                startDate = new Date(now.getFullYear() - 1, 0, 1);
                endDate = new Date(now.getFullYear() - 1, 11, 31);
                break;
            default:
                startDate = new Date(now.getFullYear(), now.getMonth() - 2, 1);
                endDate = now;
        }

        const startInput = document.getElementById('report-start-date');
        const endInput = document.getElementById('report-end-date');
        if (startInput) startInput.value = formatters.formatDateForAPI(startDate);
        if (endInput) endInput.value = formatters.formatDateForAPI(endDate);
    }

    async loadReportsView() {
        // Setup event listeners on first load
        if (!this.reportEventListenersSetup) {
            this.setupReportEventListeners();
            this.reportEventListenersSetup = true;
        }

        // Populate account dropdown
        this.populateReportAccountDropdown();

        // Load and populate tags dropdown
        await this.loadAllTagsForReports();

        // Set default date range
        this.setReportDateRange('last-3-months');

        // Generate initial report
        await this.generateReport();
    }

    populateReportAccountDropdown() {
        const dropdown = document.getElementById('report-account');
        if (!dropdown) return;

        dropdown.innerHTML = '<option value="">All Accounts</option>';
        if (Array.isArray(this.accounts)) {
            this.accounts.forEach(account => {
                dropdown.innerHTML += `<option value="${account.id}">${account.name}</option>`;
            });
        }
    }

    async loadAllTagsForReports() {
        try {
            const response = await fetch(
                OC.generateUrl('/apps/budget/api/tag-sets'),
                { headers: { 'requesttoken': OC.requestToken } }
            );

            if (response.ok) {
                this.allTagSetsForReports = await response.json();
                this.populateReportTagsDropdown();
            }
        } catch (error) {
            console.error('Failed to load tags for reports:', error);
        }
    }

    populateReportTagsDropdown() {
        const container = document.getElementById('report-tags-filter');
        if (!container) return;

        container.innerHTML = '';

        if (!this.allTagSetsForReports || this.allTagSetsForReports.length === 0) {
            container.innerHTML = '<div style="padding: 8px; color: var(--color-text-lighter); font-style: italic;">No tags available</div>';
            return;
        }

        // Track selected tags
        this.selectedReportTags = this.selectedReportTags || new Set();

        // Create input field
        const input = document.createElement('input');
        input.type = 'text';
        input.id = 'report-tags-input';
        input.className = 'tags-autocomplete-input';
        input.placeholder = 'Type to filter tags...';

        // Create dropdown
        const dropdown = document.createElement('div');
        dropdown.className = 'tags-autocomplete-dropdown';
        dropdown.style.display = 'none';

        container.appendChild(input);
        container.appendChild(dropdown);

        // Build flat list of all tags
        const allTags = [];
        this.allTagSetsForReports.forEach(tagSet => {
            if (tagSet.tags && tagSet.tags.length > 0) {
                tagSet.tags.forEach(tag => {
                    allTags.push({
                        id: tag.id,
                        name: tag.name,
                        color: tag.color,
                        tagSetName: tagSet.name,
                        tagSetId: tagSet.id
                    });
                });
            }
        });

        // Render dropdown function
        const renderDropdown = (filter = '') => {
            const filtered = filter
                ? allTags.filter(t =>
                    t.name.toLowerCase().includes(filter.toLowerCase()) ||
                    t.tagSetName.toLowerCase().includes(filter.toLowerCase())
                )
                : allTags;

            // Group by tag set
            const grouped = {};
            filtered.forEach(tag => {
                if (!grouped[tag.tagSetId]) {
                    grouped[tag.tagSetId] = {
                        name: tag.tagSetName,
                        tags: []
                    };
                }
                grouped[tag.tagSetId].tags.push(tag);
            });

            let html = '';
            Object.values(grouped).forEach(group => {
                html += `<div class="tags-group-header">${this.escapeHtml(group.name)}</div>`;
                group.tags.forEach(tag => {
                    const isSelected = this.selectedReportTags.has(tag.id);
                    html += `
                        <div class="tags-autocomplete-item ${isSelected ? 'selected' : ''}"
                             data-tag-id="${tag.id}">
                            <span class="tag-chip"
                                  style="display: inline-flex; align-items: center; background-color: ${this.escapeHtml(tag.color || '#888')}; color: white;
                                         padding: 2px 6px; border-radius: 10px; font-size: 10px; line-height: 14px; margin-right: 4px;">
                                ${this.escapeHtml(tag.name)}
                            </span>
                            <span class="tag-check">${isSelected ? '✓' : ''}</span>
                        </div>
                    `;
                });
            });

            dropdown.innerHTML = html || '<div class="tags-autocomplete-empty">No tags found</div>';
            dropdown.style.display = 'block';
        };

        // Event listeners
        input.addEventListener('focus', () => renderDropdown(input.value));
        input.addEventListener('input', () => renderDropdown(input.value));

        // Prevent dropdown from closing when clicking inside
        dropdown.addEventListener('mousedown', (e) => {
            e.preventDefault();
        });

        // Handle tag selection
        dropdown.addEventListener('click', (e) => {
            e.stopPropagation();
            const item = e.target.closest('.tags-autocomplete-item');
            if (item) {
                const tagId = parseInt(item.dataset.tagId);
                const clickedTag = allTags.find(t => t.id === tagId);
                if (!clickedTag) return;

                // Remove other tags from same tag set (single selection per set)
                const tagsFromSameSet = allTags.filter(t => t.tagSetId === clickedTag.tagSetId);
                tagsFromSameSet.forEach(t => {
                    if (t.id !== tagId) {
                        this.selectedReportTags.delete(t.id);
                    }
                });

                // Toggle selection
                if (this.selectedReportTags.has(tagId)) {
                    this.selectedReportTags.delete(tagId);
                } else {
                    this.selectedReportTags.add(tagId);
                }

                renderDropdown(input.value);
            }
        });

        // Close dropdown when clicking outside
        const closeDropdown = (e) => {
            if (!container.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        };

        document.addEventListener('click', closeDropdown);

        // Cleanup on module unload
        container.dataset.cleanupListener = 'true';
    }

    async generateReport() {
        const reportType = document.getElementById('report-type')?.value || 'summary';
        const startDate = document.getElementById('report-start-date')?.value;
        const endDate = document.getElementById('report-end-date')?.value;
        const accountId = document.getElementById('report-account')?.value || '';

        // Get selected tags from dropdown state
        const selectedTags = this.selectedReportTags ? Array.from(this.selectedReportTags) : [];

        // Get include untagged checkbox
        const includeUntaggedCheckbox = document.getElementById('report-include-untagged');
        const includeUntagged = includeUntaggedCheckbox ? includeUntaggedCheckbox.checked : true;

        // Show loading
        const loadingEl = document.getElementById('report-loading');
        if (loadingEl) loadingEl.style.display = 'flex';

        // Hide all report sections
        document.querySelectorAll('.report-section').forEach(el => el.style.display = 'none');

        try {
            const params = new URLSearchParams({
                startDate,
                endDate,
                ...(accountId && { accountId })
            });

            // Add tag filters if any tags are selected
            if (selectedTags.length > 0) {
                selectedTags.forEach(tagId => {
                    params.append('tagIds[]', tagId);
                });
                // Only add includeUntagged if tags are selected (otherwise it's irrelevant)
                params.append('includeUntagged', includeUntagged.toString());
            }

            switch (reportType) {
                case 'summary':
                    await this.generateSummaryReport(params);
                    break;
                case 'spending':
                    await this.generateSpendingReport(params);
                    break;
                case 'cashflow':
                    await this.generateCashFlowReport(params);
                    break;
                case 'yoy':
                    this.showYoYReport();
                    break;
                case 'bills-calendar':
                    this.showBillsCalendarReport();
                    break;
            }
        } catch (error) {
            console.error('Failed to generate report:', error);
            OC.Notification.showTemporary('Failed to generate report');
        } finally {
            if (loadingEl) loadingEl.style.display = 'none';
        }
    }

    async generateSummaryReport(params) {
        const response = await fetch(
            OC.generateUrl(`/apps/budget/api/reports/summary-comparison?${params}`),
            { headers: { 'requesttoken': OC.requestToken } }
        );

        if (!response.ok) throw new Error('Failed to fetch summary');
        const data = await response.json();

        const section = document.getElementById('report-summary');
        if (section) section.style.display = 'block';

        const currency = this.getPrimaryCurrency();
        const totals = data.totals || {};
        const comparison = data.comparison?.changes || {};

        // Update summary cards
        this.updateReportCard('report-total-income', totals.totalIncome, currency, 'report-income-change', comparison.income);
        this.updateReportCard('report-total-expenses', totals.totalExpenses, currency, 'report-expenses-change', comparison.expenses);
        this.updateReportCard('report-net-income', totals.netIncome, currency, 'report-net-change', comparison.netIncome);

        // Savings rate
        const savingsRateEl = document.getElementById('report-savings-rate');
        if (savingsRateEl && totals.totalIncome > 0) {
            const rate = ((totals.netIncome / totals.totalIncome) * 100).toFixed(1);
            savingsRateEl.textContent = `${rate}%`;
        } else if (savingsRateEl) {
            savingsRateEl.textContent = '--';
        }

        // Render trend chart
        this.renderReportTrendChart(data.trends);

        // Render accounts table
        this.renderReportAccountsTable(data.accounts || [], currency);
    }

    updateReportCard(valueId, value, currency, changeId, change) {
        const valueEl = document.getElementById(valueId);
        if (valueEl) {
            valueEl.textContent = this.formatCurrency(value || 0, currency);
        }

        const changeEl = document.getElementById(changeId);
        if (changeEl && change) {
            const arrow = change.direction === 'up' ? '↑' : change.direction === 'down' ? '↓' : '';
            const colorClass = change.direction === 'up' ? 'positive' : change.direction === 'down' ? 'negative' : '';
            changeEl.innerHTML = `${arrow} ${change.percentage}% vs prior period`;
            changeEl.className = `summary-change ${colorClass}`;
        } else if (changeEl) {
            changeEl.innerHTML = '';
        }
    }

    renderReportTrendChart(trends) {
        const canvas = document.getElementById('report-trend-chart');
        if (!canvas || !trends) return;

        if (this.reportCharts.trend) {
            this.reportCharts.trend.destroy();
        }

        const ctx = canvas.getContext('2d');
        const currency = this.getPrimaryCurrency();

        this.reportCharts.trend = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: trends.labels || [],
                datasets: [
                    {
                        label: 'Income',
                        data: trends.income || [],
                        backgroundColor: 'rgba(46, 125, 50, 0.7)',
                        borderColor: 'rgba(46, 125, 50, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Expenses',
                        data: trends.expenses || [],
                        backgroundColor: 'rgba(198, 40, 40, 0.7)',
                        borderColor: 'rgba(198, 40, 40, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${this.formatCurrency(context.raw, currency)}`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrencyCompact(value, currency)
                        }
                    }
                }
            }
        });
    }

    renderReportAccountsTable(accounts, currency) {
        const tbody = document.querySelector('#report-accounts-table tbody');
        if (!tbody) return;

        tbody.innerHTML = accounts.map(account => `
            <tr>
                <td>${account.name}</td>
                <td class="text-right positive">${this.formatCurrency(account.income || 0, currency)}</td>
                <td class="text-right negative">${this.formatCurrency(account.expenses || 0, currency)}</td>
                <td class="text-right ${(account.net || 0) >= 0 ? 'positive' : 'negative'}">${this.formatCurrency(account.net || 0, currency)}</td>
                <td class="text-right">${this.formatCurrency(account.balance || 0, currency)}</td>
            </tr>
        `).join('');
    }

    async generateSpendingReport(params) {
        const [categoryResponse, vendorResponse] = await Promise.all([
            fetch(OC.generateUrl(`/apps/budget/api/reports/spending?${params}&groupBy=category`), {
                headers: { 'requesttoken': OC.requestToken }
            }),
            fetch(OC.generateUrl(`/apps/budget/api/reports/spending?${params}&groupBy=vendor`), {
                headers: { 'requesttoken': OC.requestToken }
            })
        ]);

        const categoryData = await categoryResponse.json();
        const vendorData = await vendorResponse.json();

        const section = document.getElementById('report-spending');
        if (section) section.style.display = 'block';

        const currency = this.getPrimaryCurrency();
        const totalSpending = categoryData.totals?.amount || 0;

        // Render category chart
        this.renderReportSpendingChart(categoryData.data || [], totalSpending);

        // Render category table
        this.renderReportCategoryTable(categoryData.data || [], totalSpending, currency);

        // Render vendor table
        this.renderReportVendorTable(vendorData.data || [], currency);
    }

    renderReportSpendingChart(data, totalSpending) {
        const canvas = document.getElementById('report-spending-chart');
        if (!canvas) return;

        if (this.reportCharts.spending) {
            this.reportCharts.spending.destroy();
        }

        const ctx = canvas.getContext('2d');
        const sortedData = [...data].sort((a, b) => b.total - a.total).slice(0, 10);

        const defaultColors = [
            '#4CAF50', '#2196F3', '#FF9800', '#9C27B0', '#F44336',
            '#00BCD4', '#FFEB3B', '#795548', '#607D8B', '#E91E63'
        ];
        const colors = sortedData.map((d, i) => d.color || defaultColors[i % defaultColors.length]);

        this.reportCharts.spending = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: sortedData.map(d => d.name),
                datasets: [{
                    data: sortedData.map(d => d.total),
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = context.raw;
                                const pct = totalSpending > 0 ? ((value / totalSpending) * 100).toFixed(1) : 0;
                                return `${context.label}: ${this.formatCurrency(value)} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Render custom legend
        this.renderSpendingLegend(sortedData, totalSpending, 'report-spending-legend');
    }

    renderSpendingLegend(data, totalSpending, containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        const defaultColors = [
            '#4CAF50', '#2196F3', '#FF9800', '#9C27B0', '#F44336',
            '#00BCD4', '#FFEB3B', '#795548', '#607D8B', '#E91E63'
        ];

        container.innerHTML = data.slice(0, 8).map((item, i) => {
            const pct = totalSpending > 0 ? ((item.total / totalSpending) * 100).toFixed(1) : 0;
            const color = item.color || defaultColors[i % defaultColors.length];
            return `
                <div class="spending-legend-item">
                    <span class="spending-legend-color" style="background: ${color}"></span>
                    <span class="spending-legend-name">${item.name}</span>
                    <span class="spending-legend-value">${this.formatCurrency(item.total)}</span>
                    <span class="spending-legend-pct">${pct}%</span>
                </div>
            `;
        }).join('');
    }

    renderReportCategoryTable(data, totalSpending, currency) {
        const tbody = document.querySelector('#report-categories-table tbody');
        if (!tbody) return;

        const sortedData = [...data].sort((a, b) => b.total - a.total);

        tbody.innerHTML = sortedData.map(cat => {
            const pct = totalSpending > 0 ? ((cat.total / totalSpending) * 100).toFixed(1) : 0;
            return `
                <tr>
                    <td>
                        <span class="category-color" style="background: ${cat.color || '#888'}"></span>
                        ${cat.name}
                    </td>
                    <td class="text-right">${this.formatCurrency(cat.total, currency)}</td>
                    <td class="text-right">${pct}%</td>
                    <td class="text-right">${cat.count}</td>
                </tr>
            `;
        }).join('');
    }

    renderReportVendorTable(data, currency) {
        const tbody = document.querySelector('#report-vendors-table tbody');
        if (!tbody) return;

        tbody.innerHTML = data.map(vendor => `
            <tr>
                <td>${vendor.name}</td>
                <td class="text-right">${this.formatCurrency(vendor.total, currency)}</td>
                <td class="text-right">${vendor.count}</td>
            </tr>
        `).join('');
    }

    async generateCashFlowReport(params) {
        const response = await fetch(
            OC.generateUrl(`/apps/budget/api/reports/cashflow?${params}`),
            { headers: { 'requesttoken': OC.requestToken } }
        );

        if (!response.ok) throw new Error('Failed to fetch cash flow');
        const data = await response.json();

        const section = document.getElementById('report-cashflow');
        if (section) section.style.display = 'block';

        const currency = this.getPrimaryCurrency();
        const averages = data.averageMonthly || {};

        // Update average cards
        const avgIncomeEl = document.getElementById('report-avg-income');
        const avgExpensesEl = document.getElementById('report-avg-expenses');
        const avgNetEl = document.getElementById('report-avg-net');

        if (avgIncomeEl) avgIncomeEl.textContent = this.formatCurrency(averages.income || 0, currency);
        if (avgExpensesEl) avgExpensesEl.textContent = this.formatCurrency(averages.expenses || 0, currency);
        if (avgNetEl) avgNetEl.textContent = this.formatCurrency(averages.net || 0, currency);

        // Render chart
        this.renderCashFlowChart(data.data || []);

        // Render table
        this.renderCashFlowTable(data.data || [], currency);
    }

    renderCashFlowChart(data) {
        const canvas = document.getElementById('report-cashflow-chart');
        if (!canvas) return;

        if (this.reportCharts.cashflow) {
            this.reportCharts.cashflow.destroy();
        }

        const ctx = canvas.getContext('2d');
        const currency = this.getPrimaryCurrency();

        this.reportCharts.cashflow = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(d => this.formatReportMonthLabel(d.month)),
                datasets: [
                    {
                        label: 'Income',
                        data: data.map(d => d.income),
                        backgroundColor: 'rgba(46, 125, 50, 0.7)',
                        borderColor: 'rgba(46, 125, 50, 1)',
                        borderWidth: 1,
                        order: 2
                    },
                    {
                        label: 'Expenses',
                        data: data.map(d => d.expenses),
                        backgroundColor: 'rgba(198, 40, 40, 0.7)',
                        borderColor: 'rgba(198, 40, 40, 1)',
                        borderWidth: 1,
                        order: 2
                    },
                    {
                        label: 'Net Cash Flow',
                        data: data.map(d => d.net),
                        borderColor: 'rgba(33, 150, 243, 1)',
                        backgroundColor: 'rgba(33, 150, 243, 0.1)',
                        borderWidth: 2,
                        type: 'line',
                        tension: 0.3,
                        pointRadius: 4,
                        fill: true,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${this.formatCurrency(context.raw, currency)}`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrencyCompact(value, currency)
                        }
                    }
                }
            }
        });
    }

    renderCashFlowTable(data, currency) {
        const tbody = document.querySelector('#report-cashflow-table tbody');
        if (!tbody) return;

        let cumulative = 0;
        tbody.innerHTML = data.map(row => {
            cumulative += row.net;
            return `
                <tr>
                    <td>${this.formatReportMonthLabel(row.month)}</td>
                    <td class="text-right positive">${this.formatCurrency(row.income, currency)}</td>
                    <td class="text-right negative">${this.formatCurrency(row.expenses, currency)}</td>
                    <td class="text-right ${row.net >= 0 ? 'positive' : 'negative'}">${this.formatCurrency(row.net, currency)}</td>
                    <td class="text-right ${cumulative >= 0 ? 'positive' : 'negative'}">${this.formatCurrency(cumulative, currency)}</td>
                </tr>
            `;
        }).join('');
    }

    formatReportMonthLabel(yearMonth) {
        if (!yearMonth) return '';
        const [year, month] = yearMonth.split('-');
        const date = new Date(year, month - 1);
        return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
    }

    // ==========================================
    // Year over Year Report Functions
    // ==========================================

    showYoYReport() {
        const section = document.getElementById('report-yoy');
        if (section) section.style.display = 'block';

        // Setup YoY controls if not already done
        if (!this.yoyControlsSetup) {
            this.setupYoYControls();
            this.yoyControlsSetup = true;
        }

        // Set default month to current month
        const monthSelect = document.getElementById('yoy-month');
        if (monthSelect) {
            monthSelect.value = new Date().getMonth() + 1;
        }
    }

    setupYoYControls() {
        const comparisonType = document.getElementById('yoy-comparison-type');
        const generateBtn = document.getElementById('generate-yoy-btn');
        const monthSelect = document.querySelector('.yoy-month-select');

        if (comparisonType) {
            comparisonType.addEventListener('change', (e) => {
                if (monthSelect) {
                    monthSelect.style.display = e.target.value === 'month' ? '' : 'none';
                }
            });
        }

        if (generateBtn) {
            generateBtn.addEventListener('click', () => this.generateYoYComparison());
        }
    }

    async generateYoYComparison() {
        const comparisonType = document.getElementById('yoy-comparison-type')?.value || 'years';
        const years = document.getElementById('yoy-years')?.value || 3;
        const month = document.getElementById('yoy-month')?.value || new Date().getMonth() + 1;

        // Show loading
        const loadingEl = document.getElementById('report-loading');
        if (loadingEl) loadingEl.style.display = 'flex';

        // Hide all YoY sections
        document.getElementById('yoy-summary')?.style.setProperty('display', 'none');
        document.getElementById('yoy-chart-container')?.style.setProperty('display', 'none');
        document.getElementById('yoy-category-table-container')?.style.setProperty('display', 'none');

        try {
            let endpoint;
            switch (comparisonType) {
                case 'month':
                    endpoint = `/apps/budget/api/yoy/month?month=${month}&years=${years}`;
                    break;
                case 'categories':
                    endpoint = `/apps/budget/api/yoy/categories?years=${years}`;
                    break;
                default:
                    endpoint = `/apps/budget/api/yoy/years?years=${years}`;
            }

            const response = await fetch(OC.generateUrl(endpoint), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error('Failed to fetch YoY data');
            const data = await response.json();

            if (comparisonType === 'categories') {
                this.displayYoYCategories(data);
            } else {
                this.displayYoYComparison(data);
            }

        } catch (error) {
            console.error('Failed to generate YoY comparison:', error);
            OC.Notification.showTemporary('Failed to generate comparison');
        } finally {
            if (loadingEl) loadingEl.style.display = 'none';
        }
    }

    displayYoYComparison(data) {
        const currency = this.getPrimaryCurrency();
        const summaryEl = document.getElementById('yoy-summary');
        const cardsEl = document.getElementById('yoy-year-cards');
        const chartContainer = document.getElementById('yoy-chart-container');
        const chartTitle = document.getElementById('yoy-chart-title');

        if (!summaryEl || !cardsEl) return;

        summaryEl.style.display = '';
        if (chartContainer) chartContainer.style.display = '';

        // Build year cards
        cardsEl.innerHTML = data.years.map(year => {
            const changeHtml = year.incomeChange !== undefined ? `
                <div class="yoy-change-indicators">
                    <span class="yoy-change ${year.incomeChange >= 0 ? 'positive' : 'negative'}">
                        Income: ${year.incomeChange >= 0 ? '+' : ''}${year.incomeChange?.toFixed(1) || '0'}%
                    </span>
                    <span class="yoy-change ${year.expenseChange <= 0 ? 'positive' : 'negative'}">
                        Expenses: ${year.expenseChange >= 0 ? '+' : ''}${year.expenseChange?.toFixed(1) || '0'}%
                    </span>
                </div>
            ` : '';

            return `
                <div class="yoy-year-card ${year.isCurrent ? 'current-year' : ''}">
                    <div class="yoy-year-header">
                        <span class="yoy-year-label">${year.year}${year.isCurrent ? ' (YTD)' : ''}</span>
                        ${year.monthName ? `<span class="yoy-month-label">${year.monthName}</span>` : ''}
                    </div>
                    <div class="yoy-year-stats">
                        <div class="yoy-stat">
                            <span class="yoy-stat-label">Income</span>
                            <span class="yoy-stat-value positive">${this.formatCurrency(year.income, currency)}</span>
                        </div>
                        <div class="yoy-stat">
                            <span class="yoy-stat-label">Expenses</span>
                            <span class="yoy-stat-value negative">${this.formatCurrency(year.expenses, currency)}</span>
                        </div>
                        <div class="yoy-stat">
                            <span class="yoy-stat-label">Savings</span>
                            <span class="yoy-stat-value ${year.savings >= 0 ? 'positive' : 'negative'}">${this.formatCurrency(year.savings, currency)}</span>
                        </div>
                    </div>
                    ${changeHtml}
                </div>
            `;
        }).join('');

        // Update chart title
        if (chartTitle) {
            chartTitle.textContent = data.type === 'month'
                ? `${data.monthName} - Year over Year Comparison`
                : 'Annual Income & Expenses';
        }

        // Render chart
        this.renderYoYChart(data.years);
    }

    renderYoYChart(years) {
        const canvas = document.getElementById('yoy-chart');
        if (!canvas) return;

        if (this.reportCharts.yoy) {
            this.reportCharts.yoy.destroy();
        }

        const ctx = canvas.getContext('2d');
        const currency = this.getPrimaryCurrency();

        this.reportCharts.yoy = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: years.map(y => y.year.toString()),
                datasets: [
                    {
                        label: 'Income',
                        data: years.map(y => y.income),
                        backgroundColor: 'rgba(46, 125, 50, 0.7)',
                        borderColor: 'rgba(46, 125, 50, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Expenses',
                        data: years.map(y => y.expenses),
                        backgroundColor: 'rgba(198, 40, 40, 0.7)',
                        borderColor: 'rgba(198, 40, 40, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                return `${context.dataset.label}: ${this.formatCurrency(context.raw, currency)}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrencyCompact(value, currency)
                        }
                    }
                }
            }
        });
    }

    displayYoYCategories(data) {
        const tableContainer = document.getElementById('yoy-category-table-container');
        const headerRow = document.getElementById('yoy-category-header');
        const tbody = document.getElementById('yoy-category-body');

        if (!tableContainer || !headerRow || !tbody) return;

        tableContainer.style.display = '';
        const currency = this.getPrimaryCurrency();

        // Build header with year columns
        const years = data.categories[0]?.years || [];
        headerRow.innerHTML = '<th>Category</th>' +
            years.map(y => `<th class="text-right">${y.year}</th>`).join('') +
            '<th class="text-right">Change</th>';

        // Build table rows
        tbody.innerHTML = data.categories.map(cat => {
            const changeHtml = cat.change !== null
                ? `<span class="${cat.change <= 0 ? 'positive' : 'negative'}">${cat.change >= 0 ? '+' : ''}${cat.change.toFixed(1)}%</span>`
                : 'N/A';

            return `
                <tr>
                    <td>${this.escapeHtml(cat.name)}</td>
                    ${cat.years.map(y => `<td class="text-right">${this.formatCurrency(y.spending, currency)}</td>`).join('')}
                    <td class="text-right">${changeHtml}</td>
                </tr>
            `;
        }).join('');
    }

    async exportReport(format) {
        const reportType = document.getElementById('report-type')?.value || 'summary';
        const startDate = document.getElementById('report-start-date')?.value;
        const endDate = document.getElementById('report-end-date')?.value;
        const accountId = document.getElementById('report-account')?.value || '';

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/reports/export'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    type: reportType,
                    format,
                    startDate,
                    endDate,
                    accountId: accountId || null
                })
            });

            if (!response.ok) throw new Error('Export failed');

            const blob = await response.blob();
            const filename = `${reportType}_report_${new Date().toISOString().split('T')[0]}.${format}`;

            // Trigger download
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);

            OC.Notification.showTemporary(`Report exported as ${format.toUpperCase()}`);
        } catch (error) {
            console.error('Export failed:', error);
            OC.Notification.showTemporary('Failed to export report');
        }
    }

    // ==========================================
    // Bills Calendar Report Functions
    // ==========================================

    showBillsCalendarReport() {
        const section = document.getElementById('report-bills-calendar');
        if (section) section.style.display = 'block';

        // Setup controls if not already done
        if (!this.billsCalendarControlsSetup) {
            this.setupBillsCalendarControls();
            this.billsCalendarControlsSetup = true;
        }

        // Populate year dropdown
        this.populateBillsCalendarYears();

        // Auto-generate on first load (like other reports)
        this.generateBillsCalendar();
    }

    setupBillsCalendarControls() {
        const generateBtn = document.getElementById('generate-bills-calendar-btn');
        const viewSelect = document.getElementById('bills-calendar-view');

        if (generateBtn) {
            generateBtn.addEventListener('click', () => this.generateBillsCalendar());
        }

        if (viewSelect) {
            viewSelect.addEventListener('change', (e) => {
                const tableContainer = document.getElementById('bills-calendar-table-container');
                const heatmapContainer = document.getElementById('bills-calendar-heatmap-container');

                if (e.target.value === 'table') {
                    if (tableContainer) tableContainer.style.display = '';
                    if (heatmapContainer) heatmapContainer.style.display = 'none';
                } else {
                    if (tableContainer) tableContainer.style.display = 'none';
                    if (heatmapContainer) heatmapContainer.style.display = '';
                }
            });
        }
    }

    populateBillsCalendarYears() {
        const yearSelect = document.getElementById('bills-calendar-year');
        if (!yearSelect) return;

        const currentYear = new Date().getFullYear();
        yearSelect.innerHTML = '';

        // Add 5 years: 2 past, current, 2 future
        for (let year = currentYear - 2; year <= currentYear + 2; year++) {
            const option = document.createElement('option');
            option.value = year;
            option.textContent = year;
            if (year === currentYear) option.selected = true;
            yearSelect.appendChild(option);
        }
    }

    async generateBillsCalendar() {
        const year = document.getElementById('bills-calendar-year')?.value || new Date().getFullYear();
        const billStatus = document.getElementById('bills-calendar-status')?.value || 'active';
        const includeTransfers = document.getElementById('bills-calendar-include-transfers')?.checked || false;

        // Show loading
        const loadingEl = document.getElementById('report-loading');
        if (loadingEl) loadingEl.style.display = 'flex';

        try {
            const params = new URLSearchParams({
                year: year.toString(),
                billStatus,
                includeTransfers: includeTransfers.toString()
            });

            const response = await fetch(
                OC.generateUrl(`/apps/budget/api/bills/annual-overview?${params}`),
                { headers: { 'requesttoken': OC.requestToken } }
            );

            if (!response.ok) throw new Error('Failed to fetch bills calendar data');

            const data = await response.json();

            // Render monthly totals chart
            this.renderBillsCalendarChart(data.monthlyTotals);

            // Get current view
            const view = document.getElementById('bills-calendar-view')?.value || 'table';

            if (view === 'table') {
                this.renderBillsCalendarTable(data.bills, data.monthlyTotals);
            } else {
                this.renderBillsCalendarHeatmap(data.bills, data.monthlyTotals);
            }

            // Show appropriate containers
            document.getElementById('bills-calendar-chart-container').style.display = '';

            if (view === 'table') {
                document.getElementById('bills-calendar-table-container').style.display = '';
                document.getElementById('bills-calendar-heatmap-container').style.display = 'none';
            } else {
                document.getElementById('bills-calendar-table-container').style.display = 'none';
                document.getElementById('bills-calendar-heatmap-container').style.display = '';
            }

        } catch (error) {
            console.error('Failed to generate bills calendar:', error);
            OC.Notification.showTemporary('Failed to generate bills calendar');
        } finally {
            if (loadingEl) loadingEl.style.display = 'none';
        }
    }

    renderBillsCalendarChart(monthlyTotals) {
        const canvas = document.getElementById('bills-calendar-chart');
        if (!canvas) return;

        if (this.reportCharts.billsCalendar) {
            this.reportCharts.billsCalendar.destroy();
        }

        const ctx = canvas.getContext('2d');
        const currency = this.getPrimaryCurrency();

        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const data = [];
        for (let i = 1; i <= 12; i++) {
            data.push(monthlyTotals[i] || 0);
        }

        this.reportCharts.billsCalendar = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: months,
                datasets: [{
                    label: 'Total Bills',
                    data: data,
                    backgroundColor: 'rgba(198, 40, 40, 0.7)',
                    borderColor: 'rgba(198, 40, 40, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => `Total: ${this.formatCurrency(context.raw, currency)}`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrencyCompact(value, currency)
                        }
                    }
                }
            }
        });
    }

    renderBillsCalendarTable(bills, monthlyTotals) {
        const tbody = document.getElementById('bills-calendar-table-body');
        const tfoot = document.getElementById('bills-calendar-table-footer');
        if (!tbody || !tfoot) return;

        const currency = this.getPrimaryCurrency();

        // Render bills rows
        tbody.innerHTML = bills.map(bill => {
            const months = [];
            for (let month = 1; month <= 12; month++) {
                const occurs = bill.occurrences[month];
                const amount = occurs ? this.formatCurrency(bill.amount, currency) : '';
                months.push(`<td class="month-cell ${occurs ? 'has-bill' : 'no-bill'}">${amount}</td>`);
            }

            const transferBadge = bill.isTransfer ? ' <span class="transfer-badge" style="background: #0082c9; color: white; padding: 2px 6px; border-radius: 10px; font-size: 10px;">Transfer</span>' : '';

            return `
                <tr>
                    <td class="bill-name-col">${this.escapeHtml(bill.name)}${transferBadge}</td>
                    ${months.join('')}
                </tr>
            `;
        }).join('');

        // Render totals row
        const totals = [];
        for (let month = 1; month <= 12; month++) {
            totals.push(`<td class="total-cell">${this.formatCurrency(monthlyTotals[month] || 0, currency)}</td>`);
        }

        tfoot.innerHTML = `
            <tr class="totals-row">
                <td class="bill-name-col"><strong>Monthly Totals</strong></td>
                ${totals.join('')}
            </tr>
        `;
    }

    renderBillsCalendarHeatmap(bills, monthlyTotals) {
        const container = document.getElementById('bills-calendar-heatmap');
        if (!container) return;

        const currency = this.getPrimaryCurrency();
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

        // Find max total for color scaling
        const maxTotal = Math.max(...Object.values(monthlyTotals));

        let html = '<div class="heatmap-grid">';

        for (let month = 1; month <= 12; month++) {
            const total = monthlyTotals[month] || 0;
            const billsThisMonth = bills.filter(b => b.occurrences[month]).length;
            const intensity = maxTotal > 0 ? (total / maxTotal) : 0;

            // Color scale from light red to dark red
            const red = Math.round(198 * (0.3 + 0.7 * intensity));
            const green = Math.round(40 * (1 - 0.5 * intensity));
            const blue = Math.round(40 * (1 - 0.5 * intensity));

            html += `
                <div class="heatmap-month" style="background-color: rgba(${red}, ${green}, ${blue}, ${0.2 + 0.6 * intensity});" title="${months[month - 1]}: ${billsThisMonth} bills, ${this.formatCurrency(total, currency)}">
                    <div class="heatmap-month-name">${months[month - 1]}</div>
                    <div class="heatmap-month-count">${billsThisMonth} bills</div>
                    <div class="heatmap-month-total">${this.formatCurrency(total, currency)}</div>
                </div>
            `;
        }

        html += '</div>';

        container.innerHTML = html;
    }
}
