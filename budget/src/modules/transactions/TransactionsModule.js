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
import { showSuccess, showError, showWarning } from '../../utils/notifications.js';
import { setDateValue, clearDateValue } from '../../utils/datepicker.js';
import flatpickr from 'flatpickr';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';

export default class TransactionsModule {
    constructor(app) {
        this.app = app;

        // Transaction state - store on app for shared access
        this.app.transactionFilters = {};
        this.app.currentSort = { field: 'date', direction: 'desc' };
        this.app.currentPage = 1;
        this.app.rowsPerPage = 25;
        this.selectedTransactions = new Set();
        this.selectedFilterTags = new Set();
        this.allFilterTagSets = null;
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
            'filter-date-from', 'filter-date-to',
            'filter-created-from', 'filter-created-to',
            'filter-amount-min', 'filter-amount-max', 'filter-search'
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

        // Find Duplicates
        const findDuplicatesBtn = document.getElementById('find-duplicates-btn');
        if (findDuplicatesBtn) {
            findDuplicatesBtn.addEventListener('click', () => {
                this.showDuplicatesModal();
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

    refreshFilterTags() {
        const filtersPanel = document.getElementById('transactions-filters');
        if (filtersPanel && filtersPanel.style.display !== 'none') {
            this.loadFilterTags().then(() => this.populateFilterTagsDropdown());
        }
    }

    populateFilterDropdowns() {
        // Populate account filter
        const accountFilter = document.getElementById('filter-account');
        if (accountFilter && this.accounts) {
            accountFilter.innerHTML = `<option value="">${t('budget', 'All Accounts')}</option>`;
            this.accounts.forEach(account => {
                accountFilter.innerHTML += `<option value="${account.id}">${account.name}</option>`;
            });
            if (this.app.transactionFilters?.account) {
                accountFilter.value = this.app.transactionFilters.account;
            }
        }

        // Populate category filter
        const categoryFilter = document.getElementById('filter-category');
        if (categoryFilter && this.categories) {
            categoryFilter.innerHTML = `<option value="">${t('budget', 'All Categories')}</option><option value="uncategorized">${t('budget', 'Uncategorized')}</option>`;
            dom.populateCategorySelect(categoryFilter, this.categoryTree || this.categories);
            if (this.app.transactionFilters?.category) {
                categoryFilter.value = this.app.transactionFilters.category;
            }
        }

        // Populate reconcile account select
        const reconcileAccount = document.getElementById('reconcile-account');
        if (reconcileAccount && this.accounts) {
            reconcileAccount.innerHTML = `<option value="">${t('budget', 'Select account to reconcile')}</option>`;
            this.accounts.forEach(account => {
                reconcileAccount.innerHTML += `<option value="${account.id}">${account.name}</option>`;
            });
        }

        // Populate tag filter dropdown
        this.loadFilterTags().then(() => this.populateFilterTagsDropdown());
    }

    async loadFilterTags() {
        // Always refetch to pick up newly created tag sets and global tags
        try {
            const [tagSetsResponse, globalTagsResponse] = await Promise.all([
                fetch(OC.generateUrl('/apps/budget/api/tag-sets'), { headers: { 'requesttoken': OC.requestToken } }),
                fetch(OC.generateUrl('/apps/budget/api/tags/global'), { headers: { 'requesttoken': OC.requestToken } })
            ]);

            if (tagSetsResponse.ok) {
                this.allFilterTagSets = await tagSetsResponse.json();
            }

            // Wrap global tags as a virtual tag set for the filter dropdown
            if (globalTagsResponse.ok) {
                const globalTags = await globalTagsResponse.json();
                if (globalTags.length > 0) {
                    this.allFilterTagSets = this.allFilterTagSets || [];
                    this.allFilterTagSets.unshift({
                        id: 'global',
                        name: t('budget', 'Tags'),
                        tags: globalTags
                    });
                }
            }
        } catch (error) {
            console.error('Failed to load tags for filter:', error);
        }
    }

    populateFilterTagsDropdown() {
        const container = document.getElementById('filter-tags');
        if (!container) return;

        container.innerHTML = '';

        if (!this.allFilterTagSets || this.allFilterTagSets.length === 0) {
            container.innerHTML = `<div style="padding: 8px; color: var(--color-text-lighter); font-style: italic;">${t('budget', 'No tags available')}</div>`;
            return;
        }

        const chipsArea = document.createElement('div');
        chipsArea.className = 'filter-tags-chips';

        const input = document.createElement('input');
        input.type = 'text';
        input.id = 'filter-tags-input';
        input.className = 'tags-autocomplete-input';
        input.placeholder = t('budget', 'Type to filter tags...');

        const dropdown = document.createElement('div');
        dropdown.className = 'tags-autocomplete-dropdown';
        dropdown.style.display = 'none';

        container.appendChild(chipsArea);
        container.appendChild(input);
        container.appendChild(dropdown);

        const renderSelectedChips = () => {
            chipsArea.innerHTML = '';
            if (this.selectedFilterTags.size === 0) {
                chipsArea.style.display = 'none';
                return;
            }
            chipsArea.style.display = 'flex';
            this.selectedFilterTags.forEach(tagId => {
                const tag = allTags.find(tg => tg.id === tagId);
                if (!tag) return;
                const chip = document.createElement('span');
                chip.className = 'filter-tag-chip';
                chip.style.cssText = `display: inline-flex; align-items: center; gap: 4px; background-color: ${dom.escapeHtml(tag.color || '#888')}; color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; cursor: pointer;`;
                chip.innerHTML = `${dom.escapeHtml(tag.name)} <span style="font-weight: bold; margin-left: 2px;">×</span>`;
                chip.addEventListener('click', () => {
                    this.selectedFilterTags.delete(tagId);
                    renderSelectedChips();
                    renderDropdown(input.value);
                    this.updateFilters();
                });
                chipsArea.appendChild(chip);
            });
        };

        const allTags = [];
        this.allFilterTagSets.forEach(tagSet => {
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
                    const isSelected = this.selectedFilterTags.has(tag.id);
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

            dropdown.innerHTML = html || `<div class="tags-autocomplete-empty">${t('budget', 'No tags found')}</div>`;
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
                const clickedTag = allTags.find(tg => tg.id === tagId);
                if (!clickedTag) return;

                // Single selection per tag set
                const tagsFromSameSet = allTags.filter(tg => tg.tagSetId === clickedTag.tagSetId);
                tagsFromSameSet.forEach(tg => {
                    if (tg.id !== tagId) {
                        this.selectedFilterTags.delete(tg.id);
                    }
                });

                if (this.selectedFilterTags.has(tagId)) {
                    this.selectedFilterTags.delete(tagId);
                } else {
                    this.selectedFilterTags.add(tagId);
                }

                renderSelectedChips();
                renderDropdown(input.value);
                this.updateFilters();
            }
        });

        renderSelectedChips();

        document.addEventListener('click', (e) => {
            if (!container.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });
    }

    updateFilters() {
        this.app.transactionFilters = {
            account: document.getElementById('filter-account')?.value || '',
            category: document.getElementById('filter-category')?.value || '',
            type: document.getElementById('filter-type')?.value || '',
            status: document.getElementById('filter-status')?.value || '',
            dateFrom: document.getElementById('filter-date-from')?.value || '',
            dateTo: document.getElementById('filter-date-to')?.value || '',
            createdAtFrom: document.getElementById('filter-created-from')?.value || '',
            createdAtTo: document.getElementById('filter-created-to')?.value || '',
            amountMin: document.getElementById('filter-amount-min')?.value || '',
            amountMax: document.getElementById('filter-amount-max')?.value || '',
            search: document.getElementById('filter-search')?.value || '',
            tagIds: Array.from(this.selectedFilterTags)
        };

        // Always auto-apply filters (including when clearing them)
        this.app.currentPage = 1;
        this.app.loadTransactions();
    }

    clearFilters() {
        const filterInputs = [
            'filter-account', 'filter-category', 'filter-type', 'filter-status',
            'filter-date-from', 'filter-date-to',
            'filter-created-from', 'filter-created-to',
            'filter-amount-min', 'filter-amount-max', 'filter-search'
        ];

        filterInputs.forEach(inputId => {
            const input = document.getElementById(inputId);
            if (input) {
                if (input._flatpickr) {
                    input._flatpickr.clear();
                } else {
                    input.value = '';
                }
            }
        });

        this.selectedFilterTags.clear();
        this.populateFilterTagsDropdown();

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

        const selected = this.transactions.filter(tx => this.selectedTransactions.has(tx.id));
        const billCount = selected.filter(tx => tx.billId).length;
        let message = n('budget', 'Are you sure you want to delete %n transaction? This action cannot be undone.', 'Are you sure you want to delete %n transactions? This action cannot be undone.', this.selectedTransactions.size);

        if (billCount > 0) {
            message += '\n\n' + n('budget', '%n of these was auto-generated from bill payments. Deleting real payments will cause your balance to diverge from your bank statement.', '%n of these were auto-generated from bill payments. Deleting real payments will cause your balance to diverge from your bank statement.', billCount);
        }

        if (!confirm(message)) {
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
                showSuccess(n('budget', 'Successfully deleted %n transaction', 'Successfully deleted %n transactions', result.success));
                this.selectedTransactions.clear();
                this.app.currentPage = 1;
                this.app.loadTransactions();
            }

            if (result.failed > 0) {
                showError(n('budget', 'Failed to delete %n transaction', 'Failed to delete %n transactions', result.failed));
            }
        } catch (error) {
            console.error('Bulk deletion failed:', error);
            showError(t('budget', 'Failed to delete transactions'));
        }
    }

    async bulkReconcileTransactions() {
        if (this.selectedTransactions.size === 0) {
            return;
        }

        if (!confirm(n('budget', 'Are you sure you want to mark %n transaction as reconciled?', 'Are you sure you want to mark %n transactions as reconciled?', this.selectedTransactions.size))) {
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
                showSuccess(n('budget', 'Successfully reconciled %n transaction', 'Successfully reconciled %n transactions', result.success));
                this.selectedTransactions.clear();
                this.app.currentPage = 1;
                this.app.loadTransactions();
            }

            if (result.failed > 0) {
                showError(n('budget', 'Failed to reconcile %n transaction', 'Failed to reconcile %n transactions', result.failed));
            }
        } catch (error) {
            console.error('Bulk reconcile failed:', error);
            showError(t('budget', 'Failed to reconcile transactions'));
        }
    }

    async bulkUnreconcileTransactions() {
        if (this.selectedTransactions.size === 0) {
            return;
        }

        if (!confirm(n('budget', 'Are you sure you want to mark %n transaction as unreconciled?', 'Are you sure you want to mark %n transactions as unreconciled?', this.selectedTransactions.size))) {
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
                showSuccess(n('budget', 'Successfully unreconciled %n transaction', 'Successfully unreconciled %n transactions', result.success));
                this.selectedTransactions.clear();
                this.app.currentPage = 1;
                this.app.loadTransactions();
            }

            if (result.failed > 0) {
                showError(n('budget', 'Failed to unreconcile %n transaction', 'Failed to unreconcile %n transactions', result.failed));
            }
        } catch (error) {
            console.error('Bulk unreconcile failed:', error);
            showError(t('budget', 'Failed to unreconcile transactions'));
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
            categorySelect.innerHTML = `<option value="">${t('budget', 'Don\'t change')}</option>`;
            dom.populateCategorySelect(categorySelect, this.categoryTree || this.categories);
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
            showWarning(t('budget', 'Please fill in at least one field to update'));
            return;
        }

        if (!confirm(n('budget', 'Are you sure you want to update %n transaction?', 'Are you sure you want to update %n transactions?', this.selectedTransactions.size))) {
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
                showSuccess(n('budget', 'Successfully updated %n transaction', 'Successfully updated %n transactions', result.success));
                this.selectedTransactions.clear();
                this.app.currentPage = 1;
                this.app.loadTransactions();

                // Close modal
                document.getElementById('bulk-edit-modal').style.display = 'none';
            }

            if (result.failed > 0) {
                showError(n('budget', 'Failed to update %n transaction', 'Failed to update %n transactions', result.failed));
            }
        } catch (error) {
            console.error('Bulk edit failed:', error);
            showError(t('budget', 'Failed to update transactions'));
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
            showWarning(t('budget', 'Please fill in all reconciliation fields'));
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

            showSuccess(t('budget', 'Reconciliation mode started'));
        } catch (error) {
            console.error('Reconciliation failed:', error);
            showError(t('budget', 'Failed to start reconciliation: {message}', { message: error.message }));
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
        const differenceAmount = reconcileData.difference || 0;
        const absDifference = Math.abs(differenceAmount);
        const adjustmentType = differenceAmount > 0 ? 'credit' : 'debit';
        const adjustmentLabel = differenceAmount > 0 ? t('budget', 'deposit') : t('budget', 'withdrawal');

        infoPanel.innerHTML = `
            <div class="reconcile-info-content">
                <h4>${t('budget', 'Account Reconciliation')}</h4>
                <div class="reconcile-stats">
                    <div class="stat">
                        <label>${t('budget', 'Current Balance:')}</label>
                        <span class="amount">${this.formatCurrency(reconcileData.currentBalance || 0)}</span>
                    </div>
                    <div class="stat">
                        <label>${t('budget', 'Statement Balance:')}</label>
                        <span class="amount">${this.formatCurrency(reconcileData.statementBalance || 0)}</span>
                    </div>
                    <div class="stat ${reconcileData.isBalanced ? 'balanced' : 'unbalanced'}">
                        <label>${t('budget', 'Difference:')}</label>
                        <span class="amount">${this.formatCurrency(differenceAmount)}</span>
                    </div>
                </div>
                ${reconcileData.isBalanced
                    ? `<p class="reconcile-message balanced">${t('budget', 'Balances match. You can finish reconciliation.')}</p>`
                    : `<p class="reconcile-message unbalanced">${t('budget', 'The difference of {amount} must be resolved before finishing. You can create an adjustment transaction to match the statement balance.', { amount: this.formatCurrency(absDifference) })}</p>`
                }
                <div class="reconcile-actions">
                    <button id="finish-reconcile-btn" class="primary" ${!reconcileData.isBalanced ? 'disabled' : ''}>
                        ${t('budget', 'Finish Reconciliation')}
                    </button>
                    ${!reconcileData.isBalanced ? `
                        <button id="adjust-reconcile-btn" class="primary">
                            ${t('budget', 'Create {amount} Adjustment ({type})', { amount: this.formatCurrency(absDifference), type: adjustmentLabel })}
                        </button>
                    ` : ''}
                    <button id="cancel-reconcile-info-btn" class="secondary">${t('budget', 'Cancel')}</button>
                </div>
            </div>
        `;

        document.body.appendChild(infoPanel);

        // Add event listeners
        document.getElementById('finish-reconcile-btn').addEventListener('click', () => {
            this.finishReconciliation();
        });

        const adjustBtn = document.getElementById('adjust-reconcile-btn');
        if (adjustBtn) {
            adjustBtn.addEventListener('click', () => {
                this.createReconciliationAdjustment(reconcileData, adjustmentType, absDifference);
            });
        }

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

    finishReconciliation() {
        this.cancelReconciliation();
        this.app.loadAccounts();
        showSuccess(t('budget', 'Reconciliation completed successfully'));
    }

    async createReconciliationAdjustment(reconcileData, type, amount) {
        const accountId = document.getElementById('filter-account')?.value
            || document.getElementById('reconcile-account')?.value;

        if (!accountId) {
            showError(t('budget', 'No account selected for adjustment'));
            return;
        }

        const today = new Date().toISOString().split('T')[0];

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/transactions'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    date: today,
                    accountId: parseInt(accountId),
                    type: type,
                    amount: amount,
                    description: t('budget', 'Reconciliation Adjustment'),
                    notes: t('budget', 'Adjustment to match statement balance of {amount}', { amount: this.formatCurrency(reconcileData.statementBalance) })
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to create adjustment');
            }

            showSuccess(t('budget', 'Adjustment transaction created'));

            // Reload transactions and update reconcile data
            await this.app.loadTransactions();
            await this.app.loadAccounts();

            // Re-run reconciliation with updated balances
            this.cancelReconciliation();
            document.getElementById('reconcile-account').value = accountId;
            document.getElementById('reconcile-statement-balance').value = reconcileData.statementBalance;
            document.getElementById('reconcile-statement-date').value = document.getElementById('reconcile-statement-date')?.value || today;
            document.getElementById('reconcile-panel').style.display = 'block';
            await this.startReconciliation();
        } catch (error) {
            console.error('Failed to create adjustment:', error);
            showError(t('budget', 'Failed to create adjustment: {message}', { message: error.message }));
        }
    }

    async toggleTransactionReconciliation(transactionId, checked) {
        // Implementation would update transaction reconciliation status
        // This is a placeholder for the actual API call
        console.log('Toggle reconciliation for transaction:', transactionId, checked);
    }

    // Rendering
    renderTransactionsList(transactions) {
        return transactions.map(tx => `
            <div class="transaction-item">
                <span class="transaction-date">${this.formatDate(tx.date)}</span>
                <span class="transaction-description">${tx.description}</span>
                <span class="amount ${tx.type}">${this.formatCurrency(tx.amount, tx.accountCurrency)}</span>
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
            accountSelect.innerHTML = `<option value="">${t('budget', 'Choose an account')}</option>`;
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
            toAccountSelect.innerHTML = `<option value="">${t('budget', 'Select account...')}</option>`;
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
            categorySelect.innerHTML = `<option value="">${t('budget', 'No category')}</option>`;
            dom.populateCategorySelect(categorySelect, this.categoryTree || this.categories);

            // Restore previous value if it exists
            if (currentValue) {
                categorySelect.value = currentValue;
            }
        }
    }

    showTransactionModal(transaction = null, preSelectedAccountId = null) {
        const modal = document.getElementById('transaction-modal');
        if (modal) {
            // Populate account and category dropdowns first
            this.populateTransactionModalDropdowns();

            // Hide Transfer option when editing (transfers are created, not converted to/from)
            const typeEl = document.getElementById('transaction-type');
            if (typeEl) {
                const existingTransferOpt = typeEl.querySelector('option[value="transfer"]');
                if (transaction && existingTransferOpt) {
                    existingTransferOpt.remove();
                } else if (!transaction && !existingTransferOpt) {
                    typeEl.appendChild(new Option(t('budget', 'Transfer'), 'transfer'));
                }
            }

            if (transaction) {
                // Populate form with transaction data (editing mode)
                document.getElementById('transaction-id').value = transaction.id;
                setDateValue('transaction-date', transaction.date);
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
                setDateValue('transaction-date', formatters.getTodayDateString());

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
        let transaction = this.transactions.find(tx => tx.id === id);

        // If not found, check AccountsModule's accountTransactions (for account detail view)
        if (!transaction && this.app.accountsModule?.accountTransactions) {
            transaction = this.app.accountsModule.accountTransactions.find(tx => tx.id === id);
        }

        // If still not found, fetch from API
        if (!transaction) {
            try {
                const response = await this.app.api.get(`/apps/budget/api/transactions/${id}`);
                transaction = response.data;
            } catch (error) {
                console.error('Failed to fetch transaction for editing:', error);
                this.app.showNotification(t('budget', 'Failed to load transaction'), 'error');
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
                showWarning(t('budget', 'Please select destination account'));
                return;
            }
            if (toAccountId === accountId) {
                showWarning(t('budget', 'Cannot transfer to same account'));
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
                showSuccess(t('budget', 'Transfer created successfully'));
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
                showError(error.message || t('budget', 'Failed to create transfer'));
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

        // Collect selected tags before the modal is closed
        const selectedTagIds = Array.from(
            document.querySelectorAll('#transaction-tags-container input[type="checkbox"]:checked')
        ).map(cb => parseInt(cb.value));

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
                // Save tags for newly created transactions
                if (!id && selectedTagIds.length > 0) {
                    const created = await response.json();
                    if (created.id) {
                        await this.app.tagSetsModule.saveTransactionTags(created.id, selectedTagIds);
                    }
                }

                showSuccess(id ? t('budget', 'Transaction updated') : t('budget', 'Transaction created'));
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
                throw new Error(error.error || t('budget', 'Failed to save transaction'));
            }
        } catch (error) {
            console.error('Failed to save transaction:', error);
            showError(error.message || t('budget', 'Failed to save transaction'));
        }
    }

    async deleteTransaction(id) {
        const transaction = this.transactions.find(tx => tx.id === id);
        let message = t('budget', 'Are you sure you want to delete this transaction?');

        if (transaction) {
            const amount = formatters.formatCurrency(
                transaction.amount,
                this.accounts.find(a => a.id === transaction.accountId)?.currency,
                this.settings
            );
            const balanceEffect = transaction.type === 'credit'
                ? t('budget', 'This will decrease the account balance by {amount}.', { amount })
                : t('budget', 'This will increase the account balance by {amount}.', { amount });

            if (transaction.billId) {
                message = t('budget', 'This transaction was auto-generated from a bill payment.') + `\n\n${balanceEffect}\n\n` + t('budget', 'If this is a real payment, deleting it will cause your balance to diverge from your bank statement. Are you sure?');
            } else {
                message = t('budget', 'Are you sure you want to delete this transaction?') + `\n\n${balanceEffect}`;
            }
        }

        if (!confirm(message)) {
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
                showSuccess(t('budget', 'Transaction deleted'));
                await this.app.loadTransactions();
                await this.app.loadAccounts(); // Refresh account balances

                // Refresh account details view if currently viewing an account
                await this.app.refreshCurrentAccountView();

                // Refresh dashboard if currently viewing it
                if (window.location.hash === '' || window.location.hash === '#/dashboard') {
                    await this.app.loadDashboard();
                }
            } else {
                const error = await response.json().catch(() => ({}));
                showError(error.error || t('budget', 'Failed to delete transaction'));
            }
        } catch (error) {
            console.error('Failed to delete transaction:', error);
            showError(t('budget', 'Failed to delete transaction'));
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
        const transaction = this.transactions?.find(tx => tx.id === transactionId);
        if (!transaction) {
            showWarning(t('budget', 'Transaction not found'));
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
        sourceDetails.querySelector('.source-account').textContent = account?.name || t('budget', 'Unknown Account');

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
                        <span class="match-account">${matchAccount?.name || t('budget', 'Unknown')}</span>
                        <button class="link-match-btn" data-source-id="${transactionId}" data-target-id="${match.id}">
                            ${t('budget', 'Link as Transfer')}
                        </button>
                    </div>
                `;
            }).join('');

        } catch (error) {
            loadingEl.style.display = 'none';
            emptyEl.style.display = 'flex';
            emptyEl.querySelector('p').textContent = t('budget', 'Failed to search for matches. Please try again.');
        }
    }

    async handleLinkMatch(sourceId, targetId) {
        try {
            await this.linkTransactions(sourceId, targetId);
            showSuccess(t('budget', 'Transactions linked as transfer'));

            // Close modal and refresh transactions
            document.getElementById('matching-modal').style.display = 'none';
            await this.app.loadTransactions();
        } catch (error) {
            showError(error.message || t('budget', 'Failed to link transactions'));
        }
    }

    async handleUnlinkTransaction(transactionId) {
        if (!confirm(t('budget', 'Are you sure you want to unlink this transaction from its transfer pair?'))) {
            return;
        }

        try {
            await this.unlinkTransaction(transactionId);
            showSuccess(t('budget', 'Transaction unlinked'));
            await this.app.loadTransactions();
        } catch (error) {
            showError(error.message || t('budget', 'Failed to unlink transaction'));
        }
    }

    // Transaction splits
    async showSplitModal(transactionId) {
        const transaction = this.transactions?.find(tx => tx.id === transactionId);
        if (!transaction) {
            showWarning(t('budget', 'Transaction not found'));
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
        titleEl.textContent = isSplit ? t('budget', 'Edit Transaction Splits') : t('budget', 'Split Transaction');
        modal.dataset.transactionId = transactionId;

        // Display transaction info
        const account = this.accounts?.find(a => a.id === transaction.accountId);
        const currency = transaction.accountCurrency || account?.currency || this.getPrimaryCurrency();
        transactionInfoEl.innerHTML = `
            <div class="split-info-row">
                <span class="split-info-label">${t('budget', 'Date:')}</span>
                <span>${this.formatDate(transaction.date)}</span>
            </div>
            <div class="split-info-row">
                <span class="split-info-label">${t('budget', 'Description:')}</span>
                <span>${this.escapeHtml(transaction.description)}</span>
            </div>
            <div class="split-info-row">
                <span class="split-info-label">${t('budget', 'Total Amount:')}</span>
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

        // Show/hide unsplit button and wire up handler
        const unsplitBtn = document.getElementById('split-unsplit-btn');
        if (unsplitBtn) {
            unsplitBtn.style.display = isSplit ? '' : 'none';
            unsplitBtn.onclick = () => this.unsplitTransaction();
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
        const transaction = this.transactions?.find(tx => tx.id === transactionId);
        const transactionType = transaction?.type || 'debit';

        const row = document.createElement('div');
        row.className = 'split-row';
        row.dataset.index = rowIndex;

        row.innerHTML = `
            <div class="split-field split-amount-field">
                <label>${t('budget', 'Amount')}</label>
                <input type="number" class="split-amount" step="0.01" min="0.01"
                       value="${split ? split.amount : ''}" placeholder="0.00" required>
            </div>
            <div class="split-field split-category-field">
                <label>${t('budget', 'Category')}</label>
                <select class="split-category">
                    <option value="">${t('budget', 'Uncategorized')}</option>
                    ${this.getCategoryOptions(split?.categoryId, transactionType)}
                </select>
            </div>
            <div class="split-field split-description-field">
                <label>${t('budget', 'Description')}</label>
                <input type="text" class="split-description" maxlength="255"
                       value="${split?.description || ''}" placeholder="${t('budget', 'Optional note')}">
            </div>
            <div class="split-actions">
                <button type="button" class="split-remove-btn ${isFirst ? 'disabled' : ''}"
                        ${isFirst ? 'disabled' : ''} title="${t('budget', 'Remove split')}">
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
            showWarning(t('budget', 'A split transaction must have at least 2 parts'));
            return;
        }

        const splitTotal = splits.reduce((sum, s) => sum + s.amount, 0);
        if (Math.abs(splitTotal - totalAmount) > 0.01) {
            showWarning(t('budget', 'Split amounts ({splitTotal}) must equal transaction amount ({totalAmount})', { splitTotal: splitTotal.toFixed(2), totalAmount: totalAmount.toFixed(2) }));
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
            showSuccess(t('budget', 'Transaction split successfully'));
            await this.app.loadTransactions();
        } catch (error) {
            console.error('Failed to save splits:', error);
            showError(error.message || t('budget', 'Failed to save splits'));
        }
    }

    async unsplitTransaction() {
        const modal = document.getElementById('split-modal');
        const transactionId = parseInt(modal?.dataset.transactionId);

        if (!confirm(t('budget', 'Are you sure you want to remove the split and revert to a single transaction?'))) {
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
            showSuccess(t('budget', 'Transaction unsplit successfully'));
            await this.app.loadTransactions();
        } catch (error) {
            console.error('Failed to unsplit transaction:', error);
            showError(error.message || t('budget', 'Failed to unsplit transaction'));
        }
    }

    hideSplitModal() {
        const modal = document.getElementById('split-modal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    // ===== Bulk Transfer Matching =====

    async scanForMatches(dateWindow = 3) {
        const response = await fetch(
            OC.generateUrl(`/apps/budget/api/transactions/scan-matches?dateWindow=${dateWindow}`),
            {
                method: 'GET',
                headers: { 'requesttoken': OC.requestToken }
            }
        );
        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.error || `HTTP ${response.status}`);
        }
        return await response.json();
    }

    async bulkLinkPairs(pairs) {
        const response = await fetch(
            OC.generateUrl('/apps/budget/api/transactions/bulk-link'),
            {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ pairs })
            }
        );
        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.error || `HTTP ${response.status}`);
        }
        return await response.json();
    }

    showBulkMatchModal() {
        const modal = document.getElementById('bulk-match-modal');
        const configEl = document.getElementById('bulk-match-config');
        const loadingEl = document.getElementById('bulk-match-loading');
        const resultsEl = document.getElementById('bulk-match-results');
        const emptyEl = document.getElementById('bulk-match-empty');
        const confirmBtn = document.getElementById('confirm-selected-btn');

        // Reset state - show config, hide everything else
        configEl.style.display = 'block';
        loadingEl.style.display = 'none';
        resultsEl.style.display = 'none';
        emptyEl.style.display = 'none';
        if (confirmBtn) confirmBtn.style.display = 'none';
        document.getElementById('auto-matched-section').style.display = 'none';
        document.getElementById('needs-review-section').style.display = 'none';
        document.getElementById('auto-matched-list').innerHTML = '';
        document.getElementById('needs-review-list').innerHTML = '';

        // Render config dialog
        configEl.innerHTML = `
            <div class="config-field">
                <label for="match-date-window">${t('budget', 'Date window (days):')}</label>
                <div class="date-window-control">
                    <input type="range" id="match-date-window" min="0" max="14" value="3" step="1">
                    <span id="match-date-window-value" class="date-window-value">3</span>
                </div>
                <p class="hint">${t('budget', 'Matches must be within this many days of each other. Use 0 for exact date match.')}</p>
            </div>
            <div class="config-field">
                <label>${t('budget', 'Matching mode:')}</label>
                <div class="mode-options">
                    <label class="mode-option">
                        <input type="radio" name="match-mode" value="auto" checked>
                        <div class="mode-option-content">
                            <span class="mode-option-title">${t('budget', 'Auto-link exact matches')}</span>
                            <span class="mode-option-desc">${t('budget', 'Single-match pairs linked automatically. Multi-match shown for review.')}</span>
                        </div>
                    </label>
                    <label class="mode-option">
                        <input type="radio" name="match-mode" value="review">
                        <div class="mode-option-content">
                            <span class="mode-option-title">${t('budget', 'Review all matches')}</span>
                            <span class="mode-option-desc">${t('budget', 'All candidates shown for your confirmation before linking.')}</span>
                        </div>
                    </label>
                </div>
            </div>
        `;

        // Wire up date window slider
        const slider = document.getElementById('match-date-window');
        const valueDisplay = document.getElementById('match-date-window-value');
        slider.addEventListener('input', () => {
            valueDisplay.textContent = slider.value;
        });

        // Track dirty state for refresh on close
        this._bulkMatchDirty = false;

        modal.style.display = 'flex';
    }

    async startScan() {
        const configEl = document.getElementById('bulk-match-config');
        const loadingEl = document.getElementById('bulk-match-loading');

        const dateWindow = parseInt(document.getElementById('match-date-window').value) || 3;
        const mode = document.querySelector('input[name="match-mode"]:checked')?.value || 'auto';

        // Hide config and scan button, show loading
        configEl.style.display = 'none';
        const scanBtn = document.getElementById('start-scan-btn');
        if (scanBtn) scanBtn.style.display = 'none';
        loadingEl.style.display = 'flex';
        loadingEl.querySelector('p').textContent = t('budget', 'Scanning for matching transactions...');

        try {
            const result = await this.scanForMatches(dateWindow);
            loadingEl.style.display = 'none';

            if (result.stats.totalCandidates === 0) {
                const resultsEl = document.getElementById('bulk-match-results');
                const emptyEl = document.getElementById('bulk-match-empty');
                resultsEl.style.display = 'block';
                emptyEl.style.display = 'flex';
                emptyEl.querySelector('p').textContent = t('budget', 'No matching transactions found.');
                return;
            }

            if (mode === 'auto') {
                await this.handleAutoMode(result);
            } else {
                this.handleReviewMode(result);
            }
        } catch (error) {
            loadingEl.style.display = 'none';
            const resultsEl = document.getElementById('bulk-match-results');
            const emptyEl = document.getElementById('bulk-match-empty');
            resultsEl.style.display = 'block';
            emptyEl.style.display = 'flex';
            emptyEl.querySelector('p').textContent = error.message || t('budget', 'Failed to scan for matches. Please try again.');
        }
    }

    async handleAutoMode(scanResult) {
        const loadingEl = document.getElementById('bulk-match-loading');
        const resultsEl = document.getElementById('bulk-match-results');
        const autoMatchedSection = document.getElementById('auto-matched-section');
        const needsReviewSection = document.getElementById('needs-review-section');
        const autoMatchedList = document.getElementById('auto-matched-list');
        const needsReviewList = document.getElementById('needs-review-list');

        const singleMatches = scanResult.candidates.filter(c => c.matchCount === 1);
        const multiMatches = scanResult.candidates.filter(c => c.matchCount > 1);

        let autoLinkedCount = 0;

        // Auto-link single-match pairs
        if (singleMatches.length > 0) {
            loadingEl.style.display = 'flex';
            loadingEl.querySelector('p').textContent = n('budget', 'Linking %n exact match pair...', 'Linking %n exact match pairs...', singleMatches.length);

            const pairs = singleMatches.map(c => ({
                sourceId: parseInt(c.transaction.id),
                targetId: parseInt(c.matches[0].id)
            }));

            try {
                const linkResult = await this.bulkLinkPairs(pairs);
                autoLinkedCount = linkResult.stats.linkedCount;
                this._bulkMatchDirty = true;
            } catch (error) {
                // If bulk link fails entirely, show all as review instead
                loadingEl.style.display = 'none';
                this.handleReviewMode(scanResult);
                return;
            }

            loadingEl.style.display = 'none';
        }

        // Show results and cancel button
        resultsEl.style.display = 'block';
        document.getElementById('auto-matched-count').textContent = autoLinkedCount;
        document.getElementById('needs-review-count').textContent = multiMatches.length;

        // Render auto-linked pairs (with undo buttons)
        if (autoLinkedCount > 0) {
            autoMatchedSection.style.display = 'block';
            autoMatchedList.innerHTML = singleMatches.map(c => this.renderAutoMatchedPair({
                transaction: c.transaction,
                linkedTo: c.matches[0]
            })).join('');
        }

        // Render multi-match items for review
        if (multiMatches.length > 0) {
            needsReviewSection.style.display = 'block';
            needsReviewList.innerHTML = multiMatches.map((item, index) =>
                this.renderNeedsReviewItem(item, index)
            ).join('');
        }

        if (autoLinkedCount === 0 && multiMatches.length === 0) {
            document.getElementById('bulk-match-empty').style.display = 'flex';
        }
    }

    handleReviewMode(scanResult) {
        const resultsEl = document.getElementById('bulk-match-results');
        const autoMatchedSection = document.getElementById('auto-matched-section');
        const needsReviewSection = document.getElementById('needs-review-section');
        const autoMatchedList = document.getElementById('auto-matched-list');
        const needsReviewList = document.getElementById('needs-review-list');
        const confirmBtn = document.getElementById('confirm-selected-btn');

        resultsEl.style.display = 'block';

        const singleMatches = scanResult.candidates.filter(c => c.matchCount === 1);
        const multiMatches = scanResult.candidates.filter(c => c.matchCount > 1);

        // Update summary for review mode
        document.getElementById('auto-matched-count').textContent = singleMatches.length;
        document.getElementById('needs-review-count').textContent = multiMatches.length;

        // Render single-match items with checkboxes (pre-checked)
        if (singleMatches.length > 0) {
            autoMatchedSection.style.display = 'block';
            autoMatchedSection.querySelector('h4').textContent = t('budget', 'Exact Matches');
            autoMatchedSection.querySelector('.section-hint').textContent = t('budget', 'These transactions have exactly one match. Uncheck any you don\'t want to link.');
            autoMatchedList.innerHTML = singleMatches.map((c, index) =>
                this.renderReviewSingleMatch(c, index)
            ).join('');
        }

        // Render multi-match items with radio buttons
        if (multiMatches.length > 0) {
            needsReviewSection.style.display = 'block';
            needsReviewList.innerHTML = multiMatches.map((item, index) =>
                this.renderNeedsReviewItem(item, index)
            ).join('');
        }

        // Show confirm button
        if (confirmBtn) {
            confirmBtn.style.display = 'inline-block';
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
                <span class="pair-arrow">\u2194</span>
                <div class="pair-transaction">
                    <span class="pair-date">${this.formatDate(linked.date)}</span>
                    <span class="pair-description">${this.escapeHtml(linked.description)}</span>
                    <div class="pair-details">
                        <span class="pair-amount ${linkedTypeClass}">${this.formatCurrency(linked.amount, linkedCurrency)}</span>
                        <span class="pair-account">${this.escapeHtml(linked.accountName)}</span>
                    </div>
                </div>
                <button class="undo-match-btn" data-tx-id="${tx.id}">${t('budget', 'Undo')}</button>
            </div>
        `;
    }

    renderReviewSingleMatch(candidate, index) {
        const tx = candidate.transaction;
        const match = candidate.matches[0];

        const txCurrency = tx.account_currency || this.getPrimaryCurrency();
        const matchCurrency = match.accountCurrency || this.getPrimaryCurrency();

        const txTypeClass = tx.type === 'credit' ? 'positive' : 'negative';
        const matchTypeClass = match.type === 'credit' ? 'positive' : 'negative';

        return `
            <div class="review-single-match" data-source-id="${tx.id}" data-target-id="${match.id}">
                <input type="checkbox" class="review-single-check" data-source-id="${tx.id}" data-target-id="${match.id}" checked>
                <div class="pair-content">
                    <div class="pair-transaction">
                        <span class="pair-date">${this.formatDate(tx.date)}</span>
                        <span class="pair-description">${this.escapeHtml(tx.description)}</span>
                        <div class="pair-details">
                            <span class="pair-amount ${txTypeClass}">${this.formatCurrency(tx.amount, txCurrency)}</span>
                            <span class="pair-account">${this.escapeHtml(tx.account_name)}</span>
                        </div>
                    </div>
                    <span class="pair-arrow">\u2194</span>
                    <div class="pair-transaction">
                        <span class="pair-date">${this.formatDate(match.date)}</span>
                        <span class="pair-description">${this.escapeHtml(match.description)}</span>
                        <div class="pair-details">
                            <span class="pair-amount ${matchTypeClass}">${this.formatCurrency(match.amount, matchCurrency)}</span>
                            <span class="pair-account">${this.escapeHtml(match.accountName)}</span>
                        </div>
                    </div>
                </div>
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
                <div class="review-matches-label">${n('budget', 'Select a match (%n option):', 'Select a match (%n options):', item.matchCount)}</div>
                <div class="review-matches">
                    ${matchesHtml}
                </div>
                <button class="link-selected-btn" data-tx-id="${tx.id}" data-index="${index}" disabled>${t('budget', 'Link Selected')}</button>
            </div>
        `;
    }

    async handleConfirmSelected() {
        const pairs = [];

        // Collect checked single-match pairs
        document.querySelectorAll('.review-single-check:checked').forEach(cb => {
            pairs.push({
                sourceId: parseInt(cb.dataset.sourceId),
                targetId: parseInt(cb.dataset.targetId)
            });
        });

        // Collect selected multi-match pairs (radio buttons)
        document.querySelectorAll('.bulk-review-item').forEach(item => {
            const selected = item.querySelector('input[type="radio"]:checked');
            if (selected) {
                pairs.push({
                    sourceId: parseInt(item.dataset.txId),
                    targetId: parseInt(selected.value)
                });
            }
        });

        if (pairs.length === 0) {
            showWarning(t('budget', 'No matches selected'));
            return;
        }

        const confirmBtn = document.getElementById('confirm-selected-btn');
        if (confirmBtn) {
            confirmBtn.disabled = true;
            confirmBtn.textContent = t('budget', 'Linking...');
        }

        try {
            const result = await this.bulkLinkPairs(pairs);
            this._bulkMatchDirty = true;

            let message = n('budget', 'Linked %n pair', 'Linked %n pairs', result.stats.linkedCount);
            if (result.stats.failedCount > 0) {
                message += ', ' + n('budget', '%n failed', '%n failed', result.stats.failedCount);
            }
            showSuccess(message);

            // Update UI to show results
            const resultsEl = document.getElementById('bulk-match-results');
            const autoMatchedSection = document.getElementById('auto-matched-section');
            const autoMatchedList = document.getElementById('auto-matched-list');
            const needsReviewSection = document.getElementById('needs-review-section');

            // Convert review to auto-matched view with undo buttons
            if (result.stats.linkedCount > 0) {
                autoMatchedSection.style.display = 'block';
                autoMatchedSection.querySelector('h4').textContent = t('budget', 'Linked Pairs');
                autoMatchedSection.querySelector('.section-hint').textContent = t('budget', 'These transactions have been linked. Click Undo to unlink a pair.');

                // Build auto-matched HTML from the successfully linked pairs
                const linkedHtml = [];
                document.querySelectorAll('.review-single-match').forEach(el => {
                    const sourceId = el.dataset.sourceId;
                    const targetId = el.dataset.targetId;
                    const wasLinked = result.linked.some(l =>
                        l.sourceId == sourceId && l.targetId == targetId
                    );
                    if (wasLinked) {
                        // Convert to auto-matched pair display with undo
                        const pairContent = el.querySelector('.pair-content');
                        if (pairContent) {
                            linkedHtml.push(`
                                <div class="bulk-match-pair" data-tx-id="${sourceId}" data-linked-id="${targetId}">
                                    ${pairContent.innerHTML}
                                    <button class="undo-match-btn" data-tx-id="${sourceId}">${t('budget', 'Undo')}</button>
                                </div>
                            `);
                        }
                    }
                });
                autoMatchedList.innerHTML = linkedHtml.join('');
                document.getElementById('auto-matched-count').textContent = result.stats.linkedCount;
            }

            // Hide review section and confirm button
            needsReviewSection.style.display = 'none';
            if (confirmBtn) confirmBtn.style.display = 'none';
            document.getElementById('needs-review-count').textContent = '0';

        } catch (error) {
            showError(error.message || t('budget', 'Failed to link transactions'));
        } finally {
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.textContent = t('budget', 'Confirm Selected');
            }
        }
    }

    async handleBulkMatchUndo(transactionId) {
        try {
            await this.unlinkTransaction(transactionId);
            this._bulkMatchDirty = true;

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

            showSuccess(t('budget', 'Match undone'));
        } catch (error) {
            showError(error.message || t('budget', 'Failed to undo match'));
        }
    }

    async handleBulkMatchLink(transactionId, index) {
        const reviewItem = document.querySelector(`.bulk-review-item[data-index="${index}"]`);
        const selectedRadio = reviewItem.querySelector(`input[name="review-match-${index}"]:checked`);

        if (!selectedRadio) {
            showWarning(t('budget', 'Please select a match first'));
            return;
        }

        const targetId = parseInt(selectedRadio.value);

        try {
            await this.linkTransactions(transactionId, targetId);
            this._bulkMatchDirty = true;

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

            showSuccess(t('budget', 'Transactions linked'));
        } catch (error) {
            showError(error.message || t('budget', 'Failed to link transactions'));
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
        const transaction = this.transactions.find(tx => tx.id === transactionId);

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
        input.type = 'text';
        input.className = 'inline-edit-input';

        cell.innerHTML = '';
        cell.appendChild(input);

        const dateFormat = this.app.settings?.date_format || 'Y-m-d';
        const fp = flatpickr(input, {
            dateFormat: dateFormat,
            defaultDate: value,
            allowInput: true,
            disableMobile: true,
        });

        const getIsoDate = () => {
            if (fp.selectedDates.length > 0) {
                return fp.formatDate(fp.selectedDates[0], 'Y-m-d');
            }
            return value;
        };

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                fp.destroy();
                this.cancelInlineEdit(cell);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                const isoDate = getIsoDate();
                fp.destroy();
                this.saveInlineEdit(cell, 'date', isoDate);
            }
        });

        input.addEventListener('blur', () => {
            setTimeout(() => {
                if (cell.classList.contains('editing') && !fp.isOpen) {
                    const isoDate = getIsoDate();
                    fp.destroy();
                    this.saveInlineEdit(cell, 'date', isoDate);
                }
            }, 200);
        });

        input.focus();
    }

    createTextEditor(cell, value, field) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'inline-edit-input';
        input.value = value || '';
        input.placeholder = field === 'description' ? t('budget', 'Enter description...') : '';

        this.setupEditorEvents(input, cell, field);
        cell.innerHTML = '';
        cell.appendChild(input);
        input.focus();
        input.select();
    }

    createAmountEditor(cell, transaction) {
        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'inline-edit-input';
        input.value = transaction.type === 'debit' ? -Math.abs(transaction.amount) : Math.abs(transaction.amount);
        input.step = '0.01';
        input.dataset.type = transaction.type;

        this.setupEditorEvents(input, cell, 'amount');

        cell.innerHTML = '';
        cell.appendChild(input);
        input.focus();
        input.select();
    }

    createCategoryEditor(cell, currentCategoryId) {
        const container = document.createElement('div');
        container.className = 'category-autocomplete';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'category-autocomplete-input';
        input.placeholder = t('budget', 'Type to search...');

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
                dropdown.innerHTML = `<div class="category-autocomplete-empty">${t('budget', 'No categories found')}</div>`;
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
                    ${t('budget', 'Uncategorized')}
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

        cell.innerHTML = `<span style="color: var(--color-text-maxcontrast); font-size: 11px;">${t('budget', 'Loading...')}</span>`;

        try {
            // Load both global tags and category tag sets
            const [globalTagsResponse, tagSets] = await Promise.all([
                fetch(OC.generateUrl('/apps/budget/api/tags/global'), { headers: { 'requesttoken': OC.requestToken } }).then(r => r.ok ? r.json() : []).catch(() => []),
                categoryId ? this.loadTagSetsForCategory(categoryId) : Promise.resolve([])
            ]);

            const globalTags = globalTagsResponse || [];

            if (globalTags.length === 0 && tagSets.length === 0) {
                cell.innerHTML = `<span style="color: var(--color-text-maxcontrast); font-size: 11px; font-style: italic;">${t('budget', 'No tags')}</span>`;
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
            input.placeholder = t('budget', 'Type to filter tags...');

            const dropdown = document.createElement('div');
            dropdown.className = 'tags-autocomplete-dropdown';
            dropdown.style.display = 'none';

            container.appendChild(input);
            container.appendChild(dropdown);

            const allTags = [];

            // Add global tags (grouped under "Tags" label)
            if (globalTags.length > 0) {
                globalTags.forEach(tag => {
                    allTags.push({
                        id: tag.id,
                        name: tag.name,
                        color: tag.color,
                        tagSetName: t('budget', 'Tags'),
                        tagSetId: 'global'
                    });
                });
            }

            // Add category tag sets
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
                    ? allTags.filter(tg =>
                        tg.name.toLowerCase().includes(filter.toLowerCase()) ||
                        tg.tagSetName.toLowerCase().includes(filter.toLowerCase())
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

                dropdown.innerHTML = html || `<div class="tags-autocomplete-empty">${t('budget', 'No tags found')}</div>`;
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
                    const clickedTag = allTags.find(tg => tg.id === tagId);
                    if (!clickedTag) return;

                    // Single-selection per category tag set (not for global tags)
                    if (clickedTag.tagSetId !== 'global') {
                        const tagsFromSameSet = allTags.filter(tg => tg.tagSetId === clickedTag.tagSetId);
                        tagsFromSameSet.forEach(tg => {
                            if (tg.id !== tagId) {
                                selectedTags.delete(tg.id);
                            }
                        });
                    }

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
            cell.innerHTML = `<span style="color: var(--color-error); font-size: 11px;">${t('budget', 'Error loading tags')}</span>`;
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
                    const raw = parseFloat(input.value) || 0;
                    const type = raw < 0 ? 'debit' : 'credit';
                    this.saveInlineEdit(cell, field, Math.abs(raw).toString(), { type });
                } else {
                    this.saveInlineEdit(cell, field, input.value);
                }
            }
        });

        input.addEventListener('blur', (e) => {
            setTimeout(() => {
                if (cell.classList.contains('editing')) {
                    if (field === 'amount') {
                        const raw = parseFloat(input.value) || 0;
                        const type = raw < 0 ? 'debit' : 'credit';
                        this.saveInlineEdit(cell, field, Math.abs(raw).toString(), { type });
                    } else {
                        this.saveInlineEdit(cell, field, input.value);
                    }
                }
            }, 100);
        });
    }

    async saveInlineEdit(cell, field, value, extra = {}) {
        const transactionId = parseInt(cell.dataset.transactionId);
        const transaction = this.transactions.find(tx => tx.id === transactionId);

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
                showSuccess(t('budget', 'Transaction updated'));
            } else {
                throw new Error('Update failed');
            }
        } catch (error) {
            console.error('Failed to save inline edit:', error);
            showError(t('budget', 'Failed to update transaction'));
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

    // ===== Duplicate Detection =====

    async showDuplicatesModal() {
        // Remove any existing modal
        const existing = document.getElementById('duplicates-modal');
        if (existing) existing.remove();

        const modal = document.createElement('div');
        modal.id = 'duplicates-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `
            <div class="budget-modal">
                <div class="budget-modal-header">
                    <h2>${t('budget', 'Find Duplicate Transactions')}</h2>
                    <button class="close-btn" title="${t('budget', 'Close')}">&times;</button>
                </div>
                <div class="budget-modal-body">
                    <div id="duplicates-loading" class="loading-indicator">
                        <span class="icon-loading"></span>
                        <p>${t('budget', 'Scanning for duplicates...')}</p>
                    </div>
                    <div id="duplicates-content" style="display: none;"></div>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        // Close handlers
        modal.querySelector('.close-btn').addEventListener('click', () => {
            modal.remove();
            if (this._duplicatesDirty) {
                this.app.loadTransactions();
                this.app.loadAccounts();
            }
        });
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.remove();
                if (this._duplicatesDirty) {
                    this.app.loadTransactions();
                    this.app.loadAccounts();
                }
            }
        });

        this._duplicatesDirty = false;

        try {
            const response = await fetch(
                OC.generateUrl('/apps/budget/api/transactions/duplicates'),
                { headers: { 'requesttoken': OC.requestToken } }
            );
            if (!response.ok) throw new Error('Failed to fetch duplicates');
            const data = await response.json();

            document.getElementById('duplicates-loading').style.display = 'none';
            const content = document.getElementById('duplicates-content');
            content.style.display = 'block';

            if (!data.groups || data.groups.length === 0) {
                content.innerHTML = `<p class="empty-message">${t('budget', 'No duplicate transactions found.')}</p>`;
                return;
            }

            this.renderDuplicateGroups(content, data.groups);
        } catch (error) {
            console.error('Failed to find duplicates:', error);
            document.getElementById('duplicates-loading').style.display = 'none';
            const content = document.getElementById('duplicates-content');
            content.style.display = 'block';
            content.innerHTML = `<p class="error-message">${t('budget', 'Failed to scan for duplicates. Please try again.')}</p>`;
        }
    }

    renderDuplicateGroups(container, groups) {
        const totalDuplicates = groups.reduce((sum, g) => sum + g.length - 1, 0);

        let html = `
            <div class="duplicates-summary">
                <p>${t('budget', 'Found {groupCount} group(s) with {duplicateCount} suspected duplicate(s). The oldest transaction in each group is kept by default. Review and adjust the selection, then delete the checked items.', { groupCount: groups.length, duplicateCount: totalDuplicates })}</p>
                <div class="duplicates-actions">
                    <button id="duplicates-delete-btn" class="budget-btn primary">
                        ${t('budget', 'Delete Selected')} (<span id="duplicates-selected-count">${totalDuplicates}</span>)
                    </button>
                    <button id="duplicates-select-none-btn" class="budget-btn secondary">${t('budget', 'Deselect All')}</button>
                </div>
            </div>
            <div class="duplicates-groups">
        `;

        groups.forEach((group, groupIdx) => {
            const first = group[0];
            const amount = formatters.formatCurrency(first.amount, first.currency, this.settings);

            html += `
                <div class="duplicate-group">
                    <div class="duplicate-group-header">
                        <strong>${this.escapeHtml(first.description) || t('budget', '(no description)')}</strong>
                        &mdash; ${amount} (${this.escapeHtml(first.type)})
                        &mdash; ${this.escapeHtml(first.accountName)}
                    </div>
                    <div class="duplicate-group-items">
            `;

            group.forEach((tx, txIdx) => {
                // Pre-select all except the first (oldest) in the group
                const isExtra = txIdx > 0;
                const checked = isExtra ? 'checked' : '';
                const keepLabel = !isExtra ? `<span class="keep-badge">${t('budget', 'keep')}</span>` : '';
                const billLabel = tx.billId ? `<span class="bill-badge">${t('budget', 'bill')}</span>` : '';
                const statusLabel = tx.status === 'scheduled' ? `<span class="scheduled-badge">${t('budget', 'scheduled')}</span>` : '';

                html += `
                    <label class="duplicate-item ${isExtra ? 'pre-selected' : 'keep-item'}">
                        <input type="checkbox" class="duplicate-checkbox"
                            data-group="${groupIdx}" data-tx-id="${tx.id}" ${checked}>
                        <span class="duplicate-item-details">
                            <span class="duplicate-date">${this.escapeHtml(tx.date)}</span>
                            <span class="duplicate-vendor">${this.escapeHtml(tx.vendor) || '-'}</span>
                            <span class="duplicate-category">${this.escapeHtml(tx.categoryName) || '-'}</span>
                            <span class="duplicate-amount">${formatters.formatCurrency(tx.amount, tx.currency, this.settings)}</span>
                            ${keepLabel}${billLabel}${statusLabel}
                        </span>
                    </label>
                `;
            });

            html += '</div></div>';
        });

        html += '</div>';
        container.innerHTML = html;

        // Update selected count on checkbox changes
        container.addEventListener('change', (e) => {
            if (e.target.classList.contains('duplicate-checkbox')) {
                this.updateDuplicatesSelectedCount();
            }
        });

        // Delete selected
        document.getElementById('duplicates-delete-btn').addEventListener('click', () => {
            this.deleteDuplicates();
        });

        // Deselect all
        document.getElementById('duplicates-select-none-btn').addEventListener('click', () => {
            container.querySelectorAll('.duplicate-checkbox').forEach(cb => cb.checked = false);
            this.updateDuplicatesSelectedCount();
        });
    }

    updateDuplicatesSelectedCount() {
        const checked = document.querySelectorAll('.duplicate-checkbox:checked');
        const countEl = document.getElementById('duplicates-selected-count');
        const deleteBtn = document.getElementById('duplicates-delete-btn');
        if (countEl) countEl.textContent = checked.length;
        if (deleteBtn) deleteBtn.disabled = checked.length === 0;
    }

    async deleteDuplicates() {
        const checked = document.querySelectorAll('.duplicate-checkbox:checked');
        const ids = Array.from(checked).map(cb => parseInt(cb.dataset.txId));

        if (ids.length === 0) return;

        const billCount = Array.from(checked).filter(cb => {
            const item = cb.closest('.duplicate-item');
            return item && item.querySelector('.bill-badge');
        }).length;

        let message = n('budget', 'Delete %n suspected duplicate transaction? This cannot be undone.', 'Delete %n suspected duplicate transactions? This cannot be undone.', ids.length);
        if (billCount > 0) {
            message += '\n\n' + n('budget', '%n of these was generated from bill payments. Make sure they are truly duplicates before deleting.', '%n of these were generated from bill payments. Make sure they are truly duplicates before deleting.', billCount);
        }

        if (!confirm(message)) return;

        const deleteBtn = document.getElementById('duplicates-delete-btn');
        deleteBtn.disabled = true;
        deleteBtn.textContent = t('budget', 'Deleting...');

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/transactions/bulk-delete'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ ids })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const result = await response.json();

            if (result.success > 0) {
                showSuccess(n('budget', 'Deleted %n duplicate transaction', 'Deleted %n duplicate transactions', result.success));
                this._duplicatesDirty = true;

                // Remove deleted items from the UI
                checked.forEach(cb => {
                    const item = cb.closest('.duplicate-item');
                    if (item) item.remove();
                });

                // Remove groups that now have only one item
                document.querySelectorAll('.duplicate-group').forEach(group => {
                    const remaining = group.querySelectorAll('.duplicate-item');
                    if (remaining.length <= 1) {
                        group.remove();
                    }
                });

                this.updateDuplicatesSelectedCount();

                // Check if all groups are resolved
                const remainingGroups = document.querySelectorAll('.duplicate-group');
                if (remainingGroups.length === 0) {
                    const content = document.getElementById('duplicates-content');
                    content.innerHTML = `<p class="empty-message">${t('budget', 'All duplicates have been resolved.')}</p>`;
                }
            }

            if (result.failed > 0) {
                showError(n('budget', 'Failed to delete %n transaction', 'Failed to delete %n transactions', result.failed));
            }
        } catch (error) {
            console.error('Failed to delete duplicates:', error);
            showError(t('budget', 'Failed to delete duplicates'));
        } finally {
            if (deleteBtn && document.body.contains(deleteBtn)) {
                deleteBtn.disabled = false;
                deleteBtn.textContent = t('budget', 'Delete Selected') + ` (${document.querySelectorAll('.duplicate-checkbox:checked').length})`;
            }
        }
    }
}
