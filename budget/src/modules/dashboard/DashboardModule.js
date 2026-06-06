/**
 * DashboardModule - Handles all dashboard-related functionality
 *
 * This module manages:
 * - Loading and displaying dashboard data
 * - Hero tiles (Net Worth, Income, Expenses, Savings)
 * - Dashboard widgets (accounts, transactions, charts, alerts, etc.)
 * - Dashboard customization (drag & drop, show/hide tiles)
 * - Chart rendering (spending, trends, net worth history)
 */

import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import Chart from 'chart.js/auto';
import { DASHBOARD_WIDGETS } from '../../config/dashboardWidgets.js';
import { showSuccess, showError } from '../../utils/notifications.js';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { GridStack } from 'gridstack';
import 'gridstack/dist/gridstack.min.css';

const GRIDSTACK_SIZE_MAP = {
    xs: { w: 1, h: 1 },
    s: { w: 1, h: 4 },
    m: { w: 2, h: 4 },
    l: { w: 3, h: 4 },  // w will be set dynamically to match column count
};

/** Widget types that support multiple instances on the dashboard */
const DUPLICABLE_WIDGETS = ['trendChart', 'spendingChart', 'netWorthHistory', 'assetValueHistory', 'recentTransactions'];
const MAX_INSTANCES = 5;

/**
 * Time-series chart tiles that display a date range and get a read-only period
 * indicator. These all default to a 6-month view and are driven by the unified
 * `dateRange` tile setting, so the indicator is always accurate for them.
 */
const PERIOD_INDICATOR_WIDGETS = ['trendChart', 'spendingChart', 'netWorthHistory', 'assetValueHistory'];

export default class DashboardModule {
    constructor(app) {
        this.app = app;
        this._pendingSettings = {};
        this._saveSettingsTimer = null;
        this._saveSettingsPromise = null;
    }

    /**
     * Debounced settings save — coalesces multiple rapid updates into a single
     * API call. Merges all pending key/value pairs and flushes after 300ms of
     * inactivity. Returns a promise that resolves when the save completes.
     */
    _saveSettings(settings) {
        Object.assign(this._pendingSettings, settings);

        if (this._saveSettingsTimer) {
            clearTimeout(this._saveSettingsTimer);
        }

        this._saveSettingsPromise = new Promise((resolve, reject) => {
            this._saveSettingsTimer = setTimeout(async () => {
                const toSave = { ...this._pendingSettings };
                this._pendingSettings = {};
                this._saveSettingsTimer = null;

                try {
                    const response = await fetch(OC.generateUrl('/apps/budget/api/settings'), {
                        method: 'PUT',
                        headers: {
                            'requesttoken': OC.requestToken,
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(toSave)
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    // Update local settings cache
                    Object.assign(this.settings, toSave);
                    resolve();
                } catch (error) {
                    reject(error);
                }
            }, 300);
        });

        return this._saveSettingsPromise;
    }

    // State proxies
    get accounts() { return this.app.accounts; }
    get categories() { return this.app.categories; }
    get settings() { return this.app.settings; }
    get charts() { return this.app.charts; }
    get dashboardConfig() { return this.app.dashboardConfig; }
    get dashboardLocked() { return this.app.dashboardLocked; }
    set dashboardLocked(value) { this.app.dashboardLocked = value; }
    get widgetDataLoaded() { return this.app.widgetDataLoaded; }
    get widgetData() { return this.app.widgetData; }
    get savingsGoals() { return this.app.savingsGoals; }

    // ===========================
    // Instance Helpers
    // ===========================

    /** Get the base widget type from an instance ID (e.g., 'trendChart__2' → 'trendChart') */
    getWidgetType(instanceId) {
        if (instanceId.includes('__')) {
            return this.dashboardConfig.widgets?.instances?.[instanceId] || instanceId.split('__')[0];
        }
        return instanceId;
    }

    /** Check if an instanceId is a duplicate (not the base) */
    isDuplicateInstance(instanceId) {
        return instanceId.includes('__');
    }

    /** Generate next instance ID for a widget type */
    generateInstanceId(widgetType) {
        const instances = this.dashboardConfig.widgets?.instances || {};
        let max = 1;
        for (const key of Object.keys(instances)) {
            if (key.startsWith(widgetType + '__')) {
                const num = parseInt(key.split('__')[1]);
                if (num >= max) max = num + 1;
            }
        }
        return `${widgetType}__${max}`;
    }

    /** Count how many instances exist for a widget type (including the base) */
    countInstances(widgetType) {
        let count = 1; // base instance
        const instances = this.dashboardConfig.widgets?.instances || {};
        for (const key of Object.keys(instances)) {
            if (instances[key] === widgetType) count++;
        }
        return count;
    }

    /** Get the card element for an instance */
    getWidgetCard(instanceId) {
        return document.querySelector(`[data-widget-id="${instanceId}"]`);
    }

    // Helper proxies
    formatCurrency(amount, currency) {
        return formatters.formatCurrency(amount, currency, this.settings);
    }

    formatDate(dateStr) {
        return formatters.formatDate(dateStr, this.settings);
    }

    escapeHtml(text) {
        return dom.escapeHtml(text);
    }

    getPrimaryCurrency() {
        return this.app.getPrimaryCurrency();
    }

    // ===========================
    // Main Dashboard Load
    // ===========================

    async loadDashboard() {
        try {
            // Calculate current month date range for hero stats
            const now = new Date();
            const startOfMonth = formatters.getMonthStart(now.getFullYear(), now.getMonth() + 1);
            const endOfMonth = formatters.getMonthEnd(now.getFullYear(), now.getMonth() + 1);

            // Calculate 6-month range for trend charts
            const sixMonthsAgoDate = new Date(now.getFullYear(), now.getMonth() - 5, 1);
            const sixMonthsAgo = formatters.getMonthStart(sixMonthsAgoDate.getFullYear(), sixMonthsAgoDate.getMonth() + 1);

            // Cache-busting timestamp to ensure fresh data
            const cacheBuster = Date.now();

            // Load all dashboard data in parallel for better performance
            const [summaryResponse, trendResponse, transResponse, billsResponse, budgetResponse, goalsResponse, pensionResponse, assetResponse, netWorthResponse, alertsResponse, debtResponse, assetHistoryResponse] = await Promise.all([
                // Current month summary for hero stats
                fetch(OC.generateUrl(`/apps/budget/api/reports/summary?startDate=${startOfMonth}&endDate=${endOfMonth}&_=${cacheBuster}`), {
                    headers: { 'requesttoken': OC.requestToken }
                }),
                // 6-month summary for trend charts
                fetch(OC.generateUrl(`/apps/budget/api/reports/summary?startDate=${sixMonthsAgo}&endDate=${endOfMonth}&_=${cacheBuster}`), {
                    headers: { 'requesttoken': OC.requestToken }
                }),
                fetch(OC.generateUrl('/apps/budget/api/transactions?limit=8'), {
                    headers: { 'requesttoken': OC.requestToken }
                }),
                fetch(OC.generateUrl('/apps/budget/api/bills/upcoming'), {
                    headers: { 'requesttoken': OC.requestToken }
                }).catch(() => ({ ok: false })),
                fetch(OC.generateUrl('/apps/budget/api/reports/budget'), {
                    headers: { 'requesttoken': OC.requestToken }
                }).catch(() => ({ ok: false })),
                fetch(OC.generateUrl('/apps/budget/api/savings-goals'), {
                    headers: { 'requesttoken': OC.requestToken }
                }).catch(() => ({ ok: false })),
                fetch(OC.generateUrl('/apps/budget/api/pensions/summary'), {
                    headers: { 'requesttoken': OC.requestToken }
                }).catch(() => ({ ok: false })),
                fetch(OC.generateUrl('/apps/budget/api/assets/summary'), {
                    headers: { 'requesttoken': OC.requestToken }
                }).catch(() => ({ ok: false })),
                fetch(OC.generateUrl('/apps/budget/api/net-worth/snapshots?days=30'), {
                    headers: { 'requesttoken': OC.requestToken }
                }).catch(() => ({ ok: false })),
                fetch(OC.generateUrl('/apps/budget/api/alerts'), {
                    headers: { 'requesttoken': OC.requestToken }
                }).catch(() => ({ ok: false })),
                fetch(OC.generateUrl('/apps/budget/api/debts/summary'), {
                    headers: { 'requesttoken': OC.requestToken }
                }).catch(() => ({ ok: false })),
                fetch(OC.generateUrl('/apps/budget/api/assets/value-history?days=30'), {
                    headers: { 'requesttoken': OC.requestToken }
                }).catch(() => ({ ok: false }))
            ]);

            const summary = await summaryResponse.json();
            const trendData = await trendResponse.json();
            const transactions = await transResponse.json();
            const bills = billsResponse.ok ? await billsResponse.json() : [];
            const budgetDataRaw = budgetResponse.ok ? await budgetResponse.json() : null;
            const budgetData = budgetDataRaw && typeof budgetDataRaw === 'object' ? budgetDataRaw : { categories: [] };
            const savingsGoals = goalsResponse.ok ? await goalsResponse.json() : [];
            const pensionSummary = pensionResponse.ok ? await pensionResponse.json() : { totalPensionWorth: 0, pensionCount: 0 };
            const assetSummary = assetResponse.ok ? await assetResponse.json() : { totalAssetWorth: 0, assetCount: 0 };
            const netWorthSnapshots = netWorthResponse.ok ? await netWorthResponse.json() : [];
            const budgetAlerts = alertsResponse.ok ? await alertsResponse.json() : [];
            const debtSummary = debtResponse.ok ? await debtResponse.json() : null;
            const assetValueHistory = assetHistoryResponse.ok ? await assetHistoryResponse.json() : null;

            // Update Hero Section (current month data)
            this.updateDashboardHero(summary, pensionSummary, assetSummary);

            // Reveal hero section after data is loaded
            const heroEl = document.querySelector('.dashboard-hero');
            if (heroEl) heroEl.style.opacity = '1';

            // Update Account Widget (current balances from current month summary)
            this.updateAccountsWidget(summary.accounts || []);

            // Update Budget Alerts Widget
            this.updateBudgetAlertsWidget(budgetAlerts);
            this.widgetDataLoaded.budgetAlerts = true;

            // Update Recent Transactions
            this.updateRecentTransactions(transactions.transactions || transactions);
            this.widgetDataLoaded.recentTransactions = true;

            // Update Upcoming Bills Widget
            this.updateUpcomingBillsWidget(bills);
            this.widgetDataLoaded.upcomingBills = true;

            // Update Budget Progress Widget
            this.updateBudgetProgressWidget(budgetData.categories || []);
            this.widgetDataLoaded.budgetProgress = true;

            // Update Savings Goals Widget
            this.updateSavingsGoalsWidget(savingsGoals);
            this.widgetDataLoaded.savingsGoals = true;

            // Update Pension Dashboard Card
            this.updatePensionsSummary(pensionSummary);

            // Update Assets Dashboard Card
            this.updateAssetsSummary(assetSummary, assetValueHistory);

            // Update Debt Payoff Dashboard Card
            this.updateDebtPayoffWidget(debtSummary);
            this.widgetDataLoaded.debtPayoff = true;

            // Phase 1: Update New Hero Tiles (use existing data)
            this.updateSavingsRateHero(summary);
            this.updateCashFlowHero(summary);
            this.updateBudgetRemainingHero(budgetData);
            this.updateBudgetHealthHero(budgetAlerts);

            // Per-Account Hero Tiles
            this._lastSummary = summary;
            this.updateAccountIncomeHero(summary);
            this.updateAccountExpensesHero(summary);

            // Phase 1: Update New Widget Tiles (use existing data)
            if (trendData.spending) {
                this.updateTopCategoriesWidget(trendData.spending);
                this.widgetDataLoaded.topCategories = true;
            }
            this.updateAccountPerformanceWidget(summary.accounts || []);
            this.widgetDataLoaded.accountPerformance = true;
            this.updateBudgetBreakdownWidget(budgetData.categories || []);
            this.widgetDataLoaded.budgetBreakdown = true;
            this.updateGoalsSummaryWidget(savingsGoals);
            this.widgetDataLoaded.goalsSummary = true;
            this.updatePaymentBreakdownWidget(summary.accounts || []);
            this.widgetDataLoaded.paymentBreakdown = true;
            this.updateReconciliationStatusWidget(summary.accounts || []);
            this.widgetDataLoaded.reconciliationStatus = true;

            // Update Charts (using 6-month trend data)
            if (trendData.spending) {
                this.updateSpendingChart(trendData.spending);
            }
            if (trendData.trends) {
                this.updateTrendChart(trendData.trends);
            }

            // Update Net Worth History Chart
            this.updateNetWorthHistoryChart(netWorthSnapshots);

            // Update Asset Value History Chart
            if (assetValueHistory) {
                this.updateAssetValueHistoryChart(assetValueHistory);
            }

            // Setup dashboard controls
            this.setupDashboardControls();

            // Populate account selectors (trend chart has "All Accounts" default in HTML)
            this.populateAccountSelector('trend-account-select');
            this.populateAccountSelector('hero-account-income-select');
            this.populateAccountSelector('hero-account-expenses-select');
            this.populateAccountSelector('spending-account-select');
            this.populateAccountSelector('net-worth-account-select');
            this.populateAccountSelector('recent-transactions-account-select');

            // Refresh widgets that have a saved account selection
            await this.refreshSavedWidgetSelections();

            // Apply dashboard widget order (must be before visibility)
            this.applyDashboardOrder();

            // Apply dashboard widget visibility (must complete before Gridstack init)
            await this.applyDashboardVisibility();

            // Create DOM for any saved duplicate instances
            this.createSavedInstances();

            // Initialize Gridstack for dashboard layout
            this.initGridstack();

            // Apply responsive layout ordering
            this.applyDashboardLayout();

            // Show the read-only period indicator on date-range tiles
            this.updateTilePeriodIndicators();

            // Fetch data for duplicate instances (after Gridstack so tiles are sized)
            setTimeout(() => this.refreshAllInstances(), 50);

            // Remove tiles that ended up hidden — run after all async data loading settles
            // (some tiles get display:none set by lazy load callbacks)
            setTimeout(() => this._removeHiddenTilesFromGrid(), 500);

        } catch (error) {
            console.error('Failed to load dashboard:', error);
        }
    }

    // ===========================
    // Hero Tile Updates
    // ===========================

    updateDashboardHero(summary, pensionSummary = {}, assetSummary = {}) {
        const totals = summary.totals || {};
        const currency = summary.baseCurrency || this.getPrimaryCurrency();

        // Show conversion indicator if multi-currency conversion was applied
        this.updateConversionIndicator(summary);

        // Net Worth (accounts + pensions + assets)
        const netWorthEl = document.getElementById('hero-net-worth-value');
        if (netWorthEl) {
            const netWorth = (totals.currentBalance || 0)
                + (pensionSummary.totalPensionWorth || 0)
                + (assetSummary.totalAssetWorth || 0);
            netWorthEl.textContent = this.formatCurrency(netWorth, currency);
            netWorthEl.className = `hero-value ${netWorth >= 0 ? '' : 'expenses'}`;
        }

        // Income This Month
        const incomeEl = document.getElementById('hero-income-value');
        if (incomeEl) {
            incomeEl.textContent = this.formatCurrency(totals.totalIncome || 0, currency);
        }

        // Calculate month-over-month change for income
        const incomeChangeEl = document.getElementById('hero-income-change');
        if (incomeChangeEl && summary.trends && summary.trends.income) {
            const incomeData = summary.trends.income;
            if (incomeData.length >= 2) {
                const currentMonth = incomeData[incomeData.length - 1] || 0;
                const lastMonth = incomeData[incomeData.length - 2] || 0;
                const change = lastMonth > 0 ? ((currentMonth - lastMonth) / lastMonth * 100) : 0;
                if (change !== 0) {
                    incomeChangeEl.innerHTML = `${change >= 0 ? '↑' : '↓'} ${t('budget', '{percent}% vs last month', { percent: Math.abs(change).toFixed(1) })}`;
                    incomeChangeEl.className = `hero-change ${change >= 0 ? 'positive' : 'negative'}`;
                }
            }
        }

        // Expenses This Month
        const expensesEl = document.getElementById('hero-expenses-value');
        if (expensesEl) {
            expensesEl.textContent = this.formatCurrency(totals.totalExpenses || 0, currency);
        }

        // Calculate month-over-month change for expenses
        const expensesChangeEl = document.getElementById('hero-expenses-change');
        if (expensesChangeEl && summary.trends && summary.trends.expenses) {
            const expenseData = summary.trends.expenses;
            if (expenseData.length >= 2) {
                const currentMonth = expenseData[expenseData.length - 1] || 0;
                const lastMonth = expenseData[expenseData.length - 2] || 0;
                const change = lastMonth > 0 ? ((currentMonth - lastMonth) / lastMonth * 100) : 0;
                if (change !== 0) {
                    // For expenses, down is good
                    expensesChangeEl.innerHTML = `${change >= 0 ? '↑' : '↓'} ${t('budget', '{percent}% vs last month', { percent: Math.abs(change).toFixed(1) })}`;
                    expensesChangeEl.className = `hero-change ${change <= 0 ? 'positive' : 'negative'}`;
                }
            }
        }

        // Net Savings
        const savingsEl = document.getElementById('hero-savings-value');
        const savingsRateEl = document.getElementById('hero-savings-rate');
        if (savingsEl) {
            const netSavings = (totals.totalIncome || 0) - (totals.totalExpenses || 0);
            savingsEl.textContent = this.formatCurrency(netSavings, currency);
            savingsEl.className = `hero-value ${netSavings >= 0 ? 'income' : 'expenses'}`;

            // Savings rate
            if (savingsRateEl && totals.totalIncome > 0) {
                const savingsRate = (netSavings / totals.totalIncome * 100);
                savingsRateEl.textContent = `${savingsRate >= 0 ? '' : '-'}${t('budget', '{percent}% savings rate', { percent: Math.abs(savingsRate).toFixed(1) })}`;
            }
        }
    }

    updateSavingsRateHero(summary) {
        const el = document.getElementById('hero-savings-rate-value');
        if (!el || !summary?.totals) return;

        const income = summary.totals.totalIncome || 0;
        const savings = summary.totals.netSavings || (income - (summary.totals.totalExpenses || 0));
        const rate = income > 0 ? (savings / income * 100) : 0;

        el.textContent = `${rate.toFixed(1)}%`;
        el.className = `hero-value ${rate >= 0 ? 'income' : 'expenses'}`;

        const changeEl = document.getElementById('hero-savings-rate-change');
        if (changeEl) {
            const trend = rate >= 20 ? 'positive' : rate >= 10 ? 'neutral' : 'negative';
            const icon = rate >= 20 ? '↑' : rate >= 10 ? '→' : '↓';
            changeEl.innerHTML = `<span class="trend-icon ${trend}">${icon} ${rate >= 20 ? t('budget', 'Great') : rate >= 10 ? t('budget', 'Good') : t('budget', 'Low')}</span>`;
            changeEl.className = `hero-change ${trend}`;
        }
    }

    updateCashFlowHero(summary) {
        const el = document.getElementById('hero-cash-flow-value');
        if (!el || !summary?.totals) return;

        const income = summary.totals.totalIncome || 0;
        const expenses = summary.totals.totalExpenses || 0;
        const cashFlow = income - expenses;

        el.textContent = this.formatCurrency(cashFlow, summary.baseCurrency || this.getPrimaryCurrency());
        el.className = `hero-value ${cashFlow >= 0 ? 'income' : 'expenses'}`;

        const changeEl = document.getElementById('hero-cash-flow-change');
        if (changeEl && summary.trends) {
            // Calculate month-over-month change
            const incomeData = summary.trends.income || [];
            const expenseData = summary.trends.expenses || [];
            if (incomeData.length >= 2 && expenseData.length >= 2) {
                const currentCF = (incomeData[incomeData.length - 1] || 0) - (expenseData[expenseData.length - 1] || 0);
                const lastCF = (incomeData[incomeData.length - 2] || 0) - (expenseData[expenseData.length - 2] || 0);
                const change = lastCF !== 0 ? ((currentCF - lastCF) / Math.abs(lastCF) * 100) : 0;
                if (change !== 0) {
                    changeEl.innerHTML = `${change >= 0 ? '↑' : '↓'} ${t('budget', '{percent}% vs last month', { percent: Math.abs(change).toFixed(1) })}`;
                    changeEl.className = `hero-change ${change >= 0 ? 'positive' : 'negative'}`;
                }
            }
        }
    }

    // ===========================
    // Per-Account Hero Tiles
    // ===========================

    populateAccountSelector(selectId) {
        const select = document.getElementById(selectId);
        if (!select || select.hasAttribute('data-populated')) return;
        select.setAttribute('data-populated', 'true');

        // "All Accounts" option is already in template HTML for selects that need it
        this.accounts.forEach(account => {
            const option = document.createElement('option');
            option.value = account.id;
            option.textContent = account.name;
            select.appendChild(option);
        });

        // Restore saved selection (check hero settings first, then widget settings)
        const savedValue = this.dashboardConfig.hero?.settings?.[selectId]
            ?? this.dashboardConfig.widgets?.settings?.[selectId];
        if (savedValue && select.querySelector(`option[value="${savedValue}"]`)) {
            select.value = savedValue;
        }
    }

    async refreshSavedWidgetSelections() {
        const refreshes = [];
        const tileSettings = this.dashboardConfig.widgets?.tileSettings || {};

        // Refresh base tiles that have saved settings (accountId or dateRange)
        const widgetRefreshMap = {
            trendChart: (s) => {
                const months = { '30d': 1, '90d': 3, '6m': 6, '1y': 12 }[s.dateRange] || 6;
                return this.refreshTrendChart(months, s.accountId || null);
            },
            spendingChart: (s) => {
                const period = { '30d': 'month', '90d': '3months', '6m': '6months', '1y': 'year' }[s.dateRange] || '6months';
                return this.refreshSpendingChart(period, s.accountId || null);
            },
            netWorthHistory: (s) => {
                const days = { '30d': 30, '90d': 90, '6m': 180, '1y': 365 }[s.dateRange] || 30;
                return this.refreshNetWorthChart(days, s.accountId || null);
            },
            recentTransactions: (s) => {
                return this.refreshRecentTransactions(s.accountId || null);
            },
            assetValueHistory: (s) => {
                const days = { '30d': 30, '90d': 90, '6m': 180, '1y': 365 }[s.dateRange] || 30;
                return this.refreshAssetValueChart(days);
            },
        };

        for (const [widgetId, refreshFn] of Object.entries(widgetRefreshMap)) {
            const settings = tileSettings[widgetId];
            if (settings && (settings.accountId || settings.dateRange)) {
                refreshes.push(refreshFn(settings));
            }
        }

        if (refreshes.length > 0) {
            await Promise.all(refreshes);
        }
    }

    /**
     * The date range a tile shows when the user hasn't chosen one. Net worth and
     * asset history default to 30 days (their initial render); the trend and
     * spending charts default to 6 months.
     */
    _defaultDateRange(baseType) {
        return (baseType === 'netWorthHistory' || baseType === 'assetValueHistory') ? '30d' : '6m';
    }

    /** Human-readable label for a tile's date-range setting (per-widget default). */
    _dateRangeLabel(dateRange, baseType) {
        const labels = {
            '30d': t('budget', 'Last 30 days'),
            '90d': t('budget', 'Last 90 days'),
            '6m': t('budget', 'Last 6 months'),
            '1y': t('budget', 'Last year'),
        };
        return labels[dateRange] || labels[this._defaultDateRange(baseType)];
    }

    /**
     * Show a read-only indicator in each date-range tile's header reflecting the
     * period it is currently displaying. The period itself is changed via the
     * tile's gear settings (issue #250) — this is display-only.
     */
    updateTilePeriodIndicators() {
        const tileSettings = this.dashboardConfig?.widgets?.tileSettings || {};
        document.querySelectorAll('.dashboard-card[data-widget-id]').forEach(card => {
            const instanceId = card.getAttribute('data-widget-id');
            const baseType = this.getWidgetType(instanceId);
            const header = card.querySelector('.card-header');
            if (!header) return;

            let indicator = header.querySelector('.tile-period-indicator');

            // Only the time-series chart tiles get an indicator (their displayed
            // period reliably tracks the dateRange setting and per-widget default).
            if (!PERIOD_INDICATOR_WIDGETS.includes(baseType)) {
                if (indicator) indicator.remove();
                return;
            }

            const label = this._dateRangeLabel(tileSettings[instanceId]?.dateRange, baseType);
            if (!indicator) {
                indicator = document.createElement('span');
                indicator.className = 'tile-period-indicator';
                const controls = header.querySelector('.card-header-controls');
                if (controls) {
                    controls.insertBefore(indicator, controls.firstChild);
                } else {
                    header.appendChild(indicator);
                }
            }
            indicator.textContent = label;
        });
    }

    updateAccountIncomeHero(summary) {
        summary = summary || this._lastSummary;
        if (!summary?.accounts) return;

        const select = document.getElementById('hero-account-income-select');
        const valueEl = document.getElementById('hero-account-income-value');
        if (!select || !valueEl) return;

        const selectedId = select.value || (summary.accounts[0]?.id);
        if (!selectedId) return;

        const accountData = summary.accounts.find(a => a.id == selectedId);
        if (accountData) {
            const currency = accountData.currency || this.getPrimaryCurrency();
            valueEl.textContent = this.formatCurrency(accountData.income || 0, currency);
        }
    }

    updateAccountExpensesHero(summary) {
        summary = summary || this._lastSummary;
        if (!summary?.accounts) return;

        const select = document.getElementById('hero-account-expenses-select');
        const valueEl = document.getElementById('hero-account-expenses-value');
        if (!select || !valueEl) return;

        const selectedId = select.value || (summary.accounts[0]?.id);
        if (!selectedId) return;

        const accountData = summary.accounts.find(a => a.id == selectedId);
        if (accountData) {
            const currency = accountData.currency || this.getPrimaryCurrency();
            valueEl.textContent = this.formatCurrency(accountData.expenses || 0, currency);
        }
    }

    async saveHeroAccountSelection(selectId, accountId) {
        if (!this.dashboardConfig.hero.settings) {
            this.dashboardConfig.hero.settings = {};
        }
        this.dashboardConfig.hero.settings[selectId] = accountId;
        await this.saveDashboardVisibility();
    }

    async saveWidgetAccountSelection(selectId, accountId) {
        if (!this.dashboardConfig.widgets.settings) {
            this.dashboardConfig.widgets.settings = {};
        }
        this.dashboardConfig.widgets.settings[selectId] = accountId;
        await this.saveDashboardVisibility();
    }

    updateBudgetRemainingHero(budgetData) {
        const el = document.getElementById('hero-budget-remaining-value');
        if (!el) return;

        if (!budgetData || !budgetData.categories || budgetData.categories.length === 0) {
            el.textContent = '--';
            return;
        }

        const totalRemaining = budgetData.categories.reduce((sum, cat) => {
            const budget = cat.budgeted || cat.budget || 0;
            const spent = cat.spent || 0;
            const remaining = budget - spent;
            return sum + (remaining > 0 ? remaining : 0);
        }, 0);

        el.textContent = this.formatCurrency(totalRemaining, this.getPrimaryCurrency());
        el.className = `hero-value ${totalRemaining >= 0 ? 'income' : 'expenses'}`;

        const changeEl = document.getElementById('hero-budget-remaining-change');
        if (changeEl) {
            const categoryCount = budgetData.categories.filter(c => {
                const budget = c.budgeted || c.budget || 0;
                const spent = c.spent || 0;
                return (budget - spent) > 0;
            }).length;
            changeEl.textContent = n('budget', '%n category under budget', '%n categories under budget', categoryCount);
        }
    }

    updateBudgetHealthHero(budgetAlerts) {
        const el = document.getElementById('hero-budget-health-value');
        if (!el) return;

        // Get total number of budget categories from the existing budget progress widget
        const budgetProgressContainer = document.getElementById('budget-progress-categories');
        const totalBudgets = budgetProgressContainer ? budgetProgressContainer.querySelectorAll('.budget-category-item').length : 0;

        if (totalBudgets === 0) {
            el.textContent = '--';
            return;
        }

        const alertCount = Array.isArray(budgetAlerts) ? budgetAlerts.length : 0;
        const onTrack = Math.max(totalBudgets - alertCount, 0);
        const healthScore = (onTrack / totalBudgets * 100);

        el.textContent = `${healthScore.toFixed(0)}%`;
        el.className = `hero-value ${healthScore >= 75 ? 'income' : healthScore >= 50 ? '' : 'expenses'}`;

        const changeEl = document.getElementById('hero-budget-health-change');
        if (changeEl) {
            changeEl.textContent = t('budget', '{onTrack}/{total} on track', { onTrack, total: totalBudgets });
        }
    }

    updateDaysUntilDebtFreeHero() {
        const el = document.getElementById('hero-debt-free-value');
        if (!el) return;

        const plan = this.widgetData.daysUntilDebtFree;
        if (!plan || !plan.debts || plan.debts.length === 0) {
            // No debts — hide the tile
            const tile = document.querySelector('[data-widget-id="daysUntilDebtFree"]');
            if (tile) tile.style.display = 'none';
            return;
        }

        if (!plan.payoffDate) {
            el.textContent = t('budget', 'N/A');
            return;
        }

        const today = new Date();
        const payoff = new Date(plan.payoffDate);
        const msPerDay = 1000 * 60 * 60 * 24;
        const daysLeft = Math.max(0, Math.round((payoff - today) / msPerDay));

        el.textContent = daysLeft.toLocaleString();

        const changeEl = document.getElementById('hero-debt-free-change');
        if (changeEl) {
            const payoffDisplay = payoff.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
            changeEl.textContent = t('budget', 'Debt free by {date}', { date: payoffDisplay });
        }
    }

    // ===========================
    // Currency Conversion Indicator
    // ===========================

    updateConversionIndicator(summary) {
        // Remove any existing indicator
        const existing = document.getElementById('currency-conversion-indicator');
        if (existing) existing.remove();

        if (!summary.currencyConverted) return;

        // Place indicator inside the net worth hero tile
        const netWorthContent = document.querySelector('.hero-net-worth .hero-content');
        if (!netWorthContent) return;

        const indicator = document.createElement('span');
        indicator.id = 'currency-conversion-indicator';
        indicator.className = 'hero-subtext conversion-info';

        const unconverted = summary.unconvertedCurrencies || [];
        if (unconverted.length > 0) {
            indicator.className = 'hero-subtext conversion-warning';
            indicator.innerHTML = `&#9888; ${t('budget', 'Rates unavailable for {currencies}', { currencies: unconverted.join(', ') })}`;
        } else {
            indicator.textContent = t('budget', 'Converted to {currency} at current rates', { currency: summary.baseCurrency });
        }

        netWorthContent.appendChild(indicator);
    }

    // ===========================
    // Widget Updates
    // ===========================

    getAccountsTileConfig() {
        const config = this.dashboardConfig.widgets?.settings?.accountsTile;
        return config || { order: [], hidden: [] };
    }

    async saveAccountsTileConfig(config) {
        if (!this.dashboardConfig.widgets.settings) {
            this.dashboardConfig.widgets.settings = {};
        }
        this.dashboardConfig.widgets.settings.accountsTile = config;
        await this.saveDashboardVisibility();
    }

    updateAccountsWidget(accounts) {
        const container = document.getElementById('accounts-summary');
        if (!container || !Array.isArray(accounts)) return;

        if (accounts.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No accounts yet')}</div>`;
            return;
        }

        // Store full accounts list for config panel
        this._allDashboardAccounts = accounts;

        const accountTypeIcons = {
            checking: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>',
            savings: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.5 3.5L18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2v14H3v3c0 1.11.89 2 2 2h14c1.11 0 2-.89 2-2v-3h-3V2l-1.5 1.5zM19 19H5v-1h14v1z"/></svg>',
            credit_card: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>',
            investment: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/></svg>',
            cash: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.67 0-1.72 1.39-2.84 3.11-3.21V4h2.67v1.95c1.86.45 2.79 1.86 2.85 3.39H14.3c-.05-1.11-.64-1.87-2.22-1.87-1.5 0-2.4.68-2.4 1.64 0 .84.65 1.39 2.67 1.91s4.18 1.39 4.18 3.91c-.01 1.83-1.38 2.83-3.12 3.16z"/></svg>',
            loan: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 14V6c0-1.1-.9-2-2-2H3C1.9 4 1 4.9 1 6v8c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zm-2 0H3V6h14v8zm-7-7c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3zm13 0v11c0 1.1-.9 2-2 2H4v-2h17V7h2z"/></svg>'
        };

        const accountTypeLabels = {
            checking: t('budget', 'Checking'),
            savings: t('budget', 'Savings'),
            credit_card: t('budget', 'Credit Card'),
            investment: t('budget', 'Investment'),
            cash: t('budget', 'Cash'),
            loan: t('budget', 'Loan')
        };

        // Apply tile config: order and visibility
        const tileConfig = this.getAccountsTileConfig();
        const hiddenIds = new Set(tileConfig.hidden || []);

        // Sort: use saved order, append any new accounts at the end
        let sortedAccounts;
        if (tileConfig.order && tileConfig.order.length > 0) {
            const orderMap = {};
            tileConfig.order.forEach((id, idx) => { orderMap[id] = idx; });
            sortedAccounts = [...accounts].sort((a, b) => {
                const aIdx = orderMap[a.id] ?? 999;
                const bIdx = orderMap[b.id] ?? 999;
                return aIdx - bIdx;
            });
        } else {
            sortedAccounts = accounts;
        }

        // Filter hidden accounts
        const visibleAccounts = sortedAccounts.filter(a => !hiddenIds.has(a.id));

        if (visibleAccounts.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'All accounts hidden')}</div>`;
            return;
        }

        container.innerHTML = visibleAccounts.map(account => {
            const type = account.type || 'checking';
            const balance = parseFloat(account.balance) || 0;
            const currency = account.currency || this.getPrimaryCurrency();
            const icon = accountTypeIcons[type] || accountTypeIcons.checking;

            return `
                <div class="account-widget-item" data-account-id="${account.id}">
                    <div class="account-widget-info">
                        <div class="account-widget-icon">${icon}</div>
                        <div>
                            <div class="account-widget-name">${this.escapeHtml(account.name)}</div>
                            <div class="account-widget-type">${accountTypeLabels[type] || type.replace('_', ' ')}</div>
                        </div>
                    </div>
                    <div class="account-widget-balance">
                        <div class="account-widget-amount ${balance >= 0 ? 'positive' : 'negative'}">
                            ${this.formatCurrency(balance, currency)}
                        </div>
                        ${account.convertedBalance != null ? `<div class="account-widget-converted">\u2248 ${this.formatCurrency(account.convertedBalance, account.baseCurrency)}</div>` : ''}
                    </div>
                </div>
            `;
        }).join('');
    }

    setupAccountsTileConfig() {
        const settingsBtn = document.getElementById('accounts-tile-settings-btn');
        if (!settingsBtn) return;

        settingsBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.openTileSettingsModal('accounts', 'widget');
        });
    }

    renderAccountsTileConfigList() {
        const listEl = document.getElementById('tile-settings-modal-list');
        if (!listEl) return;

        const accounts = this._allDashboardAccounts || [];
        if (accounts.length === 0) {
            listEl.innerHTML = `<div class="empty-state-small">${t('budget', 'No accounts')}</div>`;
            return;
        }

        const tileConfig = this.getAccountsTileConfig();
        const hiddenIds = new Set(tileConfig.hidden || []);

        // Sort accounts by saved order
        let sortedAccounts;
        if (tileConfig.order && tileConfig.order.length > 0) {
            const orderMap = {};
            tileConfig.order.forEach((id, idx) => { orderMap[id] = idx; });
            sortedAccounts = [...accounts].sort((a, b) => {
                const aIdx = orderMap[a.id] ?? 999;
                const bIdx = orderMap[b.id] ?? 999;
                return aIdx - bIdx;
            });
        } else {
            sortedAccounts = accounts;
        }

        listEl.innerHTML = sortedAccounts.map(account => `
            <div class="tile-config-item" draggable="true" data-account-id="${account.id}">
                <span class="tile-config-drag-handle">&#x2630;</span>
                <span class="tile-config-name">${this.escapeHtml(account.name)}</span>
                <label class="tile-config-toggle">
                    <input type="checkbox" ${!hiddenIds.has(account.id) ? 'checked' : ''} data-account-id="${account.id}">
                </label>
            </div>
        `).join('');

        // Visibility toggles
        listEl.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', async () => {
                const config = this.getAccountsTileConfig();
                const accountId = parseInt(cb.dataset.accountId);
                if (cb.checked) {
                    config.hidden = (config.hidden || []).filter(id => id !== accountId);
                } else {
                    if (!config.hidden) config.hidden = [];
                    if (!config.hidden.includes(accountId)) config.hidden.push(accountId);
                }
                await this.saveAccountsTileConfig(config);
                this.updateAccountsWidget(this._allDashboardAccounts);
            });
        });

        // Drag and drop reordering
        let dragItem = null;
        listEl.querySelectorAll('.tile-config-item').forEach(item => {
            item.addEventListener('dragstart', (e) => {
                dragItem = item;
                item.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            item.addEventListener('dragend', () => {
                item.classList.remove('dragging');
                dragItem = null;
            });
            item.addEventListener('dragover', (e) => {
                e.preventDefault();
                if (!dragItem || dragItem === item) return;
                const rect = item.getBoundingClientRect();
                const midY = rect.top + rect.height / 2;
                if (e.clientY < midY) {
                    listEl.insertBefore(dragItem, item);
                } else {
                    listEl.insertBefore(dragItem, item.nextSibling);
                }
            });
        });

        // Allow drop on the list container
        listEl.addEventListener('dragover', (e) => e.preventDefault());

        listEl.addEventListener('drop', async (e) => {
            e.preventDefault();
            // Save new order from DOM
            const config = this.getAccountsTileConfig();
            config.order = Array.from(listEl.querySelectorAll('.tile-config-item'))
                .map(el => parseInt(el.dataset.accountId));
            await this.saveAccountsTileConfig(config);
            this.updateAccountsWidget(this._allDashboardAccounts);
        });

        // Also save on dragend as a fallback (some browsers don't fire drop reliably)
        listEl.addEventListener('dragend', async () => {
            const config = this.getAccountsTileConfig();
            const newOrder = Array.from(listEl.querySelectorAll('.tile-config-item'))
                .map(el => parseInt(el.dataset.accountId));
            const currentOrder = config.order || [];
            // Only save if order actually changed
            if (JSON.stringify(newOrder) !== JSON.stringify(currentOrder)) {
                config.order = newOrder;
                await this.saveAccountsTileConfig(config);
                this.updateAccountsWidget(this._allDashboardAccounts);
            }
        });
    }

    updateRecentTransactions(transactions, instanceId = 'recentTransactions') {
        let container;
        if (this.isDuplicateInstance(instanceId)) {
            const card = this.getWidgetCard(instanceId);
            container = card ? card.querySelector('.recent-transactions-list') : null;
        } else {
            container = document.getElementById('recent-transactions');
        }
        if (!container) return;

        if (!Array.isArray(transactions) || transactions.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No recent transactions')}</div>`;
            return;
        }

        const txSettings = this.dashboardConfig.widgets?.tileSettings?.[instanceId] || {};
        const rowCount = txSettings.rowCount || 8;
        container.innerHTML = transactions.slice(0, rowCount).map(tx => {
            const isCredit = tx.type === 'credit';
            const amount = parseFloat(tx.amount) || 0;
            const category = this.categories.find(c => c.id === tx.categoryId || c.id === tx.category_id);
            const categoryName = category ? category.name : t('budget', 'Uncategorized');
            const categoryColor = category ? category.color : '#999';
            const date = tx.date ? this.formatDate(tx.date) : '';

            return `
                <div class="recent-transaction-item">
                    <div class="recent-transaction-info">
                        <div class="recent-transaction-icon ${isCredit ? 'income' : 'expense'}">
                            ${isCredit ?
                                '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/></svg>' :
                                '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M16 18l2.29-2.29-4.88-4.88-4 4L2 7.41 3.41 6l6 6 4-4 6.3 6.29L22 12v6z"/></svg>'
                            }
                        </div>
                        <div class="recent-transaction-details">
                            <div class="recent-transaction-description">${this.escapeHtml(tx.description || tx.vendor || t('budget', 'Transaction'))}</div>
                            <div class="recent-transaction-meta">
                                <span>${date}</span>
                                <span class="recent-transaction-category">
                                    <span class="recent-transaction-category-dot" style="background: ${categoryColor}"></span>
                                    ${this.escapeHtml(categoryName)}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="recent-transaction-amount ${isCredit ? 'credit' : 'debit'}">
                        ${isCredit ? '+' : '-'}${this.formatCurrency(amount)}
                    </div>
                </div>
            `;
        }).join('');
    }

    updateBudgetAlertsWidget(alerts) {
        const card = document.getElementById('budget-alerts-card');
        const container = document.getElementById('budget-alerts');

        if (!card || !container) return;

        // Hide the card if no alerts
        if (!Array.isArray(alerts) || alerts.length === 0) {
            card.style.display = 'none';
            return;
        }

        // Show the card
        card.style.display = '';
        const currency = this.getPrimaryCurrency();

        container.innerHTML = alerts.map(alert => {
            const severityClass = alert.severity === 'danger' ? 'alert-danger' : 'alert-warning';
            const severityIcon = alert.severity === 'danger'
                ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L1 21h22L12 2zm0 3.83L19.53 19H4.47L12 5.83zM11 10v4h2v-4h-2zm0 6v2h2v-2h-2z"/></svg>'
                : '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>';

            const percentDisplay = alert.percentage >= 100
                ? t('budget', '{percent}% over', { percent: Math.round(alert.percentage - 100) })
                : t('budget', '{percent}% used', { percent: Math.round(alert.percentage) });

            return `
                <div class="budget-alert-item ${severityClass}">
                    <div class="alert-icon">${severityIcon}</div>
                    <div class="alert-content">
                        <div class="alert-category">${this.escapeHtml(alert.categoryName)}</div>
                        <div class="alert-progress">
                            <div class="alert-progress-bar">
                                <div class="alert-progress-fill ${severityClass}" style="width: ${Math.min(100, alert.percentage)}%"></div>
                            </div>
                            <span class="alert-percent">${percentDisplay}</span>
                        </div>
                        <div class="alert-amounts">
                            <span class="alert-spent">${this.formatCurrency(alert.spent, currency)}</span>
                            <span class="alert-separator">/</span>
                            <span class="alert-budget">${this.formatCurrency(alert.budgetAmount, currency)}</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    updateDebtPayoffWidget(summary) {
        const card = document.getElementById('debt-payoff-card');
        if (!card) return;

        // Hide the card if no debt
        if (!summary || summary.debtCount === 0) {
            card.style.display = 'none';
            return;
        }

        card.style.display = '';
        const currency = this.getPrimaryCurrency();

        // Update summary stats
        const totalEl = document.getElementById('debt-total-balance');
        const countEl = document.getElementById('debt-account-count');
        const minEl = document.getElementById('debt-minimum-payment');
        const estimateEl = document.getElementById('debt-payoff-estimate');

        if (totalEl) totalEl.textContent = this.formatCurrency(summary.totalBalance, currency);
        if (countEl) countEl.textContent = summary.debtCount.toString();
        if (minEl) minEl.textContent = this.formatCurrency(summary.totalMinimumPayment, currency);

        // Show payoff estimate if available
        if (estimateEl) {
            if (summary.highestInterestRate > 0) {
                estimateEl.innerHTML = `<span class="debt-hint">${t('budget', 'Highest rate: {rate}% APR', { rate: summary.highestInterestRate.toFixed(1) })}</span>`;
            } else {
                estimateEl.innerHTML = '';
            }
        }
    }

    async renderDebtChartWidget() {
        try {
            // Try active scenario first, fall back to default plan
            let plan;
            const scenariosRes = await fetch(OC.generateUrl('/apps/budget/api/debt-scenarios'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!scenariosRes.ok) return;
            const scenarios = await scenariosRes.json();
            const active = Array.isArray(scenarios) ? scenarios.find(s => s.isActive) : null;

            if (active) {
                const res = await fetch(OC.generateUrl(`/apps/budget/api/debt-scenarios/${active.id}/calculate`), {
                    headers: { 'requesttoken': OC.requestToken }
                });
                if (!res.ok) return;
                plan = await res.json();
            } else {
                const res = await fetch(OC.generateUrl('/apps/budget/api/debts/payoff-plan?strategy=avalanche'), {
                    headers: { 'requesttoken': OC.requestToken }
                });
                if (!res.ok) return;
                plan = await res.json();
            }

            if (!plan || !plan.debts || plan.debts.length === 0) return;

            // Stats row
            const statsEl = document.getElementById('debt-chart-widget-stats');
            if (statsEl) {
                const totalDebt = plan.debts.reduce((sum, d) => sum + (parseFloat(d.originalBalance) || 0), 0);
                const payoffDate = plan.payoffDate ? new Date(plan.payoffDate).toLocaleDateString(undefined, { month: 'short', year: 'numeric' }) : 'N/A';
                statsEl.innerHTML = `
                    <div style="flex:1;"><div style="font-size:10px;color:var(--color-text-maxcontrast);">${t('budget', 'Total Debt')}</div><div style="font-size:14px;font-weight:bold;color:var(--color-error);">${this.formatCurrency(totalDebt)}</div></div>
                    <div style="flex:1;"><div style="font-size:10px;color:var(--color-text-maxcontrast);">${t('budget', 'Debt Free')}</div><div style="font-size:14px;font-weight:bold;color:var(--color-success);">${payoffDate}</div></div>
                `;
            }

            // End date label
            const endEl = document.getElementById('debt-chart-widget-end');
            if (endEl && plan.payoffDate) {
                endEl.textContent = new Date(plan.payoffDate).toLocaleDateString(undefined, { year: 'numeric' });
            }

            // Mini sparkline chart
            const canvas = document.getElementById('debt-chart-widget-canvas');
            if (!canvas || !plan.timeline || plan.timeline.length === 0) return;

            const colors = ['#e74c3c', '#f39c12', '#3498db', '#2ecc71', '#9b59b6', '#1abc9c'];
            const labels = plan.timeline.map(() => '');

            // Build per-debt balance data
            const datasets = plan.debts.map((debt, i) => {
                const data = plan.timeline.map(entry => {
                    const p = entry.payments?.find(p => p.debtId === debt.id);
                    return p ? Math.max(0, p.remainingBalance) : 0;
                });
                return {
                    data,
                    borderColor: colors[i % colors.length],
                    backgroundColor: colors[i % colors.length] + '40',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0,
                    borderWidth: 1,
                };
            });

            if (this.debtChartWidget) this.debtChartWidget.destroy();
            this.debtChartWidget = new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    scales: {
                        x: { display: false },
                        y: { display: false, stacked: true },
                    },
                },
            });
        } catch (e) {
            console.error('Failed to render debt chart widget', e);
        }
    }

    async renderDebtProgressWidget() {
        try {
            const [progressRes, summaryRes] = await Promise.all([
                fetch(OC.generateUrl('/apps/budget/api/debts/progress'), { headers: { 'requesttoken': OC.requestToken } }),
                fetch(OC.generateUrl('/apps/budget/api/debts/summary'), { headers: { 'requesttoken': OC.requestToken } }),
            ]);
            if (!progressRes.ok || !summaryRes.ok) return;
            const progress = await progressRes.json();
            const summary = await summaryRes.json();

            if (summary.debtCount === 0) return;

            // Countdown
            const monthsEl = document.getElementById('debt-progress-months');
            if (monthsEl) {
                monthsEl.textContent = progress.hasActiveScenario ? progress.totalMonths : '?';
            }

            // Progress bar
            const currentDebt = Math.abs(summary.totalBalance || 0);
            const originalDebt = progress.hasActiveScenario ? progress.originalTotalDebt : currentDebt;
            const paidOff = originalDebt > 0 ? Math.max(0, Math.min(100, ((originalDebt - currentDebt) / originalDebt) * 100)) : 0;

            const remainingEl = document.getElementById('debt-progress-remaining');
            if (remainingEl) remainingEl.textContent = this.formatCurrency(currentDebt) + ' ' + t('budget', 'remaining');

            const percentEl = document.getElementById('debt-progress-percent');
            if (percentEl) percentEl.textContent = Math.round(paidOff) + '% ' + t('budget', 'paid off');

            const barEl = document.getElementById('debt-progress-bar');
            if (barEl) barEl.style.width = paidOff + '%';

            // Next payoff & status
            if (progress.hasActiveScenario) {
                // Get plan to find first unpaid debt
                const scenariosRes = await fetch(OC.generateUrl('/apps/budget/api/debt-scenarios'), { headers: { 'requesttoken': OC.requestToken } });
                if (!scenariosRes.ok) return;
                const scenarios = await scenariosRes.json();
                const active = Array.isArray(scenarios) ? scenarios.find(s => s.isActive) : null;
                if (active) {
                    const planRes = await fetch(OC.generateUrl(`/apps/budget/api/debt-scenarios/${active.id}/calculate`), { headers: { 'requesttoken': OC.requestToken } });
                    if (!planRes.ok) return;
                    const plan = await planRes.json();
                    const nextDebt = plan.debts?.find(d => d.payoffMonth);
                    const nextNameEl = document.getElementById('debt-progress-next-name');
                    const nextDateEl = document.getElementById('debt-progress-next-date');
                    if (nextDebt && nextNameEl) {
                        nextNameEl.textContent = nextDebt.name;
                        const payoffDate = new Date();
                        payoffDate.setMonth(payoffDate.getMonth() + nextDebt.payoffMonth);
                        if (nextDateEl) nextDateEl.textContent = payoffDate.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
                    }
                }

                const statusEl = document.getElementById('debt-progress-status');
                if (statusEl) {
                    const statusMap = {
                        'ahead': { text: t('budget', 'Ahead of schedule'), color: 'var(--color-success)' },
                        'behind': { text: t('budget', 'Behind schedule'), color: 'var(--color-error)' },
                        'on_track': { text: t('budget', 'On track'), color: 'var(--color-success)' },
                    };
                    const s = statusMap[progress.status] || statusMap['on_track'];
                    statusEl.textContent = s.text;
                    statusEl.style.color = s.color;
                }
            }
        } catch (e) {
            console.error('Failed to render debt progress widget', e);
        }
    }

    updateUpcomingBillsWidget(bills) {
        const container = document.getElementById('upcoming-bills');
        if (!container) return;

        if (!Array.isArray(bills) || bills.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No upcoming bills')}</div>`;
            return;
        }

        const todayStr = formatters.getTodayDateString();

        container.innerHTML = bills.slice(0, 5).map(bill => {
            const dueDateStr = bill.nextDueDate || bill.next_due_date;
            const daysUntilDue = formatters.daysBetweenDates(todayStr, dueDateStr);

            let statusClass = '';
            let dueText = '';

            if (daysUntilDue < 0) {
                statusClass = 'overdue';
                dueText = n('budget', 'Overdue by %n day', 'Overdue by %n days', Math.abs(daysUntilDue));
            } else if (daysUntilDue === 0) {
                statusClass = 'due-soon';
                dueText = t('budget', 'Due today');
            } else if (daysUntilDue <= 7) {
                statusClass = 'due-soon';
                dueText = n('budget', 'Due in %n day', 'Due in %n days', daysUntilDue);
            } else {
                dueText = t('budget', 'Due {date}', { date: formatters.parseLocalDate(dueDateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) });
            }

            return `
                <div class="bill-widget-item ${statusClass}">
                    <div class="bill-widget-info">
                        <div class="bill-widget-name">${this.escapeHtml(bill.name)}</div>
                        <div class="bill-widget-due ${statusClass}">${dueText}</div>
                    </div>
                    <div class="bill-widget-amount">${this.formatCurrency(bill.amount, bill.currency)}</div>
                </div>
            `;
        }).join('');
    }

    updateBudgetProgressWidget(categories) {
        const container = document.getElementById('budget-progress');
        if (!container) return;

        if (!Array.isArray(categories) || categories.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No budgets configured')}</div>`;
            return;
        }

        // Aggregate to top-level if setting is enabled
        const budgetSettings = this.dashboardConfig.widgets?.tileSettings?.budgetProgress || {};
        let catData = categories;
        if (budgetSettings.topLevelOnly) {
            catData = this.aggregateToTopLevel(categories);
        }

        // Filter to only categories with budgets
        const budgetedCategories = catData.filter(c => c.budgeted > 0 || c.budget > 0);

        if (budgetedCategories.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No budgets configured')}</div>`;
            return;
        }

        container.innerHTML = budgetedCategories.slice(0, 5).map(cat => {
            const budgeted = cat.budgeted || cat.budget || 0;
            const spent = cat.spent || 0;
            const percentage = budgeted > 0 ? Math.min((spent / budgeted) * 100, 100) : 0;
            const actualPercentage = budgeted > 0 ? (spent / budgeted) * 100 : 0;

            let statusClass = 'good';
            if (actualPercentage > 100) statusClass = 'over';
            else if (actualPercentage > 80) statusClass = 'danger';
            else if (actualPercentage > 50) statusClass = 'warning';

            const color = cat.color || '#0082c9';

            return `
                <div class="budget-widget-item">
                    <div class="budget-widget-header">
                        <div class="budget-widget-name">
                            <span class="budget-widget-color" style="background: ${color}"></span>
                            ${this.escapeHtml(cat.categoryName || cat.name)}
                        </div>
                        <div class="budget-widget-amounts">
                            ${this.formatCurrency(spent)} / ${this.formatCurrency(budgeted)}
                        </div>
                    </div>
                    <div class="budget-progress-bar">
                        <div class="budget-progress-fill ${statusClass}" style="width: ${percentage}%"></div>
                    </div>
                </div>
            `;
        }).join('');
    }

    async refreshBudgetProgressWidget(instanceId = 'budgetProgress') {
        try {
            const settings = this.dashboardConfig.widgets?.tileSettings?.[instanceId] || {};
            const { startDate, endDate } = this._dateRangeToParams(settings.dateRange);

            let url = `/apps/budget/api/reports/budget?startDate=${startDate}&endDate=${endDate}`;
            if (settings.accountId) {
                url += `&accountId=${settings.accountId}`;
            }
            const response = await fetch(
                OC.generateUrl(url),
                { headers: { 'requesttoken': OC.requestToken } }
            );
            if (!response.ok) throw new Error('Failed to fetch budget data');
            const data = await response.json();
            this.updateBudgetProgressWidget(data.categories || []);
        } catch (error) {
            console.error('Failed to refresh budget progress:', error);
        }
    }

    async refreshTopCategoriesWidget(instanceId = 'topCategories') {
        try {
            const settings = this.dashboardConfig.widgets?.tileSettings?.[instanceId] || {};
            const { startDate, endDate } = this._dateRangeToParams(settings.dateRange);

            let url = `/apps/budget/api/reports/spending?startDate=${startDate}&endDate=${endDate}`;
            if (settings.accountId) {
                url += `&accountId=${settings.accountId}`;
            }
            const response = await fetch(
                OC.generateUrl(url),
                { headers: { 'requesttoken': OC.requestToken } }
            );
            if (!response.ok) throw new Error('Failed to fetch spending data');
            const data = await response.json();
            this.updateTopCategoriesWidget(data.data || []);
        } catch (error) {
            console.error('Failed to refresh top categories:', error);
        }
    }

    /** Convert a dateRange setting to startDate/endDate params */
    _dateRangeToParams(dateRange) {
        const now = new Date();
        let startDate, endDate = formatters.formatDateForAPI(now);

        switch (dateRange) {
            case '30d':
                startDate = new Date(now.getFullYear(), now.getMonth(), 1);
                break;
            case '90d':
                startDate = new Date(now);
                startDate.setMonth(startDate.getMonth() - 3);
                break;
            case '1y':
                startDate = new Date(now.getFullYear(), 0, 1);
                break;
            case '6m':
            default:
                startDate = new Date(now);
                startDate.setMonth(startDate.getMonth() - 6);
                break;
        }

        return {
            startDate: formatters.formatDateForAPI(startDate),
            endDate,
        };
    }

    updateSavingsGoalsWidget(goals) {
        const container = document.getElementById('savings-goals-summary');
        if (!container) return;

        if (!Array.isArray(goals) || goals.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No savings goals yet')}</div>`;
            return;
        }

        container.innerHTML = goals.slice(0, 3).map(goal => {
            const target = goal.targetAmount || goal.target_amount || 0;
            const current = goal.currentAmount || goal.current_amount || 0;
            const percentage = target > 0 ? Math.min((current / target) * 100, 100) : 0;
            const safeColor = goal.color && /^#[0-9a-fA-F]{3,6}$/.test(goal.color) ? goal.color : '';
            const fillStyle = safeColor ? `background: ${safeColor};` : '';

            return `
                <div class="savings-goal-item">
                    <div class="savings-goal-header">
                        <div class="savings-goal-name">${this.escapeHtml(goal.name)}</div>
                        <div class="savings-goal-target">${t('budget', 'Target: {amount}', { amount: this.formatCurrency(target) })}</div>
                    </div>
                    <div class="savings-goal-progress">
                        <div class="savings-goal-fill" style="width: ${percentage}%; ${fillStyle}"></div>
                    </div>
                    <div class="savings-goal-footer">
                        <span class="savings-goal-current">${t('budget', '{amount} saved', { amount: this.formatCurrency(current) })}</span>
                        <span>${percentage.toFixed(0)}%</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    updatePensionsSummary(summary) {
        const currency = summary.baseCurrency || this.getPrimaryCurrency();
        const pensionWorth = summary.totalPensionWorth || 0;
        const projectedIncome = summary.totalProjectedIncome || 0;
        const count = summary.pensionCount || 0;

        const worthEl = document.getElementById('pensions-total-worth');
        const countEl = document.getElementById('pensions-count');

        if (worthEl) {
            worthEl.textContent = this.formatCurrency(pensionWorth, currency);
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
                heroPensionValue.textContent = this.formatCurrency(pensionWorth, currency);
                if (heroPensionLabel) heroPensionLabel.textContent = t('budget', 'Pension Worth');
            } else if (projectedIncome > 0) {
                heroPensionValue.textContent = this.formatCurrency(projectedIncome, currency) + '/yr';
                if (heroPensionLabel) heroPensionLabel.textContent = t('budget', 'Pension Income');
            } else {
                heroPensionValue.textContent = this.formatCurrency(0, currency);
                if (heroPensionLabel) heroPensionLabel.textContent = t('budget', 'Pension Worth');
            }
        }
        if (heroPensionCount) {
            let subtext = n('budget', '%n pension', '%n pensions', count);
            // If showing income but also have some pot value, mention it
            if (pensionWorth > 0 && projectedIncome > 0) {
                subtext += ` · ${t('budget', '{amount}/yr income', { amount: this.formatCurrency(projectedIncome, currency) })}`;
            }
            heroPensionCount.textContent = subtext;
        }
    }

    updateAssetsSummary(summary, valueHistory = null) {
        const currency = summary.baseCurrency || this.getPrimaryCurrency();
        const assetWorth = summary.totalAssetWorth || 0;
        const count = summary.assetCount || 0;

        const worthEl = document.getElementById('assets-total-worth');
        const countEl = document.getElementById('assets-count');

        if (worthEl) {
            worthEl.textContent = this.formatCurrency(assetWorth, currency);
        }
        if (countEl) {
            countEl.textContent = count;
        }

        // Update dashboard hero card
        const heroAssetsValue = document.getElementById('hero-assets-value');
        const heroAssetsCount = document.getElementById('hero-assets-count');
        const heroAssetsChange = document.getElementById('hero-assets-change');

        if (heroAssetsValue) {
            heroAssetsValue.textContent = this.formatCurrency(assetWorth, currency);
        }
        if (heroAssetsCount) {
            heroAssetsCount.textContent = n('budget', '%n asset', '%n assets', count);
        }

        // Show 30-day change indicator
        if (heroAssetsChange && valueHistory?.change) {
            const { amount, percentage } = valueHistory.change;
            if (amount !== 0) {
                const arrow = amount >= 0 ? '\u2191' : '\u2193';
                const absAmount = this.formatCurrency(Math.abs(amount), currency);
                heroAssetsChange.textContent = `${arrow} ${t('budget', '{amount} ({percent}%) vs 30d ago', { amount: absAmount, percent: Math.abs(percentage).toFixed(1) })}`;
                heroAssetsChange.className = `hero-change ${amount >= 0 ? 'positive' : 'negative'}`;
            } else {
                heroAssetsChange.textContent = '';
            }
        }
    }

    // Phase 1: New Widget Tiles
    updateTopCategoriesWidget(spending) {
        const container = document.getElementById('top-categories-list');
        if (!container) return;

        // Handle both object and array formats
        let spendingData;
        if (Array.isArray(spending)) {
            if (spending.length === 0) {
                container.innerHTML = `<div class="empty-state-small">${t('budget', 'No spending data')}</div>`;
                return;
            }
            spendingData = spending;
        } else if (typeof spending === 'object') {
            const entries = Object.entries(spending);
            if (entries.length === 0) {
                container.innerHTML = `<div class="empty-state-small">${t('budget', 'No spending data')}</div>`;
                return;
            }
            spendingData = entries.map(([categoryId, amount]) => ({ categoryId: parseInt(categoryId), amount }));
        } else {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No spending data')}</div>`;
            return;
        }

        // Aggregate to top-level if setting is enabled
        const topCatSettings = this.dashboardConfig.widgets?.tileSettings?.topCategories || {};
        if (topCatSettings.topLevelOnly) {
            spendingData = this.aggregateToTopLevel(spendingData);
        }

        const topCategories = spendingData
            .sort((a, b) => Math.abs(b.total || b.amount || 0) - Math.abs(a.total || a.amount || 0))
            .slice(0, 5);

        container.innerHTML = topCategories.map(item => {
            // API already includes name and color in the spending data
            const name = item.name || 'Unknown';
            const color = item.color || '#999';
            const amount = item.total || item.amount || 0;
            return `
                <div class="top-category-item">
                    <span class="category-dot" style="background: ${color}"></span>
                    <span class="category-name">${this.escapeHtml(name)}</span>
                    <span class="category-amount">${this.formatCurrency(Math.abs(amount))}</span>
                </div>
            `;
        }).join('');
    }

    updateAccountPerformanceWidget(accounts) {
        const container = document.getElementById('account-performance-list');
        if (!container || !Array.isArray(accounts)) return;

        if (accounts.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No account data')}</div>`;
            return;
        }

        // Calculate balance changes (this would ideally use historical data, but we'll use current balance as proxy)
        const accountsWithPerformance = accounts
            .map(acc => ({
                ...acc,
                changeAmount: acc.balance || 0  // In future, this could be balance - previousBalance
            }))
            .sort((a, b) => Math.abs(b.changeAmount) - Math.abs(a.changeAmount))
            .slice(0, 5);

        container.innerHTML = accountsWithPerformance.map(account => {
            const change = account.changeAmount;
            const isPositive = change >= 0;
            return `
                <div class="account-performance-item">
                    <div class="account-name">${this.escapeHtml(account.name)}</div>
                    <div class="account-balance">${this.formatCurrency(account.balance || 0)}</div>
                    <div class="account-change ${isPositive ? 'positive' : 'negative'}">
                        ${isPositive ? '↑' : '↓'} ${this.formatCurrency(Math.abs(change))}
                    </div>
                </div>
            `;
        }).join('');
    }

    updateBudgetBreakdownWidget(categories) {
        const container = document.getElementById('budget-breakdown-table');
        if (!container) return;

        if (!Array.isArray(categories) || categories.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No budget data')}</div>`;
            return;
        }

        container.innerHTML = `
            <table class="budget-breakdown-table">
                <thead>
                    <tr>
                        <th>${t('budget', 'Category')}</th>
                        <th>${t('budget', 'Budget')}</th>
                        <th>${t('budget', 'Spent')}</th>
                        <th>${t('budget', 'Remaining')}</th>
                    </tr>
                </thead>
                <tbody>
                    ${categories.map(cat => {
                        const budget = cat.budgeted || cat.budget || 0;
                        const spent = cat.spent || 0;
                        const remaining = budget - spent;
                        return `
                            <tr>
                                <td>${this.escapeHtml(cat.name)}</td>
                                <td>${this.formatCurrency(budget)}</td>
                                <td>${this.formatCurrency(spent)}</td>
                                <td class="${remaining >= 0 ? 'positive' : 'negative'}">
                                    ${this.formatCurrency(remaining)}
                                </td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        `;
    }

    updateGoalsSummaryWidget(goals) {
        const container = document.getElementById('goals-summary-list');
        if (!container) return;

        if (!Array.isArray(goals) || goals.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No savings goals')}</div>`;
            return;
        }

        container.innerHTML = goals.map(goal => {
            const target = goal.targetAmount || goal.target_amount || 0;
            const current = goal.currentAmount || goal.current_amount || 0;
            const percentage = target > 0 ? Math.min((current / target) * 100, 100) : 0;
            const safeColor = goal.color && /^#[0-9a-fA-F]{3,6}$/.test(goal.color) ? goal.color : '';
            const fillStyle = safeColor ? `background: ${safeColor};` : '';

            return `
                <div class="goal-summary-item">
                    <div class="goal-summary-header">
                        <span class="goal-name">${this.escapeHtml(goal.name)}</span>
                        <span class="goal-percentage">${percentage.toFixed(0)}%</span>
                    </div>
                    <div class="goal-summary-progress">
                        <div class="goal-summary-fill" style="width: ${percentage}%; ${fillStyle}"></div>
                    </div>
                    <div class="goal-summary-footer">
                        <span>${this.formatCurrency(current)}</span>
                        <span>${this.formatCurrency(target)}</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    updatePaymentBreakdownWidget(accounts) {
        const container = document.getElementById('payment-breakdown-list');
        if (!container || !Array.isArray(accounts)) return;

        if (accounts.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No account data')}</div>`;
            return;
        }

        // Group by account type
        const breakdown = accounts.reduce((acc, account) => {
            const type = account.type || 'Other';
            if (!acc[type]) {
                acc[type] = { count: 0, total: 0 };
            }
            acc[type].count++;
            acc[type].total += (account.balance || 0);
            return acc;
        }, {});

        const typeLabels = {
            'checking': t('budget', 'Checking'),
            'savings': t('budget', 'Savings'),
            'credit': t('budget', 'Credit Cards'),
            'investment': t('budget', 'Investments'),
            'loan': t('budget', 'Loans'),
            'Other': t('budget', 'Other')
        };

        container.innerHTML = Object.entries(breakdown).map(([type, data]) => `
            <div class="payment-method-item">
                <div class="payment-method-header">
                    <span class="payment-method-name">${typeLabels[type] || type}</span>
                    <span class="payment-method-count">${n('budget', '%n account', '%n accounts', data.count)}</span>
                </div>
                <div class="payment-method-total">${this.formatCurrency(data.total)}</div>
            </div>
        `).join('');
    }

    updateReconciliationStatusWidget(accounts) {
        const container = document.getElementById('reconciliation-status-list');
        if (!container || !Array.isArray(accounts)) return;

        if (accounts.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No accounts to reconcile')}</div>`;
            return;
        }

        // In a real implementation, this would track unreconciled transactions
        // For now, show account status
        const accountsToReconcile = accounts.map(acc => ({
            name: acc.name,
            unreconciledCount: 0,  // Would be fetched from API
            lastReconciled: null    // Would be fetched from API
        })).filter(_a => true);  // Would filter to only show accounts needing reconciliation

        if (accountsToReconcile.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'All accounts reconciled')}</div>`;
            return;
        }

        container.innerHTML = accountsToReconcile.slice(0, 5).map(account => `
            <div class="reconciliation-item">
                <div class="reconciliation-name">${this.escapeHtml(account.name)}</div>
                <div class="reconciliation-status">
                    <span class="reconciliation-badge">${t('budget', 'Up to date')}</span>
                </div>
            </div>
        `).join('');
    }

    // ===========================
    // Phase 2/3 Widget Updates
    // ===========================

    updateMonthlyComparisonWidget() {
        const container = document.getElementById('monthly-comparison-content');
        if (!container) return;

        const data = this.widgetData.monthlyComparison;
        if (!data || !data.current) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No data available')}</div>`;
            return;
        }

        const currentIncome = parseFloat(data.current.totalIncome || 0);
        const currentExpenses = parseFloat(data.current.totalExpenses || 0);
        const prevIncome = parseFloat(data.previous?.totalIncome || 0);
        const prevExpenses = parseFloat(data.previous?.totalExpenses || 0);

        const incomeChange = prevIncome > 0 ? ((currentIncome - prevIncome) / prevIncome * 100).toFixed(1) : 0;
        const expenseChange = prevExpenses > 0 ? ((currentExpenses - prevExpenses) / prevExpenses * 100).toFixed(1) : 0;

        const incomeArrow = incomeChange >= 0 ? '↑' : '↓';
        const expenseArrow = expenseChange >= 0 ? '↑' : '↓';

        container.innerHTML = `
            <div class="comparison-row">
                <span class="comparison-label">${t('budget', 'Income')}</span>
                <span class="comparison-value">${this.formatCurrency(currentIncome)}</span>
                <span class="comparison-change ${incomeChange >= 0 ? 'positive' : 'negative'}">${incomeArrow} ${Math.abs(incomeChange)}%</span>
            </div>
            <div class="comparison-row">
                <span class="comparison-label">${t('budget', 'Expenses')}</span>
                <span class="comparison-value">${this.formatCurrency(currentExpenses)}</span>
                <span class="comparison-change ${expenseChange <= 0 ? 'positive' : 'negative'}">${expenseArrow} ${Math.abs(expenseChange)}%</span>
            </div>
        `;
    }

    updateLargeTransactionsWidget() {
        const container = document.getElementById('large-transactions-list');
        if (!container) return;

        const transactions = this.widgetData.largeTransactions;
        if (!Array.isArray(transactions) || transactions.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No large transactions')}</div>`;
            return;
        }

        // Sort by absolute amount descending
        const sorted = [...transactions].sort((a, b) => Math.abs(b.amount) - Math.abs(a.amount));

        container.innerHTML = sorted.slice(0, 5).map(tx => `
            <div class="widget-list-item">
                <div class="widget-item-info">
                    <div class="widget-item-name">${this.escapeHtml(tx.vendor || tx.description || t('budget', 'Unknown'))}</div>
                    <div class="widget-item-meta">${formatters.formatDate(tx.date, this.settings)}</div>
                </div>
                <div class="widget-item-amount ${tx.type === 'credit' ? 'positive' : 'negative'}">${this.formatCurrency(tx.amount)}</div>
            </div>
        `).join('');
    }

    updateWeeklyTrendWidget() {
        const container = document.getElementById('weekly-trend-content');
        if (!container) return;

        const data = this.widgetData.weeklyTrend;
        if (!data || !Array.isArray(data) || data.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No spending data this week')}</div>`;
            return;
        }

        const total = data.reduce((sum, d) => sum + Math.abs(parseFloat(d.total || 0)), 0);
        const avgDaily = total / 7;

        container.innerHTML = `
            <div class="widget-stat">
                <div class="widget-stat-value">${this.formatCurrency(total)}</div>
                <div class="widget-stat-label">${t('budget', 'This week')}</div>
            </div>
            <div class="widget-stat">
                <div class="widget-stat-value">${this.formatCurrency(avgDaily)}</div>
                <div class="widget-stat-label">${t('budget', 'Daily average')}</div>
            </div>
        `;
    }

    updateUnmatchedTransfersWidget() {
        const container = document.getElementById('unmatched-transfers-list');
        if (!container) return;

        const transactions = this.widgetData.unmatchedTransfers;
        if (!Array.isArray(transactions) || transactions.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No unmatched transfers')}</div>`;
            return;
        }

        container.innerHTML = transactions.slice(0, 5).map(tx => `
            <div class="widget-list-item">
                <div class="widget-item-info">
                    <div class="widget-item-name">${this.escapeHtml(tx.vendor || tx.description || t('budget', 'Unknown'))}</div>
                    <div class="widget-item-meta">${formatters.formatDate(tx.date, this.settings)} · ${this.formatCurrency(tx.amount)}</div>
                </div>
            </div>
        `).join('');
    }

    updateCategoryTrendsWidget() {
        const container = document.getElementById('category-trends-content');
        if (!container) return;

        const data = this.widgetData.categoryTrends;
        if (!data || !Array.isArray(data) || data.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No category trends available')}</div>`;
            return;
        }

        container.innerHTML = data.slice(0, 5).map(cat => {
            const changePercent = cat.previousTotal > 0
                ? ((cat.currentTotal - cat.previousTotal) / cat.previousTotal * 100).toFixed(1)
                : 0;
            const arrow = changePercent >= 0 ? '↑' : '↓';

            return `
                <div class="widget-list-item">
                    <div class="widget-item-info">
                        <span class="category-color" style="background-color: ${cat.color || '#3b82f6'}; width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 6px;"></span>
                        <div class="widget-item-name">${this.escapeHtml(cat.name)}</div>
                    </div>
                    <div class="widget-item-amount">
                        ${this.formatCurrency(cat.currentTotal)}
                        <span class="comparison-change ${changePercent <= 0 ? 'positive' : 'negative'}" style="font-size: 0.8em; margin-left: 4px;">${arrow} ${Math.abs(changePercent)}%</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    updateBillsDueSoonWidget() {
        const container = document.getElementById('bills-due-soon-list');
        if (!container) return;

        const bills = this.widgetData.billsDueSoon;
        if (!Array.isArray(bills) || bills.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No bills due soon')}</div>`;
            return;
        }

        const todayStr = formatters.getTodayDateString();

        container.innerHTML = bills.slice(0, 5).map(bill => {
            const dueDateStr = bill.nextDueDate || bill.next_due_date;
            const daysUntilDue = formatters.daysBetweenDates(todayStr, dueDateStr);

            let statusClass = '';
            let dueText = '';
            if (daysUntilDue < 0) {
                statusClass = 'overdue';
                dueText = n('budget', 'Overdue by %n day', 'Overdue by %n days', Math.abs(daysUntilDue));
            } else if (daysUntilDue === 0) {
                statusClass = 'due-soon';
                dueText = t('budget', 'Due today');
            } else {
                statusClass = daysUntilDue <= 7 ? 'due-soon' : '';
                dueText = n('budget', 'Due in %n day', 'Due in %n days', daysUntilDue);
            }

            return `
                <div class="bill-widget-item ${statusClass}">
                    <div class="bill-widget-info">
                        <div class="bill-widget-name">${this.escapeHtml(bill.name)}</div>
                        <div class="bill-widget-due ${statusClass}">${dueText}</div>
                    </div>
                    <div class="bill-widget-amount">${this.formatCurrency(bill.amount)}</div>
                </div>
            `;
        }).join('');
    }

    updateIncomeTrackingWidget() {
        const container = document.getElementById('income-tracking-content');
        if (!container) return;

        const data = this.widgetData.incomeTracking;
        if (!data || (!Array.isArray(data) && !data.incomes)) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No recurring income set up')}</div>`;
            return;
        }

        const incomes = Array.isArray(data) ? data : (data.incomes || []);
        if (incomes.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No recurring income set up')}</div>`;
            return;
        }

        container.innerHTML = incomes.slice(0, 5).map(income => `
            <div class="widget-list-item">
                <div class="widget-item-info">
                    <div class="widget-item-name">${this.escapeHtml(income.name)}</div>
                    <div class="widget-item-meta">${income.frequency || 'monthly'}</div>
                </div>
                <div class="widget-item-amount positive">${this.formatCurrency(income.amount)}</div>
            </div>
        `).join('');
    }

    updateRecentImportsWidget() {
        const container = document.getElementById('recent-imports-list');
        if (!container) return;

        const data = this.widgetData.recentImports;
        if (!Array.isArray(data) || data.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No recent imports')}</div>`;
            return;
        }

        container.innerHTML = data.slice(0, 5).map(imp => {
            const accountName = imp.account_name || t('budget', 'Unknown');
            const count = parseInt(imp.count) || 0;
            const importedAt = imp.imported_at ? formatters.formatDate(imp.imported_at.split(' ')[0], this.settings) : '';

            return `
                <div class="widget-list-item">
                    <div class="widget-item-info">
                        <div class="widget-item-name">${this.escapeHtml(accountName)}</div>
                        <div class="widget-item-meta">${n('budget', '%n transaction', '%n transactions', count)}${importedAt ? ` · ${importedAt}` : ''}</div>
                    </div>
                </div>
            `;
        }).join('');
    }

    updateRuleEffectivenessWidget() {
        const container = document.getElementById('rule-effectiveness-content');
        if (!container) return;

        const rules = this.widgetData.ruleEffectiveness;
        if (!Array.isArray(rules) || rules.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No import rules configured')}</div>`;
            return;
        }

        const activeRules = rules.filter(r => r.isActive !== false);
        container.innerHTML = `
            <div class="widget-stat">
                <div class="widget-stat-value">${activeRules.length}</div>
                <div class="widget-stat-label">${t('budget', 'Active rules')}</div>
            </div>
            <div class="widget-stat">
                <div class="widget-stat-value">${rules.length}</div>
                <div class="widget-stat-label">${t('budget', 'Total rules')}</div>
            </div>
        `;
    }

    updateSpendingVelocityWidget() {
        const container = document.getElementById('spending-velocity-content');
        if (!container) return;

        const data = this.widgetData.spendingVelocity;
        if (!data) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No spending data available')}</div>`;
            return;
        }

        const dailyRate = parseFloat(data.dailyRate || 0);
        const projectedMonthly = dailyRate * 30;

        container.innerHTML = `
            <div class="widget-stat">
                <div class="widget-stat-value">${this.formatCurrency(dailyRate)}</div>
                <div class="widget-stat-label">${t('budget', 'Per day')}</div>
            </div>
            <div class="widget-stat">
                <div class="widget-stat-value">${this.formatCurrency(projectedMonthly)}</div>
                <div class="widget-stat-label">${t('budget', 'Projected monthly')}</div>
            </div>
        `;
    }

    updateUncategorizedCountWidget() {
        const container = document.getElementById('uncategorized-count-content');
        if (!container) return;

        const transactions = this.widgetData.uncategorizedCount;
        const count = Array.isArray(transactions) ? transactions.length : 0;

        container.innerHTML = `
            <div class="widget-stat">
                <div class="widget-stat-value">${count}</div>
                <div class="widget-stat-label">${t('budget', 'Uncategorized transactions')}</div>
            </div>
        `;
    }

    // ===========================
    // Chart Updates
    // ===========================

    updateSpendingChart(spending, instanceId = 'spendingChart') {
        let canvas;
        if (this.isDuplicateInstance(instanceId)) {
            const card = this.getWidgetCard(instanceId);
            canvas = card ? card.querySelector('canvas') : null;
        } else {
            canvas = document.getElementById('spending-chart');
        }
        if (!canvas) return;

        // Destroy existing chart
        if (this.charts[instanceId]) {
            this.charts[instanceId].destroy();
        }

        // Handle both object and array formats
        let spendingData;
        if (Array.isArray(spending)) {
            if (spending.length === 0) return;
            spendingData = spending;
        } else if (typeof spending === 'object') {
            const entries = Object.entries(spending);
            if (entries.length === 0) return;
            spendingData = entries.map(([categoryId, amount]) => ({ categoryId: parseInt(categoryId), amount }));
        } else {
            return;
        }

        // Aggregate to top-level categories if setting is enabled
        const spendingSettings = this.dashboardConfig.widgets?.tileSettings?.[instanceId] || {};
        if (spendingSettings.topLevelOnly) {
            spendingData = this.aggregateToTopLevel(spendingData);
        }

        const ctx = canvas.getContext('2d');

        // Sort by absolute amount and take top 10
        const sortedData = spendingData
            .sort((a, b) => Math.abs(b.total || b.amount || 0) - Math.abs(a.total || a.amount || 0))
            .slice(0, 10);

        const labels = sortedData.map(item => item.name || t('budget', 'Unknown'));
        const data = sortedData.map(item => Math.abs(item.total || item.amount || 0));
        const colors = sortedData.map(item => item.color || '#999');

        const spendingChartType = spendingSettings.chartType || 'doughnut';
        const isSpendingBar = spendingChartType === 'bar';
        this.charts[instanceId] = new Chart(ctx, {
            type: isSpendingBar ? 'bar' : 'doughnut',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: colors,
                    ...(isSpendingBar ? { borderRadius: 4 } : {})
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                ...(isSpendingBar ? { indexAxis: 'y' } : {}),
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.label}: ${this.formatCurrency(context.raw)}`
                        }
                    }
                },
                layout: { padding: 10 }
            }
        });

        // Populate custom legend
        let legendContainer;
        if (this.isDuplicateInstance(instanceId)) {
            const card = this.getWidgetCard(instanceId);
            legendContainer = card ? card.querySelector('.spending-legend') : null;
        } else {
            legendContainer = document.getElementById('spending-chart-legend');
        }
        if (legendContainer) {
            const totalSpending = data.reduce((sum, val) => sum + val, 0);
            legendContainer.innerHTML = `
                <div class="spending-breakdown">
                    <div class="spending-breakdown-header">
                        <strong>${t('budget', 'Total Spending')}</strong>
                        <strong>${this.formatCurrency(totalSpending)}</strong>
                    </div>
                    ${sortedData.map((item, index) => {
                        const amount = data[index];
                        const percentage = totalSpending > 0 ? ((amount / totalSpending) * 100).toFixed(1) : 0;
                        return `
                            <div class="spending-breakdown-item">
                                <div class="spending-breakdown-label">
                                    <span class="spending-dot" style="background: ${colors[index]}"></span>
                                    <span class="spending-category-name">${this.escapeHtml(labels[index])}</span>
                                </div>
                                <div class="spending-breakdown-values">
                                    <span class="spending-percentage">${percentage}%</span>
                                    <span class="spending-amount">${this.formatCurrency(amount)}</span>
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        }
    }

    updateTrendChart(trends, instanceId = 'trendChart') {
        let canvas;
        if (this.isDuplicateInstance(instanceId)) {
            const card = this.getWidgetCard(instanceId);
            canvas = card ? card.querySelector('canvas') : null;
        } else {
            canvas = document.getElementById('trend-chart');
        }
        if (!canvas) return;

        if (this.charts[instanceId]) {
            this.charts[instanceId].destroy();
        }

        if (!trends || !trends.labels || trends.labels.length === 0) return;

        const ctx = canvas.getContext('2d');
        const trendSettings = this.dashboardConfig.widgets?.tileSettings?.[instanceId] || {};
        const trendChartType = trendSettings.chartType || 'line';
        const isBar = trendChartType === 'bar';
        const trendShowLegend = trendSettings.showLegend !== false;
        this.charts[instanceId] = new Chart(ctx, {
            type: isBar ? 'bar' : 'line',
            data: {
                labels: trends.labels,
                datasets: [
                    {
                        label: t('budget', 'Income'),
                        data: trends.income || [],
                        borderColor: '#46ba61',
                        backgroundColor: isBar ? 'rgba(70, 186, 97, 0.6)' : 'rgba(70, 186, 97, 0.1)',
                        fill: !isBar,
                        tension: isBar ? 0 : 0.3
                    },
                    {
                        label: t('budget', 'Expenses'),
                        data: trends.expenses || [],
                        borderColor: '#e9322d',
                        backgroundColor: isBar ? 'rgba(233, 50, 45, 0.6)' : 'rgba(233, 50, 45, 0.1)',
                        fill: !isBar,
                        tension: isBar ? 0 : 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: trendShowLegend, position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${this.formatCurrency(context.raw)}`
                        }
                    }
                },
                scales: {
                    y: { ticks: { callback: (value) => this.formatCurrency(value) } }
                }
            }
        });
    }

    updateNetWorthHistoryChart(data, accountId = null, instanceId = 'netWorthHistory') {
        let canvas, emptyState, statusEl;
        if (this.isDuplicateInstance(instanceId)) {
            const card = this.getWidgetCard(instanceId);
            canvas = card ? card.querySelector('canvas') : null;
            emptyState = card ? card.querySelector('.chart-empty-state') : null;
            statusEl = card ? card.querySelector('.net-worth-status') : null;
        } else {
            canvas = document.getElementById('net-worth-chart');
            emptyState = document.getElementById('net-worth-chart-empty');
            statusEl = document.getElementById('net-worth-snapshot-status');
        }
        if (!canvas) return;

        if (this.charts[instanceId]) {
            this.charts[instanceId].destroy();
        }

        if (!data || data.length === 0) {
            canvas.style.display = 'none';
            if (emptyState) emptyState.style.display = 'block';
            if (statusEl) statusEl.style.display = 'none';
            return;
        }

        canvas.style.display = 'block';
        if (emptyState) emptyState.style.display = 'none';

        const currency = this.getPrimaryCurrency();
        const labels = data.map(s => s.date);

        let datasets;
        if (accountId) {
            if (statusEl) statusEl.style.display = 'none';
            datasets = [{
                label: t('budget', 'Balance'),
                data: data.map(s => s.balance),
                borderColor: '#0082c9',
                backgroundColor: 'rgba(0, 130, 201, 0.1)',
                fill: true, tension: 0.3, borderWidth: 2
            }];
        } else {
            this.updateNetWorthStatus(data, statusEl);
            datasets = [
                {
                    label: t('budget', 'Net Worth'),
                    data: data.map(s => s.netWorth),
                    borderColor: '#46ba61',
                    backgroundColor: 'rgba(70, 186, 97, 0.1)',
                    fill: true, tension: 0.3, borderWidth: 2
                },
                {
                    label: t('budget', 'Assets'),
                    data: data.map(s => s.totalAssets),
                    borderColor: '#0082c9',
                    borderDash: [5, 5],
                    fill: false, tension: 0.3, borderWidth: 1.5
                },
                {
                    label: t('budget', 'Liabilities'),
                    data: data.map(s => s.totalLiabilities),
                    borderColor: '#e9322d',
                    borderDash: [5, 5],
                    fill: false, tension: 0.3, borderWidth: 1.5
                }
            ];
        }

        this.charts[instanceId] = new Chart(canvas, {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 15
                        }
                    },
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
                        ticks: {
                            callback: (value) => this.formatCurrency(value, currency)
                        }
                    },
                    x: {
                        ticks: {
                            maxTicksLimit: 8
                        }
                    }
                }
            }
        });
    }

    updateAssetValueHistoryChart(data, instanceId = 'assetValueHistory') {
        let canvas, emptyState;
        if (this.isDuplicateInstance(instanceId)) {
            const card = this.getWidgetCard(instanceId);
            canvas = card ? card.querySelector('canvas') : null;
            emptyState = card ? card.querySelector('.chart-empty-state') : null;
        } else {
            canvas = document.getElementById('asset-value-history-chart');
            emptyState = document.getElementById('asset-value-chart-empty');
        }
        if (!canvas) return;

        if (this.charts[instanceId]) {
            this.charts[instanceId].destroy();
        }

        const history = data?.history || [];

        if (history.length === 0) {
            canvas.style.display = 'none';
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        canvas.style.display = 'block';
        if (emptyState) emptyState.style.display = 'none';

        const currency = data.baseCurrency || this.getPrimaryCurrency();
        const labels = history.map(p => p.date);
        const values = history.map(p => p.totalValue);

        this.charts[instanceId] = new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: t('budget', 'Asset Value'),
                    data: values,
                    borderColor: '#ff9800',
                    backgroundColor: 'rgba(255, 152, 0, 0.1)',
                    fill: true,
                    tension: 0.3,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                return `${t('budget', 'Portfolio')}: ${this.formatCurrency(context.raw, currency)}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: (value) => this.formatCurrency(value, currency)
                        }
                    },
                    x: {
                        ticks: {
                            maxTicksLimit: 8
                        }
                    }
                }
            }
        });
    }

    async refreshAssetValueChart(days, instanceId = 'assetValueHistory') {
        try {
            const response = await fetch(
                OC.generateUrl(`/apps/budget/api/assets/value-history?days=${days}`),
                { headers: { 'requesttoken': OC.requestToken } }
            );
            if (!response.ok) throw new Error('Failed to fetch asset value history');
            const data = await response.json();
            this.updateAssetValueHistoryChart(data, instanceId);
        } catch (error) {
            console.error('Failed to refresh asset value chart:', error);
        }
    }

    updateNetWorthStatus(snapshots, statusEl) {
        if (!statusEl) return;

        // Find the most recent automatic snapshot
        const autoSnapshots = snapshots.filter(s => s.source === 'auto');
        const lastAutoSnapshot = autoSnapshots.length > 0 ? autoSnapshots[autoSnapshots.length - 1] : null;

        // Build status message
        let statusHTML = '<div class="net-worth-status-content">';
        statusHTML += '<span class="status-icon">📊</span>';

        if (lastAutoSnapshot) {
            const lastDate = new Date(lastAutoSnapshot.date);
            const now = new Date();
            const hoursAgo = Math.floor((now - lastDate) / (1000 * 60 * 60));
            const daysAgo = Math.floor(hoursAgo / 24);

            let timeAgoText;
            if (daysAgo === 0) {
                if (hoursAgo === 0) {
                    timeAgoText = t('budget', 'just now');
                } else {
                    timeAgoText = n('budget', '%n hour ago', '%n hours ago', hoursAgo);
                }
            } else if (daysAgo === 1) {
                timeAgoText = t('budget', 'yesterday');
            } else {
                timeAgoText = n('budget', '%n day ago', '%n days ago', daysAgo);
            }

            statusHTML += `<span class="status-text">${t('budget', 'Snapshots recorded automatically daily')} • ${t('budget', 'Last: {time}', { time: timeAgoText })}</span>`;
        } else {
            statusHTML += `<span class="status-text">${t('budget', 'Snapshots recorded automatically daily')}</span>`;
        }

        statusHTML += `<button id="record-net-worth-btn-inline" class="btn-link-small">${t('budget', 'Record now')}</button>`;
        statusHTML += '</div>';

        statusEl.innerHTML = statusHTML;
        statusEl.style.display = 'block';

        // Wire up inline record button
        const inlineBtn = document.getElementById('record-net-worth-btn-inline');
        if (inlineBtn && !inlineBtn.hasAttribute('data-initialized')) {
            inlineBtn.setAttribute('data-initialized', 'true');
            inlineBtn.addEventListener('click', async () => {
                await this.recordNetWorthSnapshot();
            });
        }
    }

    // ===========================
    // Dashboard Controls
    // ===========================

    setupDashboardControls() {
        // Trend account selector
        const trendAccountSelect = document.getElementById('trend-account-select');
        if (trendAccountSelect && !trendAccountSelect.hasAttribute('data-initialized')) {
            trendAccountSelect.setAttribute('data-initialized', 'true');
            trendAccountSelect.addEventListener('change', async () => {
                const periodSelect = document.getElementById('trend-period-select');
                const months = periodSelect ? parseInt(periodSelect.value) : 6;
                const accountId = trendAccountSelect.value || null;
                await this.refreshTrendChart(months, accountId);
                await this.saveWidgetAccountSelection('trend-account-select', trendAccountSelect.value);
            });
        }

        // Trend period selector
        const trendPeriodSelect = document.getElementById('trend-period-select');
        if (trendPeriodSelect && !trendPeriodSelect.hasAttribute('data-initialized')) {
            trendPeriodSelect.setAttribute('data-initialized', 'true');
            trendPeriodSelect.addEventListener('change', async (e) => {
                const months = parseInt(e.target.value);
                const accountSelect = document.getElementById('trend-account-select');
                const accountId = accountSelect ? (accountSelect.value || null) : null;
                await this.refreshTrendChart(months, accountId);
            });
        }

        // Spending account selector
        const spendingAccountSelect = document.getElementById('spending-account-select');
        if (spendingAccountSelect && !spendingAccountSelect.hasAttribute('data-initialized')) {
            spendingAccountSelect.setAttribute('data-initialized', 'true');
            spendingAccountSelect.addEventListener('change', async () => {
                const periodSelect = document.getElementById('spending-period-select');
                const period = periodSelect ? periodSelect.value : 'month';
                const accountId = spendingAccountSelect.value || null;
                await this.refreshSpendingChart(period, accountId);
                await this.saveWidgetAccountSelection('spending-account-select', spendingAccountSelect.value);
            });
        }

        // Spending period selector
        const spendingPeriodSelect = document.getElementById('spending-period-select');
        if (spendingPeriodSelect && !spendingPeriodSelect.hasAttribute('data-initialized')) {
            spendingPeriodSelect.setAttribute('data-initialized', 'true');
            spendingPeriodSelect.addEventListener('change', async (e) => {
                const period = e.target.value;
                const accountSelect = document.getElementById('spending-account-select');
                const accountId = accountSelect ? (accountSelect.value || null) : null;
                await this.refreshSpendingChart(period, accountId);
            });
        }

        // Net Worth account selector
        const netWorthAccountSelect = document.getElementById('net-worth-account-select');
        if (netWorthAccountSelect && !netWorthAccountSelect.hasAttribute('data-initialized')) {
            netWorthAccountSelect.setAttribute('data-initialized', 'true');
            netWorthAccountSelect.addEventListener('change', async () => {
                const activeBtn = document.querySelector('#net-worth-period-selector .period-btn.active');
                const days = activeBtn ? parseInt(activeBtn.dataset.days) : 30;
                const accountId = netWorthAccountSelect.value || null;
                await this.refreshNetWorthChart(days, accountId);
                await this.saveWidgetAccountSelection('net-worth-account-select', netWorthAccountSelect.value);
            });
        }

        // Net Worth period selector
        const netWorthPeriodSelector = document.getElementById('net-worth-period-selector');
        if (netWorthPeriodSelector && !netWorthPeriodSelector.hasAttribute('data-initialized')) {
            netWorthPeriodSelector.setAttribute('data-initialized', 'true');
            netWorthPeriodSelector.addEventListener('click', async (e) => {
                if (e.target.classList.contains('period-btn')) {
                    // Update active button
                    netWorthPeriodSelector.querySelectorAll('.period-btn').forEach(btn => btn.classList.remove('active'));
                    e.target.classList.add('active');
                    // Refresh chart with new period, respecting account filter
                    const days = parseInt(e.target.dataset.days);
                    const accountSelect = document.getElementById('net-worth-account-select');
                    const accountId = accountSelect ? (accountSelect.value || null) : null;
                    await this.refreshNetWorthChart(days, accountId);
                }
            });
        }

        // Asset Value period selector
        const assetValuePeriodSelector = document.getElementById('asset-value-period-selector');
        if (assetValuePeriodSelector && !assetValuePeriodSelector.hasAttribute('data-initialized')) {
            assetValuePeriodSelector.setAttribute('data-initialized', 'true');
            assetValuePeriodSelector.addEventListener('click', async (e) => {
                if (e.target.classList.contains('period-btn')) {
                    assetValuePeriodSelector.querySelectorAll('.period-btn').forEach(btn => btn.classList.remove('active'));
                    e.target.classList.add('active');
                    const days = parseInt(e.target.dataset.days);
                    await this.refreshAssetValueChart(days);
                }
            });
        }

        // Record Net Worth Snapshot button
        const recordNetWorthBtn = document.getElementById('record-net-worth-btn');
        if (recordNetWorthBtn && !recordNetWorthBtn.hasAttribute('data-initialized')) {
            recordNetWorthBtn.setAttribute('data-initialized', 'true');
            recordNetWorthBtn.addEventListener('click', async () => {
                await this.recordNetWorthSnapshot();
            });
        }

        // Recent Transactions account selector
        const recentTxAccountSelect = document.getElementById('recent-transactions-account-select');
        if (recentTxAccountSelect && !recentTxAccountSelect.hasAttribute('data-initialized')) {
            recentTxAccountSelect.setAttribute('data-initialized', 'true');
            recentTxAccountSelect.addEventListener('change', async () => {
                const accountId = recentTxAccountSelect.value || null;
                await this.refreshRecentTransactions(accountId);
                await this.saveWidgetAccountSelection('recent-transactions-account-select', recentTxAccountSelect.value);
            });
        }

        // Per-account hero tile selectors
        ['hero-account-income-select', 'hero-account-expenses-select'].forEach(selectId => {
            const select = document.getElementById(selectId);
            if (select && !select.hasAttribute('data-initialized')) {
                select.setAttribute('data-initialized', 'true');
                select.addEventListener('change', () => {
                    if (selectId.includes('income')) {
                        this.updateAccountIncomeHero();
                    } else {
                        this.updateAccountExpensesHero();
                    }
                    this.saveHeroAccountSelection(selectId, select.value);
                });
            }
        });
    }

    async refreshTrendChart(months, accountId = null, instanceId = 'trendChart') {
        try {
            const startDate = new Date();
            startDate.setMonth(startDate.getMonth() - months);

            let url = `/apps/budget/api/reports/summary?startDate=${formatters.formatDateForAPI(startDate)}`;
            if (accountId) {
                url += `&accountId=${accountId}`;
            }

            const response = await fetch(
                OC.generateUrl(url),
                { headers: { 'requesttoken': OC.requestToken } }
            );
            const data = await response.json();

            if (data.trends) {
                this.updateTrendChart(data.trends, instanceId);
            }
        } catch (error) {
            console.error('Failed to refresh trend chart:', error);
        }
    }

    async refreshSpendingChart(period, accountId = null, instanceId = 'spendingChart') {
        try {
            let startDate = new Date();
            const endDate = new Date();

            switch (period) {
                case 'month':
                    startDate = new Date(endDate.getFullYear(), endDate.getMonth(), 1);
                    break;
                case '3months':
                    startDate.setMonth(startDate.getMonth() - 3);
                    break;
                case '6months':
                    startDate.setMonth(startDate.getMonth() - 6);
                    break;
                case 'year':
                    startDate = new Date(endDate.getFullYear(), 0, 1);
                    break;
            }

            let url = `/apps/budget/api/reports/spending?startDate=${formatters.formatDateForAPI(startDate)}&endDate=${formatters.formatDateForAPI(endDate)}`;
            if (accountId) {
                url += `&accountId=${accountId}`;
            }

            const response = await fetch(
                OC.generateUrl(url),
                { headers: { 'requesttoken': OC.requestToken } }
            );
            const data = await response.json();

            if (data.data) {
                this.updateSpendingChart(data.data, instanceId);
            }
        } catch (error) {
            console.error('Failed to refresh spending chart:', error);
        }
    }

    async refreshNetWorthChart(days, accountId = null, instanceId = 'netWorthHistory') {
        try {
            if (accountId) {
                const response = await fetch(
                    OC.generateUrl(`/apps/budget/api/accounts/${accountId}/balance-history?days=${days}`),
                    { headers: { 'requesttoken': OC.requestToken } }
                );
                if (!response.ok) throw new Error('Failed to fetch balance history');
                const history = await response.json();
                this.updateNetWorthHistoryChart(history, accountId, instanceId);
            } else {
                const response = await fetch(
                    OC.generateUrl(`/apps/budget/api/net-worth/snapshots?days=${days}`),
                    { headers: { 'requesttoken': OC.requestToken } }
                );
                if (!response.ok) throw new Error('Failed to fetch net worth snapshots');
                const snapshots = await response.json();
                this.updateNetWorthHistoryChart(snapshots, null, instanceId);
            }
        } catch (error) {
            console.error('Failed to refresh net worth chart:', error);
        }
    }

    async refreshRecentTransactions(accountId = null, instanceId = 'recentTransactions') {
        try {
            let url = '/apps/budget/api/transactions?limit=8';
            if (accountId) {
                url += `&accountId=${accountId}`;
            }
            const response = await fetch(
                OC.generateUrl(url),
                { headers: { 'requesttoken': OC.requestToken } }
            );
            if (!response.ok) throw new Error('Failed to fetch recent transactions');
            const data = await response.json();
            this.updateRecentTransactions(data.transactions || data, instanceId);
        } catch (error) {
            console.error('Failed to refresh recent transactions:', error);
        }
    }

    /** Refresh any duplicable widget instance using its per-instance settings */
    async refreshWidgetInstance(instanceId) {
        const baseType = this.getWidgetType(instanceId);
        const settings = this.dashboardConfig.widgets?.tileSettings?.[instanceId] || {};
        const dateRange = settings.dateRange || this._defaultDateRange(baseType);

        // Convert dateRange setting to parameters
        const dateRangeMap = { '30d': 30, '90d': 90, '6m': 180, '1y': 365 };
        const days = dateRangeMap[dateRange] || 180;
        const monthsMap = { '30d': 1, '90d': 3, '6m': 6, '1y': 12 };
        const months = monthsMap[dateRange] || 6;
        const periodMap = { '30d': 'month', '90d': '3months', '6m': '6months', '1y': 'year' };
        const period = periodMap[dateRange] || '6months';

        switch (baseType) {
            case 'trendChart':
                return this.refreshTrendChart(months, settings.accountId || null, instanceId);
            case 'spendingChart':
                return this.refreshSpendingChart(period, settings.accountId || null, instanceId);
            case 'netWorthHistory':
                return this.refreshNetWorthChart(days, settings.accountId || null, instanceId);
            case 'assetValueHistory':
                return this.refreshAssetValueChart(days, instanceId);
            case 'recentTransactions':
                return this.refreshRecentTransactions(settings.accountId || null, instanceId);
        }
    }

    async recordNetWorthSnapshot() {
        try {
            const response = await fetch(
                OC.generateUrl('/apps/budget/api/net-worth/snapshots'),
                {
                    method: 'POST',
                    headers: {
                        'requesttoken': OC.requestToken,
                        'Content-Type': 'application/json'
                    }
                }
            );
            if (!response.ok) throw new Error('Failed to record snapshot');

            showSuccess(t('budget', 'Net worth snapshot recorded'));

            // Refresh the chart with current period
            const activeBtn = document.querySelector('#net-worth-period-selector .period-btn.active');
            const days = activeBtn ? parseInt(activeBtn.dataset.days) : 30;
            await this.refreshNetWorthChart(days);
        } catch (error) {
            console.error('Failed to record net worth snapshot:', error);
            showError(t('budget', 'Failed to record snapshot'));
        }
    }

    // ===========================
    // Dashboard Customization
    // ===========================

    parseDashboardConfig(settingValue, category) {
        // Get defaults from widget registry
        const widgets = DASHBOARD_WIDGETS[category];
        const defaults = {
            order: Object.keys(widgets),
            visibility: Object.keys(widgets).reduce((acc, key) => {
                acc[key] = widgets[key].defaultVisible;
                return acc;
            }, {})
        };

        if (!settingValue) return defaults;

        try {
            const saved = JSON.parse(settingValue);
            if (!saved || typeof saved !== 'object') return defaults;

            const savedOrder = Array.isArray(saved.order) ? saved.order : [];
            const savedVisibility = saved.visibility || {};

            // Merge: preserve user settings, add any new widgets from defaults
            const allWidgetIds = new Set([...savedOrder, ...defaults.order]);
            const mergedOrder = savedOrder.filter(id => allWidgetIds.has(id));

            // Append any new widgets that aren't in saved order
            defaults.order.forEach(id => {
                if (!mergedOrder.includes(id)) {
                    mergedOrder.push(id);
                }
            });

            const mergedVisibility = { ...defaults.visibility, ...savedVisibility };


            const config = {
                order: mergedOrder,
                visibility: mergedVisibility,
                settings: saved.settings || {}
            };

            // Backward compat: add sizes if missing
            if (!config.sizes) {
                config.sizes = saved.sizes || {};
            }

            // Backward compat: add tileSettings if missing
            if (!config.tileSettings) {
                config.tileSettings = saved.tileSettings || {};
            }

            // Restore duplicate tile instances
            if (!config.instances) {
                config.instances = saved.instances || {};
            }

            // Restore positions for Gridstack
            if (!config.positions) {
                config.positions = saved.positions || {};
            }

            return config;
        } catch (e) {
            console.error('Failed to parse dashboard config', e);
            return defaults;
        }
    }

    async applyDashboardVisibility() {
        // Apply hero visibility (with lazy loading for Phase 2+)
        for (const [key, visible] of Object.entries(this.dashboardConfig.hero.visibility)) {
            const widget = DASHBOARD_WIDGETS.hero[key];
            if (!widget) continue;

            const element = document.querySelector(`[data-widget-id="${key}"][data-widget-category="hero"]`);
            if (!element) continue;

            // Lazy load data if becoming visible and not yet loaded
            if (visible && this.app.needsLazyLoad(key) && !this.widgetDataLoaded[key]) {
                await this.app.loadWidgetData(key);
                // Call the appropriate update method
                const updateMethod = `update${key.charAt(0).toUpperCase() + key.slice(1)}Hero`;
                if (typeof this[updateMethod] === 'function') {
                    this[updateMethod]();
                }
            }

            element.style.display = visible ? '' : 'none';
        }

        // Apply widget visibility (with lazy loading for Phase 2+)
        for (const [key, visible] of Object.entries(this.dashboardConfig.widgets.visibility)) {
            // Resolve base widget type for instances (e.g., trendChart__2 → trendChart)
            const baseType = this.getWidgetType(key);
            const widget = DASHBOARD_WIDGETS.widgets[baseType];
            if (!widget && !this.isDuplicateInstance(key)) continue;

            const element = document.querySelector(`[data-widget-id="${key}"][data-widget-category="widget"]`);
            if (!element) continue;

            // Initialize Quick Add form when it becomes visible (Phase 4)
            if (visible && key === 'quickAdd' && !this.widgetDataLoaded[key]) {
                this.app.initQuickAddForm();
                this.widgetDataLoaded[key] = true;
            }

            // Lazy load data if becoming visible and not yet loaded
            if (visible && !this.isDuplicateInstance(key) && this.app.needsLazyLoad(key) && !this.widgetDataLoaded[key]) {
                await this.app.loadWidgetData(key);
                const updateMethod = `update${key.charAt(0).toUpperCase() + key.slice(1)}Widget`;
                if (typeof this[updateMethod] === 'function') {
                    this[updateMethod]();
                }
            }

            // Respect conditional widgets — don't override display:none set by data logic
            const conditionalTiles = ['budgetAlerts', 'debtPayoff', 'debtChart', 'debtProgress'];
            if (visible) {
                const isConditionallyHidden = conditionalTiles.includes(key) &&
                    element.style.display === 'none';
                if (!isConditionallyHidden) {
                    element.style.display = '';
                    const wrapper = element.closest('.grid-stack-item');
                    if (wrapper) wrapper.style.display = '';
                }
            } else {
                element.style.display = 'none';
                const wrapper = element.closest('.grid-stack-item');
                if (wrapper) wrapper.style.display = 'none';
            }
        }
    }

    async hideWidget(widgetId, category) {
        const config = category === 'hero' ? this.dashboardConfig.hero : this.dashboardConfig.widgets;
        config.visibility[widgetId] = false;

        if (category !== 'hero' && this.gridstack) {
            const wrapper = document.querySelector(`[gs-id="${widgetId}"]`);
            if (wrapper) {
                this.gridstack.removeWidget(wrapper, false);
                const stash = document.getElementById('hidden-widgets');
                if (stash) {
                    wrapper.style.display = 'none';
                    stash.appendChild(wrapper);
                }
            }
        } else {
            const element = document.querySelector(`[data-widget-id="${widgetId}"]`);
            if (element) element.style.display = 'none';
        }

        this.updateAddTilesMenu();
        await this.saveDashboardVisibility();
    }

    async showWidget(widgetId, category) {
        const config = category === 'hero' ? this.dashboardConfig.hero : this.dashboardConfig.widgets;
        config.visibility[widgetId] = true;

        if (category === 'hero') {
            const element = document.querySelector(`[data-widget-id="${widgetId}"]`);
            if (element) element.style.display = '';
        } else if (this.gridstack) {
            const gridEl = document.querySelector('.dashboard-grid');
            let wrapper = document.querySelector(`[gs-id="${widgetId}"]`);

            if (wrapper) {
                const size = this.getWidgetSize(widgetId, 'widgets');
                const mapped = GRIDSTACK_SIZE_MAP[size] || { w: 1, h: 4 };
                const w = size === 'l' ? (this.gridColumns || 3) : mapped.w;

                // Clear stale Gridstack data
                delete wrapper.gridstackNode;
                wrapper.removeAttribute('gs-x');
                wrapper.removeAttribute('gs-y');
                wrapper.removeAttribute('gs-w');
                wrapper.removeAttribute('gs-h');
                wrapper.style.display = '';

                // Ensure card inside is visible
                const card = wrapper.querySelector('.dashboard-card');
                if (card) card.style.display = '';

                // Remove from stash (don't append to grid — let addWidget handle it)
                if (wrapper.parentElement) {
                    wrapper.parentElement.removeChild(wrapper);
                }

                // Let Gridstack add it to the grid and register it
                this.gridstack.addWidget({ el: wrapper, w, h: mapped.h, autoPosition: true });
            }
        }

        if (!this.dashboardLocked) {
            this.addTileControls();
        }

        this.updateAddTilesMenu();
        await this.saveDashboardVisibility();
    }

    async saveDashboardVisibility() {
        try {
            await this._saveSettings({
                dashboard_hero_config: JSON.stringify(this.dashboardConfig.hero),
                dashboard_widgets_config: JSON.stringify(this.dashboardConfig.widgets)
            });
        } catch (error) {
            console.error('Failed to save dashboard config:', error);
            showError(t('budget', 'Failed to save dashboard layout'));
        }
    }

    // ===========================
    // Widget Instance Templates
    // ===========================

    /** Create DOM for a widget instance. Returns the card element. */
    createWidgetDOM(instanceId) {
        const widgetType = this.getWidgetType(instanceId);
        const widgetDef = DASHBOARD_WIDGETS.widgets[widgetType];
        if (!widgetDef) return null;

        const size = this.getWidgetSize(instanceId, 'widgets');
        const templateFn = this._widgetTemplates[widgetType];
        if (!templateFn) return null;

        const card = document.createElement('div');
        card.className = `dashboard-card dashboard-tile-${size}`;
        card.setAttribute('data-widget-id', instanceId);
        card.setAttribute('data-widget-category', 'widget');
        card.innerHTML = templateFn.call(this, instanceId, widgetDef);

        return card;
    }

    get _widgetTemplates() {
        return {
            trendChart: (instanceId, def) => `
                <div class="card-header">
                    <h3>${def.name}</h3>
                    <div class="card-header-controls"></div>
                </div>
                <div class="chart-container">
                    <canvas></canvas>
                </div>
                <div class="chart-legend"></div>
            `,
            spendingChart: (instanceId, def) => `
                <div class="card-header">
                    <h3>${def.name}</h3>
                    <div class="card-header-controls"></div>
                </div>
                <div class="spending-chart-wrapper">
                    <div class="chart-container chart-container-doughnut">
                        <canvas></canvas>
                    </div>
                    <div class="spending-legend"></div>
                </div>
            `,
            netWorthHistory: (instanceId, def) => `
                <div class="card-header">
                    <h3>${def.name}</h3>
                    <div class="card-header-controls"></div>
                </div>
                <div class="net-worth-status" style="display:none;"></div>
                <div class="chart-container">
                    <canvas></canvas>
                </div>
                <div class="chart-empty-state" style="display: none;">
                    <div class="empty-state-content">
                        <p class="empty-state-title">${t('budget', 'No net worth history yet')}</p>
                        <p class="empty-state-subtitle">${t('budget', 'Snapshots are recorded automatically every day.')}</p>
                    </div>
                </div>
            `,
            assetValueHistory: (instanceId, def) => `
                <div class="card-header">
                    <h3>${def.name}</h3>
                    <div class="card-header-controls"></div>
                </div>
                <div class="chart-container">
                    <canvas></canvas>
                </div>
                <div class="chart-empty-state" style="display: none;">
                    <div class="empty-state-content">
                        <p class="empty-state-title">${t('budget', 'No asset value history yet')}</p>
                        <p class="empty-state-subtitle">${t('budget', 'Add snapshots to your assets to track their combined value over time.')}</p>
                    </div>
                </div>
            `,
            recentTransactions: (instanceId, def) => `
                <div class="card-header">
                    <h3>${def.name}</h3>
                    <div class="card-header-controls">
                        <a href="#transactions" class="card-link">${t('budget', 'View All')}</a>
                    </div>
                </div>
                <div class="recent-transactions-list"></div>
            `,
        };
    }

    // ===========================
    // Gridstack Integration
    // ===========================

    _wrapCardsForGridstack(gridEl) {
        gridEl.classList.add('grid-stack');
        const visibility = this.dashboardConfig.widgets?.visibility || {};
        const stash = document.getElementById('hidden-widgets');
        const cards = Array.from(gridEl.querySelectorAll('[data-widget-category="widget"]'));
        cards.forEach(card => {
            if (card.closest('.grid-stack-item')) return; // already wrapped
            const widgetId = card.dataset.widgetId;
            // Use saved visibility config as source of truth, but respect conditional
            // tiles that are hidden by data logic (e.g., budgetAlerts only shows with alerts)
            const conditionalTiles = ['budgetAlerts', 'debtPayoff', 'debtChart', 'debtProgress'];
            const isConditional = conditionalTiles.includes(widgetId);
            const configVisible = visibility[widgetId] === true || (visibility[widgetId] === undefined && card.style.display !== 'none');
            const isVisible = configVisible && !(isConditional && card.style.display === 'none');

            const wrapper = document.createElement('div');
            wrapper.className = 'grid-stack-item';
            wrapper.setAttribute('gs-id', widgetId);
            const content = document.createElement('div');
            content.className = 'grid-stack-item-content';
            card.parentNode.insertBefore(wrapper, card);
            content.appendChild(card);
            wrapper.appendChild(content);

            if (!isVisible) {
                // Move hidden tiles to stash immediately — don't leave in grid
                wrapper.style.display = 'none';
                if (stash) stash.appendChild(wrapper);
            }
        });
    }

    _computeInitialPositions() {
        const visibility = this.dashboardConfig.widgets?.visibility || {};
        const visibleCount = Object.values(visibility).filter(v => v !== false).length;

        // Use saved positions if available AND they cover the visible tiles
        const savedPositions = this.dashboardConfig.widgets?.positions;
        if (savedPositions && Object.keys(savedPositions).length > 0) {
            // Check that saved positions include most visible tiles
            const positionedVisible = Object.keys(savedPositions).filter(id => visibility[id] !== false).length;
            if (positionedVisible >= visibleCount * 0.8) {
                // Add auto-positions for any visible tiles missing from saved positions
                let needsUpdate = false;
                for (const [id, vis] of Object.entries(visibility)) {
                    if (vis !== false && !savedPositions[id]) {
                        const size = this.getWidgetSize(id, 'widgets');
                        const mapped = GRIDSTACK_SIZE_MAP[size] || { w: 1, h: 4 };
                        savedPositions[id] = { x: 0, y: 0, w: mapped.w, h: mapped.h, autoPosition: true };
                        needsUpdate = true;
                    }
                }
                return savedPositions;
            }
        }

        // Derive from order + sizes (fresh computation)
        // Only include tiles whose wrappers are actually in the grid (not stashed)
        const cols = this.gridColumns || 3;
        const gridEl = document.querySelector('.dashboard-grid');
        const order = this.dashboardConfig.widgets?.order || [];
        const positions = {};
        let x = 0, y = 0;

        for (const widgetId of order) {
            if (visibility[widgetId] === false) continue;
            // Skip tiles that aren't in the grid (stashed or never wrapped)
            if (gridEl && !gridEl.querySelector(`[gs-id="${widgetId}"]`)) continue;
            const size = this.getWidgetSize(widgetId, 'widgets');
            const mapped = GRIDSTACK_SIZE_MAP[size] || { w: 1, h: 4 };
            const w = size === 'l' ? cols : mapped.w;
            const h = mapped.h;

            if (size === 'l' && x !== 0) { y += 4; x = 0; }
            if (x + w > cols) { y += 4; x = 0; }

            positions[widgetId] = { x, y, w, h };
            x += w;
            if (x >= cols) { x = 0; y += h; }
        }

        return positions;
    }

    initGridstack() {
        const gridEl = document.querySelector('.dashboard-grid');
        if (!gridEl || this.gridstack) return;

        this._wrapCardsForGridstack(gridEl);
        const positions = this._computeInitialPositions();

        this.gridstack = GridStack.init({
            column: this.gridColumns || 3,
            cellHeight: 100,
            margin: 10,
            float: false,
            animate: true,
            disableOneColumnMode: true,
            disableResize: true,
            staticGrid: false,
        }, gridEl);

        // Disable dragging if dashboard is locked (must be done after init
        // so that drag handlers are created and can be toggled later)
        if (this.dashboardLocked) {
            this.gridstack.disable();
        }

        // Apply positions — all tiles in the grid at this point are visible
        // (hidden tiles were moved to stash during _wrapCardsForGridstack)
        const items = [];
        for (const [widgetId, pos] of Object.entries(positions)) {
            const el = gridEl.querySelector(`[gs-id="${widgetId}"]`);
            if (el) {
                items.push({ el, ...pos });
            }
        }
        this.gridstack.load(items);

        // Ensure gs-w/gs-h attributes are set on all items for consistent CSS grid rendering
        this.gridstack.getGridItems().forEach(el => {
            const node = el.gridstackNode;
            if (node) {
                if (!el.getAttribute('gs-w')) el.setAttribute('gs-w', node.w);
                if (!el.getAttribute('gs-h')) el.setAttribute('gs-h', node.h);
                if (el.getAttribute('gs-x') === null) el.setAttribute('gs-x', node.x);
                if (el.getAttribute('gs-y') === null) el.setAttribute('gs-y', node.y);
            }
        });

        // Listen for changes (drag end) — delay to avoid saving during initial layout
        this._gridstackReady = false;
        setTimeout(() => { this._gridstackReady = true; }, 500);
        this.gridstack.on('change', (_event, _changedItems) => {
            if (this._gridstackReady) {
                this._saveGridstackPositions();
            }
        });

        // Resize charts after any drag or layout change
        this.gridstack.on('dragstop resizestop change', () => {
            requestAnimationFrame(() => this.resizeAllCharts());
        });

        // Initial chart resize after Gridstack has laid out tiles, then reveal
        requestAnimationFrame(() => {
            this.resizeAllCharts();
            gridEl.style.opacity = '1';
        });
    }

    _removeHiddenTilesFromGrid() {
        if (!this.gridstack) return;
        const stash = document.getElementById('hidden-widgets');
        const gridEl = document.querySelector('.dashboard-grid');
        if (!stash || !gridEl) return;

        // After all data has loaded and update methods have run,
        // remove any tiles whose cards are still display:none
        // (conditional tiles with no data, or Phase 2+ tiles not yet populated)
        gridEl.querySelectorAll('.grid-stack-item').forEach(wrapper => {
            const card = wrapper.querySelector('.dashboard-card');
            if (card && card.style.display === 'none') {
                this.gridstack.removeWidget(wrapper, false);
                wrapper.style.display = 'none';
                stash.appendChild(wrapper);
            }
        });
    }

    _saveGridstackPositions() {
        if (!this.gridstack) return;
        const positions = {};
        this.gridstack.getGridItems().forEach(el => {
            const id = el.getAttribute('gs-id');
            if (id) {
                const node = el.gridstackNode;
                if (node) {
                    positions[id] = { x: node.x, y: node.y, w: node.w, h: node.h };
                }
            }
        });

        if (!this.dashboardConfig.widgets) this.dashboardConfig.widgets = {};
        this.dashboardConfig.widgets.positions = positions;

        // Update order from sorted positions — but don't overwrite with empty
        // (preserves order for tiles temporarily hidden)
        if (Object.keys(positions).length > 0) {
            const sorted = Object.entries(positions)
                .sort(([, a], [, b]) => a.y - b.y || a.x - b.x)
                .map(([id]) => id);

            // Merge: keep hidden tiles in order, update visible tile positions
            const existingOrder = this.dashboardConfig.widgets.order || [];
            const hiddenInOrder = existingOrder.filter(id => !sorted.includes(id));
            this.dashboardConfig.widgets.order = [...sorted, ...hiddenInOrder];
        }

        this.saveDashboardVisibility('widgets');
    }

    _updateFullWidthTiles(cols) {
        if (!this.gridstack) return;
        const sizes = this.dashboardConfig.widgets?.sizes || {};
        this.gridstack.getGridItems().forEach(el => {
            const id = el.getAttribute('gs-id');
            if (id && sizes[id] === 'l') {
                this.gridstack.update(el, { w: cols });
            }
        });
    }

    // ===========================
    // Hero Tile Drag-and-Drop
    // ===========================

    setupHeroDragAndDrop() {
        const container = document.querySelector('.dashboard-hero');
        if (!container) return;

        const cards = container.querySelectorAll('[data-widget-category="hero"]');
        cards.forEach(card => {
            card.setAttribute('draggable', 'true');
            card.style.cursor = 'move';

            card.addEventListener('dragstart', (e) => {
                this._heroDragItem = card;
                card.classList.add('hero-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', card.dataset.widgetId);
            });

            card.addEventListener('dragend', () => {
                card.classList.remove('hero-dragging');
                container.querySelectorAll('[data-widget-category="hero"]').forEach(c => {
                    c.classList.remove('hero-drag-over');
                });
                this._heroDragItem = null;
            });

            card.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                if (this._heroDragItem && this._heroDragItem !== card) {
                    card.classList.add('hero-drag-over');
                }
            });

            card.addEventListener('dragleave', () => {
                card.classList.remove('hero-drag-over');
            });

            card.addEventListener('drop', (e) => {
                e.preventDefault();
                card.classList.remove('hero-drag-over');
                if (!this._heroDragItem || this._heroDragItem === card) return;

                // Determine insertion position
                const allCards = Array.from(container.querySelectorAll('[data-widget-category="hero"]'));
                const dragIdx = allCards.indexOf(this._heroDragItem);
                const dropIdx = allCards.indexOf(card);

                if (dragIdx < dropIdx) {
                    container.insertBefore(this._heroDragItem, card.nextSibling);
                } else {
                    container.insertBefore(this._heroDragItem, card);
                }

                // Save new order
                this._saveHeroOrder();
            });
        });
    }

    teardownHeroDragAndDrop() {
        const container = document.querySelector('.dashboard-hero');
        if (!container) return;

        container.querySelectorAll('[data-widget-category="hero"]').forEach(card => {
            card.removeAttribute('draggable');
            card.style.cursor = '';
            card.classList.remove('hero-dragging', 'hero-drag-over');
        });
    }

    _saveHeroOrder() {
        const container = document.querySelector('.dashboard-hero');
        if (!container) return;

        const order = Array.from(container.querySelectorAll('[data-widget-category="hero"]'))
            .map(card => card.dataset.widgetId);

        if (!this.dashboardConfig.hero) this.dashboardConfig.hero = {};
        this.dashboardConfig.hero.order = order;
        this.saveDashboardVisibility('hero');
    }

    getDefaultSize(widgetKey) {
        for (const category of ['hero', 'widgets']) {
            const widget = DASHBOARD_WIDGETS[category]?.[widgetKey];
            if (widget) return widget.defaultSize || 's';
        }
        return 's';
    }

    getWidgetSize(widgetId, category) {
        const config = this.dashboardConfig[category];
        if (config?.sizes?.[widgetId]) return config.sizes[widgetId];
        // Resolve instance ID to base type for default size lookup
        const baseType = this.getWidgetType(widgetId);
        const widgets = DASHBOARD_WIDGETS[category] || {};
        for (const [key, def] of Object.entries(widgets)) {
            if (def.id === baseType || key === baseType || def.id === widgetId || key === widgetId) return def.defaultSize || 's';
        }
        return 's';
    }

    applyTileSizes() {
        const grid = document.querySelector('.dashboard-grid');
        if (!grid) return;

        const sizes = this.dashboardConfig.widgets?.sizes || {};
        grid.querySelectorAll('[data-widget-category="widget"]').forEach(card => {
            const widgetId = card.dataset.widgetId;
            const size = sizes[widgetId] || this.getWidgetSize(widgetId, 'widgets');
            card.classList.remove('dashboard-tile-xs', 'dashboard-tile-s', 'dashboard-tile-m', 'dashboard-tile-l');
            card.classList.add(`dashboard-tile-${size}`);
        });
    }



    applyDashboardOrder() {
        // Hero cards (unchanged logic)
        const heroContainer = document.querySelector('.dashboard-hero');
        if (heroContainer) {
            const heroOrder = this.dashboardConfig.hero?.order || [];
            const heroCards = Array.from(heroContainer.querySelectorAll('[data-widget-category="hero"]'));
            heroCards.sort((a, b) => {
                const aIdx = heroOrder.indexOf(a.dataset.widgetId);
                const bIdx = heroOrder.indexOf(b.dataset.widgetId);
                return (aIdx === -1 ? 999 : aIdx) - (bIdx === -1 ? 999 : bIdx);
            });
            heroCards.forEach(card => heroContainer.appendChild(card));
        }

        // Widget card positions are managed by Gridstack — just apply size classes
        this.applyTileSizes();
    }

    applyDashboardLayout() {

        const isMobile = window.innerWidth < 1200;

        if (isMobile) {
            // On mobile, apply CSS order property for single-column layout
            let orderIndex = 0;

            // Hero cards first
            this.dashboardConfig.hero.order.forEach((widgetId) => {
                const card = document.querySelector(`[data-widget-id="${widgetId}"][data-widget-category="hero"]`);
                if (card && this.dashboardConfig.hero.visibility[widgetId]) {
                    card.style.order = orderIndex++;
                }
            });

            // Then widget cards
            this.dashboardConfig.widgets.order.forEach((widgetId) => {
                const card = document.querySelector(`[data-widget-id="${widgetId}"][data-widget-category="widget"]`);
                if (card && this.dashboardConfig.widgets.visibility[widgetId]) {
                    card.style.order = orderIndex++;
                }
            });
        } else {
            // On desktop, clear order and let CSS Grid handle layout
            document.querySelectorAll('[data-widget-id]').forEach(card => {
                card.style.order = '';
            });
        }
    }

    // Phase 2: Lazy loading infrastructure
    needsLazyLoad(widgetKey) {
        // Phase 1 tiles don't need lazy loading (use existing data)
        const phase1Tiles = [
            'savingsRate', 'cashFlow', 'budgetRemaining', 'budgetHealth',
            'topCategories', 'accountPerformance', 'budgetBreakdown',
            'goalsSummary', 'paymentBreakdown', 'reconciliationStatus'
        ];
        return !phase1Tiles.includes(widgetKey);
    }

    async loadWidgetData(widgetKey) {
        if (this.widgetDataLoaded[widgetKey]) return; // Already loaded

        try {
            switch(widgetKey) {
                case 'uncategorizedCount': {
                    const uncatResp = await fetch(
                        OC.generateUrl('/apps/budget/api/transactions/uncategorized?limit=100'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.uncategorizedCount = await uncatResp.json();
                    break;
                }

                case 'monthlyComparison': {
                    const now = new Date();
                    const thisMonth = {
                        start: formatters.getMonthStart(now.getFullYear(), now.getMonth() + 1),
                        end: formatters.getMonthEnd(now.getFullYear(), now.getMonth() + 1)
                    };
                    const lastMonthDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                    const lastMonth = {
                        start: formatters.getMonthStart(lastMonthDate.getFullYear(), lastMonthDate.getMonth() + 1),
                        end: formatters.getMonthEnd(lastMonthDate.getFullYear(), lastMonthDate.getMonth() + 1)
                    };

                    const [currentResp, previousResp] = await Promise.all([
                        fetch(
                            OC.generateUrl(`/apps/budget/api/reports/summary?startDate=${thisMonth.start}&endDate=${thisMonth.end}`),
                            { headers: { 'requesttoken': OC.requestToken } }
                        ),
                        fetch(
                            OC.generateUrl(`/apps/budget/api/reports/summary?startDate=${lastMonth.start}&endDate=${lastMonth.end}`),
                            { headers: { 'requesttoken': OC.requestToken } }
                        )
                    ]);

                    this.widgetData.monthlyComparison = {
                        current: await currentResp.json(),
                        previous: await previousResp.json()
                    };
                    break;
                }

                case 'largeTransactions': {
                    const largeResp = await fetch(
                        OC.generateUrl('/apps/budget/api/transactions?limit=10&sort=amount'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.largeTransactions = await largeResp.json();
                    break;
                }

                // Phase 3 cases
                case 'cashFlowForecast': {
                    const forecastResp = await fetch(
                        OC.generateUrl('/apps/budget/api/forecast/live?days=90'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.cashFlowForecast = await forecastResp.json();
                    break;
                }

                case 'yoyComparison': {
                    const yoyResp = await fetch(
                        OC.generateUrl('/apps/budget/api/yoy/years?years=2'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.yoyComparison = await yoyResp.json();
                    break;
                }

                case 'incomeTracking': {
                    const incomeResp = await fetch(
                        OC.generateUrl('/apps/budget/api/recurring-income/summary'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.incomeTracking = await incomeResp.json();
                    break;
                }

                case 'daysUntilDebtFree': {
                    const debtResp = await fetch(
                        OC.generateUrl('/apps/budget/api/debts/payoff-plan?strategy=avalanche'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.daysUntilDebtFree = await debtResp.json();
                    break;
                }

                case 'debtChart':
                    // Data is fetched directly inside renderDebtChartWidget
                    await this.renderDebtChartWidget();
                    break;

                case 'debtProgress':
                    // Data is fetched directly inside renderDebtProgressWidget
                    await this.renderDebtProgressWidget();
                    break;

                case 'recentImports': {
                    const importsResp = await fetch(
                        OC.generateUrl('/apps/budget/api/import/history?limit=5'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.recentImports = importsResp.ok ? await importsResp.json() : [];
                    break;
                }

                case 'ruleEffectiveness': {
                    const rulesResp = await fetch(
                        OC.generateUrl('/apps/budget/api/import-rules'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.ruleEffectiveness = await rulesResp.json();
                    break;
                }

                case 'weeklyTrend': {
                    const weekEnd = new Date();
                    const weekStart = new Date();
                    weekStart.setDate(weekEnd.getDate() - 7);
                    const weekResp = await fetch(
                        OC.generateUrl(`/apps/budget/api/reports/summary?startDate=${formatters.formatDateForAPI(weekStart)}&endDate=${formatters.formatDateForAPI(weekEnd)}`),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    const weekData = await weekResp.json();
                    this.widgetData.weeklyTrend = [{ total: weekData.totalExpenses || 0 }];
                    break;
                }

                case 'unmatchedTransfers': {
                    const unmatchedResp = await fetch(
                        OC.generateUrl('/apps/budget/api/transactions?limit=10&unmatched=true'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    const unmatchedData = await unmatchedResp.json();
                    this.widgetData.unmatchedTransfers = Array.isArray(unmatchedData) ? unmatchedData : [];
                    break;
                }

                case 'billsDueSoon': {
                    const billsResp = await fetch(
                        OC.generateUrl('/apps/budget/api/bills?isTransfer=false'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    const allBills = await billsResp.json();
                    // Filter to upcoming bills (due within 14 days)
                    const todayStr = formatters.getTodayDateString();
                    this.widgetData.billsDueSoon = (Array.isArray(allBills) ? allBills : [])
                        .filter(b => {
                            const due = b.nextDueDate || b.next_due_date;
                            if (!due) return false;
                            const days = formatters.daysBetweenDates(todayStr, due);
                            return days >= -7 && days <= 14;
                        })
                        .sort((a, b) => (a.nextDueDate || a.next_due_date || '').localeCompare(b.nextDueDate || b.next_due_date || ''));
                    break;
                }

                case 'categoryTrends': {
                    const ctNow = new Date();
                    const ctThisMonth = {
                        start: formatters.getMonthStart(ctNow.getFullYear(), ctNow.getMonth() + 1),
                        end: formatters.getMonthEnd(ctNow.getFullYear(), ctNow.getMonth() + 1)
                    };
                    const ctLastDate = new Date(ctNow.getFullYear(), ctNow.getMonth() - 1, 1);
                    const ctLastMonth = {
                        start: formatters.getMonthStart(ctLastDate.getFullYear(), ctLastDate.getMonth() + 1),
                        end: formatters.getMonthEnd(ctLastDate.getFullYear(), ctLastDate.getMonth() + 1)
                    };

                    const [ctCurrentResp, ctPrevResp] = await Promise.all([
                        fetch(OC.generateUrl(`/apps/budget/api/categories/spending?startDate=${ctThisMonth.start}&endDate=${ctThisMonth.end}`), { headers: { 'requesttoken': OC.requestToken } }),
                        fetch(OC.generateUrl(`/apps/budget/api/categories/spending?startDate=${ctLastMonth.start}&endDate=${ctLastMonth.end}`), { headers: { 'requesttoken': OC.requestToken } })
                    ]);

                    const ctCurrent = await ctCurrentResp.json();
                    const ctPrev = await ctPrevResp.json();

                    // Build lookup for previous month
                    const prevMap = {};
                    if (Array.isArray(ctPrev)) {
                        ctPrev.forEach(c => { prevMap[c.categoryId] = parseFloat(c.spent || 0); });
                    }

                    this.widgetData.categoryTrends = (Array.isArray(ctCurrent) ? ctCurrent : [])
                        .map(c => ({
                            name: c.name,
                            color: c.color,
                            currentTotal: parseFloat(c.spent || 0),
                            previousTotal: prevMap[c.categoryId] || 0,
                        }))
                        .filter(c => c.currentTotal > 0 || c.previousTotal > 0)
                        .sort((a, b) => b.currentTotal - a.currentTotal);
                    break;
                }

                case 'spendingVelocity': {
                    const svNow = new Date();
                    const svMonthStart = formatters.getMonthStart(svNow.getFullYear(), svNow.getMonth() + 1);
                    const svToday = formatters.formatDateForAPI(svNow);
                    const svDayOfMonth = svNow.getDate();

                    const svResp = await fetch(
                        OC.generateUrl(`/apps/budget/api/reports/summary?startDate=${svMonthStart}&endDate=${svToday}`),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    const svData = await svResp.json();
                    const totalSpent = parseFloat(svData.totalExpenses || 0);
                    this.widgetData.spendingVelocity = {
                        dailyRate: svDayOfMonth > 0 ? totalSpent / svDayOfMonth : 0,
                    };
                    break;
                }
            }

            this.widgetDataLoaded[widgetKey] = true;
        } catch (error) {
            console.error(`Failed to load data for ${widgetKey}:`, error);
        }
    }

    // ===========================
    // Dashboard Customization
    // ===========================

    setupDashboardCustomization() {
        const toggleBtn = document.getElementById('toggle-dashboard-lock-btn');
        if (!toggleBtn) return;

        // Load saved lock state
        const savedLockState = this.settings.dashboard_locked !== 'false'; // Default to locked
        this.app.dashboardLocked = savedLockState;
        this.gridColumns = parseInt(this.app.settings?.dashboard_grid_columns) || 3;
        this.updateDashboardLockUI();

        toggleBtn.addEventListener('click', () => this.toggleDashboardLock());

        // Add Tiles dropdown
        const addTilesBtn = document.getElementById('add-tiles-btn');
        const addTilesMenu = document.getElementById('add-tiles-menu');

        if (addTilesBtn && addTilesMenu) {
            addTilesBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const isVisible = addTilesMenu.style.display !== 'none';
                addTilesMenu.style.display = isVisible ? 'none' : 'block';
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!addTilesBtn.contains(e.target) && !addTilesMenu.contains(e.target)) {
                    addTilesMenu.style.display = 'none';
                }
            });
        }

        // Setup column picker
        this.setupColumnPicker();

        // Setup tile-level config panels
        this.setupAccountsTileConfig();
    }

    setupColumnPicker() {
        const picker = document.getElementById('dashboard-columns-picker');
        if (!picker) return;

        // Highlight current column count
        picker.querySelectorAll('.columns-btn').forEach(btn => {
            btn.classList.toggle('active', parseInt(btn.dataset.cols) === this.gridColumns);
            btn.onclick = () => {
                const cols = parseInt(btn.dataset.cols);
                this.gridColumns = cols;

                picker.querySelectorAll('.columns-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                this._saveSettings({ dashboard_grid_columns: cols.toString() });

                // Update Gridstack column count
                if (this.gridstack) {
                    this.gridstack.column(cols, 'moveScale');
                    this._updateFullWidthTiles(cols);
                }

                // Resize charts after layout change
                requestAnimationFrame(() => this.resizeAllCharts());
            };
        });
    }

    async toggleDashboardLock() {
        this.app.dashboardLocked = !this.app.dashboardLocked;

        // Update UI immediately
        this.updateDashboardLockUI();

        // Update Gridstack static mode
        if (this.gridstack) {
            if (this.app.dashboardLocked) {
                this.gridstack.disable();
            } else {
                this.gridstack.enable();
            }
        }

        // Toggle hero tile drag-and-drop
        if (this.app.dashboardLocked) {
            this.teardownHeroDragAndDrop();
        } else {
            this.setupHeroDragAndDrop();
        }

        // Save state to backend
        try {
            await this._saveSettings({
                dashboard_locked: this.app.dashboardLocked.toString()
            });
        } catch (error) {
            console.error('Failed to save lock state:', error);
            showError(t('budget', 'Failed to save dashboard lock state'));
        }
    }

    updateDashboardLockUI() {
        const btn = document.getElementById('toggle-dashboard-lock-btn');
        const btnText = document.getElementById('lock-btn-text');
        const hint = document.getElementById('dashboard-hint');
        const icon = document.getElementById('lock-btn-icon');
        const addTilesDropdown = document.getElementById('add-tiles-dropdown');

        if (!btn || !btnText || !hint) return;

        const lockedSvg = '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>';
        const unlockedSvg = '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 5-5 5 5 0 0 1 5 5"></path>';

        const columnsPicker = document.getElementById('dashboard-columns-picker');

        if (this.dashboardLocked) {
            // Locked state
            btnText.textContent = t('budget', 'Unlock Dashboard');
            hint.querySelector('span:last-child').textContent = t('budget', 'Dashboard is locked. Click unlock to reorder tiles.');
            if (icon) icon.innerHTML = lockedSvg;
            // Hide Add Tiles button, column picker, and tile settings buttons
            if (addTilesDropdown) addTilesDropdown.style.display = 'none';
            if (columnsPicker) columnsPicker.style.display = 'none';
            document.querySelectorAll('.tile-settings-btn').forEach(b => b.style.display = 'none');
            const tileModal = document.getElementById('tile-settings-modal');
            if (tileModal) tileModal.style.display = 'none';
            // Remove all tile controls
            document.querySelectorAll('.widget-tile-controls').forEach(el => el.remove());
            // Inline selectors removed — settings are in the tile settings modal
            document.querySelectorAll('.card-select, .card-header-controls .period-selector').forEach(el => el.style.display = 'none');
        } else {
            // Unlocked state
            btnText.textContent = t('budget', 'Lock Dashboard');
            hint.querySelector('span:last-child').textContent = t('budget', 'Drag tiles to reorder your dashboard');
            if (icon) icon.innerHTML = unlockedSvg;
            // Show Add Tiles button, column picker, and tile settings buttons
            if (addTilesDropdown) addTilesDropdown.style.display = 'block';
            if (columnsPicker) columnsPicker.style.display = 'flex';
            document.querySelectorAll('.tile-settings-btn').forEach(b => b.style.display = '');
            // Hide inline selectors when unlocked — use gear icon / settings modal instead
            document.querySelectorAll('.card-select, .card-header-controls .period-selector').forEach(el => el.style.display = 'none');
            // Add tile controls to all visible widgets
            this.addTileControls();
        }

        // Update Add Tiles dropdown content
        this.updateAddTilesMenu();
    }

    addTileControls() {
        // Remove existing controls first
        document.querySelectorAll('.widget-tile-controls').forEach(el => el.remove());

        const addControls = (card, category) => {
            const widgetId = card.dataset.widgetId;
            if (!widgetId) return;

            const controls = document.createElement('div');
            controls.className = 'widget-tile-controls';

            // Size picker
            const widgetDef = this.findWidgetDef(widgetId);
            const allowedSizes = widgetDef?.allowedSizes;
            if (allowedSizes && allowedSizes.length > 1) {
                const configCategory = category === 'hero' ? 'hero' : 'widgets';
                const currentSize = this.getWidgetSize(widgetId, configCategory);

                const sizePicker = document.createElement('div');
                sizePicker.className = 'tile-size-picker';
                allowedSizes.forEach(size => {
                    const btn = document.createElement('button');
                    btn.className = `size-btn ${size === currentSize ? 'active' : ''}`;
                    btn.dataset.size = size;
                    btn.textContent = size.toUpperCase();
                    btn.title = { xs: 'Extra Small', s: 'Small', m: 'Medium', l: 'Large' }[size];
                    btn.onclick = (e) => {
                        e.stopPropagation();
                        this.changeTileSize(widgetId, size, card);
                        sizePicker.querySelectorAll('.size-btn').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                    };
                    sizePicker.appendChild(btn);
                });
                controls.appendChild(sizePicker);

                // Divider after size picker
                const div1 = document.createElement('div');
                div1.className = 'tile-controls-divider';
                controls.appendChild(div1);
            }

            // Gear icon (widgets only — hero tiles have no settings)
            if (category !== 'hero') {
                const gearBtn = document.createElement('button');
                gearBtn.className = 'tile-gear-btn';
                gearBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>';
                gearBtn.title = t('budget', 'Tile Settings');
                gearBtn.onclick = (e) => {
                    e.stopPropagation();
                    this.openTileSettingsModal(widgetId, category);
                };
                controls.appendChild(gearBtn);
            }

            // Divider before remove
            const div2 = document.createElement('div');
            div2.className = 'tile-controls-divider';
            controls.appendChild(div2);

            // Remove button
            const removeBtn = document.createElement('button');
            removeBtn.className = 'widget-remove-btn';
            removeBtn.innerHTML = '&times;';
            removeBtn.title = this.isDuplicateInstance(widgetId) ? t('budget', 'Remove tile') : t('budget', 'Hide tile');
            removeBtn.onclick = (e) => {
                e.stopPropagation();
                if (this.isDuplicateInstance(widgetId)) {
                    this.removeWidgetInstance(widgetId);
                } else {
                    this.hideWidget(widgetId, category === 'widget' ? 'widgets' : 'hero');
                }
            };
            controls.appendChild(removeBtn);

            card.style.position = 'relative';
            card.appendChild(controls);
        };

        document.querySelectorAll('.hero-card').forEach(card => addControls(card, 'hero'));
        document.querySelectorAll('.dashboard-card').forEach(card => addControls(card, 'widget'));
    }

    findWidgetDef(widgetId) {
        const baseType = this.getWidgetType(widgetId);
        for (const category of ['hero', 'widgets']) {
            for (const [key, def] of Object.entries(DASHBOARD_WIDGETS[category] || {})) {
                if (key === baseType || def.id === baseType || key === widgetId || def.id === widgetId) return def;
            }
        }
        return null;
    }

    changeTileSize(widgetId, newSize, card) {
        if (!this.dashboardConfig.widgets.sizes) this.dashboardConfig.widgets.sizes = {};
        this.dashboardConfig.widgets.sizes[widgetId] = newSize;

        // Update CSS class (for content styling like XS max-height)
        card.classList.remove('dashboard-tile-xs', 'dashboard-tile-s', 'dashboard-tile-m', 'dashboard-tile-l');
        card.classList.add(`dashboard-tile-${newSize}`);

        // Update Gridstack dimensions
        if (this.gridstack) {
            const mapped = GRIDSTACK_SIZE_MAP[newSize] || { w: 1, h: 4 };
            const w = newSize === 'l' ? (this.gridColumns || 3) : mapped.w;
            const wrapper = card.closest('.grid-stack-item');
            if (wrapper) {
                this.gridstack.update(wrapper, { w, h: mapped.h });
            }
        }

        this.saveDashboardVisibility('widgets');

        requestAnimationFrame(() => {
            const canvas = card.querySelector('canvas');
            if (canvas) {
                const chartKeys = Object.keys(this.charts || {});
                for (const key of chartKeys) {
                    if (this.charts[key]?.canvas === canvas) {
                        this.charts[key].resize();
                        break;
                    }
                }
            }
        });
    }

    openTileSettingsModal(widgetId, category) {
        const modal = document.getElementById('tile-settings-modal');
        if (!modal) return;

        const widgetDef = this.findWidgetDef(widgetId);
        const schema = widgetDef?.settingsSchema || {};
        const configCategory = category === 'hero' ? 'hero' : 'widgets';
        const currentSettings = this.dashboardConfig[configCategory]?.tileSettings?.[widgetId] || {};

        // Set title — append instance number for duplicates
        const titleEl = document.getElementById('tile-settings-modal-title');
        if (titleEl) {
            let title = widgetDef?.name || t('budget', 'Tile Settings');
            if (this.isDuplicateInstance(widgetId)) {
                const num = widgetId.split('__')[1];
                title += ` (${num})`;
            }
            titleEl.textContent = title;
        }

        const commonSection = document.getElementById('tile-settings-common');
        const specificSection = document.getElementById('tile-settings-specific');
        const listSection = document.getElementById('tile-settings-modal-list');

        if (commonSection) commonSection.innerHTML = '';
        if (specificSection) specificSection.innerHTML = '';
        if (listSection) listSection.innerHTML = '';

        const fields = [];

        // Date range
        if (schema.dateRange) {
            // Default to the same value the tile actually displays / the indicator shows
            const current = currentSettings.dateRange || this._defaultDateRange(this.getWidgetType(widgetId));
            fields.push(`
                <div class="form-group">
                    <label>${t('budget', 'Date Range')}</label>
                    <select class="tile-setting-input" data-setting="dateRange">
                        <option value="30d" ${current === '30d' ? 'selected' : ''}>${t('budget', 'Last 30 days')}</option>
                        <option value="90d" ${current === '90d' ? 'selected' : ''}>${t('budget', 'Last 90 days')}</option>
                        <option value="6m" ${current === '6m' ? 'selected' : ''}>${t('budget', 'Last 6 months')}</option>
                        <option value="1y" ${current === '1y' ? 'selected' : ''}>${t('budget', 'Last year')}</option>
                    </select>
                </div>
            `);
        }

        // Account selector
        if (schema.accountSelector) {
            const currentAccount = currentSettings.accountId || '';
            let options = `<option value="">${t('budget', 'All Accounts')}</option>`;
            if (this.accounts) {
                this.accounts.forEach(acc => {
                    options += `<option value="${acc.id}" ${currentAccount == acc.id ? 'selected' : ''}>${this.escapeHtml(acc.name)}</option>`;
                });
            }
            fields.push(`
                <div class="form-group">
                    <label>${t('budget', 'Account')}</label>
                    <select class="tile-setting-input" data-setting="accountId">${options}</select>
                </div>
            `);
        }

        // Show legend (checkbox)
        if (schema.showLegend) {
            const checked = currentSettings.showLegend !== false;
            fields.push(`
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" class="tile-setting-input" data-setting="showLegend"
                            style="width: auto; min-height: auto;" ${checked ? 'checked' : ''}>
                        ${t('budget', 'Show legend')}
                    </label>
                </div>
            `);
        }

        // Top-level categories only
        if (schema.topLevelOnly) {
            const checked = currentSettings.topLevelOnly || false;
            fields.push(`
                <div class="form-group">
                    <label>${t('budget', 'Categories')}</label>
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" class="tile-setting-input" data-setting="topLevelOnly"
                            style="width: auto; min-height: auto;" ${checked ? 'checked' : ''}>
                        ${t('budget', 'Show top-level categories only')}
                    </label>
                </div>
            `);
        }

        // Chart type
        if (schema.chartType && Array.isArray(schema.chartType)) {
            const current = currentSettings.chartType || schema.chartType[0];
            let options = schema.chartType.map(type => {
                const label = type.charAt(0).toUpperCase() + type.slice(1);
                return `<option value="${type}" ${current === type ? 'selected' : ''}>${label}</option>`;
            }).join('');
            fields.push(`
                <div class="form-group">
                    <label>${t('budget', 'Chart Type')}</label>
                    <select class="tile-setting-input" data-setting="chartType">${options}</select>
                </div>
            `);
        }

        // Row count
        if (schema.rowCount) {
            const current = currentSettings.rowCount || schema.rowCount.default || 5;
            fields.push(`
                <div class="form-group">
                    <label>${t('budget', 'Rows to show')}</label>
                    <input type="number" class="tile-setting-input" data-setting="rowCount"
                        value="${current}" min="${schema.rowCount.min || 3}" max="${schema.rowCount.max || 20}">
                </div>
            `);
        }

        // Display format (hero tiles)
        if (schema.displayFormat && Array.isArray(schema.displayFormat)) {
            const current = currentSettings.displayFormat || schema.displayFormat[0];
            let options = schema.displayFormat.map(fmt => {
                const label = fmt.charAt(0).toUpperCase() + fmt.slice(1);
                return `<option value="${fmt}" ${current === fmt ? 'selected' : ''}>${label}</option>`;
            }).join('');
            fields.push(`
                <div class="form-group">
                    <label>${t('budget', 'Display Format')}</label>
                    <select class="tile-setting-input" data-setting="displayFormat">${options}</select>
                </div>
            `);
        }

        // Show change indicator (hero tiles)
        if (schema.showChangeIndicator) {
            const checked = currentSettings.showChangeIndicator !== false;
            fields.push(`
                <div class="form-group">
                    <label>
                        <input type="checkbox" class="tile-setting-input" data-setting="showChangeIndicator" ${checked ? 'checked' : ''}>
                        ${t('budget', 'Show change indicator')}
                    </label>
                </div>
            `);
        }

        // Render fields
        if (commonSection) {
            if (fields.length > 0) {
                commonSection.innerHTML = fields.join('');
            } else {
                commonSection.innerHTML = `<p style="color: var(--color-text-maxcontrast); font-size: 13px;">${t('budget', 'No settings available for this tile.')}</p>`;
            }
        }

        // Accounts tile: render drag-to-reorder list in the list section
        if (widgetId === 'accounts') {
            if (specificSection) {
                specificSection.innerHTML = `<p class="tile-config-hint">${t('budget', 'Drag to reorder. Toggle visibility for each item.')}</p>`;
            }
            this.renderAccountsTileConfigList();
        }

        // Wire change handlers (save immediately on change)
        modal.querySelectorAll('.tile-setting-input').forEach(input => {
            input.onchange = () => {
                this.saveTileSetting(widgetId, configCategory, input);
            };
        });

        // Close button
        const closeBtn = document.getElementById('tile-settings-close');
        if (closeBtn) closeBtn.onclick = () => { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); };

        // Show
        modal.style.display = '';
        modal.setAttribute('aria-hidden', 'false');
    }

    saveTileSetting(widgetId, category, input) {
        const setting = input.dataset.setting;
        let value;
        if (input.type === 'checkbox') {
            value = input.checked;
        } else if (input.type === 'number') {
            value = parseInt(input.value) || 0;
        } else {
            value = input.value;
        }

        // Ensure tileSettings exists
        if (!this.dashboardConfig[category].tileSettings) {
            this.dashboardConfig[category].tileSettings = {};
        }
        if (!this.dashboardConfig[category].tileSettings[widgetId]) {
            this.dashboardConfig[category].tileSettings[widgetId] = {};
        }

        this.dashboardConfig[category].tileSettings[widgetId][setting] = value;
        this.saveDashboardVisibility();

        // Refresh the tile to apply the new setting
        this.refreshTileAfterSettingsChange(widgetId, category);

        // Keep the period indicator in sync with the new setting
        if (setting === 'dateRange') {
            this.updateTilePeriodIndicators();
        }
    }

    refreshTileAfterSettingsChange(widgetId, _category) {
        const baseType = this.getWidgetType(widgetId);

        // Use instance-aware refresh for duplicable widgets (reads from tileSettings directly)
        if (DUPLICABLE_WIDGETS.includes(baseType)) {
            return this.refreshWidgetInstance(widgetId);
        }

        const refreshMap = {
            'upcomingBills': () => this.updateUpcomingBillsWidget?.(),
            // Stat widgets
            'accounts': () => this.updateAccountsWidget(this._allDashboardAccounts),
            'budgetProgress': () => this.refreshBudgetProgressWidget(widgetId),
            'topCategories': () => this.refreshTopCategoriesWidget(widgetId),
            'budgetAlerts': () => this.updateBudgetAlertsWidget?.(),
            'savingsGoals': () => this.updateSavingsGoalsWidget?.(),
            // Debt widgets
            'debtPayoff': () => this.updateDebtPayoffWidget?.(),
            'debtChart': () => this.renderDebtChartWidget(),
            'debtProgress': () => this.renderDebtProgressWidget(),
            // Phase 2+ widgets
            'monthlyComparison': () => this.updateMonthlyComparisonWidget?.(),
            'largeTransactions': () => this.updateLargeTransactionsWidget?.(),
            'weeklyTrend': () => this.updateWeeklyTrendWidget?.(),
            'categoryTrends': () => this.updateCategoryTrendsWidget?.(),
            'billsDueSoon': () => this.updateBillsDueSoonWidget?.(),
            'incomeTracking': () => this.updateIncomeTrackingWidget?.(),
            'cashFlowForecast': () => this.updateCashFlowForecastWidget?.(),
            'yoyComparison': () => this.updateYoyComparisonWidget?.(),
            // Hero widgets
            'accountIncome': () => this.updateAccountIncomeHero?.(),
            'accountExpenses': () => this.updateAccountExpensesHero?.(),
        };

        const refreshFn = refreshMap[widgetId];
        if (refreshFn) {
            try { refreshFn(); } catch (e) { console.error('Failed to refresh tile', widgetId, e); }
        }

    }


    /**
     * Aggregate subcategory data into parent categories.
     * Works with spending data ({id, name, color, total/amount}) and
     * budget data ({id, categoryName, color, budgeted, spent}).
     * Returns only top-level categories with summed values.
     */
    aggregateToTopLevel(data) {
        if (!data || !Array.isArray(data) || data.length === 0) return data;

        const categories = this.app.categories || [];
        const catById = {};
        const parentMap = {};

        for (const cat of categories) {
            catById[cat.id] = cat;
        }

        const findTopLevel = (catId) => {
            if (parentMap[catId] !== undefined) return parentMap[catId];
            const cat = catById[catId];
            if (!cat || !cat.parentId) {
                parentMap[catId] = catId;
                return catId;
            }
            parentMap[catId] = findTopLevel(cat.parentId);
            return parentMap[catId];
        };

        const aggregated = {};
        for (const item of data) {
            const catId = item.id || item.categoryId;
            const topId = findTopLevel(catId);
            const topCat = catById[topId];

            if (!aggregated[topId]) {
                aggregated[topId] = {
                    ...item,
                    id: topId,
                    categoryId: topId,
                    name: topCat?.name || item.categoryName || item.name,
                    categoryName: topCat?.name || item.categoryName || item.name,
                    color: topCat?.color || item.color,
                    total: 0,
                    amount: 0,
                    count: 0,
                    budgeted: 0,
                    budget: 0,
                    spent: 0,
                };
            }
            aggregated[topId].total += Math.abs(parseFloat(item.total || item.amount || 0));
            aggregated[topId].amount += Math.abs(parseFloat(item.total || item.amount || 0));
            aggregated[topId].count += parseInt(item.count || 1);
            aggregated[topId].budgeted += parseFloat(item.budgeted || item.budget || 0);
            aggregated[topId].budget += parseFloat(item.budgeted || item.budget || 0);
            aggregated[topId].spent += parseFloat(item.spent || 0);
        }

        return Object.values(aggregated);
    }

    resizeAllCharts() {
        const chartKeys = Object.keys(this.charts || {});
        chartKeys.forEach(key => {
            if (this.charts[key] && typeof this.charts[key].resize === 'function') {
                this.charts[key].resize();
            }
        });
    }

    updateAddTilesMenu() {
        const menuList = document.getElementById('add-tiles-menu-list');
        if (!menuList) return;

        menuList.innerHTML = '';

        // Group tiles by category
        const tilesByCategory = {};

        // Collect hidden hero tiles
        Object.entries(DASHBOARD_WIDGETS.hero).forEach(([key, widget]) => {
            if (!this.dashboardConfig.hero.visibility[key]) {
                const category = widget.category || 'other';
                if (!tilesByCategory[category]) {
                    tilesByCategory[category] = [];
                }
                tilesByCategory[category].push({
                    key,
                    name: widget.name,
                    type: 'hero',
                    size: 'hero'
                });
            }
        });

        // Collect hidden widget tiles
        Object.entries(DASHBOARD_WIDGETS.widgets).forEach(([key, widget]) => {
            if (!this.dashboardConfig.widgets.visibility[key]) {
                const category = widget.category || 'other';
                if (!tilesByCategory[category]) {
                    tilesByCategory[category] = [];
                }
                tilesByCategory[category].push({
                    key,
                    name: widget.name,
                    type: 'widget',
                    size: widget.defaultSize
                });
            }
        });

        // Check if any tiles are hidden
        const totalHidden = Object.values(tilesByCategory).reduce((sum, tiles) => sum + tiles.length, 0);
        if (totalHidden === 0) {
            menuList.innerHTML = `<div class="add-tiles-empty">${t('budget', 'All tiles are visible')}</div>`;
            return;
        }

        // Category display order and labels
        const categoryOrder = [
            { key: 'insights', label: t('budget', 'Insights & Analytics') },
            { key: 'budgeting', label: t('budget', 'Budgeting') },
            { key: 'forecasting', label: t('budget', 'Forecasting') },
            { key: 'transactions', label: t('budget', 'Transactions') },
            { key: 'income', label: t('budget', 'Income') },
            { key: 'debts', label: t('budget', 'Debts') },
            { key: 'goals', label: t('budget', 'Goals') },
            { key: 'bills', label: t('budget', 'Bills') },
            { key: 'alerts', label: t('budget', 'Alerts') },
            { key: 'interactive', label: t('budget', 'Interactive') },
            { key: 'other', label: t('budget', 'Other') }
        ];

        // Render tiles grouped by category
        categoryOrder.forEach(({ key, label }) => {
            const tiles = tilesByCategory[key];
            if (!tiles || tiles.length === 0) return;

            // Add category header
            const categoryHeader = document.createElement('div');
            categoryHeader.className = 'add-tiles-category-header';
            categoryHeader.textContent = label;
            menuList.appendChild(categoryHeader);

            // Add tiles in this category
            tiles.forEach(tile => {
                const item = document.createElement('div');
                item.className = 'add-tiles-menu-item';

                // Add size badge for hero tiles
                const sizeBadge = tile.size === 'hero'
                    ? `<span class="tile-size-badge">${t('budget', 'Hero')}</span>`
                    : '';

                item.innerHTML = `
                    <span class="tile-name-wrapper">
                        <span class="tile-name">${tile.name}</span>
                        ${sizeBadge}
                    </span>
                    <button class="add-tile-btn" data-widget-id="${tile.key}" data-category="${tile.type}">
                        <span class="icon-add"></span>
                    </button>
                `;
                menuList.appendChild(item);
            });
        });

        // "Add another" section for duplicable widgets that are already visible
        const duplicableItems = [];
        DUPLICABLE_WIDGETS.forEach(widgetType => {
            if (this.dashboardConfig.widgets.visibility[widgetType] !== false && this.countInstances(widgetType) < MAX_INSTANCES) {
                const widgetDef = DASHBOARD_WIDGETS.widgets[widgetType];
                if (widgetDef) {
                    duplicableItems.push({ key: widgetType, name: widgetDef.name });
                }
            }
        });

        if (duplicableItems.length > 0) {
            const dupHeader = document.createElement('div');
            dupHeader.className = 'add-tiles-category-header';
            dupHeader.textContent = t('budget', 'Add Another');
            menuList.appendChild(dupHeader);

            duplicableItems.forEach(tile => {
                const item = document.createElement('div');
                item.className = 'add-tiles-menu-item';
                item.innerHTML = `
                    <span class="tile-name-wrapper">
                        <span class="tile-name">${tile.name}</span>
                        <span class="tile-size-badge">${this.countInstances(tile.key)}/${MAX_INSTANCES}</span>
                    </span>
                    <button class="add-tile-btn add-another-btn" data-widget-type="${tile.key}">
                        <span class="icon-add"></span>
                    </button>
                `;
                menuList.appendChild(item);
            });
        }

        // Wire up add buttons
        menuList.querySelectorAll('.add-tile-btn:not(.add-another-btn)').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const widgetId = btn.dataset.widgetId;
                const category = btn.dataset.category;
                this.showWidget(widgetId, category);
            });
        });

        // Wire up "Add another" buttons
        menuList.querySelectorAll('.add-another-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.addWidgetInstance(btn.dataset.widgetType);
            });
        });
    }

    // ===========================
    // Widget Instance Lifecycle
    // ===========================

    /** Create DOM elements for saved duplicate instances (called during load, before Gridstack init) */
    createSavedInstances() {
        const instances = this.dashboardConfig.widgets?.instances || {};
        const gridEl = document.querySelector('.dashboard-grid');
        if (!gridEl) return;

        for (const [instanceId, _widgetType] of Object.entries(instances)) {
            if (this.dashboardConfig.widgets.visibility[instanceId] === false) continue;
            if (this.getWidgetCard(instanceId)) continue; // already exists

            const card = this.createWidgetDOM(instanceId);
            if (card) {
                gridEl.appendChild(card);
            }
        }
    }

    /** Fetch data for all duplicate instances (called after Gridstack init) */
    async refreshAllInstances() {
        const instances = this.dashboardConfig.widgets?.instances || {};
        const refreshes = [];
        for (const instanceId of Object.keys(instances)) {
            if (this.dashboardConfig.widgets.visibility[instanceId] === false) continue;
            refreshes.push(this.refreshWidgetInstance(instanceId));
        }
        await Promise.all(refreshes);
    }

    async addWidgetInstance(widgetType) {
        if (!DUPLICABLE_WIDGETS.includes(widgetType)) return;
        if (this.countInstances(widgetType) >= MAX_INSTANCES) {
            showError(t('budget', 'Maximum of {max} instances reached', { max: MAX_INSTANCES }));
            return;
        }

        const instanceId = this.generateInstanceId(widgetType);

        // Register in config
        if (!this.dashboardConfig.widgets.instances) this.dashboardConfig.widgets.instances = {};
        this.dashboardConfig.widgets.instances[instanceId] = widgetType;

        // Set default visibility and size
        this.dashboardConfig.widgets.visibility[instanceId] = true;
        const defaultSize = DASHBOARD_WIDGETS.widgets[widgetType]?.defaultSize || 'm';
        if (!this.dashboardConfig.widgets.sizes) this.dashboardConfig.widgets.sizes = {};
        this.dashboardConfig.widgets.sizes[instanceId] = defaultSize;

        // Add to order
        if (!this.dashboardConfig.widgets.order) this.dashboardConfig.widgets.order = [];
        this.dashboardConfig.widgets.order.push(instanceId);

        // Create DOM
        const card = this.createWidgetDOM(instanceId);
        if (!card) return;

        const gridEl = document.querySelector('.dashboard-grid');
        if (!gridEl) return;

        // Add to Gridstack
        if (this.gridstack) {
            const mapped = GRIDSTACK_SIZE_MAP[defaultSize] || { w: 2, h: 4 };
            const w = defaultSize === 'l' ? (this.gridColumns || 3) : mapped.w;

            // Build the grid-stack-item wrapper and append to grid
            const wrapper = document.createElement('div');
            wrapper.className = 'grid-stack-item';
            wrapper.setAttribute('gs-id', instanceId);
            wrapper.setAttribute('gs-w', w);
            wrapper.setAttribute('gs-h', mapped.h);
            wrapper.setAttribute('gs-auto-position', 'true');
            const content = document.createElement('div');
            content.className = 'grid-stack-item-content';
            content.appendChild(card);
            wrapper.appendChild(content);
            gridEl.appendChild(wrapper);

            // v12 API: makeWidget registers an existing DOM element with Gridstack
            this.gridstack.makeWidget(wrapper);
        } else {
            gridEl.appendChild(card);
        }

        // Save config
        this._saveGridstackPositions();
        this.saveDashboardVisibility('widgets');

        // Add tile controls if unlocked
        if (!this.dashboardLocked) {
            this.addTileControls();
        }

        // Fetch and render data
        await this.refreshWidgetInstance(instanceId);

        // Show the period indicator on the new tile (if it supports a date range)
        this.updateTilePeriodIndicators();

        // Update menu
        this.updateAddTilesMenu();

        showSuccess(t('budget', 'Tile added'));
    }

    removeWidgetInstance(instanceId) {
        if (!this.isDuplicateInstance(instanceId)) {
            // Base tiles just get hidden
            this.hideWidget(instanceId, 'widgets');
            return;
        }

        // Remove from Gridstack
        if (this.gridstack) {
            const el = document.querySelector(`.grid-stack-item[gs-id="${instanceId}"]`);
            if (el) this.gridstack.removeWidget(el);
        } else {
            const card = this.getWidgetCard(instanceId);
            if (card) card.remove();
        }

        // Destroy chart if exists
        if (this.charts[instanceId]) {
            this.charts[instanceId].destroy();
            delete this.charts[instanceId];
        }

        // Remove from config
        delete this.dashboardConfig.widgets.instances?.[instanceId];
        delete this.dashboardConfig.widgets.visibility?.[instanceId];
        delete this.dashboardConfig.widgets.sizes?.[instanceId];
        delete this.dashboardConfig.widgets.positions?.[instanceId];
        delete this.dashboardConfig.widgets.tileSettings?.[instanceId];

        const orderIdx = this.dashboardConfig.widgets.order?.indexOf(instanceId);
        if (orderIdx > -1) this.dashboardConfig.widgets.order.splice(orderIdx, 1);

        this.saveDashboardVisibility('widgets');
        this.updateAddTilesMenu();

        showSuccess(t('budget', 'Tile removed'));
    }

}
