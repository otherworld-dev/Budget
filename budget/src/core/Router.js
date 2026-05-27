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
                const href = link.getAttribute('href');
                // Let external links open naturally (e.g., Help & Docs)
                if (!href || !href.startsWith('#')) return;

                e.preventDefault();
                const view = href.substring(1);
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

    /**
     * Map of view names to their load methods on the app.
     * Used by both showView() and reloadCurrentView() to avoid duplication.
     */
    static VIEW_LOADERS = {
        'dashboard': 'loadDashboard',
        'accounts': 'loadAccounts',
        'transactions': 'loadTransactions',
        'categories': 'loadCategories',
        'tags': 'loadTagsView',
        'budget': 'loadBudgetView',
        'forecast': 'loadForecastView',
        'reports': 'loadReportsView',
        'bills': 'loadBillsView',
        'transfers': 'loadTransfersView',
        'rules': 'loadRulesView',
        'income': 'loadIncomeView',
        'savings-goals': 'loadSavingsGoalsView',
        'debt-payoff': 'loadDebtPayoffView',
        'shared-expenses': 'loadSharedExpensesView',
        'pensions': 'loadPensionsView',
        'assets': 'loadAssetsView',
        'exchange-rates': 'loadExchangeRatesView',
        'sharing': 'loadSharingView',
        'bank-sync': 'loadBankSyncView',
        'settings': 'loadSettingsView',
    };

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

            // Update help panel if open
            if (typeof this.app._updateHelpContent === 'function') {
                this.app._updateHelpContent();
            }

            // Load view-specific data
            const loader = Router.VIEW_LOADERS[viewName];
            if (loader) {
                this.app[loader]();
            }
        }
    }

    reloadCurrentView() {
        const viewName = this.app.currentView;
        // Don't reload settings view (we're already in it)
        if (viewName === 'settings') return;

        const loader = Router.VIEW_LOADERS[viewName];
        if (loader) {
            this.app[loader]();
        }
    }
}
