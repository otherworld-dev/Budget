/**
 * Tag Sets Module - Category tag management and transaction tagging
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';

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
                    OC.Notification.showTemporary('Tag set created successfully');
                } catch (error) {
                    console.error('Failed to create tag set:', error);
                    OC.Notification.showTemporary('Failed to create tag set');
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
                    OC.Notification.showTemporary('Tag set deleted');
                } catch (error) {
                    console.error('Failed to delete tag set:', error);
                    OC.Notification.showTemporary('Failed to delete tag set');
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
                    OC.Notification.showTemporary('Tag created successfully');
                } catch (error) {
                    console.error('Failed to create tag:', error);
                    OC.Notification.showTemporary('Failed to create tag');
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
                    OC.Notification.showTemporary('Tag deleted');
                } catch (error) {
                    console.error('Failed to delete tag:', error);
                    OC.Notification.showTemporary('Failed to delete tag');
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

            if (tagSets.length === 0) {
                container.innerHTML = '<div class="empty-state"><p style="font-size: 13px; color: var(--color-text-maxcontrast); margin: 8px 0;">No tag sets yet.</p></div>';
            } else {
                tagSets.forEach(tagSet => {
                    const tagSetCard = document.createElement('div');
                    tagSetCard.className = 'tag-set-card';
                    tagSetCard.innerHTML = `
                        <div class="tag-set-header">
                            <div class="tag-set-info">
                                <h4 class="tag-set-name">${dom.escapeHtml(tagSet.name)}</h4>
                                ${tagSet.description ? `<p class="tag-set-description">${dom.escapeHtml(tagSet.description)}</p>` : ''}
                            </div>
                            <div class="tag-set-actions">
                                <button class="action-btn add-tag-btn" data-tag-set-id="${tagSet.id}" title="Add Tag">
                                    <span class="icon-add" aria-hidden="true"></span>
                                </button>
                                <button class="action-btn edit-tag-set-btn" data-tag-set-id="${tagSet.id}" title="Edit Tag Set">
                                    <span class="icon-rename" aria-hidden="true"></span>
                                </button>
                                <button class="action-btn delete-tag-set-btn" data-tag-set-id="${tagSet.id}" title="Delete Tag Set">
                                    <span class="icon-delete" aria-hidden="true"></span>
                                </button>
                            </div>
                        </div>
                        <div class="tags-container">
                            ${tagSet.tags && tagSet.tags.length > 0 ? tagSet.tags.map(tag => `
                                <span class="tag-badge" style="background-color: ${tag.color || '#666'}; color: white;">
                                    ${dom.escapeHtml(tag.name)}
                                    <button class="delete-tag-btn" data-tag-id="${tag.id}" data-tag-set-id="${tagSet.id}" title="Delete tag">×</button>
                                </span>
                            `).join('') : '<span class="no-tags-text">No tags yet - click "+" to add tags</span>'}
                        </div>
                    `;
                    container.appendChild(tagSetCard);
                });
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
        // Add Tag Set button
        const addTagSetBtn = document.getElementById('add-tag-set-btn');
        if (addTagSetBtn) {
            addTagSetBtn.addEventListener('click', () => {
                this.showAddTagSetModal(categoryId);
            });
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
        if (!modal) return;

        document.getElementById('tag-set-category-id').value = categoryId;
        document.getElementById('tag-set-name').value = '';
        document.getElementById('tag-set-description').value = '';

        modal.style.display = 'flex';

        // Setup form submission
        const form = document.getElementById('add-tag-set-form');
        if (form) {
            form.addEventListener('submit', (e) => this.saveTagSet(e));
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
        if (!modal) return;

        document.getElementById('tag-set-id').value = tagSetId;
        document.getElementById('tag-category-id').value = categoryId;
        document.getElementById('tag-name').value = '';
        document.getElementById('tag-color').value = '#666666';

        modal.style.display = 'flex';
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
        if (this.app.hideModals) {
            return this.app.hideModals();
        }
        // Fallback implementation
        document.querySelectorAll('.budget-modal-overlay').forEach(modal => {
            modal.style.display = 'none';
        });
    }

    showNotification(message, type = 'info') {
        if (this.app.showNotification) {
            return this.app.showNotification(message, type);
        }
        // Fallback to OC notification
        OC.Notification.showTemporary(message);
    }
}
