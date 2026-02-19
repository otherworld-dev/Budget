/**
 * Categories Module - Category management, budgets, and tree visualization
 */
import * as formatters from '../../utils/formatters.js';
import { showSuccess, showError, showWarning } from '../../utils/notifications.js';

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
            const response = await fetch(OC.generateUrl('/apps/budget/api/categories/tree'), {
                headers: {
                    'requesttoken': OC.requestToken
                }
            });
            if (response.ok) {
                this.categoryTree = await response.json();
                this.allCategories = this.flattenCategories(this.categoryTree);
                this.renderCategoriesTree();
                this.setupCategoriesEventListeners();
            }
        } catch (error) {
            console.error('Failed to load categories:', error);
            showError('Failed to load categories');
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
                showSuccess('Category reordered successfully');
            } else {
                throw new Error('Failed to reorder category');
            }

        } catch (error) {
            console.error('Failed to reorder category:', error);
            showError('Failed to reorder category');
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

        // Load and display tag sets
        await this.app.renderCategoryTagSetsList(category.id);

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
                    <div class="transaction-date">${this.formatDate(transaction.date)}</div>
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
                showSuccess('Category deleted successfully');
                this.selectedCategory = null;
                await this.loadCategories();
                await this.app.loadInitialData();
                this.showCategoryDetailsEmpty();
            } else {
                const error = await response.json();
                throw new Error(error.error || 'Failed to delete category');
            }
        } catch (error) {
            console.error('Failed to delete category:', error);
            showError(error.message || 'Failed to delete category');
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
                showSuccess('Category deleted successfully');
                if (this.selectedCategory?.id === categoryId) {
                    this.selectedCategory = null;
                    this.showCategoryDetailsEmpty();
                }
                this.selectedCategoryIds.delete(categoryId);
                await this.loadCategories();
                await this.app.loadInitialData();
            } else {
                const error = await response.json();
                throw new Error(error.error || 'Failed to delete category');
            }
        } catch (error) {
            console.error('Failed to delete category:', error);
            showError(error.message || 'Failed to delete category');
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
            showSuccess(`${deleted} categor${deleted === 1 ? 'y' : 'ies'} deleted successfully`);
            this.selectedCategory = null;
            this.showCategoryDetailsEmpty();
            await this.loadCategories();
            await this.app.loadInitialData();
        }

        if (errors.length > 0) {
            showError(`Failed to delete: ${errors.join(', ')}`);
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
            showWarning('Category name is required');
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
                showSuccess(isEdit ? 'Category updated successfully' : 'Category created successfully');
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
                throw new Error(error.error || 'Failed to save category');
            }
        } catch (error) {
            console.error('Failed to save category:', error);
            showError(error.message || 'Failed to save category');
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
                showSuccess('Default categories created successfully');
                await this.loadCategories();
                await this.app.loadInitialData();
            } else {
                const error = await response.json();
                throw new Error(error.error || 'Failed to create default categories');
            }
        } catch (error) {
            console.error('Failed to create default categories:', error);
            showError(error.message || 'Failed to create default categories');
        }
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
                this.app.navigateTo('categories');
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
        // Initialize spending object
        this.categorySpending = {};

        // Get all categories that have budgets
        const allCategories = this.flattenCategories(this.categoryTree || []);
        const categoriesWithBudgets = allCategories.filter(cat => parseFloat(cat.budgetAmount) > 0);

        if (categoriesWithBudgets.length === 0) {
            return;
        }

        // Group categories by their period to minimize API calls
        const categoriesByPeriod = {
            weekly: [],
            monthly: [],
            quarterly: [],
            yearly: []
        };

        categoriesWithBudgets.forEach(cat => {
            const period = cat.budgetPeriod || 'monthly';
            if (categoriesByPeriod[period]) {
                categoriesByPeriod[period].push(cat.id);
            }
        });

        // Fetch spending for each period
        try {
            for (const [period, categoryIds] of Object.entries(categoriesByPeriod)) {
                if (categoryIds.length === 0) continue;

                // Get date range for this period
                const dateRange = formatters.getPeriodDateRange(period);

                // Fetch spending for this period
                const response = await fetch(
                    OC.generateUrl(`/apps/budget/api/categories/spending?startDate=${dateRange.start}&endDate=${dateRange.end}`),
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

            // Get category's budget period (defaults to monthly if not set)
            const categoryPeriod = category.budgetPeriod || 'monthly';

            // Get spending for this category (already calculated for the period)
            const spent = this.categorySpending[category.id] || 0;

            // Get the stored budget amount
            const storedBudget = parseFloat(category.budgetAmount) || 0;

            // Budget is already in the correct period since we store it by period
            // No pro-rating needed - the budget amount is for the selected period
            const budget = storedBudget;

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
                               value="${budget ? Math.round(budget * 100) / 100 : ''}"
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
            select.addEventListener('change', async (e) => {
                const categoryId = parseInt(e.target.dataset.categoryId);
                const newPeriod = e.target.value;
                const oldPeriod = e.target.dataset.oldPeriod || e.target.querySelector('option[selected]')?.value || 'monthly';

                // Find the category to get current budget amount
                const category = this.findCategoryById(categoryId);
                if (!category) return;

                const currentBudget = parseFloat(category.budgetAmount) || 0;
                const currentPeriod = category.budgetPeriod || 'monthly';

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
            const dateRange = formatters.getPeriodDateRange(period);

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

                // Refresh dashboard if currently viewing it
                if (window.location.hash === '' || window.location.hash === '#/dashboard') {
                    await this.app.loadDashboard();
                }

                showSuccess('Budget updated');
            } else {
                // Try to get detailed error message
                let errorMessage = 'Failed to update budget';
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
            showError(`Failed to update budget: ${error.message}`);
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
            const period = cat.budgetPeriod || 'monthly';
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
}
