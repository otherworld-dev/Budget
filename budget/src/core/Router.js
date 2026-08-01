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
                this.showView(href.substring(1));

                // Close mobile navigation after selecting a view
                this.closeMobileNavigation();
            });
        });

        // Dashboard card links (View All, Manage, Details, etc.)
        document.addEventListener('click', (e) => {
            const cardLink = e.target.closest('.card-link');
            if (!cardLink) return;
            const href = cardLink.getAttribute('href');
            if (href && href.startsWith('#')) {
                e.preventDefault();
                this.showView(href.substring(1));
            }
        });

        // Browser back / forward: re-render the view encoded in the URL without
        // recording another history entry (that would fight the user).
        window.addEventListener('popstate', (e) => {
            const view = (e.state && e.state.view) || this.viewFromHash() || 'dashboard';
            this.showView(view, { history: false });
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
        'help': 'loadHelpView',
    };

    /**
     * Show a view and (by default) record it in browser history so the back /
     * forward buttons move between in-app views instead of leaving the app.
     *
     * @param {string} viewName
     * @param {object} [opts]
     * @param {boolean} [opts.history=true] Update browser history for this
     *   navigation. Pass false when the call is itself a response to a history
     *   event (popstate) or the initial page load, so we don't double-record it.
     */
    showView(viewName, { history = true } = {}) {
        // Hide all views
        document.querySelectorAll('.view').forEach(view => {
            view.classList.remove('active');
            view.style.display = ''; // Clear any inline display styles
        });

        // Show selected view
        const view = document.getElementById(`${viewName}-view`);
        if (!view) return;

        view.classList.add('active');
        this.app.currentView = viewName;
        this.setActiveNav(viewName);

        // Update help panel if open
        if (typeof this.app._updateHelpContent === 'function') {
            this.app._updateHelpContent();
        }

        // Load view-specific data
        const loader = Router.VIEW_LOADERS[viewName];
        if (loader) {
            this.app[loader]();
        }

        if (history) {
            this.syncHistory(viewName);
        }
    }

    /**
     * Highlight the matching left-nav entry (matched by its data-id) and clear
     * the rest. Views without a nav entry simply clear all highlights.
     */
    setActiveNav(viewName) {
        document.querySelectorAll('.app-navigation-entry').forEach(entry => {
            entry.classList.toggle('active', entry.dataset.id === viewName);
        });
    }

    /**
     * Read the view name from the URL hash (supports both #view and the
     * #/view?params deep-link form).
     */
    viewFromHash() {
        const m = window.location.hash.match(/^#\/?([a-z-]+)/);
        return m ? m[1] : null;
    }

    /**
     * Push (or replace) a history entry so back/forward navigate between views.
     * Re-selecting the current view replaces rather than pushes, keeping the
     * history stack free of duplicate adjacent entries.
     */
    syncHistory(viewName) {
        const currentView = (window.history.state && window.history.state.view) || this.viewFromHash();
        const state = { view: viewName };
        if (currentView === viewName) {
            window.history.replaceState(state, '', `#${viewName}`);
        } else {
            window.history.pushState(state, '', `#${viewName}`);
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
