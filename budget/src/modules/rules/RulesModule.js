/**
 * Rules Module - Transaction auto-categorization rules
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';

export default class RulesModule {
    constructor(app) {
        this.app = app;
    }

    // Getters for app state
    get rules() {
        return this.app.rules;
    }

    set rules(value) {
        this.app.rules = value;
    }

    get categories() {
        return this.app.categories;
    }

    get accounts() {
        return this.app.accounts;
    }

    get currentView() {
        return this.app.currentView;
    }

    get settings() {
        return this.app.settings;
    }

    // Helper method delegations
    formatCurrency(amount, currency = null) {
        return formatters.formatCurrency(amount, currency, this.settings);
    }

    formatDate(dateStr) {
        return formatters.formatDate(dateStr, this.settings);
    }

    escapeHtml(text) {
        return dom.escapeHtml(text);
    }

    hideModals() {
        return this.app.hideModals();
    }

    async loadTransactions() {
        return this.app.loadTransactions();
    }

    async loadRulesView() {
        // Always setup event listeners first, even if data load fails
        this.setupRulesEventListeners();

        try {
            await this.loadRules();
        } catch (error) {
            console.error('Failed to load rules view:', error);
            OC.Notification.showTemporary('Failed to load rules');
        }
    }

    async loadRules() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import-rules'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            this.rules = await response.json();
            this.renderRules(this.rules);
            this.updateRulesSummary();
        } catch (error) {
            console.error('Failed to load rules:', error);
            throw error;
        }
    }

    renderRules(rules) {
        const rulesList = document.getElementById('rules-list');
        const emptyRules = document.getElementById('empty-rules');

        if (!rulesList) return;

        if (!rules || rules.length === 0) {
            rulesList.innerHTML = '';
            if (emptyRules) emptyRules.style.display = 'flex';
            return;
        }

        if (emptyRules) emptyRules.style.display = 'none';

        rulesList.innerHTML = rules.map(rule => {
            const actions = rule.actions || {};
            const actionBadges = this.getRuleActionBadges(rule, actions);
            const matchTypeLabels = {
                'contains': 'contains',
                'exact': 'equals',
                'starts_with': 'starts with',
                'ends_with': 'ends with',
                'regex': 'matches'
            };

            const criteriaText = `${rule.field} ${matchTypeLabels[rule.matchType] || rule.matchType} "${this.escapeHtml(rule.pattern)}"`;

            return `
                <tr class="rule-row ${rule.active ? '' : 'inactive'}" data-rule-id="${rule.id}">
                    <td class="rules-col-priority">${rule.priority}</td>
                    <td class="rules-col-name">${this.escapeHtml(rule.name)}</td>
                    <td class="rules-col-status">
                        <label class="rule-toggle" title="${rule.active ? 'Click to disable' : 'Click to enable'}">
                            <input type="checkbox" class="rule-active-toggle" data-rule-id="${rule.id}" ${rule.active ? 'checked' : ''}>
                            <span class="rule-toggle-slider"></span>
                        </label>
                        ${rule.applyOnImport ? '<span class="status-badge import">Import</span>' : ''}
                    </td>
                    <td class="rules-col-criteria"><code>${criteriaText}</code></td>
                    <td class="rules-col-actions">${actionBadges}</td>
                    <td class="rules-col-buttons">
                        <button class="icon-rename rule-edit-btn" data-rule-id="${rule.id}" title="Edit rule"></button>
                        <button class="icon-delete rule-delete-btn" data-rule-id="${rule.id}" title="Delete rule"></button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    getRuleActionBadges(rule, actions) {
        const badges = [];

        // Check for category action
        const categoryId = actions.categoryId || rule.categoryId;
        if (categoryId) {
            const category = this.categories?.find(c => c.id === categoryId);
            const categoryName = category?.name || `Category #${categoryId}`;
            badges.push(`<span class="action-badge category">→ ${this.escapeHtml(categoryName)}</span>`);
        }

        // Check for vendor action
        const vendor = actions.vendor || rule.vendorName;
        if (vendor) {
            badges.push(`<span class="action-badge vendor">Vendor: ${this.escapeHtml(vendor)}</span>`);
        }

        // Check for notes action
        if (actions.notes) {
            badges.push(`<span class="action-badge notes">Set notes</span>`);
        }

        return badges.length > 0 ? badges.join('') : '<span class="action-badge none">No actions</span>';
    }

    updateRulesSummary() {
        const totalCount = document.getElementById('rules-total-count');
        const activeCount = document.getElementById('rules-active-count');

        if (totalCount && this.rules) {
            totalCount.textContent = this.rules.length;
        }
        if (activeCount && this.rules) {
            activeCount.textContent = this.rules.filter(r => r.active).length;
        }
    }

    setupRulesEventListeners() {
        console.log('setupRulesEventListeners called');

        // Add Rule button in view header
        const addRuleBtn = document.getElementById('rules-add-btn');
        console.log('rules-add-btn found:', addRuleBtn);
        if (addRuleBtn && !addRuleBtn.dataset.listenerAttached) {
            addRuleBtn.addEventListener('click', () => {
                console.log('Add Rule button clicked');
                this.showRuleModal();
            });
            addRuleBtn.dataset.listenerAttached = 'true';
        }

        // Empty state add button
        const emptyAddBtn = document.getElementById('empty-rules-add-btn');
        if (emptyAddBtn && !emptyAddBtn.dataset.listenerAttached) {
            emptyAddBtn.addEventListener('click', () => this.showRuleModal());
            emptyAddBtn.dataset.listenerAttached = 'true';
        }

        // Apply Rules button
        const applyRulesBtn = document.getElementById('apply-rules-btn');
        if (applyRulesBtn && !applyRulesBtn.dataset.listenerAttached) {
            applyRulesBtn.addEventListener('click', () => this.showApplyRulesModal());
            applyRulesBtn.dataset.listenerAttached = 'true';
        }

        // Rule form submit
        const ruleForm = document.getElementById('rule-form');
        if (ruleForm && !ruleForm.dataset.listenerAttached) {
            ruleForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveRule();
            });
            ruleForm.dataset.listenerAttached = 'true';
        }

        // Preview button in apply modal
        const previewBtn = document.getElementById('preview-rules-btn');
        if (previewBtn && !previewBtn.dataset.listenerAttached) {
            previewBtn.addEventListener('click', () => this.previewRuleApplication());
            previewBtn.dataset.listenerAttached = 'true';
        }

        // Execute apply button
        const executeBtn = document.getElementById('execute-apply-rules-btn');
        if (executeBtn && !executeBtn.dataset.listenerAttached) {
            executeBtn.addEventListener('click', () => this.executeApplyRules());
            executeBtn.dataset.listenerAttached = 'true';
        }

        // Select/Deselect all rules
        const selectAllBtn = document.getElementById('select-all-rules');
        const deselectAllBtn = document.getElementById('deselect-all-rules');
        if (selectAllBtn && !selectAllBtn.dataset.listenerAttached) {
            selectAllBtn.addEventListener('click', () => this.toggleAllRuleSelections(true));
            selectAllBtn.dataset.listenerAttached = 'true';
        }
        if (deselectAllBtn && !deselectAllBtn.dataset.listenerAttached) {
            deselectAllBtn.addEventListener('click', () => this.toggleAllRuleSelections(false));
            deselectAllBtn.dataset.listenerAttached = 'true';
        }

        // Delegate click events for rule cards
        const rulesList = document.getElementById('rules-list');
        if (rulesList && !rulesList.dataset.listenerAttached) {
            rulesList.addEventListener('click', (e) => {
                const editBtn = e.target.closest('.rule-edit-btn');
                const deleteBtn = e.target.closest('.rule-delete-btn');

                if (editBtn) {
                    const ruleId = parseInt(editBtn.dataset.ruleId);
                    this.editRule(ruleId);
                } else if (deleteBtn) {
                    const ruleId = parseInt(deleteBtn.dataset.ruleId);
                    this.deleteRule(ruleId);
                }
            });

            // Toggle active state
            rulesList.addEventListener('change', (e) => {
                if (e.target.classList.contains('rule-active-toggle')) {
                    const ruleId = parseInt(e.target.dataset.ruleId);
                    const active = e.target.checked;
                    this.toggleRuleActive(ruleId, active);
                }
            });

            rulesList.dataset.listenerAttached = 'true';
        }
    }

    async showRuleModal(rule = null) {
        console.log('showRuleModal called', rule);
        const modal = document.getElementById('rule-modal');
        const title = document.getElementById('rule-modal-title');
        const form = document.getElementById('rule-form');

        console.log('modal:', modal, 'form:', form);
        if (!modal || !form) {
            console.error('Modal or form not found!');
            return;
        }

        form.reset();
        document.getElementById('rule-id').value = '';

        // Populate category dropdown
        await this.populateRuleCategoryDropdown();

        if (rule) {
            title.textContent = 'Edit Rule';
            document.getElementById('rule-id').value = rule.id;
            document.getElementById('rule-name').value = rule.name || '';
            document.getElementById('rule-field').value = rule.field || 'description';
            document.getElementById('rule-match-type').value = rule.matchType || 'contains';
            document.getElementById('rule-pattern').value = rule.pattern || '';
            document.getElementById('rule-priority').value = rule.priority || 0;
            document.getElementById('rule-active').checked = rule.active !== false;
            document.getElementById('rule-apply-on-import').checked = rule.applyOnImport !== false;

            // Parse actions
            const actions = rule.actions || {};
            document.getElementById('rule-action-category').value = actions.categoryId || rule.categoryId || '';
            document.getElementById('rule-action-vendor').value = actions.vendor || rule.vendorName || '';
            document.getElementById('rule-action-notes').value = actions.notes || '';
        } else {
            title.textContent = 'Add Rule';
        }

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');

        // Focus on name field
        const nameField = document.getElementById('rule-name');
        if (nameField) nameField.focus();
    }

    async populateRuleCategoryDropdown() {
        const select = document.getElementById('rule-action-category');
        if (!select) return;

        // Keep first option (-- Don't change --)
        const firstOption = select.options[0];
        select.innerHTML = '';
        select.appendChild(firstOption);

        // Add categories
        if (this.categories) {
            this.categories.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat.id;
                option.textContent = cat.name;
                select.appendChild(option);
            });
        }
    }

    async saveRule() {
        const ruleId = document.getElementById('rule-id').value;
        const isEdit = !!ruleId;

        // Collect form data
        const name = document.getElementById('rule-name').value.trim();
        const field = document.getElementById('rule-field').value;
        const matchType = document.getElementById('rule-match-type').value;
        const pattern = document.getElementById('rule-pattern').value.trim();
        const priority = parseInt(document.getElementById('rule-priority').value) || 0;
        const active = document.getElementById('rule-active').checked;
        const applyOnImport = document.getElementById('rule-apply-on-import').checked;

        // Collect actions
        const categoryId = document.getElementById('rule-action-category').value;
        const vendor = document.getElementById('rule-action-vendor').value.trim();
        const notes = document.getElementById('rule-action-notes').value.trim();

        const actions = {};
        if (categoryId) actions.categoryId = parseInt(categoryId);
        if (vendor) actions.vendor = vendor;
        if (notes) actions.notes = notes;

        try {
            const url = isEdit
                ? OC.generateUrl(`/apps/budget/api/import-rules/${ruleId}`)
                : OC.generateUrl('/apps/budget/api/import-rules');

            const response = await fetch(url, {
                method: isEdit ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    name,
                    pattern,
                    field,
                    matchType,
                    priority,
                    active,
                    applyOnImport,
                    actions: Object.keys(actions).length > 0 ? actions : null
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to save rule');
            }

            OC.Notification.showTemporary(isEdit ? 'Rule updated successfully' : 'Rule created successfully');
            this.hideModals();
            await this.loadRules();
        } catch (error) {
            console.error('Failed to save rule:', error);
            OC.Notification.showTemporary('Failed to save rule: ' + error.message);
        }
    }

    async editRule(ruleId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/import-rules/${ruleId}`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const rule = await response.json();
            this.showRuleModal(rule);
        } catch (error) {
            console.error('Failed to load rule:', error);
            OC.Notification.showTemporary('Failed to load rule');
        }
    }

    async deleteRule(ruleId) {
        if (!confirm('Are you sure you want to delete this rule?')) return;

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/import-rules/${ruleId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            OC.Notification.showTemporary('Rule deleted successfully');
            await this.loadRules();
        } catch (error) {
            console.error('Failed to delete rule:', error);
            OC.Notification.showTemporary('Failed to delete rule');
        }
    }

    async toggleRuleActive(ruleId, active) {
        try {
            // Find the rule data
            const rule = this.rules.find(r => r.id === ruleId);
            if (!rule) throw new Error('Rule not found');

            const response = await fetch(OC.generateUrl(`/apps/budget/api/import-rules/${ruleId}`), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    ...rule,
                    active: active
                })
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Failed to update rule');
            }

            // Update local state
            rule.active = active;

            // Update the row styling
            const row = document.querySelector(`.rule-row[data-rule-id="${ruleId}"]`);
            if (row) {
                row.classList.toggle('inactive', !active);
            }

            OC.Notification.showTemporary(active ? 'Rule enabled' : 'Rule disabled');
        } catch (error) {
            console.error('Failed to toggle rule:', error);
            OC.Notification.showTemporary('Failed to update rule: ' + error.message);
            // Revert the checkbox
            await this.loadRules();
        }
    }

    async showApplyRulesModal() {
        const modal = document.getElementById('apply-rules-modal');
        if (!modal) return;

        // Reset state
        document.getElementById('apply-rules-preview').style.display = 'none';
        document.getElementById('apply-rules-results').style.display = 'none';
        document.getElementById('execute-apply-rules-btn').disabled = true;

        // Populate account filter
        await this.populateApplyRulesFilters();

        // Populate rules selection
        await this.populateRulesSelectionList();

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    async populateApplyRulesFilters() {
        const accountSelect = document.getElementById('apply-account-filter');
        if (!accountSelect) return;

        // Keep first option (All Accounts)
        const firstOption = accountSelect.options[0];
        accountSelect.innerHTML = '';
        accountSelect.appendChild(firstOption);

        // Add accounts
        if (this.accounts) {
            this.accounts.forEach(account => {
                const option = document.createElement('option');
                option.value = account.id;
                option.textContent = account.name;
                accountSelect.appendChild(option);
            });
        }
    }

    async populateRulesSelectionList() {
        const container = document.getElementById('rules-selection-list');
        if (!container) return;

        // Ensure we have rules loaded
        if (!this.rules) {
            await this.loadRules();
        }

        const activeRules = this.rules?.filter(r => r.active) || [];

        if (activeRules.length === 0) {
            container.innerHTML = '<p class="no-rules-message">No active rules available. Create and activate rules first.</p>';
            return;
        }

        container.innerHTML = activeRules.map(rule => `
            <label class="rule-selection-item">
                <input type="checkbox" name="rule-select" value="${rule.id}" checked>
                <span class="rule-select-name">${this.escapeHtml(rule.name)}</span>
                <span class="rule-select-pattern">${this.escapeHtml(rule.pattern)}</span>
            </label>
        `).join('');
    }

    toggleAllRuleSelections(checked) {
        const checkboxes = document.querySelectorAll('#rules-selection-list input[type="checkbox"]');
        checkboxes.forEach(cb => cb.checked = checked);
    }

    async previewRuleApplication() {
        const previewDiv = document.getElementById('apply-rules-preview');
        const resultsDiv = document.getElementById('apply-rules-results');
        const executeBtn = document.getElementById('execute-apply-rules-btn');
        const previewBtn = document.getElementById('preview-rules-btn');

        if (!previewDiv) return;

        // Collect filters
        const filters = this.collectApplyRulesFilters();
        const ruleIds = this.collectSelectedRuleIds();

        if (ruleIds.length === 0) {
            OC.Notification.showTemporary('Please select at least one rule');
            return;
        }

        previewBtn.disabled = true;
        previewBtn.textContent = 'Loading...';

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import-rules/preview'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    ruleIds,
                    ...filters
                })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const result = await response.json();
            this.renderPreviewResults(result);

            previewDiv.style.display = 'block';
            resultsDiv.style.display = 'none';
            executeBtn.disabled = result.matchCount === 0;

        } catch (error) {
            console.error('Failed to preview rules:', error);
            OC.Notification.showTemporary('Failed to preview rule application');
        } finally {
            previewBtn.disabled = false;
            previewBtn.textContent = 'Preview Changes';
        }
    }

    collectApplyRulesFilters() {
        const accountId = document.getElementById('apply-account-filter')?.value || null;
        const startDate = document.getElementById('apply-date-start')?.value || null;
        const endDate = document.getElementById('apply-date-end')?.value || null;
        const uncategorizedOnly = document.getElementById('apply-uncategorized-only')?.checked || false;

        return {
            accountId: accountId ? parseInt(accountId) : null,
            startDate,
            endDate,
            uncategorizedOnly
        };
    }

    collectSelectedRuleIds() {
        const checkboxes = document.querySelectorAll('#rules-selection-list input[type="checkbox"]:checked');
        return Array.from(checkboxes).map(cb => parseInt(cb.value));
    }

    renderPreviewResults(result) {
        const countSpan = document.getElementById('preview-match-count');
        const tbody = document.querySelector('#apply-rules-preview-table tbody');

        if (countSpan) countSpan.textContent = result.matchCount;

        if (tbody) {
            tbody.innerHTML = result.preview.slice(0, 50).map(item => {
                const changesHtml = Object.entries(item.changes).map(([field, change]) => {
                    const fromVal = change.from || '(empty)';
                    const toVal = change.to || '(empty)';
                    if (field === 'categoryId') {
                        const fromCat = this.categories?.find(c => c.id === change.from)?.name || fromVal;
                        const toCat = this.categories?.find(c => c.id === change.to)?.name || toVal;
                        return `<span class="change-item">Category: ${this.escapeHtml(fromCat)} → ${this.escapeHtml(toCat)}</span>`;
                    }
                    return `<span class="change-item">${field}: ${this.escapeHtml(String(fromVal))} → ${this.escapeHtml(String(toVal))}</span>`;
                }).join('');

                return `
                    <tr>
                        <td>${this.formatDate(item.transactionDate)}</td>
                        <td>${this.escapeHtml(item.transactionDescription)}</td>
                        <td>${this.formatCurrency(item.transactionAmount)}</td>
                        <td>${this.escapeHtml(item.ruleName)}</td>
                        <td>${changesHtml}</td>
                    </tr>
                `;
            }).join('');

            if (result.matchCount > 50) {
                tbody.innerHTML += `<tr><td colspan="5" class="preview-truncated">... and ${result.matchCount - 50} more transactions</td></tr>`;
            }
        }
    }

    async executeApplyRules() {
        const previewDiv = document.getElementById('apply-rules-preview');
        const resultsDiv = document.getElementById('apply-rules-results');
        const executeBtn = document.getElementById('execute-apply-rules-btn');

        if (!confirm('Apply rules to the previewed transactions? This will modify the selected transactions.')) {
            return;
        }

        // Collect filters and rules
        const filters = this.collectApplyRulesFilters();
        const ruleIds = this.collectSelectedRuleIds();

        executeBtn.disabled = true;
        executeBtn.textContent = 'Applying...';

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import-rules/apply'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    ruleIds,
                    ...filters
                })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const result = await response.json();

            // Show results
            document.getElementById('result-success-count').textContent = result.success;
            document.getElementById('result-skipped-count').textContent = result.skipped;
            document.getElementById('result-failed-count').textContent = result.failed;

            previewDiv.style.display = 'none';
            resultsDiv.style.display = 'block';

            OC.Notification.showTemporary(`Rules applied: ${result.success} updated, ${result.skipped} skipped, ${result.failed} failed`);

            // Refresh transactions if we're on that view
            if (this.currentView === 'transactions') {
                await this.loadTransactions();
            }

        } catch (error) {
            console.error('Failed to apply rules:', error);
            OC.Notification.showTemporary('Failed to apply rules');
        } finally {
            executeBtn.disabled = false;
            executeBtn.textContent = 'Apply Rules';
        }
    }
}
