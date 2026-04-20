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

                // Close mobile navigation after selecting a view
                this.closeMobileNavigation();
            });
        });

        // Dashboard card links (View All, Manage, Details, etc.)
        document.addEventListener('click', (e) => {
            const cardLink = e.target.closest('.card-link');
            if (cardLink) {
                e.preventDefault();
                const href = cardLink.getAttribute('href');
                if (href && href.startsWith('#')) {
                    const view = href.substring(1);
                    this.showView(view);

                    // Update nav active state
                    document.querySelectorAll('.app-navigation-entry').forEach(entry => {
                        const navLink = entry.querySelector('a');
                        if (navLink && navLink.getAttribute('href') === href) {
                            document.querySelectorAll('.app-navigation-entry').forEach(e => e.classList.remove('active'));
                            entry.classList.add('active');
                        }
                    });
                }
            }
        });

        this.setupMobileNavigationToggle();
    }

    setupMobileNavigationToggle() {
        const toggle = document.getElementById('budget-nav-toggle');
        const nav = document.getElementById('app-navigation');
        const backdrop = document.getElementById('nav-backdrop');

        if (!toggle || !nav) {
            return;
        }

        toggle.addEventListener('click', () => {
            const isOpen = nav.classList.contains('nav-open');
            if (isOpen) {
                this.closeMobileNavigation();
            } else {
                this.openMobileNavigation();
            }
        });

        if (backdrop) {
            backdrop.addEventListener('click', () => {
                this.closeMobileNavigation();
            });
        }
    }

    openMobileNavigation() {
        const nav = document.getElementById('app-navigation');
        const wrapper = document.getElementById('budget-nav-toggle-wrapper');
        const backdrop = document.getElementById('nav-backdrop');
        const iconOpen = document.getElementById('nav-toggle-icon-open');
        const iconClose = document.getElementById('nav-toggle-icon-close');

        if (nav) nav.classList.add('nav-open');
        if (wrapper) wrapper.classList.add('nav-open');
        if (backdrop) backdrop.classList.add('active');
        if (iconOpen) iconOpen.style.display = 'none';
        if (iconClose) iconClose.style.display = '';
    }

    closeMobileNavigation() {
        const nav = document.getElementById('app-navigation');
        const wrapper = document.getElementById('budget-nav-toggle-wrapper');
        const backdrop = document.getElementById('nav-backdrop');
        const iconOpen = document.getElementById('nav-toggle-icon-open');
        const iconClose = document.getElementById('nav-toggle-icon-close');

        if (nav) nav.classList.remove('nav-open');
        if (wrapper) wrapper.classList.remove('nav-open');
        if (backdrop) backdrop.classList.remove('active');
        if (iconOpen) iconOpen.style.display = '';
        if (iconClose) iconClose.style.display = 'none';
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
                case 'tags':
                    this.app.loadTagsView();
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
                case 'transfers':
                    this.app.loadTransfersView();
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
                case 'assets':
                    this.app.loadAssetsView();
                    break;
                case 'exchange-rates':
                    this.app.loadExchangeRatesView();
                    break;
                case 'sharing':
                    this.app.loadSharingView();
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
            case 'tags':
                this.app.loadTagsView();
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
            case 'transfers':
                this.app.loadTransfersView();
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
            case 'assets':
                this.app.loadAssetsView();
                break;
            case 'exchange-rates':
                this.app.loadExchangeRatesView();
                break;
            case 'sharing':
                this.app.loadSharingView();
                break;
            case 'settings':
                // Don't reload settings view (we're already in it)
                break;
        }
    }
}
