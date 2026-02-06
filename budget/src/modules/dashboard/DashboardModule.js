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

export default class DashboardModule {
    constructor(app) {
        this.app = app;
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
            const [summaryResponse, trendResponse, transResponse, billsResponse, budgetResponse, goalsResponse, pensionResponse, netWorthResponse, alertsResponse, debtResponse] = await Promise.all([
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
                fetch(OC.generateUrl('/apps/budget/api/net-worth/snapshots?days=30'), {
                    headers: { 'requesttoken': OC.requestToken }
                }).catch(() => ({ ok: false })),
                fetch(OC.generateUrl('/apps/budget/api/alerts'), {
                    headers: { 'requesttoken': OC.requestToken }
                }).catch(() => ({ ok: false })),
                fetch(OC.generateUrl('/apps/budget/api/debts/summary'), {
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
            const netWorthSnapshots = netWorthResponse.ok ? await netWorthResponse.json() : [];
            const budgetAlerts = alertsResponse.ok ? await alertsResponse.json() : [];
            const debtSummary = debtResponse.ok ? await debtResponse.json() : null;

            // Update Hero Section (current month data)
            this.updateDashboardHero(summary);

            // Update Account Widget (current balances from current month summary)
            this.updateAccountsWidget(summary.accounts || []);

            // Update Budget Alerts Widget
            this.updateBudgetAlertsWidget(budgetAlerts);

            // Update Recent Transactions
            this.updateRecentTransactions(transactions);

            // Update Upcoming Bills Widget
            this.updateUpcomingBillsWidget(bills);

            // Update Budget Progress Widget
            this.updateBudgetProgressWidget(budgetData.categories || []);

            // Update Savings Goals Widget
            this.updateSavingsGoalsWidget(savingsGoals);

            // Update Pension Dashboard Card
            this.updatePensionsSummary(pensionSummary);

            // Update Debt Payoff Dashboard Card
            this.updateDebtPayoffWidget(debtSummary);

            // Phase 1: Update New Hero Tiles (use existing data)
            this.updateSavingsRateHero(summary);
            this.updateCashFlowHero(summary);
            this.updateBudgetRemainingHero(budgetData);
            this.updateBudgetHealthHero(budgetAlerts);

            // Phase 1: Update New Widget Tiles (use existing data)
            if (trendData.spending) {
                this.updateTopCategoriesWidget(trendData.spending);
            }
            this.updateAccountPerformanceWidget(summary.accounts || []);
            this.updateBudgetBreakdownWidget(budgetData.categories || []);
            this.updateGoalsSummaryWidget(savingsGoals);
            this.updatePaymentBreakdownWidget(summary.accounts || []);
            this.updateReconciliationStatusWidget(summary.accounts || []);

            // Update Charts (using 6-month trend data)
            if (trendData.spending) {
                this.updateSpendingChart(trendData.spending);
            }
            if (trendData.trends) {
                this.updateTrendChart(trendData.trends);
            }

            // Update Net Worth History Chart
            this.updateNetWorthHistoryChart(netWorthSnapshots);

            // Setup dashboard controls
            this.setupDashboardControls();

            // Apply dashboard widget order (must be before visibility)
            this.applyDashboardOrder();

            // Apply dashboard widget visibility
            this.applyDashboardVisibility();

            // Setup drag-and-drop for dashboard customization
            this.setupDashboardDragAndDrop();

            // Apply responsive layout ordering
            this.applyDashboardLayout();

        } catch (error) {
            console.error('Failed to load dashboard:', error);
        }
    }

    // ===========================
    // Hero Tile Updates
    // ===========================

    updateDashboardHero(summary) {
        const totals = summary.totals || {};
        const currency = this.getPrimaryCurrency();

        // Net Worth (total balance across all accounts)
        const netWorthEl = document.getElementById('hero-net-worth-value');
        if (netWorthEl) {
            const netWorth = totals.currentBalance || 0;
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
                    incomeChangeEl.innerHTML = `${change >= 0 ? '↑' : '↓'} ${Math.abs(change).toFixed(1)}% vs last month`;
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
                    expensesChangeEl.innerHTML = `${change >= 0 ? '↑' : '↓'} ${Math.abs(change).toFixed(1)}% vs last month`;
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
                savingsRateEl.textContent = `${savingsRate >= 0 ? '' : '-'}${Math.abs(savingsRate).toFixed(1)}% savings rate`;
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
            changeEl.innerHTML = `<span class="trend-icon ${trend}">${icon} ${rate >= 20 ? 'Great' : rate >= 10 ? 'Good' : 'Low'}</span>`;
            changeEl.className = `hero-change ${trend}`;
        }
    }

    updateCashFlowHero(summary) {
        const el = document.getElementById('hero-cash-flow-value');
        if (!el || !summary?.totals) return;

        const income = summary.totals.totalIncome || 0;
        const expenses = summary.totals.totalExpenses || 0;
        const cashFlow = income - expenses;

        el.textContent = this.formatCurrency(cashFlow, this.getPrimaryCurrency());
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
                    changeEl.innerHTML = `${change >= 0 ? '↑' : '↓'} ${Math.abs(change).toFixed(1)}% vs last month`;
                    changeEl.className = `hero-change ${change >= 0 ? 'positive' : 'negative'}`;
                }
            }
        }
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
            changeEl.textContent = `${categoryCount} categories under budget`;
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
            changeEl.textContent = `${onTrack}/${totalBudgets} on track`;
        }
    }

    // ===========================
    // Widget Updates
    // ===========================

    updateAccountsWidget(accounts) {
        const container = document.getElementById('accounts-summary');
        if (!container || !Array.isArray(accounts)) return;

        if (accounts.length === 0) {
            container.innerHTML = '<div class="empty-state-small">No accounts yet</div>';
            return;
        }

        const accountTypeIcons = {
            checking: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>',
            savings: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.5 3.5L18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2v14H3v3c0 1.11.89 2 2 2h14c1.11 0 2-.89 2-2v-3h-3V2l-1.5 1.5zM19 19H5v-1h14v1z"/></svg>',
            credit_card: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>',
            investment: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/></svg>',
            cash: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.67 0-1.72 1.39-2.84 3.11-3.21V4h2.67v1.95c1.86.45 2.79 1.86 2.85 3.39H14.3c-.05-1.11-.64-1.87-2.22-1.87-1.5 0-2.4.68-2.4 1.64 0 .84.65 1.39 2.67 1.91s4.18 1.39 4.18 3.91c-.01 1.83-1.38 2.83-3.12 3.16z"/></svg>',
            loan: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 14V6c0-1.1-.9-2-2-2H3C1.9 4 1 4.9 1 6v8c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zm-2 0H3V6h14v8zm-7-7c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3zm13 0v11c0 1.1-.9 2-2 2H4v-2h17V7h2z"/></svg>'
        };

        container.innerHTML = accounts.slice(0, 5).map(account => {
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
                            <div class="account-widget-type">${type.replace('_', ' ')}</div>
                        </div>
                    </div>
                    <div class="account-widget-balance">
                        <div class="account-widget-amount ${balance >= 0 ? 'positive' : 'negative'}">
                            ${this.formatCurrency(balance, currency)}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    updateRecentTransactions(transactions) {
        const container = document.getElementById('recent-transactions');
        if (!container) return;

        if (!Array.isArray(transactions) || transactions.length === 0) {
            container.innerHTML = '<div class="empty-state-small">No recent transactions</div>';
            return;
        }

        container.innerHTML = transactions.slice(0, 8).map(tx => {
            const isCredit = tx.type === 'credit';
            const amount = parseFloat(tx.amount) || 0;
            const category = this.categories.find(c => c.id === tx.categoryId || c.id === tx.category_id);
            const categoryName = category ? category.name : 'Uncategorized';
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
                            <div class="recent-transaction-description">${this.escapeHtml(tx.description || tx.vendor || 'Transaction')}</div>
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
                ? `${Math.round(alert.percentage - 100)}% over`
                : `${Math.round(alert.percentage)}% used`;

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
                estimateEl.innerHTML = `<span class="debt-hint">Highest rate: ${summary.highestInterestRate.toFixed(1)}% APR</span>`;
            } else {
                estimateEl.innerHTML = '';
            }
        }
    }

    updateUpcomingBillsWidget(bills) {
        const container = document.getElementById('upcoming-bills');
        if (!container) return;

        if (!Array.isArray(bills) || bills.length === 0) {
            container.innerHTML = '<div class="empty-state-small">No upcoming bills</div>';
            return;
        }

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        container.innerHTML = bills.slice(0, 5).map(bill => {
            const dueDate = new Date(bill.nextDueDate || bill.next_due_date);
            const daysUntilDue = Math.ceil((dueDate - today) / (1000 * 60 * 60 * 24));

            let statusClass = '';
            let dueText = '';

            if (daysUntilDue < 0) {
                statusClass = 'overdue';
                dueText = `Overdue by ${Math.abs(daysUntilDue)} day${Math.abs(daysUntilDue) !== 1 ? 's' : ''}`;
            } else if (daysUntilDue === 0) {
                statusClass = 'due-soon';
                dueText = 'Due today';
            } else if (daysUntilDue <= 7) {
                statusClass = 'due-soon';
                dueText = `Due in ${daysUntilDue} day${daysUntilDue !== 1 ? 's' : ''}`;
            } else {
                dueText = `Due ${dueDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`;
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

    updateBudgetProgressWidget(categories) {
        const container = document.getElementById('budget-progress');
        if (!container) return;

        if (!Array.isArray(categories) || categories.length === 0) {
            container.innerHTML = '<div class="empty-state-small">No budgets configured</div>';
            return;
        }

        // Filter to only categories with budgets
        const budgetedCategories = categories.filter(c => c.budgeted > 0 || c.budget > 0);

        if (budgetedCategories.length === 0) {
            container.innerHTML = '<div class="empty-state-small">No budgets configured</div>';
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

    updateSavingsGoalsWidget(goals) {
        const container = document.getElementById('savings-goals-summary');
        if (!container) return;

        if (!Array.isArray(goals) || goals.length === 0) {
            container.innerHTML = '<div class="empty-state-small">No savings goals yet</div>';
            return;
        }

        container.innerHTML = goals.slice(0, 3).map(goal => {
            const target = goal.targetAmount || goal.target_amount || 0;
            const current = goal.currentAmount || goal.current_amount || 0;
            const percentage = target > 0 ? Math.min((current / target) * 100, 100) : 0;
            const remaining = Math.max(target - current, 0);

            return `
                <div class="savings-goal-item">
                    <div class="savings-goal-header">
                        <div class="savings-goal-name">${this.escapeHtml(goal.name)}</div>
                        <div class="savings-goal-target">Target: ${this.formatCurrency(target)}</div>
                    </div>
                    <div class="savings-goal-progress">
                        <div class="savings-goal-fill" style="width: ${percentage}%"></div>
                    </div>
                    <div class="savings-goal-footer">
                        <span class="savings-goal-current">${this.formatCurrency(current)} saved</span>
                        <span>${percentage.toFixed(0)}%</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    updatePensionsSummary(summary) {
        const currency = this.getPrimaryCurrency();
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
                if (heroPensionLabel) heroPensionLabel.textContent = 'Pension Worth';
            } else if (projectedIncome > 0) {
                heroPensionValue.textContent = this.formatCurrency(projectedIncome, currency) + '/yr';
                if (heroPensionLabel) heroPensionLabel.textContent = 'Pension Income';
            } else {
                heroPensionValue.textContent = this.formatCurrency(0, currency);
                if (heroPensionLabel) heroPensionLabel.textContent = 'Pension Worth';
            }
        }
        if (heroPensionCount) {
            let subtext = count === 1 ? '1 pension' : `${count} pensions`;
            // If showing income but also have some pot value, mention it
            if (pensionWorth > 0 && projectedIncome > 0) {
                subtext += ` · ${this.formatCurrency(projectedIncome, currency)}/yr income`;
            }
            heroPensionCount.textContent = subtext;
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
                container.innerHTML = '<div class="empty-state-small">No spending data</div>';
                return;
            }
            spendingData = spending;
        } else if (typeof spending === 'object') {
            const entries = Object.entries(spending);
            if (entries.length === 0) {
                container.innerHTML = '<div class="empty-state-small">No spending data</div>';
                return;
            }
            spendingData = entries.map(([categoryId, amount]) => ({ categoryId: parseInt(categoryId), amount }));
        } else {
            container.innerHTML = '<div class="empty-state-small">No spending data</div>';
            return;
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
            container.innerHTML = '<div class="empty-state-small">No account data</div>';
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
            container.innerHTML = '<div class="empty-state-small">No budget data</div>';
            return;
        }

        container.innerHTML = `
            <table class="budget-breakdown-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Budget</th>
                        <th>Spent</th>
                        <th>Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    ${categories.map(cat => {
                        const budget = cat.budgeted || cat.budget || 0;
                        const spent = cat.spent || 0;
                        const remaining = budget - spent;
                        const percentage = budget > 0 ? (spent / budget * 100) : 0;
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
            container.innerHTML = '<div class="empty-state-small">No savings goals</div>';
            return;
        }

        container.innerHTML = goals.map(goal => {
            const target = goal.targetAmount || goal.target_amount || 0;
            const current = goal.currentAmount || goal.current_amount || 0;
            const percentage = target > 0 ? Math.min((current / target) * 100, 100) : 0;

            return `
                <div class="goal-summary-item">
                    <div class="goal-summary-header">
                        <span class="goal-name">${this.escapeHtml(goal.name)}</span>
                        <span class="goal-percentage">${percentage.toFixed(0)}%</span>
                    </div>
                    <div class="goal-summary-progress">
                        <div class="goal-summary-fill" style="width: ${percentage}%"></div>
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
            container.innerHTML = '<div class="empty-state-small">No account data</div>';
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
            'checking': 'Checking',
            'savings': 'Savings',
            'credit': 'Credit Cards',
            'investment': 'Investments',
            'loan': 'Loans',
            'Other': 'Other'
        };

        container.innerHTML = Object.entries(breakdown).map(([type, data]) => `
            <div class="payment-method-item">
                <div class="payment-method-header">
                    <span class="payment-method-name">${typeLabels[type] || type}</span>
                    <span class="payment-method-count">${data.count} accounts</span>
                </div>
                <div class="payment-method-total">${this.formatCurrency(data.total)}</div>
            </div>
        `).join('');
    }

    updateReconciliationStatusWidget(accounts) {
        const container = document.getElementById('reconciliation-status-list');
        if (!container || !Array.isArray(accounts)) return;

        if (accounts.length === 0) {
            container.innerHTML = '<div class="empty-state-small">No accounts to reconcile</div>';
            return;
        }

        // In a real implementation, this would track unreconciled transactions
        // For now, show account status
        const accountsToReconcile = accounts.map(acc => ({
            name: acc.name,
            unreconciledCount: 0,  // Would be fetched from API
            lastReconciled: null    // Would be fetched from API
        })).filter(a => true);  // Would filter to only show accounts needing reconciliation

        if (accountsToReconcile.length === 0) {
            container.innerHTML = '<div class="empty-state-small">All accounts reconciled</div>';
            return;
        }

        container.innerHTML = accountsToReconcile.slice(0, 5).map(account => `
            <div class="reconciliation-item">
                <div class="reconciliation-name">${this.escapeHtml(account.name)}</div>
                <div class="reconciliation-status">
                    <span class="reconciliation-badge">Up to date</span>
                </div>
            </div>
        `).join('');
    }

    // ===========================
    // Chart Updates
    // ===========================

    updateSpendingChart(spending) {
        const canvas = document.getElementById('spending-chart');
        if (!canvas) return;

        // Destroy existing chart
        if (this.charts.spending) {
            this.charts.spending.destroy();
        }

        // Handle both object and array formats
        let spendingData;
        if (Array.isArray(spending)) {
            // If it's an array, check if it's empty
            if (spending.length === 0) return;
            spendingData = spending;
        } else if (typeof spending === 'object') {
            // If it's an object, convert to array format
            const entries = Object.entries(spending);
            if (entries.length === 0) return;
            spendingData = entries.map(([categoryId, amount]) => ({ categoryId: parseInt(categoryId), amount }));
        } else {
            return;
        }

        const ctx = canvas.getContext('2d');

        // Sort by absolute amount and take top 10
        const sortedData = spendingData
            .sort((a, b) => Math.abs(b.amount) - Math.abs(a.amount))
            .slice(0, 10);

        // Extract data - API already returns name and color in each item
        const labels = sortedData.map(item => {
            // Use the name directly from the spending item (API includes it)
            return item.name || 'Unknown';
        });

        const data = sortedData.map(item => Math.abs(item.total || item.amount || 0));
        const colors = sortedData.map(item => {
            // Use the color directly from the spending item (API includes it)
            return item.color || '#999';
        });

        this.charts.spending = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data,
                    backgroundColor: colors
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false  // Hide built-in legend, we'll use custom one
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                return `${context.label}: ${this.formatCurrency(context.raw)}`;
                            }
                        }
                    }
                },
                layout: {
                    padding: 10
                }
            }
        });

        // Populate custom legend with spending breakdown
        const legendContainer = document.getElementById('spending-chart-legend');
        if (legendContainer) {
            const totalSpending = data.reduce((sum, val) => sum + val, 0);
            legendContainer.innerHTML = `
                <div class="spending-breakdown">
                    <div class="spending-breakdown-header">
                        <strong>Total Spending</strong>
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

    updateTrendChart(trends) {
        const canvas = document.getElementById('trend-chart');
        if (!canvas) {
            return;
        }

        // Destroy existing chart
        if (this.charts.trend) {
            this.charts.trend.destroy();
        }

        if (!trends || !trends.labels || trends.labels.length === 0) {
            return;
        }

        const ctx = canvas.getContext('2d');
        this.charts.trend = new Chart(ctx, {
            type: 'line',
            data: {
                labels: trends.labels,
                datasets: [
                    {
                        label: 'Income',
                        data: trends.income || [],
                        borderColor: '#46ba61',
                        backgroundColor: 'rgba(70, 186, 97, 0.1)',
                        fill: false,
                        tension: 0.3
                    },
                    {
                        label: 'Expenses',
                        data: trends.expenses || [],
                        borderColor: '#e9322d',
                        backgroundColor: 'rgba(233, 50, 45, 0.1)',
                        fill: false,
                        tension: 0.3
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
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                return `${context.dataset.label}: ${this.formatCurrency(context.raw)}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: (value) => this.formatCurrency(value)
                        }
                    }
                }
            }
        });
    }

    updateNetWorthHistoryChart(snapshots) {
        const canvas = document.getElementById('net-worth-chart');
        const emptyState = document.getElementById('net-worth-chart-empty');
        if (!canvas) return;

        // Destroy existing chart
        if (this.charts.netWorth) {
            this.charts.netWorth.destroy();
        }

        // Handle empty state
        if (!snapshots || snapshots.length === 0) {
            canvas.style.display = 'none';
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        // Show canvas, hide empty state
        canvas.style.display = 'block';
        if (emptyState) emptyState.style.display = 'none';

        const currency = this.getPrimaryCurrency();
        const labels = snapshots.map(s => s.date);
        const netWorthData = snapshots.map(s => s.netWorth);
        const assetsData = snapshots.map(s => s.totalAssets);
        const liabilitiesData = snapshots.map(s => s.totalLiabilities);

        this.charts.netWorth = new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Net Worth',
                        data: netWorthData,
                        borderColor: '#46ba61',
                        backgroundColor: 'rgba(70, 186, 97, 0.1)',
                        fill: true,
                        tension: 0.3,
                        borderWidth: 2
                    },
                    {
                        label: 'Assets',
                        data: assetsData,
                        borderColor: '#0082c9',
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0.3,
                        borderWidth: 1.5
                    },
                    {
                        label: 'Liabilities',
                        data: liabilitiesData,
                        borderColor: '#e9322d',
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0.3,
                        borderWidth: 1.5
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

    // ===========================
    // Dashboard Controls
    // ===========================

    setupDashboardControls() {
        // Trend period selector
        const trendPeriodSelect = document.getElementById('trend-period-select');
        if (trendPeriodSelect && !trendPeriodSelect.hasAttribute('data-initialized')) {
            trendPeriodSelect.setAttribute('data-initialized', 'true');
            trendPeriodSelect.addEventListener('change', async (e) => {
                const months = parseInt(e.target.value);
                await this.refreshTrendChart(months);
            });
        }

        // Spending period selector
        const spendingPeriodSelect = document.getElementById('spending-period-select');
        if (spendingPeriodSelect && !spendingPeriodSelect.hasAttribute('data-initialized')) {
            spendingPeriodSelect.setAttribute('data-initialized', 'true');
            spendingPeriodSelect.addEventListener('change', async (e) => {
                const period = e.target.value;
                await this.refreshSpendingChart(period);
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
                    // Refresh chart with new period
                    const days = parseInt(e.target.dataset.days);
                    await this.refreshNetWorthChart(days);
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
    }

    async refreshTrendChart(months) {
        try {
            const startDate = new Date();
            startDate.setMonth(startDate.getMonth() - months);

            const response = await fetch(
                OC.generateUrl(`/apps/budget/api/reports/summary?startDate=${formatters.formatDateForAPI(startDate)}`),
                { headers: { 'requesttoken': OC.requestToken } }
            );
            const data = await response.json();

            if (data.trends) {
                this.updateTrendChart(data.trends);
            }
        } catch (error) {
            console.error('Failed to refresh trend chart:', error);
        }
    }

    async refreshSpendingChart(period) {
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
                case 'year':
                    startDate = new Date(endDate.getFullYear(), 0, 1);
                    break;
            }

            const response = await fetch(
                OC.generateUrl(`/apps/budget/api/reports/spending?startDate=${formatters.formatDateForAPI(startDate)}&endDate=${formatters.formatDateForAPI(endDate)}`),
                { headers: { 'requesttoken': OC.requestToken } }
            );
            const data = await response.json();

            if (data.data) {
                this.updateSpendingChart(data.data);
            }
        } catch (error) {
            console.error('Failed to refresh spending chart:', error);
        }
    }

    async refreshNetWorthChart(days) {
        try {
            const response = await fetch(
                OC.generateUrl(`/apps/budget/api/net-worth/snapshots?days=${days}`),
                { headers: { 'requesttoken': OC.requestToken } }
            );
            if (!response.ok) throw new Error('Failed to fetch net worth snapshots');
            const snapshots = await response.json();
            this.updateNetWorthHistoryChart(snapshots);
        } catch (error) {
            console.error('Failed to refresh net worth chart:', error);
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

            OC.Notification.showTemporary('Net worth snapshot recorded');

            // Refresh the chart with current period
            const activeBtn = document.querySelector('#net-worth-period-selector .period-btn.active');
            const days = activeBtn ? parseInt(activeBtn.dataset.days) : 30;
            await this.refreshNetWorthChart(days);
        } catch (error) {
            console.error('Failed to record net worth snapshot:', error);
            OC.Notification.showTemporary('Failed to record snapshot');
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

            // Merge: preserve user settings, add any new widgets from defaults
            const allWidgetIds = new Set([...saved.order, ...defaults.order]);
            const mergedOrder = saved.order.filter(id => allWidgetIds.has(id));

            // Append any new widgets that aren't in saved order
            defaults.order.forEach(id => {
                if (!mergedOrder.includes(id)) {
                    mergedOrder.push(id);
                }
            });

            return {
                order: mergedOrder,
                visibility: { ...defaults.visibility, ...saved.visibility }
            };
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
            const widget = DASHBOARD_WIDGETS.widgets[key];
            if (!widget) continue;

            const element = document.querySelector(`[data-widget-id="${key}"][data-widget-category="widget"]`);
            if (!element) continue;

            // Lazy load data if becoming visible and not yet loaded
            if (visible && this.app.needsLazyLoad(key) && !this.widgetDataLoaded[key]) {
                await this.app.loadWidgetData(key);
                // Call the appropriate update method
                const updateMethod = `update${key.charAt(0).toUpperCase() + key.slice(1)}Widget`;
                if (typeof this[updateMethod] === 'function') {
                    this[updateMethod]();
                }
            }

            // Initialize Quick Add form when it becomes visible (Phase 4)
            if (visible && key === 'quickAdd' && !this.widgetDataLoaded[key]) {
                this.app.initQuickAddForm();
                this.widgetDataLoaded[key] = true;
            }

            // Respect conditional widgets (Budget Alerts, Debt Payoff)
            if (visible) {
                const hasConditionalHide = element.hasAttribute('style') &&
                                           element.getAttribute('style').includes('display: none') &&
                                           (key === 'budgetAlerts' || key === 'debtPayoff');
                if (!hasConditionalHide) {
                    element.style.display = '';
                }
            } else {
                element.style.display = 'none';
            }
        }
    }

    async hideWidget(widgetId, category) {
        const config = category === 'hero' ? this.dashboardConfig.hero : this.dashboardConfig.widgets;

        // Update visibility
        config.visibility[widgetId] = false;

        // Apply to DOM
        await this.applyDashboardVisibility();

        // Update Add Tiles menu
        this.app.updateAddTilesMenu();

        // Save to backend
        await this.saveDashboardVisibility();
    }

    async showWidget(widgetId, category) {
        const config = category === 'hero' ? this.dashboardConfig.hero : this.dashboardConfig.widgets;

        // Update visibility
        config.visibility[widgetId] = true;

        // Apply to DOM
        await this.applyDashboardVisibility();

        // Add remove buttons if unlocked
        if (!this.dashboardLocked) {
            this.app.addRemoveButtons();
        }

        // Update Add Tiles menu
        this.app.updateAddTilesMenu();

        // Save to backend
        await this.saveDashboardVisibility();
    }

    async saveDashboardVisibility() {
        try {
            const settings = {
                dashboard_hero_config: JSON.stringify(this.dashboardConfig.hero),
                dashboard_widgets_config: JSON.stringify(this.dashboardConfig.widgets)
            };

            const response = await fetch(OC.generateUrl('/apps/budget/api/settings'), {
                method: 'PUT',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(settings)
            });

            if (!response.ok) {
                throw new Error('Failed to save dashboard config');
            }

            this.settings.dashboard_hero_config = settings.dashboard_hero_config;
            this.settings.dashboard_widgets_config = settings.dashboard_widgets_config;

        } catch (error) {
            console.error('Failed to save dashboard config:', error);
            OC.Notification.showTemporary('Failed to save dashboard layout');
        }
    }

    setupDashboardDragAndDrop() {
        // Implementation for dashboard drag and drop would go here
        // This is a complex feature that allows reordering dashboard tiles
        console.log('Dashboard drag and drop setup');
    }

    applyDashboardOrder() {
        // Reorder hero cards
        const heroContainer = document.querySelector('.dashboard-hero');
        if (heroContainer) {
            const fragment = document.createDocumentFragment();
            this.dashboardConfig.hero.order.forEach(widgetId => {
                const card = heroContainer.querySelector(`[data-widget-id="${widgetId}"]`);
                if (card) {
                    fragment.appendChild(card);
                }
            });
            heroContainer.appendChild(fragment);
        }

        // For widgets, we need to handle two columns
        // Simpler approach: collect all widgets, sort by order, then redistribute
        const mainColumn = document.querySelector('.dashboard-column-main');
        const sideColumn = document.querySelector('.dashboard-column-side');

        if (!mainColumn || !sideColumn) return;

        // Collect all widget cards
        const allCards = [];
        document.querySelectorAll('[data-widget-category="widget"]').forEach(card => {
            allCards.push(card);
        });

        // Sort by configured order
        allCards.sort((a, b) => {
            const aIndex = this.dashboardConfig.widgets.order.indexOf(a.dataset.widgetId);
            const bIndex = this.dashboardConfig.widgets.order.indexOf(b.dataset.widgetId);
            return aIndex - bIndex;
        });

        // Remove all widget cards from both columns first
        allCards.forEach(card => card.remove());

        // Redistribute: first 4 to main, rest to side (matching original layout)
        const fragment1 = document.createDocumentFragment();
        const fragment2 = document.createDocumentFragment();

        allCards.forEach((card, index) => {
            if (index < 4) {
                fragment1.appendChild(card);
            } else {
                fragment2.appendChild(card);
            }
        });

        mainColumn.appendChild(fragment1);
        sideColumn.appendChild(fragment2);
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
                case 'uncategorizedCount':
                    const uncatResp = await fetch(
                        OC.generateUrl('/apps/budget/api/transactions/uncategorized?limit=100'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.uncategorizedCount = await uncatResp.json();
                    break;

                case 'monthlyComparison':
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

                case 'largeTransactions':
                    const largeResp = await fetch(
                        OC.generateUrl('/apps/budget/api/transactions?limit=10&sort=amount'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.largeTransactions = await largeResp.json();
                    break;

                // Phase 3 cases
                case 'cashFlowForecast':
                    const forecastResp = await fetch(
                        OC.generateUrl('/apps/budget/api/forecast/live?days=90'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.cashFlowForecast = await forecastResp.json();
                    break;

                case 'yoyComparison':
                    const yoyResp = await fetch(
                        OC.generateUrl('/apps/budget/api/yoy/years?years=2'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.yoyComparison = await yoyResp.json();
                    break;

                case 'incomeTracking':
                    const incomeResp = await fetch(
                        OC.generateUrl('/apps/budget/api/recurring-income/summary'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.incomeTracking = await incomeResp.json();
                    break;

                case 'daysUntilDebtFree':
                    const debtResp = await fetch(
                        OC.generateUrl('/apps/budget/api/debts/payoff-plan?strategy=avalanche'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.daysUntilDebtFree = await debtResp.json();
                    break;

                case 'recentImports':
                    // Placeholder - would use /api/import/history if it exists
                    this.widgetData.recentImports = [];
                    break;

                case 'ruleEffectiveness':
                    const rulesResp = await fetch(
                        OC.generateUrl('/apps/budget/api/import-rules'),
                        { headers: { 'requesttoken': OC.requestToken } }
                    );
                    this.widgetData.ruleEffectiveness = await rulesResp.json();
                    break;
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
    }

    async toggleDashboardLock() {
        this.app.dashboardLocked = !this.app.dashboardLocked;

        // Update UI immediately
        this.updateDashboardLockUI();

        // Apply/remove draggable state
        this.setupDashboardDragAndDrop();

        // Save state to backend
        try {
            const settings = {
                dashboard_locked: this.app.dashboardLocked.toString()
            };

            const response = await fetch(OC.generateUrl('/apps/budget/api/settings'), {
                method: 'PUT',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(settings)
            });

            if (!response.ok) {
                throw new Error('Failed to save lock state');
            }

            this.settings.dashboard_locked = settings.dashboard_locked;

        } catch (error) {
            console.error('Failed to save lock state:', error);
            OC.Notification.showTemporary('Failed to save dashboard lock state');
        }
    }

    updateDashboardLockUI() {
        const btn = document.getElementById('toggle-dashboard-lock-btn');
        const btnText = document.getElementById('lock-btn-text');
        const hint = document.getElementById('dashboard-hint');
        const icon = btn?.querySelector('.icon-lock, .icon-unlock');
        const addTilesDropdown = document.getElementById('add-tiles-dropdown');

        if (!btn || !btnText || !hint) return;

        if (this.dashboardLocked) {
            // Locked state
            btnText.textContent = 'Unlock Dashboard';
            hint.querySelector('span:last-child').textContent = 'Dashboard is locked. Click unlock to reorder tiles.';
            if (icon) {
                icon.classList.remove('icon-unlock');
                icon.classList.add('icon-lock');
            }
            // Hide Add Tiles button
            if (addTilesDropdown) addTilesDropdown.style.display = 'none';
            // Remove all X buttons
            document.querySelectorAll('.widget-remove-btn').forEach(btn => btn.remove());
        } else {
            // Unlocked state
            btnText.textContent = 'Lock Dashboard';
            hint.querySelector('span:last-child').textContent = 'Drag tiles to reorder your dashboard';
            if (icon) {
                icon.classList.remove('icon-lock');
                icon.classList.add('icon-unlock');
            }
            // Show Add Tiles button
            if (addTilesDropdown) addTilesDropdown.style.display = 'block';
            // Add X buttons to all visible widgets
            this.addRemoveButtons();
        }

        // Update Add Tiles dropdown content
        this.updateAddTilesMenu();
    }

    addRemoveButtons() {
        // Add remove button to hero cards
        document.querySelectorAll('.hero-card').forEach(card => {
            if (card.querySelector('.widget-remove-btn')) return; // Already has button

            const removeBtn = document.createElement('button');
            removeBtn.className = 'widget-remove-btn';
            removeBtn.setAttribute('aria-label', 'Remove tile');
            removeBtn.innerHTML = '&times;';
            removeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.hideWidget(card.dataset.widgetId, 'hero');
            });
            card.appendChild(removeBtn);
        });

        // Add remove button to dashboard cards
        document.querySelectorAll('.dashboard-card').forEach(card => {
            if (card.querySelector('.widget-remove-btn')) return; // Already has button

            const removeBtn = document.createElement('button');
            removeBtn.className = 'widget-remove-btn';
            removeBtn.setAttribute('aria-label', 'Remove tile');
            removeBtn.innerHTML = '&times;';
            removeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.hideWidget(card.dataset.widgetId, 'widget');
            });
            card.appendChild(removeBtn);
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
                    size: widget.size
                });
            }
        });

        // Check if any tiles are hidden
        const totalHidden = Object.values(tilesByCategory).reduce((sum, tiles) => sum + tiles.length, 0);
        if (totalHidden === 0) {
            menuList.innerHTML = '<div class="add-tiles-empty">All tiles are visible</div>';
            return;
        }

        // Category display order and labels
        const categoryOrder = [
            { key: 'insights', label: 'Insights & Analytics' },
            { key: 'budgeting', label: 'Budgeting' },
            { key: 'forecasting', label: 'Forecasting' },
            { key: 'transactions', label: 'Transactions' },
            { key: 'income', label: 'Income' },
            { key: 'debts', label: 'Debts' },
            { key: 'goals', label: 'Goals' },
            { key: 'bills', label: 'Bills' },
            { key: 'alerts', label: 'Alerts' },
            { key: 'interactive', label: 'Interactive' },
            { key: 'other', label: 'Other' }
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
                    ? '<span class="tile-size-badge">Hero</span>'
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

        // Wire up add buttons
        menuList.querySelectorAll('.add-tile-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const widgetId = btn.dataset.widgetId;
                const category = btn.dataset.category;
                this.showWidget(widgetId, category);
            });
        });
    }

    // ===========================
    // Dashboard Drag and Drop
    // ===========================

    setupDashboardDragAndDrop() {
        // Check if touch device - disable drag on mobile
        const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
        if (isTouchDevice) {
            return; // Only allow drag-and-drop on desktop
        }

        // Set draggable based on lock state
        const isDraggable = !this.dashboardLocked;

        // Make hero cards draggable
        document.querySelectorAll('.hero-card').forEach(card => {
            card.draggable = isDraggable;

            card.addEventListener('dragstart', (e) => {
                const widgetId = card.dataset.widgetId;
                e.dataTransfer.setData('text/plain', JSON.stringify({
                    id: widgetId,
                    category: 'hero'
                }));
                card.classList.add('dragging');
            });

            card.addEventListener('dragend', (e) => {
                card.classList.remove('dragging');
                this.clearDashboardDropIndicators();
            });
        });

        // Make dashboard cards draggable
        document.querySelectorAll('.dashboard-card').forEach(card => {
            card.draggable = isDraggable;

            card.addEventListener('dragstart', (e) => {
                const widgetId = card.dataset.widgetId;
                e.dataTransfer.setData('text/plain', JSON.stringify({
                    id: widgetId,
                    category: 'widget'
                }));
                card.classList.add('dragging');
            });

            card.addEventListener('dragend', (e) => {
                card.classList.remove('dragging');
                this.clearDashboardDropIndicators();
            });
        });

        // Setup drop zones
        const heroContainer = document.querySelector('.dashboard-hero');
        const mainColumn = document.querySelector('.dashboard-column-main');
        const sideColumn = document.querySelector('.dashboard-column-side');

        [heroContainer, mainColumn, sideColumn].forEach(container => {
            if (!container) return;

            container.addEventListener('dragover', (e) => {
                e.preventDefault();
                this.showDashboardDropIndicator(e, container);
            });

            container.addEventListener('drop', async (e) => {
                e.preventDefault();
                this.clearDashboardDropIndicators();

                try {
                    const data = JSON.parse(e.dataTransfer.getData('text/plain'));
                    const dropInfo = this.getDashboardDropTarget(e, container);

                    if (dropInfo) {
                        await this.reorderDashboardWidget(data.id, dropInfo.targetId, dropInfo.position, data.category);
                    }
                } catch (error) {
                    console.error('Drop failed:', error);
                }
            });

            container.addEventListener('dragleave', (e) => {
                if (!container.contains(e.relatedTarget)) {
                    this.clearDashboardDropIndicators();
                }
            });
        });
    }

    showDashboardDropIndicator(e, container) {
        e.preventDefault();

        // Find the card we're hovering over
        const cards = Array.from(container.children).filter(el =>
            el.classList.contains('hero-card') || el.classList.contains('dashboard-card')
        );

        const draggingCard = document.querySelector('.dragging');
        const afterCard = this.getDragAfterElement(container, e.clientY);

        // Remove existing indicators
        this.clearDashboardDropIndicators();

        // Add visual feedback
        if (afterCard) {
            afterCard.classList.add('drag-over');
            const indicator = document.createElement('div');
            indicator.className = 'drop-indicator';
            afterCard.parentElement.insertBefore(indicator, afterCard);
        } else {
            // Drop at the end
            const lastCard = cards[cards.length - 1];
            if (lastCard && lastCard !== draggingCard) {
                lastCard.classList.add('drag-over');
                const indicator = document.createElement('div');
                indicator.className = 'drop-indicator';
                container.appendChild(indicator);
            }
        }
    }

    getDragAfterElement(container, y) {
        const draggableElements = Array.from(container.children).filter(el =>
            (el.classList.contains('hero-card') || el.classList.contains('dashboard-card')) &&
            !el.classList.contains('dragging')
        );

        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;

            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    getDashboardDropTarget(e, container) {
        const afterCard = this.getDragAfterElement(container, e.clientY);

        if (afterCard) {
            return {
                targetId: afterCard.dataset.widgetId,
                position: 'before'
            };
        } else {
            // Drop at end - find last card in container
            const cards = Array.from(container.children).filter(el =>
                (el.classList.contains('hero-card') || el.classList.contains('dashboard-card')) &&
                !el.classList.contains('dragging')
            );
            const lastCard = cards[cards.length - 1];
            if (lastCard) {
                return {
                    targetId: lastCard.dataset.widgetId,
                    position: 'after'
                };
            }
        }
        return null;
    }

    clearDashboardDropIndicators() {
        document.querySelectorAll('.drop-indicator').forEach(el => el.remove());
        document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
    }

    async reorderDashboardWidget(draggedId, targetId, position, category) {
        // Determine which config to update
        const config = category === 'hero' ? this.dashboardConfig.hero : this.dashboardConfig.widgets;
        const order = [...config.order];

        // Find indices
        const draggedIndex = order.indexOf(draggedId);
        let targetIndex = order.indexOf(targetId);

        if (draggedIndex === -1 || targetIndex === -1) {
            console.warn('Widget not found in order array:', { draggedId, targetId, order, category });
            // If widget not in order, add it
            if (draggedIndex === -1 && targetIndex !== -1) {
                // Dragged widget not in order, insert it next to target
                if (position === 'before') {
                    order.splice(targetIndex, 0, draggedId);
                } else {
                    order.splice(targetIndex + 1, 0, draggedId);
                }
                config.order = order;
                await this.saveDashboardVisibility();
                this.applyDashboardOrder();
                return;
            } else {
                console.error('Cannot reorder - target not found');
                return;
            }
        }

        // Remove dragged item
        order.splice(draggedIndex, 1);

        // Adjust target index if needed
        if (draggedIndex < targetIndex) {
            targetIndex--;
        }

        // Insert at new position
        if (position === 'before') {
            order.splice(targetIndex, 0, draggedId);
        } else {
            order.splice(targetIndex + 1, 0, draggedId);
        }

        // Update config
        config.order = order;

        // Persist to backend
        try {
            const settingKey = category === 'hero' ? 'dashboard_hero_config' : 'dashboard_widgets_config';
            const settings = {
                [settingKey]: JSON.stringify(config)
            };

            const response = await fetch(OC.generateUrl('/apps/budget/api/settings'), {
                method: 'PUT',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(settings)
            });

            if (!response.ok) {
                throw new Error('Failed to save widget order');
            }

            this.settings[settingKey] = settings[settingKey];

        } catch (error) {
            console.error('Failed to save widget order:', error);
            OC.Notification.showTemporary('Failed to save widget order');
        }

        // Reorder DOM elements after config is saved
        this.applyDashboardOrder();

        // Update CSS layout properties
        this.applyDashboardLayout();
    }
}
