/**
 * Dashboard widget registry - defines all available widgets
 */
import { translate as t } from '@nextcloud/l10n';

export const DASHBOARD_WIDGETS = {
    hero: {
        netWorth: { id: 'hero-net-worth', name: t('budget', 'Net Worth'), size: 'hero', defaultVisible: true },
        income: { id: 'hero-income', name: t('budget', 'Income This Month'), size: 'hero', defaultVisible: true },
        expenses: { id: 'hero-expenses', name: t('budget', 'Expenses This Month'), size: 'hero', defaultVisible: true },
        savings: { id: 'hero-savings', name: t('budget', 'Net Savings'), size: 'hero', defaultVisible: true },
        pension: { id: 'hero-pension', name: t('budget', 'Pension Worth'), size: 'hero', defaultVisible: true },
        assets: { id: 'hero-assets', name: t('budget', 'Assets Worth'), size: 'hero', defaultVisible: true },

        // Phase 1 - Quick Wins (use existing data)
        savingsRate: { id: 'hero-savings-rate', name: t('budget', 'Savings Rate'), size: 'hero', defaultVisible: false, category: 'insights' },
        cashFlow: { id: 'hero-cash-flow', name: t('budget', 'Cash Flow'), size: 'hero', defaultVisible: false, category: 'insights' },
        budgetRemaining: { id: 'hero-budget-remaining', name: t('budget', 'Budget Remaining'), size: 'hero', defaultVisible: false, category: 'budgeting' },
        budgetHealth: { id: 'hero-budget-health', name: t('budget', 'Budget Health'), size: 'hero', defaultVisible: false, category: 'budgeting' },

        // Per-Account Views
        accountIncome: { id: 'hero-account-income', name: t('budget', 'Account Income'), size: 'hero', defaultVisible: false, category: 'accounts' },
        accountExpenses: { id: 'hero-account-expenses', name: t('budget', 'Account Expenses'), size: 'hero', defaultVisible: false, category: 'accounts' },

        // Phase 2 - Moderate Complexity (lazy loaded)
        uncategorizedCount: { id: 'hero-uncategorized', name: t('budget', 'Uncategorized'), size: 'hero', defaultVisible: false, category: 'alerts' },
        lowBalanceAlert: { id: 'hero-low-balance', name: t('budget', 'Low Balance Alert'), size: 'hero', defaultVisible: false, category: 'alerts' },

        // Phase 3 - Advanced Features (lazy loaded with charts)
        burnRate: { id: 'hero-burn-rate', name: t('budget', 'Burn Rate'), size: 'hero', defaultVisible: false, category: 'forecasting' },
        daysUntilDebtFree: { id: 'hero-debt-free', name: t('budget', 'Days Until Debt Free'), size: 'hero', defaultVisible: false, category: 'debts' }
    },
    widgets: {
        trendChart: { id: 'trend-chart-card', name: t('budget', 'Income vs Expenses'), size: 'large', defaultVisible: true },
        spendingChart: { id: 'spending-chart-card', name: t('budget', 'Spending by Category'), size: 'medium', defaultVisible: true },
        netWorthHistory: { id: 'net-worth-history-card', name: t('budget', 'Net Worth History'), size: 'medium', defaultVisible: true },
        assetValueHistory: { id: 'asset-value-history-card', name: t('budget', 'Asset Value History'), size: 'medium', defaultVisible: true },
        recentTransactions: { id: 'recent-transactions-card', name: t('budget', 'Recent Transactions'), size: 'medium', defaultVisible: true },
        accounts: { id: 'accounts-card', name: t('budget', 'Accounts'), size: 'small', defaultVisible: true },
        budgetAlerts: { id: 'budget-alerts-card', name: t('budget', 'Budget Alerts'), size: 'small', defaultVisible: true },
        upcomingBills: { id: 'upcoming-bills-card', name: t('budget', 'Upcoming Bills'), size: 'small', defaultVisible: true },
        budgetProgress: { id: 'budget-progress-card', name: t('budget', 'Budget Progress'), size: 'small', defaultVisible: true },
        savingsGoals: { id: 'savings-goals-card', name: t('budget', 'Savings Goals'), size: 'small', defaultVisible: true },
        debtPayoff: { id: 'debt-payoff-card', name: t('budget', 'Debt Payoff'), size: 'small', defaultVisible: true },

        // Phase 1 - Quick Wins (use existing data)
        topCategories: { id: 'top-categories-card', name: t('budget', 'Top Spending Categories'), size: 'small', defaultVisible: false, category: 'insights' },
        accountPerformance: { id: 'account-performance-card', name: t('budget', 'Account Performance'), size: 'small', defaultVisible: false, category: 'insights' },
        budgetBreakdown: { id: 'budget-breakdown-card', name: t('budget', 'Budget Breakdown'), size: 'medium', defaultVisible: false, category: 'budgeting' },
        goalsSummary: { id: 'goals-summary-card', name: t('budget', 'Savings Goals Summary'), size: 'small', defaultVisible: false, category: 'goals' },
        paymentBreakdown: { id: 'payment-breakdown-card', name: t('budget', 'Payment Methods'), size: 'small', defaultVisible: false, category: 'insights' },
        reconciliationStatus: { id: 'reconciliation-card', name: t('budget', 'Reconciliation Status'), size: 'small', defaultVisible: false, category: 'transactions' },

        // Phase 2 - Moderate Complexity (lazy loaded)
        monthlyComparison: { id: 'monthly-comparison-card', name: t('budget', 'Monthly Comparison'), size: 'medium', defaultVisible: false, category: 'insights' },
        largeTransactions: { id: 'large-transactions-card', name: t('budget', 'Large Transactions'), size: 'medium', defaultVisible: false, category: 'transactions' },
        weeklyTrend: { id: 'weekly-trend-card', name: t('budget', 'Weekly Spending'), size: 'small', defaultVisible: false, category: 'insights' },
        unmatchedTransfers: { id: 'unmatched-transfers-card', name: t('budget', 'Unmatched Transfers'), size: 'small', defaultVisible: false, category: 'transactions' },
        categoryTrends: { id: 'category-trends-card', name: t('budget', 'Category Trends'), size: 'medium', defaultVisible: false, category: 'insights' },
        billsDueSoon: { id: 'bills-due-soon-card', name: t('budget', 'Bills Due Soon'), size: 'small', defaultVisible: false, category: 'bills' },

        // Phase 3 - Advanced Features (lazy loaded with charts)
        cashFlowForecast: { id: 'cash-flow-forecast-card', name: t('budget', 'Cash Flow Forecast'), size: 'large', defaultVisible: false, category: 'forecasting' },
        yoyComparison: { id: 'yoy-comparison-card', name: t('budget', 'Year-over-Year'), size: 'large', defaultVisible: false, category: 'insights' },
        incomeTracking: { id: 'income-tracking-card', name: t('budget', 'Income Tracking'), size: 'medium', defaultVisible: false, category: 'income' },
        recentImports: { id: 'recent-imports-card', name: t('budget', 'Recent Imports'), size: 'small', defaultVisible: false, category: 'transactions' },
        ruleEffectiveness: { id: 'rule-effectiveness-card', name: t('budget', 'Rule Effectiveness'), size: 'small', defaultVisible: false, category: 'insights' },
        spendingVelocity: { id: 'spending-velocity-card', name: t('budget', 'Spending Velocity'), size: 'small', defaultVisible: false, category: 'insights' },

        // Phase 4 - Interactive Widgets
        quickAdd: { id: 'quick-add-card', name: t('budget', 'Quick Add Transaction'), size: 'medium', defaultVisible: false, category: 'interactive' }
    }
};
