/**
 * Forecast Module - Balance forecasting and trend analysis
 */
import * as formatters from '../../utils/formatters.js';
import Chart from 'chart.js/auto';
import { showError } from '../../utils/notifications.js';

export default class ForecastModule {
    constructor(app) {
        this.app = app;
        this.savingsChart = null;
        this.balanceChart = null;
        this.forecastData = null;
        this.forecastCurrency = null;
        this._eventsSetup = false;
    }

    // Getters for app state
    get settings() {
        return this.app.settings;
    }

    // Helper method delegations
    formatCurrency(amount, currency = null) {
        return formatters.formatCurrency(amount, currency, this.settings);
    }

    getPrimaryCurrency() {
        return formatters.getPrimaryCurrency(this.app.accounts, this.settings);
    }

    async loadForecastView() {
        // Setup event listeners once
        if (!this._eventsSetup) {
            this.setupForecastEventListeners();
            this._eventsSetup = true;
        }

        const loadingEl = document.getElementById('forecast-loading');
        const emptyEl = document.getElementById('forecast-empty');
        const sections = [
            'forecast-overview',
            'forecast-trends',
            'forecast-savings',
            'forecast-chart',
            'forecast-categories',
            'forecast-quality'
        ];

        // Show loading, hide everything else
        if (loadingEl) loadingEl.style.display = 'block';
        if (emptyEl) emptyEl.style.display = 'none';
        sections.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });

        try {
            const horizon = document.getElementById('forecast-horizon')?.value || 6;
            const response = await fetch(OC.generateUrl(`/apps/budget/api/forecast/live?forecastMonths=${horizon}`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                throw new Error('Failed to fetch forecast');
            }

            const data = await response.json();
            this.forecastData = data;
            this.forecastCurrency = data.currency || this.getPrimaryCurrency();

            // Hide loading
            if (loadingEl) loadingEl.style.display = 'none';

            // Check if we have enough data
            if (!data.dataQuality.isReliable && data.dataQuality.monthsOfData < 1) {
                if (emptyEl) emptyEl.style.display = 'block';
                return;
            }

            // Display all sections (currency is accessed via this.forecastCurrency)
            this.displayBalanceOverview(data);
            this.displayTrendsSummary(data.trends);
            this.displaySavingsProjection(data.savingsProjection, data.monthlyProjections);
            this.displayBalanceProjectionChart(data.monthlyProjections);
            this.displayCategoryTrends(data.categoryBreakdown);
            this.displayDataQuality(data);

            // Show all sections
            sections.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'block';
            });

        } catch (error) {
            console.error('Failed to load forecast:', error);
            if (loadingEl) loadingEl.style.display = 'none';
            showError('Failed to load forecast data');
        }
    }

    setupForecastEventListeners() {
        // Forecast horizon dropdown
        const horizonSelect = document.getElementById('forecast-horizon');
        if (horizonSelect) {
            horizonSelect.addEventListener('change', () => {
                this.loadForecastView();
            });
        }
    }

    displayBalanceOverview(data) {
        const currentBalanceEl = document.getElementById('current-balance');
        const projectedBalanceEl = document.getElementById('projected-balance');
        const balanceChangeEl = document.getElementById('balance-change');
        const currency = this.forecastCurrency;

        if (currentBalanceEl) {
            currentBalanceEl.textContent = this.formatCurrency(data.currentBalance, currency);
        }

        if (projectedBalanceEl) {
            projectedBalanceEl.textContent = this.formatCurrency(data.projectedBalance, currency);
        }

        if (balanceChangeEl) {
            const change = data.projectedBalance - data.currentBalance;
            const changePercent = data.currentBalance !== 0
                ? ((change / Math.abs(data.currentBalance)) * 100).toFixed(1)
                : 0;
            const sign = change >= 0 ? '+' : '';
            balanceChangeEl.textContent = `${sign}${this.formatCurrency(change, currency)} (${sign}${changePercent}%)`;
            balanceChangeEl.className = `card-change ${change >= 0 ? 'positive' : 'negative'}`;
        }
    }

    displayTrendsSummary(trends) {
        const avgIncomeEl = document.getElementById('avg-income');
        const avgExpensesEl = document.getElementById('avg-expenses');
        const avgSavingsEl = document.getElementById('avg-savings');
        const incomeDirectionEl = document.getElementById('income-direction');
        const expenseDirectionEl = document.getElementById('expense-direction');
        const savingsDirectionEl = document.getElementById('savings-direction');
        const currency = this.forecastCurrency;

        if (avgIncomeEl) avgIncomeEl.textContent = this.formatCurrency(trends.avgMonthlyIncome, currency);
        if (avgExpensesEl) avgExpensesEl.textContent = this.formatCurrency(trends.avgMonthlyExpenses, currency);
        if (avgSavingsEl) avgSavingsEl.textContent = this.formatCurrency(trends.avgMonthlySavings, currency);

        this.setDirectionIndicator(incomeDirectionEl, trends.incomeDirection);
        this.setDirectionIndicator(expenseDirectionEl, trends.expenseDirection, true);
        this.setDirectionIndicator(savingsDirectionEl, trends.savingsDirection);
    }

    setDirectionIndicator(element, direction, invertColors = false) {
        if (!element) return;

        const arrows = { up: '↑', down: '↓', stable: '→' };
        element.textContent = arrows[direction] || '→';

        // For expenses, down is good (green) and up is bad (red)
        let colorClass;
        if (direction === 'up') {
            colorClass = invertColors ? 'negative' : 'positive';
        } else if (direction === 'down') {
            colorClass = invertColors ? 'positive' : 'negative';
        } else {
            colorClass = 'neutral';
        }
        element.className = `trend-direction ${colorClass}`;
    }

    displaySavingsProjection(savings, monthlyProjections) {
        const monthlySavingsEl = document.getElementById('current-monthly-savings');
        const projectedTotalEl = document.getElementById('projected-total-savings');
        const savingsRateEl = document.getElementById('savings-rate');
        const currency = this.forecastCurrency;

        if (monthlySavingsEl) monthlySavingsEl.textContent = this.formatCurrency(savings.currentMonthlySavings, currency);
        if (projectedTotalEl) projectedTotalEl.textContent = this.formatCurrency(savings.projectedTotalSavings, currency);
        if (savingsRateEl) savingsRateEl.textContent = `${savings.savingsRate}%`;

        // Render savings chart
        this.renderSavingsChart(monthlyProjections);
    }

    renderSavingsChart(monthlyProjections) {
        const canvas = document.getElementById('savings-chart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const currency = this.forecastCurrency;

        if (this.savingsChart) {
            this.savingsChart.destroy();
        }

        const labels = monthlyProjections.map(p => p.month);
        const savingsData = [];
        let cumulative = 0;
        monthlyProjections.forEach(p => {
            cumulative += p.savings;
            savingsData.push(cumulative);
        });

        this.savingsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Cumulative Savings',
                    data: savingsData,
                    borderColor: 'rgba(75, 192, 192, 1)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrency(value, currency)
                        }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => `Savings: ${this.formatCurrency(context.parsed.y, currency)}`
                        }
                    }
                }
            }
        });
    }

    displayBalanceProjectionChart(monthlyProjections) {
        const canvas = document.getElementById('balance-projection-chart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const currency = this.forecastCurrency;

        if (this.balanceChart) {
            this.balanceChart.destroy();
        }

        const labels = monthlyProjections.map(p => p.month);
        const balanceData = monthlyProjections.map(p => p.balance);
        const incomeData = monthlyProjections.map(p => p.income);
        const expenseData = monthlyProjections.map(p => p.expenses);

        this.balanceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Projected Balance',
                        data: balanceData,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        fill: true,
                        tension: 0.3,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Projected Income',
                        data: incomeData,
                        borderColor: 'rgba(75, 192, 192, 1)',
                        backgroundColor: 'transparent',
                        borderDash: [5, 5],
                        tension: 0.3,
                        yAxisID: 'y1'
                    },
                    {
                        label: 'Projected Expenses',
                        data: expenseData,
                        borderColor: 'rgba(255, 99, 132, 1)',
                        backgroundColor: 'transparent',
                        borderDash: [5, 5],
                        tension: 0.3,
                        yAxisID: 'y1'
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
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: { display: true, text: 'Balance' },
                        ticks: {
                            callback: (value) => this.formatCurrency(value, currency)
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: { display: true, text: 'Income/Expenses' },
                        grid: { drawOnChartArea: false },
                        ticks: {
                            callback: (value) => this.formatCurrency(value, currency)
                        }
                    }
                },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${this.formatCurrency(context.parsed.y, currency)}`
                        }
                    }
                }
            }
        });
    }

    displayCategoryTrends(categoryBreakdown) {
        const container = document.getElementById('category-trends-list');
        if (!container) return;

        container.innerHTML = '';
        const currency = this.forecastCurrency;

        if (!categoryBreakdown || categoryBreakdown.length === 0) {
            container.innerHTML = '<p class="empty-message">No category data available</p>';
            return;
        }

        categoryBreakdown.forEach(category => {
            const trendArrow = { up: '↑', down: '↓', stable: '→' }[category.trend] || '→';
            const trendClass = category.trend === 'up' ? 'negative' : (category.trend === 'down' ? 'positive' : 'neutral');

            const item = document.createElement('div');
            item.className = 'category-trend-item';
            item.innerHTML = `
                <span class="category-name">${category.name}</span>
                <span class="category-amount">${this.formatCurrency(category.avgMonthly, currency)}/mo</span>
                <span class="category-trend ${trendClass}">${trendArrow}</span>
            `;
            container.appendChild(item);
        });
    }

    displayDataQuality(data) {
        const confidenceEl = document.getElementById('forecast-confidence');
        const dataInfoEl = document.getElementById('data-info');

        if (confidenceEl) {
            confidenceEl.textContent = `${data.confidence}%`;
            // Add color class based on confidence
            confidenceEl.className = 'quality-value';
            if (data.confidence >= 75) {
                confidenceEl.classList.add('high');
            } else if (data.confidence >= 50) {
                confidenceEl.classList.add('medium');
            } else {
                confidenceEl.classList.add('low');
            }
        }

        if (dataInfoEl) {
            const quality = data.dataQuality;
            dataInfoEl.textContent = `Based on ${quality.monthsOfData} month(s) of data (${quality.transactionCount} transactions)`;
        }
    }
}
