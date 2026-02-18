/**
 * Dashboard widget registry - defines all available widgets
 */
export const DASHBOARD_WIDGETS = {
    hero: {
        netWorth: { id: 'hero-net-worth', name: 'Net Worth', size: 'hero', defaultVisible: true },
        income: { id: 'hero-income', name: 'Income This Month', size: 'hero', defaultVisible: true },
        expenses: { id: 'hero-expenses', name: 'Expenses This Month', size: 'hero', defaultVisible: true },
        savings: { id: 'hero-savings', name: 'Net Savings', size: 'hero', defaultVisible: true },
        pension: { id: 'hero-pension', name: 'Pension Worth', size: 'hero', defaultVisible: true },

        // Phase 1 - Quick Wins (use existing data)
        savingsRate: { id: 'hero-savings-rate', name: 'Savings Rate', size: 'hero', defaultVisible: false, category: 'insights' },
        cashFlow: { id: 'hero-cash-flow', name: 'Cash Flow', size: 'hero', defaultVisible: false, category: 'insights' },
        budgetRemaining: { id: 'hero-budget-remaining', name: 'Budget Remaining', size: 'hero', defaultVisible: false, category: 'budgeting' },
        budgetHealth: { id: 'hero-budget-health', name: 'Budget Health', size: 'hero', defaultVisible: false, category: 'budgeting' },

        // Per-Account Views
        accountIncome: { id: 'hero-account-income', name: 'Account Income', size: 'hero', defaultVisible: false, category: 'accounts' },
        accountExpenses: { id: 'hero-account-expenses', name: 'Account Expenses', size: 'hero', defaultVisible: false, category: 'accounts' },

        // Phase 2 - Moderate Complexity (lazy loaded)
        uncategorizedCount: { id: 'hero-uncategorized', name: 'Uncategorized', size: 'hero', defaultVisible: false, category: 'alerts' },
        lowBalanceAlert: { id: 'hero-low-balance', name: 'Low Balance Alert', size: 'hero', defaultVisible: false, category: 'alerts' },

        // Phase 3 - Advanced Features (lazy loaded with charts)
        burnRate: { id: 'hero-burn-rate', name: 'Burn Rate', size: 'hero', defaultVisible: false, category: 'forecasting' },
        daysUntilDebtFree: { id: 'hero-debt-free', name: 'Days Until Debt Free', size: 'hero', defaultVisible: false, category: 'debts' }
    },
    widgets: {
        trendChart: { id: 'trend-chart-card', name: 'Income vs Expenses', size: 'large', defaultVisible: true },
        spendingChart: { id: 'spending-chart-card', name: 'Spending by Category', size: 'medium', defaultVisible: true },
        netWorthHistory: { id: 'net-worth-history-card', name: 'Net Worth History', size: 'medium', defaultVisible: true },
        recentTransactions: { id: 'recent-transactions-card', name: 'Recent Transactions', size: 'medium', defaultVisible: true },
        accounts: { id: 'accounts-card', name: 'Accounts', size: 'small', defaultVisible: true },
        budgetAlerts: { id: 'budget-alerts-card', name: 'Budget Alerts', size: 'small', defaultVisible: true },
        upcomingBills: { id: 'upcoming-bills-card', name: 'Upcoming Bills', size: 'small', defaultVisible: true },
        budgetProgress: { id: 'budget-progress-card', name: 'Budget Progress', size: 'small', defaultVisible: true },
        savingsGoals: { id: 'savings-goals-card', name: 'Savings Goals', size: 'small', defaultVisible: true },
        debtPayoff: { id: 'debt-payoff-card', name: 'Debt Payoff', size: 'small', defaultVisible: true },

        // Phase 1 - Quick Wins (use existing data)
        topCategories: { id: 'top-categories-card', name: 'Top Spending Categories', size: 'small', defaultVisible: false, category: 'insights' },
        accountPerformance: { id: 'account-performance-card', name: 'Account Performance', size: 'small', defaultVisible: false, category: 'insights' },
        budgetBreakdown: { id: 'budget-breakdown-card', name: 'Budget Breakdown', size: 'medium', defaultVisible: false, category: 'budgeting' },
        goalsSummary: { id: 'goals-summary-card', name: 'Savings Goals Summary', size: 'small', defaultVisible: false, category: 'goals' },
        paymentBreakdown: { id: 'payment-breakdown-card', name: 'Payment Methods', size: 'small', defaultVisible: false, category: 'insights' },
        reconciliationStatus: { id: 'reconciliation-card', name: 'Reconciliation Status', size: 'small', defaultVisible: false, category: 'transactions' },

        // Phase 2 - Moderate Complexity (lazy loaded)
        monthlyComparison: { id: 'monthly-comparison-card', name: 'Monthly Comparison', size: 'medium', defaultVisible: false, category: 'insights' },
        largeTransactions: { id: 'large-transactions-card', name: 'Large Transactions', size: 'medium', defaultVisible: false, category: 'transactions' },
        weeklyTrend: { id: 'weekly-trend-card', name: 'Weekly Spending', size: 'small', defaultVisible: false, category: 'insights' },
        unmatchedTransfers: { id: 'unmatched-transfers-card', name: 'Unmatched Transfers', size: 'small', defaultVisible: false, category: 'transactions' },
        categoryTrends: { id: 'category-trends-card', name: 'Category Trends', size: 'medium', defaultVisible: false, category: 'insights' },
        billsDueSoon: { id: 'bills-due-soon-card', name: 'Bills Due Soon', size: 'small', defaultVisible: false, category: 'bills' },

        // Phase 3 - Advanced Features (lazy loaded with charts)
        cashFlowForecast: { id: 'cash-flow-forecast-card', name: 'Cash Flow Forecast', size: 'large', defaultVisible: false, category: 'forecasting' },
        yoyComparison: { id: 'yoy-comparison-card', name: 'Year-over-Year', size: 'large', defaultVisible: false, category: 'insights' },
        incomeTracking: { id: 'income-tracking-card', name: 'Income Tracking', size: 'medium', defaultVisible: false, category: 'income' },
        recentImports: { id: 'recent-imports-card', name: 'Recent Imports', size: 'small', defaultVisible: false, category: 'transactions' },
        ruleEffectiveness: { id: 'rule-effectiveness-card', name: 'Rule Effectiveness', size: 'small', defaultVisible: false, category: 'insights' },
        spendingVelocity: { id: 'spending-velocity-card', name: 'Spending Velocity', size: 'small', defaultVisible: false, category: 'insights' },

        // Phase 4 - Interactive Widgets
        quickAdd: { id: 'quick-add-card', name: 'Quick Add Transaction', size: 'medium', defaultVisible: false, category: 'interactive' }
    }
};
