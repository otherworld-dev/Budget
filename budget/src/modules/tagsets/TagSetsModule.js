/**
 * Tag Sets Module - Category tag management and transaction tagging
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError, showInfo } from '../../utils/notifications.js';

export default class TagSetsModule {
    constructor(app) {
        this.app = app;
    }

    // Getters for app state
    get tagSets() { return this.app.tagSets; }
    set tagSets(value) { this.app.tagSets = value; }
    get selectedCategoryTagSets() { return this.app.selectedCategoryTagSets; }
    set selectedCategoryTagSets(value) { this.app.selectedCategoryTagSets = value; }
    get transactionTags() { return this.app.transactionTags; }
    set transactionTags(value) { this.app.transactionTags = value; }
    get allTagSetsForReports() { return this.app.allTagSetsForReports; }
    set allTagSetsForReports(value) { this.app.allTagSetsForReports = value; }
    get settings() { return this.app.settings; }
    get categories() { return this.app.categories; }
    get transactions() { return this.app.transactions; }

    /**
     * Load tag sets for a specific category
     */
    async loadTagSetsForCategory(categoryId) {
        try {
            const response = await fetch(
                OC.generateUrl(`/apps/budget/api/tag-sets?categoryId=${categoryId}`),
                {
                    headers: { 'requesttoken': OC.requestToken }
                }
            );

            if (response.ok) {
                this.selectedCategoryTagSets = await response.json();
                return this.selectedCategoryTagSets;
            }
        } catch (error) {
            console.error('Failed to load tag sets:', error);
        }
        return [];
    }

    /**
     * Load tags for a transaction
     */
    async loadTransactionTags(transactionId) {
        try {
            const response = await fetch(
                OC.generateUrl(`/apps/budget/api/transactions/${transactionId}/tags`),
                {
                    headers: { 'requesttoken': OC.requestToken }
                }
            );

            if (response.ok) {
                const tags = await response.json();
                this.transactionTags[transactionId] = tags;
                return tags;
            }
        } catch (error) {
            console.error('Failed to load transaction tags:', error);
        }
        return [];
    }

    /**
     * Save tags for a transaction
     */
    async saveTransactionTags(transactionId, tagIds) {
        try {
            const response = await fetch(
                OC.generateUrl(`/apps/budget/api/transactions/${transactionId}/tags`),
                {
                    method: 'PUT',
                    headers: {
                        'requesttoken': OC.requestToken,
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ tagIds })
                }
            );

            if (response.ok) {
                // Update cache
                await this.loadTransactionTags(transactionId);
                return true;
            }
        } catch (error) {
            console.error('Failed to save transaction tags:', error);
        }
        return false;
    }

    /**
     * Render tag chips for display in transaction list
     */
    renderTagChips(tags) {
        if (!tags || tags.length === 0) {
            return '';
        }

        return tags.map(tag => `
            <span class="tag-chip" style="background-color: ${tag.color || '#666'}; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-right: 4px;">
                ${this.escapeHtml(tag.name)}
            </span>
        `).join('');
    }

    /**
     * Render tag set management UI in category modal
     */
    async renderCategoryTagSetsUI(categoryId) {
        const container = document.getElementById('category-tag-sets-container');
        if (!container) return;

        if (!categoryId) {
            container.innerHTML = '<p style="color: #999; font-style: italic;">Save category first to manage tag sets</p>';
            return;
        }

        // Load tag sets for this category
        const tagSets = await this.loadTagSetsForCategory(categoryId);

        let html = `
            <div class="tag-sets-header">
                <h4 style="margin: 0;">Tag Sets</h4>
                <button type="button" class="add-tag-set-btn" data-category-id="${categoryId}">
                    <span class="icon-add" aria-hidden="true"></span> Add Tag Set
                </button>
            </div>
        `;

        if (tagSets.length === 0) {
            html += '<p style="color: #999; font-style: italic;">No tag sets yet. Add your first tag set to enable multi-dimensional categorization.</p>';
        }

        // Render each tag set
        if (tagSets.length > 0) {
            tagSets.forEach(tagSet => {
                html += `
                    <div class="tag-set-card">
                        <div class="tag-set-header">
                            <h5>${dom.escapeHtml(tagSet.name)}</h5>
                            <div class="tag-set-actions">
                                <button type="button" class="add-tag-btn" data-tag-set-id="${tagSet.id}" title="Add tag">
                                    <span class="icon-add" aria-hidden="true"></span>
                                </button>
                                <button type="button" class="delete-tag-set-btn" data-tag-set-id="${tagSet.id}" title="Delete tag set">
                                    <span class="icon-delete" aria-hidden="true"></span>
                                </button>
                            </div>
                        </div>
                        ${tagSet.description ? `<p class="tag-set-description">${dom.escapeHtml(tagSet.description)}</p>` : ''}
                        <div class="tags-list">
                            ${tagSet.tags && tagSet.tags.length > 0 ? tagSet.tags.map(tag => `
                                <span class="tag-badge" style="background-color: ${tag.color || '#666'}">
                                    ${dom.escapeHtml(tag.name)}
                                    <button type="button" class="delete-tag-btn" data-tag-id="${tag.id}" data-tag-set-id="${tagSet.id}">×</button>
                                </span>
                            `).join('') : '<span style="color: #999; font-size: 12px;">No tags yet</span>'}
                        </div>
                    </div>
                `;
            });
        }

        container.innerHTML = html;

        // Setup event listeners
        this.setupCategoryTagSetsModalListeners(categoryId);
    }

    /**
     * Setup event listeners for tag set management in category modal
     */
    setupCategoryTagSetsModalListeners(categoryId) {
        // Add tag set button
        document.querySelectorAll('.add-tag-set-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const name = prompt('Enter tag set name (e.g., "Priority", "Status"):');
                if (!name) return;

                const description = prompt('Enter description (optional):');

                try {
                    await this.createTagSet(categoryId, name, description);
                    await this.renderCategoryTagSetsUI(categoryId);
                    showSuccess('Tag set created successfully');
                } catch (error) {
                    console.error('Failed to create tag set:', error);
                    showError('Failed to create tag set');
                }
            });
        });

        // Delete tag set buttons
        document.querySelectorAll('.delete-tag-set-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tagSetId = parseInt(btn.dataset.tagSetId);
                if (!confirm('Delete this tag set? All associated tags will be removed from transactions.')) return;

                try {
                    await this.deleteTagSet(tagSetId);
                    await this.renderCategoryTagSetsUI(categoryId);
                    showSuccess('Tag set deleted');
                } catch (error) {
                    console.error('Failed to delete tag set:', error);
                    showError('Failed to delete tag set');
                }
            });
        });

        // Add tag buttons
        document.querySelectorAll('.add-tag-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tagSetId = parseInt(btn.dataset.tagSetId);
                const name = prompt('Enter tag name:');
                if (!name) return;

                const color = prompt('Enter color (e.g., #FF5733):') || '#666666';

                try {
                    await this.createTag(tagSetId, name, color);
                    await this.renderCategoryTagSetsUI(categoryId);
                    showSuccess('Tag created successfully');
                } catch (error) {
                    console.error('Failed to create tag:', error);
                    showError('Failed to create tag');
                }
            });
        });

        // Delete tag buttons
        document.querySelectorAll('.delete-tag-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tagId = parseInt(btn.dataset.tagId);
                const tagSetId = parseInt(btn.dataset.tagSetId);

                if (!confirm('Delete this tag? It will be removed from all transactions.')) return;

                try {
                    await this.deleteTag(tagId, tagSetId);
                    await this.renderCategoryTagSetsUI(categoryId);
                    showSuccess('Tag deleted');
                } catch (error) {
                    console.error('Failed to delete tag:', error);
                    showError('Failed to delete tag');
                }
            });
        });
    }

    /**
     * Create a new tag set
     */
    async createTagSet(categoryId, name, description) {
        const response = await fetch(OC.generateUrl('/apps/budget/api/tag-sets'), {
            method: 'POST',
            headers: {
                'requesttoken': OC.requestToken,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                categoryId: categoryId,
                name: name,
                description: description || null
            })
        });

        if (!response.ok) {
            throw new Error('Failed to create tag set');
        }

        return await response.json();
    }

    /**
     * Delete a tag set
     */
    async deleteTagSet(tagSetId) {
        const response = await fetch(OC.generateUrl(`/apps/budget/api/tag-sets/${tagSetId}`), {
            method: 'DELETE',
            headers: {
                'requesttoken': OC.requestToken
            }
        });

        if (!response.ok) {
            throw new Error('Failed to delete tag set');
        }

        return true;
    }

    /**
     * Create a new tag
     */
    async createTag(tagSetId, name, color) {
        const response = await fetch(OC.generateUrl(`/apps/budget/api/tag-sets/${tagSetId}/tags`), {
            method: 'POST',
            headers: {
                'requesttoken': OC.requestToken,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                name: name,
                color: color || '#666666'
            })
        });

        if (!response.ok) {
            throw new Error('Failed to create tag');
        }

        return await response.json();
    }

    /**
     * Delete a tag
     */
    async deleteTag(tagId, tagSetId) {
        const response = await fetch(OC.generateUrl(`/apps/budget/api/tag-sets/${tagSetId}/tags/${tagId}`), {
            method: 'DELETE',
            headers: {
                'requesttoken': OC.requestToken
            }
        });

        if (!response.ok) {
            throw new Error('Failed to delete tag');
        }

        return true;
    }

    /**
     * Render transaction tag selectors
     */
    async renderTransactionTagSelectors(categoryId, transactionId) {
        const container = document.getElementById('transaction-tags-container');
        if (!container) return;

        if (!categoryId) {
            container.innerHTML = '<p style="color: #999; font-size: 12px;">No tag sets available for this category</p>';
            return;
        }

        // Load tag sets for this category
        const tagSets = await this.loadTagSetsForCategory(categoryId);

        if (tagSets.length === 0) {
            container.innerHTML = '<p style="color: #999; font-size: 12px;">No tag sets available for this category</p>';
            return;
        }

        // Load current tags for this transaction
        const currentTags = await this.loadTransactionTags(transactionId);
        const currentTagIds = currentTags.map(t => t.id);

        let html = '';
        tagSets.forEach(tagSet => {
            html += `
                <div class="tag-set-selector">
                    <label class="tag-set-label">${dom.escapeHtml(tagSet.name)}</label>
                    <div class="tag-options">
                        ${tagSet.tags && tagSet.tags.length > 0 ? tagSet.tags.map(tag => `
                            <label class="tag-option">
                                <input type="checkbox"
                                       value="${tag.id}"
                                       data-transaction-id="${transactionId}"
                                       ${currentTagIds.includes(tag.id) ? 'checked' : ''}>
                                <span class="tag-badge" style="background-color: ${tag.color || '#666'}">
                                    ${dom.escapeHtml(tag.name)}
                                </span>
                            </label>
                        `).join('') : '<span style="color: #999; font-size: 11px;">No tags defined</span>'}
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;

        // Add change listeners to save tags
        container.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', async () => {
                const selectedTags = Array.from(container.querySelectorAll('input[type="checkbox"]:checked'))
                    .map(cb => parseInt(cb.value));

                await this.saveTransactionTags(transactionId, selectedTags);
            });
        });
    }

    /**
     * Load and display transaction tags in the transaction modal
     */
    async loadAndDisplayTransactionTags() {
        const transactionId = document.getElementById('transaction-id').value;
        const categoryId = document.getElementById('transaction-category').value;

        if (transactionId && categoryId) {
            await this.renderTransactionTagSelectors(parseInt(categoryId), parseInt(transactionId));
        }
    }

    /**
     * Render tag sets in the category details view
     */
    async renderCategoryTagSetsList(categoryId) {
        const container = document.getElementById('category-tag-sets-list');
        if (!container) return;

        try {
            if (!categoryId) {
                container.innerHTML = '<div class="empty-state"><p>Select a category to manage tag sets</p></div>';
                return;
            }

            const tagSets = await this.loadTagSetsForCategory(categoryId);

            // Clear the container first to avoid duplicates
            container.innerHTML = '';

            if (tagSets.length === 0) {
                container.innerHTML = '<div class="empty-state"><p style="font-size: 13px; color: var(--color-text-maxcontrast); margin: 8px 0;">No tag sets yet.</p></div>';
            } else {
                // Create table for list layout
                const table = document.createElement('table');
                table.className = 'tag-sets-list-table';
                table.style.width = '100%';
                table.style.borderCollapse = 'collapse';

                const tbody = document.createElement('tbody');

                tagSets.forEach(tagSet => {
                    const row = document.createElement('tr');
                    row.className = 'tag-set-row';
                    row.style.borderBottom = '1px solid var(--color-border)';

                    row.innerHTML = `
                        <td class="tag-set-name-cell" style="padding: 12px 8px; vertical-align: top; width: 25%;">
                            <div class="tag-set-name" style="font-weight: 600; margin-bottom: 4px;">
                                ${dom.escapeHtml(tagSet.name)}
                            </div>
                            ${tagSet.description ? `
                                <div class="tag-set-description" style="font-size: 12px; color: var(--color-text-maxcontrast);">
                                    ${dom.escapeHtml(tagSet.description)}
                                </div>
                            ` : ''}
                        </td>
                        <td class="tag-set-tags-cell" style="padding: 12px 8px; vertical-align: top;">
                            <div class="tags-container" style="display: flex; flex-wrap: wrap; gap: 6px;">
                                ${tagSet.tags && tagSet.tags.length > 0 ? tagSet.tags.map(tag => `
                                    <span class="tag-badge" style="background-color: ${tag.color || '#666'}; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px;">
                                        ${dom.escapeHtml(tag.name)}
                                        <button class="delete-tag-btn" data-tag-id="${tag.id}" data-tag-set-id="${tagSet.id}" title="Delete tag" style="background: none; border: none; color: white; cursor: pointer; padding: 0; margin-left: 2px; font-size: 16px; line-height: 1; opacity: 0.7;">×</button>
                                    </span>
                                `).join('') : '<span class="no-tags-text" style="color: var(--color-text-maxcontrast); font-size: 12px; font-style: italic;">No tags yet</span>'}
                            </div>
                        </td>
                        <td class="tag-set-actions-cell" style="padding: 12px 8px; vertical-align: top; width: 120px; text-align: right;">
                            <div class="tag-set-actions" style="display: flex; gap: 4px; justify-content: flex-end;">
                                <button class="action-btn add-tag-btn" data-tag-set-id="${tagSet.id}" title="Add Tag" style="padding: 6px 8px;">
                                    <span class="icon-add" aria-hidden="true"></span>
                                </button>
                                <button class="action-btn edit-tag-set-btn" data-tag-set-id="${tagSet.id}" title="Edit Tag Set" style="padding: 6px 8px;">
                                    <span class="icon-rename" aria-hidden="true"></span>
                                </button>
                                <button class="action-btn delete-tag-set-btn" data-tag-set-id="${tagSet.id}" title="Delete Tag Set" style="padding: 6px 8px;">
                                    <span class="icon-delete" aria-hidden="true"></span>
                                </button>
                            </div>
                        </td>
                    `;

                    tbody.appendChild(row);
                });

                table.appendChild(tbody);
                container.appendChild(table);
            }

            // Always setup listeners, even when there are no tag sets (for the Add button)
            this.setupCategoryTagSetsListeners(categoryId);
        } catch (error) {
            console.error('Failed to load tag sets:', error);
            container.innerHTML = '<div class="error-state"><p>Failed to load tag sets</p></div>';
        }
    }

    /**
     * Setup event listeners for category tag sets list
     */
    setupCategoryTagSetsListeners(categoryId) {
        // Add Tag Set button (check both IDs for compatibility)
        const addTagSetBtn = document.getElementById('add-tag-set-btn-detail') || document.getElementById('add-tag-set-btn');
        if (addTagSetBtn) {
            // Remove old listener if exists
            addTagSetBtn.replaceWith(addTagSetBtn.cloneNode(true));
            const newBtn = document.getElementById('add-tag-set-btn-detail') || document.getElementById('add-tag-set-btn');
            if (newBtn) {
                newBtn.addEventListener('click', () => {
                    this.showAddTagSetModal(categoryId);
                });
            }
        }

        // Add Tag buttons
        document.querySelectorAll('.add-tag-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const tagSetId = parseInt(btn.dataset.tagSetId);
                this.showAddTagModal(tagSetId, categoryId);
            });
        });

        // Delete Tag Set buttons
        document.querySelectorAll('.delete-tag-set-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tagSetId = parseInt(btn.dataset.tagSetId);
                if (confirm('Delete this tag set? All tags in this set will be removed.')) {
                    try {
                        await this.deleteTagSet(tagSetId);
                        await this.renderCategoryTagSetsList(categoryId);
                        this.showNotification('Tag set deleted', 'success');
                    } catch (error) {
                        console.error('Failed to delete tag set:', error);
                        this.showNotification('Failed to delete tag set', 'error');
                    }
                }
            });
        });

        // Delete Tag buttons
        document.querySelectorAll('.delete-tag-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();

                const tagId = parseInt(btn.dataset.tagId);
                const tagSetId = parseInt(btn.dataset.tagSetId);

                if (confirm('Delete this tag? It will be removed from all transactions.')) {
                    try {
                        await this.deleteTag(tagId, tagSetId);
                        await this.renderCategoryTagSetsList(categoryId);
                        this.showNotification('Tag deleted', 'success');
                    } catch (error) {
                        console.error('Failed to delete tag:', error);
                        this.showNotification('Failed to delete tag', 'error');
                    }
                }
            });
        });
    }

    /**
     * Save a tag set from the modal form
     */
    async saveTagSet(e) {
        e.preventDefault();

        const categoryId = document.getElementById('tag-set-category-id').value;
        const name = document.getElementById('tag-set-name').value;
        const description = document.getElementById('tag-set-description').value;

        try {
            await this.createTagSet(parseInt(categoryId), name, description);
            this.hideModals();
            await this.renderCategoryTagSetsList(parseInt(categoryId));
            this.showNotification('Tag set created successfully', 'success');
        } catch (error) {
            console.error('Failed to create tag set:', error);
            this.showNotification('Failed to create tag set', 'error');
        }
    }

    /**
     * Show modal for adding tag set
     */
    showAddTagSetModal(categoryId) {
        const modal = document.getElementById('add-tag-set-modal');
        if (!modal) {
            console.error('Add tag set modal not found');
            return;
        }

        const categoryIdInput = document.getElementById('tag-set-category-id');
        const nameInput = document.getElementById('tag-set-name');
        const descInput = document.getElementById('tag-set-description');

        if (!categoryIdInput || !nameInput) {
            console.error('Tag set modal form inputs not found');
            return;
        }

        categoryIdInput.value = categoryId;
        nameInput.value = '';
        if (descInput) {
            descInput.value = '';
        }

        modal.style.display = 'flex';

        // Setup form submission (remove old listener first)
        const form = document.getElementById('add-tag-set-form');
        if (form) {
            const newForm = form.cloneNode(true);
            form.parentNode.replaceChild(newForm, form);
            newForm.addEventListener('submit', (e) => this.saveTagSet(e));
        }
    }

    /**
     * Load all transaction tags for filtering
     */
    async loadAllTransactionTags() {
        if (!this.transactions || this.transactions.length === 0) {
            this.transactionTags = {};
            return;
        }

        try {
            // Load tags for each transaction
            const tagPromises = this.transactions.map(async (transaction) => {
                const response = await fetch(OC.generateUrl(`/apps/budget/api/transactions/${transaction.id}/tags`), {
                    headers: {
                        'requesttoken': OC.requestToken
                    }
                });

                if (response.ok) {
                    const tags = await response.json();
                    return { transactionId: transaction.id, tags: Array.isArray(tags) ? tags : [] };
                }
                return { transactionId: transaction.id, tags: [] };
            });

            const results = await Promise.all(tagPromises);

            // Store tags by transaction ID
            this.transactionTags = {};
            results.forEach(result => {
                this.transactionTags[result.transactionId] = result.tags;
            });
        } catch (error) {
            console.error('Failed to load transaction tags:', error);
            this.transactionTags = {};
        }
    }

    /**
     * Show modal for adding a tag
     */
    showAddTagModal(tagSetId, categoryId) {
        const modal = document.getElementById('add-tag-modal');
        if (!modal) {
            console.error('Add tag modal not found');
            return;
        }

        const tagSetIdInput = document.getElementById('tag-set-id');
        const categoryIdInput = document.getElementById('tag-category-id');
        const nameInput = document.getElementById('tag-name');
        const colorInput = document.getElementById('tag-color');

        if (!tagSetIdInput || !categoryIdInput || !nameInput || !colorInput) {
            console.error('Tag modal form inputs not found');
            return;
        }

        tagSetIdInput.value = tagSetId;
        categoryIdInput.value = categoryId;
        nameInput.value = '';
        colorInput.value = '#666666';

        modal.style.display = 'flex';

        // Setup form submission (remove old listener first)
        const form = document.getElementById('add-tag-form');
        if (form) {
            const newForm = form.cloneNode(true);
            form.parentNode.replaceChild(newForm, form);
            newForm.addEventListener('submit', (e) => this.saveTag(e));
        }
    }

    /**
     * Save a tag from the modal form
     */
    async saveTag(e) {
        e.preventDefault();

        const tagSetId = parseInt(document.getElementById('tag-set-id').value);
        const categoryId = parseInt(document.getElementById('tag-category-id').value);
        const name = document.getElementById('tag-name').value;
        const color = document.getElementById('tag-color').value;

        try {
            await this.createTag(tagSetId, name, color);
            this.hideModals();
            await this.renderCategoryTagSetsList(categoryId);
            this.showNotification('Tag created successfully', 'success');
        } catch (error) {
            console.error('Failed to create tag:', error);
            this.showNotification('Failed to create tag', 'error');
        }
    }

    /**
     * Setup event listeners for add tag modal
     */
    setupAddTagModalListeners() {
        const form = document.getElementById('add-tag-form');
        if (form) {
            form.addEventListener('submit', (e) => this.saveTag(e));
        }

        // Cancel buttons
        document.querySelectorAll('.cancel-tag-btn').forEach(btn => {
            btn.addEventListener('click', () => this.hideModals());
        });

        // Close on background click
        const modal = document.getElementById('add-tag-modal');
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.hideModals();
                }
            });
        }
    }

    // Delegate helper methods to app
    escapeHtml(text) {
        return dom.escapeHtml(text);
    }

    hideModals() {
        // Hide all modals
        const modals = [
            'add-tag-set-modal',
            'add-tag-modal',
            'edit-tag-set-modal'
        ];

        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
        });

        // Also try app's hideModals if it exists
        if (this.app.hideModals) {
            this.app.hideModals();
        }
    }

    showNotification(message, type = 'info') {
        if (this.app.showNotification) {
            return this.app.showNotification(message, type);
        }
        // Fallback to notifications utility
        showInfo(message);
    }
}
