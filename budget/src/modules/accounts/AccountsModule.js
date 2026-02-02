/**
 * Accounts Module - Account management and visualization
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';

export default class AccountsModule {
    constructor(app) {
        this.app = app;

        // Account details state
        this.currentAccount = null;
        this.accountTransactions = [];
        this.accountCurrentPage = 1;
        this.accountRowsPerPage = 50;
        this.accountFilters = {};
        this.accountSort = { field: 'date', direction: 'desc' };
        this.accountTotalPages = 1;
        this.accountTotal = 0;
    }

    // ============================================
    // State Proxies
    // ============================================

    get accounts() { return this.app.accounts; }
    set accounts(value) { this.app.accounts = value; }

    get categories() { return this.app.categories; }
    get settings() { return this.app.settings; }
    get data() { return this.app.data; }

    // ============================================
    // Helper Method Proxies
    // ============================================

    formatCurrency(amount, currency = null) {
        return formatters.formatCurrency(amount, currency, this.settings);
    }

    formatDate(date) {
        return formatters.formatDate(date);
    }

    getPrimaryCurrency() {
        return this.app.getPrimaryCurrency();
    }

    populateAccountDropdowns() {
        return this.app.populateAccountDropdowns();
    }

    loadTransactions() {
        return this.app.loadTransactions();
    }

    showView(viewName) {
        return this.app.router.showView(viewName);
    }

    hideModals() {
        return this.app.hideModals();
    }

    loadInitialData() {
        return this.app.loadInitialData();
    }

    editTransaction(id) {
        return this.app.editTransaction(id);
    }

    deleteTransaction(id) {
        return this.app.deleteTransaction(id);
    }

    loadAndDisplayTransactionTags() {
        return this.app.loadAndDisplayTransactionTags();
    }

    getSelectedTransactionTags() {
        return this.app.getSelectedTransactionTags();
    }

    saveTransactionTags(transactionId, tagIds) {
        return this.app.saveTransactionTags(transactionId, tagIds);
    }

    setupCategoriesEventListeners() {
        return this.app.setupCategoriesEventListeners();
    }

    renderCategoriesTree() {
        return this.app.renderCategoriesTree();
    }

    loadDashboard() {
        return this.app.loadDashboard();
    }

    // ============================================
    // Accounts Module Methods
    // ============================================

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
        document.getElementById('account-opened').textContent = account.openedDate ? this.formatDate(account.openedDate) : 'Not provided';
        document.getElementById('last-reconciled').textContent = account.lastReconciled ? this.formatDate(account.lastReconciled) : 'Never';
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
                        <span class="transaction-date">${this.formatDate(transaction.date)}</span>
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
                        <div class="transaction-tags-display" data-transaction-id="${transaction.id}" style="margin-top: 4px;"></div>
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

        // Load and display tags for visible transactions
        this.loadAndDisplayTransactionTags();
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
                const result = await response.json();
                const savedTransactionId = result.id || transactionId;

                // Save tags if any are selected
                const selectedTagIds = this.getSelectedTransactionTags();
                if (selectedTagIds.length > 0 && savedTransactionId) {
                    await this.saveTransactionTags(savedTransactionId, selectedTagIds);
                }

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

    // Phase 4: Quick Add Transaction methods
    async saveQuickAddTransaction() {
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
        const accountId = getFormValue('quick-add-account', null, false, true);
        const date = getFormValue('quick-add-date');
        const type = getFormValue('quick-add-type');
        const amount = getFormValue('quick-add-amount', null, true);
        const description = getFormValue('quick-add-description');

        const messageEl = document.getElementById('quick-add-message');

        if (!accountId) {
            if (!Array.isArray(this.accounts) || this.accounts.length === 0) {
                this.showQuickAddMessage('No accounts available. Please create an account first.', 'error');
                return;
            }
            this.showQuickAddMessage('Please select an account', 'error');
            return;
        }
        if (!date) {
            this.showQuickAddMessage('Please enter a date', 'error');
            return;
        }
        if (!type) {
            this.showQuickAddMessage('Please select a transaction type', 'error');
            return;
        }
        if (amount === null || amount <= 0) {
            this.showQuickAddMessage('Please enter a valid amount', 'error');
            return;
        }
        if (!description) {
            this.showQuickAddMessage('Please enter a description', 'error');
            return;
        }

        const formData = {
            accountId: accountId,
            date: date,
            type: type,
            amount: amount,
            description: description,
            vendor: null,
            categoryId: getFormValue('quick-add-category', null, false, true),
            notes: null
        };

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/transactions'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(formData)
            });

            if (response.ok) {
                this.showQuickAddMessage('Transaction added successfully!', 'success');
                this.resetQuickAddForm();
                // Reload transactions if on transactions view
                if (this.currentView === 'transactions') {
                    this.loadTransactions();
                }
                // Reload dashboard to update totals
                if (this.currentView === 'dashboard') {
                    this.loadDashboard();
                }
            } else {
                let errorMessage = 'Failed to add transaction';
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
            console.error('Failed to save quick add transaction:', error);
            this.showQuickAddMessage(error.message || 'Failed to add transaction', 'error');
        }
    }

    resetQuickAddForm() {
        const form = document.getElementById('quick-add-form');
        if (form) {
            form.reset();
            // Set today's date as default
            const dateInput = document.getElementById('quick-add-date');
            if (dateInput) {
                dateInput.value = new Date().toISOString().split('T')[0];
            }
        }
        // Hide message
        const messageEl = document.getElementById('quick-add-message');
        if (messageEl) {
            messageEl.style.display = 'none';
        }
    }

    showQuickAddMessage(message, type = 'info') {
        const messageEl = document.getElementById('quick-add-message');
        if (!messageEl) return;

        messageEl.textContent = message;
        messageEl.className = `quick-add-message ${type}`;
        messageEl.style.display = 'block';

        // Auto-hide success messages after 3 seconds
        if (type === 'success') {
            setTimeout(() => {
                messageEl.style.display = 'none';
            }, 3000);
        }
    }

    initQuickAddForm() {
        // Populate account dropdown
        const accountSelect = document.getElementById('quick-add-account');
        if (accountSelect && this.accounts) {
            accountSelect.innerHTML = '<option value="">Select account</option>';
            this.accounts.forEach(account => {
                const option = document.createElement('option');
                option.value = account.id;
                option.textContent = account.name;
                accountSelect.appendChild(option);
            });
        }

        // Populate category dropdown
        const categorySelect = document.getElementById('quick-add-category');
        if (categorySelect && this.categories) {
            categorySelect.innerHTML = '<option value="">No category</option>';
            this.categories.forEach(category => {
                const option = document.createElement('option');
                option.value = category.id;
                option.textContent = category.name;
                categorySelect.appendChild(option);
            });
        }

        // Set today's date as default
        const dateInput = document.getElementById('quick-add-date');
        if (dateInput && !dateInput.value) {
            dateInput.value = new Date().toISOString().split('T')[0];
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
}
