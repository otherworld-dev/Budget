/**
 * Dashboard widget registry - defines all available widgets
 */
import { translate as t } from '@nextcloud/l10n';

export const DASHBOARD_WIDGETS = {
    hero: {
        netWorth: { id: 'hero-net-worth', name: t('budget', 'Net Worth'), defaultSize: 'hero', defaultVisible: true, settingsSchema: {} },
        income: { id: 'hero-income', name: t('budget', 'Income This Month'), defaultSize: 'hero', defaultVisible: true, settingsSchema: {} },
        expenses: { id: 'hero-expenses', name: t('budget', 'Expenses This Month'), defaultSize: 'hero', defaultVisible: true, settingsSchema: {} },
        savings: { id: 'hero-savings', name: t('budget', 'Net Savings'), defaultSize: 'hero', defaultVisible: true, settingsSchema: {} },
        pension: { id: 'hero-pension', name: t('budget', 'Pension Worth'), defaultSize: 'hero', defaultVisible: true, settingsSchema: {} },
        assets: { id: 'hero-assets', name: t('budget', 'Assets Worth'), defaultSize: 'hero', defaultVisible: true, settingsSchema: {} },

        // Phase 1 - Quick Wins (use existing data)
        savingsRate: { id: 'hero-savings-rate', name: t('budget', 'Savings Rate'), defaultSize: 'hero', defaultVisible: false, category: 'insights', settingsSchema: {} },
        cashFlow: { id: 'hero-cash-flow', name: t('budget', 'Cash Flow'), defaultSize: 'hero', defaultVisible: false, category: 'insights', settingsSchema: {} },
        budgetRemaining: { id: 'hero-budget-remaining', name: t('budget', 'Budget Remaining'), defaultSize: 'hero', defaultVisible: false, category: 'budgeting', settingsSchema: {} },
        budgetHealth: { id: 'hero-budget-health', name: t('budget', 'Budget Health'), defaultSize: 'hero', defaultVisible: false, category: 'budgeting', settingsSchema: {} },

        // Per-Account Views
        accountIncome: { id: 'hero-account-income', name: t('budget', 'Account Income'), defaultSize: 'hero', defaultVisible: false, category: 'accounts', settingsSchema: {} },
        accountExpenses: { id: 'hero-account-expenses', name: t('budget', 'Account Expenses'), defaultSize: 'hero', defaultVisible: false, category: 'accounts', settingsSchema: {} },

        // Phase 2 - Moderate Complexity (lazy loaded)
        uncategorizedCount: { id: 'hero-uncategorized', name: t('budget', 'Uncategorized'), defaultSize: 'hero', defaultVisible: false, category: 'alerts', settingsSchema: {} },
        lowBalanceAlert: { id: 'hero-low-balance', name: t('budget', 'Low Balance Alert'), defaultSize: 'hero', defaultVisible: false, category: 'alerts', settingsSchema: {} },

        // Phase 3 - Advanced Features (lazy loaded with charts)
        burnRate: { id: 'hero-burn-rate', name: t('budget', 'Burn Rate'), defaultSize: 'hero', defaultVisible: false, category: 'forecasting', settingsSchema: {} },
        daysUntilDebtFree: { id: 'hero-debt-free', name: t('budget', 'Days Until Debt Free'), defaultSize: 'hero', defaultVisible: false, category: 'debts', settingsSchema: { displayFormat: ['compact', 'full'], showChangeIndicator: true } }
    },
    widgets: {
        trendChart: { id: 'trend-chart-card', name: t('budget', 'Income vs Expenses'), defaultSize: 'l', allowedSizes: ['s', 'm', 'l'], defaultVisible: true, settingsSchema: { dateRange: true, accountSelector: true, showLegend: true, chartType: ['bar', 'line'] } },
        spendingChart: { id: 'spending-chart-card', name: t('budget', 'Spending by Category'), defaultSize: 'm', allowedSizes: ['s', 'm', 'l'], defaultVisible: true, settingsSchema: { dateRange: true, accountSelector: true, showLegend: true, chartType: ['doughnut', 'bar'] } },
        netWorthHistory: { id: 'net-worth-history-card', name: t('budget', 'Net Worth History'), defaultSize: 'm', allowedSizes: ['s', 'm', 'l'], defaultVisible: true, settingsSchema: { dateRange: true, accountSelector: true, showLegend: true } },
        assetValueHistory: { id: 'asset-value-history-card', name: t('budget', 'Asset Value History'), defaultSize: 'm', allowedSizes: ['s', 'm', 'l'], defaultVisible: true, settingsSchema: { dateRange: true, accountSelector: true, showLegend: true } },
        recentTransactions: { id: 'recent-transactions-card', name: t('budget', 'Recent Transactions'), defaultSize: 'm', allowedSizes: ['s', 'm', 'l'], defaultVisible: true, settingsSchema: { dateRange: true, accountSelector: true, rowCount: { min: 3, max: 20, default: 5 } } },
        accounts: { id: 'accounts-card', name: t('budget', 'Accounts'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: true, settingsSchema: {} },
        budgetAlerts: { id: 'budget-alerts-card', name: t('budget', 'Budget Alerts'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: true, settingsSchema: {} },
        upcomingBills: { id: 'upcoming-bills-card', name: t('budget', 'Upcoming Bills'), defaultSize: 's', allowedSizes: ['s', 'm', 'l'], defaultVisible: true, settingsSchema: { dateRange: true, accountSelector: true, rowCount: { min: 3, max: 20, default: 5 } } },
        budgetProgress: { id: 'budget-progress-card', name: t('budget', 'Budget Progress'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: true, settingsSchema: {} },
        savingsGoals: { id: 'savings-goals-card', name: t('budget', 'Savings Goals'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: true, settingsSchema: {} },
        debtPayoff: { id: 'debt-payoff-card', name: t('budget', 'Debt Payoff'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: true, settingsSchema: {} },
        debtChart: {
            id: 'debt-chart-card',
            name: t('budget', 'Debt Payoff Chart'),
            defaultSize: 'm',
            allowedSizes: ['s', 'm', 'l'],
            defaultVisible: false,
            category: 'debts',
            settingsSchema: { dateRange: true, accountSelector: true, showLegend: true },
        },
        debtProgress: {
            id: 'debt-progress-card',
            name: t('budget', 'Debt Progress'),
            defaultSize: 's',
            allowedSizes: ['xs', 's', 'm', 'l'],
            defaultVisible: false,
            category: 'debts',
            settingsSchema: {},
        },

        // Phase 1 - Quick Wins (use existing data)
        topCategories: { id: 'top-categories-card', name: t('budget', 'Top Spending Categories'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: false, category: 'insights', settingsSchema: {} },
        accountPerformance: { id: 'account-performance-card', name: t('budget', 'Account Performance'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: false, category: 'insights', settingsSchema: {} },
        budgetBreakdown: { id: 'budget-breakdown-card', name: t('budget', 'Budget Breakdown'), defaultSize: 'hero', defaultVisible: false, category: 'budgeting', settingsSchema: {} },
        goalsSummary: { id: 'goals-summary-card', name: t('budget', 'Savings Goals Summary'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: false, category: 'goals', settingsSchema: {} },
        paymentBreakdown: { id: 'payment-breakdown-card', name: t('budget', 'Payment Methods'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: false, category: 'insights', settingsSchema: {} },
        reconciliationStatus: { id: 'reconciliation-card', name: t('budget', 'Reconciliation Status'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: false, category: 'transactions', settingsSchema: {} },

        // Phase 2 - Moderate Complexity (lazy loaded)
        monthlyComparison: { id: 'monthly-comparison-card', name: t('budget', 'Monthly Comparison'), defaultSize: 'hero', defaultVisible: false, category: 'insights', settingsSchema: { dateRange: true, accountSelector: true, showLegend: true } },
        largeTransactions: { id: 'large-transactions-card', name: t('budget', 'Large Transactions'), defaultSize: 'hero', defaultVisible: false, category: 'transactions', settingsSchema: { dateRange: true, accountSelector: true, rowCount: { min: 3, max: 20, default: 5 } } },
        weeklyTrend: { id: 'weekly-trend-card', name: t('budget', 'Weekly Spending'), defaultSize: 's', allowedSizes: ['s', 'm', 'l'], defaultVisible: false, category: 'insights', settingsSchema: { dateRange: true, accountSelector: true, showLegend: true } },
        unmatchedTransfers: { id: 'unmatched-transfers-card', name: t('budget', 'Unmatched Transfers'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: false, category: 'transactions', settingsSchema: {} },
        categoryTrends: { id: 'category-trends-card', name: t('budget', 'Category Trends'), defaultSize: 'hero', defaultVisible: false, category: 'insights', settingsSchema: { dateRange: true, accountSelector: true, showLegend: true } },
        billsDueSoon: { id: 'bills-due-soon-card', name: t('budget', 'Bills Due Soon'), defaultSize: 's', allowedSizes: ['s', 'm', 'l'], defaultVisible: false, category: 'bills', settingsSchema: { dateRange: true, accountSelector: true, rowCount: { min: 3, max: 20, default: 5 } } },

        // Phase 3 - Advanced Features (lazy loaded with charts)
        cashFlowForecast: { id: 'cash-flow-forecast-card', name: t('budget', 'Cash Flow Forecast'), defaultSize: 'l', allowedSizes: ['s', 'm', 'l'], defaultVisible: false, category: 'forecasting', settingsSchema: { dateRange: true, accountSelector: true, showLegend: true } },
        yoyComparison: { id: 'yoy-comparison-card', name: t('budget', 'Year-over-Year'), defaultSize: 'l', allowedSizes: ['s', 'm', 'l'], defaultVisible: false, category: 'insights', settingsSchema: { dateRange: true, accountSelector: true, showLegend: true } },
        incomeTracking: { id: 'income-tracking-card', name: t('budget', 'Income Tracking'), defaultSize: 'hero', defaultVisible: false, category: 'income', settingsSchema: { dateRange: true, accountSelector: true, showLegend: true } },
        recentImports: { id: 'recent-imports-card', name: t('budget', 'Recent Imports'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: false, category: 'transactions', settingsSchema: {} },
        ruleEffectiveness: { id: 'rule-effectiveness-card', name: t('budget', 'Rule Effectiveness'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: false, category: 'insights', settingsSchema: {} },
        spendingVelocity: { id: 'spending-velocity-card', name: t('budget', 'Spending Velocity'), defaultSize: 's', allowedSizes: ['xs', 's', 'm', 'l'], defaultVisible: false, category: 'insights', settingsSchema: {} },

        // Phase 4 - Interactive Widgets
        quickAdd: { id: 'quick-add-card', name: t('budget', 'Quick Add Transaction'), defaultSize: 'hero', defaultVisible: false, category: 'interactive', settingsSchema: {} }
    }
};
