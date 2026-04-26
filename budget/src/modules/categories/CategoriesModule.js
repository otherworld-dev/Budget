/**
 * Categories Module - Category management, budgets, and tree visualization
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError, showWarning } from '../../utils/notifications.js';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import Chart from 'chart.js/auto';

export default class CategoriesModule {
    constructor(app) {
        this.app = app;
        this.selectedCategory = null;
        this.selectedCategoryIds = new Set();
        this.expandedCategories = new Set();
        this.currentCategoryType = 'expense';
        this.budgetType = 'expense';
        this.budgetMonth = new Date().toISOString().slice(0, 7); // YYYY-MM
        this.budgetEventListenersSetup = false;
        this.categoryEventListenersSetup = false;
        this.categorySpending = {};
        this.categoryChart = null;
    }

    // State proxies
    get accounts() {
        return this.app.accounts;
    }

    get categories() {
        return this.app.categories;
    }

    get allCategories() {
        return this.app.allCategories;
    }

    set allCategories(value) {
        this.app.allCategories = value;
    }

    get categoryTree() {
        return this.app.categoryTree;
    }

    set categoryTree(value) {
        this.app.categoryTree = value;
    }

    get transactions() {
        return this.app.transactions;
    }

    get settings() {
        return this.app.settings;
    }

    // Helper method delegations
    formatCurrency(amount, currency = null) {
        return formatters.formatCurrency(amount, currency, this.settings);
    }

    formatDate(date) {
        return formatters.formatDate(date);
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    async loadCategories() {
        try {
            const [treeResponse, countsResponse] = await Promise.all([
                fetch(OC.generateUrl('/apps/budget/api/categories/tree'), {
                    headers: { 'requesttoken': OC.requestToken }
                }),
                fetch(OC.generateUrl('/apps/budget/api/categories/transaction-counts'), {
                    headers: { 'requesttoken': OC.requestToken }
                }),
            ]);
            if (treeResponse.ok) {
                const fullTree = await treeResponse.json();
                // Merge own + shared for dropdowns and budget view
                const mergedTree = this.mergeCategoryTree(fullTree);
                this.app.categoryTree = mergedTree;
                this.app.allCategories = this.flattenCategories(mergedTree);
                this.app.categories = this.app.allCategories;
                // For the management view, show only own categories
                this.managementTree = fullTree.filter(cat => !cat._shared);
            }
            if (countsResponse.ok) {
                this.serverTransactionCounts = await countsResponse.json();
            }
            this.renderCategoriesTree();
            this.setupCategoriesEventListeners();
        } catch (error) {
            console.error('Failed to load categories:', error);
            showError(t('budget', 'Failed to load categories'));
        }
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
        // Prevent duplicate event listeners
        if (this.categoryEventListenersSetup) {
            return;
        }
        this.categoryEventListenersSetup = true;

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

        // Use management tree (own categories only, no shared) for the management page
        const tree = this.managementTree || this.categoryTree;
        if (!tree || !Array.isArray(tree) || tree.length === 0) {
            treeContainer.innerHTML = '';
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        // Filter categories by current type
        const typedCategories = tree.filter(cat => cat.type === this.currentCategoryType);

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

        // Auto-select first category if none is selected
        if (!this.selectedCategory && typedCategories.length > 0) {
            this.selectCategory(typedCategories[0].id);
        }
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
                                title="${t('budget', 'Delete {name}', { name: category.name })}">
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
        // Use server-side counts (loaded in loadCategories), fall back to empty
        return this.serverTransactionCounts || {};
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
            targetItem.parentNode.insertBefore(indicator, targetItem);
        } else if (y > rect.height - threshold) {
            // Drop below
            indicator.classList.add('bottom');
            targetItem.parentNode.insertBefore(indicator, targetItem.nextSibling);
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
                showSuccess(t('budget', 'Category reordered successfully'));
            } else {
                throw new Error(t('budget', 'Failed to reorder category'));
            }

        } catch (error) {
            console.error('Failed to reorder category:', error);
            showError(t('budget', 'Failed to reorder category'));
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

        // Load data from server in parallel
        const [detailsRes, transactionsRes] = await Promise.all([
            fetch(OC.generateUrl(`/apps/budget/api/categories/${category.id}/details`), {
                headers: { 'requesttoken': OC.requestToken }
            }),
            fetch(OC.generateUrl(`/apps/budget/api/categories/${category.id}/transactions?limit=5`), {
                headers: { 'requesttoken': OC.requestToken }
            }),
            this.app.renderCategoryTagSetsList(category.id),
        ]);

        if (detailsRes.ok) {
            const details = await detailsRes.json();
            this.updateAnalyticsFromServer(details);
            this.renderCategorySpendingChartFromServer(details.monthlySpending, category.color);
        }

        if (transactionsRes.ok) {
            const transactions = await transactionsRes.json();
            this.renderRecentTransactions(transactions);
        }
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
            const typeLabels = { expense: t('budget', 'Expense'), income: t('budget', 'Income') };
            typeEl.textContent = typeLabels[category.type] || category.type;
            typeEl.className = `category-type-badge ${category.type}`;
        }

        // Build category path
        const path = this.getCategoryPath(category);
        const pathEl = document.getElementById('category-display-path');
        if (pathEl) pathEl.textContent = path;
    }

    updateAnalyticsFromServer(details) {
        const countEl = document.getElementById('total-transactions-count');
        if (countEl) countEl.textContent = details.count.toLocaleString();

        const avgEl = document.getElementById('avg-transaction-amount');
        if (avgEl) avgEl.textContent = this.formatCurrency(details.average);

        const trendEl = document.getElementById('category-trend');
        if (trendEl) {
            const trendMap = {
                increasing: '\u2197 ' + t('budget', 'Increasing'),
                decreasing: '\u2198 ' + t('budget', 'Decreasing'),
                stable: '\u2192 ' + t('budget', 'Stable'),
            };
            trendEl.textContent = trendMap[details.trend] || '\u2014';
        }

        const totalSpentEl = document.getElementById('category-total-spent-value');
        if (totalSpentEl) totalSpentEl.textContent = this.formatCurrency(details.total);

        const thisMonthEl = document.getElementById('category-this-month');
        if (thisMonthEl) thisMonthEl.textContent = this.formatCurrency(details.thisMonth);
    }

    renderRecentTransactions(transactions) {
        const container = document.getElementById('category-recent-transactions');
        if (!container) return;

        if (!transactions || transactions.length === 0) {
            container.innerHTML = '<div class="empty-state"><p>' + t('budget', 'No transactions in this category yet.') + '</p></div>';
            return;
        }

        container.innerHTML = transactions.map(transaction => `
            <div class="transaction-item">
                <div class="transaction-description">${this.escapeHtml(transaction.description || '')}</div>
                <div class="transaction-date">${this.formatDate(transaction.date)}</div>
                <div class="transaction-amount ${transaction.type}">
                    ${transaction.type === 'credit' ? '+' : '-'}${this.formatCurrency(Math.abs(transaction.amount))}
                </div>
            </div>
        `).join('');
    }

    renderCategorySpendingChartFromServer(monthlySpending, categoryColor) {
        const canvas = document.getElementById('category-spending-chart');
        if (!canvas) return;

        if (this.categoryChart) {
            this.categoryChart.destroy();
            this.categoryChart = null;
        }

        // Build labels and amounts from server data, filling gaps for missing months
        const now = new Date();
        const months = [];
        const amounts = [];
        const serverMap = {};
        for (const entry of (monthlySpending || [])) {
            serverMap[entry.month] = entry.total;
        }
        for (let i = 5; i >= 0; i--) {
            const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
            const key = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
            months.push(d.toLocaleDateString(undefined, { month: 'short' }));
            amounts.push(serverMap[key] || 0);
        }

        const chartColor = categoryColor || 'rgba(54, 162, 235, 0.7)';
        const ctx = canvas.getContext('2d');
        this.categoryChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: months,
                datasets: [{
                    data: amounts,
                    backgroundColor: chartColor,
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: v => this.formatCurrency(v) }
                    },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    showCategoryDetailsEmpty() {
        const contentEl = document.getElementById('category-details-content');
        const emptyEl = document.getElementById('category-details-empty');

        if (contentEl) contentEl.style.display = 'none';
        if (emptyEl) emptyEl.style.display = 'flex';

        if (this.categoryChart) {
            this.categoryChart.destroy();
            this.categoryChart = null;
        }
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

        return path.length > 0 ? path.join(' › ') : t('budget', 'Root');
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

        title.textContent = t('budget', 'Add Category');
        this.resetCategoryForm();

        // Set category type BEFORE populating parent dropdown so it filters correctly
        const typeSelect = document.getElementById('category-type');
        if (typeSelect && this.currentCategoryType) {
            typeSelect.value = this.currentCategoryType;
        }

        this.populateCategoryParentDropdown();

        // Show empty state for tag sets (can't add tag sets until category is saved)
        this.app.renderCategoryTagSetsUI(null);

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

        title.textContent = t('budget', 'Edit Category');
        this.populateCategoryParentDropdown(this.selectedCategory.id, this.selectedCategory.parentId);
        this.loadCategoryData(this.selectedCategory);

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
        if (!confirm(t('budget', 'Are you sure you want to delete the category "{name}"? This action cannot be undone.', { name: categoryName }))) {
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
                showSuccess(t('budget', 'Category deleted successfully'));
                this.selectedCategory = null;
                await this.loadCategories();
                await this.app.loadInitialData();
                this.showCategoryDetailsEmpty();
            } else {
                const error = await response.json();
                throw new Error(error.error || t('budget', 'Failed to delete category'));
            }
        } catch (error) {
            console.error('Failed to delete category:', error);
            showError(error.message || t('budget', 'Failed to delete category'));
        }
    }

    async deleteCategoryById(categoryId) {
        const category = this.findCategoryById(categoryId);
        const categoryName = category ? category.name : t('budget', 'this category');

        if (!confirm(t('budget', 'Are you sure you want to delete "{name}"? This action cannot be undone.', { name: categoryName }))) {
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
                showSuccess(t('budget', 'Category deleted successfully'));
                if (this.selectedCategory?.id === categoryId) {
                    this.selectedCategory = null;
                    this.showCategoryDetailsEmpty();
                }
                this.selectedCategoryIds.delete(categoryId);
                await this.loadCategories();
                await this.app.loadInitialData();
            } else {
                const error = await response.json();
                throw new Error(error.error || t('budget', 'Failed to delete category'));
            }
        } catch (error) {
            console.error('Failed to delete category:', error);
            showError(error.message || t('budget', 'Failed to delete category'));
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
            countSpan.textContent = n('budget', '%n selected', '%n selected', selectedCount);
        }
    }

    async bulkDeleteCategories() {
        const count = this.selectedCategoryIds.size;
        if (count === 0) return;

        if (!confirm(n('budget', 'Are you sure you want to delete %n category? This action cannot be undone.', 'Are you sure you want to delete %n categories? This action cannot be undone.', count))) {
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
                    errors.push(`${category?.name || categoryId}: ${error.error || t('budget', 'Failed to delete')}`);
                }
            } catch (error) {
                const category = this.findCategoryById(categoryId);
                errors.push(`${category?.name || categoryId}: ${error.message}`);
            }
        }

        if (deleted > 0) {
            showSuccess(n('budget', '%n category deleted successfully', '%n categories deleted successfully', deleted));
            this.selectedCategory = null;
            this.showCategoryDetailsEmpty();
            await this.loadCategories();
            await this.app.loadInitialData();
        }

        if (errors.length > 0) {
            showError(t('budget', 'Failed to delete: {errors}', { errors: errors.join(', ') }));
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

        // Load tag sets for this category
        this.app.renderCategoryTagSetsUI(category.id);
    }

    populateCategoryParentDropdown(excludeId = null, selectedId = null) {
        const parentSelect = document.getElementById('category-parent');
        if (!parentSelect) return;

        const typeSelect = document.getElementById('category-type');
        const currentType = typeSelect ? typeSelect.value : 'expense';

        parentSelect.innerHTML = `<option value="">${t('budget', 'None (Top Level)')}</option>`;

        if (this.categoryTree) {
            dom.populateCategorySelect(parentSelect, this.categoryTree, {
                typeFilter: currentType,
                excludeId: excludeId ? parseInt(excludeId) : null,
                selectedId: selectedId ? parseInt(selectedId) : null,
            });
        }
    }

    async saveCategory() {
        const categoryId = document.getElementById('category-id').value;
        const name = document.getElementById('category-name').value.trim();
        const type = document.getElementById('category-type').value;
        const parentId = document.getElementById('category-parent').value || null;
        const color = document.getElementById('category-color').value;

        if (!name) {
            showWarning(t('budget', 'Category name is required'));
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
                showSuccess(isEdit ? t('budget', 'Category updated successfully') : t('budget', 'Category created successfully'));
                this.app.hideModals();
                await this.loadCategories();
                await this.app.loadInitialData();

                // Re-select the category to update the details panel
                const categoryIdToSelect = isEdit ? parseInt(categoryId) : savedCategory.id;
                if (categoryIdToSelect) {
                    this.selectCategory(categoryIdToSelect);
                }
            } else {
                const error = await response.json();
                throw new Error(error.error || t('budget', 'Failed to save category'));
            }
        } catch (error) {
            console.error('Failed to save category:', error);
            showError(error.message || t('budget', 'Failed to save category'));
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
                showSuccess(t('budget', 'Default categories created successfully'));
                await this.loadCategories();
                await this.app.loadInitialData();
            } else {
                let message = t('budget', 'Failed to create default categories');
                try {
                    const error = await response.json();
                    message = error.error || message;
                } catch (e) {
                    // Response wasn't JSON (e.g. server error page)
                }
                throw new Error(message);
            }
        } catch (error) {
            console.error('Failed to create default categories:', error);
            showError(error.message || t('budget', 'Failed to create default categories'));
        }
    }

    // ===================================
    // Budget View Methods
    // ===================================

    async loadBudgetView() {
        // Initialize budget state
        this.budgetType = this.budgetType || 'expense';
        this.budgetMonth = this.budgetMonth || new Date().toISOString().slice(0, 7); // YYYY-MM
        this._snapshotMonths = this._snapshotMonths || [];
        this._effectiveBudgets = null;

        // Setup event listeners on first load
        if (!this.budgetEventListenersSetup) {
            this.setupBudgetEventListeners();
            this.budgetEventListenersSetup = true;
        }

        // Populate month selector
        this.populateBudgetMonthSelector();

        // Always fetch fresh with shared categories for budget view
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/categories/tree'), {
                headers: this.app.getAuthHeaders()
            });
            if (response.ok) {
                const rawTree = await response.json();
                // Merge own + shared: shared takes priority, children merged
                const mergedTree = this.mergeCategoryTree(rawTree);
                this.categoryTree = mergedTree;
                this.allCategories = this.flattenCategories(mergedTree);
            }
        } catch (error) {
            console.error('Failed to load categories for budget:', error);
        }

        // Fetch effective budgets for this month (snapshot-aware)
        await this.fetchEffectiveBudgets();

        // Calculate spending for each category
        await this.calculateCategorySpending();

        // Render the budget tree
        this.renderBudgetTree();

        // Update summary
        this.updateBudgetSummary();

        // Render snapshot controls
        this.renderSnapshotControls();
    }

    async fetchEffectiveBudgets() {
        try {
            const response = await fetch(
                OC.generateUrl(`/apps/budget/api/budget-snapshots/${this.budgetMonth}/budgets`),
                { headers: this.app.getAuthHeaders() }
            );
            if (response.ok) {
                const data = await response.json();
                this._effectiveBudgets = data.budgets || {};
                this._currentMonthHasSnapshot = data.hasSnapshot || false;
            }
        } catch (error) {
            console.error('Failed to fetch effective budgets:', error);
            this._effectiveBudgets = null;
            this._currentMonthHasSnapshot = false;
        }

        // Also fetch snapshot months list
        try {
            const response = await fetch(
                OC.generateUrl('/apps/budget/api/budget-snapshots'),
                { headers: this.app.getAuthHeaders() }
            );
            if (response.ok) {
                this._snapshotMonths = await response.json();
            }
        } catch (error) {
            this._snapshotMonths = [];
        }
    }

    renderSnapshotControls() {
        const container = document.getElementById('budget-snapshot-controls');
        if (!container) return;

        const monthLabel = new Date(this.budgetMonth + '-01').toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

        if (this._currentMonthHasSnapshot) {
            // Show notice that this month has adjusted budgets
            container.innerHTML = `
                <div class="budget-snapshot-notice">
                    <span class="icon-info" aria-hidden="true"></span>
                    <span>${t('budget', 'Budgets adjusted from {month}', { month: monthLabel })}</span>
                    <button class="budget-snapshot-remove" title="${t('budget', 'Remove adjustment')}">
                        <span class="icon-close" aria-hidden="true"></span>
                    </button>
                </div>
            `;
            container.querySelector('.budget-snapshot-remove')?.addEventListener('click', () => {
                this.deleteSnapshot(this.budgetMonth);
            });
        } else {
            // Show button to create snapshot
            container.innerHTML = `
                <button class="budget-snapshot-btn" id="budget-snapshot-create-btn">
                    <span class="icon-edit" aria-hidden="true"></span>
                    ${t('budget', 'Adjust budgets from this month')}
                </button>
            `;
            container.querySelector('#budget-snapshot-create-btn')?.addEventListener('click', () => {
                this.confirmCreateSnapshot();
            });
        }
    }

    confirmCreateSnapshot() {
        const monthLabel = new Date(this.budgetMonth + '-01').toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

        OC.dialogs.confirmDestructive(
            t('budget', 'This will save the current budget values as a new baseline from {month} onwards. Previous months will keep their existing values. You can edit the new values after confirming.', { month: monthLabel }),
            t('budget', 'Adjust budgets from {month}?', { month: monthLabel }),
            {
                type: OC.dialogs.YES_NO_BUTTONS,
                confirm: t('budget', 'Confirm'),
                cancel: t('budget', 'Cancel'),
            },
            async (confirmed) => {
                if (!confirmed) return;
                await this.createSnapshot(this.budgetMonth);
            }
        );
    }

    async createSnapshot(month) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/budget-snapshots/${month}`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                }
            });

            if (response.ok) {
                this._currentMonthHasSnapshot = true;
                if (!this._snapshotMonths.includes(month)) {
                    this._snapshotMonths.push(month);
                    this._snapshotMonths.sort().reverse();
                }
                await this.fetchEffectiveBudgets();
                this.renderBudgetTree();
                this.updateBudgetSummary();
                this.renderSnapshotControls();

                const monthLabel = new Date(month + '-01').toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
                showSuccess(t('budget', 'Budget adjusted from {month}. You can now edit values for this month onwards.', { month: monthLabel }));

                // Undo toast
                this._showSnapshotUndo(month);
            } else {
                const data = await response.json().catch(() => ({}));
                showError(data.error || t('budget', 'Failed to create budget adjustment'));
            }
        } catch (error) {
            console.error('Failed to create snapshot:', error);
            showError(t('budget', 'Failed to create budget adjustment'));
        }
    }

    _showSnapshotUndo(month) {
        // Create undo notification
        const undoEl = document.createElement('div');
        undoEl.className = 'budget-snapshot-undo-toast';
        undoEl.innerHTML = `
            <span>${t('budget', 'Budget adjustment created.')}</span>
            <button class="undo-btn">${t('budget', 'Undo')}</button>
        `;
        document.body.appendChild(undoEl);

        // Show with animation
        requestAnimationFrame(() => undoEl.classList.add('visible'));

        const cleanup = () => {
            undoEl.classList.remove('visible');
            setTimeout(() => undoEl.remove(), 300);
        };

        undoEl.querySelector('.undo-btn').addEventListener('click', async () => {
            cleanup();
            await this.deleteSnapshot(month);
        });

        // Auto-dismiss after 8 seconds
        setTimeout(cleanup, 8000);
    }

    async deleteSnapshot(month) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/budget-snapshots/${month}`), {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                }
            });

            if (response.ok) {
                this._currentMonthHasSnapshot = false;
                this._snapshotMonths = this._snapshotMonths.filter(m => m !== month);
                await this.fetchEffectiveBudgets();
                this.renderBudgetTree();
                this.updateBudgetSummary();
                this.renderSnapshotControls();
                showSuccess(t('budget', 'Budget adjustment removed'));
            } else {
                showError(t('budget', 'Failed to remove budget adjustment'));
            }
        } catch (error) {
            console.error('Failed to delete snapshot:', error);
            showError(t('budget', 'Failed to remove budget adjustment'));
        }
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
                await this.fetchEffectiveBudgets();
                await this.calculateCategorySpending();
                this.renderBudgetTree();
                this.updateBudgetSummary();
                this.renderSnapshotControls();
            });
        }

        // Go to categories button (empty state)
        const goToCategoriesBtn = document.getElementById('empty-budget-go-categories-btn');
        if (goToCategoriesBtn) {
            goToCategoriesBtn.addEventListener('click', () => {
                this.app.router.showView('categories');
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
            const label = date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
            options.push({ value, label });
        }

        monthSelect.innerHTML = options.map(opt =>
            `<option value="${opt.value}" ${opt.value === this.budgetMonth ? 'selected' : ''}>${opt.label}</option>`
        ).join('');
    }

    async calculateCategorySpending() {
        // Initialize spending object and reset own-spending baseline
        this.categorySpending = {};
        this._ownSpending = {};

        // Get all categories (not just ones with budgets — parents need children's spending)
        const allCategories = this.flattenCategories(this.categoryTree || []);

        if (allCategories.length === 0) {
            return;
        }

        // Group categories by period and type to minimize API calls
        // Income categories need credit transactions, expense categories need debit
        const groups = {};

        allCategories.forEach(cat => {
            const period = cat.budgetPeriod || 'monthly';
            const txType = cat.type === 'income' ? 'credit' : 'debit';
            const key = `${period}:${txType}`;
            if (!groups[key]) {
                groups[key] = { period, txType, categoryIds: [] };
            }
            groups[key].categoryIds.push(cat.id);
        });

        // Fetch spending for each period+type group
        try {
            for (const { period, txType, categoryIds } of Object.values(groups)) {
                if (categoryIds.length === 0) continue;

                // Get date range for this period
                const startDay = period === 'monthly' ? parseInt(this.app.settings?.budget_start_day || '1', 10) : 1;
                // Use selected budget month as reference date (1st of month)
                const referenceDate = this.budgetMonth ? `${this.budgetMonth}-15` : null;
                const dateRange = formatters.getPeriodDateRange(period, startDay, referenceDate);

                // Fetch spending for this period and transaction type
                const response = await fetch(
                    OC.generateUrl(`/apps/budget/api/categories/spending?startDate=${dateRange.start}&endDate=${dateRange.end}&transactionType=${txType}`),
                    {
                        headers: { 'requesttoken': OC.requestToken }
                    }
                );

                if (response.ok) {
                    const spendingData = await response.json();
                    // Map spending to categories
                    spendingData.forEach(item => {
                        if (categoryIds.includes(item.categoryId)) {
                            this.categorySpending[item.categoryId] = parseFloat(item.spent) || 0;
                        }
                    });
                }
            }
        } catch (error) {
            console.error('Failed to fetch category spending:', error);
            this.categorySpending = {};
        }

        // Aggregate children's spending into parent categories
        this.aggregateParentSpending(this.categoryTree || []);
    }

    /**
     * Recursively sum children's spending and budgets into parent categories.
     * Safe to call multiple times — uses _ownSpending baseline to avoid double-counting.
     */
    aggregateParentSpending(categories) {
        for (const category of categories) {
            if (category.children && category.children.length > 0) {
                // Recurse into children first
                this.aggregateParentSpending(category.children);

                // Save own spending on first pass, reuse on subsequent calls
                if (this._ownSpending == null) {
                    this._ownSpending = {};
                }
                if (!(category.id in this._ownSpending)) {
                    this._ownSpending[category.id] = this.categorySpending[category.id] || 0;
                }

                // Sum all children's spending and budgets into this parent
                let childSpentTotal = 0;
                let childBudgetTotal = 0;
                for (const child of category.children) {
                    childSpentTotal += this.categorySpending[child.id] || 0;
                    childBudgetTotal += this._getEffectiveBudgetAmount(child.id, child.budgetAmount);
                }

                // Own spending + children's spending (idempotent)
                this.categorySpending[category.id] = this._ownSpending[category.id] + childSpentTotal;

                // Store aggregated budget: parent's own budget + children's budgets
                const ownBudget = this._getEffectiveBudgetAmount(category.id, category.budgetAmount);
                category._aggregatedBudget = ownBudget + childBudgetTotal;
            }
        }
    }

    /**
     * Get the effective budget amount for a category, considering snapshots.
     */
    _getEffectiveBudgetAmount(categoryId, fallback) {
        if (this._effectiveBudgets && this._effectiveBudgets[categoryId]) {
            return parseFloat(this._effectiveBudgets[categoryId].amount) || 0;
        }
        return parseFloat(fallback) || 0;
    }

    /**
     * Get the effective budget period for a category, considering snapshots.
     */
    _getEffectiveBudgetPeriod(categoryId, fallback) {
        if (this._effectiveBudgets && this._effectiveBudgets[categoryId]) {
            return this._effectiveBudgets[categoryId].period || 'monthly';
        }
        return fallback || 'monthly';
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

            // Get effective budget (snapshot-aware)
            const effectiveBudgetAmount = this._getEffectiveBudgetAmount(category.id, category.budgetAmount);
            const effectivePeriod = this._getEffectiveBudgetPeriod(category.id, category.budgetPeriod);

            // Get spending for this category (already calculated for the period)
            const spent = this.categorySpending[category.id] || 0;

            // For parents, use aggregated budget (own + children's); for leaves, use effective budget
            const budget = (hasChildren && category._aggregatedBudget != null)
                ? category._aggregatedBudget
                : effectiveBudgetAmount;

            const remaining = budget - spent;
            const percentage = budget > 0 ? Math.min((spent / budget) * 100, 100) : 0;
            const isIncome = category.type === 'income';

            let progressStatus = 'good';
            if (isIncome) {
                // For income: exceeding target is good, under target is concerning
                if (percentage >= 100) progressStatus = 'good';
                else if (percentage >= 80) progressStatus = 'good';
                else if (percentage >= 60) progressStatus = 'warning';
                else progressStatus = 'danger';
            } else {
                // For expenses: under budget is good, over is bad
                if (percentage >= 100) progressStatus = 'over';
                else if (percentage >= 80) progressStatus = 'danger';
                else if (percentage >= 60) progressStatus = 'warning';
            }

            // For income, negative remaining = exceeded target (good), positive = not yet reached (neutral)
            const remainingClass = isIncome
                ? (remaining <= 0 ? 'positive' : 'zero')
                : (remaining > 0 ? 'positive' : (remaining < 0 ? 'negative' : 'zero'));

            return `
                <div class="budget-category-row ${hasChildren ? 'parent-row' : ''}" data-category-id="${category.id}">
                    <div class="budget-category-name level-${level}" data-label="">
                        <span class="category-color" style="background-color: ${category.color || '#3b82f6'}"></span>
                        <span class="category-label">${category.name}</span>
                    </div>
                    <div class="budget-input-wrapper" data-label="${t('budget', 'Budget')}">
                        <input type="number"
                               class="budget-input"
                               data-category-id="${category.id}"
                               value="${effectiveBudgetAmount ? Math.round(effectiveBudgetAmount * 100) / 100 : ''}"
                               placeholder="0.00"
                               step="0.01"
                               min="0">
                        ${hasChildren && budget > effectiveBudgetAmount ? `<span class="budget-aggregate-hint">${t('budget', 'Total')}: ${this.formatCurrency(budget)}</span>` : ''}
                    </div>
                    <div data-label="${t('budget', 'Period')}">
                        <select class="budget-period-select" data-category-id="${category.id}">
                            <option value="monthly" ${effectivePeriod === 'monthly' ? 'selected' : ''}>${t('budget', 'Monthly')}</option>
                            <option value="weekly" ${effectivePeriod === 'weekly' ? 'selected' : ''}>${t('budget', 'Weekly')}</option>
                            <option value="quarterly" ${effectivePeriod === 'quarterly' ? 'selected' : ''}>${t('budget', 'Quarterly')}</option>
                            <option value="yearly" ${effectivePeriod === 'yearly' ? 'selected' : ''}>${t('budget', 'Yearly')}</option>
                        </select>
                    </div>
                    <div class="budget-spent" data-label="${t('budget', 'Spent')}">
                        ${this.formatCurrency(spent)}
                    </div>
                    <div class="budget-remaining ${remainingClass}" data-label="${t('budget', 'Remaining')}">
                        ${budget > 0 ? this.formatCurrency(remaining) : '<span class="no-budget">—</span>'}
                    </div>
                    <div class="budget-progress-wrapper" data-label="${t('budget', 'Progress')}">
                        ${budget > 0 ? `
                            <div class="budget-progress-bar">
                                <div class="budget-progress-fill ${progressStatus}" style="width: ${percentage}%"></div>
                            </div>
                            <span class="budget-progress-text">${Math.round(percentage)}%</span>
                        ` : `<span class="no-budget">${t('budget', 'No budget set')}</span>`}
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
            select.addEventListener('change', async (e) => {
                const categoryId = parseInt(e.target.dataset.categoryId);
                const newPeriod = e.target.value;
                const oldPeriod = e.target.dataset.oldPeriod || e.target.querySelector('option[selected]')?.value || 'monthly';

                // Find the category to get current budget amount
                const category = this.findCategoryById(categoryId);
                if (!category) return;

                const currentBudget = this._getEffectiveBudgetAmount(categoryId, category.budgetAmount);
                const currentPeriod = this._getEffectiveBudgetPeriod(categoryId, category.budgetPeriod);

                // Pro-rate budget from current period to new period
                const proratedBudget = formatters.prorateBudget(currentBudget, currentPeriod, newPeriod);

                // Save both the new period and pro-rated amount
                await this.saveCategoryBudget(categoryId, {
                    budgetPeriod: newPeriod,
                    budgetAmount: proratedBudget
                });

                // Recalculate spending for the new period
                await this.recalculateCategorySpending(categoryId, newPeriod);

                // Update old period data attribute for next change
                e.target.dataset.oldPeriod = newPeriod;
            });
        });
    }

    async recalculateCategorySpending(categoryId, period) {
        try {
            // Get date range for the period
            const startDay = period === 'monthly' ? parseInt(this.app.settings?.budget_start_day || '1', 10) : 1;
            const dateRange = formatters.getPeriodDateRange(period, startDay);

            // Fetch spending for this category in the period
            const response = await fetch(
                OC.generateUrl(`/apps/budget/api/categories/spending?startDate=${dateRange.start}&endDate=${dateRange.end}`),
                {
                    headers: { 'requesttoken': OC.requestToken }
                }
            );

            if (response.ok) {
                const spendingData = await response.json();

                // Find this category's spending in the response
                const categorySpending = spendingData.find(item => item.categoryId === categoryId);
                const spent = categorySpending ? parseFloat(categorySpending.spent) || 0 : 0;

                // Update local spending data for this category
                this.categorySpending[categoryId] = spent;

                // Re-render to show updated spending
                this.renderBudgetTree();
                this.updateBudgetSummary();
            }
        } catch (error) {
            console.error('Failed to recalculate spending:', error);
        }
    }

    async saveCategoryBudget(categoryId, updates) {
        try {
            // Convert empty string or null to 0 for budgetAmount
            if ('budgetAmount' in updates && (updates.budgetAmount === null || updates.budgetAmount === '')) {
                updates.budgetAmount = 0;
            } else if ('budgetAmount' in updates) {
                updates.budgetAmount = parseFloat(updates.budgetAmount) || 0;
            }

            let response;

            if (this._currentMonthHasSnapshot) {
                // Save to snapshot API
                const snapshotPayload = {};
                if ('budgetAmount' in updates) snapshotPayload.amount = updates.budgetAmount;
                if ('budgetPeriod' in updates) snapshotPayload.period = updates.budgetPeriod;

                response = await fetch(
                    OC.generateUrl(`/apps/budget/api/budget-snapshots/${this.budgetMonth}/categories/${categoryId}`),
                    {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'requesttoken': OC.requestToken
                        },
                        body: JSON.stringify(snapshotPayload)
                    }
                );
            } else {
                // Save to category directly (default behaviour)
                response = await fetch(OC.generateUrl(`/apps/budget/api/categories/${categoryId}`), {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'requesttoken': OC.requestToken
                    },
                    body: JSON.stringify(updates)
                });
            }

            if (response.ok) {
                // Update local data
                const category = this.findCategoryById(parseInt(categoryId));
                if (category) {
                    if (!this._currentMonthHasSnapshot) {
                        Object.assign(category, updates);
                    }
                }

                // Update effective budgets cache locally
                if (this._effectiveBudgets) {
                    if (!this._effectiveBudgets[categoryId]) {
                        this._effectiveBudgets[categoryId] = { amount: 0, period: 'monthly' };
                    }
                    if ('budgetAmount' in updates) {
                        this._effectiveBudgets[categoryId].amount = updates.budgetAmount;
                    }
                    if ('budgetPeriod' in updates) {
                        this._effectiveBudgets[categoryId].period = updates.budgetPeriod;
                    }
                }

                // Re-aggregate parent budgets and re-render
                this.aggregateParentSpending(this.categoryTree || []);
                this.renderBudgetTree();
                this.updateBudgetSummary();

                // Refresh dashboard if currently viewing it
                if (window.location.hash === '' || window.location.hash === '#/dashboard') {
                    await this.app.loadDashboard();
                }

                showSuccess(t('budget', 'Budget updated'));
            } else {
                // Try to get detailed error message
                let errorMessage = t('budget', 'Failed to update budget');
                try {
                    const errorData = await response.json();
                    if (errorData.error) {
                        errorMessage = errorData.error;
                    }
                } catch (e) {
                    errorMessage = `HTTP ${response.status}: ${response.statusText}`;
                }
                throw new Error(errorMessage);
            }
        } catch (error) {
            console.error('Failed to save budget:', error);
            showError(t('budget', 'Failed to update budget: {message}', { message: error.message }));
        }
    }

    updateBudgetSummary() {
        const categories = this.flattenCategories(this.categoryTree || [])
            .filter(cat => cat.type === this.budgetType);

        let totalBudgeted = 0;
        let totalSpent = 0;
        let categoriesWithBudget = 0;

        categories.forEach(cat => {
            const budget = this._getEffectiveBudgetAmount(cat.id, cat.budgetAmount);
            const period = this._getEffectiveBudgetPeriod(cat.id, cat.budgetPeriod);
            // Normalize to monthly so the summary cards stay consistent
            const monthlyBudget = formatters.prorateBudget(budget, period, 'monthly');
            const spent = this.categorySpending[cat.id] || 0;

            if (budget > 0) {
                totalBudgeted += monthlyBudget;
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

    /**
     * Merge own and shared category trees intelligently.
     * - Shared categories take priority over own categories with the same name+type
     * - Children are merged: shared children replace own children with same name,
     *   own-only children are kept alongside shared children
     * - Unmatched shared parents are appended
     *
     * @param {Array} tree - Full tree containing both own and shared categories
     * @returns {Array} Merged tree without duplicates
     */
    mergeCategoryTree(tree) {
        const own = tree.filter(cat => !cat._shared);
        const shared = tree.filter(cat => cat._shared);

        if (shared.length === 0) return own;
        if (own.length === 0) return shared;

        const merged = [];
        const usedSharedIds = new Set();

        for (const ownCat of own) {
            // Find matching shared category by name + type
            const match = shared.find(s =>
                s.name === ownCat.name && s.type === ownCat.type && !usedSharedIds.has(s.id)
            );

            if (match) {
                usedSharedIds.add(match.id);
                // Use the shared version (has budget amounts etc) but merge children
                const mergedCat = { ...match };
                mergedCat.children = this.mergeChildren(
                    ownCat.children || [],
                    match.children || []
                );
                merged.push(mergedCat);
            } else {
                // No shared match — keep own category as-is
                merged.push(ownCat);
            }
        }

        // Append any shared categories that didn't match an own category
        for (const sharedCat of shared) {
            if (!usedSharedIds.has(sharedCat.id)) {
                merged.push(sharedCat);
            }
        }

        return merged;
    }

    /**
     * Merge children arrays: shared children replace own children with same name,
     * own-only children are kept.
     */
    mergeChildren(ownChildren, sharedChildren) {
        if (sharedChildren.length === 0) return ownChildren;
        if (ownChildren.length === 0) return sharedChildren;

        const merged = [];
        const usedSharedIds = new Set();

        for (const ownChild of ownChildren) {
            const match = sharedChildren.find(s =>
                s.name === ownChild.name && !usedSharedIds.has(s.id)
            );

            if (match) {
                usedSharedIds.add(match.id);
                // Use shared version, recursively merge grandchildren
                const mergedChild = { ...match };
                mergedChild.children = this.mergeChildren(
                    ownChild.children || [],
                    match.children || []
                );
                merged.push(mergedChild);
            } else {
                // Own child not shared — keep it
                merged.push(ownChild);
            }
        }

        // Append shared children that didn't match any own child
        for (const sharedChild of sharedChildren) {
            if (!usedSharedIds.has(sharedChild.id)) {
                merged.push(sharedChild);
            }
        }

        return merged;
    }
}
