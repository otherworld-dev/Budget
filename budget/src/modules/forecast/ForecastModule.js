/**
 * Forecast Module - Balance forecasting and trend analysis
 */
import { translate as t } from '@nextcloud/l10n';
import * as formatters from '../../utils/formatters.js';
import Chart from '../../utils/chart.js';
import { showError } from '../../utils/notifications.js';
import { escapeHtml } from '../../utils/dom.js';
import { apiFetch } from '../../utils/api.js';
import { applyScenario, EMPTY_SCENARIO, isEmptyScenario, parseScenario } from '../../utils/forecastScenario.js';

/** Categories listed before "Show all". */
export const CATEGORY_TRENDS_SHOWN = 10;

/** The what-if boxes, by the scenario field each one fills. */
const SCENARIO_INPUTS = {
    incomePercent: 'forecast-scenario-income',
    spendingPercent: 'forecast-scenario-spending',
    monthlyAmount: 'forecast-scenario-monthly',
    oneOffAmount: 'forecast-scenario-oneoff',
    oneOffMonth: 'forecast-scenario-oneoff-month',
};

export default class ForecastModule {
    constructor(app) {
        this.app = app;
        this.showAllCategories = false;
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
            'forecast-chart',
            'forecast-trends',
            'forecast-categories'
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
            const data = await apiFetch(`/apps/budget/api/forecast/live?forecastMonths=${horizon}`, {
                errorMessage: 'Failed to fetch forecast',
            });
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
            this.displayTrendsSummary(data.trends, data.savingsProjection);
            this.displayBalanceProjectionChart(data.monthlyProjections);
            this.populateScenarioMonths(data.monthlyProjections);
            this.refreshScenario();
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
            showError(t('budget', 'Failed to load forecast data'));
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

        // What-if boxes redraw as they are typed in; nothing goes to the server
        Object.values(SCENARIO_INPUTS).forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', () => this.refreshScenario());
            }
        });

        const resetBtn = document.getElementById('forecast-scenario-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                Object.values(SCENARIO_INPUTS).forEach(id => {
                    const input = document.getElementById(id);
                    if (input && input.tagName !== 'SELECT') input.value = '';
                });
                this.refreshScenario();
            });
        }

        const categoriesToggle = document.getElementById('category-trends-toggle');
        if (categoriesToggle) {
            categoriesToggle.addEventListener('click', () => {
                this.showAllCategories = !this.showAllCategories;
                this.displayCategoryTrends(this.forecastData?.categoryBreakdown);
            });
        }
    }

    readScenario() {
        const raw = {};
        Object.entries(SCENARIO_INPUTS).forEach(([field, id]) => {
            raw[field] = document.getElementById(id)?.value ?? '';
        });
        return parseScenario(raw);
    }

    /**
     * The months a one-off can land in are the forecast's own, so they follow
     * the forecast period. The chosen month is kept while it is still in it.
     */
    populateScenarioMonths(monthlyProjections) {
        const select = document.getElementById(SCENARIO_INPUTS.oneOffMonth);
        if (!select) return;

        const previous = select.value;
        select.innerHTML = '';
        monthlyProjections.forEach((projection, index) => {
            const option = document.createElement('option');
            option.value = String(index);
            option.textContent = formatters.projectionMonthLabel(projection);
            select.appendChild(option);
        });
        if (previous !== '' && Number(previous) < monthlyProjections.length) {
            select.value = previous;
        }
    }

    refreshScenario() {
        const data = this.forecastData;
        if (!data) return;

        const projections = data.monthlyProjections || [];
        const scenario = this.readScenario();
        const empty = isEmptyScenario(scenario) || projections.length === 0;

        const resetBtn = document.getElementById('forecast-scenario-reset');
        if (resetBtn) resetBtn.style.display = empty ? 'none' : '';

        const result = empty ? null : applyScenario(data.currentBalance, projections, scenario);
        this.drawScenarioLine(result ? result.months : null);
        this.displayScenarioResult(result, projections);
    }

    /**
     * Puts the scenario on the balance chart as a dashed line, or takes it
     * off again.
     */
    drawScenarioLine(months) {
        const chart = this.balanceChart;
        if (!chart) return;

        chart.data.datasets = chart.data.datasets.filter(dataset => !dataset.whatIf);
        if (months) {
            chart.data.datasets.push({
                label: t('budget', 'What if'),
                data: months.map(month => month.balance),
                borderColor: 'rgba(255, 159, 64, 1)',
                backgroundColor: 'transparent',
                borderDash: [8, 4],
                tension: 0.3,
                yAxisID: 'y',
                whatIf: true,
            });
        }
        chart.update('none');
    }

    displayScenarioResult(result, projections) {
        const container = document.getElementById('forecast-scenario-result');
        if (!container) return;

        container.replaceChildren();
        if (!result) return;

        const currency = this.forecastCurrency;
        // Against the same sum with nothing changed, not the server's balances,
        // which can be a penny off it
        const baseline = applyScenario(this.forecastData.currentBalance, projections, EMPTY_SCENARIO);
        const difference = result.projectedBalance - baseline.projectedBalance;
        const lastMonth = formatters.projectionMonthLabel(projections[projections.length - 1]);

        const line = (text, className = '') => {
            const p = document.createElement('p');
            p.textContent = text;
            if (className) p.className = className;
            container.appendChild(p);
            return p;
        };

        const end = line(t('budget', 'By {month} your balance would be {amount}.', {
            month: lastMonth,
            amount: this.formatCurrency(result.projectedBalance, currency),
        }), 'forecast-scenario-end');

        const sign = difference > 0 ? '+' : '';
        const change = document.createElement('span');
        change.className = `forecast-scenario-change ${difference >= 0 ? 'positive' : 'negative'}`;
        change.textContent = ' ' + t('budget', '{change} compared with the forecast', {
            change: sign + this.formatCurrency(difference, currency),
        });
        end.appendChild(change);

        const belowZero = result.months.findIndex(month => month.balance < 0);
        if (belowZero !== -1) {
            line(t('budget', 'Your balance would go below zero in {month}.', {
                month: formatters.projectionMonthLabel(projections[belowZero]),
            }), 'forecast-scenario-warning');
        } else if (result.lowest) {
            line(t('budget', 'Lowest point: {amount} in {month}.', {
                amount: this.formatCurrency(result.lowest.balance, currency),
                month: formatters.projectionMonthLabel(projections[result.lowest.index]),
            }));
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

    displayTrendsSummary(trends, savingsProjection = null) {
        const avgIncomeEl = document.getElementById('avg-income');
        const avgExpensesEl = document.getElementById('avg-expenses');
        const avgSavingsEl = document.getElementById('avg-savings');
        const incomeDirectionEl = document.getElementById('income-direction');
        const expenseDirectionEl = document.getElementById('expense-direction');
        const savingsDirectionEl = document.getElementById('savings-direction');
        const savingsRateEl = document.getElementById('savings-rate');
        const currency = this.forecastCurrency;

        if (avgIncomeEl) avgIncomeEl.textContent = this.formatCurrency(trends.avgMonthlyIncome, currency);
        if (avgExpensesEl) avgExpensesEl.textContent = this.formatCurrency(trends.avgMonthlyExpenses, currency);
        if (avgSavingsEl) avgSavingsEl.textContent = this.formatCurrency(trends.avgMonthlySavings, currency);
        if (savingsRateEl) {
            const rate = savingsProjection?.savingsRate;
            // The % stays out of the string: translation tools read a literal
            // % in a msgid as a placeholder
            savingsRateEl.textContent = rate === undefined || rate === null
                ? ''
                : t('budget', '{rate} of income saved', { rate: `${rate}%` });
        }

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

    /**
     * The balance line, with the what-if line drawn beside it. Each month's
     * projected income and spending are in the tooltip rather than on a
     * second axis, which put two scales on one chart.
     */
    displayBalanceProjectionChart(monthlyProjections) {
        const canvas = document.getElementById('balance-projection-chart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const currency = this.forecastCurrency;

        if (this.balanceChart) {
            this.balanceChart.destroy();
        }

        const labels = monthlyProjections.map(formatters.projectionMonthLabel);
        const balanceData = monthlyProjections.map(p => p.balance);

        this.balanceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: t('budget', 'Projected Balance'),
                        data: balanceData,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        fill: true,
                        tension: 0.3,
                        yAxisID: 'y'
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
                        ticks: {
                            callback: (value) => formatters.formatCurrencyCompact(value, currency, this.settings)
                        }
                    }
                },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${this.formatCurrency(context.parsed.y, currency)}`,
                            afterBody: (items) => this.projectionTooltipLines(monthlyProjections[items[0]?.dataIndex])
                        }
                    }
                }
            }
        });
    }

    /**
     * A month's projected income and spending, for under the balances in the
     * chart's tooltip.
     */
    projectionTooltipLines(month) {
        if (!month) return [];
        const currency = this.forecastCurrency;
        return [
            `${t('budget', 'Projected Income')}: ${this.formatCurrency(month.income, currency)}`,
            `${t('budget', 'Projected Expenses')}: ${this.formatCurrency(month.expenses, currency)}`,
        ];
    }

    /**
     * The biggest categories, with the rest behind "Show all": the whole
     * list filled half the page.
     */
    displayCategoryTrends(categoryBreakdown) {
        const container = document.getElementById('category-trends-list');
        if (!container) return;

        container.innerHTML = '';
        const currency = this.forecastCurrency;
        const toggle = document.getElementById('category-trends-toggle');
        if (toggle) toggle.style.display = 'none';

        if (!categoryBreakdown || categoryBreakdown.length === 0) {
            container.innerHTML = `<p class="empty-message">${t('budget', 'No category data available')}</p>`;
            return;
        }

        const shown = this.showAllCategories
            ? categoryBreakdown
            : categoryBreakdown.slice(0, CATEGORY_TRENDS_SHOWN);

        shown.forEach(category => {
            const trendArrow = { up: '↑', down: '↓', stable: '→' }[category.trend] || '→';
            const trendClass = category.trend === 'up' ? 'negative' : (category.trend === 'down' ? 'positive' : 'neutral');

            const item = document.createElement('div');
            item.className = 'category-trend-item';
            item.innerHTML = `
                <span class="category-name">${escapeHtml(category.name)}</span>
                <span class="category-amount">${t('budget', '{amount}/mo', { amount: this.formatCurrency(category.avgMonthly, currency) })}</span>
                <span class="category-trend ${trendClass}">${trendArrow}</span>
            `;
            container.appendChild(item);
        });

        if (toggle && categoryBreakdown.length > CATEGORY_TRENDS_SHOWN) {
            toggle.textContent = this.showAllCategories
                ? t('budget', 'Show fewer')
                : t('budget', 'Show all ({count})', { count: categoryBreakdown.length });
            toggle.style.display = '';
        }
    }

    displayDataQuality(data) {
        const confidenceEl = document.getElementById('forecast-confidence');
        const dataInfoEl = document.getElementById('data-info');

        if (confidenceEl) {
            confidenceEl.textContent = `${data.confidence}%`;
            // Add color class based on confidence
            confidenceEl.className = 'card-value quality-value';
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
            dataInfoEl.textContent = t('budget', 'Based on {months} month(s) of data ({count} transactions)', { months: quality.monthsOfData, count: quality.transactionCount });
        }

        formatters.showMonthGroupingNote(document.getElementById('forecast-month-note'), data.monthStartDay || 1);
    }
}
