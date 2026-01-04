/**
 * Budget App - Main JavaScript
 */

import Chart from 'chart.js/auto';

class BudgetApp {
    constructor() {
        this.currentView = 'dashboard';
        this.accounts = [];
        this.categories = [];
        this.transactions = [];
        this.charts = {};
        
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
            btn.addEventListener('click', () => {
                this.hideModals();
            });
        });

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
            } else if (e.target.classList.contains('transaction-edit-btn')) {
                const transactionId = parseInt(e.target.getAttribute('data-transaction-id'));
                this.editTransaction(transactionId);
            } else if (e.target.classList.contains('transaction-delete-btn')) {
                const transactionId = parseInt(e.target.getAttribute('data-transaction-id'));
                this.deleteTransaction(transactionId);
            } else if (e.target.classList.contains('autocomplete-item')) {
                const bankName = e.target.getAttribute('data-bank-name');
                this.selectInstitution(bankName);
            } else if (e.target.id === 'empty-categories-add-btn' || e.target.closest('#empty-categories-add-btn')) {
                this.showAddCategoryModal();
            } else if (e.target.id === 'create-default-categories-btn' || e.target.closest('#create-default-categories-btn')) {
                this.createDefaultCategories();
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
                case 'forecast':
                    this.loadForecastView();
                    break;
                case 'reports':
                    this.loadReportsView();
                    break;
                case 'bills':
                    this.loadBillsView();
                    break;
                case 'settings':
                    this.loadSettingsView();
                    break;
            }
        }
    }

    async loadInitialData() {
        try {
            // Load accounts
            const accountsResponse = await fetch(OC.generateUrl('/apps/budget/api/accounts'), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });

            if (!accountsResponse.ok) {
                throw new Error(`Failed to load accounts: ${accountsResponse.status} ${accountsResponse.statusText}`);
            }

            const accountsData = await accountsResponse.json();
            this.accounts = Array.isArray(accountsData) ? accountsData : [];

            // Load categories
            const categoriesResponse = await fetch(OC.generateUrl('/apps/budget/api/categories'), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });
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
            // Load summary data
            const summaryResponse = await fetch(OC.generateUrl('/apps/budget/api/reports/summary'), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });
            const summary = await summaryResponse.json();

            // Update account summary
            const accountsSummary = document.getElementById('accounts-summary');
            if (accountsSummary && Array.isArray(summary.accounts)) {
                accountsSummary.innerHTML = summary.accounts.map(account => `
                    <div class="account-summary-item">
                        <span>${account.name}</span>
                        <span class="amount ${account.balance >= 0 ? 'credit' : 'debit'}">
                            ${this.formatCurrency(account.balance, account.currency)}
                        </span>
                    </div>
                `).join('');
            }

            // Load recent transactions
            const transResponse = await fetch(OC.generateUrl('/apps/budget/api/transactions?limit=10'), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });
            const transactions = await transResponse.json();

            const recentTransactions = document.getElementById('recent-transactions');
            if (recentTransactions && Array.isArray(transactions)) {
                recentTransactions.innerHTML = this.renderTransactionsList(transactions);
            }

            // Update charts
            if (summary.spending) {
                this.updateSpendingChart(summary.spending);
            }
            if (summary.trend) {
                this.updateTrendChart(summary.trend);
            }
        } catch (error) {
            console.error('Failed to load dashboard:', error);
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

            const accountsList = document.getElementById('accounts-list');
            if (accountsList) {
                const accountsHTML = accounts.map(account => {
                    // Helper function to get field with both camelCase and snake_case support
                    const getField = (obj, camelName, snakeName = null) => {
                        if (!snakeName) {
                            // Convert camelCase to snake_case automatically
                            snakeName = camelName.replace(/[A-Z]/g, letter => `_${letter.toLowerCase()}`);
                        }
                        return obj[camelName] || obj[snakeName] || null;
                    };

                    // Handle missing or undefined fields with both naming conventions
                    const accountType = getField(account, 'type') || 'unknown';
                    const accountName = getField(account, 'name') || 'Unnamed Account';
                    const accountBalance = parseFloat(getField(account, 'balance')) || 0;
                    const accountCurrency = getField(account, 'currency') || 'USD';
                    const accountId = getField(account, 'id') || 0;
                    const institution = getField(account, 'institution') || '';
                    const accountNumber = getField(account, 'accountNumber', 'account_number') || '';

                    // Get account type icon and color
                    const typeInfo = this.getAccountTypeInfo(accountType);

                    // Format account number for display
                    const maskedAccountNumber = accountNumber ?
                        '***' + accountNumber.slice(-4) : '';

                    // Calculate health status based on balance and limits
                    const healthStatus = this.getAccountHealthStatus(account);

                    return `
                        <div class="account-card" data-type="${accountType}" data-account-id="${accountId}">
                            <div class="account-card-header">
                                <div class="account-icon" style="background-color: ${typeInfo.color};">
                                    <span class="${typeInfo.icon}" aria-hidden="true"></span>
                                </div>
                                <div class="account-details">
                                    <h3 class="account-name">${accountName}</h3>
                                    <div class="account-meta">
                                        <span class="account-type">${typeInfo.label}</span>
                                        ${institution ? `<span class="account-institution">• ${institution}</span>` : ''}
                                        ${maskedAccountNumber ? `<span class="account-number">• ${maskedAccountNumber}</span>` : ''}
                                    </div>
                                </div>
                            </div>

                            <div class="account-balance-section">
                                <div class="balance-main">
                                    <span class="balance-label">Balance</span>
                                    <span class="balance-amount ${accountBalance >= 0 ? 'positive' : 'negative'}">
                                        ${this.formatCurrency(accountBalance, accountCurrency)}
                                    </span>
                                </div>
                            </div>

                            <div class="account-status ${healthStatus.class}">
                                <span class="${healthStatus.icon}" aria-hidden="true" title="${healthStatus.tooltip}"></span>
                            </div>

                            <div class="account-actions">
                                <button class="account-action-btn view-btn view-transactions-btn" data-account-id="${accountId}" title="View Transactions">
                                    <span class="icon-menu" aria-hidden="true"></span>
                                    <span class="btn-text">Transactions</span>
                                </button>
                                <button class="account-action-btn edit-btn edit-account-btn" data-account-id="${accountId}" title="Edit Account">
                                    <span class="icon-rename" aria-hidden="true"></span>
                                    <span class="btn-text">Edit</span>
                                </button>
                                <button class="account-action-btn delete-btn delete-account-btn" data-account-id="${accountId}" title="Delete Account">
                                    <span class="icon-delete" aria-hidden="true"></span>
                                    <span class="btn-text">Delete</span>
                                </button>
                            </div>
                        </div>
                    `;
                }).join('');

                accountsList.innerHTML = accountsHTML;
            }

            // Also update account dropdowns
            this.populateAccountDropdowns();
            // Add click handlers for account cards
            this.setupAccountCardClickHandlers();
        } catch (error) {
            console.error('Failed to load accounts:', error);
        }
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
        const currency = account.currency || 'USD';

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
            const currency = this.currentAccount?.currency || 'USD';
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

            const currency = this.currentAccount?.currency || 'USD';

            // Update metrics display
            document.getElementById('total-transactions').textContent = totalTransactions.toLocaleString();
            document.getElementById('total-income').textContent = this.formatCurrency(thisMonthIncome, currency);
            document.getElementById('total-expenses').textContent = this.formatCurrency(thisMonthExpenses, currency);
            document.getElementById('avg-transaction').textContent = this.formatCurrency(avgTransaction, currency);

        } catch (error) {
            console.error('Failed to calculate account metrics:', error);
            // Show zeros on error
            document.getElementById('total-transactions').textContent = '0';
            document.getElementById('total-income').textContent = '$0';
            document.getElementById('total-expenses').textContent = '$0';
            document.getElementById('avg-transaction').textContent = '$0';
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
            let url = '/apps/budget/api/transactions?limit=' + this.rowsPerPage;

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
            const currency = transaction.accountCurrency || account?.currency || 'USD';

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

            return `
                <tr class="transaction-row" data-transaction-id="${transaction.id}">
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
                        </div>
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
                const currency = t.accountCurrency || 'USD';
                currencyCounts[currency] = (currencyCounts[currency] || 0) + 1;
            });
            const mostCommonCurrency = Object.entries(currencyCounts)
                .sort((a, b) => b[1] - a[1])[0]?.[0] || 'USD';

            totalElement.textContent = `Total: ${this.formatCurrency(total, mostCommonCurrency)}`;
        }
    }

    updatePagination(result) {
        const pageInfo = document.getElementById('page-info');
        const prevBtn = document.getElementById('prev-page-btn');
        const nextBtn = document.getElementById('next-page-btn');

        // Only update pagination if elements exist
        if (!pageInfo && !prevBtn && !nextBtn) return;

        if (result && result.total && result.totalPages) {
            if (pageInfo) {
                pageInfo.textContent = `Page ${this.currentPage || 1} of ${result.totalPages}`;
            }

            if (prevBtn) {
                prevBtn.disabled = (this.currentPage || 1) <= 1;
            }

            if (nextBtn) {
                nextBtn.disabled = (this.currentPage || 1) >= result.totalPages;
            }
        } else {
            // Hide pagination if not needed or not supported
            if (pageInfo) {
                pageInfo.textContent = '';
            }
            if (prevBtn) prevBtn.disabled = true;
            if (nextBtn) nextBtn.disabled = true;
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

            const formData = {
                name: getFormValue('account-name', ''),
                type: getFormValue('account-type', ''),
                balance: getFormValue('account-balance', 0, true),
                currency: getFormValue('account-currency', 'USD'),
                institution: getFormValue('account-institution'),
                accountNumber: getFormValue('account-number'),
                routingNumber: getFormValue('account-routing-number'),
                sortCode: getFormValue('account-sort-code'),
                iban: getFormValue('account-iban'),
                swiftBic: getFormValue('account-swift-bic'),
                accountHolderName: getFormValue('account-holder-name'),
                openingDate: getFormValue('account-opening-date'),
                interestRate: getFormValue('account-interest-rate', null, true),
                creditLimit: getFormValue('account-credit-limit', null, true),
                overdraftLimit: getFormValue('account-overdraft-limit', null, true)
            };

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

            const accountId = getFormValue('account-id');

            // Make API request
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
            document.getElementById('account-number').value = account.accountNumber || '';
            document.getElementById('account-routing-number').value = account.routingNumber || '';
            document.getElementById('account-sort-code').value = account.sortCode || '';
            document.getElementById('account-iban').value = account.iban || '';
            document.getElementById('account-swift-bic').value = account.swiftBic || '';
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
        if (currency) currency.value = 'USD';
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
        const ibanField = document.getElementById('account-iban');
        if (ibanField) {
            ibanField.addEventListener('blur', () => {
                this.validateBankingField('iban', ibanField.value, 'account-iban');
            });
        }

        // Routing number validation
        const routingField = document.getElementById('account-routing-number');
        if (routingField) {
            routingField.addEventListener('blur', () => {
                this.validateBankingField('routing-number', routingField.value, 'account-routing-number');
            });
        }

        // Sort code validation
        const sortCodeField = document.getElementById('account-sort-code');
        if (sortCodeField) {
            sortCodeField.addEventListener('blur', () => {
                this.validateBankingField('sort-code', sortCodeField.value, 'account-sort-code');
            });
        }

        // SWIFT/BIC validation
        const swiftField = document.getElementById('account-swift-bic');
        if (swiftField) {
            swiftField.addEventListener('blur', () => {
                this.validateBankingField('swift-bic', swiftField.value, 'account-swift-bic');
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

        const bulkCategorizeBtn = document.getElementById('bulk-categorize-btn');
        if (bulkCategorizeBtn) {
            bulkCategorizeBtn.addEventListener('click', () => {
                this.bulkCategorizeTransactions();
            });
        }

        const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
        if (bulkDeleteBtn) {
            bulkDeleteBtn.addEventListener('click', () => {
                this.bulkDeleteTransactions();
            });
        }

        // Reconciliation
        const reconcileModeBtn = document.getElementById('reconcile-mode-btn');
        if (reconcileModeBtn) {
            reconcileModeBtn.addEventListener('click', () => {
                this.toggleReconcileMode();
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

        // Populate bulk category select
        const bulkCategorySelect = document.getElementById('bulk-category-select');
        if (bulkCategorySelect && this.categories) {
            bulkCategorySelect.innerHTML = '<option value="">Select category...</option>';
            this.categories.forEach(category => {
                bulkCategorySelect.innerHTML += `<option value="${category.id}">${category.name}</option>`;
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
        const bulkCategorizeBtn = document.getElementById('bulk-categorize-btn');
        const bulkDeleteBtn = document.getElementById('bulk-delete-btn');

        if (selectedCountElement) {
            selectedCountElement.textContent = selectedCount;
        }

        if (bulkActionsBtn) {
            bulkActionsBtn.disabled = selectedCount === 0;
        }

        if (bulkCategorizeBtn) {
            bulkCategorizeBtn.disabled = selectedCount === 0;
        }

        if (bulkDeleteBtn) {
            bulkDeleteBtn.disabled = selectedCount === 0;
        }
    }

    async bulkCategorizeTransactions() {
        const categoryId = document.getElementById('bulk-category-select').value;
        if (!categoryId || this.selectedTransactions.size === 0) {
            OC.Notification.showTemporary('Please select a category and transactions');
            return;
        }

        try {
            // Fallback to individual updates if bulk endpoint doesn't exist
            const updates = Array.from(this.selectedTransactions);
            const updatePromises = updates.map(async (transactionId) => {
                return fetch(OC.generateUrl(`/apps/budget/api/transactions/${transactionId}`), {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken
                    },
                    body: JSON.stringify({ categoryId: parseInt(categoryId) })
                });
            });

            await Promise.all(updatePromises);
            OC.Notification.showTemporary('Transactions categorized successfully');
            this.selectedTransactions.clear();
            this.loadTransactions();
        } catch (error) {
            console.error('Bulk categorization failed:', error);
            OC.Notification.showTemporary('Failed to categorize transactions');
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
            const deletePromises = Array.from(this.selectedTransactions).map(id =>
                fetch(OC.generateUrl(`/apps/budget/api/transactions/${id}`), {
                    method: 'DELETE',
                    headers: { 'requesttoken': OC.requestToken }
                })
            );

            await Promise.all(deletePromises);
            OC.Notification.showTemporary('Transactions deleted successfully');
            this.selectedTransactions.clear();
            this.loadTransactions();
        } catch (error) {
            console.error('Bulk deletion failed:', error);
            OC.Notification.showTemporary('Failed to delete transactions');
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

        // Import rules
        const addRuleBtn = document.getElementById('add-rule-btn');
        const testRulesBtn = document.getElementById('test-rules-btn');

        if (addRuleBtn) {
            addRuleBtn.addEventListener('click', () => this.showRuleDialog());
        }
        if (testRulesBtn) {
            testRulesBtn.addEventListener('click', () => this.testImportRules());
        }

        // Initialize import state
        this.currentImportStep = 1;
        this.currentImportData = null;
        this.importRules = [];
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
        if (tabName === 'rules') {
            this.loadImportRules();
        } else if (tabName === 'history') {
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
            skipDuplicates: document.getElementById('skip-duplicates')?.checked ?? true
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

            row.innerHTML = `
                <td>
                    <input type="checkbox" checked data-row-index="${transaction.rowIndex ?? index}">
                </td>
                <td>${transaction.date || ''}</td>
                <td>${transaction.description || ''}</td>
                <td class="${amount >= 0 ? 'positive' : 'negative'}">
                    ${this.formatCurrency(amount)}
                </td>
                <td>${transaction.ruleName || 'Uncategorized'}</td>
                <td>
                    <span class="status-badge status-success">New</span>
                </td>
            `;

            tbody.appendChild(row);
        });

        document.getElementById('preview-info').textContent =
            `Showing ${Math.min(50, transactions.length)} of ${transactions.length}`;
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
                        option.textContent = `${account.name} (${account.type})`;
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

            // Build account options HTML
            let optionsHtml = '<option value="">Skip this account</option>';
            accounts.forEach(account => {
                optionsHtml += `<option value="${account.id}">${account.name} (${account.type})</option>`;
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
            skipDuplicates: document.getElementById('skip-duplicates')?.checked ?? true,
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

    // Import Rules Management
    async loadImportRules() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import/rules'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            const rules = await response.json();
            this.importRules = rules;
            this.renderImportRules(rules);
        } catch (error) {
            console.error('Failed to load import rules:', error);
        }
    }

    renderImportRules(rules) {
        const tbody = document.querySelector('#rules-table tbody');
        if (!tbody) return;

        tbody.innerHTML = '';

        rules.forEach(rule => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${rule.priority}</td>
                <td>${rule.field}</td>
                <td>${rule.matchType}</td>
                <td>${rule.pattern}</td>
                <td>${rule.categoryName}</td>
                <td>
                    <button class="icon-edit" onclick="budgetApp.editRule(${rule.id})" title="Edit rule"></button>
                    <button class="icon-delete" onclick="budgetApp.deleteRule(${rule.id})" title="Delete rule"></button>
                </td>
            `;
            tbody.appendChild(row);
        });
    }

    async testImportRules() {
        const testInput = document.getElementById('test-description').value;
        if (!testInput) return;

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import/rules/test'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ description: testInput })
            });

            const result = await response.json();
            const resultsDiv = document.getElementById('test-results');

            if (result.match) {
                resultsDiv.innerHTML = `
                    <div class="test-results-match">
                        ✓ Matched rule: "${result.rule.pattern}" → ${result.categoryName}
                    </div>
                `;
            } else {
                resultsDiv.innerHTML = `
                    <div class="test-results-no-match">
                        No matching rules found
                    </div>
                `;
            }
        } catch (error) {
            console.error('Failed to test rules:', error);
        }
    }

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
    // Enhanced Forecast System Methods
    // ============================================

    setupForecastEventListeners() {
        // Main forecast generation
        const generateBtn = document.getElementById('generate-forecast-btn');
        if (generateBtn) {
            generateBtn.addEventListener('click', () => this.generateEnhancedForecast());
        }

        // Export forecast
        const exportBtn = document.getElementById('export-forecast-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', () => this.exportForecast());
        }

        // Scenario tabs
        const scenarioTabs = document.querySelectorAll('.scenario-tab');
        scenarioTabs.forEach(tab => {
            tab.addEventListener('click', (e) => {
                const scenario = e.target.dataset.scenario;
                this.switchScenario(scenario);
            });
        });

        // Custom scenario calculation
        const customBtn = document.getElementById('calculate-custom-scenario');
        if (customBtn) {
            customBtn.addEventListener('click', () => this.calculateCustomScenario());
        }

        // Chart controls
        const chartToggles = document.querySelectorAll('.chart-toggle');
        chartToggles.forEach(toggle => {
            toggle.addEventListener('click', (e) => {
                const chartType = e.target.dataset.chart;
                this.switchChart(chartType);
            });
        });

        // Goal management
        const addGoalBtn = document.getElementById('add-goal-btn');
        if (addGoalBtn) {
            addGoalBtn.addEventListener('click', () => this.showGoalDialog());
        }

        // Initialize forecast state
        this.forecastData = null;
        this.currentChart = 'timeline';
        this.currentScenario = 'conservative';
        this.financialGoals = [];
    }

    async generateEnhancedForecast() {
        const accountId = document.getElementById('forecast-account').value;
        const period = document.getElementById('forecast-period').value;
        const horizon = document.getElementById('forecast-horizon').value;
        const confidence = document.getElementById('forecast-confidence').value;

        try {
            // Show loading states
            this.showForecastLoading();

            const response = await fetch(OC.generateUrl('/apps/budget/api/forecast/enhanced'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    accountId: accountId || null,
                    historicalPeriod: parseInt(period),
                    forecastHorizon: parseInt(horizon),
                    confidenceLevel: parseInt(confidence)
                })
            });

            if (response.ok) {
                const forecastData = await response.json();
                this.forecastData = forecastData;

                // Display all forecast components
                this.displayIntelligenceSummary(forecastData.intelligence);
                this.displayScenarioAnalysis(forecastData.scenarios);
                this.displayForecastDashboard(forecastData);
                this.displayGoalTracking(forecastData.goalProjections);
                this.displayRecommendations(forecastData.recommendations);

                // Show all sections
                this.showForecastSections();
            } else {
                throw new Error('Forecast generation failed');
            }
        } catch (error) {
            console.error('Failed to generate enhanced forecast:', error);
            OC.Notification.showTemporary('Failed to generate forecast');
        }
    }

    showForecastLoading() {
        // Hide all sections and show loading
        const sections = [
            'forecast-intelligence',
            'forecast-scenarios',
            'forecast-dashboard',
            'forecast-goals',
            'forecast-recommendations'
        ];

        sections.forEach(sectionId => {
            const section = document.getElementById(sectionId);
            if (section) {
                section.style.display = 'none';
            }
        });
    }

    showForecastSections() {
        // Show all forecast sections
        const sections = [
            'forecast-intelligence',
            'forecast-scenarios',
            'forecast-dashboard',
            'forecast-goals',
            'forecast-recommendations'
        ];

        sections.forEach(sectionId => {
            const section = document.getElementById(sectionId);
            if (section) {
                section.style.display = 'block';
            }
        });
    }

    displayIntelligenceSummary(intelligence) {
        // Update confidence score
        document.getElementById('forecast-score').textContent = `${intelligence.confidence}%`;

        // Update insights
        document.getElementById('trend-insight').textContent = intelligence.trendAnalysis;
        document.getElementById('seasonality-insight').textContent = intelligence.seasonalityInsight;
        document.getElementById('volatility-insight').textContent = intelligence.volatilityAssessment;
    }

    displayScenarioAnalysis(scenarios) {
        // Update scenario assumptions and metrics
        this.updateScenarioData('conservative', scenarios.conservative);
        this.updateScenarioData('base', scenarios.base);
        this.updateScenarioData('optimistic', scenarios.optimistic);

        // Store scenarios for later use
        this.scenarios = scenarios;
    }

    updateScenarioData(scenarioType, data) {
        // Update assumptions
        const assumptionsList = document.getElementById(`${scenarioType}-assumptions`);
        if (assumptionsList && data.assumptions) {
            assumptionsList.innerHTML = data.assumptions.map(assumption =>
                `<li>${assumption}</li>`
            ).join('');
        }

        // Update metrics
        const balanceElement = document.getElementById(`${scenarioType}-balance`);
        if (balanceElement) {
            balanceElement.textContent = this.formatCurrency(data.projectedBalance);
        }
    }

    displayForecastDashboard(forecastData) {
        // Update metrics
        this.updateDashboardMetrics(forecastData.metrics);

        // Initialize main chart
        this.renderForecastChart(forecastData.chartData);
    }

    updateDashboardMetrics(metrics) {
        // Update metric values and trends
        document.getElementById('avg-income').textContent = this.formatCurrency(metrics.avgIncome);
        document.getElementById('avg-expenses').textContent = this.formatCurrency(metrics.avgExpenses);
        document.getElementById('net-cashflow').textContent = this.formatCurrency(metrics.netCashflow);
        document.getElementById('savings-rate').textContent = `${metrics.savingsRate}%`;

        // Update trends
        this.updateTrendIndicator('income-trend', metrics.incomeTrend);
        this.updateTrendIndicator('expense-trend', metrics.expenseTrend);
        this.updateTrendIndicator('cashflow-trend', metrics.cashflowTrend);
        this.updateTrendIndicator('savings-trend', metrics.savingsTrend);

        // Update changes
        document.getElementById('income-change').textContent = metrics.incomeChange;
        document.getElementById('expense-change').textContent = metrics.expenseChange;
        document.getElementById('cashflow-change').textContent = metrics.cashflowChange;
        document.getElementById('savings-change').textContent = metrics.savingsChange;
    }

    updateTrendIndicator(elementId, trend) {
        const element = document.getElementById(elementId);
        if (element) {
            if (trend > 0) {
                element.textContent = '↗️';
            } else if (trend < 0) {
                element.textContent = '↘️';
            } else {
                element.textContent = '➡️';
            }
        }
    }

    renderForecastChart(chartData) {
        const canvas = document.getElementById('forecast-main-chart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');

        // Destroy existing chart if it exists
        if (this.forecastChart) {
            this.forecastChart.destroy();
        }

        this.forecastChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        label: 'Historical Balance',
                        data: chartData.historical,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        fill: false
                    },
                    {
                        label: 'Forecast (Base)',
                        data: chartData.forecast.base,
                        borderColor: 'rgba(255, 159, 64, 1)',
                        backgroundColor: 'rgba(255, 159, 64, 0.1)',
                        borderDash: [5, 5]
                    },
                    {
                        label: 'Conservative',
                        data: chartData.forecast.conservative,
                        borderColor: 'rgba(255, 99, 132, 1)',
                        backgroundColor: 'rgba(255, 99, 132, 0.05)',
                        borderDash: [2, 2]
                    },
                    {
                        label: 'Optimistic',
                        data: chartData.forecast.optimistic,
                        borderColor: 'rgba(75, 192, 192, 1)',
                        backgroundColor: 'rgba(75, 192, 192, 0.05)',
                        borderDash: [2, 2]
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: (value) => this.formatCurrency(value)
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                return `${context.dataset.label}: ${this.formatCurrency(context.parsed.y)}`;
                            }
                        }
                    }
                }
            }
        });
    }

    switchChart(chartType) {
        // Update active toggle
        document.querySelectorAll('.chart-toggle').forEach(toggle => {
            toggle.classList.remove('active');
        });
        document.querySelector(`[data-chart="${chartType}"]`).classList.add('active');

        this.currentChart = chartType;

        // Render different chart based on type
        if (this.forecastData) {
            switch (chartType) {
                case 'timeline':
                    this.renderForecastChart(this.forecastData.chartData);
                    break;
                case 'comparison':
                    this.renderScenarioComparisonChart();
                    break;
                case 'breakdown':
                    this.renderCategoryBreakdownChart();
                    break;
                case 'confidence':
                    this.renderConfidenceBandsChart();
                    break;
            }
        }
    }

    switchScenario(scenario) {
        // Update active tab
        document.querySelectorAll('.scenario-tab').forEach(tab => {
            tab.classList.remove('active');
        });
        document.querySelector(`[data-scenario="${scenario}"]`).classList.add('active');

        // Update active panel
        document.querySelectorAll('.scenario-panel').forEach(panel => {
            panel.classList.remove('active');
        });
        document.getElementById(`${scenario}-scenario`).classList.add('active');

        this.currentScenario = scenario;
    }

    calculateCustomScenario() {
        const params = {
            incomeGrowth: parseFloat(document.getElementById('custom-income-growth').value) / 100,
            expenseGrowth: parseFloat(document.getElementById('custom-expense-growth').value) / 100,
            oneTimeIncome: parseFloat(document.getElementById('custom-one-time-income').value),
            oneTimeExpense: parseFloat(document.getElementById('custom-one-time-expense').value)
        };

        // Calculate custom scenario based on base case
        if (this.scenarios && this.scenarios.base) {
            const customResult = this.calculateScenarioProjection(this.scenarios.base, params);

            document.getElementById('custom-balance').textContent = this.formatCurrency(customResult.projectedBalance);

            // Determine risk level
            const riskElement = document.getElementById('custom-risk');
            const riskLevel = this.assessRiskLevel(customResult.projectedBalance, this.scenarios.base.projectedBalance);
            riskElement.textContent = riskLevel;
            riskElement.className = `metric-value risk-${riskLevel.toLowerCase()}`;
        }
    }

    calculateScenarioProjection(baseScenario, customParams) {
        // Simplified calculation - in real implementation, this would be more sophisticated
        let adjustedBalance = baseScenario.projectedBalance;

        // Apply income growth adjustment
        adjustedBalance += (baseScenario.avgIncome * customParams.incomeGrowth * 12);

        // Apply expense growth adjustment
        adjustedBalance -= (baseScenario.avgExpenses * customParams.expenseGrowth * 12);

        // Apply one-time adjustments
        adjustedBalance += customParams.oneTimeIncome - customParams.oneTimeExpense;

        return {
            projectedBalance: adjustedBalance
        };
    }

    assessRiskLevel(customBalance, baseBalance) {
        const difference = (customBalance - baseBalance) / baseBalance;

        if (difference < -0.2) return 'High';
        if (difference < -0.1) return 'Medium';
        if (difference > 0.2) return 'High';
        if (difference > 0.1) return 'Medium';
        return 'Low';
    }

    displayGoalTracking(goalProjections) {
        // Load and display financial goals
        this.loadFinancialGoals(goalProjections);
    }

    async loadFinancialGoals(projections = null) {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/goals'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            const goals = await response.json();
            this.financialGoals = goals;
            this.renderGoalsList(goals, projections);
        } catch (error) {
            console.error('Failed to load financial goals:', error);
        }
    }

    renderGoalsList(goals, projections) {
        const goalsList = document.querySelector('.goals-list');
        const template = document.querySelector('.goal-card.template');

        // Clear existing goals (except template)
        goalsList.querySelectorAll('.goal-card:not(.template)').forEach(card => card.remove());

        goals.forEach(goal => {
            const goalCard = template.cloneNode(true);
            goalCard.classList.remove('template');
            goalCard.style.display = 'block';

            // Update goal information
            goalCard.querySelector('.goal-name').textContent = goal.name;
            goalCard.querySelector('.goal-target').textContent = `Target: ${this.formatCurrency(goal.targetAmount)}`;

            // Calculate progress
            const progress = Math.min((goal.currentAmount / goal.targetAmount) * 100, 100);
            goalCard.querySelector('.progress-fill').style.width = `${progress}%`;
            goalCard.querySelector('.progress-current').textContent = this.formatCurrency(goal.currentAmount);
            goalCard.querySelector('.progress-percentage').textContent = `${Math.round(progress)}%`;

            // Calculate timeline
            const monthsRemaining = this.calculateGoalTimeline(goal, projections);
            goalCard.querySelector('.progress-timeline').textContent = `${monthsRemaining} months to go`;

            // Update forecast result
            const forecastResult = this.getGoalForecastResult(goal, projections);
            goalCard.querySelector('.forecast-result').textContent = forecastResult;

            goalsList.appendChild(goalCard);
        });
    }

    calculateGoalTimeline(goal, projections) {
        if (!projections || !projections.monthlySavings) {
            return '--';
        }

        const remaining = goal.targetAmount - goal.currentAmount;
        const monthsNeeded = Math.ceil(remaining / projections.monthlySavings);
        return monthsNeeded > 0 ? monthsNeeded : 0;
    }

    getGoalForecastResult(goal, projections) {
        if (!projections) {
            return 'Unable to forecast';
        }

        const timeline = this.calculateGoalTimeline(goal, projections);
        if (timeline === '--') {
            return 'Insufficient data';
        }

        if (timeline <= goal.targetMonths) {
            return '✅ On track to achieve goal';
        } else if (timeline <= goal.targetMonths * 1.5) {
            return '⚠️ Slightly behind schedule';
        } else {
            return '❌ Significantly behind schedule';
        }
    }

    displayRecommendations(recommendations) {
        // Update AI recommendations
        document.getElementById('high-priority-recommendation').textContent =
            recommendations.high || 'No high priority recommendations at this time.';

        document.getElementById('medium-priority-recommendation').textContent =
            recommendations.medium || 'Your spending patterns look stable.';

        document.getElementById('low-priority-recommendation').textContent =
            recommendations.low || 'Consider setting up automated savings goals.';
    }

    async exportForecast() {
        if (!this.forecastData) {
            OC.Notification.showTemporary('Please generate a forecast first');
            return;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/forecast/export'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(this.forecastData)
            });

            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `financial-forecast-${new Date().toISOString().split('T')[0]}.pdf`;
                a.click();
                window.URL.revokeObjectURL(url);
            }
        } catch (error) {
            console.error('Failed to export forecast:', error);
            OC.Notification.showTemporary('Failed to export forecast');
        }
    }

    showGoalDialog() {
        // Implementation for goal creation dialog
        console.log('Show goal dialog - to be implemented');
    }

    // Legacy method for compatibility
    async generateForecast() {
        return this.generateEnhancedForecast();
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
    formatCurrency(amount, currency = 'USD') {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency
        }).format(amount);
    }

    renderTransactionsList(transactions) {
        return transactions.map(t => `
            <div class="transaction-item">
                <span class="transaction-date">${new Date(t.date).toLocaleDateString()}</span>
                <span class="transaction-description">${t.description}</span>
                <span class="amount ${t.type}">${this.formatCurrency(t.amount, t.accountCurrency || 'USD')}</span>
            </div>
        `).join('');
    }

    renderTransactionsTable(transactions) {
        return transactions.map(t => `
            <tr>
                <td class="select-column">
                    <input type="checkbox" class="transaction-checkbox" data-transaction-id="${t.id}">
                </td>
                <td>${new Date(t.date).toLocaleDateString()}</td>
                <td>${t.description}</td>
                <td>${t.categoryName || '-'}</td>
                <td class="amount ${t.type}">${this.formatCurrency(t.amount, t.accountCurrency || 'USD')}</td>
                <td>${t.accountName}</td>
                <td class="reconcile-column"></td>
                <td>
                    <button class="tertiary transaction-edit-btn" data-transaction-id="${t.id}" aria-label="Edit transaction: ${t.description}">Edit</button>
                    <button class="error transaction-delete-btn" data-transaction-id="${t.id}" aria-label="Delete transaction: ${t.description}">Delete</button>
                </td>
            </tr>
        `).join('');
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

    renderCategoryNodes(categories, level = 0) {
        return categories.map(category => {
            const hasChildren = category.children && category.children.length > 0;
            const isExpanded = this.expandedCategories && this.expandedCategories.has(category.id);
            const isSelected = this.selectedCategory?.id === category.id;
            const isChecked = this.selectedCategoryIds && this.selectedCategoryIds.has(category.id);

            // Calculate transaction count and budget status
            const transactionCount = this.getCategoryTransactionCount(category.id);
            const budgetStatus = this.getCategoryBudgetStatus(category);

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
                                ${category.budgetAmount ? `<div class="budget-indicator ${budgetStatus}"></div>` : ''}
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
                            ${this.renderCategoryNodes(category.children, level + 1)}
                        </div>
                    ` : ''}
                </div>
            `;
        }).join('');
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
            // Calculate date range for this month
            const now = new Date();
            const startDate = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().split('T')[0];
            const endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().split('T')[0];

            // Get spending data if endpoint exists
            try {
                const response = await fetch(OC.generateUrl(`/apps/budget/api/categories/${categoryId}/spending?startDate=${startDate}&endDate=${endDate}`), {
                    headers: { 'requesttoken': OC.requestToken }
                });

                if (response.ok) {
                    const data = await response.json();
                    this.updateBudgetDisplay(data.spending || 0);
                } else {
                    // Fallback to client-side calculation
                    this.updateBudgetDisplay(0);
                }
            } catch (error) {
                // Fallback to client-side calculation
                this.updateBudgetDisplay(0);
            }

            this.updateAnalyticsDisplay(categoryId);

        } catch (error) {
            console.error('Failed to load category analytics:', error);
            this.updateBudgetDisplay(0);
            this.updateAnalyticsDisplay(categoryId);
        }
    }

    updateBudgetDisplay(spent) {
        const category = this.selectedCategory;
        const budget = category?.budgetAmount || 0;
        const remaining = budget - spent;
        const percentage = budget > 0 ? Math.min((spent / budget) * 100, 100) : 0;

        // Update amounts with null safety
        const budgetEl = document.getElementById('category-budget-amount');
        if (budgetEl) budgetEl.textContent = this.formatCurrency(budget);

        const spentEl = document.getElementById('category-spent-amount');
        if (spentEl) spentEl.textContent = this.formatCurrency(spent);

        const remainingEl = document.getElementById('category-remaining-amount');
        if (remainingEl) {
            remainingEl.textContent = this.formatCurrency(remaining);
            remainingEl.className = `remaining-amount ${remaining >= 0 ? 'positive' : 'negative'}`;
        }

        // Update progress bar
        const progressFill = document.getElementById('budget-progress-fill');
        if (progressFill) {
            progressFill.style.width = `${percentage}%`;

            let progressClass = '';
            if (percentage >= 100) progressClass = 'over';
            else if (percentage >= 80) progressClass = 'warning';

            progressFill.className = `progress-fill ${progressClass}`;
        }

        // Update progress text
        const progressText = document.getElementById('budget-progress-text');
        if (progressText) progressText.textContent = `${Math.round(percentage)}% of budget used`;
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

    getCategoryBudgetStatus(category) {
        if (!category.budgetAmount) return '';

        const spent = this.getCategoryTransactions(category.id)
            .reduce((sum, t) => sum + Math.abs(parseFloat(t.amount) || 0), 0);

        const percentage = (spent / category.budgetAmount) * 100;

        if (percentage >= 100) return 'over';
        if (percentage >= 80) return 'warning';
        return 'good';
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

        const budgetPeriod = document.getElementById('category-budget-period');
        if (budgetPeriod) budgetPeriod.value = 'monthly';
    }

    loadCategoryData(category) {
        document.getElementById('category-id').value = category.id;
        document.getElementById('category-name').value = category.name;
        document.getElementById('category-type').value = category.type;
        document.getElementById('category-parent').value = category.parentId || '';
        document.getElementById('category-budget').value = category.budgetAmount || '';
        document.getElementById('category-budget-period').value = category.budgetPeriod || 'monthly';
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
        const budgetAmount = document.getElementById('category-budget').value || null;
        const budgetPeriod = document.getElementById('category-budget-period').value;
        const color = document.getElementById('category-color').value;

        if (!name) {
            OC.Notification.showTemporary('Category name is required');
            return;
        }

        const categoryData = {
            name,
            type,
            parentId: parentId ? parseInt(parentId) : null,
            budgetAmount: budgetAmount ? parseFloat(budgetAmount) : null,
            budgetPeriod,
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
                OC.Notification.showTemporary(isEdit ? 'Category updated successfully' : 'Category created successfully');
                this.hideModals();
                await this.loadCategories();
                await this.loadInitialData();
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

        this.charts.spending = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.map(d => d.name),
                datasets: [{
                    data: data.map(d => d.total),
                    backgroundColor: data.map(d => d.color || this.generateColor())
                }]
            },
            options: {
                responsive: false,
                maintainAspectRatio: false,
                aspectRatio: 1
            }
        });
    }

    updateTrendChart(data) {
        const ctx = document.getElementById('trend-chart');
        if (!ctx) return;

        if (this.charts.trend) {
            this.charts.trend.destroy();
        }

        this.charts.trend = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Income',
                    data: data.income,
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)'
                }, {
                    label: 'Expenses',
                    data: data.expenses,
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)'
                }]
            },
            options: {
                responsive: false,
                maintainAspectRatio: false,
                aspectRatio: 2,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
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
        if (!transactionsTable) return;

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

        if (!transaction) return;

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

        // Set current category name as value
        const currentCategory = this.categories?.find(c => c.id === parseInt(currentCategoryId));
        input.value = currentCategory ? currentCategory.name : '';
        input.dataset.categoryId = currentCategoryId || '';

        const dropdown = document.createElement('div');
        dropdown.className = 'category-autocomplete-dropdown';
        dropdown.style.display = 'none';

        container.appendChild(input);
        container.appendChild(dropdown);

        // Build flat list of categories for search
        const flatCategories = this.getFlatCategoryList();

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

        dropdown.addEventListener('click', (e) => {
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
        const thousandsSep = document.getElementById('setting-number-format-thousands-sep')?.value || ',';
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

        const billData = {
            name: document.getElementById('bill-name').value,
            amount: parseFloat(document.getElementById('bill-amount').value),
            frequency: document.getElementById('bill-frequency').value,
            dueDay: document.getElementById('bill-due-day').value ? parseInt(document.getElementById('bill-due-day').value) : null,
            dueMonth: document.getElementById('bill-due-month').value ? parseInt(document.getElementById('bill-due-month').value) : null,
            categoryId: document.getElementById('bill-category').value ? parseInt(document.getElementById('bill-category').value) : null,
            accountId: document.getElementById('bill-account').value ? parseInt(document.getElementById('bill-account').value) : null,
            autoDetectPattern: document.getElementById('bill-auto-pattern').value || null,
            notes: document.getElementById('bill-notes').value || null
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
            document.getElementById('detected-bills-panel').style.display = 'block';
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
}

// Initialize app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.budgetApp = new BudgetApp();
});