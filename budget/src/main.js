/**
 * Budget App - Main JavaScript
 */

import Chart from 'chart.js/auto';

// Utilities
import * as formatters from './utils/formatters.js';
import * as dom from './utils/dom.js';
import * as helpers from './utils/helpers.js';
import * as validators from './utils/validators.js';
import ApiClient from './utils/api.js';

// Configuration
import { DASHBOARD_WIDGETS } from './config/dashboardWidgets.js';

// Core
import Router from './core/Router.js';

// Modules
import AuthModule from './modules/auth/AuthModule.js';
import DashboardModule from './modules/dashboard/DashboardModule.js';
import TransactionsModule from './modules/transactions/TransactionsModule.js';
import PensionsModule from './modules/pensions/PensionsModule.js';
import SavingsModule from './modules/savings/SavingsModule.js';
import IncomeModule from './modules/income/IncomeModule.js';
import BillsModule from './modules/bills/BillsModule.js';
import SettingsModule from './modules/settings/SettingsModule.js';
import SharedExpensesModule from './modules/shared-expenses/SharedExpensesModule.js';
import TagSetsModule from './modules/tagsets/TagSetsModule.js';
import RulesModule from './modules/rules/RulesModule.js';
import ForecastModule from './modules/forecast/ForecastModule.js';
import ReportsModule from './modules/reports/ReportsModule.js';
import ImportModule from './modules/import/ImportModule.js';
import AccountsModule from './modules/accounts/AccountsModule.js';
import CategoriesModule from './modules/categories/CategoriesModule.js';

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
        this.sessionToken = localStorage.getItem('budget_session_token'); // Session token for auth
        this.lastActivityTime = Date.now(); // Track last user activity for session timeout
        this.inactivityTimer = null; // Timer for auto-lock

        // Tag sets feature
        this.tagSets = []; // All tag sets with their tags
        this.selectedCategoryTagSets = []; // Tag sets for currently selected/editing category
        this.transactionTags = {}; // Cache of transaction tags by transaction ID
        this.allTagSetsForReports = []; // All tag sets for reports filtering

        // Savings goals
        this.savingsGoals = [];

        // Recurring income
        this.recurringIncome = [];

        // Bills
        this.bills = [];

        // Shared expenses
        this.contacts = [];
        this.splitContacts = [];
        this.currentContactDetails = null;

        // Rules
        this.rules = [];

        // Initialize core infrastructure
        this.router = new Router(this);

        // Initialize modules
        this.authModule = new AuthModule(this);
        this.dashboardModule = new DashboardModule(this);
        this.transactionsModule = new TransactionsModule(this);
        this.pensionsModule = new PensionsModule(this);
        this.savingsModule = new SavingsModule(this);
        this.incomeModule = new IncomeModule(this);
        this.billsModule = new BillsModule(this);
        this.settingsModule = new SettingsModule(this);
        this.sharedExpensesModule = new SharedExpensesModule(this);
        this.tagSetsModule = new TagSetsModule(this);
        this.rulesModule = new RulesModule(this);
        this.forecastModule = new ForecastModule(this);
        this.reportsModule = new ReportsModule(this);
        this.importModule = new ImportModule(this);
        this.accountsModule = new AccountsModule(this);
        this.categoriesModule = new CategoriesModule(this);

        this.init();
    }

    async init() {
        // Check authentication first
        const authRequired = await this.authModule.checkAuth();

        if (authRequired) {
            // Show password prompt modal
            this.authModule.showPasswordModal();
            return;
        }

        // Authentication passed or not required, proceed with normal init
        this.setupNavigation();
        this.setupEventListeners();
        this.authModule.setupActivityMonitoring();
        await this.authModule.setupLockButton();
        this.loadInitialData();
        this.showView('dashboard');
    }


    // ============================================
    // Auth Module Delegations
    // ============================================

    getAuthHeaders() {
        return this.authModule.getAuthHeaders();
    }

    // Navigation - delegated to Router
    setupNavigation() {
        return this.router.setupNavigation();
    }

    showView(viewName) {
        return this.router.showView(viewName);
    }

    reloadCurrentView() {
        return this.router.reloadCurrentView();
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

        // Quick Add form (Phase 4)
        const quickAddForm = document.getElementById('quick-add-form');
        if (quickAddForm) {
            quickAddForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveQuickAddTransaction();
            });
        }

        // Quick Add reset button (Phase 4)
        const quickAddReset = document.getElementById('quick-add-reset');
        if (quickAddReset) {
            quickAddReset.addEventListener('click', () => {
                this.resetQuickAddForm();
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

        // Tag modal listeners
        this.setupAddTagModalListeners();
        this.setupAddTagSetModalListeners();
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


    applyTheme(themePreference) {
        const appContainer = document.getElementById('app-content-wrapper');
        if (!appContainer) return;

        // Remove existing theme classes
        appContainer.classList.remove('theme-light', 'theme-dark', 'theme-system');

        // Apply theme based on preference
        switch (themePreference) {
            case 'light':
                appContainer.classList.add('theme-light');
                appContainer.setAttribute('data-theme', 'light');
                break;
            case 'dark':
                appContainer.classList.add('theme-dark');
                appContainer.setAttribute('data-theme', 'dark');
                break;
            case 'system':
            default:
                // Follow Nextcloud's theme (remove overrides)
                appContainer.classList.add('theme-system');
                appContainer.removeAttribute('data-theme');
                break;
        }
    }

    async loadInitialData() {
        try {
            // Load all initial data in parallel for better performance
            const [settingsResponse, accountsResponse, categoriesResponse] = await Promise.all([
                fetch(OC.generateUrl('/apps/budget/api/settings'), {
                    headers: this.getAuthHeaders()
                }),
                fetch(OC.generateUrl('/apps/budget/api/accounts'), {
                    headers: this.getAuthHeaders()
                }),
                fetch(OC.generateUrl('/apps/budget/api/categories'), {
                    headers: this.getAuthHeaders()
                })
            ]);

            if (settingsResponse.ok) {
                this.settings = await settingsResponse.json();
                this.columnVisibility = this.parseColumnVisibility(this.settings.transaction_columns_visible);
                this.syncColumnConfigUI();

                // Parse dashboard config
                this.dashboardConfig.hero = this.parseDashboardConfig(this.settings.dashboard_hero_config, 'hero');
                this.dashboardConfig.widgets = this.parseDashboardConfig(this.settings.dashboard_widgets_config, 'widgets');

                // Apply theme preference on load
                if (this.settings.theme_preference) {
                    this.applyTheme(this.settings.theme_preference);
                }
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

    // ============================================
    // Dashboard Module Delegations
    // ============================================

    async loadDashboard() {
        return this.dashboardModule.loadDashboard();
    }

    parseDashboardConfig(config, type) {
        return this.dashboardModule.parseDashboardConfig(config, type);
    }

    applyDashboardLayout() {
        return this.dashboardModule.applyDashboardLayout();
    }

    toggleDashboardLock() {
        return this.dashboardModule.toggleDashboardLock();
    }

    async applyDashboardVisibility() {
        return this.dashboardModule.applyDashboardVisibility();
    }

    applyDashboardOrder() {
        return this.dashboardModule.applyDashboardOrder();
    }

    needsLazyLoad(widgetKey) {
        return this.dashboardModule.needsLazyLoad(widgetKey);
    }

    async loadWidgetData(widgetKey) {
        return this.dashboardModule.loadWidgetData(widgetKey);
    }

    async hideWidget(widgetId, category) {
        return this.dashboardModule.hideWidget(widgetId, category);
    }

    async showWidget(widgetId, category) {
        return this.dashboardModule.showWidget(widgetId, category);
    }

    async saveDashboardVisibility() {
        return this.dashboardModule.saveDashboardVisibility();
    }

    setupDashboardCustomization() {
        return this.dashboardModule.setupDashboardCustomization();
    }

    updateDashboardLockUI() {
        return this.dashboardModule.updateDashboardLockUI();
    }

    addRemoveButtons() {
        return this.dashboardModule.addRemoveButtons();
    }

    updateAddTilesMenu() {
        return this.dashboardModule.updateAddTilesMenu();
    }

    setupDashboardDragAndDrop() {
        return this.dashboardModule.setupDashboardDragAndDrop();
    }

    showDashboardDropIndicator(e, container) {
        return this.dashboardModule.showDashboardDropIndicator(e, container);
    }

    getDragAfterElement(container, y) {
        return this.dashboardModule.getDragAfterElement(container, y);
    }

    getDashboardDropTarget(e, container) {
        return this.dashboardModule.getDashboardDropTarget(e, container);
    }

    clearDashboardDropIndicators() {
        return this.dashboardModule.clearDashboardDropIndicators();
    }

    async reorderDashboardWidget(draggedId, targetId, position, category) {
        return this.dashboardModule.reorderDashboardWidget(draggedId, targetId, position, category);
    }


    // ============================================
    // Accounts Module Delegations
    // ============================================

    async loadAccounts() {
        return this.accountsModule.loadAccounts();
    }

    async showAccountDetails(accountId) {
        return this.accountsModule.showAccountDetails(accountId);
    }

    hideAccountDetails() {
        return this.accountsModule.hideAccountDetails();
    }

    async saveAccount() {
        return this.accountsModule.saveAccount();
    }

    showAccountModal(accountId = null) {
        return this.accountsModule.showAccountModal(accountId);
    }

    async editAccount(id) {
        return this.accountsModule.editAccount(id);
    }

    async deleteAccount(id) {
        return this.accountsModule.deleteAccount(id);
    }

    viewAccountTransactions(accountId) {
        return this.accountsModule.viewAccountTransactions(accountId);
    }

    // ==================== END ACCOUNTS MODULE ====================

    // ============================================
    // Categories Module Delegations
    // ============================================

    async loadCategories() {
        return this.categoriesModule.loadCategories();
    }

    setupCategoriesEventListeners() {
        return this.categoriesModule.setupCategoriesEventListeners();
    }

    switchCategoryType(type) {
        return this.categoriesModule.switchCategoryType(type);
    }

    renderCategoriesTree() {
        return this.categoriesModule.renderCategoriesTree();
    }

    selectCategory(categoryId) {
        return this.categoriesModule.selectCategory(categoryId);
    }

    showAddCategoryModal() {
        return this.categoriesModule.showAddCategoryModal();
    }

    editSelectedCategory() {
        return this.categoriesModule.editSelectedCategory();
    }

    deleteSelectedCategory() {
        return this.categoriesModule.deleteSelectedCategory();
    }

    async deleteCategoryById(categoryId) {
        return this.categoriesModule.deleteCategoryById(categoryId);
    }

    async saveCategory() {
        return this.categoriesModule.saveCategory();
    }

    async createDefaultCategories() {
        return this.categoriesModule.createDefaultCategories();
    }

    async loadBudgetView() {
        return this.categoriesModule.loadBudgetView();
    }

    // ==================== END CATEGORIES MODULE ====================

    // ============================================
    // Transactions Module Delegations
    // ============================================

    setupTransactionEventListeners() {
        return this.transactionsModule.setupTransactionEventListeners();
    }

    setupInlineEditingListeners() {
        return this.transactionsModule.setupInlineEditingListeners();
    }

    renderTransactionsTable(transactions) {
        return this.transactionsModule.renderTransactionsTable(transactions);
    }

    renderEnhancedTransactionsTable() {
        const tbody = document.querySelector('#transactions-table tbody');
        if (!tbody || !this.transactions) return;

        tbody.innerHTML = this.transactions.map(transaction => {
            const account = this.accounts?.find(a => a.id === transaction.accountId);
            const category = this.categories?.find(c => c.id === transaction.categoryId);
            const currency = transaction.accountCurrency || account?.currency || this.getPrimaryCurrency();

            const typeClass = transaction.type === 'credit' ? 'positive' : 'negative';
            const formattedAmount = this.formatCurrency(transaction.amount, currency);

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
                               ${this.transactionsModule.selectedTransactions?.has(transaction.id) ? 'checked' : ''}>
                    </td>
                    <td class="date-column editable-cell"
                        data-field="date"
                        data-value="${transaction.date}"
                        data-transaction-id="${transaction.id}">
                        <span class="cell-display">${this.formatDate(transaction.date)}</span>
                    </td>
                    <td class="description-column editable-cell"
                        data-field="description"
                        data-value="${this.escapeHtml(transaction.description)}"
                        data-transaction-id="${transaction.id}">
                        <div class="transaction-description">
                            <span class="primary-text cell-display">${this.escapeHtml(transaction.description) || 'No description'}</span>
                            ${transaction.reference ? `<span class="secondary-text">${this.escapeHtml(transaction.reference)}</span>` : ''}
                            ${linkedBadge}
                        </div>
                    </td>
                    <td class="vendor-column editable-cell"
                        data-field="vendor"
                        data-value="${this.escapeHtml(transaction.vendor || '')}"
                        data-transaction-id="${transaction.id}">
                        <span class="cell-display">${this.escapeHtml(transaction.vendor) || '-'}</span>
                    </td>
                    <td class="category-column editable-cell"
                        data-field="categoryId"
                        data-value="${transaction.categoryId || ''}"
                        data-transaction-id="${transaction.id}">
                        <span class="category-badge cell-display ${category ? 'categorized' : 'uncategorized'}">
                            ${category ? this.escapeHtml(category.name) : 'Uncategorized'}
                        </span>
                    </td>
                    <td class="tags-column editable-cell"
                        data-field="tags"
                        data-value="${this.getTransactionTagIds(transaction.id).join(',')}"
                        data-category-id="${transaction.categoryId || ''}"
                        data-transaction-id="${transaction.id}">
                        <span class="cell-display">
                            ${this.renderTransactionTags(transaction.id)}
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
                        <span class="account-name cell-display">${account ? this.escapeHtml(account.name) : 'Unknown Account'}</span>
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

    /**
     * Render tags for a transaction
     */
    renderTransactionTags(transactionId) {
        // Ensure transactionTags is initialized
        if (!this.transactionTags) {
            this.transactionTags = {};
        }

        const tags = this.transactionTags[transactionId];

        if (!tags || tags.length === 0) {
            return '<span style="color: var(--color-text-maxcontrast); font-size: 11px;">-</span>';
        }

        return tags.map(tag => `
            <span class="tag-chip"
                  style="display: inline-flex; align-items: center; background-color: ${this.escapeHtml(tag.color)}; color: white;
                         padding: 2px 6px; border-radius: 10px; font-size: 10px; line-height: 14px; margin: 0 2px 2px 0;">
                ${this.escapeHtml(tag.name)}
            </span>
        `).join('');
    }

    /**
     * Get tag IDs for a transaction
     */
    getTransactionTagIds(transactionId) {
        if (!this.transactionTags) {
            this.transactionTags = {};
        }

        const tags = this.transactionTags[transactionId];
        if (!tags || tags.length === 0) {
            return [];
        }

        return tags.map(tag => tag.id);
    }

    showTransactionModal(transaction, preSelectedAccountId) {
        return this.transactionsModule.showTransactionModal(transaction, preSelectedAccountId);
    }

    editTransaction(id) {
        return this.transactionsModule.editTransaction(id);
    }

    async deleteTransaction(id) {
        return this.transactionsModule.deleteTransaction(id);
    }

    // Transaction matching/splits
    async findTransactionMatches(transactionId) {
        return this.transactionsModule.findTransactionMatches(transactionId);
    }

    async getTransactionSplits(transactionId) {
        return this.transactionsModule.getTransactionSplits(transactionId);
    }

    addSplitRow(container, split, isFirst) {
        return this.transactionsModule.addSplitRow(container, split, isFirst);
    }

    async bulkMatchTransactions() {
        return this.transactionsModule.bulkMatchTransactions();
    }

    async handleBulkMatchUndo(transactionId) {
        return this.transactionsModule.handleBulkMatchUndo(transactionId);
    }

    async handleBulkMatchLink(transactionId, index) {
        return this.transactionsModule.handleBulkMatchLink(transactionId, index);
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

            // Load tags for all displayed transactions
            await this.loadAllTransactionTags();

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

    // ============================================
    // Import Module Delegations
    // ============================================

    setupImportEventListeners() {
        return this.importModule.setupImportEventListeners();
    }

    async handleImportFile(file) {
        return this.importModule.handleImportFile(file);
    }

    // ============================================
    // Forecast Module Delegations
    // ============================================

    setupForecastEventListeners() {
        // This method may not exist in ForecastModule yet
        // For now, just return to prevent errors
        if (this.forecastModule.setupForecastEventListeners) {
            return this.forecastModule.setupForecastEventListeners();
        }
    }


    // ===========================
    // Reports Management - delegated to ReportsModule
    // ===========================

    async loadReportsView() {
        return this.reportsModule.loadReportsView();
    }

    // ==========================================
    // Forecast - delegated to ForecastModule
    // ==========================================

    async loadForecastView() {
        return this.forecastModule.loadForecastView();
    }

    // Shared Expenses - delegated to SharedExpensesModule
    // ===========================

    async loadSharedExpensesView() {
        return this.sharedExpensesModule.loadSharedExpensesView();
    }

    async showShareExpenseModal(transaction) {
        return this.sharedExpensesModule.showShareExpenseModal(transaction);
    }

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

        // Password protection event listeners
        this.setupPasswordProtectionEventListeners();

        // Migration event listeners
        this.setupMigrationEventListeners();

        // Factory reset event listeners
        this.setupFactoryResetEventListeners();
    }

    setupPasswordProtectionEventListeners() {
        const passwordToggle = document.getElementById('setting-password-protection-enabled');
        const setupPasswordBtn = document.getElementById('setup-password-btn');
        const changePasswordBtn = document.getElementById('change-password-btn');
        const disablePasswordBtn = document.getElementById('disable-password-btn');
        const passwordConfig = document.getElementById('password-protection-config');

        if (passwordToggle) {
            passwordToggle.addEventListener('change', async () => {
                if (passwordToggle.checked) {
                    // Show password setup UI
                    if (passwordConfig) {
                        passwordConfig.style.display = 'block';
                        this.updatePasswordButtons(false);
                    }
                } else {
                    // Hide password config
                    if (passwordConfig) {
                        passwordConfig.style.display = 'none';
                    }
                }
            });
        }

        if (setupPasswordBtn) {
            setupPasswordBtn.addEventListener('click', () => this.showSetupPasswordModal());
        }

        if (changePasswordBtn) {
            changePasswordBtn.addEventListener('click', () => this.showChangePasswordModal());
        }

        if (disablePasswordBtn) {
            disablePasswordBtn.addEventListener('click', () => this.showDisablePasswordModal());
        }
    }

    updatePasswordButtons(hasPassword) {
        const setupBtn = document.getElementById('setup-password-btn');
        const changeBtn = document.getElementById('change-password-btn');
        const disableBtn = document.getElementById('disable-password-btn');

        if (setupBtn) setupBtn.style.display = hasPassword ? 'none' : 'inline-block';
        if (changeBtn) changeBtn.style.display = hasPassword ? 'inline-block' : 'none';
        if (disableBtn) disableBtn.style.display = hasPassword ? 'inline-block' : 'none';
    }

    showSetupPasswordModal() {
        const modal = document.createElement('div');
        modal.id = 'setup-password-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `
            <div class="budget-modal">
                <div class="budget-modal-header">
                    <h2>Set Up Password Protection</h2>
                    <button class="close-btn">×</button>
                </div>
                <div class="budget-modal-body">
                    <p>Enter a password to protect your budget app. You will need to enter this password when accessing the app.</p>
                    <form id="setup-password-form">
                        <div class="form-group">
                            <label for="new-password">New Password</label>
                            <input type="password" id="new-password" class="budget-input" required minlength="6" autocomplete="new-password">
                            <small>Minimum 6 characters</small>
                        </div>
                        <div class="form-group">
                            <label for="confirm-password">Confirm Password</label>
                            <input type="password" id="confirm-password" class="budget-input" required autocomplete="new-password">
                        </div>
                        <div id="setup-password-error" class="error-message" style="display: none;"></div>
                        <div class="form-actions">
                            <button type="button" class="budget-btn secondary close-btn">Cancel</button>
                            <button type="submit" class="budget-btn primary">Set Password</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        const form = document.getElementById('setup-password-form');
        const newPasswordInput = document.getElementById('new-password');
        const confirmPasswordInput = document.getElementById('confirm-password');
        const errorDiv = document.getElementById('setup-password-error');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const newPassword = newPasswordInput.value;
            const confirmPassword = confirmPasswordInput.value;

            if (newPassword !== confirmPassword) {
                errorDiv.textContent = 'Passwords do not match';
                errorDiv.style.display = 'block';
                return;
            }

            try {
                const response = await fetch(OC.generateUrl('/apps/budget/api/auth/setup'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...this.getAuthHeaders()
                    },
                    body: JSON.stringify({ password: newPassword })
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    // Store session token
                    this.sessionToken = result.sessionToken;
                    localStorage.setItem('budget_session_token', result.sessionToken);

                    OC.Notification.showTemporary('Password protection enabled');
                    modal.remove();

                    // Update UI
                    this.updatePasswordButtons(true);
                } else {
                    errorDiv.textContent = result.error || 'Failed to set password';
                    errorDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('Failed to set password:', error);
                errorDiv.textContent = 'Failed to set password. Please try again.';
                errorDiv.style.display = 'block';
            }
        });

        // Close modal handlers
        modal.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', () => modal.remove());
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.remove();
        });

        newPasswordInput.focus();
    }

    showChangePasswordModal() {
        const modal = document.createElement('div');
        modal.id = 'change-password-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `
            <div class="budget-modal">
                <div class="budget-modal-header">
                    <h2>Change Password</h2>
                    <button class="close-btn">×</button>
                </div>
                <div class="budget-modal-body">
                    <form id="change-password-form">
                        <div class="form-group">
                            <label for="current-password">Current Password</label>
                            <input type="password" id="current-password" class="budget-input" required autocomplete="current-password">
                        </div>
                        <div class="form-group">
                            <label for="new-password-change">New Password</label>
                            <input type="password" id="new-password-change" class="budget-input" required minlength="6" autocomplete="new-password">
                            <small>Minimum 6 characters</small>
                        </div>
                        <div class="form-group">
                            <label for="confirm-password-change">Confirm New Password</label>
                            <input type="password" id="confirm-password-change" class="budget-input" required autocomplete="new-password">
                        </div>
                        <div id="change-password-error" class="error-message" style="display: none;"></div>
                        <div class="form-actions">
                            <button type="button" class="budget-btn secondary close-btn">Cancel</button>
                            <button type="submit" class="budget-btn primary">Change Password</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        const form = document.getElementById('change-password-form');
        const currentPasswordInput = document.getElementById('current-password');
        const newPasswordInput = document.getElementById('new-password-change');
        const confirmPasswordInput = document.getElementById('confirm-password-change');
        const errorDiv = document.getElementById('change-password-error');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const currentPassword = currentPasswordInput.value;
            const newPassword = newPasswordInput.value;
            const confirmPassword = confirmPasswordInput.value;

            if (newPassword !== confirmPassword) {
                errorDiv.textContent = 'New passwords do not match';
                errorDiv.style.display = 'block';
                return;
            }

            try {
                const response = await fetch(OC.generateUrl('/apps/budget/api/auth/password'), {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        ...this.getAuthHeaders()
                    },
                    body: JSON.stringify({
                        currentPassword: currentPassword,
                        newPassword: newPassword
                    })
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    OC.Notification.showTemporary('Password changed successfully');
                    modal.remove();
                } else {
                    errorDiv.textContent = result.error || 'Failed to change password';
                    errorDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('Failed to change password:', error);
                errorDiv.textContent = 'Failed to change password. Please try again.';
                errorDiv.style.display = 'block';
            }
        });

        // Close modal handlers
        modal.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', () => modal.remove());
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.remove();
        });

        currentPasswordInput.focus();
    }

    showDisablePasswordModal() {
        const modal = document.createElement('div');
        modal.id = 'disable-password-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `
            <div class="budget-modal">
                <div class="budget-modal-header">
                    <h2>Disable Password Protection</h2>
                    <button class="close-btn">×</button>
                </div>
                <div class="budget-modal-body">
                    <p>Enter your current password to disable password protection.</p>
                    <form id="disable-password-form">
                        <div class="form-group">
                            <label for="disable-current-password">Current Password</label>
                            <input type="password" id="disable-current-password" class="budget-input" required autocomplete="current-password">
                        </div>
                        <div id="disable-password-error" class="error-message" style="display: none;"></div>
                        <div class="form-actions">
                            <button type="button" class="budget-btn secondary close-btn">Cancel</button>
                            <button type="submit" class="budget-btn primary">Disable Protection</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        const form = document.getElementById('disable-password-form');
        const passwordInput = document.getElementById('disable-current-password');
        const errorDiv = document.getElementById('disable-password-error');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const password = passwordInput.value;

            try {
                const response = await fetch(OC.generateUrl('/apps/budget/api/auth/disable'), {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        ...this.getAuthHeaders()
                    },
                    body: JSON.stringify({ password: password })
                });

                const result = await response.json();

                if (response.ok && result.success) {
                    // Update UI
                    const passwordToggle = document.getElementById('setting-password-protection-enabled');
                    if (passwordToggle) passwordToggle.checked = false;

                    const passwordConfig = document.getElementById('password-protection-config');
                    if (passwordConfig) passwordConfig.style.display = 'none';

                    OC.Notification.showTemporary('Password protection disabled');
                    modal.remove();
                } else {
                    errorDiv.textContent = result.error || 'Failed to disable password protection';
                    errorDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('Failed to disable password protection:', error);
                errorDiv.textContent = 'Failed to disable password protection. Please try again.';
                errorDiv.style.display = 'block';
            }
        });

        // Close modal handlers
        modal.querySelectorAll('.close-btn').forEach(btn => {
            btn.addEventListener('click', () => modal.remove());
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.remove();
        });

        passwordInput.focus();
    }

    setupFactoryResetEventListeners() {
        const factoryResetBtn = document.getElementById('factory-reset-btn');
        const factoryResetModal = document.getElementById('factory-reset-modal');
        const factoryResetInput = document.getElementById('factory-reset-confirm-input');
        const factoryResetConfirmBtn = document.getElementById('factory-reset-confirm-btn');
        const modalCloseButtons = factoryResetModal ? factoryResetModal.querySelectorAll('.close-btn') : [];

        // Open modal
        if (factoryResetBtn) {
            factoryResetBtn.addEventListener('click', () => {
                this.openFactoryResetModal();
            });
        }

        // Enable/disable confirm button based on input value
        if (factoryResetInput && factoryResetConfirmBtn) {
            factoryResetInput.addEventListener('input', (e) => {
                // User must type exactly "DELETE" (case-sensitive)
                factoryResetConfirmBtn.disabled = e.target.value !== 'DELETE';
            });
        }

        // Confirm button
        if (factoryResetConfirmBtn) {
            factoryResetConfirmBtn.addEventListener('click', () => {
                this.executeFactoryReset();
            });
        }

        // Close modal buttons
        modalCloseButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                this.closeFactoryResetModal();
            });
        });

        // Close modal on background click
        if (factoryResetModal) {
            factoryResetModal.addEventListener('click', (e) => {
                if (e.target === factoryResetModal) {
                    this.closeFactoryResetModal();
                }
            });
        }
    }

    openFactoryResetModal() {
        const modal = document.getElementById('factory-reset-modal');
        const input = document.getElementById('factory-reset-confirm-input');
        const confirmBtn = document.getElementById('factory-reset-confirm-btn');

        if (modal) {
            // Reset input and button state
            if (input) {
                input.value = '';
                input.focus(); // Auto-focus the input field
            }
            if (confirmBtn) confirmBtn.disabled = true;

            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
        }
    }

    closeFactoryResetModal() {
        const modal = document.getElementById('factory-reset-modal');
        if (modal) {
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
        }
    }

    async executeFactoryReset() {
        try {
            // Show loading state
            const confirmBtn = document.getElementById('factory-reset-confirm-btn');
            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.innerHTML = '<span class="icon-loading-small" aria-hidden="true"></span> Deleting...';
            }

            const response = await fetch(OC.generateUrl('/apps/budget/api/setup/factory-reset'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    confirmed: true
                })
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Factory reset failed');
            }

            // Close modal
            this.closeFactoryResetModal();

            // Show success message
            OC.Notification.showTemporary('Factory reset completed successfully. All data has been deleted.');

            // Reload the page to show empty state
            setTimeout(() => {
                window.location.reload();
            }, 1500);

        } catch (error) {
            console.error('Factory reset error:', error);

            // Reset button state
            const confirmBtn = document.getElementById('factory-reset-confirm-btn');
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<span class="icon-delete" aria-hidden="true"></span> Delete Everything';
            }

            OC.Notification.showTemporary(error.message || 'Failed to perform factory reset');
        }
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

    // Settings - delegated to SettingsModule
    async loadSettingsView() {
        return this.settingsModule.loadSettingsView();
    }

    // ==========================================
    // Bills Management - delegated to BillsModule
    // ==========================================
    async loadBillsView() {
        return this.billsModule.loadBillsView();
    }

    async loadRulesView() {
        return this.rulesModule.loadRulesView();
    }

    // ============================================
    // RECURRING INCOME METHODS
    // ============================================

    async loadIncomeView() {
        return this.incomeModule.loadIncomeView();
    }

    async loadIncomeSummary() {
        return this.incomeModule.loadIncomeSummary();
    }

    renderRecurringIncome(incomeItems) {
        return this.incomeModule.renderRecurringIncome(incomeItems);
    }

    isIncomeReceivedThisMonth(income) {
        return this.incomeModule.isIncomeReceivedThisMonth(income);
    }

    isExpectedSoon(dateStr) {
        return this.incomeModule.isExpectedSoon(dateStr);
    }

    filterIncome(filter) {
        return this.incomeModule.filterIncome(filter);
    }

    setupIncomeEventListeners() {
        return this.incomeModule.setupIncomeEventListeners();
    }

    showIncomeModal(income = null) {
        return this.incomeModule.showIncomeModal(income);
    }

    hideIncomeModal() {
        return this.incomeModule.hideIncomeModal();
    }

    updateIncomeFormFields() {
        return this.incomeModule.updateIncomeFormFields();
    }

    renderDetectedIncome(detected) {
        return this.incomeModule.renderDetectedIncome(detected);
    }

    async addSelectedDetectedIncome() {
        return this.incomeModule.addSelectedDetectedIncome();
    }

    // ============================================
    // SAVINGS GOALS METHODS
    // ============================================

    async loadSavingsGoalsView() {
        return this.savingsModule.loadSavingsGoalsView();
    }

    updateGoalsSummary() {
        return this.savingsModule.updateGoalsSummary();
    }

    renderGoals(goals) {
        return this.savingsModule.renderGoals(goals);
    }

    setupGoalsEventListeners() {
        return this.savingsModule.setupGoalsEventListeners();
    }

    populateGoalAccountDropdown() {
        return this.savingsModule.populateGoalAccountDropdown();
    }

    showGoalModal(goal = null) {
        return this.savingsModule.showGoalModal(goal);
    }

    async saveGoal() {
        return this.savingsModule.saveGoal();
    }

    editGoal(goalId) {
        return this.savingsModule.editGoal(goalId);
    }

    async deleteGoal(goalId) {
        return this.savingsModule.deleteGoal(goalId);
    }

    showAddMoneyModal(goalId) {
        return this.savingsModule.showAddMoneyModal(goalId);
    }

    async addMoneyToGoal() {
        return this.savingsModule.addMoneyToGoal();
    }

    // ============================================
    // DEBT PAYOFF METHODS
    // ============================================

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

    formatAccountType(type) {
        return formatters.formatAccountType(type);
    }

    // ============================================
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

        sourceDetails.querySelector('.source-date').textContent = this.formatDate(transaction.date);
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
                        <span class="match-date">${this.formatDate(match.date)}</span>
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
                <span>${this.formatDate(transaction.date)}</span>
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

        // Get the transaction to determine its type
        const transactionId = parseInt(modal?.dataset.transactionId);
        const transaction = this.transactions?.find(t => t.id === transactionId);
        const transactionType = transaction?.type || 'debit';

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
                    ${this.getCategoryOptions(split?.categoryId, transactionType)}
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
    getCategoryOptions(selectedId = null, transactionType = null) {
        if (!this.categories) return '';

        // Determine category type based on transaction type
        // credit = income categories, debit = expense categories
        const categoryType = transactionType === 'credit' ? 'income' : 'expense';

        return this.categories
            .filter(c => c.type === categoryType)
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
                    <span class="pair-date">${this.formatDate(tx.date)}</span>
                    <span class="pair-description">${this.escapeHtml(tx.description)}</span>
                    <div class="pair-details">
                        <span class="pair-amount ${txTypeClass}">${this.formatCurrency(tx.amount, txCurrency)}</span>
                        <span class="pair-account">${this.escapeHtml(tx.account_name)}</span>
                    </div>
                </div>
                <span class="pair-arrow">↔</span>
                <div class="pair-transaction">
                    <span class="pair-date">${this.formatDate(linked.date)}</span>
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
                            <span class="match-date">${this.formatDate(match.date)}</span>
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
                        <span class="review-source-date">${this.formatDate(tx.date)}</span>
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
    // =====================
    // Pensions Methods
    // =====================

    async loadPensionsView() {
        return this.pensionsModule.loadPensionsView();
    }

    async loadPensions() {
        return this.pensionsModule.loadPensions();
    }

    async loadPensionSummary() {
        return this.pensionsModule.loadPensionSummary();
    }

    async loadPensionProjection() {
        return this.pensionsModule.loadPensionProjection();
    }

    renderPensions() {
        return this.pensionsModule.renderPensions();
    }

    renderPensionCard(pension) {
        return this.pensionsModule.renderPensionCard(pension);
    }

    updatePensionsSummary(summary) {
        return this.pensionsModule.updatePensionsSummary(summary);
    }

    updatePensionsProjection(projection) {
        return this.pensionsModule.updatePensionsProjection(projection);
    }

    setupPensionEventListeners() {
        return this.pensionsModule.setupPensionEventListeners();
    }

    togglePensionFields() {
        return this.pensionsModule.togglePensionFields();
    }

    showPensionModal(pensionId = null) {
        return this.pensionsModule.showPensionModal(pensionId);
    }

    closePensionModal() {
        return this.pensionsModule.closePensionModal();
    }

    async savePension() {
        return this.pensionsModule.savePension();
    }

    async deletePension(pensionId) {
        return this.pensionsModule.deletePension(pensionId);
    }

    async showPensionDetails(pensionId) {
        return this.pensionsModule.showPensionDetails(pensionId);
    }

    closePensionDetails() {
        return this.pensionsModule.closePensionDetails();
    }

    async loadPensionBalanceChart(pensionId) {
        return this.pensionsModule.loadPensionBalanceChart(pensionId);
    }

    async loadPensionProjectionChart(pensionId) {
        return this.pensionsModule.loadPensionProjectionChart(pensionId);
    }

    async loadPensionActivity(pensionId) {
        return this.pensionsModule.loadPensionActivity(pensionId);
    }

    showBalanceModal() {
        return this.pensionsModule.showBalanceModal();
    }

    closeBalanceModal() {
        return this.pensionsModule.closeBalanceModal();
    }

    async saveSnapshot() {
        return this.pensionsModule.saveSnapshot();
    }

    showContributionModal() {
        return this.pensionsModule.showContributionModal();
    }

    closeContributionModal() {
        return this.pensionsModule.closeContributionModal();
    }

    async saveContribution() {
        return this.pensionsModule.saveContribution();
    }

    async loadDashboardPensionSummary() {
        return this.pensionsModule.loadDashboardPensionSummary();
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

    // Duplicate parseDashboardConfig removed - using delegation to dashboardModule

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

    // Dashboard customization methods moved to DashboardModule

    // ============================================
    // Tag Sets Module Delegations
    // ============================================

    async loadTagSetsForCategory(categoryId) {
        return this.tagSetsModule.loadTagSetsForCategory(categoryId);
    }

    async loadTransactionTags(transactionId) {
        return this.tagSetsModule.loadTransactionTags(transactionId);
    }

    async saveTransactionTags(transactionId, tagIds) {
        return this.tagSetsModule.saveTransactionTags(transactionId, tagIds);
    }

    renderTagChips(tags) {
        return this.tagSetsModule.renderTagChips(tags);
    }

    async renderCategoryTagSetsUI(categoryId) {
        return this.tagSetsModule.renderCategoryTagSetsUI(categoryId);
    }

    async renderTransactionTagSelectors(categoryId, transactionId) {
        return this.tagSetsModule.renderTransactionTagSelectors(categoryId, transactionId);
    }

    async loadAndDisplayTransactionTags() {
        return this.tagSetsModule.loadAndDisplayTransactionTags();
    }

    async renderCategoryTagSetsList(categoryId) {
        return this.tagSetsModule.renderCategoryTagSetsList(categoryId);
    }

    async loadAllTransactionTags() {
        return this.tagSetsModule.loadAllTransactionTags();
    }

    setupAddTagModalListeners() {
        return this.tagSetsModule.setupAddTagModalListeners();
    }

    setupAddTagSetModalListeners() {
        // Check if method exists in TagSetsModule
        if (this.tagSetsModule.setupAddTagSetModalListeners) {
            return this.tagSetsModule.setupAddTagSetModalListeners();
        }
    }

    // ============================================
    // Helper Methods
    // ============================================

    formatCurrency(amount, currency = null) {
        return formatters.formatCurrency(amount, currency, this.settings);
    }

    getPrimaryCurrency() {
        // Get default currency from settings
        const defaultCurrency = this.settings?.default_currency || 'GBP';

        // Return cached value if accounts and settings haven't changed
        const currentHash = formatters.getAccountsHash(this.accounts);
        if (this._primaryCurrencyCache &&
            this._accountsHash === currentHash &&
            this._settingsCurrencyCache === defaultCurrency) {
            return this._primaryCurrencyCache;
        }

        // Get primary currency from utility
        const primaryCurrency = formatters.getPrimaryCurrency(this.accounts, this.settings);

        // Cache the result
        this._primaryCurrencyCache = primaryCurrency;
        this._accountsHash = currentHash;
        this._settingsCurrencyCache = defaultCurrency;

        return primaryCurrency;
    }

    formatDate(dateStr) {
        return formatters.formatDate(dateStr, this.settings);
    }

    escapeHtml(text) {
        return dom.escapeHtml(text);
    }

    populateAccountDropdowns() {
        // Stub method - dropdowns are populated by individual modules as needed
        // This is called from loadInitialData but doesn't need to do anything
    }

    populateCategoryDropdowns() {
        // Stub method - dropdowns are populated by individual modules as needed
        // This is called from loadInitialData but doesn't need to do anything
    }
}

// Initialize app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.budgetApp = new BudgetApp();
});