/**
 * Router - Client-side navigation and view management
 */
export default class Router {
    constructor(app) {
        this.app = app;
    }

    setupNavigation() {
        document.querySelectorAll('.app-navigation-entry a').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const view = link.getAttribute('href').substring(1);
                this.showView(view);

                // Update active state on parent li
                document.querySelectorAll('.app-navigation-entry').forEach(entry =>
                    entry.classList.remove('active')
                );
                link.parentElement.classList.add('active');
            });
        });
    }

    showView(viewName) {
        // Hide all views
        document.querySelectorAll('.view').forEach(view => {
            view.classList.remove('active');
            view.style.display = ''; // Clear any inline display styles
        });

        // Show selected view
        const view = document.getElementById(`${viewName}-view`);
        if (view) {
            view.classList.add('active');
            this.app.currentView = viewName;

            // Load view-specific data
            switch (viewName) {
                case 'dashboard':
                    this.app.loadDashboard();
                    break;
                case 'accounts':
                    this.app.loadAccounts();
                    break;
                case 'transactions':
                    this.app.loadTransactions();
                    break;
                case 'categories':
                    this.app.loadCategories();
                    break;
                case 'budget':
                    this.app.loadBudgetView();
                    break;
                case 'forecast':
                    this.app.loadForecastView();
                    break;
                case 'reports':
                    this.app.loadReportsView();
                    break;
                case 'bills':
                    this.app.loadBillsView();
                    break;
                case 'rules':
                    this.app.loadRulesView();
                    break;
                case 'income':
                    this.app.loadIncomeView();
                    break;
                case 'savings-goals':
                    this.app.loadSavingsGoalsView();
                    break;
                case 'debt-payoff':
                    this.app.loadDebtPayoffView();
                    break;
                case 'shared-expenses':
                    this.app.loadSharedExpensesView();
                    break;
                case 'pensions':
                    this.app.loadPensionsView();
                    break;
                case 'settings':
                    this.app.loadSettingsView();
                    break;
            }
        }
    }

    reloadCurrentView() {
        // Reload the current view to apply setting changes
        switch (this.app.currentView) {
            case 'dashboard':
                this.app.loadDashboard();
                break;
            case 'accounts':
                this.app.loadAccounts();
                break;
            case 'transactions':
                this.app.loadTransactions();
                break;
            case 'categories':
                this.app.loadCategories();
                break;
            case 'budget':
                this.app.loadBudgetView();
                break;
            case 'forecast':
                this.app.loadForecastView();
                break;
            case 'reports':
                this.app.loadReportsView();
                break;
            case 'bills':
                this.app.loadBillsView();
                break;
            case 'rules':
                this.app.loadRulesView();
                break;
            case 'income':
                this.app.loadIncomeView();
                break;
            case 'savings-goals':
                this.app.loadSavingsGoalsView();
                break;
            case 'debt-payoff':
                this.app.loadDebtPayoffView();
                break;
            case 'shared-expenses':
                this.app.loadSharedExpensesView();
                break;
            case 'pensions':
                this.app.loadPensionsView();
                break;
            case 'settings':
                // Don't reload settings view (we're already in it)
                break;
        }
    }
}
