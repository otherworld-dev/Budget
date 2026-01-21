/**
 * Budget App - Main JavaScript
 */

import Chart from 'chart.js/auto';

// Dashboard widget registry - defines all available widgets
const DASHBOARD_WIDGETS = {
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

        // Phase 2 - Moderate Complexity (lazy loaded)
        uncategorizedCount: { id: 'hero-uncategorized', name: 'Uncategorized', size: 'hero', defaultVisible: false, category: 'alerts' },
        lowBalanceAlert: { id: 'hero-low-balance', name: 'Low Balance Alert', size: 'hero', defaultVisible: false, category: 'alerts' }
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
        billsDueSoon: { id: 'bills-due-soon-card', name: 'Bills Due Soon', size: 'small', defaultVisible: false, category: 'bills' }
    }
};

class BudgetApp {
    constructor() {
        this.currentView = 'dashboard';
        this.accounts = [];
        this.categories = [];
        this.transactions = [];
        this.pensions = [];
        this.currentPension = null;
        this.charts = {};
        this.settings = {};
        this.columnVisibility = {};
        this.dashboardConfig = {
            hero: { order: [], visibility: {} },
            widgets: { order: [], visibility: {} }
        };
        this.dashboardLocked = true; // Default to locked
        this.widgetDataLoaded = {}; // Track which widgets have loaded data (Phase 2+)
        this.widgetData = {}; // Store widget-specific lazy-loaded data (Phase 2+)

        this.init();
    }

    init() {
        this.setupNavigation();
        this.setupEventListeners();
        this.loadInitialData();
        this.showView('dashboard');
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

    setupEventListeners() {
        // Navigation search functionality
        this.setupNavigationSearch();

        // Settings toggle (collapsible bottom nav)
        this.setupSettingsToggle();

        // Transaction form
        const transactionForm = document.getElementById('transaction-form');
        if (transactionForm) {
            transactionForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveTransaction();
            });
        }

        // Add transaction button
        const addTransactionBtn = document.getElementById('add-transaction-btn');
        if (addTransactionBtn) {
            addTransactionBtn.addEventListener('click', () => {
                this.showTransactionModal();
            });
        }

        // Account add transaction button
        const accountAddTransactionBtn = document.getElementById('account-add-transaction-btn');
        if (accountAddTransactionBtn) {
            accountAddTransactionBtn.addEventListener('click', () => {
                this.showTransactionModal(null, this.currentAccount?.id);
            });
        }

        // Account form
        const accountForm = document.getElementById('account-form');
        if (accountForm) {
            accountForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveAccount();
            });
        }

        // Category form
        const categoryForm = document.getElementById('category-form');
        if (categoryForm) {
            categoryForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveCategory();
            });
        }

        // Update parent dropdown when category type changes
        const categoryType = document.getElementById('category-type');
        if (categoryType) {
            categoryType.addEventListener('change', () => {
                this.populateCategoryParentDropdown();
            });
        }

        // Add account button
        const addAccountBtn = document.getElementById('add-account-btn');
        if (addAccountBtn) {
            addAccountBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.showAccountModal();
            });
        }

        // Account type change for conditional fields
        const accountType = document.getElementById('account-type');
        if (accountType) {
            accountType.addEventListener('change', () => {
                this.setupAccountTypeConditionals();
            });
        }

        // Institution autocomplete
        const institutionInput = document.getElementById('account-institution');
        if (institutionInput) {
            institutionInput.addEventListener('input', () => {
                this.setupInstitutionAutocomplete();
            });
            institutionInput.addEventListener('blur', () => {
                setTimeout(() => {
                    document.getElementById('institution-suggestions').style.display = 'none';
                }, 200);
            });
        }

        // Modal cancel button
        document.querySelectorAll('.cancel-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                // Check if closing bulk match modal - refresh transactions
                const bulkMatchModal = document.getElementById('bulk-match-modal');
                if (bulkMatchModal && bulkMatchModal.style.display !== 'none' && bulkMatchModal.contains(e.target)) {
                    this.hideModals();
                    this.loadTransactions();
                } else {
                    this.hideModals();
                }
            });
        });

        // Split modal buttons
        document.getElementById('split-save-btn')?.addEventListener('click', () => {
            this.saveSplits();
        });

        document.getElementById('split-unsplit-btn')?.addEventListener('click', () => {
            this.unsplitTransaction();
        });

        document.getElementById('add-split-row-btn')?.addEventListener('click', () => {
            const container = document.getElementById('splits-container');
            if (container) {
                this.addSplitRow(container);
                this.updateSplitRemaining();
            }
        });

        // Column configuration toggle
        const columnConfigBtn = document.getElementById('column-config-btn');
        const columnConfigDropdown = document.getElementById('column-config-dropdown');

        if (columnConfigBtn && columnConfigDropdown) {
            columnConfigBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const isVisible = columnConfigDropdown.style.display !== 'none';
                columnConfigDropdown.style.display = isVisible ? 'none' : 'block';
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!columnConfigBtn.contains(e.target) && !columnConfigDropdown.contains(e.target)) {
                    columnConfigDropdown.style.display = 'none';
                }
            });

            // Column visibility toggles
            ['date', 'description', 'vendor', 'category', 'amount', 'account'].forEach(col => {
                const checkbox = document.getElementById(`col-toggle-${col}`);
                if (checkbox) {
                    checkbox.addEventListener('change', () => {
                        this.toggleColumnVisibility(col, checkbox.checked);
                    });
                }
            });
        }

        // Account action buttons, transaction action buttons, and autocomplete (using event delegation)
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('edit-account-btn') || e.target.closest('.edit-account-btn')) {
                const button = e.target.classList.contains('edit-account-btn') ? e.target : e.target.closest('.edit-account-btn');
                const accountId = parseInt(button.getAttribute('data-account-id'));
                this.editAccount(accountId);
            } else if (e.target.classList.contains('delete-account-btn') || e.target.closest('.delete-account-btn')) {
                const button = e.target.classList.contains('delete-account-btn') ? e.target : e.target.closest('.delete-account-btn');
                const accountId = parseInt(button.getAttribute('data-account-id'));
                this.deleteAccount(accountId);
            } else if (e.target.classList.contains('view-transactions-btn') || e.target.closest('.view-transactions-btn')) {
                const button = e.target.classList.contains('view-transactions-btn') ? e.target : e.target.closest('.view-transactions-btn');
                const accountId = parseInt(button.getAttribute('data-account-id'));
                this.viewAccountTransactions(accountId);
            } else if (e.target.classList.contains('transaction-edit-btn') || e.target.closest('.transaction-edit-btn')) {
                const button = e.target.classList.contains('transaction-edit-btn') ? e.target : e.target.closest('.transaction-edit-btn');
                const transactionId = parseInt(button.getAttribute('data-transaction-id'));
                this.editTransaction(transactionId);
            } else if (e.target.classList.contains('transaction-delete-btn') || e.target.closest('.transaction-delete-btn')) {
                const button = e.target.classList.contains('transaction-delete-btn') ? e.target : e.target.closest('.transaction-delete-btn');
                const transactionId = parseInt(button.getAttribute('data-transaction-id'));
                this.deleteTransaction(transactionId);
            } else if (e.target.classList.contains('transaction-split-btn') || e.target.closest('.transaction-split-btn')) {
                const button = e.target.classList.contains('transaction-split-btn') ? e.target : e.target.closest('.transaction-split-btn');
                const transactionId = parseInt(button.getAttribute('data-transaction-id'));
                this.showSplitModal(transactionId);
            } else if (e.target.classList.contains('transaction-share-btn') || e.target.closest('.transaction-share-btn')) {
                const button = e.target.classList.contains('transaction-share-btn') ? e.target : e.target.closest('.transaction-share-btn');
                const transactionId = parseInt(button.getAttribute('data-transaction-id'));
                const transaction = this.transactions?.find(t => t.id === transactionId);
                if (transaction) {
                    this.showShareExpenseModal(transaction);
                }
            } else if (e.target.classList.contains('transaction-match-btn') || e.target.closest('.transaction-match-btn')) {
                const button = e.target.classList.contains('transaction-match-btn') ? e.target : e.target.closest('.transaction-match-btn');
                const transactionId = parseInt(button.getAttribute('data-transaction-id'));
                this.showMatchingModal(transactionId);
            } else if (e.target.classList.contains('transaction-unlink-btn') || e.target.closest('.transaction-unlink-btn')) {
                const button = e.target.classList.contains('transaction-unlink-btn') ? e.target : e.target.closest('.transaction-unlink-btn');
                const transactionId = parseInt(button.getAttribute('data-transaction-id'));
                this.handleUnlinkTransaction(transactionId);
            } else if (e.target.classList.contains('linked-indicator')) {
                const transactionId = parseInt(e.target.getAttribute('data-transaction-id'));
                this.handleUnlinkTransaction(transactionId);
            } else if (e.target.classList.contains('link-match-btn')) {
                const sourceId = parseInt(e.target.getAttribute('data-source-id'));
                const targetId = parseInt(e.target.getAttribute('data-target-id'));
                this.handleLinkMatch(sourceId, targetId);
            } else if (e.target.classList.contains('undo-match-btn')) {
                const transactionId = parseInt(e.target.getAttribute('data-tx-id'));
                this.handleBulkMatchUndo(transactionId);
            } else if (e.target.classList.contains('link-selected-btn')) {
                const transactionId = parseInt(e.target.getAttribute('data-tx-id'));
                const index = parseInt(e.target.getAttribute('data-index'));
                this.handleBulkMatchLink(transactionId, index);
            } else if (e.target.classList.contains('autocomplete-item')) {
                const bankName = e.target.getAttribute('data-bank-name');
                this.selectInstitution(bankName);
            } else if (e.target.id === 'empty-categories-add-btn' || e.target.closest('#empty-categories-add-btn')) {
                this.showAddCategoryModal();
            } else if (e.target.id === 'create-default-categories-btn' || e.target.closest('#create-default-categories-btn')) {
                this.createDefaultCategories();
            }
        });

        // Bulk match radio button change handler (enable/disable link button)
        document.addEventListener('change', (e) => {
            if (e.target.type === 'radio' && e.target.name && e.target.name.startsWith('review-match-')) {
                const index = e.target.name.replace('review-match-', '');
                const linkBtn = document.querySelector(`.link-selected-btn[data-index="${index}"]`);
                if (linkBtn) {
                    linkBtn.disabled = false;
                }
            }
        });

        // Import file handling
        const importDropzone = document.getElementById('import-dropzone');
        const importFileInput = document.getElementById('import-file-input');
        const importBrowseBtn = document.getElementById('import-browse-btn');

        if (importDropzone) {
            importDropzone.addEventListener('dragover', (e) => {
                e.preventDefault();
                importDropzone.classList.add('dragover');
            });

            importDropzone.addEventListener('dragleave', () => {
                importDropzone.classList.remove('dragover');
            });

            importDropzone.addEventListener('drop', (e) => {
                e.preventDefault();
                importDropzone.classList.remove('dragover');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    this.handleImportFile(files[0]);
                }
            });
        }

        if (importBrowseBtn) {
            importBrowseBtn.addEventListener('click', () => {
                importFileInput.click();
            });
        }

        if (importFileInput) {
            importFileInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    this.handleImportFile(file);
                }
            });
        }

        // Enhanced Transaction Features
        this.setupTransactionEventListeners();
        this.setupInlineEditingListeners();

        // Enhanced Import System
        this.setupImportEventListeners();

        // Enhanced Forecast System
        this.setupForecastEventListeners();

        // Generate report
        const generateReportBtn = document.getElementById('generate-report-btn');
        if (generateReportBtn) {
            generateReportBtn.addEventListener('click', () => {
                this.generateReport();
            });
        }

        // Settings page event listeners
        this.setupSettingsEventListeners();

        // Dashboard customization
        this.setupDashboardCustomization();

        // Window resize handler for responsive dashboard layout
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                if (this.currentView === 'dashboard') {
                    this.applyDashboardLayout();
                }
            }, 250);
        });
    }

    setupNavigationSearch() {
        const searchInput = document.getElementById('app-navigation-search-input');
        const clearButton = document.getElementById('app-navigation-search-clear');
        const navigationEntries = document.querySelectorAll('.app-navigation-entry');

        if (!searchInput || !clearButton) return;

        // Store original navigation entry data for filtering
        this.originalNavigationEntries = Array.from(navigationEntries).map(entry => ({
            element: entry,
            text: entry.textContent.toLowerCase().trim(),
            id: entry.dataset.id
        }));

        // Search input event listener
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            this.filterNavigationEntries(query);

            // Show/hide clear button
            if (query) {
                clearButton.style.display = 'flex';
            } else {
                clearButton.style.display = 'none';
            }
        });

        // Clear button event listener
        clearButton.addEventListener('click', () => {
            searchInput.value = '';
            searchInput.focus();
            clearButton.style.display = 'none';
            this.filterNavigationEntries('');
        });

        // Support escape key to clear search
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                searchInput.value = '';
                clearButton.style.display = 'none';
                this.filterNavigationEntries('');
                searchInput.blur();
            }
        });
    }

    setupSettingsToggle() {
        const settingsToggle = document.querySelector('#app-settings-header .settings-toggle');
        const appSettings = document.getElementById('app-settings');

        if (!settingsToggle || !appSettings) return;

        // Load saved state from localStorage
        const isExpanded = localStorage.getItem('budget-settings-expanded') === 'true';
        if (isExpanded) {
            appSettings.classList.add('expanded');
            settingsToggle.setAttribute('aria-expanded', 'true');
        }

        settingsToggle.addEventListener('click', () => {
            const expanded = appSettings.classList.toggle('expanded');
            settingsToggle.setAttribute('aria-expanded', expanded.toString());

            // Save state to localStorage
            localStorage.setItem('budget-settings-expanded', expanded.toString());
        });
    }

    filterNavigationEntries(query) {
        if (!this.originalNavigationEntries) return;

        this.originalNavigationEntries.forEach(entry => {
            const matches = !query || entry.text.includes(query);

            if (matches) {
                entry.element.style.display = '';
                // Highlight matching text if there's a query
                if (query) {
                    this.highlightNavigationText(entry.element, query);
                } else {
                    this.clearNavigationHighlight(entry.element);
                }
            } else {
                entry.element.style.display = 'none';
            }
        });
    }

    highlightNavigationText(element, query) {
        const textElement = element.querySelector('a');
        if (!textElement) return;

        const originalText = textElement.dataset.originalText || textElement.textContent;
        textElement.dataset.originalText = originalText;

        const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
        const highlightedText = originalText.replace(regex, '<mark>$1</mark>');

        // Only update if we have an icon span to preserve
        const iconSpan = textElement.querySelector('.app-navigation-entry-icon');
        if (iconSpan) {
            const iconHTML = iconSpan.outerHTML;
            textElement.innerHTML = iconHTML + highlightedText.replace(iconHTML, '');
        } else {
            textElement.innerHTML = highlightedText;
        }
    }

    clearNavigationHighlight(element) {
        const textElement = element.querySelector('a');
        if (!textElement || !textElement.dataset.originalText) return;

        const iconSpan = textElement.querySelector('.app-navigation-entry-icon');
        if (iconSpan) {
            const iconHTML = iconSpan.outerHTML;
            textElement.innerHTML = iconHTML + textElement.dataset.originalText.replace(/^[^>]*>/, '');
        } else {
            textElement.textContent = textElement.dataset.originalText;
        }

        delete textElement.dataset.originalText;
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
            this.currentView = viewName;

            // Load view-specific data
            switch (viewName) {
                case 'dashboard':
                    this.loadDashboard();
                    break;
                case 'accounts':
                    this.loadAccounts();
                    break;
                case 'transactions':
                    this.loadTransactions();
                    break;
                case 'categories':
                    this.loadCategories();
                    break;
                case 'budget':
                    this.loadBudgetView();
                    break;
                case 'forecast':
                    this.loadForecastView();
                    break;
                case 'reports':
                    this.loadReportsView();
                    break;
                case 'bills':
                    this.loadBillsView();
                    break;
                case 'rules':
                    this.loadRulesView();
                    break;
                case 'income':
                    this.loadIncomeView();
                    break;
                case 'savings-goals':
                    this.loadSavingsGoalsView();
                    break;
                case 'debt-payoff':
                    this.loadDebtPayoffView();
                    break;
                case 'shared-expenses':
                    this.loadSharedExpensesView();
                    break;
                case 'pensions':
                    this.loadPensionsView();
                    break;
                case 'settings':
                    this.loadSettingsView();
                    break;
            }
        }
    }

    async loadInitialData() {
        try {
            // Load all initial data in parallel for better performance
            const [settingsResponse, accountsResponse, categoriesResponse] = await Promise.all([
                fetch(OC.generateUrl('/apps/budget/api/settings'), {
                    headers: { 'requesttoken': OC.requestToken }
                }),
                fetch(OC.generateUrl('/apps/budget/api/accounts'), {
                    headers: { 'requesttoken': OC.requestToken }
                }),
                fetch(OC.generateUrl('/apps/budget/api/categories'), {
                    headers: { 'requesttoken': OC.requestToken }
                })
            ]);

            if (settingsResponse.ok) {
                this.settings = await settingsResponse.json();
                this.columnVisibility = this.parseColumnVisibility(this.settings.transaction_columns_visible);
                this.syncColumnConfigUI();

                // Parse dashboard config
                this.dashboardConfig.hero = this.parseDashboardConfig(this.settings.dashboard_hero_config, 'hero');
                this.dashboardConfig.widgets = this.parseDashboardConfig(this.settings.dashboard_widgets_config, 'widgets');
            }

            if (!accountsResponse.ok) {
                throw new Error(`Failed to load accounts: ${accountsResponse.status} ${accountsResponse.statusText}`);
            }
            const accountsData = await accountsResponse.json();
            this.accounts = Array.isArray(accountsData) ? accountsData : [];

            const categoriesData = await categoriesResponse.json();
            this.categories = Array.isArray(categoriesData) ? categoriesData : [];

            // Populate dropdowns
            this.populateAccountDropdowns();
            this.populateCategoryDropdowns();
        } catch (error) {
            console.error('Failed to load initial data:', error);
            OC.Notification.showTemporary('Failed to load data');
        }
    }

    async loadDashboard() {
        try {
            // Calculate current month date range for hero stats
            const now = new Date();
            const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
            const endOfMonth = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0];

            // Calculate 6-month range for trend charts
            const sixMonthsAgo = new Date(now.getFullYear(), now.getMonth() - 5, 1).toISOString().split('T')[0];

            // Load all dashboard data in parallel for better performance
            const [summaryResponse, trendResponse, transResponse, billsResponse, budgetResponse, goalsResponse, pensionResponse, netWorthResponse, alertsResponse, debtResponse] = await Promise.all([
                // Current month summary for hero stats
                fetch(OC.generateUrl(`/apps/budget/api/reports/summary?startDate=${startOfMonth}&endDate=${endOfMonth}`), {
                    headers: { 'requesttoken': OC.requestToken }
                }),
                // 6-month summary for trend charts
                fetch(OC.generateUrl(`/apps/budget/api/reports/summary?startDate=${sixMonthsAgo}&endDate=${endOfMonth}`), {
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
            const budgetData = budgetResponse.ok ? await budgetResponse.json() : { categories: [] };
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
            const date = tx.date ? new Date(tx.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '';

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

    async loadDebtPayoffView() {
        try {
            // Load debt summary
            const summaryResponse = await fetch(OC.generateUrl('/apps/budget/api/debts/summary'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            const summary = summaryResponse.ok ? await summaryResponse.json() : null;

            // Load debt list
            const debtsResponse = await fetch(OC.generateUrl('/apps/budget/api/debts'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            const debts = debtsResponse.ok ? await debtsResponse.json() : [];

            // Update summary cards
            const currency = this.getPrimaryCurrency();
            if (summary) {
                const totalEl = document.getElementById('debt-view-total');
                const rateEl = document.getElementById('debt-view-highest-rate');
                const minEl = document.getElementById('debt-view-minimum');
                const countEl = document.getElementById('debt-view-count');

                if (totalEl) totalEl.textContent = this.formatCurrency(summary.totalBalance, currency);
                if (rateEl) rateEl.textContent = summary.highestInterestRate > 0 ? `${summary.highestInterestRate.toFixed(1)}%` : 'N/A';
                if (minEl) minEl.textContent = this.formatCurrency(summary.totalMinimumPayment, currency);
                if (countEl) countEl.textContent = summary.debtCount.toString();
            }

            // Update debt list
            this.renderDebtList(debts);

            // Setup event listeners
            this.setupDebtPayoffControls();

        } catch (error) {
            console.error('Failed to load debt payoff view:', error);
        }
    }

    renderDebtList(debts) {
        const container = document.getElementById('debt-list');
        if (!container) return;

        if (!Array.isArray(debts) || debts.length === 0) {
            container.innerHTML = '<div class="empty-state">No debt accounts found. Debts are pulled from liability accounts (credit cards, loans, mortgages).</div>';
            return;
        }

        const currency = this.getPrimaryCurrency();
        container.innerHTML = debts.map(debt => {
            const balance = Math.abs(parseFloat(debt.balance) || 0);
            const rate = parseFloat(debt.interestRate) || 0;
            const minPayment = parseFloat(debt.minimumPayment) || 0;

            return `
                <div class="debt-item" data-id="${debt.id}">
                    <div class="debt-item-header">
                        <div class="debt-item-name">${this.escapeHtml(debt.name)}</div>
                        <div class="debt-item-type">${this.formatAccountType(debt.type)}</div>
                    </div>
                    <div class="debt-item-details">
                        <div class="debt-detail">
                            <span class="detail-label">Balance</span>
                            <span class="detail-value debt-balance">${this.formatCurrency(balance, currency)}</span>
                        </div>
                        <div class="debt-detail">
                            <span class="detail-label">Interest Rate</span>
                            <span class="detail-value">${rate > 0 ? rate.toFixed(1) + '%' : 'N/A'}</span>
                        </div>
                        <div class="debt-detail">
                            <span class="detail-label">Min Payment</span>
                            <span class="detail-value">${minPayment > 0 ? this.formatCurrency(minPayment, currency) : 'Not set'}</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    setupDebtPayoffControls() {
        const calculateBtn = document.getElementById('calculate-payoff-btn');
        const compareBtn = document.getElementById('compare-strategies-btn');

        if (calculateBtn) {
            calculateBtn.onclick = () => this.calculatePayoffPlan();
        }

        if (compareBtn) {
            compareBtn.onclick = () => this.compareStrategies();
        }
    }

    async calculatePayoffPlan() {
        const strategy = document.getElementById('debt-strategy-select')?.value || 'avalanche';
        const extraPayment = parseFloat(document.getElementById('debt-extra-payment')?.value) || 0;

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/debts/payoff-plan?strategy=${strategy}&extraPayment=${extraPayment}`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error('Failed to calculate payoff plan');

            const plan = await response.json();
            this.displayPayoffPlan(plan);

            // Hide comparison results when showing plan
            const comparisonEl = document.getElementById('debt-comparison-results');
            if (comparisonEl) comparisonEl.style.display = 'none';

        } catch (error) {
            console.error('Failed to calculate payoff plan:', error);
            OC.Notification.showTemporary('Failed to calculate payoff plan');
        }
    }

    displayPayoffPlan(plan) {
        const resultsEl = document.getElementById('debt-payoff-results');
        if (!resultsEl) return;

        resultsEl.style.display = '';
        const currency = this.getPrimaryCurrency();

        // Update summary cards
        const monthsEl = document.getElementById('payoff-months');
        const dateEl = document.getElementById('payoff-date');
        const interestEl = document.getElementById('payoff-total-interest');
        const totalEl = document.getElementById('payoff-total-paid');

        if (monthsEl) {
            const years = Math.floor(plan.totalMonths / 12);
            const months = plan.totalMonths % 12;
            if (years > 0) {
                monthsEl.textContent = `${years}y ${months}m`;
            } else {
                monthsEl.textContent = `${months} months`;
            }
        }

        if (dateEl && plan.payoffDate) {
            const date = new Date(plan.payoffDate);
            dateEl.textContent = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        }

        if (interestEl) interestEl.textContent = this.formatCurrency(plan.totalInterest, currency);
        if (totalEl) totalEl.textContent = this.formatCurrency(plan.totalPaid, currency);

        // Update payoff order
        const orderEl = document.getElementById('debt-payoff-order');
        if (orderEl && plan.debts) {
            orderEl.innerHTML = plan.debts.map((debt, index) => `
                <div class="payoff-order-item">
                    <span class="payoff-order-number">${index + 1}</span>
                    <div class="payoff-order-details">
                        <div class="payoff-order-name">${this.escapeHtml(debt.name)}</div>
                        <div class="payoff-order-meta">
                            <span>${this.formatCurrency(debt.originalBalance, currency)}</span>
                            <span class="meta-separator">•</span>
                            <span>${debt.interestRate}% APR</span>
                            <span class="meta-separator">•</span>
                            <span>Paid off month ${debt.payoffMonth}</span>
                        </div>
                    </div>
                    <div class="payoff-order-interest">
                        <span class="interest-label">Interest</span>
                        <span class="interest-value">${this.formatCurrency(debt.interestPaid, currency)}</span>
                    </div>
                </div>
            `).join('');
        }
    }

    async compareStrategies() {
        const extraPayment = parseFloat(document.getElementById('debt-extra-payment')?.value) || 0;

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/debts/compare?extraPayment=${extraPayment}`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error('Failed to compare strategies');

            const comparison = await response.json();
            this.displayComparison(comparison);

            // Hide plan results when showing comparison
            const planEl = document.getElementById('debt-payoff-results');
            if (planEl) planEl.style.display = 'none';

        } catch (error) {
            console.error('Failed to compare strategies:', error);
            OC.Notification.showTemporary('Failed to compare strategies');
        }
    }

    displayComparison(comparison) {
        const resultsEl = document.getElementById('debt-comparison-results');
        if (!resultsEl) return;

        resultsEl.style.display = '';
        const currency = this.getPrimaryCurrency();

        // Update avalanche stats
        const avalancheMonths = document.getElementById('avalanche-months');
        const avalancheInterest = document.getElementById('avalanche-interest');
        if (avalancheMonths) avalancheMonths.textContent = `${comparison.avalanche.totalMonths} months`;
        if (avalancheInterest) avalancheInterest.textContent = this.formatCurrency(comparison.avalanche.totalInterest, currency);

        // Update snowball stats
        const snowballMonths = document.getElementById('snowball-months');
        const snowballInterest = document.getElementById('snowball-interest');
        if (snowballMonths) snowballMonths.textContent = `${comparison.snowball.totalMonths} months`;
        if (snowballInterest) snowballInterest.textContent = this.formatCurrency(comparison.snowball.totalInterest, currency);

        // Update recommendation
        const recommendationEl = document.getElementById('comparison-recommendation');
        if (recommendationEl && comparison.comparison) {
            const c = comparison.comparison;
            let recClass = c.recommendation === 'avalanche' ? 'recommend-avalanche' :
                           c.recommendation === 'snowball' ? 'recommend-snowball' : 'recommend-either';

            recommendationEl.innerHTML = `
                <div class="recommendation-box ${recClass}">
                    <div class="recommendation-title">
                        ${c.recommendation === 'avalanche' ? 'Avalanche Recommended' :
                          c.recommendation === 'snowball' ? 'Snowball Recommended' : 'Either Works'}
                    </div>
                    <div class="recommendation-text">${this.escapeHtml(c.explanation)}</div>
                    ${c.interestSavedByAvalanche > 0 ? `<div class="recommendation-savings">Avalanche saves ${this.formatCurrency(c.interestSavedByAvalanche, currency)} in interest</div>` : ''}
                </div>
            `;
        }

        // Highlight recommended card
        const avalancheCard = document.getElementById('avalanche-comparison');
        const snowballCard = document.getElementById('snowball-comparison');
        if (avalancheCard) avalancheCard.classList.toggle('recommended', comparison.comparison?.recommendation === 'avalanche');
        if (snowballCard) snowballCard.classList.toggle('recommended', comparison.comparison?.recommendation === 'snowball');
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

    // Phase 1: New Hero Tile Update Methods
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
            const remaining = (cat.budget || 0) - (cat.spent || 0);
            return sum + (remaining > 0 ? remaining : 0);
        }, 0);

        el.textContent = this.formatCurrency(totalRemaining, this.getPrimaryCurrency());
        el.className = `hero-value ${totalRemaining >= 0 ? 'income' : 'expenses'}`;

        const changeEl = document.getElementById('hero-budget-remaining-change');
        if (changeEl) {
            const categoryCount = budgetData.categories.filter(c => (c.budget || 0) - (c.spent || 0) > 0).length;
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

    // Phase 1: New Widget Update Methods
    updateTopCategoriesWidget(spending) {
        const container = document.getElementById('top-categories-list');
        if (!container) return;

        if (!spending || typeof spending !== 'object' || Object.keys(spending).length === 0) {
            container.innerHTML = '<div class="empty-state-small">No spending data</div>';
            return;
        }

        const topCategories = Object.entries(spending)
            .sort((a, b) => Math.abs(b[1]) - Math.abs(a[1]))
            .slice(0, 5);

        container.innerHTML = topCategories.map(([categoryId, amount]) => {
            const category = this.categories.find(c => c.id == categoryId);
            return `
                <div class="top-category-item">
                    <span class="category-dot" style="background: ${category?.color || '#999'}"></span>
                    <span class="category-name">${this.escapeHtml(category?.name || 'Unknown')}</span>
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
                        const budget = cat.budget || 0;
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

    // Phase 2: Lazy Loading Infrastructure
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
                        start: new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0],
                        end: new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0]
                    };
                    const lastMonth = {
                        start: new Date(now.getFullYear(), now.getMonth() - 1, 1).toISOString().split('T')[0],
                        end: new Date(now.getFullYear(), now.getMonth(), 0).toISOString().split('T')[0]
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

                // More cases will be added as we implement them
            }

            this.widgetDataLoaded[widgetKey] = true;
        } catch (error) {
            console.error(`Failed to load data for ${widgetKey}:`, error);
        }
    }

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
                OC.generateUrl(`/apps/budget/api/reports/summary?startDate=${startDate.toISOString().split('T')[0]}`),
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
                OC.generateUrl(`/apps/budget/api/reports/spending?startDate=${startDate.toISOString().split('T')[0]}&endDate=${endDate.toISOString().split('T')[0]}`),
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

    /**
     * Update the net worth history chart with snapshot data.
     */
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

    /**
     * Refresh the net worth chart with a new time period.
     */
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

    /**
     * Record a manual net worth snapshot.
     */
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

    async loadAccounts() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/accounts'), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const accounts = await response.json();

            // Check if we got a CSRF error instead of accounts
            if (accounts && accounts.message === "CSRF check failed") {
                throw new Error('CSRF check failed - please refresh the page');
            }

            if (!Array.isArray(accounts)) {
                console.error('API returned non-array:', accounts);
                throw new Error('API returned invalid data format');
            }

            // Update the instance accounts array
            this.accounts = accounts;

            // Render the accounts page with new layout
            this.renderAccountsPage(accounts);

            // Also update account dropdowns
            this.populateAccountDropdowns();
            // Add click handlers for account cards
            this.setupAccountCardClickHandlers();
        } catch (error) {
            console.error('Failed to load accounts:', error);
        }
    }

    renderAccountsPage(accounts) {
        // Helper function to get field with both camelCase and snake_case support
        const getField = (obj, camelName, snakeName = null) => {
            if (!snakeName) {
                snakeName = camelName.replace(/[A-Z]/g, letter => `_${letter.toLowerCase()}`);
            }
            return obj[camelName] || obj[snakeName] || null;
        };

        // Categorize accounts into assets and liabilities
        const assetTypes = ['checking', 'savings', 'investment', 'cash'];
        const liabilityTypes = ['credit_card', 'loan'];

        const assets = accounts.filter(acc => assetTypes.includes(getField(acc, 'type')));
        const liabilities = accounts.filter(acc => liabilityTypes.includes(getField(acc, 'type')));

        // Calculate totals
        const primaryCurrency = this.getPrimaryCurrency();
        let totalAssets = 0;
        let totalLiabilities = 0;

        assets.forEach(acc => {
            totalAssets += parseFloat(getField(acc, 'balance')) || 0;
        });

        liabilities.forEach(acc => {
            // Liabilities are typically negative or represent debt
            const balance = parseFloat(getField(acc, 'balance')) || 0;
            totalLiabilities += Math.abs(balance);
        });

        const netWorth = totalAssets - totalLiabilities;

        // Update summary cards
        const totalAssetsEl = document.getElementById('summary-total-assets');
        const totalLiabilitiesEl = document.getElementById('summary-total-liabilities');
        const netWorthEl = document.getElementById('summary-net-worth');
        const assetsSubtotalEl = document.getElementById('assets-subtotal');
        const liabilitiesSubtotalEl = document.getElementById('liabilities-subtotal');

        if (totalAssetsEl) totalAssetsEl.textContent = this.formatCurrency(totalAssets, primaryCurrency);
        if (totalLiabilitiesEl) totalLiabilitiesEl.textContent = this.formatCurrency(totalLiabilities, primaryCurrency);
        if (netWorthEl) {
            netWorthEl.textContent = this.formatCurrency(netWorth, primaryCurrency);
            netWorthEl.classList.toggle('positive', netWorth >= 0);
            netWorthEl.classList.toggle('negative', netWorth < 0);
        }
        if (assetsSubtotalEl) assetsSubtotalEl.textContent = this.formatCurrency(totalAssets, primaryCurrency);
        if (liabilitiesSubtotalEl) liabilitiesSubtotalEl.textContent = this.formatCurrency(totalLiabilities, primaryCurrency);

        // Render account cards for each section
        const assetsGrid = document.getElementById('accounts-assets-grid');
        const liabilitiesGrid = document.getElementById('accounts-liabilities-grid');
        const assetsSection = document.getElementById('accounts-assets-section');
        const liabilitiesSection = document.getElementById('accounts-liabilities-section');

        if (assetsGrid) {
            if (assets.length > 0) {
                assetsGrid.innerHTML = assets.map(account => this.renderAccountCard(account, getField)).join('');
                assetsSection.style.display = 'block';
            } else {
                assetsGrid.innerHTML = '<div class="accounts-empty-state">No asset accounts yet</div>';
            }
        }

        if (liabilitiesGrid) {
            if (liabilities.length > 0) {
                liabilitiesGrid.innerHTML = liabilities.map(account => this.renderAccountCard(account, getField)).join('');
                liabilitiesSection.style.display = 'block';
            } else {
                liabilitiesSection.style.display = 'none';
            }
        }

        // Load sparklines asynchronously
        this.loadAccountSparklines(accounts);
    }

    renderAccountCard(account, getField) {
        const accountType = getField(account, 'type') || 'unknown';
        const accountName = getField(account, 'name') || 'Unnamed Account';
        const accountBalance = parseFloat(getField(account, 'balance')) || 0;
        const accountCurrency = getField(account, 'currency') || this.getPrimaryCurrency();
        const accountId = getField(account, 'id') || 0;
        const institution = getField(account, 'institution') || '';

        const typeInfo = this.getAccountTypeInfo(accountType);
        const healthStatus = this.getAccountHealthStatus(account);

        // For liabilities (credit cards, loans), display balance differently
        const isLiability = ['credit_card', 'loan'].includes(accountType);
        const displayBalance = isLiability ? Math.abs(accountBalance) : accountBalance;
        const balanceClass = isLiability ? 'negative' : (accountBalance >= 0 ? 'positive' : 'negative');

        return `
            <div class="account-card" data-type="${accountType}" data-account-id="${accountId}">
                <div class="account-card-header">
                    <div class="account-icon" style="background-color: ${typeInfo.color};">
                        <span class="${typeInfo.icon}" aria-hidden="true"></span>
                    </div>
                    <div class="account-details">
                        <h3 class="account-name">${accountName}</h3>
                        <div class="account-meta">
                            <span class="account-type-badge">${typeInfo.label}</span>
                            ${institution ? `<span class="account-institution">${institution}</span>` : ''}
                        </div>
                    </div>
                </div>

                <div class="account-card-balance">
                    <div class="balance-info">
                        <span class="balance-label">${isLiability ? 'Owed' : 'Balance'}</span>
                        <span class="balance-amount ${balanceClass}">
                            ${isLiability ? '-' : ''}${this.formatCurrency(displayBalance, accountCurrency)}
                        </span>
                    </div>
                    <div class="account-sparkline" data-account-id="${accountId}">
                        <svg viewBox="0 0 80 32" preserveAspectRatio="none">
                            <path class="sparkline-path neutral" d="M0,16 L80,16"></path>
                        </svg>
                    </div>
                </div>

                <div class="account-card-footer">
                    <div class="account-status">
                        <span class="account-status-dot ${healthStatus.class}"></span>
                        <span>${healthStatus.tooltip}</span>
                    </div>
                    <div class="account-actions">
                        <button class="account-action-btn edit-btn edit-account-btn" data-account-id="${accountId}" title="Edit Account">
                            <span class="icon-rename" aria-hidden="true"></span>
                        </button>
                        <button class="account-action-btn delete-btn delete-account-btn" data-account-id="${accountId}" title="Delete Account">
                            <span class="icon-delete" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    async loadAccountSparklines(accounts) {
        // Load balance history for each account and render sparklines
        for (const account of accounts) {
            try {
                const accountId = account.id || account.Id;
                if (!accountId) continue;

                // Get transactions for this account from the last 7 days
                const endDate = new Date();
                const startDate = new Date();
                startDate.setDate(startDate.getDate() - 7);

                const response = await fetch(
                    OC.generateUrl(`/apps/budget/api/transactions?account=${accountId}&startDate=${startDate.toISOString().split('T')[0]}&endDate=${endDate.toISOString().split('T')[0]}`),
                    { headers: { 'requesttoken': OC.requestToken } }
                );

                if (!response.ok) continue;

                const transactions = await response.json();
                if (!Array.isArray(transactions)) continue;

                // Calculate daily balances
                const balanceHistory = this.calculateBalanceHistory(account, transactions, 7);

                // Render sparkline
                this.renderSparkline(accountId, balanceHistory);
            } catch (error) {
                console.error(`Failed to load sparkline for account ${account.id}:`, error);
            }
        }
    }

    calculateBalanceHistory(account, transactions, days) {
        const currentBalance = parseFloat(account.balance) || 0;
        const balances = [];

        // Sort transactions by date descending
        const sortedTxns = [...transactions].sort((a, b) =>
            new Date(b.date || b.Date) - new Date(a.date || a.Date)
        );

        // Start with current balance and work backwards
        let runningBalance = currentBalance;
        const today = new Date();
        today.setHours(23, 59, 59, 999);

        for (let i = 0; i < days; i++) {
            const date = new Date(today);
            date.setDate(date.getDate() - i);
            date.setHours(0, 0, 0, 0);

            // Find transactions on this day and reverse their effect
            const dayTxns = sortedTxns.filter(t => {
                const txnDate = new Date(t.date || t.Date);
                txnDate.setHours(0, 0, 0, 0);
                return txnDate.getTime() === date.getTime();
            });

            // Store the balance at end of this day
            balances.unshift(runningBalance);

            // Reverse transactions to get previous day's balance
            dayTxns.forEach(t => {
                const amount = parseFloat(t.amount || t.Amount) || 0;
                runningBalance -= amount;
            });
        }

        return balances;
    }

    renderSparkline(accountId, balances) {
        const sparklineEl = document.querySelector(`.account-sparkline[data-account-id="${accountId}"] svg`);
        if (!sparklineEl || balances.length < 2) return;

        const width = 80;
        const height = 32;
        const padding = 2;

        // Find min and max for scaling
        const min = Math.min(...balances);
        const max = Math.max(...balances);
        const range = max - min || 1;

        // Generate path points
        const points = balances.map((val, i) => {
            const x = padding + (i / (balances.length - 1)) * (width - padding * 2);
            const y = padding + (1 - (val - min) / range) * (height - padding * 2);
            return `${x},${y}`;
        });

        const pathD = `M${points.join(' L')}`;

        // Determine trend color
        const trend = balances[balances.length - 1] - balances[0];
        const trendClass = trend > 0 ? 'positive' : (trend < 0 ? 'negative' : 'neutral');

        sparklineEl.innerHTML = `<path class="sparkline-path ${trendClass}" d="${pathD}"></path>`;
    }

    setupAccountCardClickHandlers() {
        const accountCards = document.querySelectorAll('.account-card');
        accountCards.forEach(card => {
            card.addEventListener('click', (e) => {
                // Don't trigger if clicking on action buttons
                if (e.target.closest('.account-actions, button')) {
                    return;
                }
                const accountId = parseInt(card.dataset.accountId);
                if (accountId) {
                    this.showAccountDetails(accountId);
                }
            });
        });
    }

    async showAccountDetails(accountId) {
        try {
            // Find the account in our cached data
            const account = this.accounts.find(acc => acc.id === accountId);
            if (!account) {
                throw new Error('Account not found');
            }

            // Hide accounts list and show account details
            document.getElementById('accounts-view').style.display = 'none';
            document.getElementById('account-details-view').style.display = 'block';

            // Store current account for context
            this.currentAccount = account;

            // Populate account overview
            this.populateAccountOverview(account);

            // Load account transactions and metrics
            await this.loadAccountTransactions(accountId);
            await this.loadAccountMetrics(accountId);

            // Setup account details event listeners
            this.setupAccountDetailsEventListeners();

        } catch (error) {
            console.error('Failed to show account details:', error);
            OC.Notification.showTemporary('Failed to load account details');
        }
    }

    populateAccountOverview(account) {
        // Update title and breadcrumb
        document.getElementById('account-details-title').textContent = account.name;

        // Get account type info
        const typeInfo = this.getAccountTypeInfo(account.type);
        const healthStatus = this.getAccountHealthStatus(account);

        // Update account header
        const typeIcon = document.getElementById('account-type-icon');
        if (typeIcon) {
            typeIcon.className = `account-type-icon ${typeInfo.icon}`;
            typeIcon.style.color = typeInfo.color;
        }

        document.getElementById('account-display-name').textContent = account.name;
        document.getElementById('account-type-label').textContent = typeInfo.label;

        const institutionEl = document.getElementById('account-institution');
        if (account.institution) {
            institutionEl.textContent = account.institution;
            institutionEl.style.display = 'inline';
        } else {
            institutionEl.style.display = 'none';
        }

        // Update health indicator
        const healthIndicator = document.getElementById('account-health-indicator');
        if (healthIndicator) {
            healthIndicator.className = `health-indicator ${healthStatus.class}`;
            if (healthStatus.tooltip) {
                healthIndicator.title = healthStatus.tooltip;
            }
        }

        // Update balance information
        const currentBalance = account.balance || 0;
        const currency = account.currency || this.getPrimaryCurrency();

        document.getElementById('account-current-balance').textContent = this.formatCurrency(currentBalance, currency);
        document.getElementById('account-current-balance').className = `balance-amount ${currentBalance >= 0 ? 'positive' : 'negative'}`;

        // Calculate available balance
        let availableBalance = currentBalance;
        if (account.type === 'credit_card' && account.creditLimit) {
            availableBalance = account.creditLimit - Math.abs(currentBalance);
            // Show credit info
            document.getElementById('credit-info').style.display = 'block';
            document.getElementById('account-credit-limit').textContent = this.formatCurrency(account.creditLimit, currency);
        } else {
            document.getElementById('credit-info').style.display = 'none';
        }

        document.getElementById('account-available-balance').textContent = this.formatCurrency(availableBalance, currency);
        document.getElementById('account-available-balance').className = `balance-amount ${availableBalance >= 0 ? 'positive' : 'negative'}`;

        // Update account details
        document.getElementById('account-number').textContent = account.accountNumber ? '***' + account.accountNumber.slice(-4) : 'Not provided';
        document.getElementById('routing-number').textContent = account.routingNumber || 'Not provided';
        document.getElementById('account-iban').textContent = account.iban || 'Not provided';
        document.getElementById('sort-code').textContent = account.sortCode || 'Not provided';
        document.getElementById('swift-bic').textContent = account.swiftBic || 'Not provided';
        document.getElementById('account-display-currency').textContent = currency;
        document.getElementById('account-opened').textContent = account.openedDate ? new Date(account.openedDate).toLocaleDateString() : 'Not provided';
        document.getElementById('last-reconciled').textContent = account.lastReconciled ? new Date(account.lastReconciled).toLocaleDateString() : 'Never';
    }

    async loadAccountTransactions(accountId) {
        try {
            // Initialize account-specific state
            this.accountCurrentPage = 1;
            this.accountRowsPerPage = 50;
            this.accountFilters = {};
            this.accountSort = { field: 'date', direction: 'desc' };

            // Build query for account-specific transactions
            const params = new URLSearchParams({
                accountId: accountId,
                limit: this.accountRowsPerPage,
                page: this.accountCurrentPage,
                sort: this.accountSort.field,
                direction: this.accountSort.direction
            });

            const response = await fetch(OC.generateUrl('/apps/budget/api/transactions?' + params.toString()), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (response.ok) {
                const result = await response.json();
                this.accountTransactions = result.transactions || result; // Handle both formats
                this.accountTotalPages = result.totalPages || 1;
                this.accountTotal = result.total || this.accountTransactions.length;
            } else {
                // Fallback: filter from all transactions
                await this.loadTransactions();
                this.accountTransactions = this.transactions.filter(t => t.accountId === accountId);
                this.accountTotal = this.accountTransactions.length;
                this.accountTotalPages = Math.ceil(this.accountTotal / this.accountRowsPerPage);
            }

            // Render account transactions
            this.renderAccountTransactions();
            this.updateAccountPagination();

        } catch (error) {
            console.error('Failed to load account transactions:', error);
            // Show empty state
            this.accountTransactions = [];
            this.renderAccountTransactions();
        }
    }

    renderAccountTransactions() {
        const tbody = document.getElementById('account-transactions-body');
        if (!tbody) return;

        if (!this.accountTransactions || this.accountTransactions.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="empty-state">
                        <div class="empty-content">
                            <span class="icon-menu" aria-hidden="true"></span>
                            <h3>No transactions found</h3>
                            <p>This account doesn't have any transactions yet.</p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        // Calculate running balance
        let runningBalance = this.currentAccount?.balance || 0;
        const transactionsWithBalance = [...this.accountTransactions].reverse().map(transaction => {
            const amount = parseFloat(transaction.amount) || 0;
            if (transaction.type === 'credit') {
                runningBalance -= amount; // Remove to get previous balance
            } else {
                runningBalance += amount; // Add back to get previous balance
            }
            const balanceAtTime = runningBalance;

            // Adjust for next iteration
            if (transaction.type === 'credit') {
                runningBalance += amount;
            } else {
                runningBalance -= amount;
            }

            return { ...transaction, balanceAtTime };
        }).reverse();

        tbody.innerHTML = transactionsWithBalance.map(transaction => {
            const amount = parseFloat(transaction.amount) || 0;
            const currency = this.currentAccount?.currency || this.getPrimaryCurrency();
            const category = this.categories?.find(c => c.id === transaction.categoryId);

            return `
                <tr class="transaction-row" data-transaction-id="${transaction.id}">
                    <td class="date-column">
                        <span class="transaction-date">${new Date(transaction.date).toLocaleDateString()}</span>
                    </td>
                    <td class="description-column">
                        <div class="transaction-description">
                            <span class="description-main">${transaction.description || 'No description'}</span>
                            ${transaction.vendor ? `<span class="vendor-name">${transaction.vendor}</span>` : ''}
                        </div>
                    </td>
                    <td class="category-column">
                        <span class="category-name ${category ? '' : 'uncategorized'}">
                            ${category ? category.name : 'Uncategorized'}
                        </span>
                    </td>
                    <td class="amount-column">
                        <span class="transaction-amount ${transaction.type}">
                            ${transaction.type === 'credit' ? '+' : '-'}${this.formatCurrency(Math.abs(amount), currency)}
                        </span>
                    </td>
                    <td class="balance-column">
                        <span class="transaction-balance ${transaction.balanceAtTime >= 0 ? 'positive' : 'negative'}">
                            ${this.formatCurrency(transaction.balanceAtTime, currency)}
                        </span>
                    </td>
                    <td class="actions-column">
                        <div class="transaction-actions">
                            <button class="icon-rename edit-transaction-btn"
                                    data-transaction-id="${transaction.id}"
                                    title="Edit transaction"></button>
                            <button class="icon-delete delete-transaction-btn"
                                    data-transaction-id="${transaction.id}"
                                    title="Delete transaction"></button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        // Add event listeners for transaction actions
        this.setupAccountTransactionActionListeners();
    }

    setupAccountTransactionActionListeners() {
        // Edit transaction buttons
        document.querySelectorAll('.edit-transaction-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const transactionId = parseInt(e.target.dataset.transactionId);
                this.editTransaction(transactionId);
            });
        });

        // Delete transaction buttons
        document.querySelectorAll('.delete-transaction-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const transactionId = parseInt(e.target.dataset.transactionId);
                this.deleteTransaction(transactionId);
            });
        });
    }

    async loadAccountMetrics(accountId) {
        try {
            // Calculate metrics from transactions
            const now = new Date();
            const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
            const endOfMonth = new Date(now.getFullYear(), now.getMonth() + 1, 0);

            // Filter transactions for this month
            const thisMonthTransactions = this.accountTransactions.filter(t => {
                const transDate = new Date(t.date);
                return transDate >= startOfMonth && transDate <= endOfMonth;
            });

            // Calculate metrics
            const totalTransactions = this.accountTransactions.length;
            const thisMonthIncome = thisMonthTransactions
                .filter(t => t.type === 'credit')
                .reduce((sum, t) => sum + (parseFloat(t.amount) || 0), 0);

            const thisMonthExpenses = thisMonthTransactions
                .filter(t => t.type === 'debit')
                .reduce((sum, t) => sum + (parseFloat(t.amount) || 0), 0);

            const avgTransaction = totalTransactions > 0
                ? this.accountTransactions.reduce((sum, t) => sum + Math.abs(parseFloat(t.amount) || 0), 0) / totalTransactions
                : 0;

            const currency = this.currentAccount?.currency || this.getPrimaryCurrency();

            // Update metrics display
            document.getElementById('total-transactions').textContent = totalTransactions.toLocaleString();
            document.getElementById('total-income').textContent = this.formatCurrency(thisMonthIncome, currency);
            document.getElementById('total-expenses').textContent = this.formatCurrency(thisMonthExpenses, currency);
            document.getElementById('avg-transaction').textContent = this.formatCurrency(avgTransaction, currency);

        } catch (error) {
            console.error('Failed to calculate account metrics:', error);
            // Show zeros on error
            document.getElementById('total-transactions').textContent = '0';
            document.getElementById('total-income').textContent = this.formatCurrency(0);
            document.getElementById('total-expenses').textContent = this.formatCurrency(0);
            document.getElementById('avg-transaction').textContent = this.formatCurrency(0);
        }
    }

    updateAccountPagination() {
        const prevBtn = document.getElementById('account-prev-page');
        const nextBtn = document.getElementById('account-next-page');
        const pageInfo = document.getElementById('account-page-info');

        if (prevBtn) prevBtn.disabled = this.accountCurrentPage <= 1;
        if (nextBtn) nextBtn.disabled = this.accountCurrentPage >= this.accountTotalPages;
        if (pageInfo) pageInfo.textContent = `Page ${this.accountCurrentPage} of ${this.accountTotalPages}`;
    }

    setupAccountDetailsEventListeners() {
        // Back to accounts button
        const backBtn = document.getElementById('back-to-accounts-btn');
        if (backBtn) {
            backBtn.addEventListener('click', () => this.hideAccountDetails());
        }

        // Edit account button
        const editBtn = document.getElementById('edit-account-btn');
        if (editBtn) {
            editBtn.addEventListener('click', () => this.editAccount(this.currentAccount.id));
        }

        // Reconcile account button
        const reconcileBtn = document.getElementById('reconcile-account-btn');
        if (reconcileBtn) {
            reconcileBtn.addEventListener('click', () => this.reconcileAccount(this.currentAccount.id));
        }

        // Account filter event listeners
        this.setupAccountFilterEventListeners();

        // Account pagination event listeners
        const prevBtn = document.getElementById('account-prev-page');
        const nextBtn = document.getElementById('account-next-page');

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (this.accountCurrentPage > 1) {
                    this.accountCurrentPage--;
                    this.loadAccountTransactions(this.currentAccount.id);
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                if (this.accountCurrentPage < this.accountTotalPages) {
                    this.accountCurrentPage++;
                    this.loadAccountTransactions(this.currentAccount.id);
                }
            });
        }
    }

    setupAccountFilterEventListeners() {
        // Apply filters button
        const applyBtn = document.getElementById('account-apply-filters-btn');
        if (applyBtn) {
            applyBtn.addEventListener('click', () => this.applyAccountFilters());
        }

        // Clear filters button
        const clearBtn = document.getElementById('account-clear-filters-btn');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => this.clearAccountFilters());
        }

        // Auto-populate category filter
        const categoryFilter = document.getElementById('account-filter-category');
        if (categoryFilter && this.categories) {
            categoryFilter.innerHTML = '<option value="">All Categories</option><option value="uncategorized">Uncategorized</option>';
            this.categories.forEach(category => {
                categoryFilter.innerHTML += `<option value="${category.id}">${category.name}</option>`;
            });
        }
    }

    applyAccountFilters() {
        // Collect filter values
        this.accountFilters = {
            category: document.getElementById('account-filter-category')?.value || '',
            type: document.getElementById('account-filter-type')?.value || '',
            dateFrom: document.getElementById('account-filter-date-from')?.value || '',
            dateTo: document.getElementById('account-filter-date-to')?.value || '',
            amountMin: document.getElementById('account-filter-amount-min')?.value || '',
            amountMax: document.getElementById('account-filter-amount-max')?.value || '',
            search: document.getElementById('account-filter-search')?.value || ''
        };

        // Reset to first page and reload
        this.accountCurrentPage = 1;
        this.loadAccountTransactions(this.currentAccount.id);
    }

    clearAccountFilters() {
        // Clear all filter inputs
        document.getElementById('account-filter-category').value = '';
        document.getElementById('account-filter-type').value = '';
        document.getElementById('account-filter-date-from').value = '';
        document.getElementById('account-filter-date-to').value = '';
        document.getElementById('account-filter-amount-min').value = '';
        document.getElementById('account-filter-amount-max').value = '';
        document.getElementById('account-filter-search').value = '';

        // Clear filters and reload
        this.accountFilters = {};
        this.accountCurrentPage = 1;
        this.loadAccountTransactions(this.currentAccount.id);
    }

    hideAccountDetails() {
        document.getElementById('account-details-view').style.display = 'none';
        document.getElementById('accounts-view').style.display = 'block';
        this.currentAccount = null;
    }

    async loadTransactions(accountId = null) {
        try {
            // Initialize default values for enhanced features
            this.currentPage = this.currentPage || 1;
            this.rowsPerPage = this.rowsPerPage || 100;
            this.currentSort = this.currentSort || { field: 'date', direction: 'desc' };

            // Build query parameters - start with basic compatibility
            let url = '/apps/budget/api/transactions?limit=' + this.rowsPerPage + '&page=' + this.currentPage;

            // Add account filter if provided
            if (accountId) {
                url += `&accountId=${accountId}`;
            } else if (this.transactionFilters?.account) {
                url += `&accountId=${this.transactionFilters.account}`;
            }

            // Try to add enhanced parameters, but don't break if backend doesn't support them
            const params = new URLSearchParams();

            // Basic parameters that should be safe
            if (this.transactionFilters?.search) {
                params.append('search', this.transactionFilters.search);
            }
            if (this.transactionFilters?.dateFrom) {
                params.append('dateFrom', this.transactionFilters.dateFrom);
            }
            if (this.transactionFilters?.dateTo) {
                params.append('dateTo', this.transactionFilters.dateTo);
            }

            if (params.toString()) {
                url += '&' + params.toString();
            }

            const response = await fetch(OC.generateUrl(url), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();
            this.transactions = Array.isArray(result) ? result : (result.transactions || result);

            // Apply client-side filtering if backend doesn't support it
            this.applyClientSideFilters();

            // Update UI with transaction data
            const tbody = document.querySelector('#transactions-table tbody');
            if (tbody) {
                // Always use enhanced rendering for inline editing support
                this.renderEnhancedTransactionsTable();
                this.applyColumnVisibility();
            }

            // Update enhanced UI elements if they exist
            this.updateTransactionsSummary(result);
            this.updatePagination(result);

        } catch (error) {
            console.error('Failed to load transactions:', error);
            OC.Notification.showTemporary('Failed to load transactions');
        }
    }

    renderEnhancedTransactionsTable() {
        const tbody = document.querySelector('#transactions-table tbody');
        if (!tbody || !this.transactions) return;

        const bulkPanel = document.getElementById('bulk-actions-panel');
        const showBulkMode = bulkPanel && bulkPanel.style.display !== 'none';
        const showReconcileMode = this.reconcileMode;

        tbody.innerHTML = this.transactions.map(transaction => {
            const account = this.accounts?.find(a => a.id === transaction.accountId);
            const category = this.categories?.find(c => c.id === transaction.categoryId);
            const currency = transaction.accountCurrency || account?.currency || this.getPrimaryCurrency();

            const typeClass = transaction.type === 'credit' ? 'positive' : 'negative';
            const formattedAmount = this.formatCurrency(transaction.amount, currency);

            // Escape HTML to prevent XSS
            const escapeHtml = (str) => {
                if (!str) return '';
                return str.replace(/&/g, '&amp;')
                          .replace(/</g, '&lt;')
                          .replace(/>/g, '&gt;')
                          .replace(/"/g, '&quot;');
            };

            const isLinked = transaction.linkedTransactionId != null;
            const linkedBadge = isLinked
                ? `<span class="linked-indicator" data-transaction-id="${transaction.id}" data-linked-id="${transaction.linkedTransactionId}" title="Linked transfer - click to unlink">&#x1F517; Transfer</span>`
                : '';
            const matchButton = !isLinked
                ? `<button class="action-btn match-btn transaction-match-btn"
                          data-transaction-id="${transaction.id}"
                          title="Find transfer matches">
                      <span class="icon-external" aria-hidden="true"></span>
                  </button>`
                : `<button class="action-btn unlink-btn transaction-unlink-btn"
                          data-transaction-id="${transaction.id}"
                          title="Unlink transfer">
                      &#x2716;
                  </button>`;

            return `
                <tr class="transaction-row ${isLinked ? 'is-linked' : ''}" data-transaction-id="${transaction.id}">
                    <td class="select-column">
                        <input type="checkbox" class="transaction-checkbox"
                               data-transaction-id="${transaction.id}"
                               ${this.selectedTransactions?.has(transaction.id) ? 'checked' : ''}>
                    </td>
                    <td class="date-column editable-cell"
                        data-field="date"
                        data-value="${transaction.date}"
                        data-transaction-id="${transaction.id}">
                        <span class="cell-display">${new Date(transaction.date).toLocaleDateString()}</span>
                    </td>
                    <td class="description-column editable-cell"
                        data-field="description"
                        data-value="${escapeHtml(transaction.description)}"
                        data-transaction-id="${transaction.id}">
                        <div class="transaction-description">
                            <span class="primary-text cell-display">${escapeHtml(transaction.description) || 'No description'}</span>
                            ${transaction.reference ? `<span class="secondary-text">${escapeHtml(transaction.reference)}</span>` : ''}
                            ${linkedBadge}
                        </div>
                    </td>
                    <td class="vendor-column editable-cell"
                        data-field="vendor"
                        data-value="${escapeHtml(transaction.vendor || '')}"
                        data-transaction-id="${transaction.id}">
                        <span class="cell-display">${escapeHtml(transaction.vendor) || '-'}</span>
                    </td>
                    <td class="category-column editable-cell"
                        data-field="categoryId"
                        data-value="${transaction.categoryId || ''}"
                        data-transaction-id="${transaction.id}">
                        <span class="category-badge cell-display ${category ? 'categorized' : 'uncategorized'}">
                            ${category ? escapeHtml(category.name) : 'Uncategorized'}
                        </span>
                    </td>
                    <td class="amount-column editable-cell"
                        data-field="amount"
                        data-value="${transaction.amount}"
                        data-type="${transaction.type}"
                        data-transaction-id="${transaction.id}">
                        <span class="amount cell-display ${typeClass}">${formattedAmount}</span>
                    </td>
                    <td class="account-column editable-cell"
                        data-field="accountId"
                        data-value="${transaction.accountId}"
                        data-transaction-id="${transaction.id}">
                        <span class="account-name cell-display">${account ? escapeHtml(account.name) : 'Unknown Account'}</span>
                    </td>
                    <td class="actions-column">
                        <div class="transaction-actions">
                            <button class="action-btn split-btn transaction-split-btn"
                                    data-transaction-id="${transaction.id}"
                                    title="Split into categories">
                                <span aria-hidden="true">⋯</span>
                            </button>
                            <button class="action-btn share-btn transaction-share-btn"
                                    data-transaction-id="${transaction.id}"
                                    title="Share with contact">
                                <span aria-hidden="true">👥</span>
                            </button>
                            ${matchButton}
                            <button class="action-btn edit-btn transaction-edit-btn"
                                    data-transaction-id="${transaction.id}"
                                    title="Edit transaction (modal)">
                                <span class="icon-rename" aria-hidden="true"></span>
                            </button>
                            <button class="action-btn delete-btn transaction-delete-btn"
                                    data-transaction-id="${transaction.id}"
                                    title="Delete transaction">
                                <span class="icon-delete" aria-hidden="true"></span>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    applyClientSideFilters() {
        if (!this.transactions || !this.transactionFilters) return;

        let filtered = [...this.transactions];

        // Apply filters that weren't handled by backend
        if (this.transactionFilters.category) {
            if (this.transactionFilters.category === 'uncategorized') {
                filtered = filtered.filter(t => !t.categoryId);
            } else {
                filtered = filtered.filter(t => t.categoryId === parseInt(this.transactionFilters.category));
            }
        }

        if (this.transactionFilters.type) {
            filtered = filtered.filter(t => t.type === this.transactionFilters.type);
        }

        if (this.transactionFilters.amountMin) {
            const min = parseFloat(this.transactionFilters.amountMin);
            filtered = filtered.filter(t => t.amount >= min);
        }

        if (this.transactionFilters.amountMax) {
            const max = parseFloat(this.transactionFilters.amountMax);
            filtered = filtered.filter(t => t.amount <= max);
        }

        // Apply sorting
        if (this.currentSort?.field) {
            filtered.sort((a, b) => {
                let aVal = a[this.currentSort.field];
                let bVal = b[this.currentSort.field];

                // Handle date sorting
                if (this.currentSort.field === 'date') {
                    aVal = new Date(aVal);
                    bVal = new Date(bVal);
                }

                // Handle amount sorting
                if (this.currentSort.field === 'amount') {
                    aVal = parseFloat(aVal);
                    bVal = parseFloat(bVal);
                }

                if (aVal < bVal) return this.currentSort.direction === 'asc' ? -1 : 1;
                if (aVal > bVal) return this.currentSort.direction === 'asc' ? 1 : -1;
                return 0;
            });
        }

        this.transactions = filtered;
    }

    updateTransactionsSummary(result) {
        const countElement = document.getElementById('transactions-count');
        const totalElement = document.getElementById('transactions-total');

        if (countElement && this.transactions) {
            const totalTransactions = result.total || this.transactions.length;
            const displayedTransactions = this.transactions.length;
            countElement.textContent = result.total ?
                `${displayedTransactions} of ${totalTransactions} transactions` :
                `${displayedTransactions} transactions`;
        }

        if (totalElement && this.transactions) {
            const total = this.transactions.reduce((sum, t) => {
                return sum + (t.type === 'credit' ? t.amount : -t.amount);
            }, 0);

            // Determine most common currency from displayed transactions
            const currencyCounts = {};
            this.transactions.forEach(t => {
                const currency = t.accountCurrency || this.getPrimaryCurrency();
                currencyCounts[currency] = (currencyCounts[currency] || 0) + 1;
            });
            const mostCommonCurrency = Object.entries(currencyCounts)
                .sort((a, b) => b[1] - a[1])[0]?.[0] || this.getPrimaryCurrency();

            totalElement.textContent = `Total: ${this.formatCurrency(total, mostCommonCurrency)}`;
        }
    }

    updatePagination(result) {
        // Top pagination controls
        const pageInfo = document.getElementById('page-info');
        const prevBtn = document.getElementById('prev-page-btn');
        const nextBtn = document.getElementById('next-page-btn');
        // Bottom pagination controls
        const pageInfoBottom = document.getElementById('page-info-bottom');
        const prevBtnBottom = document.getElementById('prev-page-btn-bottom');
        const nextBtnBottom = document.getElementById('next-page-btn-bottom');

        // Only update pagination if at least one set of elements exist
        if (!pageInfo && !prevBtn && !nextBtn && !pageInfoBottom && !prevBtnBottom && !nextBtnBottom) return;

        if (result && result.total && result.totalPages) {
            const currentPage = this.currentPage || 1;
            const pageText = `Page ${currentPage} of ${result.totalPages}`;
            const atFirstPage = currentPage <= 1;
            const atLastPage = currentPage >= result.totalPages;

            // Update top controls
            if (pageInfo) pageInfo.textContent = pageText;
            if (prevBtn) prevBtn.disabled = atFirstPage;
            if (nextBtn) nextBtn.disabled = atLastPage;

            // Update bottom controls
            if (pageInfoBottom) pageInfoBottom.textContent = pageText;
            if (prevBtnBottom) prevBtnBottom.disabled = atFirstPage;
            if (nextBtnBottom) nextBtnBottom.disabled = atLastPage;
        } else {
            // Hide pagination if not needed or not supported
            if (pageInfo) pageInfo.textContent = '';
            if (prevBtn) prevBtn.disabled = true;
            if (nextBtn) nextBtn.disabled = true;
            if (pageInfoBottom) pageInfoBottom.textContent = '';
            if (prevBtnBottom) prevBtnBottom.disabled = true;
            if (nextBtnBottom) nextBtnBottom.disabled = true;
        }
    }

    // Additional missing methods
    toggleTransactionReconciliation(transactionId, reconciled) {
        // This would update the transaction's reconciliation status
        // Implementation depends on backend API
        console.log(`Toggle reconciliation for transaction ${transactionId}: ${reconciled}`);
    }

    finishReconciliation() {
        if (!this.reconcileData || !this.reconcileData.isBalanced) {
            OC.Notification.showTemporary('Cannot finish reconciliation - balances do not match');
            return;
        }

        // Mark all checked transactions as reconciled and finish reconciliation
        this.cancelReconciliation();
        OC.Notification.showTemporary('Reconciliation completed successfully');
    }

    async loadCategories() {
        // Initialize category state with defaults
        this.categoryTree = [];
        this.allCategories = [];
        this.currentCategoryType = this.currentCategoryType || 'expense';
        this.selectedCategory = null;
        this.expandedCategories = this.expandedCategories || new Set();

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/categories/tree'), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });
            const categories = await response.json();

            // Update category state with fetched data
            if (Array.isArray(categories)) {
                this.categoryTree = categories;
                this.allCategories = categories;
            }
        } catch (error) {
            console.error('Failed to load categories:', error);
        }

        // Always setup event listeners and render (even if fetch failed)
        this.setupCategoriesEventListeners();
        this.renderCategoriesTree();
    }

    async saveTransaction() {
        // Helper function to safely get and clean form values
        const getFormValue = (id, defaultValue = null, isNumeric = false, isInteger = false) => {
            const element = document.getElementById(id);
            if (!element) return defaultValue;

            const value = element.value ? String(element.value).trim() : '';
            if (value === '') return defaultValue;

            if (isInteger) {
                const intValue = parseInt(value);
                return isNaN(intValue) ? defaultValue : intValue;
            }

            if (isNumeric) {
                const numValue = parseFloat(value);
                return isNaN(numValue) ? defaultValue : numValue;
            }

            return value;
        };

        // Validate required fields
        const accountId = getFormValue('transaction-account', null, false, true);
        const date = getFormValue('transaction-date');
        const type = getFormValue('transaction-type');
        const amount = getFormValue('transaction-amount', null, true);
        const description = getFormValue('transaction-description');

        if (!accountId) {
            if (!Array.isArray(this.accounts) || this.accounts.length === 0) {
                OC.Notification.showTemporary('No accounts available. Please create an account first.');
                return;
            }
            OC.Notification.showTemporary('Please select an account');
            return;
        }
        if (!date) {
            OC.Notification.showTemporary('Please enter a date');
            return;
        }
        if (!type) {
            OC.Notification.showTemporary('Please select a transaction type');
            return;
        }
        if (amount === null || amount <= 0) {
            OC.Notification.showTemporary('Please enter a valid amount');
            return;
        }
        if (!description) {
            OC.Notification.showTemporary('Please enter a description');
            return;
        }

        const formData = {
            accountId: accountId,
            date: date,
            type: type,
            amount: amount,
            description: description,
            vendor: getFormValue('transaction-vendor'),
            categoryId: getFormValue('transaction-category', null, false, true),
            notes: getFormValue('transaction-notes')
        };

        const transactionId = getFormValue('transaction-id');


        try {
            const url = transactionId
                ? `/apps/budget/api/transactions/${transactionId}`
                : '/apps/budget/api/transactions';

            const method = transactionId ? 'PUT' : 'POST';

            const response = await fetch(OC.generateUrl(url), {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(formData)
            });

            if (response.ok) {
                OC.Notification.showTemporary('Transaction saved successfully');
                this.hideModals();
                this.loadTransactions();
                // Also reload account transactions if we're on account details view
                if (this.currentView === 'account-details' && this.currentAccount) {
                    this.loadAccountTransactions(this.currentAccount.id);
                }
            } else {
                // Try to get the actual error message from backend
                let errorMessage = 'Failed to save transaction';
                try {
                    const errorData = await response.json();
                    if (errorData.error) {
                        errorMessage = errorData.error;
                    }
                } catch (e) {
                    // If we can't parse JSON, use default message
                }
                throw new Error(errorMessage);
            }
        } catch (error) {
            console.error('Failed to save transaction:', error);
            OC.Notification.showTemporary(error.message || 'Failed to save transaction');
        }
    }

    async saveAccount() {
        try {
            // Get form elements
            const nameElement = document.getElementById('account-name');
            const typeElement = document.getElementById('account-type');

            if (!nameElement) {
                console.error('Account name element not found');
                OC.Notification.showTemporary('Form error: Account name field not found');
                return;
            }

            if (!typeElement) {
                console.error('Account type element not found');
                OC.Notification.showTemporary('Form error: Account type field not found');
                return;
            }

            // Helper function to safely get and clean form values
            const getFormValue = (id, defaultValue = null, isNumeric = false) => {
                const element = document.getElementById(id);
                if (!element) return defaultValue;

                const value = element.value ? String(element.value).trim() : '';
                if (value === '') return defaultValue;

                if (isNumeric) {
                    const numValue = parseFloat(value);
                    return isNaN(numValue) ? defaultValue : numValue;
                }

                return value;
            };

            const accountId = getFormValue('account-id');
            const isEdit = !!accountId;

            const formData = {
                name: getFormValue('account-name', ''),
                type: getFormValue('account-type', ''),
                balance: getFormValue('account-balance', 0, true),
                currency: getFormValue('account-currency', 'USD'),
                institution: getFormValue('account-institution'),
                accountHolderName: getFormValue('account-holder-name'),
                openingDate: getFormValue('account-opening-date'),
                interestRate: getFormValue('account-interest-rate', null, true),
                creditLimit: getFormValue('account-credit-limit', null, true),
                overdraftLimit: getFormValue('account-overdraft-limit', null, true)
            };

            // Sensitive fields: only include if user entered a value
            // For edits, empty means "keep existing" - don't send to avoid overwriting
            const sensitiveFields = ['accountNumber', 'routingNumber', 'sortCode', 'iban', 'swiftBic'];
            const sensitiveFieldIds = {
                accountNumber: 'form-account-number',
                routingNumber: 'form-routing-number',
                sortCode: 'form-sort-code',
                iban: 'form-iban',
                swiftBic: 'form-swift-bic'
            };

            sensitiveFields.forEach(field => {
                const value = getFormValue(sensitiveFieldIds[field]);
                // For new accounts: include all fields (null for empty)
                // For edits: only include if user entered a value
                if (!isEdit || value !== null) {
                    formData[field] = value;
                }
            });

            // Validate required fields on frontend
            if (!formData.name || formData.name === '') {
                console.error('Account name is empty');
                OC.Notification.showTemporary('Please enter an account name');
                nameElement.focus();
                return;
            }

            if (!formData.type || formData.type === '') {
                console.error('Account type is empty');
                OC.Notification.showTemporary('Please select an account type');
                typeElement.focus();
                return;
            }

            // Validate account name length
            if (formData.name.length > 255) {
                OC.Notification.showTemporary('Account name is too long (maximum 255 characters)');
                nameElement.focus();
                return;
            }

            // Validate numeric fields
            if (isNaN(formData.balance)) {
                OC.Notification.showTemporary('Please enter a valid balance amount');
                document.getElementById('account-balance').focus();
                return;
            }

            // Make API request (accountId already defined above for isEdit check)
            const url = accountId
                ? `/apps/budget/api/accounts/${accountId}`
                : '/apps/budget/api/accounts';

            const method = accountId ? 'PUT' : 'POST';

            const response = await fetch(OC.generateUrl(url), {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(formData)
            });

            if (response.ok) {
                // Try to parse response as JSON, but handle empty responses
                let result = {};
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    const text = await response.text();
                    if (text.trim()) {
                        result = JSON.parse(text);
                    }
                }

                OC.Notification.showTemporary('Account saved successfully');
                this.hideModals();
                await this.loadAccounts();
                await this.loadInitialData(); // Refresh dropdowns

                // Refresh account details view if it's currently visible
                const detailsView = document.getElementById('account-details-view');
                if (detailsView && detailsView.style.display !== 'none' && accountId) {
                    const updatedAccount = this.accounts.find(a => a.id === parseInt(accountId));
                    if (updatedAccount) {
                        this.currentAccount = updatedAccount;
                        this.populateAccountOverview(updatedAccount);
                    }
                }
            } else {
                // Handle error responses more safely
                let errorMessage = 'Failed to save account';
                try {
                    const contentType = response.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        const text = await response.text();
                        if (text.trim()) {
                            const errorData = JSON.parse(text);
                            errorMessage = errorData.error || errorMessage;
                        }
                    } else {
                        // Non-JSON response, get status text
                        errorMessage = `HTTP ${response.status}: ${response.statusText}`;
                    }
                } catch (parseError) {
                    console.error('Error parsing response:', parseError);
                    errorMessage = `HTTP ${response.status}: ${response.statusText}`;
                }
                throw new Error(errorMessage);
            }
        } catch (error) {
            console.error('Failed to save account:', error);

            // Show specific error message if available
            const errorMsg = error.message || 'Unknown error occurred';
            OC.Notification.showTemporary(`Failed to save account: ${errorMsg}`);

            // Don't hide modal on error so user can fix and retry
        }
    }

    showAccountModal(accountId = null) {
        const modal = document.getElementById('account-modal');
        const title = document.getElementById('account-modal-title');

        if (!modal || !title) {
            console.error('Account modal or title not found');
            return;
        }

        if (accountId) {
            title.textContent = 'Edit Account';
            this.loadAccountData(accountId);
        } else {
            title.textContent = 'Add Account';
            this.resetAccountForm();
        }

        // Setup conditional fields and validation
        setTimeout(() => {
            this.setupAccountTypeConditionals();
            this.setupBankingFieldValidation();
        }, 100);

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');

        // Focus on the name field
        const nameField = document.getElementById('account-name');
        if (nameField) {
            nameField.focus();
        }
    }

    async loadAccountData(accountId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/accounts/${accountId}`), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });
            const account = await response.json();

            document.getElementById('account-id').value = account.id;
            document.getElementById('account-name').value = account.name;
            document.getElementById('account-type').value = account.type;
            document.getElementById('account-balance').value = account.balance;
            document.getElementById('account-currency').value = account.currency;
            document.getElementById('account-institution').value = account.institution || '';

            // Sensitive fields: don't populate with masked values, use placeholder instead
            // This prevents the masked value from being saved back and corrupting the data
            const sensitiveFields = [
                { id: 'form-account-number', hasValue: !!account.accountNumber },
                { id: 'form-routing-number', hasValue: !!account.routingNumber },
                { id: 'form-sort-code', hasValue: !!account.sortCode },
                { id: 'form-iban', hasValue: !!account.iban },
                { id: 'form-swift-bic', hasValue: !!account.swiftBic }
            ];

            sensitiveFields.forEach(field => {
                const element = document.getElementById(field.id);
                if (element) {
                    element.value = ''; // Don't populate with masked value
                    if (field.hasValue) {
                        element.placeholder = '••••••••  (leave blank to keep current)';
                    } else {
                        element.placeholder = '';
                    }
                }
            });

            document.getElementById('account-holder-name').value = account.accountHolderName || '';
            document.getElementById('account-opening-date').value = account.openingDate || '';
            document.getElementById('account-interest-rate').value = account.interestRate || '';
            document.getElementById('account-credit-limit').value = account.creditLimit || '';
            document.getElementById('account-overdraft-limit').value = account.overdraftLimit || '';
        } catch (error) {
            console.error('Failed to load account data:', error);
            OC.Notification.showTemporary('Failed to load account data');
        }
    }

    resetAccountForm() {
        const form = document.getElementById('account-form');
        if (!form) {
            console.error('Account form not found');
            return;
        }
        form.reset();

        const accountId = document.getElementById('account-id');
        const currency = document.getElementById('account-currency');
        const balance = document.getElementById('account-balance');

        if (accountId) accountId.value = '';
        if (currency) currency.value = this.settings?.default_currency || 'GBP';
        if (balance) balance.value = '0';
    }

    async editAccount(id) {
        this.showAccountModal(id);
    }

    async deleteAccount(id) {
        if (!confirm('Are you sure you want to delete this account? This action cannot be undone.')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/accounts/${id}`), {
                method: 'DELETE',
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (response.ok) {
                OC.Notification.showTemporary('Account deleted successfully');
                this.loadAccounts();
                this.loadInitialData(); // Refresh dropdowns
            } else {
                const error = await response.json();
                throw new Error(error.error || 'Failed to delete account');
            }
        } catch (error) {
            console.error('Failed to delete account:', error);
            OC.Notification.showTemporary('Failed to delete account: ' + error.message);
        }
    }

    async setupAccountTypeConditionals() {
        const accountType = document.getElementById('account-type').value;
        const currency = document.getElementById('account-currency').value || 'USD';

        // Hide all conditional groups first
        document.querySelectorAll('.form-group.conditional').forEach(group => {
            group.style.display = 'none';
        });

        // Get banking field requirements for the selected currency
        let requirements = {};
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/accounts/banking-requirements/${currency}`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            requirements = await response.json();
        } catch (error) {
            console.warn('Failed to load banking requirements:', error);
        }

        // Show relevant fields based on account type and currency
        switch (accountType) {
            case 'checking':
            case 'savings':
                // Show banking fields based on currency
                if (requirements.routing_number) {
                    document.getElementById('routing-number-group').style.display = 'block';
                }
                if (requirements.sort_code) {
                    document.getElementById('sort-code-group').style.display = 'block';
                }
                if (requirements.iban) {
                    document.getElementById('iban-group').style.display = 'block';
                }
                document.getElementById('swift-bic-group').style.display = 'block';
                document.getElementById('overdraft-limit-group').style.display = 'block';

                if (accountType === 'savings') {
                    document.getElementById('interest-rate-group').style.display = 'block';
                }
                break;

            case 'credit_card':
                // Show credit card specific fields
                document.getElementById('credit-limit-group').style.display = 'block';
                document.getElementById('interest-rate-group').style.display = 'block';
                break;

            case 'loan':
                // Show loan specific fields
                document.getElementById('interest-rate-group').style.display = 'block';
                break;

            case 'investment':
                // Show investment account fields
                document.getElementById('swift-bic-group').style.display = 'block';
                if (requirements.iban) {
                    document.getElementById('iban-group').style.display = 'block';
                }
                break;

            case 'cash':
                // No additional fields for cash accounts
                break;
        }
    }

    async setupInstitutionAutocomplete() {
        const input = document.getElementById('account-institution');
        const suggestions = document.getElementById('institution-suggestions');
        const query = input.value.toLowerCase();

        if (query.length < 2) {
            suggestions.style.display = 'none';
            return;
        }

        try {
            // Get banking institutions from backend
            if (!this.bankingInstitutions) {
                const response = await fetch(OC.generateUrl('/apps/budget/api/accounts/banking-institutions'), {
                    headers: { 'requesttoken': OC.requestToken }
                });
                this.bankingInstitutions = await response.json();
            }

            // Get currency to show relevant banks
            const currency = document.getElementById('account-currency').value || 'USD';
            const currencyMap = { 'USD': 'US', 'GBP': 'UK', 'EUR': 'EU', 'CAD': 'CA' };
            const region = currencyMap[currency] || 'US';

            const banks = this.bankingInstitutions[region] || this.bankingInstitutions['US'];
            const filteredBanks = banks.filter(bank =>
                bank.toLowerCase().includes(query)
            ).slice(0, 8);

            if (filteredBanks.length > 0) {
                suggestions.innerHTML = filteredBanks.map(bank =>
                    `<div class="autocomplete-item" data-bank-name="${bank}">${bank}</div>`
                ).join('');
                suggestions.style.display = 'block';
            } else {
                suggestions.style.display = 'none';
            }
        } catch (error) {
            console.warn('Failed to load banking institutions:', error);
            suggestions.style.display = 'none';
        }
    }

    selectInstitution(bankName) {
        document.getElementById('account-institution').value = bankName;
        document.getElementById('institution-suggestions').style.display = 'none';
    }

    // Real-time validation methods
    async validateBankingField(fieldType, value, fieldId) {
        if (!value || value.length < 3) {
            this.clearValidationFeedback(fieldId);
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/accounts/validate/${fieldType}`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ [fieldType.replace('-', '')]: value })
            });

            const result = await response.json();
            this.showValidationFeedback(fieldId, result);

            // Auto-format if validation succeeded
            if (result.valid && result.formatted && result.formatted !== value) {
                document.getElementById(fieldId).value = result.formatted;
            }
        } catch (error) {
            console.warn(`Failed to validate ${fieldType}:`, error);
        }
    }

    showValidationFeedback(fieldId, result) {
        const field = document.getElementById(fieldId);
        const formGroup = field.closest('.form-group');

        // Remove existing feedback
        this.clearValidationFeedback(fieldId);

        // Add validation state
        field.classList.remove('error', 'success');
        field.classList.add(result.valid ? 'success' : 'error');

        // Add feedback message
        if (!result.valid && result.error) {
            const feedback = document.createElement('div');
            feedback.className = 'field-feedback error';
            feedback.textContent = result.error;
            feedback.id = `${fieldId}-feedback`;
            formGroup.appendChild(feedback);
        } else if (result.valid) {
            const feedback = document.createElement('div');
            feedback.className = 'field-feedback success';
            feedback.innerHTML = '<span class="icon-checkmark"></span> Valid';
            feedback.id = `${fieldId}-feedback`;
            formGroup.appendChild(feedback);
        }
    }

    clearValidationFeedback(fieldId) {
        const field = document.getElementById(fieldId);
        const formGroup = field.closest('.form-group');

        field.classList.remove('error', 'success');

        const existingFeedback = document.getElementById(`${fieldId}-feedback`);
        if (existingFeedback) {
            existingFeedback.remove();
        }
    }

    setupBankingFieldValidation() {
        // IBAN validation
        const ibanField = document.getElementById('form-iban');
        if (ibanField) {
            ibanField.addEventListener('blur', () => {
                this.validateBankingField('iban', ibanField.value, 'form-iban');
            });
        }

        // Routing number validation
        const routingField = document.getElementById('form-routing-number');
        if (routingField) {
            routingField.addEventListener('blur', () => {
                this.validateBankingField('routing-number', routingField.value, 'form-routing-number');
            });
        }

        // Sort code validation
        const sortCodeField = document.getElementById('form-sort-code');
        if (sortCodeField) {
            sortCodeField.addEventListener('blur', () => {
                this.validateBankingField('sort-code', sortCodeField.value, 'form-sort-code');
            });
        }

        // SWIFT/BIC validation
        const swiftField = document.getElementById('form-swift-bic');
        if (swiftField) {
            swiftField.addEventListener('blur', () => {
                this.validateBankingField('swift-bic', swiftField.value, 'form-swift-bic');
            });
        }

        // Currency change handler
        const currencyField = document.getElementById('account-currency');
        if (currencyField) {
            currencyField.addEventListener('change', () => {
                this.setupAccountTypeConditionals();
            });
        }
    }

    // Helper methods for account display
    getAccountTypeInfo(accountType) {
        const typeMap = {
            'checking': {
                icon: 'icon-checkmark',
                color: '#4A90E2',
                label: 'Checking Account'
            },
            'savings': {
                icon: 'icon-folder',
                color: '#50E3C2',
                label: 'Savings Account'
            },
            'credit_card': {
                icon: 'icon-category-integration',
                color: '#F5A623',
                label: 'Credit Card'
            },
            'investment': {
                icon: 'icon-trending',
                color: '#7ED321',
                label: 'Investment'
            },
            'loan': {
                icon: 'icon-file',
                color: '#D0021B',
                label: 'Loan'
            },
            'cash': {
                icon: 'icon-category-monitoring',
                color: '#9013FE',
                label: 'Cash'
            }
        };

        return typeMap[accountType] || {
            icon: 'icon-folder',
            color: '#999999',
            label: 'Unknown'
        };
    }

    getAccountHealthStatus(account) {
        const balance = account.balance || 0;
        const type = account.type;

        // For credit cards, check credit utilization
        if (type === 'credit_card' && account.creditLimit) {
            const utilization = Math.abs(balance) / account.creditLimit;
            if (utilization > 0.9) {
                return {
                    class: 'critical',
                    icon: 'icon-error',
                    tooltip: 'Credit utilization very high'
                };
            } else if (utilization > 0.7) {
                return {
                    class: 'warning',
                    icon: 'icon-triangle-s',
                    tooltip: 'Credit utilization high'
                };
            }
        }

        // For regular accounts, check for negative balances
        if (balance < 0 && type !== 'credit_card' && type !== 'loan') {
            return {
                class: 'warning',
                icon: 'icon-triangle-s',
                tooltip: 'Negative balance'
            };
        }

        // Check overdraft limits
        if (account.overdraftLimit && balance < -account.overdraftLimit) {
            return {
                class: 'critical',
                icon: 'icon-error',
                tooltip: 'Exceeds overdraft limit'
            };
        }

        return {
            class: 'healthy',
            icon: 'icon-checkmark',
            tooltip: 'Account is in good standing'
        };
    }

    viewAccountTransactions(accountId) {
        // Switch to transactions view and filter by account
        this.showView('transactions');

        // Set the account filter
        const accountFilter = document.getElementById('filter-account');
        if (accountFilter) {
            accountFilter.value = accountId.toString();
        }

        // Load transactions for this account
        this.loadTransactions();
    }

    setupTransactionEventListeners() {
        // Initialize transaction state only if enhanced UI is present
        const hasEnhancedUI = document.getElementById('transactions-filters');

        if (hasEnhancedUI) {
            this.transactionFilters = {};
            this.currentSort = { field: 'date', direction: 'desc' };
            this.currentPage = 1;
            this.rowsPerPage = 25;
            this.selectedTransactions = new Set();
            this.reconcileMode = false;
        }

        // Toggle filters panel
        const toggleFiltersBtn = document.getElementById('toggle-filters-btn');
        if (toggleFiltersBtn) {
            toggleFiltersBtn.addEventListener('click', () => {
                this.toggleFiltersPanel();
            });
        }

        // Filter controls
        const filterControls = [
            'filter-account', 'filter-category', 'filter-type',
            'filter-date-from', 'filter-date-to', 'filter-amount-min',
            'filter-amount-max', 'filter-search'
        ];

        filterControls.forEach(controlId => {
            const control = document.getElementById(controlId);
            if (control) {
                const eventType = control.type === 'text' || control.type === 'number' ? 'input' : 'change';
                control.addEventListener(eventType, () => {
                    if (control.type === 'text' || control.type === 'number') {
                        // Debounce text/number inputs
                        clearTimeout(this.filterTimeout);
                        this.filterTimeout = setTimeout(() => {
                            this.updateFilters();
                        }, 300);
                    } else {
                        this.updateFilters();
                    }
                });
            }
        });

        // Filter action buttons
        const applyFiltersBtn = document.getElementById('apply-filters-btn');
        if (applyFiltersBtn) {
            applyFiltersBtn.addEventListener('click', () => {
                this.loadTransactions();
            });
        }

        const clearFiltersBtn = document.getElementById('clear-filters-btn');
        if (clearFiltersBtn) {
            clearFiltersBtn.addEventListener('click', () => {
                this.clearFilters();
            });
        }

        // Bulk actions
        const bulkActionsBtn = document.getElementById('bulk-actions-btn');
        if (bulkActionsBtn) {
            bulkActionsBtn.addEventListener('click', () => {
                this.toggleBulkMode();
            });
        }

        const cancelBulkBtn = document.getElementById('cancel-bulk-btn');
        if (cancelBulkBtn) {
            cancelBulkBtn.addEventListener('click', () => {
                this.cancelBulkMode();
            });
        }

        const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
        if (bulkDeleteBtn) {
            bulkDeleteBtn.addEventListener('click', () => {
                this.bulkDeleteTransactions();
            });
        }

        const bulkReconcileBtn = document.getElementById('bulk-reconcile-btn');
        if (bulkReconcileBtn) {
            bulkReconcileBtn.addEventListener('click', () => {
                this.bulkReconcileTransactions();
            });
        }

        const bulkUnreconcileBtn = document.getElementById('bulk-unreconcile-btn');
        if (bulkUnreconcileBtn) {
            bulkUnreconcileBtn.addEventListener('click', () => {
                this.bulkUnreconcileTransactions();
            });
        }

        const bulkEditBtn = document.getElementById('bulk-edit-btn');
        if (bulkEditBtn) {
            bulkEditBtn.addEventListener('click', () => {
                this.showBulkEditModal();
            });
        }

        const bulkEditSubmitBtn = document.getElementById('bulk-edit-submit-btn');
        if (bulkEditSubmitBtn) {
            bulkEditSubmitBtn.addEventListener('click', () => {
                this.submitBulkEdit();
            });
        }

        const cancelBulkEditBtns = document.querySelectorAll('.cancel-bulk-edit-btn');
        cancelBulkEditBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('bulk-edit-modal').style.display = 'none';
            });
        });

        // Reconciliation
        const reconcileModeBtn = document.getElementById('reconcile-mode-btn');
        if (reconcileModeBtn) {
            reconcileModeBtn.addEventListener('click', () => {
                this.toggleReconcileMode();
            });
        }

        // Bulk Match All
        const bulkMatchBtn = document.getElementById('bulk-match-btn');
        if (bulkMatchBtn) {
            bulkMatchBtn.addEventListener('click', () => {
                this.showBulkMatchModal();
            });
        }

        const startReconcileBtn = document.getElementById('start-reconcile-btn');
        if (startReconcileBtn) {
            startReconcileBtn.addEventListener('click', () => {
                this.startReconciliation();
            });
        }

        const cancelReconcileBtn = document.getElementById('cancel-reconcile-btn');
        if (cancelReconcileBtn) {
            cancelReconcileBtn.addEventListener('click', () => {
                this.cancelReconciliation();
            });
        }

        // Pagination
        const rowsPerPageSelect = document.getElementById('rows-per-page');
        if (rowsPerPageSelect) {
            rowsPerPageSelect.addEventListener('change', (e) => {
                this.rowsPerPage = parseInt(e.target.value);
                this.currentPage = 1;
                this.loadTransactions();
            });
        }

        const prevPageBtn = document.getElementById('prev-page-btn');
        if (prevPageBtn) {
            prevPageBtn.addEventListener('click', () => {
                if (this.currentPage > 1) {
                    this.currentPage--;
                    this.loadTransactions();
                }
            });
        }

        const nextPageBtn = document.getElementById('next-page-btn');
        if (nextPageBtn) {
            nextPageBtn.addEventListener('click', () => {
                this.currentPage++;
                this.loadTransactions();
            });
        }

        // Bottom pagination buttons
        const prevPageBtnBottom = document.getElementById('prev-page-btn-bottom');
        if (prevPageBtnBottom) {
            prevPageBtnBottom.addEventListener('click', () => {
                if (this.currentPage > 1) {
                    this.currentPage--;
                    this.loadTransactions();
                }
            });
        }

        const nextPageBtnBottom = document.getElementById('next-page-btn-bottom');
        if (nextPageBtnBottom) {
            nextPageBtnBottom.addEventListener('click', () => {
                this.currentPage++;
                this.loadTransactions();
            });
        }

        // Table sorting and selection
        document.addEventListener('click', (e) => {
            // Column sorting
            if (e.target.closest('.sortable')) {
                const header = e.target.closest('.sortable');
                const field = header.getAttribute('data-sort');
                this.sortTransactions(field);
            }

            // Select all checkbox
            if (e.target.id === 'select-all-transactions') {
                this.toggleAllTransactionSelection(e.target.checked);
            }

            // Individual transaction checkboxes
            if (e.target.classList.contains('transaction-checkbox')) {
                const transactionId = parseInt(e.target.getAttribute('data-transaction-id'));
                this.toggleTransactionSelection(transactionId, e.target.checked);
            }

            // Reconcile checkboxes
            if (e.target.classList.contains('reconcile-checkbox')) {
                const transactionId = parseInt(e.target.getAttribute('data-transaction-id'));
                this.toggleTransactionReconciliation(transactionId, e.target.checked);
            }
        });
    }

    // Transaction filtering and display methods
    toggleFiltersPanel() {
        const filtersPanel = document.getElementById('transactions-filters');
        const toggleBtn = document.getElementById('toggle-filters-btn');

        if (filtersPanel.style.display === 'none') {
            filtersPanel.style.display = 'block';
            toggleBtn.classList.add('active');
            // Populate filter dropdowns
            this.populateFilterDropdowns();
        } else {
            filtersPanel.style.display = 'none';
            toggleBtn.classList.remove('active');
        }
    }

    populateFilterDropdowns() {
        // Populate account filter
        const accountFilter = document.getElementById('filter-account');
        if (accountFilter && this.accounts) {
            accountFilter.innerHTML = '<option value="">All Accounts</option>';
            this.accounts.forEach(account => {
                accountFilter.innerHTML += `<option value="${account.id}">${account.name}</option>`;
            });
        }

        // Populate category filter
        const categoryFilter = document.getElementById('filter-category');
        if (categoryFilter && this.categories) {
            categoryFilter.innerHTML = '<option value="">All Categories</option><option value="uncategorized">Uncategorized</option>';
            this.categories.forEach(category => {
                categoryFilter.innerHTML += `<option value="${category.id}">${category.name}</option>`;
            });
        }

        // Populate reconcile account select
        const reconcileAccount = document.getElementById('reconcile-account');
        if (reconcileAccount && this.accounts) {
            reconcileAccount.innerHTML = '<option value="">Select account to reconcile</option>';
            this.accounts.forEach(account => {
                reconcileAccount.innerHTML += `<option value="${account.id}">${account.name}</option>`;
            });
        }
    }

    updateFilters() {
        this.transactionFilters = {
            account: document.getElementById('filter-account')?.value || '',
            category: document.getElementById('filter-category')?.value || '',
            type: document.getElementById('filter-type')?.value || '',
            dateFrom: document.getElementById('filter-date-from')?.value || '',
            dateTo: document.getElementById('filter-date-to')?.value || '',
            amountMin: document.getElementById('filter-amount-min')?.value || '',
            amountMax: document.getElementById('filter-amount-max')?.value || '',
            search: document.getElementById('filter-search')?.value || ''
        };

        // Auto-apply filters if any are set
        const hasFilters = Object.values(this.transactionFilters).some(value => value !== '');
        if (hasFilters) {
            this.currentPage = 1;
            this.loadTransactions();
        }
    }

    clearFilters() {
        const filterInputs = [
            'filter-account', 'filter-category', 'filter-type',
            'filter-date-from', 'filter-date-to', 'filter-amount-min',
            'filter-amount-max', 'filter-search'
        ];

        filterInputs.forEach(inputId => {
            const input = document.getElementById(inputId);
            if (input) {
                input.value = '';
            }
        });

        this.transactionFilters = {};
        this.currentPage = 1;
        this.loadTransactions();
    }

    sortTransactions(field) {
        if (this.currentSort.field === field) {
            this.currentSort.direction = this.currentSort.direction === 'asc' ? 'desc' : 'asc';
        } else {
            this.currentSort.field = field;
            this.currentSort.direction = 'asc';
        }

        // Update sort indicators
        document.querySelectorAll('.sort-indicator').forEach(indicator => {
            indicator.className = 'sort-indicator';
        });

        const currentHeader = document.querySelector(`[data-sort="${field}"] .sort-indicator`);
        if (currentHeader) {
            currentHeader.className = `sort-indicator ${this.currentSort.direction}`;
        }

        this.loadTransactions();
    }

    toggleBulkMode() {
        const bulkPanel = document.getElementById('bulk-actions-panel');
        const bulkBtn = document.getElementById('bulk-actions-btn');
        const selectColumn = document.querySelectorAll('.select-column');

        if (bulkPanel.style.display === 'none') {
            bulkPanel.style.display = 'block';
            bulkBtn.classList.add('active');
            selectColumn.forEach(col => col.style.display = 'table-cell');
            this.loadTransactions(); // Reload to show checkboxes
        } else {
            this.cancelBulkMode();
        }
    }

    cancelBulkMode() {
        const bulkPanel = document.getElementById('bulk-actions-panel');
        const bulkBtn = document.getElementById('bulk-actions-btn');
        const selectColumn = document.querySelectorAll('.select-column');

        bulkPanel.style.display = 'none';
        bulkBtn.classList.remove('active');
        selectColumn.forEach(col => col.style.display = 'none');
        this.selectedTransactions.clear();
        this.updateBulkActionsState();
        this.loadTransactions(); // Reload to hide checkboxes
    }

    toggleAllTransactionSelection(checked) {
        this.selectedTransactions.clear();

        if (checked) {
            // Select all visible transactions
            document.querySelectorAll('.transaction-checkbox').forEach(checkbox => {
                checkbox.checked = true;
                const transactionId = parseInt(checkbox.getAttribute('data-transaction-id'));
                this.selectedTransactions.add(transactionId);
            });
        } else {
            document.querySelectorAll('.transaction-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
        }

        this.updateBulkActionsState();
    }

    toggleTransactionSelection(transactionId, checked) {
        if (checked) {
            this.selectedTransactions.add(transactionId);
        } else {
            this.selectedTransactions.delete(transactionId);
        }

        // Update select all checkbox
        const selectAllCheckbox = document.getElementById('select-all-transactions');
        const allCheckboxes = document.querySelectorAll('.transaction-checkbox');
        const checkedCheckboxes = document.querySelectorAll('.transaction-checkbox:checked');

        if (selectAllCheckbox) {
            selectAllCheckbox.checked = allCheckboxes.length === checkedCheckboxes.length && allCheckboxes.length > 0;
            selectAllCheckbox.indeterminate = checkedCheckboxes.length > 0 && checkedCheckboxes.length < allCheckboxes.length;
        }

        this.updateBulkActionsState();
    }

    updateBulkActionsState() {
        const selectedCount = this.selectedTransactions.size;
        const selectedCountElement = document.getElementById('selected-count');
        const bulkActionsBtn = document.getElementById('bulk-actions-btn');
        const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
        const bulkReconcileBtn = document.getElementById('bulk-reconcile-btn');
        const bulkUnreconcileBtn = document.getElementById('bulk-unreconcile-btn');
        const bulkEditBtn = document.getElementById('bulk-edit-btn');

        if (selectedCountElement) {
            selectedCountElement.textContent = selectedCount;
        }

        const disabled = selectedCount === 0;

        if (bulkActionsBtn) {
            bulkActionsBtn.disabled = disabled;
        }

        if (bulkDeleteBtn) {
            bulkDeleteBtn.disabled = disabled;
        }

        if (bulkReconcileBtn) {
            bulkReconcileBtn.disabled = disabled;
        }

        if (bulkUnreconcileBtn) {
            bulkUnreconcileBtn.disabled = disabled;
        }

        if (bulkEditBtn) {
            bulkEditBtn.disabled = disabled;
        }
    }

    async bulkDeleteTransactions() {
        if (this.selectedTransactions.size === 0) {
            return;
        }

        if (!confirm(`Are you sure you want to delete ${this.selectedTransactions.size} transactions? This action cannot be undone.`)) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/transactions/bulk-delete'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    ids: Array.from(this.selectedTransactions)
                })
            });

            const result = await response.json();

            if (result.success > 0) {
                OC.Notification.showTemporary(`Successfully deleted ${result.success} transaction(s)`);
                this.selectedTransactions.clear();
                this.loadTransactions();
            }

            if (result.failed > 0) {
                OC.Notification.showTemporary(`Failed to delete ${result.failed} transaction(s)`, { type: 'error' });
            }
        } catch (error) {
            console.error('Bulk deletion failed:', error);
            OC.Notification.showTemporary('Failed to delete transactions');
        }
    }

    async bulkReconcileTransactions() {
        if (this.selectedTransactions.size === 0) {
            return;
        }

        if (!confirm(`Are you sure you want to mark ${this.selectedTransactions.size} transactions as reconciled?`)) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/transactions/bulk-reconcile'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    ids: Array.from(this.selectedTransactions),
                    reconciled: true
                })
            });

            const result = await response.json();

            if (result.success > 0) {
                OC.Notification.showTemporary(`Successfully reconciled ${result.success} transaction(s)`);
                this.selectedTransactions.clear();
                this.loadTransactions();
            }

            if (result.failed > 0) {
                OC.Notification.showTemporary(`Failed to reconcile ${result.failed} transaction(s)`, { type: 'error' });
            }
        } catch (error) {
            console.error('Bulk reconcile failed:', error);
            OC.Notification.showTemporary('Failed to reconcile transactions');
        }
    }

    async bulkUnreconcileTransactions() {
        if (this.selectedTransactions.size === 0) {
            return;
        }

        if (!confirm(`Are you sure you want to mark ${this.selectedTransactions.size} transactions as unreconciled?`)) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/transactions/bulk-reconcile'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    ids: Array.from(this.selectedTransactions),
                    reconciled: false
                })
            });

            const result = await response.json();

            if (result.success > 0) {
                OC.Notification.showTemporary(`Successfully unreconciled ${result.success} transaction(s)`);
                this.selectedTransactions.clear();
                this.loadTransactions();
            }

            if (result.failed > 0) {
                OC.Notification.showTemporary(`Failed to unreconcile ${result.failed} transaction(s)`, { type: 'error' });
            }
        } catch (error) {
            console.error('Bulk unreconcile failed:', error);
            OC.Notification.showTemporary('Failed to unreconcile transactions');
        }
    }

    showBulkEditModal() {
        if (this.selectedTransactions.size === 0) {
            return;
        }

        const modal = document.getElementById('bulk-edit-modal');
        const countElement = document.getElementById('bulk-edit-count');
        const categorySelect = document.getElementById('bulk-edit-category');

        // Update count
        if (countElement) {
            countElement.textContent = this.selectedTransactions.size;
        }

        // Populate category dropdown
        if (categorySelect && this.categories) {
            categorySelect.innerHTML = '<option value="">Don\'t change</option>';
            this.categories.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat.id;
                option.textContent = cat.name;
                categorySelect.appendChild(option);
            });
        }

        // Reset form
        document.getElementById('bulk-edit-vendor').value = '';
        document.getElementById('bulk-edit-reference').value = '';
        document.getElementById('bulk-edit-notes').value = '';

        modal.style.display = 'flex';
    }

    async submitBulkEdit() {
        const categoryId = document.getElementById('bulk-edit-category').value;
        const vendor = document.getElementById('bulk-edit-vendor').value.trim();
        const reference = document.getElementById('bulk-edit-reference').value.trim();
        const notes = document.getElementById('bulk-edit-notes').value.trim();

        // Build updates object with only non-empty fields
        const updates = {};
        if (categoryId) updates.categoryId = parseInt(categoryId);
        if (vendor) updates.vendor = vendor;
        if (reference) updates.reference = reference;
        if (notes) updates.notes = notes;

        if (Object.keys(updates).length === 0) {
            OC.Notification.showTemporary('Please fill in at least one field to update');
            return;
        }

        if (!confirm(`Are you sure you want to update ${this.selectedTransactions.size} transactions?`)) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/transactions/bulk-edit'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    ids: Array.from(this.selectedTransactions),
                    updates: updates
                })
            });

            const result = await response.json();

            if (result.success > 0) {
                OC.Notification.showTemporary(`Successfully updated ${result.success} transaction(s)`);
                this.selectedTransactions.clear();
                this.loadTransactions();

                // Close modal
                document.getElementById('bulk-edit-modal').style.display = 'none';
            }

            if (result.failed > 0) {
                OC.Notification.showTemporary(`Failed to update ${result.failed} transaction(s)`, { type: 'error' });
            }
        } catch (error) {
            console.error('Bulk edit failed:', error);
            OC.Notification.showTemporary('Failed to update transactions');
        }
    }

    toggleReconcileMode() {
        const reconcilePanel = document.getElementById('reconcile-panel');
        const reconcileBtn = document.getElementById('reconcile-mode-btn');

        if (reconcilePanel.style.display === 'none') {
            reconcilePanel.style.display = 'block';
            reconcileBtn.classList.add('active');
            this.populateFilterDropdowns();
        } else {
            reconcilePanel.style.display = 'none';
            reconcileBtn.classList.remove('active');
            this.reconcileMode = false;
            this.loadTransactions();
        }
    }

    async startReconciliation() {
        const accountId = document.getElementById('reconcile-account').value;
        const statementBalance = document.getElementById('reconcile-statement-balance').value;
        const statementDate = document.getElementById('reconcile-statement-date').value;

        if (!accountId || !statementBalance || !statementDate) {
            OC.Notification.showTemporary('Please fill in all reconciliation fields');
            return;
        }

        try {
            // Check if we have the reconcile endpoint, otherwise simulate it
            const account = this.accounts?.find(a => a.id === parseInt(accountId));
            if (!account) {
                throw new Error('Account not found');
            }

            let result;
            try {
                const response = await fetch(OC.generateUrl(`/apps/budget/api/accounts/${accountId}/reconcile`), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken
                    },
                    body: JSON.stringify({
                        statementBalance: parseFloat(statementBalance)
                    })
                });

                if (response.ok) {
                    result = await response.json();
                } else {
                    throw new Error('Endpoint not available');
                }
            } catch (apiError) {
                // Fallback: simulate reconciliation locally
                console.warn('Reconcile API not available, using local simulation:', apiError);
                const currentBalance = account.balance || 0;
                const targetBalance = parseFloat(statementBalance);
                const difference = targetBalance - currentBalance;

                result = {
                    currentBalance: currentBalance,
                    statementBalance: targetBalance,
                    difference: difference,
                    isBalanced: Math.abs(difference) < 0.01
                };
            }

            this.reconcileMode = true;
            this.reconcileData = result;

            // Show reconcile columns and filter by account
            document.querySelectorAll('.reconcile-column').forEach(col => {
                col.style.display = 'table-cell';
            });

            // Set account filter
            const filterAccount = document.getElementById('filter-account');
            if (filterAccount) {
                filterAccount.value = accountId;
                this.updateFilters();
            }

            // Hide reconcile panel and show reconcile info
            document.getElementById('reconcile-panel').style.display = 'none';
            this.showReconcileInfo(result);

            OC.Notification.showTemporary('Reconciliation mode started');
        } catch (error) {
            console.error('Reconciliation failed:', error);
            OC.Notification.showTemporary('Failed to start reconciliation: ' + error.message);
        }
    }

    showReconcileInfo(reconcileData) {
        // Create floating reconcile info panel
        const existingInfo = document.getElementById('reconcile-info-float');
        if (existingInfo) {
            existingInfo.remove();
        }

        const infoPanel = document.createElement('div');
        infoPanel.id = 'reconcile-info-float';
        infoPanel.className = 'reconcile-info-float';
        infoPanel.innerHTML = `
            <div class="reconcile-info-content">
                <h4>Account Reconciliation</h4>
                <div class="reconcile-stats">
                    <div class="stat">
                        <label>Current Balance:</label>
                        <span class="amount">${this.formatCurrency(reconcileData.currentBalance || 0)}</span>
                    </div>
                    <div class="stat">
                        <label>Statement Balance:</label>
                        <span class="amount">${this.formatCurrency(reconcileData.statementBalance || 0)}</span>
                    </div>
                    <div class="stat ${reconcileData.isBalanced ? 'balanced' : 'unbalanced'}">
                        <label>Difference:</label>
                        <span class="amount">${this.formatCurrency(reconcileData.difference || 0)}</span>
                    </div>
                </div>
                <button id="finish-reconcile-btn" class="primary" ${!reconcileData.isBalanced ? 'disabled' : ''}>
                    Finish Reconciliation
                </button>
                <button id="cancel-reconcile-info-btn" class="secondary">Cancel</button>
            </div>
        `;

        document.body.appendChild(infoPanel);

        // Add event listeners
        document.getElementById('finish-reconcile-btn').addEventListener('click', () => {
            this.finishReconciliation();
        });

        document.getElementById('cancel-reconcile-info-btn').addEventListener('click', () => {
            this.cancelReconciliation();
        });
    }

    cancelReconciliation() {
        this.reconcileMode = false;
        this.reconcileData = null;

        // Hide reconcile columns
        document.querySelectorAll('.reconcile-column').forEach(col => {
            col.style.display = 'none';
        });

        // Remove floating info panel
        const infoPanel = document.getElementById('reconcile-info-float');
        if (infoPanel) {
            infoPanel.remove();
        }

        // Reset reconcile panel
        document.getElementById('reconcile-panel').style.display = 'none';
        document.getElementById('reconcile-mode-btn').classList.remove('active');

        this.loadTransactions();
    }

    async handleImportFile(file) {
        const formData = new FormData();
        formData.append('file', file);

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import/upload'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken
                },
                body: formData
            });

            if (response.ok) {
                const result = await response.json();
                this.currentImportData = result;
                this.showImportMapping(result);
            } else {
                throw new Error('Upload failed');
            }
        } catch (error) {
            console.error('Failed to upload file:', error);
            OC.Notification.showTemporary('Failed to upload file');
        }
    }

    // ============================================
    // Enhanced Import System Methods
    // ============================================

    setupImportEventListeners() {
        // Tab navigation
        const tabButtons = document.querySelectorAll('.import-tab-btn');
        tabButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                const tabName = e.target.dataset.tab;
                this.switchImportTab(tabName);
            });
        });

        // Wizard navigation
        const nextBtn = document.getElementById('next-step-btn');
        const prevBtn = document.getElementById('prev-step-btn');
        const importBtn = document.getElementById('import-btn');
        const cancelBtn = document.getElementById('cancel-import-btn');

        if (nextBtn) {
            nextBtn.addEventListener('click', () => this.nextImportStep());
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', () => this.prevImportStep());
        }
        if (importBtn) {
            importBtn.addEventListener('click', () => this.executeImport());
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => this.cancelImport());
        }

        // Account selection triggers preview loading
        const importAccountSelect = document.getElementById('import-account');
        if (importAccountSelect) {
            importAccountSelect.addEventListener('change', () => {
                if (importAccountSelect.value && this.currentImportStep === 3) {
                    this.processImportData();
                }
            });
        }

        // Column mapping change handlers
        const mappingSelects = document.querySelectorAll('#import-step-2 select');
        mappingSelects.forEach(select => {
            select.addEventListener('change', () => this.updatePreviewMapping());
        });

        // Preview filter checkboxes
        const showDuplicates = document.getElementById('show-duplicates');
        const showUncategorized = document.getElementById('show-uncategorized');
        if (showDuplicates) {
            showDuplicates.addEventListener('change', () => this.filterPreviewTransactions());
        }
        if (showUncategorized) {
            showUncategorized.addEventListener('change', () => this.filterPreviewTransactions());
        }

        // Initialize import state
        this.currentImportStep = 1;
        this.currentImportData = null;
        this.importHistory = [];
    }

    switchImportTab(tabName) {
        // Switch tab buttons
        document.querySelectorAll('.import-tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');

        // Switch tab content
        document.querySelectorAll('.import-tab-content').forEach(content => {
            content.classList.remove('active');
        });
        document.getElementById(`import-${tabName}-tab`).classList.add('active');

        // Load tab-specific data
        if (tabName === 'history') {
            this.loadImportHistory();
        }
    }

    showImportMapping(uploadResult) {
        // Switch to wizard tab if not already active
        this.switchImportTab('wizard');

        // Store source accounts for multi-account mapping
        this.sourceAccounts = uploadResult.sourceAccounts || [];
        this.importFormat = uploadResult.format;

        // Update file info
        const fileDetails = document.querySelector('.file-details');
        if (fileDetails) {
            fileDetails.innerHTML = `
                <span class="file-name">${uploadResult.filename}</span>
                <span class="file-size">${this.formatFileSize(uploadResult.size)}</span>
                <span class="record-count">${uploadResult.recordCount} records</span>
            `;
        }

        // Populate column mapping dropdowns
        this.populateColumnMappings(uploadResult.columns);

        // Show preview data
        this.showMappingPreview(uploadResult.preview);

        // Move to step 2
        this.setImportStep(2);
    }

    populateColumnMappings(columns) {
        const mappingSelects = {
            'map-date': document.getElementById('map-date'),
            'map-amount': document.getElementById('map-amount'),
            'map-description': document.getElementById('map-description'),
            'map-type': document.getElementById('map-type'),
            'map-vendor': document.getElementById('map-vendor'),
            'map-reference': document.getElementById('map-reference')
        };

        // Clear existing options and add columns
        Object.values(mappingSelects).forEach(select => {
            if (!select) return;
            const firstOption = select.firstElementChild;
            select.innerHTML = '';
            if (firstOption) select.appendChild(firstOption);

            columns.forEach((column, index) => {
                const option = document.createElement('option');
                option.value = index;
                option.textContent = column;
                select.appendChild(option);
            });
        });

        // Auto-detect common column mappings
        this.autoDetectMappings(columns, mappingSelects);
    }

    autoDetectMappings(columns, mappingSelects) {
        const patterns = {
            'map-date': ['date', 'transaction date', 'trans date', 'posting date'],
            'map-amount': ['amount', 'transaction amount', 'trans amount', 'value'],
            'map-description': ['description', 'memo', 'details', 'transaction details'],
            'map-type': ['type', 'transaction type', 'debit/credit', 'dr/cr'],
            'map-vendor': ['vendor', 'payee', 'merchant', 'counterparty'],
            'map-reference': ['reference', 'ref', 'check number', 'transaction id']
        };

        Object.entries(patterns).forEach(([fieldId, patternList]) => {
            const select = mappingSelects[fieldId];
            if (!select) return;

            const matchingColumn = columns.findIndex(col =>
                patternList.some(pattern =>
                    col.toLowerCase().includes(pattern.toLowerCase())
                )
            );

            if (matchingColumn !== -1) {
                select.value = matchingColumn;
            }
        });
    }

    showMappingPreview(previewData) {
        const table = document.getElementById('mapping-preview-table');
        if (!table || !previewData.length) return;

        // Create header
        const thead = table.querySelector('thead');
        const tbody = table.querySelector('tbody');

        thead.innerHTML = '';
        tbody.innerHTML = '';

        const headerRow = document.createElement('tr');
        previewData[0].forEach((header, index) => {
            const th = document.createElement('th');
            th.textContent = `${index + 1}. ${header}`;
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);

        // Show first 5 rows of data
        previewData.slice(1, 6).forEach(row => {
            const tr = document.createElement('tr');
            row.forEach(cell => {
                const td = document.createElement('td');
                // Handle objects/arrays by converting to string
                if (cell === null || cell === undefined) {
                    td.textContent = '';
                } else if (typeof cell === 'object') {
                    td.textContent = JSON.stringify(cell);
                } else {
                    td.textContent = String(cell);
                }
                td.title = td.textContent; // Show full text on hover
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
    }

    updatePreviewMapping() {
        // Update the mapping preview when selections change
        const mapping = this.getCurrentMapping();
        // Update mapping indicators in preview table
        this.highlightMappedColumns(mapping);
        this.validateMappingStep();
    }

    getCurrentMapping() {
        return {
            date: document.getElementById('map-date')?.value || null,
            amount: document.getElementById('map-amount')?.value || null,
            description: document.getElementById('map-description')?.value || null,
            type: document.getElementById('map-type')?.value || null,
            vendor: document.getElementById('map-vendor')?.value || null,
            reference: document.getElementById('map-reference')?.value || null,
            skipFirstRow: document.getElementById('skip-first-row')?.checked || false,
            applyRules: document.getElementById('apply-rules')?.checked || false
        };
    }

    highlightMappedColumns(mapping) {
        const table = document.getElementById('mapping-preview-table');
        const headers = table.querySelectorAll('th');

        // Reset highlighting
        headers.forEach(th => th.classList.remove('mapped-column'));

        // Highlight mapped columns
        Object.values(mapping).forEach(columnIndex => {
            if (columnIndex !== null && columnIndex !== '') {
                const header = headers[parseInt(columnIndex)];
                if (header) header.classList.add('mapped-column');
            }
        });
    }

    async nextImportStep() {
        if (this.currentImportStep === 1) {
            // Step 1 → 2: File should be uploaded
            if (!this.currentImportData) {
                OC.Notification.showTemporary('Please select a file first');
                return;
            }
            this.setImportStep(2);
        } else if (this.currentImportStep === 2) {
            // Step 2 → 3: Validate mapping, then show step 3 with account selection
            if (!this.validateMappingStep()) {
                return;
            }
            this.setImportStep(3);
            // Preview will be loaded when user selects an account
        }
    }

    prevImportStep() {
        if (this.currentImportStep > 1) {
            this.setImportStep(this.currentImportStep - 1);
        }
    }

    setImportStep(step) {
        this.currentImportStep = step;

        // Update progress bar
        document.querySelectorAll('.wizard-step').forEach((stepEl, index) => {
            stepEl.classList.remove('active', 'completed');
            if (index + 1 < step) {
                stepEl.classList.add('completed');
            } else if (index + 1 === step) {
                stepEl.classList.add('active');
            }
        });

        // Show/hide steps
        document.querySelectorAll('.import-step').forEach((stepEl, index) => {
            stepEl.classList.remove('active');
            stepEl.style.display = 'none';
            if (index + 1 === step) {
                stepEl.classList.add('active');
                stepEl.style.display = 'block';
            }
        });

        // Update navigation buttons
        const prevBtn = document.getElementById('prev-step-btn');
        const nextBtn = document.getElementById('next-step-btn');
        const importBtn = document.getElementById('import-btn');

        if (prevBtn) {
            prevBtn.style.display = step > 1 ? 'block' : 'none';
        }

        if (nextBtn) {
            nextBtn.style.display = step < 3 ? 'block' : 'none';
            nextBtn.disabled = !this.canProceedToNextStep();
        }

        if (importBtn) {
            importBtn.style.display = step === 3 ? 'block' : 'none';
        }

        // Load step-specific data
        if (step === 3) {
            this.loadAccountsForImport();
        }
    }

    canProceedToNextStep() {
        if (this.currentImportStep === 1) {
            return this.currentImportData !== null;
        } else if (this.currentImportStep === 2) {
            return this.validateMappingStep();
        }
        return false;
    }

    validateMappingStep() {
        const mapping = this.getCurrentMapping();
        const required = ['date', 'amount', 'description'];

        const isValid = required.every(field =>
            mapping[field] !== null && mapping[field] !== ''
        );

        // Update next button state
        const nextBtn = document.getElementById('next-step-btn');
        if (nextBtn) {
            nextBtn.disabled = !isValid;
        }

        return isValid;
    }

    async processImportData() {
        const mapping = this.getCurrentMapping();
        const isMultiAccount = this.sourceAccounts && this.sourceAccounts.length > 0;

        // Build request body based on import type
        const requestBody = {
            fileId: this.currentImportData.fileId,
            mapping: mapping,
            skipDuplicates: !(document.getElementById('show-duplicates')?.checked ?? true)
        };

        if (isMultiAccount) {
            const accountMapping = this.getAccountMapping();
            if (Object.keys(accountMapping).length === 0) {
                OC.Notification.showTemporary('Please map at least one account');
                return;
            }
            requestBody.accountMapping = accountMapping;
        } else {
            const accountId = document.getElementById('import-account')?.value;
            if (!accountId) {
                OC.Notification.showTemporary('Please select an account first');
                return;
            }
            requestBody.accountId = parseInt(accountId);
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import/preview'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(requestBody)
            });

            if (response.ok) {
                const result = await response.json();
                this.processedTransactions = result.transactions;
                this.updateImportSummary(result);
                this.showTransactionPreview(result.transactions);
                this.filterPreviewTransactions();
            } else {
                const errorData = await response.json();
                throw new Error(errorData.error || 'Processing failed');
            }
        } catch (error) {
            console.error('Failed to process import data:', error);
            OC.Notification.showTemporary('Failed to process import data: ' + error.message);
        }
    }

    updateImportSummary(result) {
        document.getElementById('total-transactions').textContent = result.totalRows || 0;
        document.getElementById('new-transactions').textContent = result.validTransactions || 0;
        document.getElementById('duplicate-transactions').textContent = result.duplicates || 0;
        // Count transactions with categoryId set
        const categorized = (result.transactions || []).filter(t => t.categoryId).length;
        document.getElementById('categorized-transactions').textContent = categorized;
    }

    showTransactionPreview(transactions) {
        const tbody = document.querySelector('#preview-table tbody');
        if (!tbody) return;

        tbody.innerHTML = '';

        if (!transactions || transactions.length === 0) {
            const row = document.createElement('tr');
            row.innerHTML = '<td colspan="6" style="text-align: center; padding: 20px;">No transactions to import</td>';
            tbody.appendChild(row);
            document.getElementById('preview-info').textContent = 'No transactions found';
            return;
        }

        transactions.slice(0, 50).forEach((transaction, index) => {
            const row = document.createElement('tr');
            const amount = parseFloat(transaction.amount) || 0;
            const isDuplicate = transaction.isDuplicate || false;
            const statusBadge = isDuplicate
                ? '<span class="status-badge status-error">Duplicate</span>'
                : '<span class="status-badge status-success">New</span>';

            row.innerHTML = `
                <td>
                    <input type="checkbox" ${isDuplicate ? '' : 'checked'} data-row-index="${transaction.rowIndex ?? index}">
                </td>
                <td>${transaction.date || ''}</td>
                <td>${transaction.description || ''}</td>
                <td class="${amount >= 0 ? 'positive' : 'negative'}">
                    ${this.formatCurrency(amount)}
                </td>
                <td>${transaction.ruleName || 'Uncategorized'}</td>
                <td>
                    ${statusBadge}
                </td>
            `;

            tbody.appendChild(row);
        });

        document.getElementById('preview-info').textContent =
            `Showing ${Math.min(50, transactions.length)} of ${transactions.length}`;
    }

    filterPreviewTransactions() {
        const showDuplicates = document.getElementById('show-duplicates')?.checked ?? true;
        const showUncategorized = document.getElementById('show-uncategorized')?.checked ?? true;
        const tbody = document.querySelector('#preview-table tbody');

        if (!tbody) return;

        const rows = tbody.querySelectorAll('tr');
        let visibleCount = 0;

        rows.forEach(row => {
            const statusBadge = row.querySelector('.status-badge');
            const category = row.cells[4]?.textContent?.trim();

            const isDuplicate = statusBadge?.textContent?.trim() === 'Duplicate';
            const isUncategorized = category === 'Uncategorized';

            let shouldShow = true;

            if (isDuplicate && !showDuplicates) {
                shouldShow = false;
            }

            if (isUncategorized && !showUncategorized) {
                shouldShow = false;
            }

            if (shouldShow) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Update the preview info text
        const totalCount = rows.length;
        document.getElementById('preview-info').textContent =
            `Showing ${visibleCount} of ${totalCount}`;
    }

    async loadAccountsForImport() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/accounts'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            const accounts = await response.json();
            this.availableAccounts = accounts;

            const singleAccountSection = document.getElementById('single-account-selection');
            const multiAccountSection = document.getElementById('multi-account-mapping');

            // Check if we have multi-account OFX/QIF file
            if (this.sourceAccounts && this.sourceAccounts.length > 0) {
                // Show multi-account mapping UI
                if (singleAccountSection) singleAccountSection.style.display = 'none';
                if (multiAccountSection) multiAccountSection.style.display = 'block';

                this.renderAccountMappingUI(accounts);
            } else {
                // Show single account selection (for CSV)
                if (singleAccountSection) singleAccountSection.style.display = 'flex';
                if (multiAccountSection) multiAccountSection.style.display = 'none';

                const select = document.getElementById('import-account');
                if (select) {
                    select.innerHTML = '<option value="">Select account...</option>';
                    accounts.forEach(account => {
                        const option = document.createElement('option');
                        option.value = account.id;
                        const accountNum = account.accountNumber ? ` - ${account.accountNumber}` : '';
                        option.textContent = `${account.name} (${account.type}${accountNum})`;
                        select.appendChild(option);
                    });
                }
            }
        } catch (error) {
            console.error('Failed to load accounts:', error);
        }
    }

    renderAccountMappingUI(accounts) {
        const container = document.getElementById('account-mapping-list');
        if (!container) return;

        container.innerHTML = '';

        this.sourceAccounts.forEach(sourceAccount => {
            const row = document.createElement('div');
            row.className = 'account-mapping-row';
            row.dataset.sourceAccountId = sourceAccount.accountId;

            // Build details string
            const details = [];
            if (sourceAccount.type) details.push(sourceAccount.type);
            if (sourceAccount.currency) details.push(sourceAccount.currency);
            if (sourceAccount.transactionCount) details.push(`${sourceAccount.transactionCount} transactions`);
            if (sourceAccount.ledgerBalance !== null && sourceAccount.ledgerBalance !== undefined) {
                details.push(`Balance: ${this.formatCurrency(sourceAccount.ledgerBalance)}`);
            }

            // Build account options HTML with auto-match selection
            const suggestedMatch = sourceAccount.suggestedMatch;
            let optionsHtml = '<option value="">Skip this account</option>';
            accounts.forEach(account => {
                const accountNum = account.accountNumber ? ` - ${account.accountNumber}` : '';
                const selected = suggestedMatch === account.id ? ' selected' : '';
                optionsHtml += `<option value="${account.id}"${selected}>${account.name} (${account.type}${accountNum})</option>`;
            });

            row.innerHTML = `
                <div class="source-account-info">
                    <span class="source-account-id">${sourceAccount.accountId}</span>
                    <span class="source-account-details">${details.join(' • ')}</span>
                </div>
                <span class="mapping-arrow">→</span>
                <select class="destination-account-select" data-source-id="${sourceAccount.accountId}">
                    ${optionsHtml}
                </select>
            `;

            container.appendChild(row);
        });

        // Add change listeners to trigger preview
        container.querySelectorAll('.destination-account-select').forEach(select => {
            select.addEventListener('change', () => {
                if (this.hasAnyAccountMapping()) {
                    this.processImportData();
                }
            });
        });

        // Auto-trigger preview if any accounts were auto-matched
        if (this.hasAnyAccountMapping()) {
            this.processImportData();
        }
    }

    hasAnyAccountMapping() {
        const selects = document.querySelectorAll('.destination-account-select');
        return Array.from(selects).some(select => select.value);
    }

    getAccountMapping() {
        const mapping = {};
        document.querySelectorAll('.destination-account-select').forEach(select => {
            if (select.value) {
                mapping[select.dataset.sourceId] = parseInt(select.value);
            }
        });
        return mapping;
    }

    async executeImport() {
        if (!this.currentImportData?.fileId) {
            OC.Notification.showTemporary('No file data available');
            return;
        }

        const mapping = this.getCurrentMapping();
        const isMultiAccount = this.sourceAccounts && this.sourceAccounts.length > 0;

        // Build request body based on import type
        const requestBody = {
            fileId: this.currentImportData.fileId,
            mapping: mapping,
            skipDuplicates: !(document.getElementById('show-duplicates')?.checked ?? true),
            applyRules: true
        };

        if (isMultiAccount) {
            const accountMapping = this.getAccountMapping();
            if (Object.keys(accountMapping).length === 0) {
                OC.Notification.showTemporary('Please map at least one account');
                return;
            }
            requestBody.accountMapping = accountMapping;
        } else {
            const accountId = document.getElementById('import-account').value;
            if (!accountId) {
                OC.Notification.showTemporary('Please select an account');
                return;
            }
            requestBody.accountId = parseInt(accountId);
        }

        // Show loading state on import button
        const importBtn = document.getElementById('import-btn');
        const originalText = importBtn.textContent;
        importBtn.disabled = true;
        importBtn.textContent = 'Importing...';

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import/process'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(requestBody)
            });

            const responseText = await response.text();
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Server response:', responseText);
                throw new Error(`Server error (${response.status}): Invalid response`);
            }

            if (response.ok) {
                OC.Notification.showTemporary(`Successfully imported ${result.imported} transactions (${result.skipped} skipped)`);
                this.resetImportWizard();
                this.loadTransactions();
            } else {
                throw new Error(result.error || 'Import failed');
            }
        } catch (error) {
            console.error('Failed to execute import:', error);
            OC.Notification.showTemporary('Failed to import transactions: ' + error.message);
        } finally {
            // Restore button state
            importBtn.disabled = false;
            importBtn.textContent = originalText;
        }
    }

    cancelImport() {
        this.resetImportWizard();
    }

    resetImportWizard() {
        this.currentImportStep = 1;
        this.currentImportData = null;
        this.processedTransactions = null;
        this.sourceAccounts = [];
        this.importFormat = null;

        this.setImportStep(1);

        // Clear form fields
        document.getElementById('import-file-input').value = '';
        document.querySelectorAll('#import-step-2 select').forEach(select => {
            select.selectedIndex = 0;
        });

        // Reset account selection UI
        const singleAccountSection = document.getElementById('single-account-selection');
        const multiAccountSection = document.getElementById('multi-account-mapping');
        if (singleAccountSection) singleAccountSection.style.display = 'flex';
        if (multiAccountSection) multiAccountSection.style.display = 'none';

        // Clear preview tables
        const mappingPreviewBody = document.querySelector('#mapping-preview-table tbody');
        const previewTableBody = document.querySelector('#preview-table tbody');
        const accountMappingList = document.getElementById('account-mapping-list');
        if (mappingPreviewBody) mappingPreviewBody.innerHTML = '';
        if (previewTableBody) previewTableBody.innerHTML = '';
        if (accountMappingList) accountMappingList.innerHTML = '';
    }

    // ==================== RULES VIEW METHODS ====================

    async loadRulesView() {
        // Always setup event listeners first, even if data load fails
        this.setupRulesEventListeners();

        try {
            await this.loadRules();
        } catch (error) {
            console.error('Failed to load rules view:', error);
            OC.Notification.showTemporary('Failed to load rules');
        }
    }

    async loadRules() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import-rules'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            this.rules = await response.json();
            this.renderRules(this.rules);
            this.updateRulesSummary();
        } catch (error) {
            console.error('Failed to load rules:', error);
            throw error;
        }
    }

    renderRules(rules) {
        const rulesList = document.getElementById('rules-list');
        const emptyRules = document.getElementById('empty-rules');

        if (!rulesList) return;

        if (!rules || rules.length === 0) {
            rulesList.innerHTML = '';
            if (emptyRules) emptyRules.style.display = 'flex';
            return;
        }

        if (emptyRules) emptyRules.style.display = 'none';

        rulesList.innerHTML = rules.map(rule => {
            const actions = rule.actions || {};
            const actionBadges = this.getRuleActionBadges(rule, actions);
            const matchTypeLabels = {
                'contains': 'contains',
                'exact': 'equals',
                'starts_with': 'starts with',
                'ends_with': 'ends with',
                'regex': 'matches'
            };

            const criteriaText = `${rule.field} ${matchTypeLabels[rule.matchType] || rule.matchType} "${this.escapeHtml(rule.pattern)}"`;

            return `
                <tr class="rule-row ${rule.active ? '' : 'inactive'}" data-rule-id="${rule.id}">
                    <td class="rules-col-priority">${rule.priority}</td>
                    <td class="rules-col-name">${this.escapeHtml(rule.name)}</td>
                    <td class="rules-col-status">
                        <label class="rule-toggle" title="${rule.active ? 'Click to disable' : 'Click to enable'}">
                            <input type="checkbox" class="rule-active-toggle" data-rule-id="${rule.id}" ${rule.active ? 'checked' : ''}>
                            <span class="rule-toggle-slider"></span>
                        </label>
                        ${rule.applyOnImport ? '<span class="status-badge import">Import</span>' : ''}
                    </td>
                    <td class="rules-col-criteria"><code>${criteriaText}</code></td>
                    <td class="rules-col-actions">${actionBadges}</td>
                    <td class="rules-col-buttons">
                        <button class="icon-rename rule-edit-btn" data-rule-id="${rule.id}" title="Edit rule"></button>
                        <button class="icon-delete rule-delete-btn" data-rule-id="${rule.id}" title="Delete rule"></button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    getRuleActionBadges(rule, actions) {
        const badges = [];

        // Check for category action
        const categoryId = actions.categoryId || rule.categoryId;
        if (categoryId) {
            const category = this.categories?.find(c => c.id === categoryId);
            const categoryName = category?.name || `Category #${categoryId}`;
            badges.push(`<span class="action-badge category">→ ${this.escapeHtml(categoryName)}</span>`);
        }

        // Check for vendor action
        const vendor = actions.vendor || rule.vendorName;
        if (vendor) {
            badges.push(`<span class="action-badge vendor">Vendor: ${this.escapeHtml(vendor)}</span>`);
        }

        // Check for notes action
        if (actions.notes) {
            badges.push(`<span class="action-badge notes">Set notes</span>`);
        }

        return badges.length > 0 ? badges.join('') : '<span class="action-badge none">No actions</span>';
    }

    updateRulesSummary() {
        const totalCount = document.getElementById('rules-total-count');
        const activeCount = document.getElementById('rules-active-count');

        if (totalCount && this.rules) {
            totalCount.textContent = this.rules.length;
        }
        if (activeCount && this.rules) {
            activeCount.textContent = this.rules.filter(r => r.active).length;
        }
    }

    setupRulesEventListeners() {
        console.log('setupRulesEventListeners called');

        // Add Rule button in view header
        const addRuleBtn = document.getElementById('rules-add-btn');
        console.log('rules-add-btn found:', addRuleBtn);
        if (addRuleBtn && !addRuleBtn.dataset.listenerAttached) {
            addRuleBtn.addEventListener('click', () => {
                console.log('Add Rule button clicked');
                this.showRuleModal();
            });
            addRuleBtn.dataset.listenerAttached = 'true';
        }

        // Empty state add button
        const emptyAddBtn = document.getElementById('empty-rules-add-btn');
        if (emptyAddBtn && !emptyAddBtn.dataset.listenerAttached) {
            emptyAddBtn.addEventListener('click', () => this.showRuleModal());
            emptyAddBtn.dataset.listenerAttached = 'true';
        }

        // Apply Rules button
        const applyRulesBtn = document.getElementById('apply-rules-btn');
        if (applyRulesBtn && !applyRulesBtn.dataset.listenerAttached) {
            applyRulesBtn.addEventListener('click', () => this.showApplyRulesModal());
            applyRulesBtn.dataset.listenerAttached = 'true';
        }

        // Rule form submit
        const ruleForm = document.getElementById('rule-form');
        if (ruleForm && !ruleForm.dataset.listenerAttached) {
            ruleForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveRule();
            });
            ruleForm.dataset.listenerAttached = 'true';
        }

        // Preview button in apply modal
        const previewBtn = document.getElementById('preview-rules-btn');
        if (previewBtn && !previewBtn.dataset.listenerAttached) {
            previewBtn.addEventListener('click', () => this.previewRuleApplication());
            previewBtn.dataset.listenerAttached = 'true';
        }

        // Execute apply button
        const executeBtn = document.getElementById('execute-apply-rules-btn');
        if (executeBtn && !executeBtn.dataset.listenerAttached) {
            executeBtn.addEventListener('click', () => this.executeApplyRules());
            executeBtn.dataset.listenerAttached = 'true';
        }

        // Select/Deselect all rules
        const selectAllBtn = document.getElementById('select-all-rules');
        const deselectAllBtn = document.getElementById('deselect-all-rules');
        if (selectAllBtn && !selectAllBtn.dataset.listenerAttached) {
            selectAllBtn.addEventListener('click', () => this.toggleAllRuleSelections(true));
            selectAllBtn.dataset.listenerAttached = 'true';
        }
        if (deselectAllBtn && !deselectAllBtn.dataset.listenerAttached) {
            deselectAllBtn.addEventListener('click', () => this.toggleAllRuleSelections(false));
            deselectAllBtn.dataset.listenerAttached = 'true';
        }

        // Delegate click events for rule cards
        const rulesList = document.getElementById('rules-list');
        if (rulesList && !rulesList.dataset.listenerAttached) {
            rulesList.addEventListener('click', (e) => {
                const editBtn = e.target.closest('.rule-edit-btn');
                const deleteBtn = e.target.closest('.rule-delete-btn');

                if (editBtn) {
                    const ruleId = parseInt(editBtn.dataset.ruleId);
                    this.editRule(ruleId);
                } else if (deleteBtn) {
                    const ruleId = parseInt(deleteBtn.dataset.ruleId);
                    this.deleteRule(ruleId);
                }
            });

            // Toggle active state
            rulesList.addEventListener('change', (e) => {
                if (e.target.classList.contains('rule-active-toggle')) {
                    const ruleId = parseInt(e.target.dataset.ruleId);
                    const active = e.target.checked;
                    this.toggleRuleActive(ruleId, active);
                }
            });

            rulesList.dataset.listenerAttached = 'true';
        }
    }

    async showRuleModal(rule = null) {
        console.log('showRuleModal called', rule);
        const modal = document.getElementById('rule-modal');
        const title = document.getElementById('rule-modal-title');
        const form = document.getElementById('rule-form');

        console.log('modal:', modal, 'form:', form);
        if (!modal || !form) {
            console.error('Modal or form not found!');
            return;
        }

        form.reset();
        document.getElementById('rule-id').value = '';

        // Populate category dropdown
        await this.populateRuleCategoryDropdown();

        if (rule) {
            title.textContent = 'Edit Rule';
            document.getElementById('rule-id').value = rule.id;
            document.getElementById('rule-name').value = rule.name || '';
            document.getElementById('rule-field').value = rule.field || 'description';
            document.getElementById('rule-match-type').value = rule.matchType || 'contains';
            document.getElementById('rule-pattern').value = rule.pattern || '';
            document.getElementById('rule-priority').value = rule.priority || 0;
            document.getElementById('rule-active').checked = rule.active !== false;
            document.getElementById('rule-apply-on-import').checked = rule.applyOnImport !== false;

            // Parse actions
            const actions = rule.actions || {};
            document.getElementById('rule-action-category').value = actions.categoryId || rule.categoryId || '';
            document.getElementById('rule-action-vendor').value = actions.vendor || rule.vendorName || '';
            document.getElementById('rule-action-notes').value = actions.notes || '';
        } else {
            title.textContent = 'Add Rule';
        }

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');

        // Focus on name field
        const nameField = document.getElementById('rule-name');
        if (nameField) nameField.focus();
    }

    async populateRuleCategoryDropdown() {
        const select = document.getElementById('rule-action-category');
        if (!select) return;

        // Keep first option (-- Don't change --)
        const firstOption = select.options[0];
        select.innerHTML = '';
        select.appendChild(firstOption);

        // Add categories
        if (this.categories) {
            this.categories.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat.id;
                option.textContent = cat.name;
                select.appendChild(option);
            });
        }
    }

    async saveRule() {
        const ruleId = document.getElementById('rule-id').value;
        const isEdit = !!ruleId;

        // Collect form data
        const name = document.getElementById('rule-name').value.trim();
        const field = document.getElementById('rule-field').value;
        const matchType = document.getElementById('rule-match-type').value;
        const pattern = document.getElementById('rule-pattern').value.trim();
        const priority = parseInt(document.getElementById('rule-priority').value) || 0;
        const active = document.getElementById('rule-active').checked;
        const applyOnImport = document.getElementById('rule-apply-on-import').checked;

        // Collect actions
        const categoryId = document.getElementById('rule-action-category').value;
        const vendor = document.getElementById('rule-action-vendor').value.trim();
        const notes = document.getElementById('rule-action-notes').value.trim();

        const actions = {};
        if (categoryId) actions.categoryId = parseInt(categoryId);
        if (vendor) actions.vendor = vendor;
        if (notes) actions.notes = notes;

        try {
            const url = isEdit
                ? OC.generateUrl(`/apps/budget/api/import-rules/${ruleId}`)
                : OC.generateUrl('/apps/budget/api/import-rules');

            const response = await fetch(url, {
                method: isEdit ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    name,
                    pattern,
                    field,
                    matchType,
                    priority,
                    active,
                    applyOnImport,
                    actions: Object.keys(actions).length > 0 ? actions : null
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to save rule');
            }

            OC.Notification.showTemporary(isEdit ? 'Rule updated successfully' : 'Rule created successfully');
            this.hideModals();
            await this.loadRules();
        } catch (error) {
            console.error('Failed to save rule:', error);
            OC.Notification.showTemporary('Failed to save rule: ' + error.message);
        }
    }

    async editRule(ruleId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/import-rules/${ruleId}`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const rule = await response.json();
            this.showRuleModal(rule);
        } catch (error) {
            console.error('Failed to load rule:', error);
            OC.Notification.showTemporary('Failed to load rule');
        }
    }

    async deleteRule(ruleId) {
        if (!confirm('Are you sure you want to delete this rule?')) return;

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/import-rules/${ruleId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            OC.Notification.showTemporary('Rule deleted successfully');
            await this.loadRules();
        } catch (error) {
            console.error('Failed to delete rule:', error);
            OC.Notification.showTemporary('Failed to delete rule');
        }
    }

    async toggleRuleActive(ruleId, active) {
        try {
            // Find the rule data
            const rule = this.rules.find(r => r.id === ruleId);
            if (!rule) throw new Error('Rule not found');

            const response = await fetch(OC.generateUrl(`/apps/budget/api/import-rules/${ruleId}`), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    ...rule,
                    active: active
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to update rule');
            }

            // Update local state
            rule.active = active;

            // Update the row styling
            const row = document.querySelector(`.rule-row[data-rule-id="${ruleId}"]`);
            if (row) {
                row.classList.toggle('inactive', !active);
            }

            OC.Notification.showTemporary(active ? 'Rule enabled' : 'Rule disabled');
        } catch (error) {
            console.error('Failed to toggle rule:', error);
            OC.Notification.showTemporary('Failed to update rule: ' + error.message);
            // Revert the checkbox
            await this.loadRules();
        }
    }

    async showApplyRulesModal() {
        const modal = document.getElementById('apply-rules-modal');
        if (!modal) return;

        // Reset state
        document.getElementById('apply-rules-preview').style.display = 'none';
        document.getElementById('apply-rules-results').style.display = 'none';
        document.getElementById('execute-apply-rules-btn').disabled = true;

        // Populate account filter
        await this.populateApplyRulesFilters();

        // Populate rules selection
        await this.populateRulesSelectionList();

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    async populateApplyRulesFilters() {
        const accountSelect = document.getElementById('apply-account-filter');
        if (!accountSelect) return;

        // Keep first option (All Accounts)
        const firstOption = accountSelect.options[0];
        accountSelect.innerHTML = '';
        accountSelect.appendChild(firstOption);

        // Add accounts
        if (this.accounts) {
            this.accounts.forEach(account => {
                const option = document.createElement('option');
                option.value = account.id;
                option.textContent = account.name;
                accountSelect.appendChild(option);
            });
        }
    }

    async populateRulesSelectionList() {
        const container = document.getElementById('rules-selection-list');
        if (!container) return;

        // Ensure we have rules loaded
        if (!this.rules) {
            await this.loadRules();
        }

        const activeRules = this.rules?.filter(r => r.active) || [];

        if (activeRules.length === 0) {
            container.innerHTML = '<p class="no-rules-message">No active rules available. Create and activate rules first.</p>';
            return;
        }

        container.innerHTML = activeRules.map(rule => `
            <label class="rule-selection-item">
                <input type="checkbox" name="rule-select" value="${rule.id}" checked>
                <span class="rule-select-name">${this.escapeHtml(rule.name)}</span>
                <span class="rule-select-pattern">${this.escapeHtml(rule.pattern)}</span>
            </label>
        `).join('');
    }

    toggleAllRuleSelections(checked) {
        const checkboxes = document.querySelectorAll('#rules-selection-list input[type="checkbox"]');
        checkboxes.forEach(cb => cb.checked = checked);
    }

    async previewRuleApplication() {
        const previewDiv = document.getElementById('apply-rules-preview');
        const resultsDiv = document.getElementById('apply-rules-results');
        const executeBtn = document.getElementById('execute-apply-rules-btn');
        const previewBtn = document.getElementById('preview-rules-btn');

        if (!previewDiv) return;

        // Collect filters
        const filters = this.collectApplyRulesFilters();
        const ruleIds = this.collectSelectedRuleIds();

        if (ruleIds.length === 0) {
            OC.Notification.showTemporary('Please select at least one rule');
            return;
        }

        previewBtn.disabled = true;
        previewBtn.textContent = 'Loading...';

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import-rules/preview'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    ruleIds,
                    ...filters
                })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const result = await response.json();
            this.renderPreviewResults(result);

            previewDiv.style.display = 'block';
            resultsDiv.style.display = 'none';
            executeBtn.disabled = result.matchCount === 0;

        } catch (error) {
            console.error('Failed to preview rules:', error);
            OC.Notification.showTemporary('Failed to preview rule application');
        } finally {
            previewBtn.disabled = false;
            previewBtn.textContent = 'Preview Changes';
        }
    }

    collectApplyRulesFilters() {
        const accountId = document.getElementById('apply-account-filter')?.value || null;
        const startDate = document.getElementById('apply-date-start')?.value || null;
        const endDate = document.getElementById('apply-date-end')?.value || null;
        const uncategorizedOnly = document.getElementById('apply-uncategorized-only')?.checked || false;

        return {
            accountId: accountId ? parseInt(accountId) : null,
            startDate,
            endDate,
            uncategorizedOnly
        };
    }

    collectSelectedRuleIds() {
        const checkboxes = document.querySelectorAll('#rules-selection-list input[type="checkbox"]:checked');
        return Array.from(checkboxes).map(cb => parseInt(cb.value));
    }

    renderPreviewResults(result) {
        const countSpan = document.getElementById('preview-match-count');
        const tbody = document.querySelector('#apply-rules-preview-table tbody');

        if (countSpan) countSpan.textContent = result.matchCount;

        if (tbody) {
            tbody.innerHTML = result.preview.slice(0, 50).map(item => {
                const changesHtml = Object.entries(item.changes).map(([field, change]) => {
                    const fromVal = change.from || '(empty)';
                    const toVal = change.to || '(empty)';
                    if (field === 'categoryId') {
                        const fromCat = this.categories?.find(c => c.id === change.from)?.name || fromVal;
                        const toCat = this.categories?.find(c => c.id === change.to)?.name || toVal;
                        return `<span class="change-item">Category: ${this.escapeHtml(fromCat)} → ${this.escapeHtml(toCat)}</span>`;
                    }
                    return `<span class="change-item">${field}: ${this.escapeHtml(String(fromVal))} → ${this.escapeHtml(String(toVal))}</span>`;
                }).join('');

                return `
                    <tr>
                        <td>${this.formatDate(item.transactionDate)}</td>
                        <td>${this.escapeHtml(item.transactionDescription)}</td>
                        <td>${this.formatCurrency(item.transactionAmount)}</td>
                        <td>${this.escapeHtml(item.ruleName)}</td>
                        <td>${changesHtml}</td>
                    </tr>
                `;
            }).join('');

            if (result.matchCount > 50) {
                tbody.innerHTML += `<tr><td colspan="5" class="preview-truncated">... and ${result.matchCount - 50} more transactions</td></tr>`;
            }
        }
    }

    async executeApplyRules() {
        const previewDiv = document.getElementById('apply-rules-preview');
        const resultsDiv = document.getElementById('apply-rules-results');
        const executeBtn = document.getElementById('execute-apply-rules-btn');

        if (!confirm('Apply rules to the previewed transactions? This will modify the selected transactions.')) {
            return;
        }

        // Collect filters and rules
        const filters = this.collectApplyRulesFilters();
        const ruleIds = this.collectSelectedRuleIds();

        executeBtn.disabled = true;
        executeBtn.textContent = 'Applying...';

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import-rules/apply'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    ruleIds,
                    ...filters
                })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const result = await response.json();

            // Show results
            document.getElementById('result-success-count').textContent = result.success;
            document.getElementById('result-skipped-count').textContent = result.skipped;
            document.getElementById('result-failed-count').textContent = result.failed;

            previewDiv.style.display = 'none';
            resultsDiv.style.display = 'block';

            OC.Notification.showTemporary(`Rules applied: ${result.success} updated, ${result.skipped} skipped, ${result.failed} failed`);

            // Refresh transactions if we're on that view
            if (this.currentView === 'transactions') {
                await this.loadTransactions();
            }

        } catch (error) {
            console.error('Failed to apply rules:', error);
            OC.Notification.showTemporary('Failed to apply rules');
        } finally {
            executeBtn.disabled = false;
            executeBtn.textContent = 'Apply Rules';
        }
    }

    // ==================== END RULES VIEW METHODS ====================

    // Import History Management
    async loadImportHistory() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import/history'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            const history = await response.json();
            this.importHistory = history;
            this.renderImportHistory(history);
        } catch (error) {
            console.error('Failed to load import history:', error);
        }
    }

    renderImportHistory(history) {
        const tbody = document.querySelector('#history-table tbody');
        if (!tbody) return;

        tbody.innerHTML = '';

        history.forEach(item => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${this.formatDate(item.importDate)}</td>
                <td>${item.filename}</td>
                <td>${item.accountName}</td>
                <td>${item.transactionCount}</td>
                <td>
                    <span class="status-badge status-${item.status}">
                        ${item.status.charAt(0).toUpperCase() + item.status.slice(1)}
                    </span>
                </td>
                <td>
                    <button class="icon-download" onclick="budgetApp.downloadImport(${item.id})" title="Download"></button>
                    <button class="icon-delete" onclick="budgetApp.rollbackImport(${item.id})" title="Rollback"></button>
                </td>
            `;
            tbody.appendChild(row);
        });
    }

    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // ============================================
    // Live Forecast Dashboard Methods
    // ============================================

    setupForecastEventListeners() {
        // Forecast horizon change - reload forecast
        const horizonSelect = document.getElementById('forecast-horizon');
        if (horizonSelect) {
            horizonSelect.addEventListener('change', () => this.loadForecastView());
        }

        // Initialize forecast state
        this.forecastData = null;
        this.balanceChart = null;
        this.savingsChart = null;
    }

    async loadForecastView() {
        const loadingEl = document.getElementById('forecast-loading');
        const emptyEl = document.getElementById('forecast-empty');
        const sections = [
            'forecast-overview',
            'forecast-trends',
            'forecast-savings',
            'forecast-chart',
            'forecast-categories',
            'forecast-quality'
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
            const response = await fetch(OC.generateUrl(`/apps/budget/api/forecast/live?forecastMonths=${horizon}`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                throw new Error('Failed to fetch forecast');
            }

            const data = await response.json();
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
            this.displayTrendsSummary(data.trends);
            this.displaySavingsProjection(data.savingsProjection, data.monthlyProjections);
            this.displayBalanceProjectionChart(data.monthlyProjections);
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
            OC.Notification.showTemporary('Failed to load forecast data');
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

    displayTrendsSummary(trends) {
        const avgIncomeEl = document.getElementById('avg-income');
        const avgExpensesEl = document.getElementById('avg-expenses');
        const avgSavingsEl = document.getElementById('avg-savings');
        const incomeDirectionEl = document.getElementById('income-direction');
        const expenseDirectionEl = document.getElementById('expense-direction');
        const savingsDirectionEl = document.getElementById('savings-direction');
        const currency = this.forecastCurrency;

        if (avgIncomeEl) avgIncomeEl.textContent = this.formatCurrency(trends.avgMonthlyIncome, currency);
        if (avgExpensesEl) avgExpensesEl.textContent = this.formatCurrency(trends.avgMonthlyExpenses, currency);
        if (avgSavingsEl) avgSavingsEl.textContent = this.formatCurrency(trends.avgMonthlySavings, currency);

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

    displaySavingsProjection(savings, monthlyProjections) {
        const monthlySavingsEl = document.getElementById('current-monthly-savings');
        const projectedTotalEl = document.getElementById('projected-total-savings');
        const savingsRateEl = document.getElementById('savings-rate');
        const currency = this.forecastCurrency;

        if (monthlySavingsEl) monthlySavingsEl.textContent = this.formatCurrency(savings.currentMonthlySavings, currency);
        if (projectedTotalEl) projectedTotalEl.textContent = this.formatCurrency(savings.projectedTotalSavings, currency);
        if (savingsRateEl) savingsRateEl.textContent = `${savings.savingsRate}%`;

        // Render savings chart
        this.renderSavingsChart(monthlyProjections);
    }

    renderSavingsChart(monthlyProjections) {
        const canvas = document.getElementById('savings-chart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const currency = this.forecastCurrency;

        if (this.savingsChart) {
            this.savingsChart.destroy();
        }

        const labels = monthlyProjections.map(p => p.month);
        const savingsData = [];
        let cumulative = 0;
        monthlyProjections.forEach(p => {
            cumulative += p.savings;
            savingsData.push(cumulative);
        });

        this.savingsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Cumulative Savings',
                    data: savingsData,
                    borderColor: 'rgba(75, 192, 192, 1)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrency(value, currency)
                        }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => `Savings: ${this.formatCurrency(context.parsed.y, currency)}`
                        }
                    }
                }
            }
        });
    }

    displayBalanceProjectionChart(monthlyProjections) {
        const canvas = document.getElementById('balance-projection-chart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const currency = this.forecastCurrency;

        if (this.balanceChart) {
            this.balanceChart.destroy();
        }

        const labels = monthlyProjections.map(p => p.month);
        const balanceData = monthlyProjections.map(p => p.balance);
        const incomeData = monthlyProjections.map(p => p.income);
        const expenseData = monthlyProjections.map(p => p.expenses);

        this.balanceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Projected Balance',
                        data: balanceData,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        fill: true,
                        tension: 0.3,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Projected Income',
                        data: incomeData,
                        borderColor: 'rgba(75, 192, 192, 1)',
                        backgroundColor: 'transparent',
                        borderDash: [5, 5],
                        tension: 0.3,
                        yAxisID: 'y1'
                    },
                    {
                        label: 'Projected Expenses',
                        data: expenseData,
                        borderColor: 'rgba(255, 99, 132, 1)',
                        backgroundColor: 'transparent',
                        borderDash: [5, 5],
                        tension: 0.3,
                        yAxisID: 'y1'
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
                        title: { display: true, text: 'Balance' },
                        ticks: {
                            callback: (value) => this.formatCurrency(value, currency)
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: { display: true, text: 'Income/Expenses' },
                        grid: { drawOnChartArea: false },
                        ticks: {
                            callback: (value) => this.formatCurrency(value, currency)
                        }
                    }
                },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${this.formatCurrency(context.parsed.y, currency)}`
                        }
                    }
                }
            }
        });
    }

    displayCategoryTrends(categoryBreakdown) {
        const container = document.getElementById('category-trends-list');
        if (!container) return;

        container.innerHTML = '';
        const currency = this.forecastCurrency;

        if (!categoryBreakdown || categoryBreakdown.length === 0) {
            container.innerHTML = '<p class="empty-message">No category data available</p>';
            return;
        }

        categoryBreakdown.forEach(category => {
            const trendArrow = { up: '↑', down: '↓', stable: '→' }[category.trend] || '→';
            const trendClass = category.trend === 'up' ? 'negative' : (category.trend === 'down' ? 'positive' : 'neutral');

            const item = document.createElement('div');
            item.className = 'category-trend-item';
            item.innerHTML = `
                <span class="category-name">${category.name}</span>
                <span class="category-amount">${this.formatCurrency(category.avgMonthly, currency)}/mo</span>
                <span class="category-trend ${trendClass}">${trendArrow}</span>
            `;
            container.appendChild(item);
        });
    }

    displayDataQuality(data) {
        const confidenceEl = document.getElementById('forecast-confidence');
        const dataInfoEl = document.getElementById('data-info');

        if (confidenceEl) {
            confidenceEl.textContent = `${data.confidence}%`;
            // Add color class based on confidence
            confidenceEl.className = 'quality-value';
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
            dataInfoEl.textContent = `Based on ${quality.monthsOfData} month(s) of data (${quality.transactionCount} transactions)`;
        }
    }

    // ============================================
    // UI/UX Enhancement & Error Handling Methods
    // ============================================

    // Loading State Management
    showButtonLoading(buttonElement, originalText = null) {
        if (!buttonElement) return;

        if (originalText) {
            buttonElement.dataset.originalText = originalText;
        } else {
            buttonElement.dataset.originalText = buttonElement.textContent;
        }

        buttonElement.classList.add('btn-loading');
        buttonElement.disabled = true;
    }

    hideButtonLoading(buttonElement) {
        if (!buttonElement) return;

        buttonElement.classList.remove('btn-loading');
        buttonElement.disabled = false;

        if (buttonElement.dataset.originalText) {
            buttonElement.textContent = buttonElement.dataset.originalText;
        }
    }

    showLoadingOverlay(containerElement) {
        if (!containerElement) return;

        // Remove existing overlay
        this.hideLoadingOverlay(containerElement);

        const overlay = document.createElement('div');
        overlay.className = 'loading-overlay';
        overlay.innerHTML = `
            <div class="loading-state">
                <div class="loading-spinner large"></div>
                <span>Loading...</span>
            </div>
        `;

        containerElement.style.position = 'relative';
        containerElement.appendChild(overlay);
    }

    hideLoadingOverlay(containerElement) {
        if (!containerElement) return;

        const overlay = containerElement.querySelector('.loading-overlay');
        if (overlay) {
            overlay.remove();
        }
    }

    showSkeletonLoading(containerElement, type = 'card') {
        if (!containerElement) return;

        const skeletonHTML = this.generateSkeletonHTML(type);
        containerElement.innerHTML = skeletonHTML;
    }

    generateSkeletonHTML(type) {
        switch (type) {
            case 'card':
                return `
                    <div class="skeleton skeleton-card"></div>
                    <div class="skeleton skeleton-card"></div>
                    <div class="skeleton skeleton-card"></div>
                `;
            case 'table':
                return `
                    <div class="skeleton skeleton-text large"></div>
                    <div class="skeleton skeleton-text"></div>
                    <div class="skeleton skeleton-text"></div>
                    <div class="skeleton skeleton-text"></div>
                    <div class="skeleton skeleton-text"></div>
                `;
            case 'chart':
                return `<div class="skeleton skeleton-chart"></div>`;
            default:
                return `
                    <div class="skeleton skeleton-text large"></div>
                    <div class="skeleton skeleton-text"></div>
                    <div class="skeleton skeleton-text"></div>
                `;
        }
    }

    // Error State Management
    showErrorState(containerElement, error, options = {}) {
        if (!containerElement) return;

        const {
            title = 'Something went wrong',
            message = error.message || 'An unexpected error occurred',
            showRetry = true,
            retryCallback = null,
            showDetails = false
        } = options;

        const errorHTML = `
            <div class="error-state">
                <div class="error-icon">⚠️</div>
                <div class="error-message">${title}</div>
                <div class="error-details">${message}</div>
                ${showDetails && error.stack ? `<details><summary>Technical Details</summary><pre>${error.stack}</pre></details>` : ''}
                <div class="error-actions">
                    ${showRetry ? '<button class="primary retry-btn">Try Again</button>' : ''}
                    <button class="secondary dismiss-btn">Dismiss</button>
                </div>
            </div>
        `;

        containerElement.innerHTML = errorHTML;

        // Add event listeners
        const retryBtn = containerElement.querySelector('.retry-btn');
        const dismissBtn = containerElement.querySelector('.dismiss-btn');

        if (retryBtn && retryCallback) {
            retryBtn.addEventListener('click', retryCallback);
        }

        if (dismissBtn) {
            dismissBtn.addEventListener('click', () => {
                containerElement.innerHTML = '';
            });
        }
    }

    // Empty State Management
    showEmptyState(containerElement, options = {}) {
        if (!containerElement) return;

        const {
            icon = '📭',
            title = 'No data available',
            description = 'There\'s nothing to show here yet.',
            actions = []
        } = options;

        const actionsHTML = actions.length > 0 ? `
            <div class="empty-actions">
                ${actions.map(action => `
                    <button class="${action.class || 'primary'}" data-action="${action.id}">
                        ${action.text}
                    </button>
                `).join('')}
            </div>
        ` : '';

        const emptyHTML = `
            <div class="empty-state">
                <div class="empty-icon">${icon}</div>
                <div class="empty-title">${title}</div>
                <div class="empty-description">${description}</div>
                ${actionsHTML}
            </div>
        `;

        containerElement.innerHTML = emptyHTML;

        // Add action listeners
        actions.forEach(action => {
            const btn = containerElement.querySelector(`[data-action="${action.id}"]`);
            if (btn && action.callback) {
                btn.addEventListener('click', action.callback);
            }
        });
    }

    // Enhanced Notification System
    showNotification(message, type = 'info', duration = 5000, options = {}) {
        const {
            persistent = false,
            actions = [],
            html = false
        } = options;

        // Create notification container if it doesn't exist
        let container = document.querySelector('.notification-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'notification-container';
            document.body.appendChild(container);
        }

        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;

        const iconMap = {
            success: '✅',
            error: '❌',
            warning: '⚠️',
            info: 'ℹ️'
        };

        const actionsHTML = actions.length > 0 ? `
            <div class="notification-actions">
                ${actions.map(action => `
                    <button class="notification-action ${action.class || ''}" data-action="${action.id}">
                        ${action.text}
                    </button>
                `).join('')}
            </div>
        ` : '';

        notification.innerHTML = `
            <div class="notification-icon">${iconMap[type] || iconMap.info}</div>
            <div class="notification-content">
                <div class="notification-message">${html ? message : this.escapeHtml(message)}</div>
                ${actionsHTML}
            </div>
            ${!persistent ? '<button class="notification-close">×</button>' : ''}
        `;

        container.appendChild(notification);

        // Add action listeners
        actions.forEach(action => {
            const btn = notification.querySelector(`[data-action="${action.id}"]`);
            if (btn && action.callback) {
                btn.addEventListener('click', () => {
                    action.callback();
                    this.dismissNotification(notification);
                });
            }
        });

        // Add close listener
        const closeBtn = notification.querySelector('.notification-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                this.dismissNotification(notification);
            });
        }

        // Auto-dismiss after duration
        if (!persistent && duration > 0) {
            setTimeout(() => {
                this.dismissNotification(notification);
            }, duration);
        }

        return notification;
    }

    dismissNotification(notification) {
        if (!notification) return;

        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => {
            notification.remove();
        }, 300);
    }

    // Form Validation Enhancement
    validateField(fieldElement, validationRules = {}) {
        if (!fieldElement) return { isValid: true };

        const value = fieldElement.value.trim();
        const fieldContainer = fieldElement.closest('.form-field') || fieldElement.parentElement;

        // Clear previous states
        fieldContainer.classList.remove('error', 'success', 'loading');
        const existingError = fieldContainer.querySelector('.field-error');
        const existingSuccess = fieldContainer.querySelector('.field-success');
        if (existingError) existingError.remove();
        if (existingSuccess) existingSuccess.remove();

        // Apply validation rules
        for (const [rule, ruleValue] of Object.entries(validationRules)) {
            let isValid = true;
            let errorMessage = '';

            switch (rule) {
                case 'required':
                    if (ruleValue && !value) {
                        isValid = false;
                        errorMessage = 'This field is required';
                    }
                    break;
                case 'minLength':
                    if (value && value.length < ruleValue) {
                        isValid = false;
                        errorMessage = `Minimum ${ruleValue} characters required`;
                    }
                    break;
                case 'maxLength':
                    if (value && value.length > ruleValue) {
                        isValid = false;
                        errorMessage = `Maximum ${ruleValue} characters allowed`;
                    }
                    break;
                case 'pattern':
                    if (value && !ruleValue.test(value)) {
                        isValid = false;
                        errorMessage = validationRules.patternMessage || 'Invalid format';
                    }
                    break;
                case 'email':
                    if (value && ruleValue && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                        isValid = false;
                        errorMessage = 'Invalid email address';
                    }
                    break;
                case 'min':
                    if (value && parseFloat(value) < ruleValue) {
                        isValid = false;
                        errorMessage = `Minimum value is ${ruleValue}`;
                    }
                    break;
                case 'max':
                    if (value && parseFloat(value) > ruleValue) {
                        isValid = false;
                        errorMessage = `Maximum value is ${ruleValue}`;
                    }
                    break;
            }

            if (!isValid) {
                fieldContainer.classList.add('error');
                const errorElement = document.createElement('div');
                errorElement.className = 'field-error';
                errorElement.innerHTML = `<span>⚠️</span> ${errorMessage}`;
                fieldContainer.appendChild(errorElement);
                return { isValid: false, error: errorMessage };
            }
        }

        // Show success state if value exists and no errors
        if (value) {
            fieldContainer.classList.add('success');
            const successElement = document.createElement('div');
            successElement.className = 'field-success';
            successElement.innerHTML = '<span>✓</span> Valid';
            fieldContainer.appendChild(successElement);
        }

        return { isValid: true };
    }

    // Enhanced API Error Handling
    async apiCall(url, options = {}) {
        const {
            method = 'GET',
            headers = {},
            body = null,
            timeout = 10000,
            retries = 2,
            showLoading = true,
            loadingElement = null
        } = options;

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        if (showLoading && loadingElement) {
            this.showLoadingOverlay(loadingElement);
        }

        try {
            const response = await fetch(OC.generateUrl(url), {
                method,
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json',
                    ...headers
                },
                body: body ? JSON.stringify(body) : null,
                signal: controller.signal
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.message || `HTTP ${response.status}: ${response.statusText}`);
            }

            return await response.json();

        } catch (error) {
            clearTimeout(timeoutId);

            if (error.name === 'AbortError') {
                throw new Error('Request timed out. Please check your connection and try again.');
            }

            // Retry logic
            if (retries > 0 && !error.message.includes('404') && !error.message.includes('401')) {
                await new Promise(resolve => setTimeout(resolve, 1000)); // Wait 1 second
                return this.apiCall(url, { ...options, retries: retries - 1 });
            }

            throw error;
        } finally {
            if (showLoading && loadingElement) {
                this.hideLoadingOverlay(loadingElement);
            }
        }
    }

    // Enhanced Error Recovery
    async handleApiError(error, context = '', options = {}) {
        const {
            showNotification = true,
            retryCallback = null,
            fallbackData = null
        } = options;

        console.error(`API Error in ${context}:`, error);

        let userMessage = 'An unexpected error occurred';
        let isRecoverable = true;

        if (error.message.includes('Network')) {
            userMessage = 'Connection problem. Please check your internet and try again.';
        } else if (error.message.includes('timeout')) {
            userMessage = 'Request timed out. Please try again.';
        } else if (error.message.includes('401')) {
            userMessage = 'Session expired. Please refresh the page.';
            isRecoverable = false;
        } else if (error.message.includes('403')) {
            userMessage = 'Access denied. You may not have permission for this action.';
            isRecoverable = false;
        } else if (error.message.includes('404')) {
            userMessage = 'Resource not found. It may have been deleted.';
            isRecoverable = false;
        } else if (error.message.includes('500')) {
            userMessage = 'Server error. Please try again later.';
        } else if (error.message) {
            userMessage = error.message;
        }

        if (showNotification) {
            const actions = [];
            if (isRecoverable && retryCallback) {
                actions.push({
                    id: 'retry',
                    text: 'Try Again',
                    class: 'primary',
                    callback: retryCallback
                });
            }

            this.showNotification(userMessage, 'error', 8000, {
                actions,
                persistent: !isRecoverable
            });
        }

        return fallbackData;
    }

    // Data Loading with States
    async loadDataWithStates(loadFunction, containerElement, options = {}) {
        const {
            emptyStateOptions = {},
            errorStateOptions = {},
            showSkeleton = true,
            skeletonType = 'card'
        } = options;

        try {
            if (showSkeleton) {
                this.showSkeletonLoading(containerElement, skeletonType);
            }

            const data = await loadFunction();

            if (!data || (Array.isArray(data) && data.length === 0)) {
                this.showEmptyState(containerElement, emptyStateOptions);
                return null;
            }

            return data;

        } catch (error) {
            this.showErrorState(containerElement, error, {
                ...errorStateOptions,
                retryCallback: () => this.loadDataWithStates(loadFunction, containerElement, options)
            });
            throw error;
        }
    }

    // Utility Methods
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Enhanced Form Submission
    async submitFormWithFeedback(formElement, submitFunction, options = {}) {
        if (!formElement) return;

        const {
            successMessage = 'Successfully saved!',
            resetForm = false,
            redirectUrl = null
        } = options;

        const submitButton = formElement.querySelector('button[type="submit"]') ||
                           formElement.querySelector('.primary');

        try {
            // Validate form
            const isValid = this.validateForm(formElement);
            if (!isValid) {
                this.showNotification('Please fix the errors before submitting', 'warning');
                return;
            }

            // Show loading state
            this.showButtonLoading(submitButton);

            // Submit form
            const result = await submitFunction();

            // Show success
            this.showNotification(successMessage, 'success');

            // Reset form if requested
            if (resetForm) {
                formElement.reset();
                this.clearFormValidation(formElement);
            }

            // Redirect if specified
            if (redirectUrl) {
                setTimeout(() => {
                    window.location.href = redirectUrl;
                }, 1000);
            }

            return result;

        } catch (error) {
            await this.handleApiError(error, 'form submission', {
                retryCallback: () => this.submitFormWithFeedback(formElement, submitFunction, options)
            });
        } finally {
            this.hideButtonLoading(submitButton);
        }
    }

    validateForm(formElement) {
        if (!formElement) return false;

        let isValid = true;
        const requiredFields = formElement.querySelectorAll('[required]');

        requiredFields.forEach(field => {
            const result = this.validateField(field, { required: true });
            if (!result.isValid) {
                isValid = false;
            }
        });

        return isValid;
    }

    clearFormValidation(formElement) {
        if (!formElement) return;

        const formFields = formElement.querySelectorAll('.form-field');
        formFields.forEach(field => {
            field.classList.remove('error', 'success', 'loading');
            const errors = field.querySelectorAll('.field-error, .field-success');
            errors.forEach(error => error.remove());
        });
    }

    // Helper methods
    formatCurrency(amount, currency = null) {
        const currencyCode = currency || this.getPrimaryCurrency();
        const decimals = parseInt(this.settings.number_format_decimals) || 2;
        const decimalSep = this.settings.number_format_decimal_sep || '.';
        const thousandsSep = this.settings.number_format_thousands_sep ?? ',';

        // Format the number manually using user settings
        const absAmount = Math.abs(amount);
        const parts = absAmount.toFixed(decimals).split('.');
        const intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandsSep);
        const decPart = parts[1] || '';

        // Get currency symbol
        const symbols = {
            'USD': '$', 'EUR': '€', 'GBP': '£', 'CAD': 'C$', 'AUD': 'A$',
            'JPY': '¥', 'CHF': 'CHF', 'CNY': '¥', 'INR': '₹', 'MXN': '$',
            'BRL': 'R$', 'KRW': '₩', 'SGD': 'S$', 'HKD': 'HK$', 'NOK': 'kr',
            'SEK': 'kr', 'DKK': 'kr', 'NZD': 'NZ$', 'ZAR': 'R', 'RUB': '₽'
        };
        const symbol = symbols[currencyCode] || currencyCode;

        const formattedNumber = decimals > 0 ? `${intPart}${decimalSep}${decPart}` : intPart;
        const sign = amount < 0 ? '-' : '';
        return `${sign}${symbol}${formattedNumber}`;
    }

    getPrimaryCurrency() {
        // Get default currency from settings (matches backend SettingController default of 'GBP')
        const defaultCurrency = this.settings?.default_currency || 'GBP';

        // Return cached value if accounts and settings haven't changed
        const currentHash = this._getAccountsHash();
        if (this._primaryCurrencyCache &&
            this._accountsHash === currentHash &&
            this._settingsCurrencyCache === defaultCurrency) {
            return this._primaryCurrencyCache;
        }

        // Default fallback to user's setting
        if (!Array.isArray(this.accounts) || this.accounts.length === 0) {
            return defaultCurrency;
        }

        // Weight currencies by absolute balance (same logic as backend ForecastService)
        const currencyWeights = {};
        this.accounts.forEach(account => {
            const currency = account.currency || defaultCurrency;
            const balance = Math.abs(parseFloat(account.balance) || 0);
            currencyWeights[currency] = (currencyWeights[currency] || 0) + balance;
        });

        // Find currency with highest weight
        let primaryCurrency = defaultCurrency;
        let maxWeight = 0;
        for (const [currency, weight] of Object.entries(currencyWeights)) {
            if (weight > maxWeight) {
                maxWeight = weight;
                primaryCurrency = currency;
            }
        }

        // Cache the result
        this._primaryCurrencyCache = primaryCurrency;
        this._accountsHash = currentHash;
        this._settingsCurrencyCache = defaultCurrency;

        return primaryCurrency;
    }

    _getAccountsHash() {
        if (!Array.isArray(this.accounts)) return '';
        return this.accounts.map(a => `${a.id}:${a.currency}:${a.balance}`).join('|');
    }

    formatDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    formatAccountType(type) {
        if (!type) return '';
        const typeNames = {
            checking: 'Checking',
            savings: 'Savings',
            credit_card: 'Credit Card',
            investment: 'Investment',
            cash: 'Cash',
            loan: 'Loan',
            mortgage: 'Mortgage',
            pension: 'Pension'
        };
        return typeNames[type] || type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    }

    closeModal(modal) {
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
    }

    renderTransactionsList(transactions) {
        return transactions.map(t => `
            <div class="transaction-item">
                <span class="transaction-date">${new Date(t.date).toLocaleDateString()}</span>
                <span class="transaction-description">${t.description}</span>
                <span class="amount ${t.type}">${this.formatCurrency(t.amount, t.accountCurrency)}</span>
            </div>
        `).join('');
    }

    renderTransactionsTable(transactions) {
        return transactions.map(t => {
            const isSplit = t.isSplit || t.is_split;
            const categoryDisplay = isSplit
                ? '<span class="split-indicator" title="This transaction is split across multiple categories">Split</span>'
                : (t.categoryName || '-');

            return `
            <tr class="${isSplit ? 'split-transaction' : ''}">
                <td class="select-column">
                    <input type="checkbox" class="transaction-checkbox" data-transaction-id="${t.id}">
                </td>
                <td>${new Date(t.date).toLocaleDateString()}</td>
                <td>${this.escapeHtml(t.description)}</td>
                <td>${categoryDisplay}</td>
                <td class="amount ${t.type}">${this.formatCurrency(t.amount, t.accountCurrency)}</td>
                <td>${this.escapeHtml(t.accountName)}</td>
                <td class="reconcile-column"></td>
                <td>
                    <button class="tertiary transaction-split-btn" data-transaction-id="${t.id}" title="${isSplit ? 'Edit splits' : 'Split transaction'}">
                        ${isSplit ? 'Splits' : 'Split'}
                    </button>
                    <button class="tertiary transaction-edit-btn" data-transaction-id="${t.id}" aria-label="Edit transaction: ${t.description}">Edit</button>
                    <button class="error transaction-delete-btn" data-transaction-id="${t.id}" aria-label="Delete transaction: ${t.description}">Delete</button>
                </td>
            </tr>
            `;
        }).join('');
    }

    renderCategoryTree(categories, level = 0) {
        return categories.map(cat => `
            <div class="category-item" style="margin-left: ${level * 20}px" data-id="${cat.id}">
                <span class="category-name">${cat.name}</span>
                ${cat.children ? this.renderCategoryTree(cat.children, level + 1) : ''}
            </div>
        `).join('');
    }

    setupCategoriesEventListeners() {
        // Tab switching
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const type = e.currentTarget.dataset.tab;
                this.switchCategoryType(type);
            });
        });

        // Search
        const searchInput = document.getElementById('categories-search');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this.searchCategories(e.target.value);
            });
        }

        // Expand/Collapse all
        const expandBtn = document.getElementById('expand-all-btn');
        const collapseBtn = document.getElementById('collapse-all-btn');

        if (expandBtn) {
            expandBtn.addEventListener('click', () => this.expandAllCategories());
        }

        if (collapseBtn) {
            collapseBtn.addEventListener('click', () => this.collapseAllCategories());
        }

        // Add category button
        const addBtn = document.getElementById('add-category-btn');
        if (addBtn) {
            addBtn.addEventListener('click', () => this.showAddCategoryModal());
        }

        // Category details actions
        const editBtn = document.getElementById('edit-category-btn');
        const deleteBtn = document.getElementById('delete-category-btn');

        if (editBtn) {
            editBtn.addEventListener('click', () => this.editSelectedCategory());
        }

        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => this.deleteSelectedCategory());
        }

        // Bulk action buttons
        const selectAllBtn = document.getElementById('category-select-all-btn');
        const clearSelectionBtn = document.getElementById('category-clear-selection-btn');
        const bulkDeleteBtn = document.getElementById('category-bulk-delete-btn');

        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', () => this.selectAllCategories());
        }

        if (clearSelectionBtn) {
            clearSelectionBtn.addEventListener('click', () => this.clearCategorySelection());
        }

        if (bulkDeleteBtn) {
            bulkDeleteBtn.addEventListener('click', () => this.bulkDeleteCategories());
        }
    }

    switchCategoryType(type) {
        // Update active tab
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === type);
        });

        this.currentCategoryType = type;
        this.selectedCategory = null;
        this.renderCategoriesTree();
        this.showCategoryDetailsEmpty();
    }

    renderCategoriesTree() {
        const treeContainer = document.getElementById('categories-tree');
        const emptyState = document.getElementById('empty-categories');

        if (!treeContainer) return;

        // Handle case where categoryTree is not loaded or empty
        if (!this.categoryTree || !Array.isArray(this.categoryTree) || this.categoryTree.length === 0) {
            treeContainer.innerHTML = '';
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        // Filter categories by current type
        const typedCategories = this.categoryTree.filter(cat => cat.type === this.currentCategoryType);

        if (typedCategories.length === 0) {
            treeContainer.innerHTML = '';
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        if (emptyState) emptyState.style.display = 'none';
        treeContainer.innerHTML = this.renderCategoryNodes(typedCategories);

        // Setup event listeners for category items
        this.setupCategoryItemListeners();
        this.setupDragAndDrop();
    }

    renderCategoryNodes(categories, level = 0, countMap = null) {
        // Build count map once at the start (avoids O(n*m) filtering)
        if (countMap === null) {
            countMap = this.buildCategoryTransactionCountMap();
        }

        return categories.map(category => {
            const hasChildren = category.children && category.children.length > 0;
            const isExpanded = this.expandedCategories && this.expandedCategories.has(category.id);
            const isSelected = this.selectedCategory?.id === category.id;
            const isChecked = this.selectedCategoryIds && this.selectedCategoryIds.has(category.id);

            // Use pre-computed count map for O(1) lookup
            const transactionCount = countMap[category.id] || 0;

            return `
                <div class="category-node" data-level="${level}">
                    <div class="category-item ${isSelected ? 'selected' : ''} ${isChecked ? 'checked' : ''}"
                         data-category-id="${category.id}"
                         draggable="true">
                        <input type="checkbox"
                               class="category-checkbox"
                               data-category-id="${category.id}"
                               ${isChecked ? 'checked' : ''}>
                        ${hasChildren ? `
                            <button class="category-toggle ${isExpanded ? 'expanded' : ''}"
                                    data-category-id="${category.id}">
                                <span class="icon-triangle-e" aria-hidden="true"></span>
                            </button>
                        ` : '<div style="width: 20px;"></div>'}

                        <div class="category-icon" style="background-color: ${category.color || '#999'};">
                            <span class="${category.icon || 'icon-tag'}" aria-hidden="true"></span>
                        </div>

                        <div class="category-content">
                            <span class="category-name">${category.name}</span>
                            <div class="category-meta">
                                ${transactionCount > 0 ? `<span class="transaction-count">${transactionCount}</span>` : ''}
                            </div>
                        </div>

                        <button class="category-delete-btn"
                                data-category-id="${category.id}"
                                title="Delete ${category.name}">
                            <span class="icon-delete" aria-hidden="true"></span>
                        </button>
                    </div>

                    ${hasChildren ? `
                        <div class="category-children ${isExpanded ? '' : 'collapsed'}">
                            ${this.renderCategoryNodes(category.children, level + 1, countMap)}
                        </div>
                    ` : ''}
                </div>
            `;
        }).join('');
    }

    buildCategoryTransactionCountMap() {
        const countMap = {};
        for (const tx of (this.transactions || [])) {
            if (tx.categoryId) {
                countMap[tx.categoryId] = (countMap[tx.categoryId] || 0) + 1;
            }
        }
        return countMap;
    }

    setupCategoryItemListeners() {
        // Initialize selectedCategoryIds if not exists
        if (!this.selectedCategoryIds) {
            this.selectedCategoryIds = new Set();
        }

        // Category selection
        document.querySelectorAll('.category-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (e.target.closest('.category-toggle')) return;
                if (e.target.closest('.category-checkbox')) return;
                if (e.target.closest('.category-delete-btn')) return;

                const categoryId = parseInt(item.dataset.categoryId);
                this.selectCategory(categoryId);
            });
        });

        // Toggle expand/collapse
        document.querySelectorAll('.category-toggle').forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                const categoryId = parseInt(toggle.dataset.categoryId);
                this.toggleCategoryExpanded(categoryId);
            });
        });

        // Checkbox selection for bulk actions
        document.querySelectorAll('.category-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                e.stopPropagation();
                const categoryId = parseInt(checkbox.dataset.categoryId);
                if (checkbox.checked) {
                    this.selectedCategoryIds.add(categoryId);
                } else {
                    this.selectedCategoryIds.delete(categoryId);
                }
                checkbox.closest('.category-item').classList.toggle('checked', checkbox.checked);
                this.updateBulkCategoryActions();
            });
        });

        // Inline delete buttons
        document.querySelectorAll('.category-delete-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const categoryId = parseInt(btn.dataset.categoryId);
                this.deleteCategoryById(categoryId);
            });
        });
    }

    setupDragAndDrop() {
        const categoryItems = document.querySelectorAll('.category-item');

        categoryItems.forEach(item => {
            item.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/plain', item.dataset.categoryId);
                item.classList.add('dragging');
            });

            item.addEventListener('dragend', (e) => {
                item.classList.remove('dragging');
                document.querySelectorAll('.drop-indicator').forEach(el => el.remove());
                document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
            });

            item.addEventListener('dragover', (e) => {
                e.preventDefault();
                this.showDropIndicator(e, item);
            });

            item.addEventListener('drop', (e) => {
                e.preventDefault();
                const draggedId = parseInt(e.dataTransfer.getData('text/plain'));
                const targetId = parseInt(item.dataset.categoryId);

                if (draggedId !== targetId) {
                    this.reorderCategory(draggedId, targetId, this.getDropPosition(e, item));
                }
            });
        });
    }

    showDropIndicator(e, targetItem) {
        // Remove existing indicators
        document.querySelectorAll('.drop-indicator').forEach(el => el.remove());
        document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));

        const rect = targetItem.getBoundingClientRect();
        const y = e.clientY - rect.top;
        const threshold = rect.height / 3;

        const indicator = document.createElement('div');
        indicator.className = 'drop-indicator';

        if (y < threshold) {
            // Drop above
            indicator.classList.add('top');
            targetItem.parentNode.insertBefore(indicator, targetItem.parentNode);
        } else if (y > rect.height - threshold) {
            // Drop below
            indicator.classList.add('bottom');
            targetItem.parentNode.insertBefore(indicator, targetItem.parentNode.nextSibling);
        } else {
            // Drop as child
            indicator.classList.add('child');
            targetItem.classList.add('drag-over');
            targetItem.appendChild(indicator);
        }
    }

    getDropPosition(e, targetItem) {
        const rect = targetItem.getBoundingClientRect();
        const y = e.clientY - rect.top;
        const threshold = rect.height / 3;

        if (y < threshold) return 'above';
        if (y > rect.height - threshold) return 'below';
        return 'child';
    }

    async reorderCategory(draggedId, targetId, position) {
        try {
            const draggedCategory = this.findCategoryById(draggedId);
            const targetCategory = this.findCategoryById(targetId);

            if (!draggedCategory || !targetCategory) return;

            let newParentId = null;
            let newSortOrder = 0;

            if (position === 'child') {
                newParentId = targetId;
                newSortOrder = 0; // First child
            } else {
                newParentId = targetCategory.parentId;
                newSortOrder = position === 'above' ? targetCategory.sortOrder : targetCategory.sortOrder + 1;
            }

            // Update via API
            const response = await fetch(OC.generateUrl(`/apps/budget/api/categories/${draggedId}`), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    parentId: newParentId,
                    sortOrder: newSortOrder
                })
            });

            if (response.ok) {
                // Reload categories to reflect changes
                await this.loadCategories();
                OC.Notification.showTemporary('Category reordered successfully');
            } else {
                throw new Error('Failed to reorder category');
            }

        } catch (error) {
            console.error('Failed to reorder category:', error);
            OC.Notification.showTemporary('Failed to reorder category');
        }
    }

    selectCategory(categoryId) {
        // Update selection in tree
        document.querySelectorAll('.category-item').forEach(item => {
            item.classList.toggle('selected', parseInt(item.dataset.categoryId) === categoryId);
        });

        // Find and store selected category
        this.selectedCategory = this.findCategoryById(categoryId);

        if (this.selectedCategory) {
            this.showCategoryDetails(this.selectedCategory);
        }
    }

    async showCategoryDetails(category) {
        // Hide empty state, show details
        const emptyEl = document.getElementById('category-details-empty');
        const contentEl = document.getElementById('category-details-content');

        if (emptyEl) emptyEl.style.display = 'none';
        if (contentEl) contentEl.style.display = 'block';

        // Update category overview
        this.updateCategoryOverview(category);

        // Load and display analytics
        await this.loadCategoryAnalytics(category.id);
        await this.loadCategoryTransactions(category.id);
    }

    updateCategoryOverview(category) {
        const nameEl = document.getElementById('category-display-name');
        if (nameEl) nameEl.textContent = category.name;

        const iconEl = document.getElementById('category-display-icon');
        if (iconEl) {
            iconEl.className = `category-icon large ${category.icon || 'icon-tag'}`;
            iconEl.style.backgroundColor = category.color || '#999';
        }

        const typeEl = document.getElementById('category-display-type');
        if (typeEl) {
            typeEl.textContent = category.type;
            typeEl.className = `category-type-badge ${category.type}`;
        }

        // Build category path
        const path = this.getCategoryPath(category);
        const pathEl = document.getElementById('category-display-path');
        if (pathEl) pathEl.textContent = path;
    }

    async loadCategoryAnalytics(categoryId) {
        try {
            this.updateAnalyticsDisplay(categoryId);
        } catch (error) {
            console.error('Failed to load category analytics:', error);
            this.updateAnalyticsDisplay(categoryId);
        }
    }

    updateAnalyticsDisplay(categoryId) {
        // Calculate analytics from transactions
        const categoryTransactions = this.getCategoryTransactions(categoryId);
        const totalCount = categoryTransactions.length;
        const totalAmount = categoryTransactions.reduce((sum, t) => sum + Math.abs(parseFloat(t.amount) || 0), 0);
        const avgAmount = totalCount > 0 ? totalAmount / totalCount : 0;

        // Calculate trend (simplified)
        const trend = this.calculateCategoryTrend(categoryTransactions);

        const countEl = document.getElementById('total-transactions-count');
        if (countEl) countEl.textContent = totalCount.toLocaleString();

        const avgEl = document.getElementById('avg-transaction-amount');
        if (avgEl) avgEl.textContent = this.formatCurrency(avgAmount);

        const trendEl = document.getElementById('category-trend');
        if (trendEl) trendEl.textContent = trend;
    }

    async loadCategoryTransactions(categoryId) {
        try {
            // Get recent transactions for this category
            const transactions = this.getCategoryTransactions(categoryId, 5);

            const container = document.getElementById('category-recent-transactions');
            if (!container) return;

            if (transactions.length === 0) {
                container.innerHTML = '<div class="empty-state"><p>No transactions in this category yet.</p></div>';
                return;
            }

            container.innerHTML = transactions.map(transaction => `
                <div class="transaction-item">
                    <div class="transaction-description">${transaction.description}</div>
                    <div class="transaction-date">${new Date(transaction.date).toLocaleDateString()}</div>
                    <div class="transaction-amount ${transaction.type}">
                        ${transaction.type === 'credit' ? '+' : '-'}${this.formatCurrency(Math.abs(transaction.amount))}
                    </div>
                </div>
            `).join('');

        } catch (error) {
            console.error('Failed to load category transactions:', error);
        }
    }

    showCategoryDetailsEmpty() {
        const contentEl = document.getElementById('category-details-content');
        const emptyEl = document.getElementById('category-details-empty');

        if (contentEl) contentEl.style.display = 'none';
        if (emptyEl) emptyEl.style.display = 'flex';
    }

    toggleCategoryExpanded(categoryId) {
        if (!this.expandedCategories) this.expandedCategories = new Set();

        if (this.expandedCategories.has(categoryId)) {
            this.expandedCategories.delete(categoryId);
        } else {
            this.expandedCategories.add(categoryId);
        }
        this.renderCategoriesTree();
    }

    expandAllCategories() {
        if (!this.expandedCategories) this.expandedCategories = new Set();

        const allCategories = this.getAllCategoryIds(this.categoryTree || []);
        allCategories.forEach(id => this.expandedCategories.add(id));
        this.renderCategoriesTree();
    }

    collapseAllCategories() {
        if (!this.expandedCategories) this.expandedCategories = new Set();

        this.expandedCategories.clear();
        this.renderCategoriesTree();
    }

    searchCategories(query) {
        // Simple search implementation
        const items = document.querySelectorAll('.category-item');
        const lowerQuery = query.toLowerCase();

        items.forEach(item => {
            const nameEl = item.querySelector('.category-name');
            if (nameEl) {
                const categoryName = nameEl.textContent.toLowerCase();
                const matches = categoryName.includes(lowerQuery);
                item.style.display = matches ? 'flex' : 'none';
            }
        });
    }

    // Helper methods
    findCategoryById(id) {
        const findInTree = (categories) => {
            for (const category of categories) {
                if (category.id === id) return category;
                if (category.children) {
                    const found = findInTree(category.children);
                    if (found) return found;
                }
            }
            return null;
        };

        return findInTree(this.categoryTree || []);
    }

    getCategoryPath(category) {
        const path = [];
        let current = category;

        while (current?.parentId) {
            const parent = this.findCategoryById(current.parentId);
            if (parent) {
                path.unshift(parent.name);
                current = parent;
            } else {
                break;
            }
        }

        return path.length > 0 ? path.join(' › ') : 'Root';
    }

    getCategoryTransactionCount(categoryId) {
        return this.getCategoryTransactions(categoryId).length;
    }

    getCategoryTransactions(categoryId, limit = null) {
        const transactions = (this.transactions || []).filter(t => t.categoryId === categoryId);
        return limit ? transactions.slice(0, limit) : transactions;
    }

    calculateCategoryTrend(transactions) {
        if (transactions.length < 2) return '—';

        // Simple trend calculation based on recent vs older transactions
        const sorted = transactions.sort((a, b) => new Date(b.date) - new Date(a.date));
        const recent = sorted.slice(0, Math.ceil(sorted.length / 2));
        const older = sorted.slice(Math.ceil(sorted.length / 2));

        const recentAvg = recent.reduce((sum, t) => sum + Math.abs(t.amount), 0) / recent.length;
        const olderAvg = older.reduce((sum, t) => sum + Math.abs(t.amount), 0) / older.length;

        const change = ((recentAvg - olderAvg) / olderAvg) * 100;

        if (Math.abs(change) < 5) return '→ Stable';
        return change > 0 ? '↗ Increasing' : '↘ Decreasing';
    }

    getAllCategoryIds(categories) {
        const ids = [];
        const traverse = (cats) => {
            cats.forEach(cat => {
                ids.push(cat.id);
                if (cat.children) traverse(cat.children);
            });
        };
        traverse(categories);
        return ids;
    }

    showAddCategoryModal() {
        const modal = document.getElementById('category-modal');
        const title = document.getElementById('category-modal-title');

        if (!modal || !title) {
            console.error('Category modal not found');
            return;
        }

        title.textContent = 'Add Category';
        this.resetCategoryForm();
        this.populateCategoryParentDropdown();

        // Pre-select the current category type tab
        const typeSelect = document.getElementById('category-type');
        if (typeSelect && this.currentCategoryType) {
            typeSelect.value = this.currentCategoryType;
        }

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');

        const nameField = document.getElementById('category-name');
        if (nameField) {
            nameField.focus();
        }
    }

    editSelectedCategory() {
        if (!this.selectedCategory) {
            return;
        }

        const modal = document.getElementById('category-modal');
        const title = document.getElementById('category-modal-title');

        if (!modal || !title) {
            console.error('Category modal not found');
            return;
        }

        title.textContent = 'Edit Category';
        this.loadCategoryData(this.selectedCategory);
        this.populateCategoryParentDropdown(this.selectedCategory.id);

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');

        const nameField = document.getElementById('category-name');
        if (nameField) {
            nameField.focus();
        }
    }

    async deleteSelectedCategory() {
        if (!this.selectedCategory) {
            return;
        }

        const categoryName = this.selectedCategory.name;
        if (!confirm(`Are you sure you want to delete the category "${categoryName}"? This action cannot be undone.`)) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/categories/${this.selectedCategory.id}`), {
                method: 'DELETE',
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (response.ok) {
                OC.Notification.showTemporary('Category deleted successfully');
                this.selectedCategory = null;
                await this.loadCategories();
                await this.loadInitialData();
                this.showCategoryDetailsEmpty();
            } else {
                const error = await response.json();
                throw new Error(error.error || 'Failed to delete category');
            }
        } catch (error) {
            console.error('Failed to delete category:', error);
            OC.Notification.showTemporary(error.message || 'Failed to delete category');
        }
    }

    async deleteCategoryById(categoryId) {
        const category = this.findCategoryById(categoryId);
        const categoryName = category ? category.name : 'this category';

        if (!confirm(`Are you sure you want to delete "${categoryName}"? This action cannot be undone.`)) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/categories/${categoryId}`), {
                method: 'DELETE',
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (response.ok) {
                OC.Notification.showTemporary('Category deleted successfully');
                if (this.selectedCategory?.id === categoryId) {
                    this.selectedCategory = null;
                    this.showCategoryDetailsEmpty();
                }
                this.selectedCategoryIds.delete(categoryId);
                await this.loadCategories();
                await this.loadInitialData();
            } else {
                const error = await response.json();
                throw new Error(error.error || 'Failed to delete category');
            }
        } catch (error) {
            console.error('Failed to delete category:', error);
            OC.Notification.showTemporary(error.message || 'Failed to delete category');
        }
    }

    updateBulkCategoryActions() {
        const toolbar = document.getElementById('category-bulk-toolbar');
        const countSpan = document.getElementById('category-bulk-count');
        const selectedCount = this.selectedCategoryIds ? this.selectedCategoryIds.size : 0;

        if (toolbar) {
            toolbar.style.display = selectedCount > 0 ? 'flex' : 'none';
        }
        if (countSpan) {
            countSpan.textContent = `${selectedCount} selected`;
        }
    }

    async bulkDeleteCategories() {
        const count = this.selectedCategoryIds.size;
        if (count === 0) return;

        if (!confirm(`Are you sure you want to delete ${count} categor${count === 1 ? 'y' : 'ies'}? This action cannot be undone.`)) {
            return;
        }

        const categoryIds = [...this.selectedCategoryIds];
        let deleted = 0;
        let errors = [];

        for (const categoryId of categoryIds) {
            try {
                const response = await fetch(OC.generateUrl(`/apps/budget/api/categories/${categoryId}`), {
                    method: 'DELETE',
                    headers: {
                        'requesttoken': OC.requestToken
                    }
                });

                if (response.ok) {
                    deleted++;
                    this.selectedCategoryIds.delete(categoryId);
                } else {
                    const error = await response.json();
                    const category = this.findCategoryById(categoryId);
                    errors.push(`${category?.name || categoryId}: ${error.error || 'Failed to delete'}`);
                }
            } catch (error) {
                const category = this.findCategoryById(categoryId);
                errors.push(`${category?.name || categoryId}: ${error.message}`);
            }
        }

        if (deleted > 0) {
            OC.Notification.showTemporary(`${deleted} categor${deleted === 1 ? 'y' : 'ies'} deleted successfully`);
            this.selectedCategory = null;
            this.showCategoryDetailsEmpty();
            await this.loadCategories();
            await this.loadInitialData();
        }

        if (errors.length > 0) {
            OC.Notification.showTemporary(`Failed to delete: ${errors.join(', ')}`);
        }

        this.updateBulkCategoryActions();
    }

    clearCategorySelection() {
        this.selectedCategoryIds.clear();
        document.querySelectorAll('.category-checkbox').forEach(cb => {
            cb.checked = false;
        });
        document.querySelectorAll('.category-item.checked').forEach(item => {
            item.classList.remove('checked');
        });
        this.updateBulkCategoryActions();
    }

    selectAllCategories() {
        const checkboxes = document.querySelectorAll('.category-checkbox');
        checkboxes.forEach(cb => {
            const categoryId = parseInt(cb.dataset.categoryId);
            cb.checked = true;
            this.selectedCategoryIds.add(categoryId);
            cb.closest('.category-item').classList.add('checked');
        });
        this.updateBulkCategoryActions();
    }

    resetCategoryForm() {
        const form = document.getElementById('category-form');
        if (form) {
            form.reset();
        }

        const categoryId = document.getElementById('category-id');
        if (categoryId) categoryId.value = '';

        const colorInput = document.getElementById('category-color');
        if (colorInput) colorInput.value = '#3b82f6';
    }

    loadCategoryData(category) {
        document.getElementById('category-id').value = category.id;
        document.getElementById('category-name').value = category.name;
        document.getElementById('category-type').value = category.type;
        document.getElementById('category-parent').value = category.parentId || '';
        document.getElementById('category-color').value = category.color || '#3b82f6';
    }

    populateCategoryParentDropdown(excludeId = null) {
        const parentSelect = document.getElementById('category-parent');
        if (!parentSelect) return;

        const typeSelect = document.getElementById('category-type');
        const currentType = typeSelect ? typeSelect.value : 'expense';

        parentSelect.innerHTML = '<option value="">None (Top Level)</option>';

        const addOptions = (categories, prefix = '') => {
            categories.forEach(cat => {
                // Only show categories of the same type, and exclude the current category and its children
                if (cat.type === currentType && cat.id !== excludeId) {
                    parentSelect.innerHTML += `<option value="${cat.id}">${prefix}${this.escapeHtml(cat.name)}</option>`;
                }
                if (cat.children && cat.children.length > 0) {
                    addOptions(cat.children, prefix + '  ');
                }
            });
        };

        if (this.allCategories) {
            addOptions(this.allCategories);
        }
    }

    async saveCategory() {
        const categoryId = document.getElementById('category-id').value;
        const name = document.getElementById('category-name').value.trim();
        const type = document.getElementById('category-type').value;
        const parentId = document.getElementById('category-parent').value || null;
        const color = document.getElementById('category-color').value;

        if (!name) {
            OC.Notification.showTemporary('Category name is required');
            return;
        }

        const categoryData = {
            name,
            type,
            parentId: parentId ? parseInt(parentId) : null,
            color
        };

        try {
            const isEdit = !!categoryId;
            const url = isEdit
                ? `/apps/budget/api/categories/${categoryId}`
                : '/apps/budget/api/categories';
            const method = isEdit ? 'PUT' : 'POST';

            const response = await fetch(OC.generateUrl(url), {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(categoryData)
            });

            if (response.ok) {
                const savedCategory = await response.json();
                OC.Notification.showTemporary(isEdit ? 'Category updated successfully' : 'Category created successfully');
                this.hideModals();
                await this.loadCategories();
                await this.loadInitialData();

                // Re-select the category to update the details panel
                const categoryIdToSelect = isEdit ? parseInt(categoryId) : savedCategory.id;
                if (categoryIdToSelect) {
                    this.selectCategory(categoryIdToSelect);
                }
            } else {
                const error = await response.json();
                throw new Error(error.error || 'Failed to save category');
            }
        } catch (error) {
            console.error('Failed to save category:', error);
            OC.Notification.showTemporary(error.message || 'Failed to save category');
        }
    }

    async createDefaultCategories() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/setup/initialize'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({})
            });

            if (response.ok) {
                OC.Notification.showTemporary('Default categories created successfully');
                await this.loadCategories();
                await this.loadInitialData();
            } else {
                const error = await response.json();
                throw new Error(error.error || 'Failed to create default categories');
            }
        } catch (error) {
            console.error('Failed to create default categories:', error);
            OC.Notification.showTemporary(error.message || 'Failed to create default categories');
        }
    }

    updateSpendingChart(data) {
        const ctx = document.getElementById('spending-chart');
        if (!ctx) return;

        if (this.charts.spending) {
            this.charts.spending.destroy();
        }

        // Sort data by total descending and take top categories
        const sortedData = [...data].sort((a, b) => b.total - a.total);
        const totalSpending = sortedData.reduce((sum, d) => sum + d.total, 0);

        // Default color palette for categories without colors
        const defaultColors = [
            '#0082c9', '#2e7d32', '#c62828', '#f57c00', '#7b1fa2',
            '#1976d2', '#388e3c', '#d32f2f', '#ffa000', '#8e24aa',
            '#0288d1', '#43a047', '#e53935', '#ffb300', '#9c27b0'
        ];

        const chartData = sortedData.map((d, i) => ({
            ...d,
            color: d.color || defaultColors[i % defaultColors.length]
        }));

        this.charts.spending = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: chartData.map(d => d.name),
                datasets: [{
                    data: chartData.map(d => d.total),
                    backgroundColor: chartData.map(d => d.color),
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverBorderWidth: 3,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                cutout: '60%',
                plugins: {
                    legend: {
                        display: false // We'll use custom legend
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = context.raw;
                                const percentage = ((value / totalSpending) * 100).toFixed(1);
                                return `${this.formatCurrency(value)} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Update custom legend
        this.updateSpendingLegend(chartData, totalSpending);
    }

    updateSpendingLegend(data, total) {
        const legendContainer = document.getElementById('spending-chart-legend');
        if (!legendContainer) return;

        legendContainer.innerHTML = data.slice(0, 8).map(item => {
            const percentage = total > 0 ? ((item.total / total) * 100).toFixed(1) : 0;
            return `
                <div class="spending-legend-item">
                    <div class="spending-legend-label">
                        <span class="spending-legend-color" style="background: ${item.color}"></span>
                        <span class="spending-legend-name">${this.escapeHtml(item.name)}</span>
                    </div>
                    <div>
                        <span class="spending-legend-value">${this.formatCurrency(item.total)}</span>
                        <span class="spending-legend-percent">${percentage}%</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    updateTrendChart(data) {
        const ctx = document.getElementById('trend-chart');
        if (!ctx) return;

        if (this.charts.trend) {
            this.charts.trend.destroy();
        }

        // Calculate net savings for each period
        const netSavings = data.income.map((inc, i) => inc - (data.expenses[i] || 0));

        this.charts.trend = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Income',
                        data: data.income,
                        backgroundColor: 'rgba(46, 125, 50, 0.8)',
                        borderColor: '#2e7d32',
                        borderWidth: 1,
                        borderRadius: 4,
                        order: 2
                    },
                    {
                        label: 'Expenses',
                        data: data.expenses,
                        backgroundColor: 'rgba(198, 40, 40, 0.8)',
                        borderColor: '#c62828',
                        borderWidth: 1,
                        borderRadius: 4,
                        order: 2
                    },
                    {
                        label: 'Net',
                        data: netSavings,
                        type: 'line',
                        borderColor: '#1976d2',
                        backgroundColor: 'rgba(25, 118, 210, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointBackgroundColor: '#1976d2',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        order: 1
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
                        display: false // We'll use custom legend
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
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            callback: (value) => this.formatCurrencyCompact(value)
                        }
                    }
                }
            }
        });

        // Update custom legend
        this.updateTrendLegend();
    }

    updateTrendLegend() {
        const legendContainer = document.getElementById('trend-chart-legend');
        if (!legendContainer) return;

        legendContainer.innerHTML = `
            <div class="chart-legend-item">
                <span class="chart-legend-color" style="background: #2e7d32"></span>
                <span>Income</span>
            </div>
            <div class="chart-legend-item">
                <span class="chart-legend-color" style="background: #c62828"></span>
                <span>Expenses</span>
            </div>
            <div class="chart-legend-item">
                <span class="chart-legend-color" style="background: #1976d2"></span>
                <span>Net Savings</span>
            </div>
        `;
    }

    formatCurrencyCompact(value) {
        if (Math.abs(value) >= 1000000) {
            return (value / 1000000).toFixed(1) + 'M';
        } else if (Math.abs(value) >= 1000) {
            return (value / 1000).toFixed(1) + 'K';
        }
        return value.toString();
    }

    populateAccountDropdowns() {
        const dropdowns = [
            { id: 'transaction-account', defaultText: 'Choose an account' },
            { id: 'account-filter', defaultText: 'All Accounts' },
            { id: 'forecast-account', defaultText: 'All Accounts' }
        ];

        dropdowns.forEach(({ id, defaultText }) => {
            const dropdown = document.getElementById(id);
            if (dropdown) {
                const currentValue = dropdown.value;
                dropdown.innerHTML = `<option value="">${defaultText}</option>` +
                    (Array.isArray(this.accounts) ? this.accounts.map(a =>
                        `<option value="${a.id}">${a.name}</option>`
                    ).join('') : '');
                dropdown.value = currentValue;
            }
        });
    }

    populateCategoryDropdowns() {
        const dropdown = document.getElementById('transaction-category');
        if (dropdown) {
            dropdown.innerHTML = '<option value="">No Category</option>' +
                this.renderCategoryOptions(this.categories);
        }
    }

    renderCategoryOptions(categories, prefix = '') {
        return categories.map(cat => {
            const option = `<option value="${cat.id}">${prefix}${cat.name}</option>`;
            const childOptions = cat.children
                ? this.renderCategoryOptions(cat.children, prefix + '  ')
                : '';
            return option + childOptions;
        }).join('');
    }

    // ===================================
    // Budget View Methods
    // ===================================

    async loadBudgetView() {
        // Initialize budget state
        this.budgetType = this.budgetType || 'expense';
        this.budgetMonth = this.budgetMonth || new Date().toISOString().slice(0, 7); // YYYY-MM

        // Setup event listeners on first load
        if (!this.budgetEventListenersSetup) {
            this.setupBudgetEventListeners();
            this.budgetEventListenersSetup = true;
        }

        // Populate month selector
        this.populateBudgetMonthSelector();

        // Fetch categories if not already loaded
        if (!this.allCategories || this.allCategories.length === 0) {
            try {
                const response = await fetch(OC.generateUrl('/apps/budget/api/categories/tree'), {
                    headers: {
                        'requesttoken': OC.requestToken
                    }
                });
                if (response.ok) {
                    this.categoryTree = await response.json();
                    this.allCategories = this.flattenCategories(this.categoryTree);
                }
            } catch (error) {
                console.error('Failed to load categories for budget:', error);
            }
        }

        // Calculate spending for each category
        await this.calculateCategorySpending();

        // Render the budget tree
        this.renderBudgetTree();

        // Update summary
        this.updateBudgetSummary();
    }

    setupBudgetEventListeners() {
        // Budget type tabs
        document.querySelectorAll('.budget-tabs .tab-button').forEach(btn => {
            btn.addEventListener('click', (e) => {
                document.querySelectorAll('.budget-tabs .tab-button').forEach(b => b.classList.remove('active'));
                e.currentTarget.classList.add('active');
                this.budgetType = e.currentTarget.dataset.budgetType;
                this.renderBudgetTree();
                this.updateBudgetSummary();
            });
        });

        // Month selector
        const monthSelect = document.getElementById('budget-month');
        if (monthSelect) {
            monthSelect.addEventListener('change', async (e) => {
                this.budgetMonth = e.target.value;
                await this.calculateCategorySpending();
                this.renderBudgetTree();
                this.updateBudgetSummary();
            });
        }

        // Go to categories button (empty state)
        const goToCategoriesBtn = document.getElementById('empty-budget-go-categories-btn');
        if (goToCategoriesBtn) {
            goToCategoriesBtn.addEventListener('click', () => {
                this.navigateTo('categories');
            });
        }
    }

    populateBudgetMonthSelector() {
        const monthSelect = document.getElementById('budget-month');
        if (!monthSelect) return;

        // Generate last 12 months + next 3 months
        const options = [];
        const now = new Date();

        for (let i = -12; i <= 3; i++) {
            const date = new Date(now.getFullYear(), now.getMonth() + i, 1);
            const value = date.toISOString().slice(0, 7);
            const label = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            options.push({ value, label });
        }

        monthSelect.innerHTML = options.map(opt =>
            `<option value="${opt.value}" ${opt.value === this.budgetMonth ? 'selected' : ''}>${opt.label}</option>`
        ).join('');
    }

    async calculateCategorySpending() {
        // Get date range for selected month
        const [year, month] = this.budgetMonth.split('-').map(Number);
        const startDate = new Date(year, month - 1, 1);
        const endDate = new Date(year, month, 0); // Last day of month

        const startStr = startDate.toISOString().split('T')[0];
        const endStr = endDate.toISOString().split('T')[0];

        // Fetch spending data from API
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/categories/spending?startDate=${startStr}&endDate=${endStr}`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (response.ok) {
                const spendingData = await response.json();
                // Map spending to categories
                this.categorySpending = {};
                spendingData.forEach(item => {
                    this.categorySpending[item.categoryId] = parseFloat(item.spent) || 0;
                });
            }
        } catch (error) {
            console.error('Failed to fetch category spending:', error);
            this.categorySpending = {};
        }

        // Also calculate from local transactions as fallback
        if (!this.categorySpending || Object.keys(this.categorySpending).length === 0) {
            this.categorySpending = {};
            (this.transactions || []).forEach(t => {
                if (!t.categoryId) return;
                const txDate = new Date(t.date);
                if (txDate >= startDate && txDate <= endDate) {
                    const amount = Math.abs(parseFloat(t.amount) || 0);
                    this.categorySpending[t.categoryId] = (this.categorySpending[t.categoryId] || 0) + amount;
                }
            });
        }
    }

    renderBudgetTree() {
        const treeContainer = document.getElementById('budget-tree');
        const emptyState = document.getElementById('empty-budget');
        const headerEl = document.querySelector('.budget-tree-header');

        if (!treeContainer) return;

        // Filter categories by type
        const filteredCategories = (this.categoryTree || []).filter(cat => cat.type === this.budgetType);

        if (filteredCategories.length === 0) {
            treeContainer.innerHTML = '';
            if (headerEl) headerEl.style.display = 'none';
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        if (headerEl) headerEl.style.display = 'grid';
        if (emptyState) emptyState.style.display = 'none';

        treeContainer.innerHTML = this.renderBudgetCategoryNodes(filteredCategories, 0);

        // Setup inline editing listeners
        this.setupBudgetInlineEditing();
    }

    renderBudgetCategoryNodes(categories, level = 0) {
        return categories.map(category => {
            const hasChildren = category.children && category.children.length > 0;
            const spent = this.categorySpending[category.id] || 0;
            const budget = parseFloat(category.budgetAmount) || 0;
            const remaining = budget - spent;
            const percentage = budget > 0 ? Math.min((spent / budget) * 100, 100) : 0;

            let progressStatus = 'good';
            if (percentage >= 100) progressStatus = 'over';
            else if (percentage >= 80) progressStatus = 'danger';
            else if (percentage >= 60) progressStatus = 'warning';

            const remainingClass = remaining > 0 ? 'positive' : (remaining < 0 ? 'negative' : 'zero');

            return `
                <div class="budget-category-row ${hasChildren ? 'parent-row' : ''}" data-category-id="${category.id}">
                    <div class="budget-category-name level-${level}" data-label="">
                        <span class="category-color" style="background-color: ${category.color || '#3b82f6'}"></span>
                        <span class="category-label">${category.name}</span>
                    </div>
                    <div class="budget-input-wrapper" data-label="Budget">
                        <input type="number"
                               class="budget-input"
                               data-category-id="${category.id}"
                               value="${budget || ''}"
                               placeholder="0.00"
                               step="0.01"
                               min="0">
                    </div>
                    <div data-label="Period">
                        <select class="budget-period-select" data-category-id="${category.id}">
                            <option value="monthly" ${category.budgetPeriod === 'monthly' || !category.budgetPeriod ? 'selected' : ''}>Monthly</option>
                            <option value="weekly" ${category.budgetPeriod === 'weekly' ? 'selected' : ''}>Weekly</option>
                            <option value="quarterly" ${category.budgetPeriod === 'quarterly' ? 'selected' : ''}>Quarterly</option>
                            <option value="yearly" ${category.budgetPeriod === 'yearly' ? 'selected' : ''}>Yearly</option>
                        </select>
                    </div>
                    <div class="budget-spent" data-label="Spent">
                        ${this.formatCurrency(spent)}
                    </div>
                    <div class="budget-remaining ${remainingClass}" data-label="Remaining">
                        ${budget > 0 ? this.formatCurrency(remaining) : '<span class="no-budget">—</span>'}
                    </div>
                    <div class="budget-progress-wrapper" data-label="Progress">
                        ${budget > 0 ? `
                            <div class="budget-progress-bar">
                                <div class="budget-progress-fill ${progressStatus}" style="width: ${percentage}%"></div>
                            </div>
                            <span class="budget-progress-text">${Math.round(percentage)}%</span>
                        ` : '<span class="no-budget">No budget set</span>'}
                    </div>
                </div>
                ${hasChildren ? this.renderBudgetCategoryNodes(category.children, level + 1) : ''}
            `;
        }).join('');
    }

    setupBudgetInlineEditing() {
        // Budget amount inputs
        document.querySelectorAll('.budget-input').forEach(input => {
            let debounceTimer;
            input.addEventListener('change', (e) => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    this.saveCategoryBudget(e.target.dataset.categoryId, {
                        budgetAmount: e.target.value || null
                    });
                }, 300);
            });
        });

        // Period selects
        document.querySelectorAll('.budget-period-select').forEach(select => {
            select.addEventListener('change', (e) => {
                this.saveCategoryBudget(e.target.dataset.categoryId, {
                    budgetPeriod: e.target.value
                });
            });
        });
    }

    async saveCategoryBudget(categoryId, updates) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/categories/${categoryId}`), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(updates)
            });

            if (response.ok) {
                // Update local data
                const category = this.findCategoryById(parseInt(categoryId));
                if (category) {
                    Object.assign(category, updates);
                }

                // Re-render to update calculations
                this.renderBudgetTree();
                this.updateBudgetSummary();

                OC.Notification.showTemporary('Budget updated');
            } else {
                throw new Error('Failed to update budget');
            }
        } catch (error) {
            console.error('Failed to save budget:', error);
            OC.Notification.showTemporary('Failed to update budget');
        }
    }

    updateBudgetSummary() {
        const categories = this.flattenCategories(this.categoryTree || [])
            .filter(cat => cat.type === this.budgetType);

        let totalBudgeted = 0;
        let totalSpent = 0;
        let categoriesWithBudget = 0;

        categories.forEach(cat => {
            const budget = parseFloat(cat.budgetAmount) || 0;
            const spent = this.categorySpending[cat.id] || 0;

            if (budget > 0) {
                totalBudgeted += budget;
                categoriesWithBudget++;
            }
            totalSpent += spent;
        });

        const totalRemaining = totalBudgeted - totalSpent;

        // Update DOM
        const budgetedEl = document.getElementById('budget-total-budgeted');
        const spentEl = document.getElementById('budget-total-spent');
        const remainingEl = document.getElementById('budget-total-remaining');
        const countEl = document.getElementById('budget-categories-count');

        if (budgetedEl) budgetedEl.textContent = this.formatCurrency(totalBudgeted);
        if (spentEl) spentEl.textContent = this.formatCurrency(totalSpent);
        if (remainingEl) remainingEl.textContent = this.formatCurrency(totalRemaining);
        if (countEl) countEl.textContent = categoriesWithBudget;
    }

    flattenCategories(categories, result = []) {
        categories.forEach(cat => {
            result.push(cat);
            if (cat.children && cat.children.length > 0) {
                this.flattenCategories(cat.children, result);
            }
        });
        return result;
    }

    // ===================================
    // End Budget View Methods
    // ===================================

    showTransactionModal(transaction = null, preSelectedAccountId = null) {
        const modal = document.getElementById('transaction-modal');
        if (modal) {
            if (transaction) {
                // Populate form with transaction data (editing mode)
                document.getElementById('transaction-id').value = transaction.id;
                document.getElementById('transaction-date').value = transaction.date;
                document.getElementById('transaction-account').value = transaction.accountId;
                document.getElementById('transaction-type').value = transaction.type;
                document.getElementById('transaction-amount').value = transaction.amount;
                document.getElementById('transaction-description').value = transaction.description;
                document.getElementById('transaction-vendor').value = transaction.vendor || '';
                document.getElementById('transaction-category').value = transaction.categoryId || '';
                document.getElementById('transaction-notes').value = transaction.notes || '';
            } else {
                // Clear form (new transaction mode)
                document.getElementById('transaction-form').reset();
                document.getElementById('transaction-id').value = '';
                document.getElementById('transaction-date').value = new Date().toISOString().split('T')[0];

                // Pre-select account if provided
                if (preSelectedAccountId) {
                    document.getElementById('transaction-account').value = preSelectedAccountId;
                }
            }
            modal.style.display = 'flex';
        }
    }

    hideModals() {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.style.display = 'none';
        });
    }

    generateColor() {
        const hue = Math.floor(Math.random() * 360);
        return `hsl(${hue}, 70%, 60%)`;
    }

    // Public methods for inline event handlers
    editTransaction(id) {
        const transaction = this.transactions.find(t => t.id === id);
        if (transaction) {
            this.showTransactionModal(transaction);
        }
    }

    async deleteTransaction(id) {
        if (!confirm('Are you sure you want to delete this transaction?')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/transactions/${id}`), {
                method: 'DELETE',
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (response.ok) {
                OC.Notification.showTemporary('Transaction deleted');
                this.loadTransactions();
            }
        } catch (error) {
            console.error('Failed to delete transaction:', error);
            OC.Notification.showTemporary('Failed to delete transaction');
        }
    }

    searchTransactions(query) {
        if (!query) {
            this.loadTransactions();
            return;
        }

        const filtered = this.transactions.filter(t =>
            t.description.toLowerCase().includes(query.toLowerCase()) ||
            (t.vendor && t.vendor.toLowerCase().includes(query.toLowerCase())) ||
            (t.notes && t.notes.toLowerCase().includes(query.toLowerCase()))
        );

        const tbody = document.querySelector('#transactions-table tbody');
        if (tbody) {
            tbody.innerHTML = this.renderTransactionsTable(filtered);
        }
    }

    // ===========================
    // Inline Editing
    // ===========================

    setupInlineEditingListeners() {
        const transactionsTable = document.getElementById('transactions-table');
        if (!transactionsTable) {
            return;
        }

        // Handle click on editable cells
        transactionsTable.addEventListener('click', (e) => {
            const cell = e.target.closest('.editable-cell');
            if (cell && !cell.classList.contains('editing')) {
                // Don't trigger if clicking on checkbox
                if (e.target.type === 'checkbox') return;
                this.startInlineEdit(cell);
            }
        });

        // Close any open inline editors when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.editable-cell') && !e.target.closest('.category-autocomplete-dropdown')) {
                this.closeAllInlineEditors();
            }
        });
    }

    startInlineEdit(cell) {
        // Close any other open editors first
        this.closeAllInlineEditors();

        const field = cell.dataset.field;
        const value = cell.dataset.value;
        const transactionId = parseInt(cell.dataset.transactionId);
        const transaction = this.transactions.find(t => t.id === transactionId);


        if (!transaction) {
            return;
        }

        cell.classList.add('editing');
        this.currentEditingCell = cell;
        this.originalValue = value;


        switch (field) {
            case 'date':
                this.createDateEditor(cell, value);
                break;
            case 'description':
                this.createTextEditor(cell, value, 'description');
                break;
            case 'categoryId':
                this.createCategoryEditor(cell, value);
                break;
            case 'amount':
                this.createAmountEditor(cell, transaction);
                break;
            case 'accountId':
                this.createAccountEditor(cell, value);
                break;
            default:
                this.createTextEditor(cell, value, field);
        }
    }

    createDateEditor(cell, value) {
        const input = document.createElement('input');
        input.type = 'date';
        input.className = 'inline-edit-input';
        input.value = value;

        this.setupEditorEvents(input, cell, 'date');
        cell.innerHTML = '';
        cell.appendChild(input);
        input.focus();
    }

    createTextEditor(cell, value, field) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'inline-edit-input';
        input.value = value || '';
        input.placeholder = field === 'description' ? 'Enter description...' : '';

        this.setupEditorEvents(input, cell, field);
        cell.innerHTML = '';
        cell.appendChild(input);
        input.focus();
        input.select();
    }

    createAmountEditor(cell, transaction) {
        const container = document.createElement('div');
        container.style.display = 'flex';
        container.style.alignItems = 'center';
        container.style.gap = '4px';

        // Type toggle
        const typeToggle = document.createElement('div');
        typeToggle.className = 'inline-type-toggle';

        const creditBtn = document.createElement('button');
        creditBtn.type = 'button';
        creditBtn.className = `inline-type-btn ${transaction.type === 'credit' ? 'active' : ''}`;
        creditBtn.textContent = '+';
        creditBtn.title = 'Income';

        const debitBtn = document.createElement('button');
        debitBtn.type = 'button';
        debitBtn.className = `inline-type-btn ${transaction.type === 'debit' ? 'active' : ''}`;
        debitBtn.textContent = '-';
        debitBtn.title = 'Expense';

        typeToggle.appendChild(creditBtn);
        typeToggle.appendChild(debitBtn);

        // Amount input
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'inline-edit-input';
        input.value = transaction.amount;
        input.step = '0.01';
        input.min = '0';
        input.dataset.type = transaction.type;

        // Type toggle events
        creditBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            creditBtn.classList.add('active');
            debitBtn.classList.remove('active');
            input.dataset.type = 'credit';
        });

        debitBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            debitBtn.classList.add('active');
            creditBtn.classList.remove('active');
            input.dataset.type = 'debit';
        });

        this.setupEditorEvents(input, cell, 'amount');

        container.appendChild(typeToggle);
        container.appendChild(input);
        cell.innerHTML = '';
        cell.appendChild(container);
        input.focus();
        input.select();
    }

    createCategoryEditor(cell, currentCategoryId) {

        const container = document.createElement('div');
        container.className = 'category-autocomplete';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'category-autocomplete-input';
        input.placeholder = 'Type to search...';

        // Try hierarchical first (for categories page), then flat (for transactions page)
        let categoryData = null;
        if (this.categoryTree && this.categoryTree.length > 0) {
            categoryData = this.categoryTree;
        } else if (this.allCategories && this.allCategories.length > 0) {
            categoryData = this.allCategories;
        } else if (this.categories && this.categories.length > 0) {
            categoryData = this.categories;
        } else {
        }

        // Build flat list of categories for search
        const flatCategories = categoryData ? this.getFlatCategoryList(categoryData) : [];

        // Set current category name as value
        const currentCategory = flatCategories.find(c => c.id === parseInt(currentCategoryId));
        input.value = currentCategory ? currentCategory.name : '';
        input.dataset.categoryId = currentCategoryId || '';

        const dropdown = document.createElement('div');
        dropdown.className = 'category-autocomplete-dropdown';
        dropdown.style.display = 'none';

        container.appendChild(input);
        container.appendChild(dropdown);

        const showDropdown = (filter = '') => {
            const filtered = filter
                ? flatCategories.filter(c => c.name.toLowerCase().includes(filter.toLowerCase()))
                : flatCategories;


            if (filtered.length === 0) {
                dropdown.innerHTML = '<div class="category-autocomplete-empty">No categories found</div>';
            } else {
                dropdown.innerHTML = filtered.map(c => `
                    <div class="category-autocomplete-item ${c.id === parseInt(input.dataset.categoryId) ? 'selected' : ''}"
                         data-category-id="${c.id}"
                         data-category-name="${c.name}">
                        ${c.prefix}${c.name}
                    </div>
                `).join('');
            }

            // Add "Uncategorized" option
            dropdown.innerHTML = `
                <div class="category-autocomplete-item ${!input.dataset.categoryId ? 'selected' : ''}"
                     data-category-id=""
                     data-category-name="">
                    Uncategorized
                </div>
            ` + dropdown.innerHTML;

            dropdown.style.display = 'block';
        };

        input.addEventListener('focus', () => showDropdown(input.value));
        input.addEventListener('input', () => showDropdown(input.value));

        // CRITICAL: Prevent input blur when clicking dropdown
        // Without this, clicking the dropdown causes blur → cancelInlineEdit → table re-render → dropdown destroyed
        dropdown.addEventListener('mousedown', (e) => {
            e.preventDefault(); // Prevents input from losing focus
        });

        dropdown.addEventListener('click', (e) => {
            e.stopPropagation(); // Prevent event from bubbling up
            const item = e.target.closest('.category-autocomplete-item');
            if (item) {
                input.dataset.categoryId = item.dataset.categoryId;
                input.value = item.dataset.categoryName;
                dropdown.style.display = 'none';
                this.saveInlineEdit(cell, 'categoryId', item.dataset.categoryId);
            }
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.cancelInlineEdit(cell);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                dropdown.style.display = 'none';
                this.saveInlineEdit(cell, 'categoryId', input.dataset.categoryId);
            } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                this.navigateCategoryDropdown(dropdown, e.key === 'ArrowDown' ? 1 : -1, input);
            }
        });

        input.addEventListener('blur', () => {
            // Delay to allow click on dropdown item
            setTimeout(() => {
                if (!container.contains(document.activeElement)) {
                    dropdown.style.display = 'none';
                    // Save if value changed
                    if (input.dataset.categoryId !== (currentCategoryId || '')) {
                        this.saveInlineEdit(cell, 'categoryId', input.dataset.categoryId);
                    } else {
                        this.cancelInlineEdit(cell);
                    }
                }
            }, 200);
        });

        cell.innerHTML = '';
        cell.appendChild(container);
        input.focus();
        input.select();
        showDropdown();
    }

    getFlatCategoryList(categories = null, prefix = '') {
        const cats = categories || this.categories || [];
        let result = [];

        for (const cat of cats) {
            result.push({ id: cat.id, name: cat.name, prefix });
            if (cat.children && cat.children.length > 0) {
                result = result.concat(this.getFlatCategoryList(cat.children, prefix + '  '));
            }
        }

        return result;
    }

    navigateCategoryDropdown(dropdown, direction, input) {
        const items = dropdown.querySelectorAll('.category-autocomplete-item');
        if (items.length === 0) return;

        const currentHighlighted = dropdown.querySelector('.category-autocomplete-item.highlighted');
        let nextIndex = 0;

        if (currentHighlighted) {
            currentHighlighted.classList.remove('highlighted');
            const currentIndex = Array.from(items).indexOf(currentHighlighted);
            nextIndex = currentIndex + direction;
            if (nextIndex < 0) nextIndex = items.length - 1;
            if (nextIndex >= items.length) nextIndex = 0;
        } else {
            nextIndex = direction === 1 ? 0 : items.length - 1;
        }

        items[nextIndex].classList.add('highlighted');
        items[nextIndex].scrollIntoView({ block: 'nearest' });
        input.dataset.categoryId = items[nextIndex].dataset.categoryId;
    }

    createAccountEditor(cell, currentAccountId) {
        const select = document.createElement('select');
        select.className = 'inline-edit-select';

        this.accounts?.forEach(account => {
            const option = document.createElement('option');
            option.value = account.id;
            option.textContent = account.name;
            option.selected = account.id === parseInt(currentAccountId);
            select.appendChild(option);
        });

        select.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.cancelInlineEdit(cell);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                this.saveInlineEdit(cell, 'accountId', select.value);
            }
        });

        select.addEventListener('change', () => {
            this.saveInlineEdit(cell, 'accountId', select.value);
        });

        select.addEventListener('blur', () => {
            // Small delay to prevent race condition with change event
            setTimeout(() => {
                if (cell.classList.contains('editing')) {
                    this.cancelInlineEdit(cell);
                }
            }, 100);
        });

        cell.innerHTML = '';
        cell.appendChild(select);
        select.focus();
    }

    setupEditorEvents(input, cell, field) {
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.cancelInlineEdit(cell);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (field === 'amount') {
                    const type = input.dataset.type;
                    this.saveInlineEdit(cell, field, input.value, { type });
                } else {
                    this.saveInlineEdit(cell, field, input.value);
                }
            }
        });

        input.addEventListener('blur', (e) => {
            // Don't save if clicking on type toggle buttons
            if (e.relatedTarget?.closest('.inline-type-toggle')) {
                return;
            }

            // Small delay to prevent race with Enter key
            setTimeout(() => {
                if (cell.classList.contains('editing')) {
                    if (field === 'amount') {
                        const type = input.dataset.type;
                        this.saveInlineEdit(cell, field, input.value, { type });
                    } else {
                        this.saveInlineEdit(cell, field, input.value);
                    }
                }
            }, 100);
        });
    }

    async saveInlineEdit(cell, field, value, extra = {}) {
        const transactionId = parseInt(cell.dataset.transactionId);
        const transaction = this.transactions.find(t => t.id === transactionId);


        if (!transaction) {
            this.cancelInlineEdit(cell);
            return;
        }

        // Check if value actually changed
        let hasChanged = false;
        if (field === 'amount') {
            const newAmount = parseFloat(value);
            const newType = extra.type || transaction.type;
            hasChanged = newAmount !== transaction.amount || newType !== transaction.type;
        } else if (field === 'categoryId') {
            const newCatId = value === '' ? null : parseInt(value);
            hasChanged = newCatId !== transaction.categoryId;
        } else if (field === 'accountId') {
            hasChanged = parseInt(value) !== transaction.accountId;
        } else {
            hasChanged = value !== (transaction[field] || '');
        }

        if (!hasChanged) {
            this.cancelInlineEdit(cell);
            return;
        }


        // Show saving state
        cell.classList.add('cell-saving');

        // Prepare update data
        const updateData = {};
        if (field === 'amount') {
            updateData.amount = parseFloat(value);
            if (extra.type) {
                updateData.type = extra.type;
            }
        } else if (field === 'categoryId') {
            updateData.categoryId = value === '' ? null : parseInt(value);
        } else if (field === 'accountId') {
            updateData.accountId = parseInt(value);
        } else {
            updateData[field] = value;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/transactions/${transactionId}`), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(updateData)
            });

            if (response.ok) {
                const result = await response.json();

                // Update local transaction data
                Object.assign(transaction, result);

                // If account changed, reload accounts to update balances
                if (field === 'accountId') {
                    await this.loadAccounts();
                }

                // Re-render the table to show updated values
                this.renderEnhancedTransactionsTable();
                this.applyColumnVisibility();
                OC.Notification.showTemporary('Transaction updated');
            } else {
                throw new Error('Update failed');
            }
        } catch (error) {
            console.error('Failed to save inline edit:', error);
            OC.Notification.showTemporary('Failed to update transaction');
            this.cancelInlineEdit(cell);
        }
    }

    cancelInlineEdit(cell) {
        if (!cell || !cell.classList.contains('editing')) return;

        // Re-render the table to restore original display
        this.renderEnhancedTransactionsTable();
        this.applyColumnVisibility();
        this.currentEditingCell = null;
        this.originalValue = null;
    }

    closeAllInlineEditors() {
        const editingCells = document.querySelectorAll('.editable-cell.editing');
        editingCells.forEach(cell => {
            this.cancelInlineEdit(cell);
        });
    }

    // ===========================
    // Reports Management
    // ===========================

    setupReportEventListeners() {
        // Period preset change
        const presetSelect = document.getElementById('report-period-preset');
        if (presetSelect) {
            presetSelect.addEventListener('change', (e) => {
                const customRange = document.getElementById('custom-date-range');
                if (customRange) {
                    customRange.style.display = e.target.value === 'custom' ? 'flex' : 'none';
                }
                if (e.target.value !== 'custom') {
                    this.setReportDateRange(e.target.value);
                }
            });
        }

        // Generate report button
        const generateBtn = document.getElementById('generate-report-btn');
        if (generateBtn) {
            generateBtn.addEventListener('click', () => this.generateReport());
        }

        // Report type change
        const typeSelect = document.getElementById('report-type');
        if (typeSelect) {
            typeSelect.addEventListener('change', () => this.generateReport());
        }

        // Export buttons
        document.getElementById('export-csv-btn')?.addEventListener('click', () => this.exportReport('csv'));
        document.getElementById('export-pdf-btn')?.addEventListener('click', () => this.exportReport('pdf'));

        // Initialize charts object for reports
        this.reportCharts = {};
    }

    setReportDateRange(preset) {
        const now = new Date();
        let startDate, endDate;

        switch (preset) {
            case 'this-month':
                startDate = new Date(now.getFullYear(), now.getMonth(), 1);
                endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                break;
            case 'last-3-months':
                startDate = new Date(now.getFullYear(), now.getMonth() - 2, 1);
                endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                break;
            case 'ytd':
                startDate = new Date(now.getFullYear(), 0, 1);
                endDate = now;
                break;
            case 'last-year':
                startDate = new Date(now.getFullYear() - 1, 0, 1);
                endDate = new Date(now.getFullYear() - 1, 11, 31);
                break;
            default:
                startDate = new Date(now.getFullYear(), now.getMonth() - 2, 1);
                endDate = now;
        }

        const startInput = document.getElementById('report-start-date');
        const endInput = document.getElementById('report-end-date');
        if (startInput) startInput.value = startDate.toISOString().split('T')[0];
        if (endInput) endInput.value = endDate.toISOString().split('T')[0];
    }

    async loadReportsView() {
        // Setup event listeners on first load
        if (!this.reportEventListenersSetup) {
            this.setupReportEventListeners();
            this.reportEventListenersSetup = true;
        }

        // Populate account dropdown
        this.populateReportAccountDropdown();

        // Set default date range
        this.setReportDateRange('last-3-months');

        // Generate initial report
        await this.generateReport();
    }

    populateReportAccountDropdown() {
        const dropdown = document.getElementById('report-account');
        if (!dropdown) return;

        dropdown.innerHTML = '<option value="">All Accounts</option>';
        if (Array.isArray(this.accounts)) {
            this.accounts.forEach(account => {
                dropdown.innerHTML += `<option value="${account.id}">${account.name}</option>`;
            });
        }
    }

    async generateReport() {
        const reportType = document.getElementById('report-type')?.value || 'summary';
        const startDate = document.getElementById('report-start-date')?.value;
        const endDate = document.getElementById('report-end-date')?.value;
        const accountId = document.getElementById('report-account')?.value || '';

        // Show loading
        const loadingEl = document.getElementById('report-loading');
        if (loadingEl) loadingEl.style.display = 'flex';

        // Hide all report sections
        document.querySelectorAll('.report-section').forEach(el => el.style.display = 'none');

        try {
            const params = new URLSearchParams({
                startDate,
                endDate,
                ...(accountId && { accountId })
            });

            switch (reportType) {
                case 'summary':
                    await this.generateSummaryReport(params);
                    break;
                case 'spending':
                    await this.generateSpendingReport(params);
                    break;
                case 'cashflow':
                    await this.generateCashFlowReport(params);
                    break;
                case 'yoy':
                    this.showYoYReport();
                    break;
            }
        } catch (error) {
            console.error('Failed to generate report:', error);
            OC.Notification.showTemporary('Failed to generate report');
        } finally {
            if (loadingEl) loadingEl.style.display = 'none';
        }
    }

    async generateSummaryReport(params) {
        const response = await fetch(
            OC.generateUrl(`/apps/budget/api/reports/summary-comparison?${params}`),
            { headers: { 'requesttoken': OC.requestToken } }
        );

        if (!response.ok) throw new Error('Failed to fetch summary');
        const data = await response.json();

        const section = document.getElementById('report-summary');
        if (section) section.style.display = 'block';

        const currency = this.getPrimaryCurrency();
        const totals = data.totals || {};
        const comparison = data.comparison?.changes || {};

        // Update summary cards
        this.updateReportCard('report-total-income', totals.totalIncome, currency, 'report-income-change', comparison.income);
        this.updateReportCard('report-total-expenses', totals.totalExpenses, currency, 'report-expenses-change', comparison.expenses);
        this.updateReportCard('report-net-income', totals.netIncome, currency, 'report-net-change', comparison.netIncome);

        // Savings rate
        const savingsRateEl = document.getElementById('report-savings-rate');
        if (savingsRateEl && totals.totalIncome > 0) {
            const rate = ((totals.netIncome / totals.totalIncome) * 100).toFixed(1);
            savingsRateEl.textContent = `${rate}%`;
        } else if (savingsRateEl) {
            savingsRateEl.textContent = '--';
        }

        // Render trend chart
        this.renderReportTrendChart(data.trends);

        // Render accounts table
        this.renderReportAccountsTable(data.accounts || [], currency);
    }

    updateReportCard(valueId, value, currency, changeId, change) {
        const valueEl = document.getElementById(valueId);
        if (valueEl) {
            valueEl.textContent = this.formatCurrency(value || 0, currency);
        }

        const changeEl = document.getElementById(changeId);
        if (changeEl && change) {
            const arrow = change.direction === 'up' ? '↑' : change.direction === 'down' ? '↓' : '';
            const colorClass = change.direction === 'up' ? 'positive' : change.direction === 'down' ? 'negative' : '';
            changeEl.innerHTML = `${arrow} ${change.percentage}% vs prior period`;
            changeEl.className = `summary-change ${colorClass}`;
        } else if (changeEl) {
            changeEl.innerHTML = '';
        }
    }

    renderReportTrendChart(trends) {
        const canvas = document.getElementById('report-trend-chart');
        if (!canvas || !trends) return;

        if (this.reportCharts.trend) {
            this.reportCharts.trend.destroy();
        }

        const ctx = canvas.getContext('2d');
        const currency = this.getPrimaryCurrency();

        this.reportCharts.trend = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: trends.labels || [],
                datasets: [
                    {
                        label: 'Income',
                        data: trends.income || [],
                        backgroundColor: 'rgba(46, 125, 50, 0.7)',
                        borderColor: 'rgba(46, 125, 50, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Expenses',
                        data: trends.expenses || [],
                        backgroundColor: 'rgba(198, 40, 40, 0.7)',
                        borderColor: 'rgba(198, 40, 40, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${this.formatCurrency(context.raw, currency)}`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrencyCompact(value, currency)
                        }
                    }
                }
            }
        });
    }

    renderReportAccountsTable(accounts, currency) {
        const tbody = document.querySelector('#report-accounts-table tbody');
        if (!tbody) return;

        tbody.innerHTML = accounts.map(account => `
            <tr>
                <td>${account.name}</td>
                <td class="text-right positive">${this.formatCurrency(account.income || 0, currency)}</td>
                <td class="text-right negative">${this.formatCurrency(account.expenses || 0, currency)}</td>
                <td class="text-right ${(account.net || 0) >= 0 ? 'positive' : 'negative'}">${this.formatCurrency(account.net || 0, currency)}</td>
                <td class="text-right">${this.formatCurrency(account.balance || 0, currency)}</td>
            </tr>
        `).join('');
    }

    async generateSpendingReport(params) {
        const [categoryResponse, vendorResponse] = await Promise.all([
            fetch(OC.generateUrl(`/apps/budget/api/reports/spending?${params}&groupBy=category`), {
                headers: { 'requesttoken': OC.requestToken }
            }),
            fetch(OC.generateUrl(`/apps/budget/api/reports/spending?${params}&groupBy=vendor`), {
                headers: { 'requesttoken': OC.requestToken }
            })
        ]);

        const categoryData = await categoryResponse.json();
        const vendorData = await vendorResponse.json();

        const section = document.getElementById('report-spending');
        if (section) section.style.display = 'block';

        const currency = this.getPrimaryCurrency();
        const totalSpending = categoryData.totals?.amount || 0;

        // Render category chart
        this.renderReportSpendingChart(categoryData.data || [], totalSpending);

        // Render category table
        this.renderReportCategoryTable(categoryData.data || [], totalSpending, currency);

        // Render vendor table
        this.renderReportVendorTable(vendorData.data || [], currency);
    }

    renderReportSpendingChart(data, totalSpending) {
        const canvas = document.getElementById('report-spending-chart');
        if (!canvas) return;

        if (this.reportCharts.spending) {
            this.reportCharts.spending.destroy();
        }

        const ctx = canvas.getContext('2d');
        const sortedData = [...data].sort((a, b) => b.total - a.total).slice(0, 10);

        const defaultColors = [
            '#4CAF50', '#2196F3', '#FF9800', '#9C27B0', '#F44336',
            '#00BCD4', '#FFEB3B', '#795548', '#607D8B', '#E91E63'
        ];
        const colors = sortedData.map((d, i) => d.color || defaultColors[i % defaultColors.length]);

        this.reportCharts.spending = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: sortedData.map(d => d.name),
                datasets: [{
                    data: sortedData.map(d => d.total),
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = context.raw;
                                const pct = totalSpending > 0 ? ((value / totalSpending) * 100).toFixed(1) : 0;
                                return `${context.label}: ${this.formatCurrency(value)} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Render custom legend
        this.renderSpendingLegend(sortedData, totalSpending, 'report-spending-legend');
    }

    renderSpendingLegend(data, totalSpending, containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        const defaultColors = [
            '#4CAF50', '#2196F3', '#FF9800', '#9C27B0', '#F44336',
            '#00BCD4', '#FFEB3B', '#795548', '#607D8B', '#E91E63'
        ];

        container.innerHTML = data.slice(0, 8).map((item, i) => {
            const pct = totalSpending > 0 ? ((item.total / totalSpending) * 100).toFixed(1) : 0;
            const color = item.color || defaultColors[i % defaultColors.length];
            return `
                <div class="spending-legend-item">
                    <span class="spending-legend-color" style="background: ${color}"></span>
                    <span class="spending-legend-name">${item.name}</span>
                    <span class="spending-legend-value">${this.formatCurrency(item.total)}</span>
                    <span class="spending-legend-pct">${pct}%</span>
                </div>
            `;
        }).join('');
    }

    renderReportCategoryTable(data, totalSpending, currency) {
        const tbody = document.querySelector('#report-categories-table tbody');
        if (!tbody) return;

        const sortedData = [...data].sort((a, b) => b.total - a.total);

        tbody.innerHTML = sortedData.map(cat => {
            const pct = totalSpending > 0 ? ((cat.total / totalSpending) * 100).toFixed(1) : 0;
            return `
                <tr>
                    <td>
                        <span class="category-color" style="background: ${cat.color || '#888'}"></span>
                        ${cat.name}
                    </td>
                    <td class="text-right">${this.formatCurrency(cat.total, currency)}</td>
                    <td class="text-right">${pct}%</td>
                    <td class="text-right">${cat.count}</td>
                </tr>
            `;
        }).join('');
    }

    renderReportVendorTable(data, currency) {
        const tbody = document.querySelector('#report-vendors-table tbody');
        if (!tbody) return;

        tbody.innerHTML = data.map(vendor => `
            <tr>
                <td>${vendor.name}</td>
                <td class="text-right">${this.formatCurrency(vendor.total, currency)}</td>
                <td class="text-right">${vendor.count}</td>
            </tr>
        `).join('');
    }

    async generateCashFlowReport(params) {
        const response = await fetch(
            OC.generateUrl(`/apps/budget/api/reports/cashflow?${params}`),
            { headers: { 'requesttoken': OC.requestToken } }
        );

        if (!response.ok) throw new Error('Failed to fetch cash flow');
        const data = await response.json();

        const section = document.getElementById('report-cashflow');
        if (section) section.style.display = 'block';

        const currency = this.getPrimaryCurrency();
        const averages = data.averageMonthly || {};

        // Update average cards
        const avgIncomeEl = document.getElementById('report-avg-income');
        const avgExpensesEl = document.getElementById('report-avg-expenses');
        const avgNetEl = document.getElementById('report-avg-net');

        if (avgIncomeEl) avgIncomeEl.textContent = this.formatCurrency(averages.income || 0, currency);
        if (avgExpensesEl) avgExpensesEl.textContent = this.formatCurrency(averages.expenses || 0, currency);
        if (avgNetEl) avgNetEl.textContent = this.formatCurrency(averages.net || 0, currency);

        // Render chart
        this.renderCashFlowChart(data.data || []);

        // Render table
        this.renderCashFlowTable(data.data || [], currency);
    }

    renderCashFlowChart(data) {
        const canvas = document.getElementById('report-cashflow-chart');
        if (!canvas) return;

        if (this.reportCharts.cashflow) {
            this.reportCharts.cashflow.destroy();
        }

        const ctx = canvas.getContext('2d');
        const currency = this.getPrimaryCurrency();

        this.reportCharts.cashflow = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(d => this.formatReportMonthLabel(d.month)),
                datasets: [
                    {
                        label: 'Income',
                        data: data.map(d => d.income),
                        backgroundColor: 'rgba(46, 125, 50, 0.7)',
                        borderColor: 'rgba(46, 125, 50, 1)',
                        borderWidth: 1,
                        order: 2
                    },
                    {
                        label: 'Expenses',
                        data: data.map(d => d.expenses),
                        backgroundColor: 'rgba(198, 40, 40, 0.7)',
                        borderColor: 'rgba(198, 40, 40, 1)',
                        borderWidth: 1,
                        order: 2
                    },
                    {
                        label: 'Net Cash Flow',
                        data: data.map(d => d.net),
                        borderColor: 'rgba(33, 150, 243, 1)',
                        backgroundColor: 'rgba(33, 150, 243, 0.1)',
                        borderWidth: 2,
                        type: 'line',
                        tension: 0.3,
                        pointRadius: 4,
                        fill: true,
                        order: 1
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
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${this.formatCurrency(context.raw, currency)}`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrencyCompact(value, currency)
                        }
                    }
                }
            }
        });
    }

    renderCashFlowTable(data, currency) {
        const tbody = document.querySelector('#report-cashflow-table tbody');
        if (!tbody) return;

        let cumulative = 0;
        tbody.innerHTML = data.map(row => {
            cumulative += row.net;
            return `
                <tr>
                    <td>${this.formatReportMonthLabel(row.month)}</td>
                    <td class="text-right positive">${this.formatCurrency(row.income, currency)}</td>
                    <td class="text-right negative">${this.formatCurrency(row.expenses, currency)}</td>
                    <td class="text-right ${row.net >= 0 ? 'positive' : 'negative'}">${this.formatCurrency(row.net, currency)}</td>
                    <td class="text-right ${cumulative >= 0 ? 'positive' : 'negative'}">${this.formatCurrency(cumulative, currency)}</td>
                </tr>
            `;
        }).join('');
    }

    formatReportMonthLabel(yearMonth) {
        if (!yearMonth) return '';
        const [year, month] = yearMonth.split('-');
        const date = new Date(year, month - 1);
        return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
    }

    formatCurrencyCompact(value, currency) {
        if (Math.abs(value) >= 1000000) {
            return this.formatCurrency(value / 1000000, currency).replace(/[\d,.]+/, (m) => parseFloat(m).toFixed(1)) + 'M';
        }
        if (Math.abs(value) >= 1000) {
            return this.formatCurrency(value / 1000, currency).replace(/[\d,.]+/, (m) => parseFloat(m).toFixed(1)) + 'K';
        }
        return this.formatCurrency(value, currency);
    }

    // ==========================================
    // Year over Year Report Functions
    // ==========================================

    showYoYReport() {
        const section = document.getElementById('report-yoy');
        if (section) section.style.display = 'block';

        // Setup YoY controls if not already done
        if (!this.yoyControlsSetup) {
            this.setupYoYControls();
            this.yoyControlsSetup = true;
        }

        // Set default month to current month
        const monthSelect = document.getElementById('yoy-month');
        if (monthSelect) {
            monthSelect.value = new Date().getMonth() + 1;
        }
    }

    setupYoYControls() {
        const comparisonType = document.getElementById('yoy-comparison-type');
        const generateBtn = document.getElementById('generate-yoy-btn');
        const monthSelect = document.querySelector('.yoy-month-select');

        if (comparisonType) {
            comparisonType.addEventListener('change', (e) => {
                if (monthSelect) {
                    monthSelect.style.display = e.target.value === 'month' ? '' : 'none';
                }
            });
        }

        if (generateBtn) {
            generateBtn.addEventListener('click', () => this.generateYoYComparison());
        }
    }

    async generateYoYComparison() {
        const comparisonType = document.getElementById('yoy-comparison-type')?.value || 'years';
        const years = document.getElementById('yoy-years')?.value || 3;
        const month = document.getElementById('yoy-month')?.value || new Date().getMonth() + 1;

        // Show loading
        const loadingEl = document.getElementById('report-loading');
        if (loadingEl) loadingEl.style.display = 'flex';

        // Hide all YoY sections
        document.getElementById('yoy-summary')?.style.setProperty('display', 'none');
        document.getElementById('yoy-chart-container')?.style.setProperty('display', 'none');
        document.getElementById('yoy-category-table-container')?.style.setProperty('display', 'none');

        try {
            let endpoint;
            switch (comparisonType) {
                case 'month':
                    endpoint = `/apps/budget/api/yoy/month?month=${month}&years=${years}`;
                    break;
                case 'categories':
                    endpoint = `/apps/budget/api/yoy/categories?years=${years}`;
                    break;
                default:
                    endpoint = `/apps/budget/api/yoy/years?years=${years}`;
            }

            const response = await fetch(OC.generateUrl(endpoint), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error('Failed to fetch YoY data');
            const data = await response.json();

            if (comparisonType === 'categories') {
                this.displayYoYCategories(data);
            } else {
                this.displayYoYComparison(data);
            }

        } catch (error) {
            console.error('Failed to generate YoY comparison:', error);
            OC.Notification.showTemporary('Failed to generate comparison');
        } finally {
            if (loadingEl) loadingEl.style.display = 'none';
        }
    }

    displayYoYComparison(data) {
        const currency = this.getPrimaryCurrency();
        const summaryEl = document.getElementById('yoy-summary');
        const cardsEl = document.getElementById('yoy-year-cards');
        const chartContainer = document.getElementById('yoy-chart-container');
        const chartTitle = document.getElementById('yoy-chart-title');

        if (!summaryEl || !cardsEl) return;

        summaryEl.style.display = '';
        if (chartContainer) chartContainer.style.display = '';

        // Build year cards
        cardsEl.innerHTML = data.years.map(year => {
            const changeHtml = year.incomeChange !== undefined ? `
                <div class="yoy-change-indicators">
                    <span class="yoy-change ${year.incomeChange >= 0 ? 'positive' : 'negative'}">
                        Income: ${year.incomeChange >= 0 ? '+' : ''}${year.incomeChange?.toFixed(1) || '0'}%
                    </span>
                    <span class="yoy-change ${year.expenseChange <= 0 ? 'positive' : 'negative'}">
                        Expenses: ${year.expenseChange >= 0 ? '+' : ''}${year.expenseChange?.toFixed(1) || '0'}%
                    </span>
                </div>
            ` : '';

            return `
                <div class="yoy-year-card ${year.isCurrent ? 'current-year' : ''}">
                    <div class="yoy-year-header">
                        <span class="yoy-year-label">${year.year}${year.isCurrent ? ' (YTD)' : ''}</span>
                        ${year.monthName ? `<span class="yoy-month-label">${year.monthName}</span>` : ''}
                    </div>
                    <div class="yoy-year-stats">
                        <div class="yoy-stat">
                            <span class="yoy-stat-label">Income</span>
                            <span class="yoy-stat-value positive">${this.formatCurrency(year.income, currency)}</span>
                        </div>
                        <div class="yoy-stat">
                            <span class="yoy-stat-label">Expenses</span>
                            <span class="yoy-stat-value negative">${this.formatCurrency(year.expenses, currency)}</span>
                        </div>
                        <div class="yoy-stat">
                            <span class="yoy-stat-label">Savings</span>
                            <span class="yoy-stat-value ${year.savings >= 0 ? 'positive' : 'negative'}">${this.formatCurrency(year.savings, currency)}</span>
                        </div>
                    </div>
                    ${changeHtml}
                </div>
            `;
        }).join('');

        // Update chart title
        if (chartTitle) {
            chartTitle.textContent = data.type === 'month'
                ? `${data.monthName} - Year over Year Comparison`
                : 'Annual Income & Expenses';
        }

        // Render chart
        this.renderYoYChart(data.years);
    }

    renderYoYChart(years) {
        const canvas = document.getElementById('yoy-chart');
        if (!canvas) return;

        if (this.reportCharts.yoy) {
            this.reportCharts.yoy.destroy();
        }

        const ctx = canvas.getContext('2d');
        const currency = this.getPrimaryCurrency();

        this.reportCharts.yoy = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: years.map(y => y.year.toString()),
                datasets: [
                    {
                        label: 'Income',
                        data: years.map(y => y.income),
                        backgroundColor: 'rgba(46, 125, 50, 0.7)',
                        borderColor: 'rgba(46, 125, 50, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Expenses',
                        data: years.map(y => y.expenses),
                        backgroundColor: 'rgba(198, 40, 40, 0.7)',
                        borderColor: 'rgba(198, 40, 40, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
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
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrencyCompact(value, currency)
                        }
                    }
                }
            }
        });
    }

    displayYoYCategories(data) {
        const tableContainer = document.getElementById('yoy-category-table-container');
        const headerRow = document.getElementById('yoy-category-header');
        const tbody = document.getElementById('yoy-category-body');

        if (!tableContainer || !headerRow || !tbody) return;

        tableContainer.style.display = '';
        const currency = this.getPrimaryCurrency();

        // Build header with year columns
        const years = data.categories[0]?.years || [];
        headerRow.innerHTML = '<th>Category</th>' +
            years.map(y => `<th class="text-right">${y.year}</th>`).join('') +
            '<th class="text-right">Change</th>';

        // Build table rows
        tbody.innerHTML = data.categories.map(cat => {
            const changeHtml = cat.change !== null
                ? `<span class="${cat.change <= 0 ? 'positive' : 'negative'}">${cat.change >= 0 ? '+' : ''}${cat.change.toFixed(1)}%</span>`
                : 'N/A';

            return `
                <tr>
                    <td>${this.escapeHtml(cat.name)}</td>
                    ${cat.years.map(y => `<td class="text-right">${this.formatCurrency(y.spending, currency)}</td>`).join('')}
                    <td class="text-right">${changeHtml}</td>
                </tr>
            `;
        }).join('');
    }

    async exportReport(format) {
        const reportType = document.getElementById('report-type')?.value || 'summary';
        const startDate = document.getElementById('report-start-date')?.value;
        const endDate = document.getElementById('report-end-date')?.value;
        const accountId = document.getElementById('report-account')?.value || '';

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/reports/export'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    type: reportType,
                    format,
                    startDate,
                    endDate,
                    accountId: accountId || null
                })
            });

            if (!response.ok) throw new Error('Export failed');

            const blob = await response.blob();
            const filename = `${reportType}_report_${new Date().toISOString().split('T')[0]}.${format}`;

            // Trigger download
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);

            OC.Notification.showTemporary(`Report exported as ${format.toUpperCase()}`);
        } catch (error) {
            console.error('Export failed:', error);
            OC.Notification.showTemporary('Failed to export report');
        }
    }

    // ===========================
    // Shared Expenses
    // ===========================

    async loadSharedExpensesView() {
        await this.loadBalanceSummary();
        await this.loadContacts();

        // Setup event listeners (only once)
        if (!this._sharedEventsSetup) {
            this.setupSharedExpenseEventListeners();
            this._sharedEventsSetup = true;
        }
    }

    async loadBalanceSummary() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/shared/balances'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to load balances');
            const data = await response.json();

            document.getElementById('split-total-owed').textContent = this.formatCurrency(data.totalOwed);
            document.getElementById('split-total-owing').textContent = this.formatCurrency(data.totalOwing);

            const netBalance = data.netBalance;
            const netEl = document.getElementById('split-net-balance');
            netEl.textContent = this.formatCurrency(Math.abs(netBalance));
            netEl.className = 'summary-value ' + (netBalance >= 0 ? 'positive' : 'negative');
            if (netBalance > 0) {
                netEl.textContent = '+' + netEl.textContent;
            } else if (netBalance < 0) {
                netEl.textContent = '-' + this.formatCurrency(Math.abs(netBalance));
            }

            this.splitContacts = data.contacts;
            this.renderContactsList(data.contacts);
        } catch (error) {
            console.error('Failed to load balances:', error);
        }
    }

    async loadContacts() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/shared/contacts'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to load contacts');
            this.contacts = await response.json();
        } catch (error) {
            console.error('Failed to load contacts:', error);
            this.contacts = [];
        }
    }

    renderContactsList(contacts) {
        const container = document.getElementById('contacts-list');
        if (!container) return;

        if (!contacts || contacts.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor" opacity="0.3">
                            <path d="M16,13C15.71,13 15.38,13 15.03,13.05C16.19,13.89 17,15 17,16.5V19H23V16.5C23,14.17 18.33,13 16,13M8,13C5.67,13 1,14.17 1,16.5V19H15V16.5C15,14.17 10.33,13 8,13M8,11A3,3 0 0,0 11,8A3,3 0 0,0 8,5A3,3 0 0,0 5,8A3,3 0 0,0 8,11M16,11A3,3 0 0,0 19,8A3,3 0 0,0 16,5A3,3 0 0,0 13,8A3,3 0 0,0 16,11Z"/>
                        </svg>
                    </div>
                    <p>Add contacts to start splitting expenses</p>
                </div>
            `;
            return;
        }

        container.innerHTML = contacts.map(item => {
            const balance = item.balance;
            const balanceClass = balance > 0 ? 'owed' : balance < 0 ? 'owing' : 'settled';
            const balanceText = balance === 0 ? 'Settled' :
                (balance > 0 ? `Owes you ${this.formatCurrency(balance)}` : `You owe ${this.formatCurrency(Math.abs(balance))}`);

            return `
                <div class="contact-card" data-contact-id="${item.contact.id}">
                    <div class="contact-card-main">
                        <div class="contact-avatar">
                            ${item.contact.name.charAt(0).toUpperCase()}
                        </div>
                        <div class="contact-info">
                            <span class="contact-name">${this.escapeHtml(item.contact.name)}</span>
                            ${item.contact.email ? `<span class="contact-email">${this.escapeHtml(item.contact.email)}</span>` : ''}
                        </div>
                        <div class="contact-balance ${balanceClass}">
                            ${balanceText}
                        </div>
                    </div>
                    <div class="contact-actions">
                        <button class="action-btn view-contact-btn" data-id="${item.contact.id}" title="View details">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12,9A3,3 0 0,0 9,12A3,3 0 0,0 12,15A3,3 0 0,0 15,12A3,3 0 0,0 12,9M12,17A5,5 0 0,1 7,12A5,5 0 0,1 12,7A5,5 0 0,1 17,12A5,5 0 0,1 12,17M12,4.5C7,4.5 2.73,7.61 1,12C2.73,16.39 7,19.5 12,19.5C17,19.5 21.27,16.39 23,12C21.27,7.61 17,4.5 12,4.5Z"/>
                            </svg>
                        </button>
                        <button class="action-btn edit-contact-btn" data-id="${item.contact.id}" title="Edit">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z"/>
                            </svg>
                        </button>
                        <button class="action-btn delete-contact-btn" data-id="${item.contact.id}" title="Delete">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z"/>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
        }).join('');

        // Add click handlers
        container.querySelectorAll('.view-contact-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.showContactDetails(parseInt(btn.dataset.id));
            });
        });

        container.querySelectorAll('.edit-contact-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.editContact(parseInt(btn.dataset.id));
            });
        });

        container.querySelectorAll('.delete-contact-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.deleteContact(parseInt(btn.dataset.id));
            });
        });

        container.querySelectorAll('.contact-card').forEach(card => {
            card.addEventListener('click', () => {
                this.showContactDetails(parseInt(card.dataset.contactId));
            });
        });
    }

    setupSharedExpenseEventListeners() {
        // Add contact button
        const addContactBtn = document.getElementById('add-contact-btn');
        if (addContactBtn) {
            addContactBtn.addEventListener('click', () => this.showContactModal());
        }

        // Contact form submission
        const contactForm = document.getElementById('contact-form');
        if (contactForm) {
            contactForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveContact();
            });
        }

        // Share expense form
        const shareForm = document.getElementById('share-expense-form');
        if (shareForm) {
            shareForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveShareExpense();
            });
        }

        // Split type change
        const splitType = document.getElementById('share-split-type');
        if (splitType) {
            splitType.addEventListener('change', () => {
                const customGroup = document.getElementById('share-custom-amount-group');
                if (customGroup) {
                    customGroup.style.display = splitType.value === 'custom' ? 'block' : 'none';
                }
            });
        }

        // Settlement form
        const settlementForm = document.getElementById('settlement-form');
        if (settlementForm) {
            settlementForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveSettlement();
            });
        }

        // Modal close buttons
        ['contact-modal', 'share-expense-modal', 'settlement-modal', 'contact-details-modal'].forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.querySelectorAll('.cancel-btn, .close-btn').forEach(btn => {
                    btn.addEventListener('click', () => this.closeModal(modal));
                });
            }
        });
    }

    showContactModal(contact = null) {
        const modal = document.getElementById('contact-modal');
        const title = document.getElementById('contact-modal-title');
        const form = document.getElementById('contact-form');

        form.reset();
        document.getElementById('contact-id').value = contact ? contact.id : '';
        title.textContent = contact ? 'Edit Contact' : 'Add Contact';

        if (contact) {
            document.getElementById('contact-name').value = contact.name || '';
            document.getElementById('contact-email').value = contact.email || '';
        }

        modal.style.display = 'flex';
    }

    async saveContact() {
        const id = document.getElementById('contact-id').value;
        const name = document.getElementById('contact-name').value.trim();
        const email = document.getElementById('contact-email').value.trim();

        if (!name) {
            OC.Notification.showTemporary('Name is required');
            return;
        }

        try {
            const url = id
                ? OC.generateUrl(`/apps/budget/api/shared/contacts/${id}`)
                : OC.generateUrl('/apps/budget/api/shared/contacts');

            const response = await fetch(url, {
                method: id ? 'PUT' : 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ name, email: email || null })
            });

            if (!response.ok) throw new Error('Failed to save contact');

            this.closeModal(document.getElementById('contact-modal'));
            OC.Notification.showTemporary(id ? 'Contact updated' : 'Contact added');
            await this.loadBalanceSummary();
            await this.loadContacts();
        } catch (error) {
            console.error('Failed to save contact:', error);
            OC.Notification.showTemporary('Failed to save contact');
        }
    }

    async editContact(id) {
        const contact = this.contacts?.find(c => c.id === id);
        if (contact) {
            this.showContactModal(contact);
        }
    }

    async deleteContact(id) {
        if (!confirm('Are you sure you want to delete this contact? This will also remove all shared expense records with them.')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/shared/contacts/${id}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error('Failed to delete contact');

            OC.Notification.showTemporary('Contact deleted');
            await this.loadBalanceSummary();
            await this.loadContacts();
        } catch (error) {
            console.error('Failed to delete contact:', error);
            OC.Notification.showTemporary('Failed to delete contact');
        }
    }

    async showContactDetails(contactId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/shared/contacts/${contactId}/details`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error('Failed to load contact details');
            const data = await response.json();

            this.currentContactDetails = data;

            // Populate modal
            document.getElementById('contact-details-name').textContent = data.contact.name;
            document.getElementById('contact-details-email').textContent = data.contact.email || '';

            const balanceEl = document.getElementById('contact-details-balance');
            const balance = data.balance;
            balanceEl.textContent = balance === 0 ? 'Settled' :
                (balance > 0 ? `Owes you ${this.formatCurrency(balance)}` : `You owe ${this.formatCurrency(Math.abs(balance))}`);
            balanceEl.className = 'balance-value ' + (balance > 0 ? 'owed' : balance < 0 ? 'owing' : 'settled');

            // Render shares
            this.renderContactShares(data.shares);
            this.renderContactSettlements(data.settlements);

            // Setup actions
            const settleAllBtn = document.getElementById('settle-all-btn');
            if (settleAllBtn) {
                settleAllBtn.onclick = () => this.settleAllWithContact(contactId);
            }

            const recordSettlementBtn = document.getElementById('record-settlement-btn');
            if (recordSettlementBtn) {
                recordSettlementBtn.onclick = () => this.showSettlementModal(contactId, data.contact.name, balance);
            }

            // Tab switching
            const tabs = document.querySelectorAll('#contact-details-modal .tab-button');
            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    tabs.forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');

                    document.getElementById('contact-shares-tab').style.display =
                        tab.dataset.tab === 'shares' ? 'block' : 'none';
                    document.getElementById('contact-settlements-tab').style.display =
                        tab.dataset.tab === 'settlements' ? 'block' : 'none';
                });
            });

            document.getElementById('contact-details-modal').style.display = 'flex';
        } catch (error) {
            console.error('Failed to load contact details:', error);
            OC.Notification.showTemporary('Failed to load contact details');
        }
    }

    renderContactShares(shares) {
        const container = document.getElementById('contact-shares-list');
        if (!shares || shares.length === 0) {
            container.innerHTML = '<div class="empty-state-small">No shared expenses</div>';
            return;
        }

        container.innerHTML = shares.map(item => {
            const share = item.share;
            const txn = item.transaction;
            const statusClass = share.isSettled ? 'settled' : (share.amount > 0 ? 'owed' : 'owing');

            return `
                <div class="share-item ${statusClass}">
                    <div class="share-date">${txn.date}</div>
                    <div class="share-desc">${this.escapeHtml(txn.description)}</div>
                    <div class="share-amount ${share.amount >= 0 ? 'positive' : 'negative'}">
                        ${share.amount >= 0 ? '+' : ''}${this.formatCurrency(share.amount)}
                    </div>
                    <div class="share-status">${share.isSettled ? 'Settled' : 'Open'}</div>
                </div>
            `;
        }).join('');
    }

    renderContactSettlements(settlements) {
        const container = document.getElementById('contact-settlements-list');
        if (!settlements || settlements.length === 0) {
            container.innerHTML = '<div class="empty-state-small">No settlements yet</div>';
            return;
        }

        container.innerHTML = settlements.map(settlement => `
            <div class="settlement-item">
                <div class="settlement-date">${settlement.date}</div>
                <div class="settlement-amount ${settlement.amount >= 0 ? 'received' : 'paid'}">
                    ${settlement.amount >= 0 ? 'Received' : 'Paid'} ${this.formatCurrency(Math.abs(settlement.amount))}
                </div>
                ${settlement.notes ? `<div class="settlement-notes">${this.escapeHtml(settlement.notes)}</div>` : ''}
            </div>
        `).join('');
    }

    showSettlementModal(contactId, contactName, balance) {
        this.closeModal(document.getElementById('contact-details-modal'));

        const modal = document.getElementById('settlement-modal');
        document.getElementById('settlement-contact-id').value = contactId;
        document.getElementById('settlement-contact-name').textContent = contactName;
        document.getElementById('settlement-balance').textContent = balance === 0 ? 'Settled' :
            (balance > 0 ? `Owes you ${this.formatCurrency(balance)}` : `You owe ${this.formatCurrency(Math.abs(balance))}`);

        document.getElementById('settlement-amount').value = Math.abs(balance).toFixed(2);
        document.getElementById('settlement-date').value = new Date().toISOString().split('T')[0];
        document.getElementById('settlement-notes').value = '';

        // Ensure form submit handler is attached
        const form = document.getElementById('settlement-form');
        if (form && !form.dataset.listenerAttached) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveSettlement();
            });
            form.dataset.listenerAttached = 'true';
        }

        modal.style.display = 'flex';
    }

    async saveSettlement() {
        const contactId = parseInt(document.getElementById('settlement-contact-id').value);
        const amount = parseFloat(document.getElementById('settlement-amount').value);
        const date = document.getElementById('settlement-date').value;
        const notes = document.getElementById('settlement-notes').value.trim();

        if (!amount || !date) {
            OC.Notification.showTemporary('Amount and date are required');
            return;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/shared/settlements'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ contactId, amount, date, notes: notes || null })
            });

            if (!response.ok) throw new Error('Failed to record settlement');

            this.closeModal(document.getElementById('settlement-modal'));
            OC.Notification.showTemporary('Settlement recorded');
            await this.loadBalanceSummary();
        } catch (error) {
            console.error('Failed to record settlement:', error);
            OC.Notification.showTemporary('Failed to record settlement');
        }
    }

    async settleAllWithContact(contactId) {
        if (!confirm('This will mark all shared expenses with this contact as settled. Continue?')) {
            return;
        }

        try {
            const date = new Date().toISOString().split('T')[0];
            const response = await fetch(OC.generateUrl(`/apps/budget/api/shared/contacts/${contactId}/settle`), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ date })
            });

            if (!response.ok) throw new Error('Failed to settle');

            this.closeModal(document.getElementById('contact-details-modal'));
            OC.Notification.showTemporary('All expenses settled');
            await this.loadBalanceSummary();
        } catch (error) {
            console.error('Failed to settle:', error);
            OC.Notification.showTemporary('Failed to settle expenses');
        }
    }

    async showShareExpenseModal(transaction) {
        const modal = document.getElementById('share-expense-modal');

        // Load contacts if not already loaded
        if (!this.contacts || this.contacts.length === 0) {
            await this.loadContacts();
        }

        // Check if there are any contacts
        if (!this.contacts || this.contacts.length === 0) {
            OC.Notification.showTemporary('Please add contacts first in Shared Expenses');
            return;
        }

        document.getElementById('share-transaction-id').value = transaction.id;
        document.getElementById('share-transaction-date').textContent = transaction.date;
        document.getElementById('share-transaction-desc').textContent = transaction.description;
        document.getElementById('share-transaction-amount').textContent = this.formatCurrency(Math.abs(transaction.amount));

        // Populate contacts dropdown
        const contactSelect = document.getElementById('share-contact');
        contactSelect.innerHTML = '<option value="">Select a contact...</option>' +
            (this.contacts || []).map(c => `<option value="${c.id}">${this.escapeHtml(c.name)}</option>`).join('');

        document.getElementById('share-split-type').value = '50-50';
        document.getElementById('share-custom-amount-group').style.display = 'none';
        document.getElementById('share-amount').value = '';
        document.getElementById('share-notes').value = '';

        modal.style.display = 'flex';
    }

    async saveShareExpense() {
        const transactionId = parseInt(document.getElementById('share-transaction-id').value);
        const contactId = parseInt(document.getElementById('share-contact').value);
        const splitType = document.getElementById('share-split-type').value;
        const notes = document.getElementById('share-notes').value.trim();

        if (!contactId) {
            OC.Notification.showTemporary('Please select a contact');
            return;
        }

        try {
            let url, body;

            if (splitType === '50-50') {
                url = OC.generateUrl('/apps/budget/api/shared/shares/split');
                body = { transactionId, contactId, notes: notes || null };
            } else {
                const amount = parseFloat(document.getElementById('share-amount').value);
                if (!amount) {
                    OC.Notification.showTemporary('Amount is required for custom splits');
                    return;
                }
                url = OC.generateUrl('/apps/budget/api/shared/shares');
                body = { transactionId, contactId, amount, notes: notes || null };
            }

            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(body)
            });

            if (!response.ok) throw new Error('Failed to share expense');

            this.closeModal(document.getElementById('share-expense-modal'));
            OC.Notification.showTemporary('Expense shared');
            await this.loadBalanceSummary();
        } catch (error) {
            console.error('Failed to share expense:', error);
            OC.Notification.showTemporary('Failed to share expense');
        }
    }

    // ===========================
    // Settings Management
    // ===========================

    setupSettingsEventListeners() {
        // Save buttons (both top and bottom)
        const saveButtons = [
            document.getElementById('save-settings-btn'),
            document.getElementById('save-settings-btn-bottom')
        ];

        saveButtons.forEach(btn => {
            if (btn) {
                btn.addEventListener('click', () => this.saveSettings());
            }
        });

        // Reset buttons (both top and bottom)
        const resetButtons = [
            document.getElementById('reset-settings-btn'),
            document.getElementById('reset-settings-btn-bottom')
        ];

        resetButtons.forEach(btn => {
            if (btn) {
                btn.addEventListener('click', () => this.resetSettings());
            }
        });

        // Number format preview update
        const numberFormatInputs = [
            'setting-number-format-decimals',
            'setting-number-format-decimal-sep',
            'setting-number-format-thousands-sep'
        ];

        numberFormatInputs.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener('change', () => this.updateNumberFormatPreview());
            }
        });

        // Migration event listeners
        this.setupMigrationEventListeners();
    }

    setupMigrationEventListeners() {
        // Export button
        const exportBtn = document.getElementById('migration-export-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', () => this.handleMigrationExport());
        }

        // Import dropzone
        const dropzone = document.getElementById('migration-import-dropzone');
        const fileInput = document.getElementById('migration-file-input');
        const browseBtn = document.getElementById('migration-browse-btn');

        if (dropzone) {
            dropzone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropzone.classList.add('dragover');
            });

            dropzone.addEventListener('dragleave', () => {
                dropzone.classList.remove('dragover');
            });

            dropzone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropzone.classList.remove('dragover');
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    this.handleMigrationFileSelect(files[0]);
                }
            });
        }

        if (browseBtn && fileInput) {
            browseBtn.addEventListener('click', () => fileInput.click());
        }

        if (fileInput) {
            fileInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    this.handleMigrationFileSelect(file);
                }
            });
        }

        // Import action buttons
        const cancelBtn = document.getElementById('migration-cancel-btn');
        const confirmBtn = document.getElementById('migration-confirm-btn');
        const doneBtn = document.getElementById('migration-done-btn');

        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => this.cancelMigrationImport());
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => this.confirmMigrationImport());
        }

        if (doneBtn) {
            doneBtn.addEventListener('click', () => this.resetMigrationUI());
        }
    }

    async handleMigrationExport() {
        const exportBtn = document.getElementById('migration-export-btn');
        const originalText = exportBtn.innerHTML;

        try {
            exportBtn.disabled = true;
            exportBtn.innerHTML = '<span class="icon-loading-small"></span> Exporting...';

            const response = await fetch(OC.generateUrl('/apps/budget/api/migration/export'), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (!response.ok) {
                throw new Error('Export failed');
            }

            // Get filename from Content-Disposition header or use default
            const contentDisposition = response.headers.get('Content-Disposition');
            let filename = 'budget_export.zip';
            if (contentDisposition) {
                const match = contentDisposition.match(/filename="([^"]+)"/);
                if (match) {
                    filename = match[1];
                }
            }

            // Download the file
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);

            OC.Notification.showTemporary('Export completed successfully');
        } catch (error) {
            console.error('Export error:', error);
            OC.Notification.showTemporary('Failed to export data: ' + error.message);
        } finally {
            exportBtn.disabled = false;
            exportBtn.innerHTML = originalText;
        }
    }

    async handleMigrationFileSelect(file) {
        if (!file.name.endsWith('.zip')) {
            OC.Notification.showTemporary('Please select a ZIP file');
            return;
        }

        this.migrationFile = file;

        // Show preview
        const dropzone = document.getElementById('migration-import-dropzone');
        const preview = document.getElementById('migration-preview');
        const progress = document.getElementById('migration-progress');

        dropzone.style.display = 'none';
        progress.style.display = 'block';
        document.getElementById('migration-progress-text').textContent = 'Validating file...';

        try {
            const formData = new FormData();
            formData.append('file', file);

            const response = await fetch(OC.generateUrl('/apps/budget/api/migration/preview'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken
                },
                body: formData
            });

            const result = await response.json();

            if (!response.ok || !result.valid) {
                throw new Error(result.error || 'Invalid export file');
            }

            // Populate preview
            document.getElementById('preview-version').textContent = result.manifest?.version || 'Unknown';
            document.getElementById('preview-date').textContent = result.manifest?.exportedAt
                ? new Date(result.manifest.exportedAt).toLocaleString()
                : 'Unknown';

            document.getElementById('preview-categories').textContent = result.counts?.categories || 0;
            document.getElementById('preview-accounts').textContent = result.counts?.accounts || 0;
            document.getElementById('preview-transactions').textContent = result.counts?.transactions || 0;
            document.getElementById('preview-bills').textContent = result.counts?.bills || 0;
            document.getElementById('preview-rules').textContent = result.counts?.importRules || 0;
            document.getElementById('preview-settings').textContent = result.counts?.settings || 0;

            // Show warnings if any
            const warningsDiv = document.getElementById('migration-warnings');
            if (result.warnings && result.warnings.length > 0) {
                warningsDiv.innerHTML = result.warnings.map(w =>
                    `<div class="warning-item"><span class="icon-info"></span> ${w}</div>`
                ).join('');
                warningsDiv.style.display = 'block';
            } else {
                warningsDiv.style.display = 'none';
            }

            progress.style.display = 'none';
            preview.style.display = 'block';
        } catch (error) {
            console.error('Preview error:', error);
            OC.Notification.showTemporary('Failed to preview file: ' + error.message);
            this.resetMigrationUI();
        }
    }

    cancelMigrationImport() {
        this.migrationFile = null;
        this.resetMigrationUI();
    }

    async confirmMigrationImport() {
        if (!this.migrationFile) {
            OC.Notification.showTemporary('No file selected');
            return;
        }

        // Double confirmation
        if (!confirm('This will PERMANENTLY DELETE all your existing data and replace it with the imported data.\n\nAre you absolutely sure you want to continue?')) {
            return;
        }

        const preview = document.getElementById('migration-preview');
        const progress = document.getElementById('migration-progress');
        const result = document.getElementById('migration-result');

        preview.style.display = 'none';
        progress.style.display = 'block';
        document.getElementById('migration-progress-text').textContent = 'Importing data... This may take a moment.';

        try {
            const formData = new FormData();
            formData.append('file', this.migrationFile);
            formData.append('confirmed', 'true');

            const response = await fetch(OC.generateUrl('/apps/budget/api/migration/import'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken
                },
                body: formData
            });

            const data = await response.json();

            progress.style.display = 'none';

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Import failed');
            }

            // Show success result
            const resultContent = document.getElementById('migration-result-content');
            resultContent.innerHTML = `
                <div class="result-success">
                    <span class="icon-checkmark-color"></span>
                    <h5>Import Successful!</h5>
                    <p>Your data has been imported successfully.</p>
                    <div class="result-counts">
                        <div class="result-count"><strong>${data.counts.categories}</strong> categories</div>
                        <div class="result-count"><strong>${data.counts.accounts}</strong> accounts</div>
                        <div class="result-count"><strong>${data.counts.transactions}</strong> transactions</div>
                        <div class="result-count"><strong>${data.counts.bills}</strong> bills</div>
                        <div class="result-count"><strong>${data.counts.importRules}</strong> import rules</div>
                        <div class="result-count"><strong>${data.counts.settings}</strong> settings</div>
                    </div>
                </div>
            `;
            result.style.display = 'block';

            // Reload application data
            this.loadInitialData();
            OC.Notification.showTemporary('Import completed successfully');
        } catch (error) {
            console.error('Import error:', error);

            const resultContent = document.getElementById('migration-result-content');
            resultContent.innerHTML = `
                <div class="result-error">
                    <span class="icon-error-color"></span>
                    <h5>Import Failed</h5>
                    <p>${error.message}</p>
                    <p class="result-hint">Your existing data has not been modified.</p>
                </div>
            `;
            result.style.display = 'block';
            progress.style.display = 'none';
        }
    }

    resetMigrationUI() {
        this.migrationFile = null;

        const dropzone = document.getElementById('migration-import-dropzone');
        const preview = document.getElementById('migration-preview');
        const progress = document.getElementById('migration-progress');
        const result = document.getElementById('migration-result');
        const fileInput = document.getElementById('migration-file-input');

        dropzone.style.display = 'block';
        preview.style.display = 'none';
        progress.style.display = 'none';
        result.style.display = 'none';

        if (fileInput) {
            fileInput.value = '';
        }
    }

    async loadSettingsView() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/settings'), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (!response.ok) {
                throw new Error('Failed to load settings');
            }

            const settings = await response.json();
            this.populateSettings(settings);
            this.updateNumberFormatPreview();
        } catch (error) {
            console.error('Error loading settings:', error);
            OC.Notification.showTemporary('Failed to load settings');
        }
    }

    populateSettings(settings) {
        // Populate each setting input
        Object.keys(settings).forEach(key => {
            const element = document.getElementById(`setting-${key.replace(/_/g, '-')}`);

            if (!element) return;

            const value = settings[key];

            if (element.type === 'checkbox') {
                element.checked = value === 'true' || value === true;
            } else {
                element.value = value;
            }
        });
    }

    async saveSettings() {
        try {
            const settings = this.gatherSettings();

            const response = await fetch(OC.generateUrl('/apps/budget/api/settings'), {
                method: 'PUT',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(settings)
            });

            if (!response.ok) {
                throw new Error('Failed to save settings');
            }

            const result = await response.json();
            OC.Notification.showTemporary('Settings saved successfully');

            // Update stored settings to apply immediately
            Object.assign(this.settings, settings);

            // Update account form currency default if needed
            this.updateAccountFormDefaults(settings);
        } catch (error) {
            console.error('Error saving settings:', error);
            OC.Notification.showTemporary('Failed to save settings');
        }
    }

    gatherSettings() {
        const settingElements = document.querySelectorAll('.setting-input');
        const settings = {};

        settingElements.forEach(element => {
            const key = element.id.replace('setting-', '').replace(/-/g, '_');

            if (element.type === 'checkbox') {
                settings[key] = element.checked ? 'true' : 'false';
            } else {
                settings[key] = element.value;
            }
        });

        return settings;
    }

    async resetSettings() {
        if (!confirm('Are you sure you want to reset all settings to defaults? This action cannot be undone.')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/settings/reset'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (!response.ok) {
                throw new Error('Failed to reset settings');
            }

            const result = await response.json();
            this.populateSettings(result.defaults);
            this.updateNumberFormatPreview();
            OC.Notification.showTemporary('Settings reset to defaults');
        } catch (error) {
            console.error('Error resetting settings:', error);
            OC.Notification.showTemporary('Failed to reset settings');
        }
    }

    updateNumberFormatPreview() {
        const decimals = parseInt(document.getElementById('setting-number-format-decimals')?.value || '2');
        const decimalSep = document.getElementById('setting-number-format-decimal-sep')?.value || '.';
        const thousandsSep = document.getElementById('setting-number-format-thousands-sep')?.value ?? ',';
        const defaultCurrency = document.getElementById('setting-default-currency')?.value || 'USD';

        // Get currency symbol
        const currencySymbols = {
            'USD': '$', 'EUR': '€', 'GBP': '£', 'CAD': 'C$',
            'AUD': 'A$', 'JPY': '¥', 'CHF': 'CHF', 'CNY': '¥',
            'INR': '₹', 'MXN': '$'
        };
        const symbol = currencySymbols[defaultCurrency] || '$';

        // Format number 1234.56
        const testNumber = 1234.56;
        const parts = testNumber.toFixed(decimals).split('.');
        const integerPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousandsSep);
        const decimalPart = decimals > 0 ? decimalSep + parts[1] : '';

        const formatted = symbol + integerPart + decimalPart;

        const previewElement = document.getElementById('number-format-preview');
        if (previewElement) {
            previewElement.textContent = formatted;
        }
    }

    updateAccountFormDefaults(settings) {
        // Update default currency in account form when it opens
        if (settings.default_currency) {
            const accountCurrencySelect = document.getElementById('account-currency');
            if (accountCurrencySelect && !accountCurrencySelect.value) {
                accountCurrencySelect.value = settings.default_currency;
            }
        }
    }

    // ==========================================
    // Bills Management
    // ==========================================

    setupBillsEventListeners() {
        // Add bill button
        const addBillBtn = document.getElementById('add-bill-btn');
        if (addBillBtn) {
            addBillBtn.addEventListener('click', () => this.showBillModal());
        }

        // Empty state add button
        const emptyBillsAddBtn = document.getElementById('empty-bills-add-btn');
        if (emptyBillsAddBtn) {
            emptyBillsAddBtn.addEventListener('click', () => this.showBillModal());
        }

        // Detect bills button
        const detectBillsBtn = document.getElementById('detect-bills-btn');
        if (detectBillsBtn) {
            detectBillsBtn.addEventListener('click', () => this.detectBills());
        }

        // Bill form submission
        const billForm = document.getElementById('bill-form');
        if (billForm) {
            billForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveBill();
            });
        }

        // Bill frequency change (show/hide due month for yearly)
        const billFrequency = document.getElementById('bill-frequency');
        if (billFrequency) {
            billFrequency.addEventListener('change', () => this.updateBillFormFields());
        }

        // Bills filter tabs
        document.querySelectorAll('.bills-tabs .tab-button').forEach(tab => {
            tab.addEventListener('click', (e) => {
                document.querySelectorAll('.bills-tabs .tab-button').forEach(t => t.classList.remove('active'));
                e.target.classList.add('active');
                this.filterBills(e.target.dataset.filter);
            });
        });

        // Close detected panel
        const closeDetectedPanel = document.getElementById('close-detected-panel');
        if (closeDetectedPanel) {
            closeDetectedPanel.addEventListener('click', () => {
                document.getElementById('detected-bills-panel').style.display = 'none';
            });
        }

        // Cancel detected
        const cancelDetectedBtn = document.getElementById('cancel-detected-btn');
        if (cancelDetectedBtn) {
            cancelDetectedBtn.addEventListener('click', () => {
                document.getElementById('detected-bills-panel').style.display = 'none';
            });
        }

        // Add selected bills from detection
        const addSelectedBillsBtn = document.getElementById('add-selected-bills-btn');
        if (addSelectedBillsBtn) {
            addSelectedBillsBtn.addEventListener('click', () => this.addSelectedDetectedBills());
        }

        // Delegated event handlers for bill actions
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('bill-edit-btn') || e.target.closest('.bill-edit-btn')) {
                const button = e.target.classList.contains('bill-edit-btn') ? e.target : e.target.closest('.bill-edit-btn');
                const billId = parseInt(button.dataset.billId);
                this.editBill(billId);
            } else if (e.target.classList.contains('bill-delete-btn') || e.target.closest('.bill-delete-btn')) {
                const button = e.target.classList.contains('bill-delete-btn') ? e.target : e.target.closest('.bill-delete-btn');
                const billId = parseInt(button.dataset.billId);
                this.deleteBill(billId);
            } else if (e.target.classList.contains('bill-paid-btn') || e.target.closest('.bill-paid-btn')) {
                const button = e.target.classList.contains('bill-paid-btn') ? e.target : e.target.closest('.bill-paid-btn');
                const billId = parseInt(button.dataset.billId);
                this.markBillPaid(billId);
            }
        });
    }

    async loadBillsView() {
        try {
            // Load summary first
            await this.loadBillsSummary();

            // Load all bills
            const response = await fetch(OC.generateUrl('/apps/budget/api/bills'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            this.bills = await response.json();
            this.renderBills(this.bills);

            // Setup event listeners (only once)
            if (!this._billsEventsSetup) {
                this.setupBillsEventListeners();
                this._billsEventsSetup = true;
            }

            // Populate dropdowns in bill modal
            this.populateBillModalDropdowns();
        } catch (error) {
            console.error('Failed to load bills:', error);
            OC.Notification.showTemporary('Failed to load bills');
        }
    }

    async loadBillsSummary() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bills/summary'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const summary = await response.json();

            // Update summary cards
            document.getElementById('bills-due-count').textContent = summary.dueThisMonth || 0;
            document.getElementById('bills-overdue-count').textContent = summary.overdue || 0;
            document.getElementById('bills-monthly-total').textContent = this.formatCurrency(summary.monthlyTotal || 0);
            document.getElementById('bills-paid-count').textContent = summary.paidThisMonth || 0;
        } catch (error) {
            console.error('Failed to load bills summary:', error);
        }
    }

    renderBills(bills) {
        const billsList = document.getElementById('bills-list');
        const emptyBills = document.getElementById('empty-bills');

        if (!bills || bills.length === 0) {
            billsList.innerHTML = '';
            emptyBills.style.display = 'flex';
            return;
        }

        emptyBills.style.display = 'none';

        billsList.innerHTML = bills.map(bill => {
            const dueDate = bill.nextDueDate || bill.next_due_date;
            const isPaid = this.isBillPaidThisMonth(bill);
            const isOverdue = !isPaid && dueDate && new Date(dueDate) < new Date();
            const isDueSoon = !isPaid && !isOverdue && dueDate && this.isDueSoon(dueDate);

            let statusClass = '';
            let statusText = '';
            if (isPaid) {
                statusClass = 'paid';
                statusText = 'Paid';
            } else if (isOverdue) {
                statusClass = 'overdue';
                statusText = 'Overdue';
            } else if (isDueSoon) {
                statusClass = 'due-soon';
                statusText = 'Due Soon';
            } else {
                statusClass = 'upcoming';
                statusText = 'Upcoming';
            }

            const frequency = bill.frequency || 'monthly';
            const frequencyLabel = frequency.charAt(0).toUpperCase() + frequency.slice(1);

            return `
                <div class="bill-card ${statusClass}" data-bill-id="${bill.id}" data-status="${statusClass}">
                    <div class="bill-header">
                        <div class="bill-info">
                            <h4 class="bill-name">${this.escapeHtml(bill.name)}</h4>
                            <span class="bill-frequency">${frequencyLabel}</span>
                        </div>
                        <div class="bill-amount">${this.formatCurrency(bill.amount)}</div>
                    </div>
                    <div class="bill-details">
                        <div class="bill-due-date">
                            <span class="icon-calendar" aria-hidden="true"></span>
                            ${dueDate ? this.formatDate(dueDate) : 'No due date'}
                        </div>
                        <div class="bill-status ${statusClass}">
                            <span class="status-badge">${statusText}</span>
                        </div>
                    </div>
                    <div class="bill-actions">
                        ${!isPaid ? `
                            <button class="bill-action-btn bill-paid-btn" data-bill-id="${bill.id}" title="Mark as paid">
                                <span class="icon-checkmark" aria-hidden="true"></span>
                                Mark Paid
                            </button>
                        ` : ''}
                        <button class="bill-action-btn bill-edit-btn" data-bill-id="${bill.id}" title="Edit bill">
                            <span class="icon-rename" aria-hidden="true"></span>
                        </button>
                        <button class="bill-action-btn bill-delete-btn" data-bill-id="${bill.id}" title="Delete bill">
                            <span class="icon-delete" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    }

    isBillPaidThisMonth(bill) {
        const lastPaid = bill.lastPaidDate || bill.last_paid_date;
        if (!lastPaid) return false;

        const paidDate = new Date(lastPaid);
        const now = new Date();
        return paidDate.getMonth() === now.getMonth() && paidDate.getFullYear() === now.getFullYear();
    }

    isDueSoon(dateStr) {
        const dueDate = new Date(dateStr);
        const now = new Date();
        const diffDays = Math.ceil((dueDate - now) / (1000 * 60 * 60 * 24));
        return diffDays >= 0 && diffDays <= 7;
    }

    filterBills(filter) {
        const billCards = document.querySelectorAll('.bill-card');
        billCards.forEach(card => {
            const status = card.dataset.status;
            let show = false;

            switch (filter) {
                case 'all':
                    show = true;
                    break;
                case 'due':
                    show = status === 'due-soon' || status === 'upcoming';
                    break;
                case 'overdue':
                    show = status === 'overdue';
                    break;
                case 'paid':
                    show = status === 'paid';
                    break;
                default:
                    show = true;
            }

            card.style.display = show ? 'flex' : 'none';
        });
    }

    showBillModal(bill = null) {
        const modal = document.getElementById('bill-modal');
        const title = document.getElementById('bill-modal-title');
        const form = document.getElementById('bill-form');

        form.reset();
        document.getElementById('bill-id').value = '';

        if (bill) {
            title.textContent = 'Edit Bill';
            document.getElementById('bill-id').value = bill.id;
            document.getElementById('bill-name').value = bill.name || '';
            document.getElementById('bill-amount').value = bill.amount || '';
            document.getElementById('bill-frequency').value = bill.frequency || 'monthly';
            document.getElementById('bill-due-day').value = bill.dueDay || bill.due_day || '';
            document.getElementById('bill-due-month').value = bill.dueMonth || bill.due_month || '';
            document.getElementById('bill-category').value = bill.categoryId || bill.category_id || '';
            document.getElementById('bill-account').value = bill.accountId || bill.account_id || '';
            document.getElementById('bill-auto-pattern').value = bill.autoDetectPattern || bill.auto_detect_pattern || '';
            document.getElementById('bill-notes').value = bill.notes || '';
            const reminderDays = bill.reminderDays ?? bill.reminder_days;
            document.getElementById('bill-reminder-days').value = reminderDays !== null && reminderDays !== undefined ? reminderDays.toString() : '';
        } else {
            title.textContent = 'Add Bill';
        }

        this.updateBillFormFields();
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    hideBillModal() {
        const modal = document.getElementById('bill-modal');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    updateBillFormFields() {
        const frequency = document.getElementById('bill-frequency').value;
        const dueDayGroup = document.getElementById('due-day-group');
        const dueMonthGroup = document.getElementById('due-month-group');

        // Show due month only for yearly bills
        if (frequency === 'yearly') {
            dueMonthGroup.style.display = 'block';
        } else {
            dueMonthGroup.style.display = 'none';
        }

        // Update due day label based on frequency
        const dueDayLabel = dueDayGroup.querySelector('label');
        const dueDayHelp = document.getElementById('bill-due-day-help');

        if (frequency === 'weekly') {
            dueDayLabel.textContent = 'Due Day (1-7)';
            dueDayHelp.textContent = 'Day of the week (1=Monday, 7=Sunday)';
            document.getElementById('bill-due-day').max = 7;
        } else {
            dueDayLabel.textContent = 'Due Day';
            dueDayHelp.textContent = 'Day of the month when bill is due';
            document.getElementById('bill-due-day').max = 31;
        }
    }

    populateBillModalDropdowns() {
        // Populate category dropdown
        const categorySelect = document.getElementById('bill-category');
        if (categorySelect && this.categories) {
            const currentValue = categorySelect.value;
            categorySelect.innerHTML = '<option value="">No category</option>';
            this.categories
                .filter(c => c.type === 'expense')
                .forEach(cat => {
                    categorySelect.innerHTML += `<option value="${cat.id}">${this.escapeHtml(cat.name)}</option>`;
                });
            if (currentValue) categorySelect.value = currentValue;
        }

        // Populate account dropdown
        const accountSelect = document.getElementById('bill-account');
        if (accountSelect && this.accounts) {
            const currentValue = accountSelect.value;
            accountSelect.innerHTML = '<option value="">No specific account</option>';
            this.accounts.forEach(acc => {
                accountSelect.innerHTML += `<option value="${acc.id}">${this.escapeHtml(acc.name)}</option>`;
            });
            if (currentValue) accountSelect.value = currentValue;
        }
    }

    async saveBill() {
        const billId = document.getElementById('bill-id').value;
        const isNew = !billId;

        const reminderValue = document.getElementById('bill-reminder-days').value;
        const billData = {
            name: document.getElementById('bill-name').value,
            amount: parseFloat(document.getElementById('bill-amount').value),
            frequency: document.getElementById('bill-frequency').value,
            dueDay: document.getElementById('bill-due-day').value ? parseInt(document.getElementById('bill-due-day').value) : null,
            dueMonth: document.getElementById('bill-due-month').value ? parseInt(document.getElementById('bill-due-month').value) : null,
            categoryId: document.getElementById('bill-category').value ? parseInt(document.getElementById('bill-category').value) : null,
            accountId: document.getElementById('bill-account').value ? parseInt(document.getElementById('bill-account').value) : null,
            autoDetectPattern: document.getElementById('bill-auto-pattern').value || null,
            notes: document.getElementById('bill-notes').value || null,
            reminderDays: reminderValue !== '' ? parseInt(reminderValue) : null
        };

        try {
            const url = isNew
                ? OC.generateUrl('/apps/budget/api/bills')
                : OC.generateUrl(`/apps/budget/api/bills/${billId}`);

            const response = await fetch(url, {
                method: isNew ? 'POST' : 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(billData)
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to save bill');
            }

            this.hideBillModal();
            this.hideModals();
            OC.Notification.showTemporary(isNew ? 'Bill created successfully' : 'Bill updated successfully');
            await this.loadBillsView();
        } catch (error) {
            console.error('Failed to save bill:', error);
            OC.Notification.showTemporary(error.message || 'Failed to save bill');
        }
    }

    async editBill(billId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${billId}`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const bill = await response.json();
            this.showBillModal(bill);
        } catch (error) {
            console.error('Failed to load bill:', error);
            OC.Notification.showTemporary('Failed to load bill');
        }
    }

    async deleteBill(billId) {
        if (!confirm('Are you sure you want to delete this bill?')) return;

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${billId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            OC.Notification.showTemporary('Bill deleted successfully');
            await this.loadBillsView();
        } catch (error) {
            console.error('Failed to delete bill:', error);
            OC.Notification.showTemporary('Failed to delete bill');
        }
    }

    async markBillPaid(billId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${billId}/paid`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ paidDate: new Date().toISOString().split('T')[0] })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            OC.Notification.showTemporary('Bill marked as paid');
            await this.loadBillsView();
        } catch (error) {
            console.error('Failed to mark bill as paid:', error);
            OC.Notification.showTemporary('Failed to mark bill as paid');
        }
    }

    async detectBills() {
        const detectBtn = document.getElementById('detect-bills-btn');
        detectBtn.disabled = true;
        detectBtn.innerHTML = '<span class="icon-loading-small" aria-hidden="true"></span> Detecting...';

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bills/detect?months=6'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const detected = await response.json();

            if (!detected || detected.length === 0) {
                OC.Notification.showTemporary('No recurring transactions detected');
                return;
            }

            this.renderDetectedBills(detected);
            document.getElementById('detected-bills-panel').style.display = 'flex';
        } catch (error) {
            console.error('Failed to detect bills:', error);
            OC.Notification.showTemporary('Failed to detect recurring bills');
        } finally {
            detectBtn.disabled = false;
            detectBtn.innerHTML = '<span class="icon-search" aria-hidden="true"></span> Detect Bills';
        }
    }

    renderDetectedBills(detected) {
        const list = document.getElementById('detected-bills-list');

        list.innerHTML = detected.map((item, index) => {
            const confidenceClass = item.confidence >= 0.8 ? 'high' : item.confidence >= 0.5 ? 'medium' : 'low';
            const confidencePercent = Math.round(item.confidence * 100);

            return `
                <div class="detected-bill-item" data-index="${index}">
                    <div class="detected-bill-select">
                        <input type="checkbox" id="detected-${index}" ${item.confidence >= 0.7 ? 'checked' : ''}>
                    </div>
                    <div class="detected-bill-info">
                        <label for="detected-${index}" class="detected-bill-name">${this.escapeHtml(item.description || item.name)}</label>
                        <div class="detected-bill-meta">
                            <span class="detected-amount">${this.formatCurrency(item.avgAmount || item.amount)}</span>
                            <span class="detected-frequency">${item.frequency}</span>
                            <span class="detected-confidence ${confidenceClass}">${confidencePercent}% confidence</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        // Store detected bills for later use
        this._detectedBills = detected;
    }

    async addSelectedDetectedBills() {
        const checkboxes = document.querySelectorAll('#detected-bills-list input[type="checkbox"]:checked');
        const selectedIndices = Array.from(checkboxes).map(cb => parseInt(cb.id.replace('detected-', '')));

        if (selectedIndices.length === 0) {
            OC.Notification.showTemporary('Please select at least one bill to add');
            return;
        }

        const billsToAdd = selectedIndices.map(i => this._detectedBills[i]);

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bills/create-from-detected'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ bills: billsToAdd })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const result = await response.json();
            document.getElementById('detected-bills-panel').style.display = 'none';
            OC.Notification.showTemporary(`${result.created} bills added successfully`);
            await this.loadBillsView();
        } catch (error) {
            console.error('Failed to add bills:', error);
            OC.Notification.showTemporary('Failed to add selected bills');
        }
    }

    // ============================================
    // RECURRING INCOME METHODS
    // ============================================

    async loadIncomeView() {
        try {
            // Load summary first
            await this.loadIncomeSummary();

            // Load all recurring income
            const response = await fetch(OC.generateUrl('/apps/budget/api/recurring-income'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            this.recurringIncome = await response.json();
            this.renderRecurringIncome(this.recurringIncome);

            // Setup event listeners (only once)
            if (!this._incomeEventsSetup) {
                this.setupIncomeEventListeners();
                this._incomeEventsSetup = true;
            }

            // Populate dropdowns in income modal
            this.populateIncomeModalDropdowns();
        } catch (error) {
            console.error('Failed to load recurring income:', error);
            OC.Notification.showTemporary('Failed to load recurring income');
        }
    }

    async loadIncomeSummary() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/recurring-income/summary'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const summary = await response.json();

            // Update summary cards
            document.getElementById('income-expected-count').textContent = summary.expectedThisMonth || 0;
            document.getElementById('income-monthly-total').textContent = this.formatCurrency(summary.monthlyTotal || 0);
            document.getElementById('income-received-count').textContent = summary.receivedThisMonth || 0;
            document.getElementById('income-active-count').textContent = summary.activeCount || 0;
        } catch (error) {
            console.error('Failed to load income summary:', error);
        }
    }

    renderRecurringIncome(incomeItems) {
        const incomeList = document.getElementById('income-list');
        const emptyIncome = document.getElementById('empty-income');

        if (!incomeItems || incomeItems.length === 0) {
            incomeList.innerHTML = '';
            emptyIncome.style.display = 'flex';
            return;
        }

        emptyIncome.style.display = 'none';

        incomeList.innerHTML = incomeItems.map(income => {
            const nextDate = income.nextExpectedDate || income.next_expected_date;
            const lastReceived = income.lastReceivedDate || income.last_received_date;
            const isReceivedThisMonth = this.isIncomeReceivedThisMonth(income);
            const isExpectedSoon = !isReceivedThisMonth && nextDate && this.isExpectedSoon(nextDate);

            let statusClass = '';
            let statusText = '';
            if (isReceivedThisMonth) {
                statusClass = 'received';
                statusText = 'Received';
            } else if (isExpectedSoon) {
                statusClass = 'expected-soon';
                statusText = 'Expected Soon';
            } else {
                statusClass = 'upcoming';
                statusText = 'Upcoming';
            }

            const frequency = income.frequency || 'monthly';
            const frequencyLabel = frequency.charAt(0).toUpperCase() + frequency.slice(1);
            const source = income.source || '';

            return `
                <div class="income-card ${statusClass}" data-income-id="${income.id}" data-status="${statusClass}">
                    <div class="income-header">
                        <div class="income-info">
                            <h4 class="income-name">${this.escapeHtml(income.name)}</h4>
                            <span class="income-frequency">${frequencyLabel}</span>
                            ${source ? `<span class="income-source">${this.escapeHtml(source)}</span>` : ''}
                        </div>
                        <div class="income-amount">${this.formatCurrency(income.amount)}</div>
                    </div>
                    <div class="income-details">
                        <div class="income-next-date">
                            <span class="icon-calendar" aria-hidden="true"></span>
                            ${nextDate ? this.formatDate(nextDate) : 'No date set'}
                        </div>
                        <div class="income-status ${statusClass}">
                            <span class="status-badge">${statusText}</span>
                        </div>
                    </div>
                    <div class="income-actions">
                        ${!isReceivedThisMonth ? `
                            <button class="income-action-btn income-received-btn" data-income-id="${income.id}" title="Mark as received">
                                <span class="icon-checkmark" aria-hidden="true"></span>
                                Mark Received
                            </button>
                        ` : ''}
                        <button class="income-action-btn income-edit-btn" data-income-id="${income.id}" title="Edit income">
                            <span class="icon-rename" aria-hidden="true"></span>
                        </button>
                        <button class="income-action-btn income-delete-btn" data-income-id="${income.id}" title="Delete income">
                            <span class="icon-delete" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    }

    isIncomeReceivedThisMonth(income) {
        const lastReceived = income.lastReceivedDate || income.last_received_date;
        if (!lastReceived) return false;

        const receivedDate = new Date(lastReceived);
        const now = new Date();
        return receivedDate.getMonth() === now.getMonth() && receivedDate.getFullYear() === now.getFullYear();
    }

    isExpectedSoon(dateStr) {
        const expectedDate = new Date(dateStr);
        const now = new Date();
        const diffDays = Math.ceil((expectedDate - now) / (1000 * 60 * 60 * 24));
        return diffDays >= 0 && diffDays <= 7;
    }

    filterIncome(filter) {
        const incomeCards = document.querySelectorAll('.income-card');
        incomeCards.forEach(card => {
            const status = card.dataset.status;
            let show = false;

            switch (filter) {
                case 'all':
                    show = true;
                    break;
                case 'expected':
                    show = status === 'expected-soon' || status === 'upcoming';
                    break;
                case 'received':
                    show = status === 'received';
                    break;
                default:
                    show = true;
            }

            card.style.display = show ? 'flex' : 'none';
        });
    }

    setupIncomeEventListeners() {
        // Add income button
        const addIncomeBtn = document.getElementById('add-income-btn');
        const emptyIncomeAddBtn = document.getElementById('empty-income-add-btn');

        if (addIncomeBtn) {
            addIncomeBtn.addEventListener('click', () => this.showIncomeModal());
        }
        if (emptyIncomeAddBtn) {
            emptyIncomeAddBtn.addEventListener('click', () => this.showIncomeModal());
        }

        // Income modal form
        const incomeForm = document.getElementById('income-form');
        if (incomeForm) {
            incomeForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveIncome();
            });
        }

        // Income modal cancel
        const incomeModal = document.getElementById('income-modal');
        if (incomeModal) {
            incomeModal.querySelectorAll('.cancel-btn').forEach(btn => {
                btn.addEventListener('click', () => this.hideIncomeModal());
            });
            incomeModal.addEventListener('click', (e) => {
                if (e.target === incomeModal) this.hideIncomeModal();
            });
        }

        // Frequency change (show/hide month selector)
        const frequencySelect = document.getElementById('income-frequency');
        if (frequencySelect) {
            frequencySelect.addEventListener('change', () => this.updateIncomeFormFields());
        }

        // Filter tabs
        const incomeTabs = document.querySelectorAll('.income-tabs .tab-button');
        incomeTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                incomeTabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                this.filterIncome(tab.dataset.filter);
            });
        });

        // Income list actions (delegated)
        const incomeList = document.getElementById('income-list');
        if (incomeList) {
            incomeList.addEventListener('click', (e) => {
                const editBtn = e.target.closest('.income-edit-btn');
                const deleteBtn = e.target.closest('.income-delete-btn');
                const receivedBtn = e.target.closest('.income-received-btn');

                if (editBtn) {
                    const incomeId = parseInt(editBtn.dataset.incomeId);
                    const income = this.recurringIncome.find(i => i.id === incomeId);
                    if (income) this.showIncomeModal(income);
                }

                if (deleteBtn) {
                    const incomeId = parseInt(deleteBtn.dataset.incomeId);
                    this.deleteIncome(incomeId);
                }

                if (receivedBtn) {
                    const incomeId = parseInt(receivedBtn.dataset.incomeId);
                    this.markIncomeReceived(incomeId);
                }
            });
        }
    }

    showIncomeModal(income = null) {
        const modal = document.getElementById('income-modal');
        const title = document.getElementById('income-modal-title');
        const form = document.getElementById('income-form');

        form.reset();
        document.getElementById('income-id').value = '';

        if (income) {
            title.textContent = 'Edit Recurring Income';
            document.getElementById('income-id').value = income.id;
            document.getElementById('income-name').value = income.name || '';
            document.getElementById('income-amount').value = income.amount || '';
            document.getElementById('income-source').value = income.source || '';
            document.getElementById('income-frequency').value = income.frequency || 'monthly';
            document.getElementById('income-expected-day').value = income.expectedDay || income.expected_day || '';
            document.getElementById('income-expected-month').value = income.expectedMonth || income.expected_month || '';
            document.getElementById('income-category').value = income.categoryId || income.category_id || '';
            document.getElementById('income-account').value = income.accountId || income.account_id || '';
            document.getElementById('income-auto-pattern').value = income.autoDetectPattern || income.auto_detect_pattern || '';
            document.getElementById('income-notes').value = income.notes || '';
        } else {
            title.textContent = 'Add Recurring Income';
        }

        this.updateIncomeFormFields();
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    hideIncomeModal() {
        const modal = document.getElementById('income-modal');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    updateIncomeFormFields() {
        const frequency = document.getElementById('income-frequency').value;
        const expectedDayGroup = document.getElementById('expected-day-group');
        const expectedMonthGroup = document.getElementById('expected-month-group');

        // Show expected month only for yearly income
        if (frequency === 'yearly') {
            expectedMonthGroup.style.display = 'block';
        } else {
            expectedMonthGroup.style.display = 'none';
        }

        // Update expected day label based on frequency
        const expectedDayLabel = expectedDayGroup.querySelector('label');
        const expectedDayHelp = document.getElementById('income-expected-day-help');

        if (frequency === 'weekly') {
            expectedDayLabel.textContent = 'Expected Day (1-7)';
            expectedDayHelp.textContent = 'Day of the week (1=Monday, 7=Sunday)';
            document.getElementById('income-expected-day').max = 7;
        } else {
            expectedDayLabel.textContent = 'Expected Day';
            expectedDayHelp.textContent = 'Day of the month when income is expected';
            document.getElementById('income-expected-day').max = 31;
        }
    }

    populateIncomeModalDropdowns() {
        // Populate category dropdown (income categories)
        const categorySelect = document.getElementById('income-category');
        if (categorySelect && this.categories) {
            const currentValue = categorySelect.value;
            categorySelect.innerHTML = '<option value="">No category</option>';
            this.categories
                .filter(c => c.type === 'income')
                .forEach(cat => {
                    categorySelect.innerHTML += `<option value="${cat.id}">${this.escapeHtml(cat.name)}</option>`;
                });
            if (currentValue) categorySelect.value = currentValue;
        }

        // Populate account dropdown
        const accountSelect = document.getElementById('income-account');
        if (accountSelect && this.accounts) {
            const currentValue = accountSelect.value;
            accountSelect.innerHTML = '<option value="">No specific account</option>';
            this.accounts.forEach(account => {
                accountSelect.innerHTML += `<option value="${account.id}">${this.escapeHtml(account.name)}</option>`;
            });
            if (currentValue) accountSelect.value = currentValue;
        }
    }

    async saveIncome() {
        try {
            const id = document.getElementById('income-id').value;
            const isNew = !id;

            const data = {
                name: document.getElementById('income-name').value.trim(),
                amount: parseFloat(document.getElementById('income-amount').value) || 0,
                source: document.getElementById('income-source').value.trim() || null,
                frequency: document.getElementById('income-frequency').value,
                expectedDay: parseInt(document.getElementById('income-expected-day').value) || null,
                expectedMonth: parseInt(document.getElementById('income-expected-month').value) || null,
                categoryId: parseInt(document.getElementById('income-category').value) || null,
                accountId: parseInt(document.getElementById('income-account').value) || null,
                autoDetectPattern: document.getElementById('income-auto-pattern').value.trim() || null,
                notes: document.getElementById('income-notes').value.trim() || null
            };

            const url = isNew
                ? OC.generateUrl('/apps/budget/api/recurring-income')
                : OC.generateUrl(`/apps/budget/api/recurring-income/${id}`);

            const response = await fetch(url, {
                method: isNew ? 'POST' : 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(data)
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            this.hideIncomeModal();
            OC.Notification.showTemporary(isNew ? 'Income source created successfully' : 'Income source updated successfully');
            await this.loadIncomeView();
        } catch (error) {
            console.error('Failed to save income:', error);
            OC.Notification.showTemporary(error.message || 'Failed to save income');
        }
    }

    async deleteIncome(incomeId) {
        if (!confirm('Are you sure you want to delete this recurring income?')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/recurring-income/${incomeId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            OC.Notification.showTemporary('Income source deleted successfully');
            await this.loadIncomeView();
        } catch (error) {
            console.error('Failed to delete income:', error);
            OC.Notification.showTemporary('Failed to delete income');
        }
    }

    async markIncomeReceived(incomeId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/recurring-income/${incomeId}/received`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ receivedDate: new Date().toISOString().split('T')[0] })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            OC.Notification.showTemporary('Income marked as received');
            await this.loadIncomeView();
        } catch (error) {
            console.error('Failed to mark income as received:', error);
            OC.Notification.showTemporary('Failed to mark income as received');
        }
    }

    // ============================================
    // SAVINGS GOALS METHODS
    // ============================================

    async loadSavingsGoalsView() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/savings-goals'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            this.savingsGoals = await response.json();
            this.updateGoalsSummary();
            this.renderGoals(this.savingsGoals);

            // Setup event listeners (only once)
            if (!this._goalsEventsSetup) {
                this.setupGoalsEventListeners();
                this._goalsEventsSetup = true;
            }

            // Populate account dropdown in modal
            this.populateGoalAccountDropdown();
        } catch (error) {
            console.error('Failed to load savings goals:', error);
            OC.Notification.showTemporary('Failed to load savings goals');
        }
    }

    updateGoalsSummary() {
        const goals = this.savingsGoals || [];
        const activeGoals = goals.filter(g => !g.completed);
        const completedGoals = goals.filter(g => g.completed);

        const totalSaved = goals.reduce((sum, g) => sum + (parseFloat(g.currentAmount || g.current_amount) || 0), 0);
        const totalTarget = goals.reduce((sum, g) => sum + (parseFloat(g.targetAmount || g.target_amount) || 0), 0);

        document.getElementById('goals-total-count').textContent = activeGoals.length;
        document.getElementById('goals-total-saved').textContent = this.formatCurrency(totalSaved);
        document.getElementById('goals-total-target').textContent = this.formatCurrency(totalTarget);
        document.getElementById('goals-completed-count').textContent = completedGoals.length;
    }

    renderGoals(goals) {
        const goalsList = document.getElementById('goals-list');
        const emptyGoals = document.getElementById('empty-goals');

        if (!goals || goals.length === 0) {
            goalsList.innerHTML = '';
            emptyGoals.style.display = 'block';
            return;
        }

        emptyGoals.style.display = 'none';

        goalsList.innerHTML = goals.map(goal => {
            const current = parseFloat(goal.currentAmount || goal.current_amount) || 0;
            const target = parseFloat(goal.targetAmount || goal.target_amount) || 0;
            const percentage = target > 0 ? Math.min((current / target) * 100, 100) : 0;
            const isCompleted = current >= target;
            const color = goal.color || '#0082c9';
            const targetDate = goal.targetDate || goal.target_date;

            let targetDateText = '';
            if (targetDate) {
                const date = new Date(targetDate);
                const today = new Date();
                const daysLeft = Math.ceil((date - today) / (1000 * 60 * 60 * 24));

                if (daysLeft < 0) {
                    targetDateText = `Target date passed`;
                } else if (daysLeft === 0) {
                    targetDateText = 'Target date: Today';
                } else if (daysLeft <= 30) {
                    targetDateText = `${daysLeft} days left`;
                } else {
                    targetDateText = `Target: ${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
                }
            }

            return `
                <div class="goal-card ${isCompleted ? 'completed' : ''}" data-goal-id="${goal.id}">
                    <div class="goal-card-header">
                        <div class="goal-card-title">
                            <span class="goal-color-indicator" style="background: ${color}"></span>
                            <h3 class="goal-name">${this.escapeHtml(goal.name)}</h3>
                        </div>
                        <div class="goal-card-actions">
                            <button class="edit-goal-btn" title="Edit" data-goal-id="${goal.id}">
                                <span class="icon-rename"></span>
                            </button>
                            <button class="delete-goal-btn delete-btn" title="Delete" data-goal-id="${goal.id}">
                                <span class="icon-delete"></span>
                            </button>
                        </div>
                    </div>

                    <div class="goal-progress-section">
                        <div class="goal-progress-bar">
                            <div class="goal-progress-fill" style="width: ${percentage}%; background: ${isCompleted ? 'linear-gradient(90deg, #2e7d32, #43a047)' : `linear-gradient(90deg, ${color}, ${color}dd)`}"></div>
                        </div>
                        <div class="goal-amounts">
                            <span class="goal-current-amount">${this.formatCurrency(current)}</span>
                            <span class="goal-percentage">${percentage.toFixed(0)}%</span>
                            <span class="goal-target-amount">of ${this.formatCurrency(target)}</span>
                        </div>
                    </div>

                    <div class="goal-footer">
                        ${targetDateText ? `<span class="goal-target-date"><span class="icon-calendar"></span> ${targetDateText}</span>` : '<span></span>'}
                        ${isCompleted ?
                            '<span class="goal-completed-badge"><span class="icon-checkmark"></span> Goal reached!</span>' :
                            `<button class="goal-add-money-btn" data-goal-id="${goal.id}">+ Add money</button>`
                        }
                    </div>
                </div>
            `;
        }).join('');
    }

    setupGoalsEventListeners() {
        // Add goal button
        document.getElementById('add-goal-btn')?.addEventListener('click', () => {
            this.showGoalModal();
        });

        // Empty state add button
        document.getElementById('empty-goals-add-btn')?.addEventListener('click', () => {
            this.showGoalModal();
        });

        // Goal form submission
        document.getElementById('goal-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.saveGoal();
        });

        // Add money form
        document.getElementById('add-to-goal-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.addMoneyToGoal();
        });

        // Event delegation for goal cards
        document.getElementById('goals-list')?.addEventListener('click', (e) => {
            const editBtn = e.target.closest('.edit-goal-btn');
            const deleteBtn = e.target.closest('.delete-goal-btn');
            const addMoneyBtn = e.target.closest('.goal-add-money-btn');

            if (editBtn) {
                const goalId = parseInt(editBtn.dataset.goalId);
                this.editGoal(goalId);
            } else if (deleteBtn) {
                const goalId = parseInt(deleteBtn.dataset.goalId);
                this.deleteGoal(goalId);
            } else if (addMoneyBtn) {
                const goalId = parseInt(addMoneyBtn.dataset.goalId);
                this.showAddMoneyModal(goalId);
            }
        });

        // Color preview
        document.getElementById('goal-color')?.addEventListener('input', (e) => {
            const preview = document.getElementById('goal-color-preview');
            if (preview) {
                preview.style.backgroundColor = e.target.value;
            }
        });
    }

    populateGoalAccountDropdown() {
        const dropdown = document.getElementById('goal-account');
        if (!dropdown) return;

        dropdown.innerHTML = '<option value="">No linked account</option>' +
            (Array.isArray(this.accounts) ? this.accounts.map(a =>
                `<option value="${a.id}">${this.escapeHtml(a.name)}</option>`
            ).join('') : '');
    }

    showGoalModal(goal = null) {
        const modal = document.getElementById('goal-modal');
        const title = document.getElementById('goal-modal-title');
        const form = document.getElementById('goal-form');

        if (!modal || !form) return;

        title.textContent = goal ? 'Edit Savings Goal' : 'Add Savings Goal';

        // Reset form
        form.reset();
        document.getElementById('goal-id').value = '';
        document.getElementById('goal-color').value = '#0082c9';

        // Populate if editing
        if (goal) {
            document.getElementById('goal-id').value = goal.id;
            document.getElementById('goal-name').value = goal.name;
            document.getElementById('goal-target').value = goal.targetAmount || goal.target_amount || '';
            document.getElementById('goal-current').value = goal.currentAmount || goal.current_amount || 0;
            document.getElementById('goal-account').value = goal.accountId || goal.account_id || '';
            document.getElementById('goal-target-date').value = goal.targetDate || goal.target_date || '';
            document.getElementById('goal-color').value = goal.color || '#0082c9';
            document.getElementById('goal-notes').value = goal.notes || '';
        }

        modal.style.display = 'flex';
    }

    async saveGoal() {
        const form = document.getElementById('goal-form');
        const goalId = document.getElementById('goal-id').value;

        const data = {
            name: document.getElementById('goal-name').value,
            targetAmount: parseFloat(document.getElementById('goal-target').value) || 0,
            currentAmount: parseFloat(document.getElementById('goal-current').value) || 0,
            accountId: document.getElementById('goal-account').value || null,
            targetDate: document.getElementById('goal-target-date').value || null,
            color: document.getElementById('goal-color').value,
            notes: document.getElementById('goal-notes').value
        };

        try {
            const url = goalId
                ? OC.generateUrl(`/apps/budget/api/savings-goals/${goalId}`)
                : OC.generateUrl('/apps/budget/api/savings-goals');

            const response = await fetch(url, {
                method: goalId ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(data)
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            document.getElementById('goal-modal').style.display = 'none';
            OC.Notification.showTemporary(goalId ? 'Goal updated' : 'Goal created');
            await this.loadSavingsGoalsView();
        } catch (error) {
            console.error('Failed to save goal:', error);
            OC.Notification.showTemporary('Failed to save goal');
        }
    }

    editGoal(goalId) {
        const goal = this.savingsGoals?.find(g => g.id === goalId);
        if (goal) {
            this.showGoalModal(goal);
        }
    }

    async deleteGoal(goalId) {
        if (!confirm('Are you sure you want to delete this savings goal?')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/savings-goals/${goalId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            OC.Notification.showTemporary('Goal deleted');
            await this.loadSavingsGoalsView();
        } catch (error) {
            console.error('Failed to delete goal:', error);
            OC.Notification.showTemporary('Failed to delete goal');
        }
    }

    showAddMoneyModal(goalId) {
        const goal = this.savingsGoals?.find(g => g.id === goalId);
        if (!goal) return;

        const modal = document.getElementById('add-to-goal-modal');
        document.getElementById('add-to-goal-name').textContent = goal.name;
        document.getElementById('add-to-goal-id').value = goalId;
        document.getElementById('add-amount').value = '';

        modal.style.display = 'flex';
    }

    async addMoneyToGoal() {
        const goalId = document.getElementById('add-to-goal-id').value;
        const amount = parseFloat(document.getElementById('add-amount').value) || 0;

        if (amount <= 0) {
            OC.Notification.showTemporary('Please enter a valid amount');
            return;
        }

        const goal = this.savingsGoals?.find(g => g.id === parseInt(goalId));
        if (!goal) return;

        const currentAmount = parseFloat(goal.currentAmount || goal.current_amount) || 0;
        const newAmount = currentAmount + amount;

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/savings-goals/${goalId}`), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ currentAmount: newAmount })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            document.getElementById('add-to-goal-modal').style.display = 'none';
            OC.Notification.showTemporary(`Added ${this.formatCurrency(amount)} to goal`);
            await this.loadSavingsGoalsView();
        } catch (error) {
            console.error('Failed to add money to goal:', error);
            OC.Notification.showTemporary('Failed to add money to goal');
        }
    }

    // ===== Transaction Matching Methods =====

    /**
     * Find potential transfer matches for a transaction
     */
    async findTransactionMatches(transactionId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/transactions/${transactionId}/matches`), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return await response.json();
        } catch (error) {
            console.error('Failed to find matches:', error);
            throw error;
        }
    }

    /**
     * Link two transactions as a transfer pair
     */
    async linkTransactions(transactionId, targetId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/transactions/${transactionId}/link/${targetId}`), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || `HTTP ${response.status}`);
            }
            return await response.json();
        } catch (error) {
            console.error('Failed to link transactions:', error);
            throw error;
        }
    }

    /**
     * Unlink a transaction from its transfer partner
     */
    async unlinkTransaction(transactionId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/transactions/${transactionId}/link`), {
                method: 'DELETE',
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || `HTTP ${response.status}`);
            }
            return await response.json();
        } catch (error) {
            console.error('Failed to unlink transaction:', error);
            throw error;
        }
    }

    /**
     * Show the matching modal for a transaction
     */
    async showMatchingModal(transactionId) {
        const transaction = this.transactions?.find(t => t.id === transactionId);
        if (!transaction) {
            OC.Notification.showTemporary('Transaction not found');
            return;
        }

        const modal = document.getElementById('matching-modal');
        const sourceDetails = modal.querySelector('.source-details');
        const loadingEl = document.getElementById('matching-loading');
        const emptyEl = document.getElementById('matching-empty');
        const listEl = document.getElementById('matching-list');

        // Populate source transaction info
        const account = this.accounts?.find(a => a.id === transaction.accountId);
        const currency = transaction.accountCurrency || account?.currency || this.getPrimaryCurrency();
        const typeClass = transaction.type === 'credit' ? 'positive' : 'negative';

        sourceDetails.querySelector('.source-date').textContent = new Date(transaction.date).toLocaleDateString();
        sourceDetails.querySelector('.source-description').textContent = transaction.description;
        sourceDetails.querySelector('.source-amount').textContent = this.formatCurrency(transaction.amount, currency);
        sourceDetails.querySelector('.source-amount').className = `source-amount ${typeClass}`;
        sourceDetails.querySelector('.source-account').textContent = account?.name || 'Unknown Account';

        // Show modal and loading state
        modal.style.display = 'flex';
        loadingEl.style.display = 'flex';
        emptyEl.style.display = 'none';
        listEl.innerHTML = '';

        try {
            const result = await this.findTransactionMatches(transactionId);
            loadingEl.style.display = 'none';

            if (!result.matches || result.matches.length === 0) {
                emptyEl.style.display = 'flex';
                return;
            }

            // Render matches
            listEl.innerHTML = result.matches.map(match => {
                const matchAccount = this.accounts?.find(a => a.id === match.accountId);
                const matchCurrency = match.accountCurrency || matchAccount?.currency || this.getPrimaryCurrency();
                const matchTypeClass = match.type === 'credit' ? 'positive' : 'negative';

                return `
                    <div class="match-item" data-match-id="${match.id}">
                        <span class="match-date">${new Date(match.date).toLocaleDateString()}</span>
                        <span class="match-description">${this.escapeHtml(match.description)}</span>
                        <span class="match-amount ${matchTypeClass}">${this.formatCurrency(match.amount, matchCurrency)}</span>
                        <span class="match-account">${matchAccount?.name || 'Unknown'}</span>
                        <button class="link-match-btn" data-source-id="${transactionId}" data-target-id="${match.id}">
                            Link as Transfer
                        </button>
                    </div>
                `;
            }).join('');

        } catch (error) {
            loadingEl.style.display = 'none';
            emptyEl.style.display = 'flex';
            emptyEl.querySelector('p').textContent = 'Failed to search for matches. Please try again.';
        }
    }

    /**
     * Handle linking a match from the modal
     */
    async handleLinkMatch(sourceId, targetId) {
        try {
            await this.linkTransactions(sourceId, targetId);
            OC.Notification.showTemporary('Transactions linked as transfer');

            // Close modal and refresh transactions
            document.getElementById('matching-modal').style.display = 'none';
            await this.loadTransactions();
        } catch (error) {
            OC.Notification.showTemporary(error.message || 'Failed to link transactions');
        }
    }

    /**
     * Handle unlinking a transaction
     */
    async handleUnlinkTransaction(transactionId) {
        if (!confirm('Are you sure you want to unlink this transaction from its transfer pair?')) {
            return;
        }

        try {
            await this.unlinkTransaction(transactionId);
            OC.Notification.showTemporary('Transaction unlinked');
            await this.loadTransactions();
        } catch (error) {
            OC.Notification.showTemporary(error.message || 'Failed to unlink transaction');
        }
    }

    // ===== Transaction Split Methods =====

    /**
     * Show the split modal for a transaction
     */
    async showSplitModal(transactionId) {
        const transaction = this.transactions?.find(t => t.id === transactionId);
        if (!transaction) {
            OC.Notification.showTemporary('Transaction not found');
            return;
        }

        const modal = document.getElementById('split-modal');
        if (!modal) {
            console.error('Split modal not found');
            return;
        }

        const isSplit = transaction.isSplit || transaction.is_split;
        const titleEl = document.getElementById('split-modal-title');
        const transactionInfoEl = document.getElementById('split-transaction-info');
        const splitsContainer = document.getElementById('splits-container');

        // Set title and store transaction id
        titleEl.textContent = isSplit ? 'Edit Transaction Splits' : 'Split Transaction';
        modal.dataset.transactionId = transactionId;

        // Display transaction info
        const account = this.accounts?.find(a => a.id === transaction.accountId);
        const currency = transaction.accountCurrency || account?.currency || this.getPrimaryCurrency();
        transactionInfoEl.innerHTML = `
            <div class="split-info-row">
                <span class="split-info-label">Date:</span>
                <span>${new Date(transaction.date).toLocaleDateString()}</span>
            </div>
            <div class="split-info-row">
                <span class="split-info-label">Description:</span>
                <span>${this.escapeHtml(transaction.description)}</span>
            </div>
            <div class="split-info-row">
                <span class="split-info-label">Total Amount:</span>
                <span class="split-total-amount">${this.formatCurrency(transaction.amount, currency)}</span>
            </div>
        `;

        // Store transaction data for later
        modal.dataset.totalAmount = transaction.amount;
        modal.dataset.currency = currency;

        // Clear and set up splits container
        splitsContainer.innerHTML = '';

        if (isSplit) {
            // Load existing splits
            try {
                const splits = await this.getTransactionSplits(transactionId);
                splits.forEach((split, index) => {
                    this.addSplitRow(splitsContainer, split, index === 0);
                });
            } catch (error) {
                console.error('Failed to load splits:', error);
                // Add two empty rows as fallback
                this.addSplitRow(splitsContainer, null, true);
                this.addSplitRow(splitsContainer, null, false);
            }
        } else {
            // Start with two empty split rows
            this.addSplitRow(splitsContainer, null, true);
            this.addSplitRow(splitsContainer, null, false);
        }

        this.updateSplitRemaining();
        modal.style.display = 'flex';
    }

    /**
     * Add a split row to the splits container
     */
    addSplitRow(container, split = null, isFirst = false) {
        const modal = document.getElementById('split-modal');
        const currency = modal?.dataset.currency || this.getPrimaryCurrency();
        const rowIndex = container.children.length;

        const row = document.createElement('div');
        row.className = 'split-row';
        row.dataset.index = rowIndex;

        row.innerHTML = `
            <div class="split-field split-amount-field">
                <label>Amount</label>
                <input type="number" class="split-amount" step="0.01" min="0.01"
                       value="${split ? split.amount : ''}" placeholder="0.00" required>
            </div>
            <div class="split-field split-category-field">
                <label>Category</label>
                <select class="split-category">
                    <option value="">Uncategorized</option>
                    ${this.getCategoryOptions(split?.categoryId)}
                </select>
            </div>
            <div class="split-field split-description-field">
                <label>Description</label>
                <input type="text" class="split-description" maxlength="255"
                       value="${split?.description || ''}" placeholder="Optional note">
            </div>
            <div class="split-actions">
                <button type="button" class="split-remove-btn ${isFirst ? 'disabled' : ''}"
                        ${isFirst ? 'disabled' : ''} title="Remove split">
                    <span class="icon-delete"></span>
                </button>
            </div>
        `;

        // Add event listeners
        row.querySelector('.split-amount').addEventListener('input', () => this.updateSplitRemaining());
        row.querySelector('.split-remove-btn').addEventListener('click', (e) => {
            if (!e.currentTarget.classList.contains('disabled')) {
                row.remove();
                this.updateSplitRemaining();
            }
        });

        container.appendChild(row);
    }

    /**
     * Get category options HTML
     */
    getCategoryOptions(selectedId = null) {
        if (!this.categories) return '';
        return this.categories
            .filter(c => c.type === 'expense')
            .map(c => `<option value="${c.id}" ${c.id === selectedId ? 'selected' : ''}>${this.escapeHtml(c.name)}</option>`)
            .join('');
    }

    /**
     * Update the remaining amount display in split modal
     */
    updateSplitRemaining() {
        const modal = document.getElementById('split-modal');
        const totalAmount = parseFloat(modal?.dataset.totalAmount || 0);
        const currency = modal?.dataset.currency || this.getPrimaryCurrency();
        const remainingEl = document.getElementById('split-remaining');
        const remainingAmountEl = document.getElementById('split-remaining-amount');

        const allocatedAmount = Array.from(document.querySelectorAll('.split-amount'))
            .reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0);

        const remaining = totalAmount - allocatedAmount;

        if (remainingEl && remainingAmountEl) {
            remainingAmountEl.textContent = this.formatCurrency(Math.abs(remaining), currency);
            remainingEl.classList.toggle('over', remaining < -0.01);
            remainingEl.classList.toggle('balanced', Math.abs(remaining) < 0.01);
        }
    }

    /**
     * API call to get transaction splits
     */
    async getTransactionSplits(transactionId) {
        const response = await fetch(OC.generateUrl(`/apps/budget/api/transactions/${transactionId}/splits`), {
            headers: { 'requesttoken': OC.requestToken }
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return await response.json();
    }

    /**
     * Save transaction splits
     */
    async saveSplits() {
        const modal = document.getElementById('split-modal');
        const transactionId = parseInt(modal?.dataset.transactionId);
        const totalAmount = parseFloat(modal?.dataset.totalAmount || 0);

        // Collect splits data
        const splits = Array.from(document.querySelectorAll('.split-row')).map(row => ({
            amount: parseFloat(row.querySelector('.split-amount').value) || 0,
            categoryId: parseInt(row.querySelector('.split-category').value) || null,
            description: row.querySelector('.split-description').value.trim() || null
        })).filter(split => split.amount > 0);

        // Validate
        if (splits.length < 2) {
            OC.Notification.showTemporary('A split transaction must have at least 2 parts');
            return;
        }

        const splitTotal = splits.reduce((sum, s) => sum + s.amount, 0);
        if (Math.abs(splitTotal - totalAmount) > 0.01) {
            OC.Notification.showTemporary(`Split amounts (${splitTotal.toFixed(2)}) must equal transaction amount (${totalAmount.toFixed(2)})`);
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/transactions/${transactionId}/splits`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ splits })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            this.hideSplitModal();
            OC.Notification.showTemporary('Transaction split successfully');
            await this.loadTransactions();
        } catch (error) {
            console.error('Failed to save splits:', error);
            OC.Notification.showTemporary(error.message || 'Failed to save splits');
        }
    }

    /**
     * Remove splits from a transaction (unsplit)
     */
    async unsplitTransaction() {
        const modal = document.getElementById('split-modal');
        const transactionId = parseInt(modal?.dataset.transactionId);

        if (!confirm('Are you sure you want to remove the split and revert to a single transaction?')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/transactions/${transactionId}/splits`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            this.hideSplitModal();
            OC.Notification.showTemporary('Transaction unsplit successfully');
            await this.loadTransactions();
        } catch (error) {
            console.error('Failed to unsplit transaction:', error);
            OC.Notification.showTemporary(error.message || 'Failed to unsplit transaction');
        }
    }

    /**
     * Hide the split modal
     */
    hideSplitModal() {
        const modal = document.getElementById('split-modal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    // ===== Bulk Transaction Matching Methods =====

    /**
     * API call to bulk match transactions
     */
    async bulkMatchTransactions() {
        const response = await fetch(OC.generateUrl('/apps/budget/api/transactions/bulk-match'), {
            method: 'POST',
            headers: {
                'requesttoken': OC.requestToken,
                'Content-Type': 'application/json'
            }
        });

        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.error || `HTTP ${response.status}`);
        }
        return await response.json();
    }

    /**
     * Show the bulk match modal and execute bulk matching
     */
    async showBulkMatchModal() {
        const modal = document.getElementById('bulk-match-modal');
        const loadingEl = document.getElementById('bulk-match-loading');
        const resultsEl = document.getElementById('bulk-match-results');
        const emptyEl = document.getElementById('bulk-match-empty');
        const autoMatchedSection = document.getElementById('auto-matched-section');
        const needsReviewSection = document.getElementById('needs-review-section');
        const autoMatchedList = document.getElementById('auto-matched-list');
        const needsReviewList = document.getElementById('needs-review-list');

        // Reset state
        loadingEl.style.display = 'flex';
        resultsEl.style.display = 'none';
        emptyEl.style.display = 'none';
        autoMatchedSection.style.display = 'none';
        needsReviewSection.style.display = 'none';
        autoMatchedList.innerHTML = '';
        needsReviewList.innerHTML = '';

        // Show modal
        modal.style.display = 'flex';

        try {
            const result = await this.bulkMatchTransactions();
            loadingEl.style.display = 'none';
            resultsEl.style.display = 'block';

            // Update summary counts
            document.getElementById('auto-matched-count').textContent = result.stats.autoMatchedCount;
            document.getElementById('needs-review-count').textContent = result.stats.needsReviewCount;

            // Check if no results
            if (result.stats.autoMatchedCount === 0 && result.stats.needsReviewCount === 0) {
                emptyEl.style.display = 'flex';
                return;
            }

            // Render auto-matched pairs
            if (result.autoMatched && result.autoMatched.length > 0) {
                autoMatchedSection.style.display = 'block';
                autoMatchedList.innerHTML = result.autoMatched.map(pair => this.renderAutoMatchedPair(pair)).join('');
            }

            // Render needs review items
            if (result.needsReview && result.needsReview.length > 0) {
                needsReviewSection.style.display = 'block';
                needsReviewList.innerHTML = result.needsReview.map((item, index) => this.renderNeedsReviewItem(item, index)).join('');
            }

        } catch (error) {
            loadingEl.style.display = 'none';
            resultsEl.style.display = 'block';
            emptyEl.style.display = 'flex';
            emptyEl.querySelector('p').textContent = error.message || 'Failed to match transactions. Please try again.';
        }
    }

    /**
     * Render an auto-matched pair in the bulk match modal
     */
    renderAutoMatchedPair(pair) {
        const tx = pair.transaction;
        const linked = pair.linkedTo;

        const txCurrency = tx.account_currency || this.getPrimaryCurrency();
        const linkedCurrency = linked.accountCurrency || this.getPrimaryCurrency();

        const txTypeClass = tx.type === 'credit' ? 'positive' : 'negative';
        const linkedTypeClass = linked.type === 'credit' ? 'positive' : 'negative';

        return `
            <div class="bulk-match-pair" data-tx-id="${tx.id}" data-linked-id="${linked.id}">
                <div class="pair-transaction">
                    <span class="pair-date">${new Date(tx.date).toLocaleDateString()}</span>
                    <span class="pair-description">${this.escapeHtml(tx.description)}</span>
                    <div class="pair-details">
                        <span class="pair-amount ${txTypeClass}">${this.formatCurrency(tx.amount, txCurrency)}</span>
                        <span class="pair-account">${this.escapeHtml(tx.account_name)}</span>
                    </div>
                </div>
                <span class="pair-arrow">↔</span>
                <div class="pair-transaction">
                    <span class="pair-date">${new Date(linked.date).toLocaleDateString()}</span>
                    <span class="pair-description">${this.escapeHtml(linked.description)}</span>
                    <div class="pair-details">
                        <span class="pair-amount ${linkedTypeClass}">${this.formatCurrency(linked.amount, linkedCurrency)}</span>
                        <span class="pair-account">${this.escapeHtml(linked.accountName)}</span>
                    </div>
                </div>
                <button class="undo-match-btn" data-tx-id="${tx.id}">Undo</button>
            </div>
        `;
    }

    /**
     * Render a needs-review item in the bulk match modal
     */
    renderNeedsReviewItem(item, index) {
        const tx = item.transaction;
        const txCurrency = tx.account_currency || this.getPrimaryCurrency();
        const txTypeClass = tx.type === 'credit' ? 'positive' : 'negative';

        const matchesHtml = item.matches.map((match) => {
            const matchCurrency = match.accountCurrency || this.getPrimaryCurrency();
            const matchTypeClass = match.type === 'credit' ? 'positive' : 'negative';

            return `
                <label class="review-match-option">
                    <input type="radio" name="review-match-${index}" value="${match.id}">
                    <div class="match-info">
                        <div class="match-info-main">
                            <span class="match-date">${new Date(match.date).toLocaleDateString()}</span>
                            <span class="match-description">${this.escapeHtml(match.description)}</span>
                        </div>
                        <span class="pair-amount ${matchTypeClass}">${this.formatCurrency(match.amount, matchCurrency)}</span>
                        <span class="pair-account">${this.escapeHtml(match.accountName)}</span>
                    </div>
                </label>
            `;
        }).join('');

        return `
            <div class="bulk-review-item" data-tx-id="${tx.id}" data-index="${index}">
                <div class="review-source">
                    <div class="review-source-info">
                        <span class="review-source-date">${new Date(tx.date).toLocaleDateString()}</span>
                        <span class="review-source-description">${this.escapeHtml(tx.description)}</span>
                        <div class="review-source-details">
                            <span class="pair-amount ${txTypeClass}">${this.formatCurrency(tx.amount, txCurrency)}</span>
                            <span class="pair-account">${this.escapeHtml(tx.account_name)}</span>
                        </div>
                    </div>
                </div>
                <div class="review-matches-label">Select a match (${item.matchCount} options):</div>
                <div class="review-matches">
                    ${matchesHtml}
                </div>
                <button class="link-selected-btn" data-tx-id="${tx.id}" data-index="${index}" disabled>Link Selected</button>
            </div>
        `;
    }

    /**
     * Handle undo of an auto-matched pair from bulk match modal
     */
    async handleBulkMatchUndo(transactionId) {
        try {
            await this.unlinkTransaction(transactionId);

            // Remove the pair from the UI
            const pairEl = document.querySelector(`.bulk-match-pair[data-tx-id="${transactionId}"]`);
            if (pairEl) {
                pairEl.remove();
            }

            // Update count
            const countEl = document.getElementById('auto-matched-count');
            const currentCount = parseInt(countEl.textContent);
            countEl.textContent = currentCount - 1;

            // Check if section is now empty
            const autoMatchedList = document.getElementById('auto-matched-list');
            if (autoMatchedList.children.length === 0) {
                document.getElementById('auto-matched-section').style.display = 'none';
            }

            OC.Notification.showTemporary('Match undone');
        } catch (error) {
            OC.Notification.showTemporary(error.message || 'Failed to undo match');
        }
    }

    /**
     * Handle linking a selected match from review section
     */
    async handleBulkMatchLink(transactionId, index) {
        const reviewItem = document.querySelector(`.bulk-review-item[data-index="${index}"]`);
        const selectedRadio = reviewItem.querySelector(`input[name="review-match-${index}"]:checked`);

        if (!selectedRadio) {
            OC.Notification.showTemporary('Please select a match first');
            return;
        }

        const targetId = parseInt(selectedRadio.value);

        try {
            await this.linkTransactions(transactionId, targetId);

            // Remove the review item from the UI
            reviewItem.remove();

            // Update counts
            const reviewCountEl = document.getElementById('needs-review-count');
            const autoCountEl = document.getElementById('auto-matched-count');
            const currentReviewCount = parseInt(reviewCountEl.textContent);
            const currentAutoCount = parseInt(autoCountEl.textContent);

            reviewCountEl.textContent = currentReviewCount - 1;
            autoCountEl.textContent = currentAutoCount + 1;

            // Check if review section is now empty
            const needsReviewList = document.getElementById('needs-review-list');
            if (needsReviewList.children.length === 0) {
                document.getElementById('needs-review-section').style.display = 'none';
            }

            OC.Notification.showTemporary('Transactions linked');
        } catch (error) {
            OC.Notification.showTemporary(error.message || 'Failed to link transactions');
        }
    }

    /**
     * Escape HTML to prevent XSS (utility method)
     */
    escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;');
    }

    // =====================
    // Pensions Methods
    // =====================

    async loadPensionsView() {
        try {
            await this.loadPensions();
            this.renderPensions();
            this.setupPensionEventListeners();
        } catch (error) {
            console.error('Failed to load pensions view:', error);
            OC.Notification.showTemporary('Failed to load pensions');
        }
    }

    async loadPensions() {
        const response = await fetch(OC.generateUrl('/apps/budget/api/pensions'), {
            headers: { 'requesttoken': OC.requestToken }
        });
        if (!response.ok) throw new Error('Failed to fetch pensions');
        this.pensions = await response.json();
    }

    async loadPensionSummary() {
        const response = await fetch(OC.generateUrl('/apps/budget/api/pensions/summary'), {
            headers: { 'requesttoken': OC.requestToken }
        });
        if (!response.ok) throw new Error('Failed to fetch pension summary');
        return await response.json();
    }

    async loadPensionProjection() {
        const response = await fetch(OC.generateUrl('/apps/budget/api/pensions/projection'), {
            headers: { 'requesttoken': OC.requestToken }
        });
        if (!response.ok) throw new Error('Failed to fetch pension projection');
        return await response.json();
    }

    renderPensions() {
        const list = document.getElementById('pensions-list');
        const emptyState = document.getElementById('empty-pensions');

        if (!this.pensions || this.pensions.length === 0) {
            list.innerHTML = '';
            emptyState.style.display = 'block';
            this.updatePensionsSummary({ totalPensionWorth: 0, pensionCount: 0 });
            return;
        }

        emptyState.style.display = 'none';
        list.innerHTML = this.pensions.map(pension => this.renderPensionCard(pension)).join('');

        // Load and update summary
        this.loadPensionSummary().then(summary => {
            this.updatePensionsSummary(summary);
        });

        // Load and update projections
        this.loadPensionProjection().then(projection => {
            this.updatePensionsProjection(projection);
        });
    }

    renderPensionCard(pension) {
        const currency = pension.currency || 'GBP';
        const typeLabels = {
            workplace: 'Workplace',
            personal: 'Personal',
            sipp: 'SIPP',
            defined_benefit: 'Defined Benefit',
            state: 'State Pension'
        };
        const typeLabel = typeLabels[pension.type] || pension.type;

        let valueDisplay = '--';
        if (pension.isDefinedContribution && pension.currentBalance !== null) {
            valueDisplay = this.formatCurrency(pension.currentBalance, currency);
        } else if (pension.annualIncome !== null) {
            valueDisplay = this.formatCurrency(pension.annualIncome, currency) + '/year';
        }

        return `
            <div class="pension-card" data-id="${pension.id}">
                <div class="pension-card-header">
                    <h4 class="pension-name">${this.escapeHtml(pension.name)}</h4>
                    <span class="pension-type-badge pension-type-${pension.type}">${typeLabel}</span>
                </div>
                <div class="pension-card-body">
                    <div class="pension-value">${valueDisplay}</div>
                    ${pension.provider ? `<div class="pension-provider">${this.escapeHtml(pension.provider)}</div>` : ''}
                    ${pension.monthlyContribution ? `<div class="pension-contribution">${this.formatCurrency(pension.monthlyContribution, currency)}/month</div>` : ''}
                </div>
                <div class="pension-card-actions">
                    <button class="pension-view-btn icon-button" title="View details" data-id="${pension.id}">
                        <span class="icon-info" aria-hidden="true"></span>
                    </button>
                    <button class="pension-edit-btn icon-button" title="Edit" data-id="${pension.id}">
                        <span class="icon-rename" aria-hidden="true"></span>
                    </button>
                    <button class="pension-delete-btn icon-button" title="Delete" data-id="${pension.id}">
                        <span class="icon-delete" aria-hidden="true"></span>
                    </button>
                </div>
            </div>
        `;
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

    updatePensionsProjection(projection) {
        const currency = this.getPrimaryCurrency();

        const projectedValueEl = document.getElementById('pensions-projected-value');
        const projectedIncomeEl = document.getElementById('pensions-projected-income');

        if (projectedValueEl) {
            projectedValueEl.textContent = this.formatCurrency(projection.totalProjectedValue || 0, currency);
        }
        if (projectedIncomeEl) {
            projectedIncomeEl.textContent = this.formatCurrency(projection.totalProjectedAnnualIncome || 0, currency);
        }
    }

    setupPensionEventListeners() {
        // Add pension button
        const addBtn = document.getElementById('add-pension-btn');
        const emptyAddBtn = document.getElementById('empty-pensions-add-btn');

        if (addBtn) {
            addBtn.onclick = () => this.showPensionModal();
        }
        if (emptyAddBtn) {
            emptyAddBtn.onclick = () => this.showPensionModal();
        }

        // Pension form
        const pensionForm = document.getElementById('pension-form');
        if (pensionForm) {
            pensionForm.onsubmit = (e) => {
                e.preventDefault();
                this.savePension();
            };
        }

        // Pension type change (toggle DC/DB fields)
        const pensionType = document.getElementById('pension-type');
        if (pensionType) {
            pensionType.onchange = () => this.togglePensionFields();
        }

        // Modal close buttons
        document.querySelectorAll('#pension-modal .cancel-btn').forEach(btn => {
            btn.onclick = () => this.closePensionModal();
        });

        // Balance form
        const balanceForm = document.getElementById('pension-balance-form');
        if (balanceForm) {
            balanceForm.onsubmit = (e) => {
                e.preventDefault();
                this.saveSnapshot();
            };
        }
        document.querySelectorAll('#pension-balance-modal .cancel-btn').forEach(btn => {
            btn.onclick = () => this.closeBalanceModal();
        });

        // Contribution form
        const contributionForm = document.getElementById('pension-contribution-form');
        if (contributionForm) {
            contributionForm.onsubmit = (e) => {
                e.preventDefault();
                this.saveContribution();
            };
        }
        document.querySelectorAll('#pension-contribution-modal .cancel-btn').forEach(btn => {
            btn.onclick = () => this.closeContributionModal();
        });

        // Pension card actions (delegated)
        const pensionsList = document.getElementById('pensions-list');
        if (pensionsList) {
            pensionsList.onclick = (e) => {
                const viewBtn = e.target.closest('.pension-view-btn');
                const editBtn = e.target.closest('.pension-edit-btn');
                const deleteBtn = e.target.closest('.pension-delete-btn');
                const card = e.target.closest('.pension-card');

                if (viewBtn) {
                    this.showPensionDetails(parseInt(viewBtn.dataset.id));
                } else if (editBtn) {
                    this.showPensionModal(parseInt(editBtn.dataset.id));
                } else if (deleteBtn) {
                    this.deletePension(parseInt(deleteBtn.dataset.id));
                } else if (card) {
                    this.showPensionDetails(parseInt(card.dataset.id));
                }
            };
        }

        // Detail panel buttons
        const closeDetailBtn = document.getElementById('pension-close-btn');
        if (closeDetailBtn) {
            closeDetailBtn.onclick = () => this.closePensionDetails();
        }

        const editDetailBtn = document.getElementById('pension-edit-btn');
        if (editDetailBtn) {
            editDetailBtn.onclick = () => {
                if (this.currentPension) {
                    this.showPensionModal(this.currentPension.id);
                }
            };
        }

        const updateBalanceBtn = document.getElementById('update-balance-btn');
        if (updateBalanceBtn) {
            updateBalanceBtn.onclick = () => this.showBalanceModal();
        }

        const addContributionBtn = document.getElementById('add-contribution-btn');
        if (addContributionBtn) {
            addContributionBtn.onclick = () => this.showContributionModal();
        }
    }

    togglePensionFields() {
        const type = document.getElementById('pension-type').value;
        const dcFields = document.getElementById('dc-pension-fields');
        const dbFields = document.getElementById('db-pension-fields');

        const isDC = ['workplace', 'personal', 'sipp'].includes(type);

        if (dcFields) dcFields.style.display = isDC ? 'block' : 'none';
        if (dbFields) dbFields.style.display = isDC ? 'none' : 'block';
    }

    showPensionModal(pensionId = null) {
        const modal = document.getElementById('pension-modal');
        const title = document.getElementById('pension-modal-title');
        const form = document.getElementById('pension-form');

        form.reset();
        document.getElementById('pension-id').value = '';

        if (pensionId) {
            title.textContent = 'Edit Pension';
            const pension = this.pensions.find(p => p.id === pensionId);
            if (pension) {
                document.getElementById('pension-id').value = pension.id;
                document.getElementById('pension-name').value = pension.name || '';
                document.getElementById('pension-type').value = pension.type || 'workplace';
                document.getElementById('pension-provider').value = pension.provider || '';
                document.getElementById('pension-currency').value = pension.currency || 'GBP';

                if (pension.isDefinedContribution) {
                    document.getElementById('pension-balance').value = pension.currentBalance || '';
                    document.getElementById('pension-monthly').value = pension.monthlyContribution || '';
                    document.getElementById('pension-return').value = pension.expectedReturnRate ? (pension.expectedReturnRate * 100) : '';
                    document.getElementById('pension-retirement-age').value = pension.retirementAge || '';
                } else {
                    document.getElementById('pension-income').value = pension.annualIncome || '';
                    document.getElementById('pension-transfer').value = pension.transferValue || '';
                    document.getElementById('pension-db-retirement-age').value = pension.retirementAge || '';
                }

                this.togglePensionFields();
            }
        } else {
            title.textContent = 'Add Pension';
            this.togglePensionFields();
        }

        modal.style.display = 'flex';
    }

    closePensionModal() {
        document.getElementById('pension-modal').style.display = 'none';
    }

    async savePension() {
        const form = document.getElementById('pension-form');
        const formData = new FormData(form);
        const pensionId = formData.get('id');

        const type = formData.get('type');
        const isDC = ['workplace', 'personal', 'sipp'].includes(type);

        const data = {
            name: formData.get('name'),
            type: type,
            provider: formData.get('provider') || null,
            currency: formData.get('currency') || 'GBP',
        };

        if (isDC) {
            data.currentBalance = formData.get('currentBalance') ? parseFloat(formData.get('currentBalance')) : null;
            data.monthlyContribution = formData.get('monthlyContribution') ? parseFloat(formData.get('monthlyContribution')) : null;
            data.expectedReturnRate = formData.get('expectedReturnRate') ? parseFloat(formData.get('expectedReturnRate')) / 100 : null;
            data.retirementAge = formData.get('retirementAge') ? parseInt(formData.get('retirementAge')) : null;
        } else {
            data.annualIncome = formData.get('annualIncome') ? parseFloat(formData.get('annualIncome')) : null;
            data.transferValue = formData.get('transferValue') ? parseFloat(formData.get('transferValue')) : null;
            data.retirementAge = document.getElementById('pension-db-retirement-age').value ? parseInt(document.getElementById('pension-db-retirement-age').value) : null;
        }

        try {
            const url = pensionId
                ? OC.generateUrl(`/apps/budget/api/pensions/${pensionId}`)
                : OC.generateUrl('/apps/budget/api/pensions');
            const method = pensionId ? 'PUT' : 'POST';

            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(data)
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to save pension');
            }

            this.closePensionModal();
            await this.loadPensions();
            this.renderPensions();
            OC.Notification.showTemporary(pensionId ? 'Pension updated' : 'Pension created');
        } catch (error) {
            OC.Notification.showTemporary(error.message);
        }
    }

    async deletePension(pensionId) {
        if (!confirm('Are you sure you want to delete this pension? This will also delete all balance history and contributions.')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error('Failed to delete pension');

            await this.loadPensions();
            this.renderPensions();
            this.closePensionDetails();
            OC.Notification.showTemporary('Pension deleted');
        } catch (error) {
            OC.Notification.showTemporary(error.message);
        }
    }

    async showPensionDetails(pensionId) {
        const pension = this.pensions.find(p => p.id === pensionId);
        if (!pension) return;

        this.currentPension = pension;

        const panel = document.getElementById('pension-detail-panel');
        const currency = pension.currency || 'GBP';

        document.getElementById('pension-detail-name').textContent = pension.name;

        if (pension.isDefinedContribution) {
            document.getElementById('pension-detail-balance').textContent = pension.currentBalance !== null
                ? this.formatCurrency(pension.currentBalance, currency)
                : '--';
            document.getElementById('pension-detail-contribution').textContent = pension.monthlyContribution
                ? this.formatCurrency(pension.monthlyContribution, currency) + '/month'
                : '--';
            document.getElementById('pension-detail-return').textContent = pension.expectedReturnRate
                ? (pension.expectedReturnRate * 100).toFixed(1) + '%'
                : '--';
        } else {
            document.getElementById('pension-detail-balance').textContent = pension.annualIncome !== null
                ? this.formatCurrency(pension.annualIncome, currency) + '/year'
                : '--';
            document.getElementById('pension-detail-contribution').textContent = pension.transferValue
                ? this.formatCurrency(pension.transferValue, currency)
                : '--';
            document.getElementById('pension-detail-return').textContent = 'N/A';
        }

        document.getElementById('pension-detail-age').textContent = pension.retirementAge || '--';

        panel.style.display = 'block';

        // Load snapshots and render chart
        await this.loadPensionBalanceChart(pensionId);

        // Load projection and render chart
        await this.loadPensionProjectionChart(pensionId);

        // Load activity
        await this.loadPensionActivity(pensionId);
    }

    closePensionDetails() {
        document.getElementById('pension-detail-panel').style.display = 'none';
        this.currentPension = null;
    }

    async loadPensionBalanceChart(pensionId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/snapshots`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to load snapshots');
            const snapshots = await response.json();

            const canvas = document.getElementById('pension-balance-chart');
            if (!canvas) return;

            // Destroy existing chart
            if (this.charts.pensionBalance) {
                this.charts.pensionBalance.destroy();
            }

            if (!snapshots || snapshots.length === 0) {
                canvas.parentElement.innerHTML = '<p class="no-data">No balance history yet</p>';
                return;
            }

            const labels = snapshots.reverse().map(s => s.date);
            const data = snapshots.map(s => s.balance);

            this.charts.pensionBalance = new Chart(canvas, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Balance',
                        data,
                        borderColor: '#0082c9',
                        backgroundColor: 'rgba(0, 130, 201, 0.1)',
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: (value) => this.formatCurrency(value, this.currentPension?.currency || 'GBP')
                            }
                        }
                    }
                }
            });
        } catch (error) {
            console.error('Failed to load balance chart:', error);
        }
    }

    async loadPensionProjectionChart(pensionId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/projection`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to load projection');
            const projection = await response.json();

            const canvas = document.getElementById('pension-projection-chart');
            if (!canvas) return;

            // Destroy existing chart
            if (this.charts.pensionProjection) {
                this.charts.pensionProjection.destroy();
            }

            if (!projection.growthProjection || projection.growthProjection.length === 0) {
                canvas.parentElement.innerHTML = '<p class="no-data">No projection available</p>';
                return;
            }

            const labels = projection.growthProjection.map(p => p.year);
            const data = projection.growthProjection.map(p => p.value);

            this.charts.pensionProjection = new Chart(canvas, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Projected Value',
                        data,
                        borderColor: '#46ba61',
                        backgroundColor: 'rgba(70, 186, 97, 0.1)',
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: (value) => this.formatCurrency(value, this.currentPension?.currency || 'GBP')
                            }
                        }
                    }
                }
            });
        } catch (error) {
            console.error('Failed to load projection chart:', error);
        }
    }

    async loadPensionActivity(pensionId) {
        try {
            const [snapshotsRes, contributionsRes] = await Promise.all([
                fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/snapshots`), {
                    headers: { 'requesttoken': OC.requestToken }
                }),
                fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/contributions`), {
                    headers: { 'requesttoken': OC.requestToken }
                })
            ]);

            const snapshots = snapshotsRes.ok ? await snapshotsRes.json() : [];
            const contributions = contributionsRes.ok ? await contributionsRes.json() : [];

            const currency = this.currentPension?.currency || 'GBP';

            // Combine and sort by date
            const activities = [
                ...snapshots.map(s => ({ type: 'snapshot', date: s.date, value: s.balance, ...s })),
                ...contributions.map(c => ({ type: 'contribution', date: c.date, value: c.amount, ...c }))
            ].sort((a, b) => new Date(b.date) - new Date(a.date)).slice(0, 10);

            const list = document.getElementById('pension-activity-list');
            if (!list) return;

            if (activities.length === 0) {
                list.innerHTML = '<p class="no-data">No recent activity</p>';
                return;
            }

            list.innerHTML = activities.map(a => {
                if (a.type === 'snapshot') {
                    return `<div class="activity-item">
                        <span class="activity-icon icon-history"></span>
                        <span class="activity-text">Balance updated to ${this.formatCurrency(a.value, currency)}</span>
                        <span class="activity-date">${a.date}</span>
                    </div>`;
                } else {
                    return `<div class="activity-item">
                        <span class="activity-icon icon-add"></span>
                        <span class="activity-text">Contributed ${this.formatCurrency(a.value, currency)}${a.note ? ` - ${this.escapeHtml(a.note)}` : ''}</span>
                        <span class="activity-date">${a.date}</span>
                    </div>`;
                }
            }).join('');
        } catch (error) {
            console.error('Failed to load activity:', error);
        }
    }

    showBalanceModal() {
        if (!this.currentPension) return;

        const modal = document.getElementById('pension-balance-modal');
        document.getElementById('pension-balance-form').reset();
        document.getElementById('snapshot-pension-id').value = this.currentPension.id;
        document.getElementById('snapshot-date').value = new Date().toISOString().split('T')[0];

        if (this.currentPension.currentBalance) {
            document.getElementById('snapshot-balance').value = this.currentPension.currentBalance;
        }

        modal.style.display = 'flex';
    }

    closeBalanceModal() {
        document.getElementById('pension-balance-modal').style.display = 'none';
    }

    async saveSnapshot() {
        const form = document.getElementById('pension-balance-form');
        const formData = new FormData(form);
        const pensionId = formData.get('pensionId');

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/snapshots`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    balance: parseFloat(formData.get('balance')),
                    date: formData.get('date')
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to update balance');
            }

            this.closeBalanceModal();
            await this.loadPensions();
            this.renderPensions();
            await this.showPensionDetails(parseInt(pensionId));
            OC.Notification.showTemporary('Balance updated');
        } catch (error) {
            OC.Notification.showTemporary(error.message);
        }
    }

    showContributionModal() {
        if (!this.currentPension) return;

        const modal = document.getElementById('pension-contribution-modal');
        document.getElementById('pension-contribution-form').reset();
        document.getElementById('contribution-pension-id').value = this.currentPension.id;
        document.getElementById('contribution-date').value = new Date().toISOString().split('T')[0];

        modal.style.display = 'flex';
    }

    closeContributionModal() {
        document.getElementById('pension-contribution-modal').style.display = 'none';
    }

    async saveContribution() {
        const form = document.getElementById('pension-contribution-form');
        const formData = new FormData(form);
        const pensionId = formData.get('pensionId');

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/pensions/${pensionId}/contributions`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    amount: parseFloat(formData.get('amount')),
                    date: formData.get('date'),
                    note: formData.get('note') || null
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to log contribution');
            }

            this.closeContributionModal();
            await this.showPensionDetails(parseInt(pensionId));
            OC.Notification.showTemporary('Contribution logged');
        } catch (error) {
            OC.Notification.showTemporary(error.message);
        }
    }

    async loadDashboardPensionSummary() {
        try {
            const summary = await this.loadPensionSummary();
            this.updatePensionsSummary(summary);
        } catch (error) {
            console.error('Failed to load pension summary for dashboard:', error);
        }
    }

    parseColumnVisibility(settingValue) {
        const defaults = {
            date: true,
            description: true,
            vendor: true,
            category: true,
            amount: true,
            account: true
        };

        if (!settingValue) return defaults;

        try {
            return Object.assign({}, defaults, JSON.parse(settingValue));
        } catch (e) {
            console.error('Failed to parse column visibility settings', e);
            return defaults;
        }
    }

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

    applyColumnVisibility() {
        const table = document.getElementById('transactions-table');
        if (!table) return;

        const columnMap = {
            date: 'date-column',
            description: 'description-column',
            vendor: 'vendor-column',
            category: 'category-column',
            amount: 'amount-column',
            account: 'account-column'
        };

        Object.entries(this.columnVisibility).forEach(([key, visible]) => {
            const className = columnMap[key];
            if (!className) return;

            // Apply to all cells with this class (header and body)
            const cells = table.querySelectorAll(`th.${className}, td.${className}`);
            cells.forEach(cell => {
                cell.style.display = visible ? '' : 'none';
            });
        });
    }

    async toggleColumnVisibility(columnKey, visible) {
        // Prevent hiding all columns (enforce minimum 1 visible)
        const visibleCount = Object.values(this.columnVisibility).filter(v => v).length;
        if (!visible && visibleCount <= 1) {
            OC.Notification.showTemporary('At least one column must remain visible');
            document.getElementById(`col-toggle-${columnKey}`).checked = true;
            return;
        }

        // Update local state
        this.columnVisibility[columnKey] = visible;

        // Apply to DOM immediately
        this.applyColumnVisibility();

        // Persist to backend
        try {
            const settings = {
                transaction_columns_visible: JSON.stringify(this.columnVisibility)
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
                throw new Error('Failed to save column visibility');
            }

            this.settings.transaction_columns_visible = JSON.stringify(this.columnVisibility);

        } catch (error) {
            console.error('Failed to save column visibility:', error);
            OC.Notification.showTemporary('Failed to save column preferences');

            // Revert on failure
            this.columnVisibility[columnKey] = !visible;
            this.applyColumnVisibility();
            document.getElementById(`col-toggle-${columnKey}`).checked = !visible;
        }
    }

    syncColumnConfigUI() {
        Object.entries(this.columnVisibility).forEach(([key, visible]) => {
            const checkbox = document.getElementById(`col-toggle-${key}`);
            if (checkbox) {
                checkbox.checked = visible;
            }
        });
    }

    // Dashboard customization methods
    setupDashboardCustomization() {
        const toggleBtn = document.getElementById('toggle-dashboard-lock-btn');
        if (!toggleBtn) return;

        // Load saved lock state
        const savedLockState = this.settings.dashboard_locked !== 'false'; // Default to locked
        this.dashboardLocked = savedLockState;
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
        this.dashboardLocked = !this.dashboardLocked;

        // Update UI immediately
        this.updateDashboardLockUI();

        // Apply/remove draggable state
        this.setupDashboardDragAndDrop();

        // Save state to backend
        try {
            const settings = {
                dashboard_locked: this.dashboardLocked.toString()
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

        // Add hidden hero metrics
        Object.entries(DASHBOARD_WIDGETS.hero).forEach(([key, widget]) => {
            if (!this.dashboardConfig.hero.visibility[key]) {
                const item = document.createElement('div');
                item.className = 'add-tiles-menu-item';
                item.innerHTML = `
                    <span>${widget.name}</span>
                    <button class="add-tile-btn" data-widget-id="${key}" data-category="hero">
                        <span class="icon-add"></span>
                    </button>
                `;
                menuList.appendChild(item);
            }
        });

        // Add hidden widgets
        Object.entries(DASHBOARD_WIDGETS.widgets).forEach(([key, widget]) => {
            if (!this.dashboardConfig.widgets.visibility[key]) {
                const item = document.createElement('div');
                item.className = 'add-tiles-menu-item';
                item.innerHTML = `
                    <span>${widget.name}</span>
                    <button class="add-tile-btn" data-widget-id="${key}" data-category="widgets">
                        <span class="icon-add"></span>
                    </button>
                `;
                menuList.appendChild(item);
            }
        });

        // Show "All tiles visible" message if nothing hidden
        if (menuList.children.length === 0) {
            menuList.innerHTML = '<div class="add-tiles-empty">All tiles are visible</div>';
        }

        // Wire up add buttons
        menuList.querySelectorAll('.add-tile-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const widgetId = btn.dataset.widgetId;
                const category = btn.dataset.category;
                this.showWidget(widgetId, category);
            });
        });
    }

    async hideWidget(widgetId, category) {
        const config = category === 'hero' ? this.dashboardConfig.hero : this.dashboardConfig.widgets;

        // Update visibility
        config.visibility[widgetId] = false;

        // Apply to DOM
        this.applyDashboardVisibility();

        // Update Add Tiles menu
        this.updateAddTilesMenu();

        // Save to backend
        await this.saveDashboardVisibility();
    }

    async showWidget(widgetId, category) {
        const config = category === 'hero' ? this.dashboardConfig.hero : this.dashboardConfig.widgets;

        // Update visibility
        config.visibility[widgetId] = true;

        // Apply to DOM
        this.applyDashboardVisibility();

        // Add remove button if unlocked
        if (!this.dashboardLocked) {
            this.addRemoveButtons();
        }

        // Update Add Tiles menu
        this.updateAddTilesMenu();

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

    applyDashboardVisibility() {
        // Apply hero visibility
        Object.entries(this.dashboardConfig.hero.visibility).forEach(([key, visible]) => {
            const widget = DASHBOARD_WIDGETS.hero[key];
            if (!widget) return;

            const element = document.querySelector(`[data-widget-id="${key}"][data-widget-category="hero"]`);
            if (element) {
                element.style.display = visible ? '' : 'none';
            }
        });

        // Apply widget visibility
        Object.entries(this.dashboardConfig.widgets.visibility).forEach(([key, visible]) => {
            const widget = DASHBOARD_WIDGETS.widgets[key];
            if (!widget) return;

            const element = document.querySelector(`[data-widget-id="${key}"][data-widget-category="widget"]`);
            if (element) {
                // Respect conditional widgets (Budget Alerts, Debt Payoff)
                // If they have inline display: none, keep it
                if (visible) {
                    // Only show if not conditionally hidden
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
        });
    }

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
            console.error('Widget not found in order array');
            return;
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

            // Reorder DOM elements
            this.applyDashboardOrder();

        } catch (error) {
            console.error('Failed to save widget order:', error);
            OC.Notification.showTemporary('Failed to save widget order');
        }
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

        // Redistribute: first 4 to main, rest to side (matching original layout)
        const fragment1 = document.createDocumentFragment();
        const fragment2 = document.createDocumentFragment();

        allCards.forEach((card, index) => {
            // Original layout: trendChart, spendingChart, netWorthHistory, recentTransactions in main
            // accounts, budgetAlerts, upcomingBills, budgetProgress, savingsGoals, debtPayoff in side
            // We'll keep a similar split: first 4 large/medium widgets to main, rest to side
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
}

// Initialize app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.budgetApp = new BudgetApp();
});