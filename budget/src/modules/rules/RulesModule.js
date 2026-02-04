/**
 * Rules Module - Transaction auto-categorization rules
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { CriteriaBuilder } from './components/CriteriaBuilder.js';
import { ActionBuilder } from './components/ActionBuilder.js';

export default class RulesModule {
    constructor(app) {
        this.app = app;
        this.criteriaBuilder = null; // Instance of CriteriaBuilder for v2 rules
        this.actionBuilder = null; // Instance of ActionBuilder for v2 actions
        this.currentRule = null; // Currently editing rule
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

    get tagSets() {
        return this.app.tagSets || [];
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

            // Get criteria display text based on schema version
            let criteriaText;
            if (rule.schemaVersion === 2 && rule.criteria) {
                criteriaText = this.formatCriteriaTreeSummary(rule.criteria);
            } else {
                // v1 format fallback
                const matchTypeLabels = {
                    'contains': 'contains',
                    'exact': 'equals',
                    'starts_with': 'starts with',
                    'ends_with': 'ends with',
                    'regex': 'matches'
                };
                criteriaText = `${rule.field} ${matchTypeLabels[rule.matchType] || rule.matchType} "${this.escapeHtml(rule.pattern)}"`;
            }

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

    formatCriteriaTreeSummary(criteria) {
        if (!criteria || !criteria.root) return 'Complex criteria';

        const root = criteria.root;

        // If root is a simple condition, format it
        if (root.type === 'condition') {
            return this.formatConditionSummary(root);
        }

        // If root is a group, show operator and condition count
        if (root.operator) {
            const conditionCount = this.countConditions(root);
            const operator = root.operator === 'AND' ? 'All' : 'Any';
            return `${operator} of ${conditionCount} condition${conditionCount !== 1 ? 's' : ''}`;
        }

        return 'Complex criteria';
    }

    formatConditionSummary(condition) {
        const matchTypeLabels = {
            'contains': 'contains',
            'starts_with': 'starts with',
            'ends_with': 'ends with',
            'equals': 'equals',
            'regex': 'matches',
            'greater_than': '>',
            'less_than': '<',
            'between': 'between',
            'before': 'before',
            'after': 'after'
        };

        const negate = condition.negate ? 'NOT ' : '';
        const field = condition.field || 'field';
        const matchType = matchTypeLabels[condition.matchType] || condition.matchType;
        const pattern = condition.pattern || '';

        return `${negate}${field} ${matchType} "${this.escapeHtml(pattern)}"`;
    }

    countConditions(node) {
        if (!node) return 0;

        if (node.type === 'condition') {
            return 1;
        }

        if (node.operator && node.conditions) {
            return node.conditions.reduce((sum, child) => sum + this.countConditions(child), 0);
        }

        return 0;
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

        // Rule modal cancel button
        const ruleModal = document.getElementById('rule-modal');
        if (ruleModal) {
            const cancelBtn = ruleModal.querySelector('.cancel-btn');
            if (cancelBtn && !cancelBtn.dataset.listenerAttached) {
                cancelBtn.addEventListener('click', () => this.hideModals());
                cancelBtn.dataset.listenerAttached = 'true';
            }
        }

        // Apply Rules modal cancel button
        const applyRulesModal = document.getElementById('apply-rules-modal');
        if (applyRulesModal) {
            const cancelBtn = applyRulesModal.querySelector('.cancel-btn');
            if (cancelBtn && !cancelBtn.dataset.listenerAttached) {
                cancelBtn.addEventListener('click', () => this.hideModals());
                cancelBtn.dataset.listenerAttached = 'true';
            }
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

        // Check if criteria has broken structure (root is a condition instead of a group)
        const hasBrokenStructure = rule && rule.schemaVersion === 2 && rule.criteria &&
            rule.criteria.root && rule.criteria.root.type === 'condition' && !rule.criteria.root.operator;

        // Auto-migrate v1 rules OR broken v2 rules
        const needsMigration = rule && (
            !rule.schemaVersion ||
            rule.schemaVersion === 1 ||
            (rule.schemaVersion === 2 && (!rule.criteria || Object.keys(rule.criteria).length === 0)) ||
            hasBrokenStructure
        );

        if (needsMigration) {
            try {
                const reason = hasBrokenStructure ? 'broken criteria structure' :
                    (!rule.criteria || Object.keys(rule.criteria).length === 0) ? 'null/empty criteria' :
                    'v1 rule';
                console.log('Migrating rule:', rule.id, 'reason:', reason, 'schemaVersion:', rule.schemaVersion, 'criteria:', rule.criteria);

                const response = await fetch(OC.generateUrl(`/apps/budget/api/import-rules/${rule.id}/migrate`), {
                    method: 'POST',
                    headers: { 'requesttoken': OC.requestToken }
                });

                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                rule = await response.json();
                console.log('Migration complete. Rule:', rule.id, 'schemaVersion:', rule.schemaVersion, 'criteria:', rule.criteria);
                OC.Notification.showTemporary('This rule has been upgraded to the new format with advanced features');
            } catch (error) {
                console.error('Failed to migrate rule:', error);
                OC.Notification.showTemporary('Failed to upgrade rule format');
                return;
            }
        }

        // Store current rule reference
        this.currentRule = rule;

        // Populate category dropdown
        await this.populateRuleCategoryDropdown();

        // Get UI sections
        const v1Section = document.getElementById('rule-criteria-v1');
        const v2Section = document.getElementById('rule-criteria-v2');

        if (rule) {
            title.textContent = 'Edit Rule';
            document.getElementById('rule-id').value = rule.id;
            document.getElementById('rule-name').value = rule.name || '';
            document.getElementById('rule-priority').value = rule.priority || 0;
            document.getElementById('rule-active').checked = rule.active !== false;
            document.getElementById('rule-apply-on-import').checked = rule.applyOnImport !== false;

            // Show appropriate criteria UI based on schema version
            console.log('Rule UI decision:', {
                ruleId: rule.id,
                schemaVersion: rule.schemaVersion,
                hasCriteria: !!rule.criteria,
                criteriaType: typeof rule.criteria,
                criteria: rule.criteria
            });

            if (rule.schemaVersion === 2 && rule.criteria) {
                // v2 format - show CriteriaBuilder
                console.log('Showing v2 CriteriaBuilder UI');
                if (v1Section) v1Section.style.display = 'none';
                if (v2Section) v2Section.style.display = 'block';
                this.initializeCriteriaBuilder(rule.criteria);
            } else {
                // v1 format (should not happen after migration, but fallback)
                console.warn('Showing v1 fallback UI - schemaVersion:', rule.schemaVersion, 'hasCriteria:', !!rule.criteria);
                if (v1Section) v1Section.style.display = 'block';
                if (v2Section) v2Section.style.display = 'none';
                document.getElementById('rule-field').value = rule.field || 'description';
                document.getElementById('rule-match-type').value = rule.matchType || 'contains';
                document.getElementById('rule-pattern').value = rule.pattern || '';
            }

            // Initialize ActionBuilder with rule actions
            this.initializeActionBuilder(rule.actions);
        } else {
            // New rule - use v2 format with empty criteria
            title.textContent = 'Add Rule';
            if (v1Section) v1Section.style.display = 'none';
            if (v2Section) v2Section.style.display = 'block';
            this.initializeCriteriaBuilder(null);
            this.initializeActionBuilder(null);
        }

        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');

        // Focus on name field
        const nameField = document.getElementById('rule-name');
        if (nameField) nameField.focus();
    }

    initializeCriteriaBuilder(initialCriteria) {
        const container = document.getElementById('criteria-builder-container');
        if (!container) {
            console.error('CriteriaBuilder container not found');
            return;
        }

        // Clear previous instance
        container.innerHTML = '';

        // Create new CriteriaBuilder instance
        this.criteriaBuilder = new CriteriaBuilder(container, initialCriteria);
    }

    initializeActionBuilder(initialActions) {
        const container = document.getElementById('action-builder-container');
        if (!container) {
            console.error('ActionBuilder container not found');
            return;
        }

        // Clear previous instance
        container.innerHTML = '';

        // Create new ActionBuilder instance with app data
        this.actionBuilder = new ActionBuilder(container, initialActions, {
            categories: this.categories,
            accounts: this.accounts,
            tagSets: this.tagSets
        });
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
        const priority = parseInt(document.getElementById('rule-priority').value) || 0;
        const active = document.getElementById('rule-active').checked;
        const applyOnImport = document.getElementById('rule-apply-on-import').checked;

        // Validate criteria from CriteriaBuilder
        if (!this.criteriaBuilder) {
            OC.Notification.showTemporary('Error: CriteriaBuilder not initialized');
            return;
        }

        const validation = this.criteriaBuilder.validate();
        if (!validation.valid) {
            OC.Notification.showTemporary('Invalid criteria: ' + validation.errors.join(', '));
            return;
        }

        const criteria = this.criteriaBuilder.getCriteria();

        // Validate actions from ActionBuilder
        if (!this.actionBuilder) {
            OC.Notification.showTemporary('Error: ActionBuilder not initialized');
            return;
        }

        const actionsValidation = this.actionBuilder.validate();
        if (!actionsValidation.valid) {
            OC.Notification.showTemporary('Invalid actions: ' + actionsValidation.errors.join(', '));
            return;
        }

        const actions = this.actionBuilder.getActions();

        try {
            const url = isEdit
                ? OC.generateUrl(`/apps/budget/api/import-rules/${ruleId}`)
                : OC.generateUrl('/apps/budget/api/import-rules');

            const requestBody = {
                name,
                priority,
                active,
                applyOnImport,
                schemaVersion: 2,
                criteria,
                actions: actions,
                stopProcessing: actions.stopProcessing
            };

            const response = await fetch(url, {
                method: isEdit ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(requestBody)
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
