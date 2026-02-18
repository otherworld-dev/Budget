/**
 * TransactionsModule - Handles all transaction-related functionality
 *
 * This module manages:
 * - Transaction filtering, sorting, and pagination
 * - Bulk operations (edit, delete, reconcile)
 * - Inline editing of transactions
 * - Transaction matching and linking (transfers)
 * - Transaction splits
 * - Reconciliation mode
 */

import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';

export default class TransactionsModule {
    constructor(app) {
        this.app = app;

        // Transaction state - store on app for shared access
        this.app.transactionFilters = {};
        this.app.currentSort = { field: 'date', direction: 'desc' };
        this.app.currentPage = 1;
        this.app.rowsPerPage = 25;
        this.selectedTransactions = new Set();
        this.reconcileMode = false;
        this.reconcileData = null;

        // Inline editing state
        this.currentEditingCell = null;
        this.originalValue = null;

        // Filter timeout for debouncing
        this.filterTimeout = null;
    }

    // State proxies
    get accounts() { return this.app.accounts; }
    get categories() { return this.app.categories; }
    get transactions() { return this.app.transactions; }
    get settings() { return this.app.settings; }
    get categoryTree() { return this.app.categoryTree; }
    get allCategories() { return this.app.allCategories; }

    // Helper proxies
    formatCurrency(amount, currency) {
        return formatters.formatCurrency(amount, currency, this.settings);
    }

    formatDate(dateStr) {
        return formatters.formatDate(dateStr, this.settings);
    }

    escapeHtml(text) {
        return dom.escapeHtml(text);
    }

    getPrimaryCurrency() {
        return this.app.getPrimaryCurrency();
    }

    // ===========================
    // Event Listeners Setup
    // ===========================

    setupTransactionEventListeners() {
        // Initialize transaction state only if enhanced UI is present
        const hasEnhancedUI = document.getElementById('transactions-filters');

        if (hasEnhancedUI) {
            this.app.transactionFilters = {};
            this.app.currentSort = { field: 'date', direction: 'desc' };
            this.app.currentPage = 1;
            this.app.rowsPerPage = 25;
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
            'filter-account', 'filter-category', 'filter-type', 'filter-status',
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
                this.app.loadTransactions();
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
                this.app.rowsPerPage = parseInt(e.target.value);
                this.app.currentPage = 1;
                this.app.loadTransactions();
            });
        }

        const prevPageBtn = document.getElementById('prev-page-btn');
        if (prevPageBtn) {
            prevPageBtn.addEventListener('click', () => {
                if (this.app.currentPage > 1) {
                    this.app.currentPage--;
                    this.app.loadTransactions();
                }
            });
        }

        const nextPageBtn = document.getElementById('next-page-btn');
        if (nextPageBtn) {
            nextPageBtn.addEventListener('click', () => {
                this.app.currentPage++;
                this.app.loadTransactions();
            });
        }

        // Bottom pagination buttons
        const prevPageBtnBottom = document.getElementById('prev-page-btn-bottom');
        if (prevPageBtnBottom) {
            prevPageBtnBottom.addEventListener('click', () => {
                if (this.app.currentPage > 1) {
                    this.app.currentPage--;
                    this.app.loadTransactions();
                }
            });
        }

        const nextPageBtnBottom = document.getElementById('next-page-btn-bottom');
        if (nextPageBtnBottom) {
            nextPageBtnBottom.addEventListener('click', () => {
                this.app.currentPage++;
                this.app.loadTransactions();
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
        this.app.transactionFilters = {
            account: document.getElementById('filter-account')?.value || '',
            category: document.getElementById('filter-category')?.value || '',
            type: document.getElementById('filter-type')?.value || '',
            status: document.getElementById('filter-status')?.value || '',
            dateFrom: document.getElementById('filter-date-from')?.value || '',
            dateTo: document.getElementById('filter-date-to')?.value || '',
            amountMin: document.getElementById('filter-amount-min')?.value || '',
            amountMax: document.getElementById('filter-amount-max')?.value || '',
            search: document.getElementById('filter-search')?.value || ''
        };

        // Always auto-apply filters (including when clearing them)
        this.app.currentPage = 1;
        this.app.loadTransactions();
    }

    clearFilters() {
        const filterInputs = [
            'filter-account', 'filter-category', 'filter-type', 'filter-status',
            'filter-date-from', 'filter-date-to', 'filter-amount-min',
            'filter-amount-max', 'filter-search'
        ];

        filterInputs.forEach(inputId => {
            const input = document.getElementById(inputId);
            if (input) {
                input.value = '';
            }
        });

        this.app.transactionFilters = {};
        this.app.currentPage = 1;
        this.app.loadTransactions();
    }

    sortTransactions(field) {
        if (this.app.currentSort.field === field) {
            this.app.currentSort.direction = this.app.currentSort.direction === 'asc' ? 'desc' : 'asc';
        } else {
            this.app.currentSort.field = field;
            this.app.currentSort.direction = 'asc';
        }

        // Update sort indicators
        document.querySelectorAll('.sort-indicator').forEach(indicator => {
            indicator.className = 'sort-indicator';
        });

        const currentHeader = document.querySelector(`[data-sort="${field}"] .sort-indicator`);
        if (currentHeader) {
            currentHeader.className = `sort-indicator ${this.app.currentSort.direction}`;
        }

        this.app.loadTransactions();
    }

    // Bulk operations
    toggleBulkMode() {
        const bulkPanel = document.getElementById('bulk-actions-panel');
        const bulkBtn = document.getElementById('bulk-actions-btn');
        const selectColumn = document.querySelectorAll('.select-column');

        if (bulkPanel.style.display === 'none') {
            bulkPanel.style.display = 'block';
            bulkBtn.classList.add('active');
            selectColumn.forEach(col => col.style.display = 'table-cell');
            this.app.loadTransactions(); // Reload to show checkboxes
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
        this.app.loadTransactions(); // Reload to hide checkboxes
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
                this.app.loadTransactions();
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
                this.app.loadTransactions();
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
                this.app.loadTransactions();
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
                this.app.loadTransactions();

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

    // Reconciliation
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
            this.app.loadTransactions();
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

        this.app.loadTransactions();
    }

    async toggleTransactionReconciliation(transactionId, checked) {
        // Implementation would update transaction reconciliation status
        // This is a placeholder for the actual API call
        console.log('Toggle reconciliation for transaction:', transactionId, checked);
    }

    // Rendering
    renderTransactionsTable(transactions) {
        const today = new Date().toISOString().split('T')[0];
        return transactions.map(t => {
            const isSplit = t.isSplit || t.is_split;
            const isPending = t.date > today;
            const rowClasses = [isSplit ? 'split-transaction' : '', isPending ? 'pending-transaction' : ''].filter(Boolean).join(' ');
            const categoryDisplay = isSplit
                ? '<span class="split-indicator" title="This transaction is split across multiple categories">Split</span>'
                : (t.categoryName ? `<span class="category-name">${this.escapeHtml(t.categoryName)}</span>` : '-');
            const pendingBadge = isPending ? '<span class="pending-badge">Pending</span>' : '';

            return `
            <tr class="${rowClasses}">
                <td class="select-column">
                    <input type="checkbox" class="transaction-checkbox" data-transaction-id="${t.id}">
                </td>
                <td>${this.formatDate(t.date)}${pendingBadge}</td>
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

    renderTransactionsList(transactions) {
        return transactions.map(t => `
            <div class="transaction-item">
                <span class="transaction-date">${this.formatDate(t.date)}</span>
                <span class="transaction-description">${t.description}</span>
                <span class="amount ${t.type}">${this.formatCurrency(t.amount, t.accountCurrency)}</span>
            </div>
        `).join('');
    }

    populateTransactionModalDropdowns() {
        // Populate account dropdown
        const accountSelect = document.getElementById('transaction-account');
        if (accountSelect && this.accounts) {
            // Save current value
            const currentValue = accountSelect.value;

            // Clear and rebuild options
            accountSelect.innerHTML = '<option value="">Choose an account</option>';
            this.accounts.forEach(account => {
                const option = document.createElement('option');
                option.value = account.id;
                option.textContent = account.name;
                accountSelect.appendChild(option);
            });

            // Restore previous value if it exists
            if (currentValue) {
                accountSelect.value = currentValue;
            }
        }

        // Populate "To Account" dropdown for transfers
        const toAccountSelect = document.getElementById('transfer-to-account');
        if (toAccountSelect && this.accounts) {
            // Save current value
            const currentValue = toAccountSelect.value;

            // Clear and rebuild options
            toAccountSelect.innerHTML = '<option value="">Select account...</option>';
            this.accounts.forEach(account => {
                const option = document.createElement('option');
                option.value = account.id;
                option.textContent = account.name;
                toAccountSelect.appendChild(option);
            });

            // Restore previous value if it exists
            if (currentValue) {
                toAccountSelect.value = currentValue;
            }
        }

        // Populate category dropdown
        const categorySelect = document.getElementById('transaction-category');
        if (categorySelect && this.categories) {
            // Save current value
            const currentValue = categorySelect.value;

            // Clear and rebuild options
            categorySelect.innerHTML = '<option value="">No category</option>';

            // Use flat categories list if available, otherwise use hierarchical
            const categoriesList = this.allCategories || this.categories;
            this.renderCategoryOptions(categorySelect, categoriesList);

            // Restore previous value if it exists
            if (currentValue) {
                categorySelect.value = currentValue;
            }
        }
    }

    renderCategoryOptions(selectElement, categories, level = 0) {
        categories.forEach(category => {
            const option = document.createElement('option');
            option.value = category.id;
            option.textContent = '  '.repeat(level) + category.name;
            selectElement.appendChild(option);

            // Recursively add child categories
            if (category.children && category.children.length > 0) {
                this.renderCategoryOptions(selectElement, category.children, level + 1);
            }
        });
    }

    showTransactionModal(transaction = null, preSelectedAccountId = null) {
        const modal = document.getElementById('transaction-modal');
        if (modal) {
            // Populate account and category dropdowns first
            this.populateTransactionModalDropdowns();

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

                // Load tag selectors for this transaction
                this.app.renderTransactionTagSelectors(transaction.categoryId, transaction.id);
            } else {
                // Clear form (new transaction mode)
                document.getElementById('transaction-form').reset();
                document.getElementById('transaction-id').value = '';
                document.getElementById('transaction-date').value = new Date().toISOString().split('T')[0];

                // Pre-select account if provided
                if (preSelectedAccountId) {
                    document.getElementById('transaction-account').value = preSelectedAccountId;
                }

                // Clear tag selectors
                this.app.renderTransactionTagSelectors(null, null);
            }

            // Set up category change listener to update tag selectors
            const categorySelect = document.getElementById('transaction-category');
            if (categorySelect) {
                // Remove old listener if exists
                const oldListener = categorySelect.onchange;
                categorySelect.onchange = () => {
                    if (oldListener) oldListener();
                    const transactionId = document.getElementById('transaction-id').value;
                    this.app.renderTransactionTagSelectors(categorySelect.value || null, transactionId || null);
                };
            }

            // Set up transaction type change listener to show/hide transfer fields
            const typeSelect = document.getElementById('transaction-type');
            const toAccountWrapper = document.getElementById('transfer-to-account-wrapper');
            if (typeSelect && toAccountWrapper) {
                const handleTypeChange = () => {
                    if (typeSelect.value === 'transfer') {
                        toAccountWrapper.style.display = 'block';
                    } else {
                        toAccountWrapper.style.display = 'none';
                    }
                };

                // Set up listener
                typeSelect.onchange = handleTypeChange;

                // Initialize visibility based on current value
                handleTypeChange();
            }

            modal.style.display = 'flex';
        }
    }

    async editTransaction(id) {
        // First check TransactionsModule's list
        let transaction = this.transactions.find(t => t.id === id);

        // If not found, check AccountsModule's accountTransactions (for account detail view)
        if (!transaction && this.app.accountsModule?.accountTransactions) {
            transaction = this.app.accountsModule.accountTransactions.find(t => t.id === id);
        }

        // If still not found, fetch from API
        if (!transaction) {
            try {
                const response = await this.app.api.get(`/apps/budget/api/transactions/${id}`);
                transaction = response.data;
            } catch (error) {
                console.error('Failed to fetch transaction for editing:', error);
                this.app.showNotification('Failed to load transaction', 'error');
                return;
            }
        }

        if (transaction) {
            this.showTransactionModal(transaction);
        }
    }

    async saveTransaction() {
        // Get form values
        const id = document.getElementById('transaction-id').value;
        const date = document.getElementById('transaction-date').value;
        const accountId = parseInt(document.getElementById('transaction-account').value);
        const type = document.getElementById('transaction-type').value;
        const amount = parseFloat(document.getElementById('transaction-amount').value);
        const description = document.getElementById('transaction-description').value;
        const vendor = document.getElementById('transaction-vendor').value;
        const categoryId = document.getElementById('transaction-category').value;
        const notes = document.getElementById('transaction-notes').value;

        // Handle transfer creation (new transfers only, not editing)
        if (type === 'transfer' && !id) {
            const toAccountId = parseInt(document.getElementById('transfer-to-account').value);

            // Validation
            if (!toAccountId) {
                OC.Notification.showTemporary('Please select destination account');
                return;
            }
            if (toAccountId === accountId) {
                OC.Notification.showTemporary('Cannot transfer to same account');
                return;
            }

            try {
                // Step 1: Create debit transaction in FROM account
                const debitResponse = await fetch(OC.generateUrl('/apps/budget/api/transactions'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken
                    },
                    body: JSON.stringify({
                        date,
                        accountId: accountId,
                        type: 'debit',
                        amount,
                        description,
                        vendor: vendor || null,
                        categoryId: categoryId ? parseInt(categoryId) : null,
                        notes: notes || null
                    })
                });

                if (!debitResponse.ok) {
                    const error = await debitResponse.json();
                    throw new Error(error.error || 'Failed to create transfer debit transaction');
                }
                const debitData = await debitResponse.json();
                const debitTransactionId = debitData.id;

                // Step 2: Create credit transaction in TO account
                const creditResponse = await fetch(OC.generateUrl('/apps/budget/api/transactions'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken
                    },
                    body: JSON.stringify({
                        date,
                        accountId: toAccountId,
                        type: 'credit',
                        amount,
                        description,
                        vendor: vendor || null,
                        categoryId: categoryId ? parseInt(categoryId) : null,
                        notes: notes || null
                    })
                });

                if (!creditResponse.ok) {
                    const error = await creditResponse.json();
                    throw new Error(error.error || 'Failed to create transfer credit transaction');
                }
                const creditData = await creditResponse.json();
                const creditTransactionId = creditData.id;

                // Step 3: Link the two transactions using existing matching API
                const linkResponse = await fetch(
                    OC.generateUrl(`/apps/budget/api/transactions/${debitTransactionId}/link/${creditTransactionId}`),
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'requesttoken': OC.requestToken
                        }
                    }
                );

                if (!linkResponse.ok) {
                    const error = await linkResponse.json();
                    throw new Error(error.error || 'Failed to link transfer transactions');
                }

                // Success
                OC.Notification.showTemporary('Transfer created successfully');
                this.app.hideModals();
                await this.app.loadTransactions();
                await this.app.loadAccounts();

                // Refresh account details view if currently viewing an account
                await this.app.refreshCurrentAccountView();

                // Refresh dashboard if currently viewing it
                if (window.location.hash === '' || window.location.hash === '#/dashboard') {
                    await this.app.loadDashboard();
                }
                return;
            } catch (error) {
                console.error('Transfer creation failed:', error);
                OC.Notification.showTemporary(error.message || 'Failed to create transfer');
                return;
            }
        }

        // Build request data for regular expense/income transactions
        const data = {
            date,
            accountId,
            type,
            amount,
            description,
            vendor: vendor || null,
            categoryId: categoryId ? parseInt(categoryId) : null,
            notes: notes || null
        };

        try {
            let response;
            if (id) {
                // Update existing transaction
                response = await fetch(OC.generateUrl(`/apps/budget/api/transactions/${id}`), {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken
                    },
                    body: JSON.stringify(data)
                });
            } else {
                // Create new transaction
                response = await fetch(OC.generateUrl('/apps/budget/api/transactions'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken
                    },
                    body: JSON.stringify(data)
                });
            }

            if (response.ok) {
                OC.Notification.showTemporary(id ? 'Transaction updated' : 'Transaction created');
                this.app.hideModals();
                await this.app.loadTransactions();
                await this.app.loadAccounts(); // Refresh account balances

                // Refresh account details view if currently viewing an account
                await this.app.refreshCurrentAccountView();

                // Refresh dashboard if currently viewing it
                if (window.location.hash === '' || window.location.hash === '#/dashboard') {
                    await this.app.loadDashboard();
                }
            } else {
                const error = await response.json();
                throw new Error(error.error || 'Failed to save transaction');
            }
        } catch (error) {
            console.error('Failed to save transaction:', error);
            OC.Notification.showTemporary(error.message || 'Failed to save transaction');
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
                await this.app.loadTransactions();
                await this.app.loadAccounts(); // Refresh account balances

                // Refresh account details view if currently viewing an account
                await this.app.refreshCurrentAccountView();

                // Refresh dashboard if currently viewing it
                if (window.location.hash === '' || window.location.hash === '#/dashboard') {
                    await this.app.loadDashboard();
                }
            }
        } catch (error) {
            console.error('Failed to delete transaction:', error);
            OC.Notification.showTemporary('Failed to delete transaction');
        }
    }

    // Transaction matching and linking
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

    async handleLinkMatch(sourceId, targetId) {
        try {
            await this.linkTransactions(sourceId, targetId);
            OC.Notification.showTemporary('Transactions linked as transfer');

            // Close modal and refresh transactions
            document.getElementById('matching-modal').style.display = 'none';
            await this.app.loadTransactions();
        } catch (error) {
            OC.Notification.showTemporary(error.message || 'Failed to link transactions');
        }
    }

    async handleUnlinkTransaction(transactionId) {
        if (!confirm('Are you sure you want to unlink this transaction from its transfer pair?')) {
            return;
        }

        try {
            await this.unlinkTransaction(transactionId);
            OC.Notification.showTemporary('Transaction unlinked');
            await this.app.loadTransactions();
        } catch (error) {
            OC.Notification.showTemporary(error.message || 'Failed to unlink transaction');
        }
    }

    // Transaction splits
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

    async getTransactionSplits(transactionId) {
        const response = await fetch(OC.generateUrl(`/apps/budget/api/transactions/${transactionId}/splits`), {
            headers: { 'requesttoken': OC.requestToken }
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return await response.json();
    }

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
            await this.app.loadTransactions();
        } catch (error) {
            console.error('Failed to save splits:', error);
            OC.Notification.showTemporary(error.message || 'Failed to save splits');
        }
    }

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
            await this.app.loadTransactions();
        } catch (error) {
            console.error('Failed to unsplit transaction:', error);
            OC.Notification.showTemporary(error.message || 'Failed to unsplit transaction');
        }
    }

    hideSplitModal() {
        const modal = document.getElementById('split-modal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    // Bulk matching
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

    // Inline editing (placeholder - full implementation would be extensive)
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
            case 'tags':
                this.createTagsEditor(cell, transaction);
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
        dropdown.addEventListener('mousedown', (e) => {
            e.preventDefault();
        });

        dropdown.addEventListener('click', (e) => {
            e.stopPropagation();
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
            setTimeout(() => {
                if (!container.contains(document.activeElement)) {
                    dropdown.style.display = 'none';
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

    async createTagsEditor(cell, transaction) {
        const categoryId = transaction.categoryId;

        if (!categoryId) {
            cell.innerHTML = '<span style="color: var(--color-text-maxcontrast); font-size: 11px; font-style: italic;">Select category first</span>';
            setTimeout(() => this.cancelInlineEdit(cell), 1500);
            return;
        }

        cell.innerHTML = '<span style="color: var(--color-text-maxcontrast); font-size: 11px;">Loading...</span>';

        try {
            const tagSets = await this.loadTagSetsForCategory(categoryId);

            if (tagSets.length === 0) {
                cell.innerHTML = '<span style="color: var(--color-text-maxcontrast); font-size: 11px; font-style: italic;">No tag sets</span>';
                setTimeout(() => this.cancelInlineEdit(cell), 1500);
                return;
            }

            const currentTagIds = this.app.getTransactionTagIds(transaction.id);
            const selectedTags = new Set(currentTagIds);

            const container = document.createElement('div');
            container.className = 'tags-autocomplete';

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'tags-autocomplete-input';
            input.placeholder = 'Type to filter tags...';

            const dropdown = document.createElement('div');
            dropdown.className = 'tags-autocomplete-dropdown';
            dropdown.style.display = 'none';

            container.appendChild(input);
            container.appendChild(dropdown);

            const allTags = [];
            tagSets.forEach(tagSet => {
                tagSet.tags.forEach(tag => {
                    allTags.push({
                        id: tag.id,
                        name: tag.name,
                        color: tag.color,
                        tagSetName: tagSet.name,
                        tagSetId: tagSet.id
                    });
                });
            });

            const renderDropdown = (filter = '') => {
                const filtered = filter
                    ? allTags.filter(t =>
                        t.name.toLowerCase().includes(filter.toLowerCase()) ||
                        t.tagSetName.toLowerCase().includes(filter.toLowerCase())
                    )
                    : allTags;

                const grouped = {};
                filtered.forEach(tag => {
                    if (!grouped[tag.tagSetId]) {
                        grouped[tag.tagSetId] = {
                            name: tag.tagSetName,
                            tags: []
                        };
                    }
                    grouped[tag.tagSetId].tags.push(tag);
                });

                let html = '';
                Object.values(grouped).forEach(group => {
                    html += `<div class="tags-group-header">${this.escapeHtml(group.name)}</div>`;
                    group.tags.forEach(tag => {
                        const isSelected = selectedTags.has(tag.id);
                        html += `
                            <div class="tags-autocomplete-item ${isSelected ? 'selected' : ''}"
                                 data-tag-id="${tag.id}">
                                <span class="tag-chip"
                                      style="display: inline-flex; align-items: center; background-color: ${this.escapeHtml(tag.color)}; color: white;
                                             padding: 2px 6px; border-radius: 10px; font-size: 10px; line-height: 14px; margin-right: 4px;">
                                    ${this.escapeHtml(tag.name)}
                                </span>
                                <span class="tag-check">${isSelected ? '✓' : ''}</span>
                            </div>
                        `;
                    });
                });

                dropdown.innerHTML = html || '<div class="tags-autocomplete-empty">No tags found</div>';
                dropdown.style.display = 'block';
            };

            input.addEventListener('focus', () => renderDropdown(input.value));
            input.addEventListener('input', () => renderDropdown(input.value));

            dropdown.addEventListener('mousedown', (e) => {
                e.preventDefault();
            });

            dropdown.addEventListener('click', (e) => {
                e.stopPropagation();
                const item = e.target.closest('.tags-autocomplete-item');
                if (item) {
                    const tagId = parseInt(item.dataset.tagId);
                    const clickedTag = allTags.find(t => t.id === tagId);
                    if (!clickedTag) return;

                    const tagsFromSameSet = allTags.filter(t => t.tagSetId === clickedTag.tagSetId);
                    tagsFromSameSet.forEach(t => {
                        if (t.id !== tagId) {
                            selectedTags.delete(t.id);
                        }
                    });

                    if (selectedTags.has(tagId)) {
                        selectedTags.delete(tagId);
                    } else {
                        selectedTags.add(tagId);
                    }

                    renderDropdown(input.value);
                }
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    this.cancelInlineEdit(cell);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    this.saveTagsFromEditor(cell, selectedTags, transaction.id);
                }
            });

            input.addEventListener('blur', () => {
                setTimeout(() => {
                    if (cell.classList.contains('editing')) {
                        this.saveTagsFromEditor(cell, selectedTags, transaction.id);
                    }
                }, 200);
            });

            cell.innerHTML = '';
            cell.appendChild(container);
            input.focus();
            renderDropdown();

        } catch (error) {
            console.error('Failed to load tag sets:', error);
            cell.innerHTML = '<span style="color: var(--color-error); font-size: 11px;">Error loading tags</span>';
            setTimeout(() => this.cancelInlineEdit(cell), 1500);
        }
    }

    async saveTagsFromEditor(cell, selectedTags, transactionId) {
        const tagIds = Array.from(selectedTags);

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/transactions/${transactionId}/tags`), {
                method: 'PUT',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ tagIds })
            });

            if (response.ok) {
                await this.app.loadTransactionTags(transactionId);
                this.cancelInlineEdit(cell);

                const cellDisplay = cell.querySelector('.cell-display');
                if (cellDisplay) {
                    cellDisplay.innerHTML = this.app.renderTransactionTags(transactionId);
                }
            } else {
                console.error('Failed to save tags');
                this.cancelInlineEdit(cell);
            }
        } catch (error) {
            console.error('Failed to save tags:', error);
            this.cancelInlineEdit(cell);
        }
    }

    async loadTagSetsForCategory(categoryId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/tag-sets?categoryId=${categoryId}`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return await response.json();
        } catch (error) {
            console.error('Failed to load tag sets:', error);
            return [];
        }
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
            if (e.relatedTarget?.closest('.inline-type-toggle')) {
                return;
            }

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

        cell.classList.add('cell-saving');

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
                Object.assign(transaction, result);

                if (field === 'accountId') {
                    await this.app.loadAccounts();
                }

                this.app.renderEnhancedTransactionsTable();
                this.app.applyColumnVisibility();
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
        this.app.renderEnhancedTransactionsTable();
        this.app.applyColumnVisibility();
        this.currentEditingCell = null;
        this.originalValue = null;
    }

    closeAllInlineEditors() {
        const editingCells = document.querySelectorAll('.editable-cell.editing');
        editingCells.forEach(cell => {
            this.cancelInlineEdit(cell);
        });
    }

    // Helper methods for categories
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
}
