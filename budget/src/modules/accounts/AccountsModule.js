/**
 * Accounts Module - Account management and visualization
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError, showWarning } from '../../utils/notifications.js';
import { setDateValue, clearDateValue } from '../../utils/datepicker.js';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';

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
        this.selectedAccountFilterTags = new Set();
        this.allAccountFilterTagSets = null;
    }

    // ============================================
    // State Proxies
    // ============================================

    get accounts() { return this.app.accounts; }
    set accounts(value) { this.app.accounts = value; }

    get categories() { return this.app.categories; }
    get categoryTree() { return this.app.categoryTree; }
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
            const [response, summaryResponse] = await Promise.all([
                fetch(OC.generateUrl('/apps/budget/api/accounts'), {
                    headers: { 'requesttoken': OC.requestToken }
                }),
                fetch(OC.generateUrl('/apps/budget/api/reports/summary'), {
                    headers: { 'requesttoken': OC.requestToken }
                }).catch(() => ({ ok: false }))
            ]);

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

            const summary = summaryResponse.ok ? await summaryResponse.json() : null;

            // Update the instance accounts array
            this.accounts = accounts;

            // Render the accounts page with new layout
            this.renderAccountsPage(accounts, summary);

            // Also update account dropdowns
            this.populateAccountDropdowns();
            // Add click handlers for account cards
            this.setupAccountCardClickHandlers();
        } catch (error) {
            console.error('Failed to load accounts:', error);
        }
    }

    renderAccountsPage(accounts, summary = null) {
        // Helper function to get field with both camelCase and snake_case support
        const getField = (obj, camelName, snakeName = null) => {
            if (!snakeName) {
                snakeName = camelName.replace(/[A-Z]/g, letter => `_${letter.toLowerCase()}`);
            }
            return obj[camelName] || obj[snakeName] || null;
        };

        // Categorize accounts into assets and liabilities
        const assetTypes = ['checking', 'savings', 'investment', 'cash', 'cryptocurrency', 'money_market'];
        const liabilityTypes = ['credit_card', 'loan'];

        const assets = accounts.filter(acc => assetTypes.includes(getField(acc, 'type')));
        const liabilities = accounts.filter(acc => liabilityTypes.includes(getField(acc, 'type')));

        // Use currency-converted totals from summary if available, otherwise fall back to raw sums
        const primaryCurrency = summary?.baseCurrency || this.getPrimaryCurrency();
        let totalAssets = 0;
        let totalLiabilities = 0;

        if (summary?.totals) {
            totalAssets = summary.totals.totalAssets || 0;
            totalLiabilities = summary.totals.totalLiabilities || 0;
        } else {
            assets.forEach(acc => {
                totalAssets += parseFloat(getField(acc, 'balance')) || 0;
            });
            liabilities.forEach(acc => {
                const balance = parseFloat(getField(acc, 'balance')) || 0;
                totalLiabilities -= balance; // balances are negative when owed, so negate to get positive total
            });
        }

        const netWorth = totalAssets - totalLiabilities;

        // Update summary cards
        const totalAssetsEl = document.getElementById('summary-total-assets');
        const totalLiabilitiesEl = document.getElementById('summary-total-liabilities');
        const netWorthEl = document.getElementById('summary-net-worth');
        const assetsSubtotalEl = document.getElementById('assets-subtotal');
        const liabilitiesSubtotalEl = document.getElementById('liabilities-subtotal');

        if (totalAssetsEl) {
            totalAssetsEl.textContent = this.formatCurrency(totalAssets, primaryCurrency);
            totalAssetsEl.classList.toggle('positive', totalAssets >= 0);
            totalAssetsEl.classList.toggle('negative', totalAssets < 0);
        }
        if (totalLiabilitiesEl) {
            totalLiabilitiesEl.textContent = this.formatCurrency(totalLiabilities, primaryCurrency);
            totalLiabilitiesEl.classList.toggle('positive', totalLiabilities <= 0);
            totalLiabilitiesEl.classList.toggle('negative', totalLiabilities > 0);
        }
        if (netWorthEl) {
            netWorthEl.textContent = this.formatCurrency(netWorth, primaryCurrency);
            netWorthEl.classList.toggle('positive', netWorth >= 0);
            netWorthEl.classList.toggle('negative', netWorth < 0);
        }
        if (assetsSubtotalEl) {
            assetsSubtotalEl.textContent = this.formatCurrency(totalAssets, primaryCurrency);
            assetsSubtotalEl.classList.toggle('positive', totalAssets >= 0);
            assetsSubtotalEl.classList.toggle('negative', totalAssets < 0);
        }
        if (liabilitiesSubtotalEl) {
            liabilitiesSubtotalEl.textContent = this.formatCurrency(totalLiabilities, primaryCurrency);
            liabilitiesSubtotalEl.classList.toggle('positive', totalLiabilities <= 0);
            liabilitiesSubtotalEl.classList.toggle('negative', totalLiabilities > 0);
        }

        // Show warning for accounts with unconvertible currencies
        const warningEl = document.getElementById('accounts-conversion-warning');
        if (warningEl) {
            const unconverted = summary?.unconvertedCurrencies || [];
            if (unconverted.length > 0) {
                warningEl.textContent = t('budget', 'Accounts in {currencies} are excluded from totals because exchange rates are unavailable. Add rates in Settings > Exchange Rates.', { currencies: unconverted.join(', ') });
                warningEl.style.display = 'block';
            } else {
                warningEl.style.display = 'none';
            }
        }

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
                assetsGrid.innerHTML = '<div class="accounts-empty-state">' + t('budget', 'No asset accounts yet') + '</div>';
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
        const accountName = getField(account, 'name') || t('budget', 'Unnamed Account');
        const accountBalance = parseFloat(getField(account, 'balance')) || 0;
        const accountCurrency = getField(account, 'currency') || this.getPrimaryCurrency();
        const accountId = getField(account, 'id') || 0;
        const institution = getField(account, 'institution') || '';

        const typeInfo = this.getAccountTypeInfo(accountType);
        const healthStatus = this.getAccountHealthStatus(account);

        // For liabilities: negative = you owe (red), positive = overpayment/credit (green), zero = paid off
        const isLiability = ['credit_card', 'loan'].includes(accountType);
        const displayBalance = isLiability ? Math.abs(accountBalance) : accountBalance;
        const balanceClass = isLiability
            ? (accountBalance <= 0 ? 'negative' : 'positive')
            : (accountBalance >= 0 ? 'positive' : 'negative');
        const balanceLabel = isLiability
            ? (accountBalance < 0 ? t('budget', 'Owed') : accountBalance > 0 ? t('budget', 'Credit') : t('budget', 'Balance'))
            : t('budget', 'Balance');

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
                        <span class="balance-label">${balanceLabel}</span>
                        <span class="balance-amount ${balanceClass}">
                            ${this.formatCurrency(displayBalance, accountCurrency)}
                        </span>
                        ${account.convertedBalance != null ? `<span class="balance-converted">\u2248 ${this.formatCurrency(isLiability ? Math.abs(account.convertedBalance) : account.convertedBalance, account.baseCurrency)}</span>` : ''}
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
                        <button class="account-action-btn edit-btn edit-account-btn" data-account-id="${accountId}" title="${t('budget', 'Edit Account')}">
                            <span class="icon-rename" aria-hidden="true"></span>
                        </button>
                        <button class="account-action-btn delete-btn delete-account-btn" data-account-id="${accountId}" title="${t('budget', 'Delete Account')}">
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
                    OC.generateUrl(`/apps/budget/api/transactions?account=${accountId}&startDate=${formatters.formatDateForAPI(startDate)}&endDate=${formatters.formatDateForAPI(endDate)}`),
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
            const dayTxns = sortedTxns.filter(tx => {
                const txnDate = new Date(tx.date || tx.Date);
                txnDate.setHours(0, 0, 0, 0);
                return txnDate.getTime() === date.getTime();
            });

            // Store the balance at end of this day
            balances.unshift(runningBalance);

            // Reverse transactions to get previous day's balance
            dayTxns.forEach(tx => {
                const amount = parseFloat(tx.amount || tx.Amount) || 0;
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

            // Initialize account-specific state for fresh view
            this.accountCurrentPage = 1;
            this.accountRowsPerPage = 50;
            this.accountFilters = {};
            this.accountSort = { field: 'date', direction: 'desc' };

            // Load account transactions and metrics
            await this.loadAccountTransactions(accountId);
            await this.loadAccountMetrics(accountId);

            // Setup account details event listeners
            this.setupAccountDetailsEventListeners();

        } catch (error) {
            console.error('Failed to show account details:', error);
            showError(t('budget', 'Failed to load account details'));
        }
    }

    /**
     * Refresh the current account view with updated data
     * Called after transactions are created/updated/deleted
     */
    async refreshCurrentAccountView() {
        if (!this.currentAccount) {
            return; // Not viewing an account details page
        }

        // Find updated account data from the accounts array
        const updatedAccount = this.accounts.find(acc => acc.id === this.currentAccount.id);
        if (!updatedAccount) {
            console.warn('Current account no longer exists');
            return;
        }

        // Update the reference
        this.currentAccount = updatedAccount;

        // Re-render the account overview with fresh data
        this.populateAccountOverview(updatedAccount);

        // Reload transactions to show the newly added/updated transaction
        await this.loadAccountTransactions(updatedAccount.id);
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

        // Show minimum payment for liability accounts
        const isLiability = ['credit_card', 'loan', 'mortgage', 'line_of_credit'].includes(account.type);
        const minPaymentInfo = document.getElementById('minimum-payment-info');
        const minPaymentDisplay = document.getElementById('account-minimum-payment-display');
        if (minPaymentInfo && minPaymentDisplay) {
            if (isLiability && account.minimumPayment) {
                minPaymentInfo.style.display = 'block';
                minPaymentDisplay.textContent = this.formatCurrency(account.minimumPayment, currency);
            } else {
                minPaymentInfo.style.display = 'none';
            }
        }

        document.getElementById('account-available-balance').textContent = this.formatCurrency(availableBalance, currency);
        document.getElementById('account-available-balance').className = `balance-amount ${availableBalance >= 0 ? 'positive' : 'negative'}`;

        // Show projected balance (including scheduled future transactions) if different from current
        const projectedInfo = document.getElementById('projected-balance-info');
        const projectedEl = document.getElementById('account-projected-balance');
        if (projectedInfo && projectedEl) {
            const storedBalance = parseFloat(account.storedBalance ?? account.balance) || 0;
            if (Math.abs(storedBalance - currentBalance) > 0.01) {
                projectedInfo.style.display = 'block';
                projectedEl.textContent = this.formatCurrency(storedBalance, currency);
                projectedEl.className = `balance-amount projected ${storedBalance >= 0 ? 'positive' : 'negative'}`;
            } else {
                projectedInfo.style.display = 'none';
            }
        }

        // Show accrued interest for accounts with interest tracking enabled
        const accruedInterestInfo = document.getElementById('accrued-interest-info');
        const totalOwingInfo = document.getElementById('total-owing-info');
        const rateHistorySection = document.getElementById('interest-rate-history-section');
        if (account.interestEnabled) {
            this.loadInterestDetails(account.id, currency);
        } else {
            if (accruedInterestInfo) accruedInterestInfo.style.display = 'none';
            if (totalOwingInfo) totalOwingInfo.style.display = 'none';
            if (rateHistorySection) rateHistorySection.style.display = 'none';
        }

        // Show unrealised P&L for investment and crypto accounts
        const isInvestmentType = ['investment', 'cryptocurrency'].includes(account.type);
        const investmentCostInfo = document.getElementById('investment-cost-info');
        const investmentValueInfo = document.getElementById('investment-value-info');
        const investmentPnlInfo = document.getElementById('investment-pnl-info');
        if (isInvestmentType) {
            this.loadValuation(account.id);
        } else {
            if (investmentCostInfo) investmentCostInfo.style.display = 'none';
            if (investmentValueInfo) investmentValueInfo.style.display = 'none';
            if (investmentPnlInfo) investmentPnlInfo.style.display = 'none';
        }

        // Update account details
        document.getElementById('account-number').textContent = account.accountNumber ? '***' + account.accountNumber.slice(-4) : t('budget', 'Not provided');
        document.getElementById('routing-number').textContent = account.routingNumber || t('budget', 'Not provided');
        document.getElementById('account-iban').textContent = account.iban || t('budget', 'Not provided');
        document.getElementById('sort-code').textContent = account.sortCode || t('budget', 'Not provided');
        document.getElementById('swift-bic').textContent = account.swiftBic || t('budget', 'Not provided');
        document.getElementById('account-display-currency').textContent = currency;
        document.getElementById('account-opened').textContent = account.openedDate ? this.formatDate(account.openedDate) : t('budget', 'Not provided');
        document.getElementById('last-reconciled').textContent = account.lastReconciled ? this.formatDate(account.lastReconciled) : t('budget', 'Never');
    }

    async loadInterestDetails(accountId, currency) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/accounts/${accountId}/interest`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) return;

            const data = await response.json();

            const accruedInterestEl = document.getElementById('account-accrued-interest');
            const accruedInterestInfo = document.getElementById('accrued-interest-info');
            const totalOwingEl = document.getElementById('account-total-owing');
            const totalOwingInfo = document.getElementById('total-owing-info');
            const rateHistorySection = document.getElementById('interest-rate-history-section');

            if (accruedInterestInfo && accruedInterestEl) {
                accruedInterestInfo.style.display = 'block';
                accruedInterestEl.textContent = this.formatCurrency(Math.abs(data.accruedInterest), currency);
                accruedInterestEl.className = `balance-amount ${data.isLiability ? 'negative' : 'positive'}`;
            }

            if (totalOwingInfo && totalOwingEl) {
                totalOwingInfo.style.display = 'block';
                totalOwingEl.textContent = this.formatCurrency(data.totalOwing, currency);
                totalOwingEl.className = `balance-amount ${data.totalOwing >= 0 ? 'positive' : 'negative'}`;
            }

            // Render rate history table
            if (rateHistorySection && data.rateHistory) {
                rateHistorySection.style.display = 'block';
                this.renderRateHistory(data.rateHistory, accountId);
            }
        } catch (error) {
            console.error('Failed to load interest details:', error);
        }
    }

    renderRateHistory(rates, accountId) {
        const tbody = document.getElementById('rate-history-body');
        if (!tbody) return;

        const compoundingLabels = {
            'daily': t('budget', 'Daily'),
            'monthly': t('budget', 'Monthly'),
            'yearly': t('budget', 'Yearly'),
            'simple': t('budget', 'Simple'),
        };

        tbody.innerHTML = rates.map((rate, index) => `
            <tr>
                <td>${this.formatDate(rate.effectiveDate)}</td>
                <td>${rate.rate}%</td>
                <td>${compoundingLabels[rate.compoundingFrequency] || rate.compoundingFrequency}</td>
                <td>
                    ${rates.length > 1 ? `
                        <button class="action-btn delete-btn delete-rate-btn"
                                data-rate-id="${rate.id}"
                                data-account-id="${accountId}"
                                title="${t('budget', 'Delete')}">
                            <span class="icon-delete" aria-hidden="true"></span>
                        </button>
                    ` : ''}
                </td>
            </tr>
        `).join('');

        // Add event listeners for delete buttons
        tbody.querySelectorAll('.delete-rate-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const rateId = parseInt(btn.dataset.rateId);
                const acctId = parseInt(btn.dataset.accountId);
                try {
                    const response = await fetch(OC.generateUrl(`/apps/budget/api/accounts/${acctId}/interest-rates/${rateId}`), {
                        method: 'DELETE',
                        headers: { 'requesttoken': OC.requestToken }
                    });
                    if (response.ok) {
                        showSuccess(t('budget', 'Rate change deleted'));
                        this.loadInterestDetails(acctId, this.currentAccount?.currency || 'USD');
                    } else {
                        const error = await response.json();
                        showError(error.error || t('budget', 'Failed to delete rate change'));
                    }
                } catch (error) {
                    showError(t('budget', 'Failed to delete rate change'));
                }
            });
        });

        // Add rate change button handler
        const addBtn = document.getElementById('add-rate-change-btn');
        if (addBtn && !addBtn._hasListener) {
            addBtn._hasListener = true;
            addBtn.addEventListener('click', () => this.showAddRateChangeModal(accountId));
        }
    }

    showAddRateChangeModal(accountId) {
        const rate = prompt(t('budget', 'Enter new annual interest rate (%)'));
        if (rate === null) return;

        const rateFloat = parseFloat(rate);
        if (isNaN(rateFloat) || rateFloat < 0 || rateFloat > 999.9999) {
            showError(t('budget', 'Invalid rate. Must be between 0 and 999.9999'));
            return;
        }

        const effectiveDate = prompt(t('budget', 'Effective date (YYYY-MM-DD)'), new Date().toISOString().split('T')[0]);
        if (!effectiveDate) return;

        const compounding = prompt(t('budget', 'Compounding frequency (daily, monthly, yearly, simple)'), 'daily');
        if (!compounding || !['daily', 'monthly', 'yearly', 'simple'].includes(compounding)) {
            showError(t('budget', 'Invalid compounding frequency'));
            return;
        }

        this.addRateChange(accountId, rateFloat, compounding, effectiveDate);
    }

    async addRateChange(accountId, rate, compoundingFrequency, effectiveDate) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/accounts/${accountId}/interest-rates`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ rate, compoundingFrequency, effectiveDate })
            });

            if (response.ok) {
                showSuccess(t('budget', 'Rate change added'));
                this.loadInterestDetails(accountId, this.currentAccount?.currency || 'USD');
            } else {
                const error = await response.json();
                showError(error.error || t('budget', 'Failed to add rate change'));
            }
        } catch (error) {
            showError(t('budget', 'Failed to add rate change'));
        }
    }

    async loadValuation(accountId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/accounts/${accountId}/valuation`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) return;

            const data = await response.json();
            const baseCurrency = data.baseCurrency || 'GBP';

            const costInfo = document.getElementById('investment-cost-info');
            const costEl = document.getElementById('account-total-cost');
            const valueInfo = document.getElementById('investment-value-info');
            const valueEl = document.getElementById('account-current-value');
            const pnlInfo = document.getElementById('investment-pnl-info');
            const pnlEl = document.getElementById('account-unrealised-pnl');

            // Hide all if no meaningful data
            const hasData = data.netInvested !== 0 || data.currentValue !== 0;
            if (!hasData) {
                if (costInfo) costInfo.style.display = 'none';
                if (valueInfo) valueInfo.style.display = 'none';
                if (pnlInfo) pnlInfo.style.display = 'none';
                return;
            }

            if (costInfo && costEl) {
                costInfo.style.display = 'block';
                costEl.textContent = this.formatCurrency(data.netInvested, baseCurrency);
            }

            if (valueInfo && valueEl) {
                valueInfo.style.display = 'block';
                valueEl.textContent = this.formatCurrency(data.currentValue, baseCurrency);
            }

            if (pnlInfo && pnlEl) {
                pnlInfo.style.display = 'block';
                const pnlSign = data.unrealisedPnL >= 0 ? '+' : '';
                const pnlPct = data.pnlPercentage != null && isFinite(data.pnlPercentage)
                    ? ` (${pnlSign}${data.pnlPercentage.toFixed(1)}%)`
                    : '';
                let pnlText = `${pnlSign}${this.formatCurrency(data.unrealisedPnL, baseCurrency)}${pnlPct}`;
                if (data.conversionWarning) {
                    pnlText += ` ⚠`;
                }
                pnlEl.textContent = pnlText;
                pnlEl.className = `balance-amount ${data.unrealisedPnL >= 0 ? 'positive' : 'negative'}`;
                if (data.conversionWarning) {
                    pnlEl.title = t('budget', 'Some historical exchange rates could not be found. P&L may be approximate.');
                }
            }
        } catch (error) {
            console.error('Failed to load valuation:', error);
        }
    }

    async loadAccountTransactions(accountId) {
        try {
            // Build query for account-specific transactions
            const params = new URLSearchParams({
                accountId: accountId,
                limit: this.accountRowsPerPage,
                page: this.accountCurrentPage,
                sort: this.accountSort.field,
                direction: this.accountSort.direction
            });

            // Apply active filters to query params
            const filters = this.accountFilters || {};
            if (filters.category) params.set('category', filters.category);
            if (filters.type) params.set('type', filters.type);
            if (filters.status) params.set('status', filters.status);
            if (filters.dateFrom) params.set('dateFrom', filters.dateFrom);
            if (filters.dateTo) params.set('dateTo', filters.dateTo);
            if (filters.amountMin) params.set('amountMin', filters.amountMin);
            if (filters.amountMax) params.set('amountMax', filters.amountMax);
            if (filters.search) params.set('search', filters.search);
            if (filters.tagIds && filters.tagIds.length > 0) {
                filters.tagIds.forEach(tagId => {
                    params.append('tagIds[]', tagId);
                });
            }

            const response = await fetch(OC.generateUrl('/apps/budget/api/transactions?' + params.toString()), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (response.ok) {
                const result = await response.json();
                this.accountTransactions = result.transactions || result; // Handle both formats
                this.accountTotalPages = result.totalPages || 1;
                this.accountTotal = result.total || this.accountTransactions.length;
                this.accountBalanceBeforePage = result.balanceBeforePage ?? null;
            } else {
                // Fallback: filter from all transactions
                await this.loadTransactions();
                this.accountTransactions = this.transactions.filter(tx => tx.accountId === accountId);
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
                            <h3>${t('budget', 'No transactions found')}</h3>
                            <p>${t('budget', 'This account doesn\'t have any transactions yet.')}</p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        // Compute running balances from server-provided balanceBeforePage
        let balanceMap = null;
        if (this.accountBalanceBeforePage !== null && this.accountBalanceBeforePage !== undefined) {
            balanceMap = {};
            const chronological = [...this.accountTransactions].sort((a, b) => {
                if (a.date < b.date) return -1;
                if (a.date > b.date) return 1;
                return a.id - b.id;
            });
            let running = parseFloat(this.accountBalanceBeforePage);
            for (const tx of chronological) {
                const amount = parseFloat(tx.amount) || 0;
                running += (tx.type === 'credit' ? amount : -amount);
                balanceMap[tx.id] = running;
            }
        }

        const today = formatters.getTodayDateString();
        tbody.innerHTML = this.accountTransactions.map(transaction => {
            const amount = parseFloat(transaction.amount) || 0;
            const currency = this.currentAccount?.currency || this.getPrimaryCurrency();
            const category = this.categories?.find(c => c.id === transaction.categoryId);
            const isScheduled = transaction.status === 'scheduled';
            const scheduledBadge = isScheduled ? '<span class="scheduled-badge">' + t('budget', 'Scheduled') + '</span>' : '';

            // Transfer badge
            const isLinked = transaction.linkedTransactionId != null;
            const linkedAccountName = transaction.linkedAccountName || this.app.accounts?.find(a => a.id === transaction.linkedAccountId)?.name || '';
            const linkedDirection = transaction.type === 'debit' ? '→' : '←';
            const linkedLabel = linkedAccountName ? t('budget', 'Transfer {direction} {account}', { direction: linkedDirection, account: dom.escapeHtml(linkedAccountName) }) : t('budget', 'Transfer');
            const linkedTitle = linkedAccountName ? t('budget', 'Click to view linked transaction in {account}', { account: dom.escapeHtml(linkedAccountName) }) : t('budget', 'Linked transfer');
            const linkedBadge = isLinked
                ? `<span class="linked-indicator" data-transaction-id="${transaction.id}" data-linked-id="${transaction.linkedTransactionId}" data-linked-account-id="${transaction.linkedAccountId || ''}" title="${linkedTitle}">&#x1F517; ${linkedLabel}</span>`
                : '';

            // Split badge
            const isSplit = transaction.isSplit || transaction.is_split;
            const splitBadge = isSplit
                ? `<span class="split-indicator" title="${t('budget', 'Split transaction')}">${t('budget', 'Split')}</span>`
                : '';

            return `
                <tr class="transaction-row${isScheduled ? ' scheduled-transaction' : ''}${isLinked ? ' is-linked' : ''}" data-transaction-id="${transaction.id}">
                    <td class="date-column">
                        <span class="transaction-date">${this.formatDate(transaction.date)}</span>${scheduledBadge}
                    </td>
                    <td class="description-column">
                        <div class="transaction-description">
                            <span class="description-main">${transaction.description || t('budget', 'No description')}</span>
                            ${transaction.vendor ? `<span class="vendor-name">${transaction.vendor}</span>` : ''}
                            ${(linkedBadge || splitBadge) ? `<div class="transaction-badges">${linkedBadge}${splitBadge}</div>` : ''}
                        </div>
                    </td>
                    <td class="category-column">
                        <span class="category-name ${category ? '' : 'uncategorized'}">
                            ${category ? category.name : t('budget', 'Uncategorized')}
                        </span>
                        <div class="transaction-tags-display" data-transaction-id="${transaction.id}" style="margin-top: 4px;"></div>
                    </td>
                    <td class="amount-column">
                        <span class="transaction-amount ${transaction.type}">
                            ${transaction.type === 'credit' ? '+' : '-'}${this.formatCurrency(Math.abs(amount), currency)}
                        </span>
                    </td>
                    <td class="balance-column">
                        ${balanceMap !== null && balanceMap[transaction.id] !== undefined
                            ? `<span class="transaction-balance ${balanceMap[transaction.id] >= 0 ? 'positive' : 'negative'}${isScheduled ? ' projected' : ''}">${this.formatCurrency(balanceMap[transaction.id], currency)}</span>`
                            : ''}
                    </td>
                    <td class="actions-column">
                        <div class="transaction-actions">
                            <button class="icon-rename edit-transaction-btn"
                                    data-transaction-id="${transaction.id}"
                                    title="${t('budget', 'Edit transaction')}"></button>
                            <button class="icon-delete delete-transaction-btn"
                                    data-transaction-id="${transaction.id}"
                                    title="${t('budget', 'Delete transaction')}"></button>
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

        // Linked transfer badge - navigate to linked transaction
        document.querySelectorAll('#account-transactions-body .linked-indicator').forEach(badge => {
            badge.addEventListener('click', (e) => {
                e.stopPropagation();
                const linkedId = parseInt(e.target.getAttribute('data-linked-id'));
                const linkedAccountId = parseInt(e.target.getAttribute('data-linked-account-id'));
                this.app.navigateToLinkedTransaction(linkedId, linkedAccountId);
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
            const thisMonthTransactions = this.accountTransactions.filter(tx => {
                const transDate = new Date(tx.date);
                return transDate >= startOfMonth && transDate <= endOfMonth;
            });

            // Calculate metrics
            const totalTransactions = this.accountTransactions.length;
            const thisMonthIncome = thisMonthTransactions
                .filter(tx => tx.type === 'credit')
                .reduce((sum, tx) => sum + (parseFloat(tx.amount) || 0), 0);

            const thisMonthExpenses = thisMonthTransactions
                .filter(tx => tx.type === 'debit')
                .reduce((sum, tx) => sum + (parseFloat(tx.amount) || 0), 0);

            const avgTransaction = totalTransactions > 0
                ? this.accountTransactions.reduce((sum, tx) => sum + Math.abs(parseFloat(tx.amount) || 0), 0) / totalTransactions
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
        if (pageInfo) pageInfo.textContent = t('budget', 'Page {current} of {total}', { current: this.accountCurrentPage, total: this.accountTotalPages });
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

        // Import button - navigate to import view with account pre-selected
        const importBtn = document.getElementById('account-import-btn');
        if (importBtn) {
            importBtn.addEventListener('click', () => {
                const accountId = this.currentAccount?.id;
                this.app.router.showView('import');
                // Pre-select the current account in the import form
                if (accountId) {
                    const importAccountSelect = document.getElementById('import-account');
                    if (importAccountSelect) {
                        importAccountSelect.value = accountId;
                    }
                }
            });
        }

        // Export button - download account transactions as CSV
        const exportBtn = document.getElementById('account-export-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', () => this.exportAccountTransactions());
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
        // Toggle filters button
        const toggleBtn = document.getElementById('account-toggle-filters-btn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => this.toggleAccountFiltersPanel());
        }

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
            categoryFilter.innerHTML = '<option value="">' + t('budget', 'All Categories') + '</option><option value="uncategorized">' + t('budget', 'Uncategorized') + '</option>';
            dom.populateCategorySelect(categoryFilter, this.categoryTree || this.categories);
        }

        // Load and populate tags filter
        this.loadAccountFilterTags().then(() => this.populateAccountFilterTagsDropdown());
    }

    toggleAccountFiltersPanel() {
        const filtersPanel = document.getElementById('account-transaction-filters');
        const toggleBtn = document.getElementById('account-toggle-filters-btn');

        if (filtersPanel.style.display === 'none') {
            filtersPanel.style.display = 'block';
            toggleBtn.classList.add('active');
        } else {
            filtersPanel.style.display = 'none';
            toggleBtn.classList.remove('active');
        }
    }

    async loadAccountFilterTags() {
        try {
            const [tagSetsResponse, globalTagsResponse] = await Promise.all([
                fetch(OC.generateUrl('/apps/budget/api/tag-sets'), { headers: { 'requesttoken': OC.requestToken } }),
                fetch(OC.generateUrl('/apps/budget/api/tags/global'), { headers: { 'requesttoken': OC.requestToken } })
            ]);

            if (tagSetsResponse.ok) {
                this.allAccountFilterTagSets = await tagSetsResponse.json();
            }

            if (globalTagsResponse.ok) {
                const globalTags = await globalTagsResponse.json();
                if (globalTags.length > 0) {
                    this.allAccountFilterTagSets = this.allAccountFilterTagSets || [];
                    this.allAccountFilterTagSets.unshift({
                        id: 'global',
                        name: t('budget', 'Tags'),
                        tags: globalTags
                    });
                }
            }
        } catch (error) {
            console.error('Failed to load tags for account filter:', error);
        }
    }

    populateAccountFilterTagsDropdown() {
        const container = document.getElementById('account-filter-tags');
        if (!container) return;

        container.innerHTML = '';

        if (!this.allAccountFilterTagSets || this.allAccountFilterTagSets.length === 0) {
            container.innerHTML = '<div style="padding: 8px; color: var(--color-text-lighter); font-style: italic;">' + t('budget', 'No tags available') + '</div>';
            return;
        }

        const chipsArea = document.createElement('div');
        chipsArea.className = 'filter-tags-chips';

        const input = document.createElement('input');
        input.type = 'text';
        input.id = 'account-filter-tags-input';
        input.className = 'tags-autocomplete-input';
        input.placeholder = t('budget', 'Type to filter tags...');

        const dropdown = document.createElement('div');
        dropdown.className = 'tags-autocomplete-dropdown';
        dropdown.style.display = 'none';

        container.appendChild(chipsArea);
        container.appendChild(input);
        container.appendChild(dropdown);

        const allTags = [];
        this.allAccountFilterTagSets.forEach(tagSet => {
            if (tagSet.tags && tagSet.tags.length > 0) {
                tagSet.tags.forEach(tag => {
                    allTags.push({
                        id: tag.id,
                        name: tag.name,
                        color: tag.color,
                        tagSetName: tagSet.name,
                        tagSetId: tagSet.id
                    });
                });
            }
        });

        const renderSelectedChips = () => {
            chipsArea.innerHTML = '';
            if (this.selectedAccountFilterTags.size === 0) {
                chipsArea.style.display = 'none';
                return;
            }
            chipsArea.style.display = 'flex';
            this.selectedAccountFilterTags.forEach(tagId => {
                const tag = allTags.find(tg => tg.id === tagId);
                if (!tag) return;
                const chip = document.createElement('span');
                chip.className = 'filter-tag-chip';
                chip.style.cssText = `display: inline-flex; align-items: center; gap: 4px; background-color: ${dom.escapeHtml(tag.color || '#888')}; color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; cursor: pointer;`;
                chip.innerHTML = `${dom.escapeHtml(tag.name)} <span style="font-weight: bold; margin-left: 2px;">×</span>`;
                chip.addEventListener('click', () => {
                    this.selectedAccountFilterTags.delete(tagId);
                    renderSelectedChips();
                    renderDropdown(input.value);
                });
                chipsArea.appendChild(chip);
            });
        };

        const renderDropdown = (filter = '') => {
            const filtered = filter
                ? allTags.filter(tg =>
                    tg.name.toLowerCase().includes(filter.toLowerCase()) ||
                    tg.tagSetName.toLowerCase().includes(filter.toLowerCase())
                )
                : allTags;

            const grouped = {};
            filtered.forEach(tag => {
                if (!grouped[tag.tagSetId]) {
                    grouped[tag.tagSetId] = { name: tag.tagSetName, tags: [] };
                }
                grouped[tag.tagSetId].tags.push(tag);
            });

            let html = '';
            Object.values(grouped).forEach(group => {
                html += `<div class="tags-group-header">${dom.escapeHtml(group.name)}</div>`;
                group.tags.forEach(tag => {
                    const isSelected = this.selectedAccountFilterTags.has(tag.id);
                    html += `
                        <div class="tags-autocomplete-item ${isSelected ? 'selected' : ''}"
                             data-tag-id="${tag.id}">
                            <span class="tag-chip"
                                  style="display: inline-flex; align-items: center; background-color: ${dom.escapeHtml(tag.color || '#888')}; color: white;
                                         padding: 2px 6px; border-radius: 10px; font-size: 10px; line-height: 14px; margin-right: 4px;">
                                ${dom.escapeHtml(tag.name)}
                            </span>
                            <span class="tag-check">${isSelected ? '✓' : ''}</span>
                        </div>
                    `;
                });
            });

            dropdown.innerHTML = html || '<div class="tags-autocomplete-empty">' + t('budget', 'No tags found') + '</div>';
            dropdown.style.display = 'block';
        };

        input.addEventListener('focus', () => renderDropdown(input.value));
        input.addEventListener('input', () => renderDropdown(input.value));

        // Handle tag selection on mousedown for Safari/WebKit compatibility
        dropdown.addEventListener('mousedown', (e) => {
            e.preventDefault();
            e.stopPropagation();

            const item = e.target.closest('.tags-autocomplete-item');
            if (item) {
                const tagId = parseInt(item.dataset.tagId);
                const clickedTag = allTags.find(tg => tg.id === tagId);
                if (!clickedTag) return;

                // Single selection per tag set
                const tagsFromSameSet = allTags.filter(tg => tg.tagSetId === clickedTag.tagSetId);
                tagsFromSameSet.forEach(tg => {
                    if (tg.id !== tagId) {
                        this.selectedAccountFilterTags.delete(tg.id);
                    }
                });

                if (this.selectedAccountFilterTags.has(tagId)) {
                    this.selectedAccountFilterTags.delete(tagId);
                } else {
                    this.selectedAccountFilterTags.add(tagId);
                }

                renderSelectedChips();
                renderDropdown(input.value);
            }
        });

        // Prevent clicks inside dropdown from bubbling
        dropdown.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        renderSelectedChips();

        document.addEventListener('click', (e) => {
            if (!container.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }

    applyAccountFilters() {
        // Collect filter values
        this.accountFilters = {
            category: document.getElementById('account-filter-category')?.value || '',
            type: document.getElementById('account-filter-type')?.value || '',
            status: document.getElementById('account-filter-status')?.value || '',
            dateFrom: document.getElementById('account-filter-date-from')?.value || '',
            dateTo: document.getElementById('account-filter-date-to')?.value || '',
            amountMin: document.getElementById('account-filter-amount-min')?.value || '',
            amountMax: document.getElementById('account-filter-amount-max')?.value || '',
            search: document.getElementById('account-filter-search')?.value || '',
            tagIds: Array.from(this.selectedAccountFilterTags)
        };

        // Reset to first page and reload
        this.accountCurrentPage = 1;
        this.loadAccountTransactions(this.currentAccount.id);
    }

    clearAccountFilters() {
        // Clear all filter inputs
        document.getElementById('account-filter-category').value = '';
        document.getElementById('account-filter-type').value = '';
        document.getElementById('account-filter-status').value = '';
        clearDateValue('account-filter-date-from');
        clearDateValue('account-filter-date-to');
        document.getElementById('account-filter-amount-min').value = '';
        document.getElementById('account-filter-amount-max').value = '';
        document.getElementById('account-filter-search').value = '';

        // Clear tags
        this.selectedAccountFilterTags.clear();
        this.populateAccountFilterTagsDropdown();

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

    async reconcileAccount(accountId) {
        // Navigate to transactions page and open reconciliation panel
        window.location.hash = '#/transactions';
        this.app.showView('transactions');
        await this.app.loadTransactions();

        const reconcileAccountSelect = document.getElementById('reconcile-account');
        if (reconcileAccountSelect) {
            // Populate the dropdown if empty (populateFilterDropdowns only runs on filter toggle)
            if (reconcileAccountSelect.options.length <= 1 && this.accounts) {
                reconcileAccountSelect.innerHTML = '<option value="">' + t('budget', 'Select account to reconcile') + '</option>';
                this.accounts.forEach(account => {
                    reconcileAccountSelect.innerHTML += `<option value="${account.id}">${account.name}</option>`;
                });
            }
            reconcileAccountSelect.value = accountId;
        }
        const reconcilePanel = document.getElementById('reconcile-panel');
        if (reconcilePanel) {
            reconcilePanel.style.display = 'block';
        }
    }

    toggleTransactionReconciliation(transactionId, reconciled) {
        // Reconcile checkboxes are tracked locally; actual save happens on finish.
    }

    finishReconciliation() {
        // Delegated to TransactionsModule where the reconcile panel lives.
        if (this.app.transactionsModule) {
            this.app.transactionsModule.finishReconciliation();
        }
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
                showWarning(t('budget', 'No accounts available. Please create an account first.'));
                return;
            }
            showWarning(t('budget', 'Please select an account'));
            return;
        }
        if (!date) {
            showWarning(t('budget', 'Please enter a date'));
            return;
        }
        if (!type) {
            showWarning(t('budget', 'Please select a transaction type'));
            return;
        }
        if (amount === null || amount <= 0) {
            showWarning(t('budget', 'Please enter a valid amount'));
            return;
        }
        if (!description) {
            showWarning(t('budget', 'Please enter a description'));
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

                showSuccess(t('budget', 'Transaction saved successfully'));
                this.hideModals();
                this.loadTransactions();
                // Also reload account transactions if we're on account details view
                if (this.currentView === 'account-details' && this.currentAccount) {
                    this.loadAccountTransactions(this.currentAccount.id);
                }
            } else {
                // Try to get the actual error message from backend
                let errorMessage = t('budget', 'Failed to save transaction');
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
            showError(error.message || t('budget', 'Failed to save transaction'));
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
                this.showQuickAddMessage(t('budget', 'No accounts available. Please create an account first.'), 'error');
                return;
            }
            this.showQuickAddMessage(t('budget', 'Please select an account'), 'error');
            return;
        }
        if (!date) {
            this.showQuickAddMessage(t('budget', 'Please enter a date'), 'error');
            return;
        }
        if (!type) {
            this.showQuickAddMessage(t('budget', 'Please select a transaction type'), 'error');
            return;
        }
        if (amount === null || amount <= 0) {
            this.showQuickAddMessage(t('budget', 'Please enter a valid amount'), 'error');
            return;
        }
        if (!description) {
            this.showQuickAddMessage(t('budget', 'Please enter a description'), 'error');
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
                this.showQuickAddMessage(t('budget', 'Transaction added successfully!'), 'success');
                this.resetQuickAddForm();
                // Reload transactions if on transactions view
                if (this.app.currentView === 'transactions') {
                    this.app.loadTransactions();
                }
                // Reload dashboard to update totals
                if (this.app.currentView === 'dashboard') {
                    this.app.loadDashboard();
                }
            } else {
                let errorMessage = t('budget', 'Failed to add transaction');
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
            this.showQuickAddMessage(error.message || t('budget', 'Failed to add transaction'), 'error');
        }
    }

    resetQuickAddForm() {
        const form = document.getElementById('quick-add-form');
        if (form) {
            form.reset();
            // Set today's date as default
            const dateInput = document.getElementById('quick-add-date');
            if (dateInput) {
                setDateValue(dateInput, formatters.getTodayDateString());
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
            accountSelect.innerHTML = '<option value="">' + t('budget', 'Select account') + '</option>';
            this.accounts.forEach(account => {
                const option = document.createElement('option');
                option.value = account.id;
                option.textContent = account.name;
                accountSelect.appendChild(option);
            });
        }

        // Populate category dropdown (hierarchical with indentation)
        const categorySelect = document.getElementById('quick-add-category');
        if (categorySelect) {
            categorySelect.innerHTML = '<option value="">' + t('budget', 'No category') + '</option>';
            dom.populateCategorySelect(categorySelect, this.categoryTree || this.categories);
        }

        // Set today's date as default
        const dateInput = document.getElementById('quick-add-date');
        if (dateInput && !dateInput.value) {
            setDateValue(dateInput, formatters.getTodayDateString());
        }
    }

    async saveAccount() {
        try {
            // Get form elements
            const nameElement = document.getElementById('account-name');
            const typeElement = document.getElementById('account-type');

            if (!nameElement) {
                console.error('Account name element not found');
                showError(t('budget', 'Form error: Account name field not found'));
                return;
            }

            if (!typeElement) {
                console.error('Account type element not found');
                showError(t('budget', 'Form error: Account type field not found'));
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
                currency: getFormValue('account-currency', 'USD'),
                institution: getFormValue('form-institution'),
                accountHolderName: getFormValue('account-holder-name'),
                openingDate: getFormValue('account-opening-date'),
                interestRate: getFormValue('account-interest-rate', null, true),
                creditLimit: getFormValue('account-credit-limit', null, true),
                overdraftLimit: getFormValue('account-overdraft-limit', null, true),
                minimumPayment: getFormValue('account-minimum-payment', null, true),
                interestEnabled: document.getElementById('account-interest-enabled')?.checked || false,
                compoundingFrequency: getFormValue('account-compounding-frequency', 'daily')
            };

            // Only include balance on create — on edit, balance is managed by transactions
            if (!isEdit) {
                formData.balance = getFormValue('account-balance', 0, true);
            }

            // Include opening balance on edit if changed
            if (isEdit) {
                const openingBalance = getFormValue('account-opening-balance', null, true);
                if (openingBalance !== null) {
                    formData.openingBalance = openingBalance;
                }
            }

            // Sensitive fields: only include if user entered a value
            // For edits, empty means "keep existing" - don't send to avoid overwriting
            const sensitiveFields = ['accountNumber', 'routingNumber', 'sortCode', 'iban', 'swiftBic', 'walletAddress'];
            const sensitiveFieldIds = {
                accountNumber: 'form-account-number',
                routingNumber: 'form-routing-number',
                sortCode: 'form-sort-code',
                iban: 'form-iban',
                swiftBic: 'form-swift-bic',
                walletAddress: 'form-wallet-address'
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
                showWarning(t('budget', 'Please enter an account name'));
                nameElement.focus();
                return;
            }

            if (!formData.type || formData.type === '') {
                console.error('Account type is empty');
                showWarning(t('budget', 'Please select an account type'));
                typeElement.focus();
                return;
            }

            // Validate account name length
            if (formData.name.length > 255) {
                showWarning(t('budget', 'Account name is too long (maximum 255 characters)'));
                nameElement.focus();
                return;
            }

            // Validate balance on create only (balance is managed by transactions on edit)
            if (!isEdit && isNaN(formData.balance)) {
                showWarning(t('budget', 'Please enter a valid balance amount'));
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

                showSuccess(t('budget', 'Account saved successfully'));
                this.hideModals();
                await this.loadAccounts();
                await this.loadInitialData(); // Refresh dropdowns

                // Refresh dashboard if currently viewing it
                if (window.location.hash === '' || window.location.hash === '#/dashboard') {
                    await this.app.loadDashboard();
                }

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
                let errorMessage = t('budget', 'Failed to save account');
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
            const errorMsg = error.message || t('budget', 'Unknown error occurred');
            showError(t('budget', 'Failed to save account: {error}', { error: errorMsg }));

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
            title.textContent = t('budget', 'Edit Account');
            this.loadAccountData(accountId);
        } else {
            title.textContent = t('budget', 'Add Account');
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

            // Balance is managed by transactions — show as read-only on edit
            const balanceField = document.getElementById('account-balance');
            if (balanceField) {
                balanceField.value = account.balance;
                balanceField.disabled = true;
                balanceField.title = t('budget', 'Balance is calculated from transactions');
            }
            const balanceLabel = document.getElementById('account-balance-label');
            if (balanceLabel) {
                balanceLabel.textContent = t('budget', 'Current Balance');
            }

            // Show opening balance field on edit
            const openingBalanceGroup = document.getElementById('opening-balance-group');
            const openingBalanceField = document.getElementById('account-opening-balance');
            if (openingBalanceGroup && openingBalanceField) {
                openingBalanceGroup.style.display = '';
                openingBalanceField.value = account.openingBalance ?? 0;
            }

            document.getElementById('account-currency').value = account.currency;
            document.getElementById('form-institution').value = account.institution || '';

            // Sensitive fields: don't populate with masked values, use placeholder instead
            // This prevents the masked value from being saved back and corrupting the data
            const sensitiveFields = [
                { id: 'form-account-number', hasValue: !!account.accountNumber },
                { id: 'form-routing-number', hasValue: !!account.routingNumber },
                { id: 'form-sort-code', hasValue: !!account.sortCode },
                { id: 'form-iban', hasValue: !!account.iban },
                { id: 'form-swift-bic', hasValue: !!account.swiftBic },
                { id: 'form-wallet-address', hasValue: !!account.walletAddress }
            ];

            sensitiveFields.forEach(field => {
                const element = document.getElementById(field.id);
                if (element) {
                    element.value = ''; // Don't populate with masked value
                    if (field.hasValue) {
                        element.placeholder = t('budget', '••••••••  (leave blank to keep current)');
                    } else {
                        element.placeholder = '';
                    }
                }
            });

            document.getElementById('account-holder-name').value = account.accountHolderName || '';
            setDateValue('account-opening-date', account.openingDate || '');
            document.getElementById('account-interest-rate').value = account.interestRate || '';
            document.getElementById('account-credit-limit').value = account.creditLimit || '';
            document.getElementById('account-overdraft-limit').value = account.overdraftLimit || '';
            document.getElementById('account-minimum-payment').value = account.minimumPayment || '';

            const interestEnabledEl = document.getElementById('account-interest-enabled');
            if (interestEnabledEl) interestEnabledEl.checked = account.interestEnabled || false;
            const compoundingEl = document.getElementById('account-compounding-frequency');
            if (compoundingEl) compoundingEl.value = account.compoundingFrequency || 'daily';
        } catch (error) {
            console.error('Failed to load account data:', error);
            showError(t('budget', 'Failed to load account data'));
        }
    }

    resetAccountForm() {
        const form = document.getElementById('account-form');
        if (!form) {
            console.error('Account form not found');
            return;
        }
        form.reset();
        clearDateValue('account-opening-date');

        const accountId = document.getElementById('account-id');
        const currency = document.getElementById('account-currency');
        const balance = document.getElementById('account-balance');

        if (accountId) accountId.value = '';
        if (currency) currency.value = this.settings?.default_currency || 'GBP';
        if (balance) {
            balance.value = '0';
            balance.disabled = false;
            balance.title = '';
        }
        const balanceLabel = document.getElementById('account-balance-label');
        if (balanceLabel) {
            balanceLabel.textContent = t('budget', 'Starting Balance');
        }

        // Hide opening balance field on new account form
        const openingBalanceGroup = document.getElementById('opening-balance-group');
        if (openingBalanceGroup) {
            openingBalanceGroup.style.display = 'none';
        }
        const openingBalanceField = document.getElementById('account-opening-balance');
        if (openingBalanceField) {
            openingBalanceField.value = '0';
        }
    }

    async editAccount(id) {
        this.showAccountModal(id);
    }

    async deleteAccount(id) {
        if (!confirm(t('budget', 'Are you sure you want to delete this account? This action cannot be undone.'))) {
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
                showSuccess(t('budget', 'Account deleted successfully'));
                await this.loadAccounts();
                await this.loadInitialData(); // Refresh dropdowns

                // Refresh dashboard if currently viewing it
                if (window.location.hash === '' || window.location.hash === '#/dashboard') {
                    await this.app.loadDashboard();
                }
            } else {
                const error = await response.json();
                throw new Error(error.error || t('budget', 'Failed to delete account'));
            }
        } catch (error) {
            console.error('Failed to delete account:', error);
            showError(t('budget', 'Failed to delete account: {error}', { error: error.message }));
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
                    document.getElementById('interest-enabled-group').style.display = 'block';
                    document.getElementById('compounding-frequency-group').style.display = 'block';
                }
                break;

            case 'credit_card':
                // Show credit card specific fields
                document.getElementById('credit-limit-group').style.display = 'block';
                document.getElementById('interest-rate-group').style.display = 'block';
                document.getElementById('interest-enabled-group').style.display = 'block';
                document.getElementById('compounding-frequency-group').style.display = 'block';
                document.getElementById('minimum-payment-group').style.display = 'block';
                break;

            case 'loan':
            case 'mortgage':
            case 'line_of_credit':
                // Show loan/liability specific fields
                document.getElementById('interest-rate-group').style.display = 'block';
                document.getElementById('interest-enabled-group').style.display = 'block';
                document.getElementById('compounding-frequency-group').style.display = 'block';
                document.getElementById('minimum-payment-group').style.display = 'block';
                break;

            case 'investment':
            case 'money_market':
                // Show investment account fields
                document.getElementById('swift-bic-group').style.display = 'block';
                document.getElementById('interest-rate-group').style.display = 'block';
                document.getElementById('interest-enabled-group').style.display = 'block';
                document.getElementById('compounding-frequency-group').style.display = 'block';
                if (requirements.iban) {
                    document.getElementById('iban-group').style.display = 'block';
                }
                break;

            case 'cash':
                // No additional fields for cash accounts
                break;

            case 'cryptocurrency':
                // Show wallet address field only
                const walletGroup = document.getElementById('wallet-address-group');
                if (walletGroup) {
                    walletGroup.style.display = 'block';
                }
                // Update balance step for crypto precision
                const balanceInput = document.getElementById('account-balance');
                if (balanceInput) {
                    balanceInput.step = '0.00000001';
                }
                break;
        }

        // Reset balance step to fiat default for non-crypto types
        if (accountType !== 'cryptocurrency') {
            const balanceInput = document.getElementById('account-balance');
            if (balanceInput) {
                balanceInput.step = '0.01';
            }
        }
    }

    async setupInstitutionAutocomplete() {
        const input = document.getElementById('form-institution');
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
        document.getElementById('form-institution').value = bankName;
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
            feedback.innerHTML = '<span class="icon-checkmark"></span> ' + t('budget', 'Valid');
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
                label: t('budget', 'Checking Account')
            },
            'savings': {
                icon: 'icon-folder',
                color: '#50E3C2',
                label: t('budget', 'Savings Account')
            },
            'credit_card': {
                icon: 'icon-category-integration',
                color: '#F5A623',
                label: t('budget', 'Credit Card')
            },
            'investment': {
                icon: 'icon-trending',
                color: '#7ED321',
                label: t('budget', 'Investment')
            },
            'loan': {
                icon: 'icon-file',
                color: '#D0021B',
                label: t('budget', 'Loan')
            },
            'cash': {
                icon: 'icon-category-monitoring',
                color: '#9013FE',
                label: t('budget', 'Cash')
            },
            'cryptocurrency': {
                icon: 'icon-link',
                color: '#F7931A',
                label: t('budget', 'Cryptocurrency')
            }
        };

        return typeMap[accountType] || {
            icon: 'icon-folder',
            color: '#999999',
            label: t('budget', 'Unknown')
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
                    tooltip: t('budget', 'Credit utilization very high')
                };
            } else if (utilization > 0.7) {
                return {
                    class: 'warning',
                    icon: 'icon-triangle-s',
                    tooltip: t('budget', 'Credit utilization high')
                };
            }
        }

        // For regular accounts, check for negative balances
        if (balance < 0 && type !== 'credit_card' && type !== 'loan') {
            return {
                class: 'warning',
                icon: 'icon-triangle-s',
                tooltip: t('budget', 'Negative balance')
            };
        }

        // Check overdraft limits
        if (account.overdraftLimit && balance < -account.overdraftLimit) {
            return {
                class: 'critical',
                icon: 'icon-error',
                tooltip: t('budget', 'Exceeds overdraft limit')
            };
        }

        return {
            class: 'healthy',
            icon: 'icon-checkmark',
            tooltip: t('budget', 'Account is in good standing')
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

    exportAccountTransactions() {
        if (!this.accountTransactions || this.accountTransactions.length === 0) {
            OC.Notification.showTemporary(t('budget', 'No transactions to export'));
            return;
        }

        const account = this.currentAccount;
        const currency = account?.currency || this.settings?.currency || 'USD';
        const categories = this.app.categories || [];

        const getCategoryName = (categoryId) => {
            const cat = categories.find(c => c.id === categoryId);
            return cat ? cat.name : '';
        };

        const rows = [[t('budget', 'Date'), t('budget', 'Description'), t('budget', 'Type'), t('budget', 'Amount'), t('budget', 'Category')]];
        for (const t of this.accountTransactions) {
            rows.push([
                t.date,
                `"${(t.description || '').replace(/"/g, '""')}"`,
                t.type,
                t.amount,
                `"${getCategoryName(t.categoryId)}"`
            ]);
        }

        const csv = rows.map(r => r.join(',')).join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${(account?.name || 'account').replace(/[^a-zA-Z0-9]/g, '_')}_transactions.csv`;
        a.click();
        URL.revokeObjectURL(url);
    }
}
