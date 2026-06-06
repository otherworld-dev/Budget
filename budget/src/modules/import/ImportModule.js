/**
 * Import Module - Bank statement import with CSV/OFX/QIF support
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError, showWarning, showInfo } from '../../utils/notifications.js';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';

export default class ImportModule {
    constructor(app) {
        this.app = app;

        // Import wizard state
        this.currentImportStep = 1;
        this.currentImportData = null;
        this.processedTransactions = null;
        this.sourceAccounts = [];
        this.importFormat = null;
        this.currentDelimiter = ',';
        this.importHistory = [];
        this.availableAccounts = [];
        this.handleDelimiterChange = null;

        // Preset state
        this.presets = [];
        this.selectedPreset = null;
        this.previewTotalValid = 0;

        // User-saved import template state
        this.userTemplates = [];
        this.selectedTemplate = null;
    }

    // ============================================
    // State Proxies
    // ============================================

    get data() { return this.app.data; }
    set data(value) { this.app.data = value; }

    get accounts() { return this.app.accounts; }
    get categories() { return this.app.categories; }
    get settings() { return this.app.settings; }

    // ============================================
    // Helper Method Proxies
    // ============================================

    formatCurrency(amount, currency = null) {
        return formatters.formatCurrency(amount, currency, this.settings);
    }

    formatDate(date) {
        return formatters.formatDate(date, this.settings);
    }

    getPrimaryCurrency() {
        return this.app.getPrimaryCurrency();
    }

    loadTransactions() {
        return this.app.loadTransactions();
    }

    getCategoryLabel(transaction) {
        if (transaction.categoryId && this.categories) {
            const cat = this.categories.find(c => c.id === transaction.categoryId);
            if (cat) return cat.name;
        }
        if (transaction.appliedRule?.name) {
            return t('budget', 'Rule: {name}', { name: transaction.appliedRule.name });
        }
        return t('budget', 'Uncategorized');
    }

    // ============================================
    // Import Module Methods
    // ============================================

    async handleImportFile(file) {
        const formData = new FormData();
        formData.append('file', file);

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import/upload'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken
                },
                body: formData
            });

            if (response.ok) {
                const result = await response.json();
                this.currentImportData = result;
                this.showImportMapping(result);
            } else {
                throw new Error(t('budget', 'Upload failed'));
            }
        } catch (error) {
            console.error('Failed to upload file:', error);
            showError(t('budget', 'Failed to upload file'));
        }
    }

    async loadPresets() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import/templates'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (response.ok) {
                const data = await response.json();
                this.presets = Object.values(data).filter(t => t.isPreset);
            }
        } catch (error) {
            console.error('Failed to load presets:', error);
        }
    }

    async loadUserTemplates() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import-templates'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (response.ok) {
                this.userTemplates = await response.json();
            }
        } catch (error) {
            console.error('Failed to load import templates:', error);
        }
    }

    showPresetSelector() {
        const step2 = document.getElementById('import-step-2');
        if (!step2) return;

        let presetGroup = document.getElementById('import-preset-group');
        if (!presetGroup) {
            presetGroup = document.createElement('div');
            presetGroup.className = 'form-group';
            presetGroup.id = 'import-preset-group';
            // Insert at the top of step 2
            step2.insertBefore(presetGroup, step2.firstChild);
            presetGroup.addEventListener('change', (e) => {
                if (e.target.id === 'import-preset') this.onImportFormatChange(e.target.value);
            });
            presetGroup.addEventListener('click', (e) => {
                if (e.target.closest('#save-template-btn')) this.openSaveTemplateModal();
                if (e.target.closest('#manage-templates-btn')) this.openManageTemplatesModal();
            });
        }

        this.renderPresetSelector();
    }

    renderPresetSelector() {
        const presetGroup = document.getElementById('import-preset-group');
        if (!presetGroup) return;

        // Preserve the current selection across re-renders
        const current = document.getElementById('import-preset')?.value
            ?? (this.selectedTemplate ? `template:${this.selectedTemplate}` : (this.selectedPreset ? `preset:${this.selectedPreset}` : ''));

        const presetOptions = this.presets
            .map(p => `<option value="preset:${dom.escapeHtml(String(p.id))}">${dom.escapeHtml(p.name)}</option>`)
            .join('');
        // Only CSV-format templates apply to the column-mapping step.
        const csvTemplates = this.userTemplates.filter(tpl => (tpl.format || 'csv') === 'csv');
        const templateOptions = csvTemplates
            .map(tpl => `<option value="template:${tpl.id}">${dom.escapeHtml(tpl.name)}</option>`)
            .join('');

        presetGroup.innerHTML = `
            <label for="import-preset">${t('budget', 'Import Format')}</label>
            <select id="import-preset">
                <option value="">${t('budget', 'Custom CSV (manual mapping)')}</option>
                ${csvTemplates.length ? `<optgroup label="${t('budget', 'My Templates')}">${templateOptions}</optgroup>` : ''}
                ${this.presets.length ? `<optgroup label="${t('budget', 'Bank Presets')}">${presetOptions}</optgroup>` : ''}
            </select>
            <div class="import-template-actions">
                <button type="button" class="button" id="save-template-btn">${t('budget', 'Save mapping as template…')}</button>
                <button type="button" class="button" id="manage-templates-btn">${t('budget', 'Manage templates')}</button>
            </div>
            <p class="preset-description" id="preset-description" style="display:none;"></p>
        `;

        const select = document.getElementById('import-preset');
        if (select) select.value = current;
    }

    /**
     * Handle a change of the "Import Format" dropdown. Values are prefixed:
     * "preset:<id>" for built-in bank presets, "template:<id>" for user templates.
     */
    onImportFormatChange(value) {
        this.selectedPreset = null;
        this.selectedTemplate = null;

        const desc = document.getElementById('preset-description');
        const mappingContainer = document.querySelector('#import-step-2 .mapping-container');
        const mappingOptions = document.querySelector('#import-step-2 .mapping-options');
        const setManualMappingVisible = (visible) => {
            if (mappingContainer) mappingContainer.style.display = visible ? '' : 'none';
            if (mappingOptions) mappingOptions.style.display = visible ? '' : 'none';
        };

        if (value.startsWith('preset:')) {
            // Built-in preset: server applies a fixed mapping, hide manual controls.
            this.selectedPreset = value.slice('preset:'.length);
            const preset = this.presets.find(p => String(p.id) === this.selectedPreset);
            if (preset && desc) {
                desc.textContent = preset.description || '';
                desc.style.display = preset.description ? 'block' : 'none';
            }
            setManualMappingVisible(false);
        } else if (value.startsWith('template:')) {
            // User template: prefill the manual controls so they can be reviewed/tweaked.
            this.selectedTemplate = parseInt(value.slice('template:'.length), 10);
            const template = this.userTemplates.find(tpl => tpl.id === this.selectedTemplate);
            if (template) {
                this.applyTemplateToForm(template);
                if (desc) {
                    desc.textContent = t('budget', 'Using saved template. Adjust any column to switch back to a custom mapping.');
                    desc.style.display = 'block';
                }
            }
            setManualMappingVisible(true);
        } else {
            if (desc) desc.style.display = 'none';
            setManualMappingVisible(true);
        }

        const nextBtn = document.getElementById('next-step-btn');
        if (nextBtn) nextBtn.disabled = !this.canProceedToNextStep();
    }

    /**
     * Populate the manual mapping controls from a saved template.
     * Setting values programmatically does not fire change events, so the
     * template stays "selected" until the user edits a control.
     */
    applyTemplateToForm(template) {
        const mapping = template.mapping || {};
        const setSelect = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.value = value ?? '';
        };
        setSelect('map-date', mapping.date);
        setSelect('map-amount', mapping.amount);
        setSelect('map-income', mapping.incomeColumn);
        setSelect('map-expense', mapping.expenseColumn);
        setSelect('map-description', mapping.description);
        setSelect('map-type', mapping.type);
        setSelect('map-vendor', mapping.vendor);
        setSelect('map-reference', mapping.reference);
        setSelect('map-category', mapping.category);
        setSelect('map-account', mapping.account);
        setSelect('map-currency', mapping.currency);

        const skipFirstRow = document.getElementById('skip-first-row');
        if (skipFirstRow) skipFirstRow.checked = !!mapping.skipFirstRow;
        const applyRules = document.getElementById('apply-rules');
        if (applyRules && mapping.applyRules !== undefined) applyRules.checked = !!mapping.applyRules;

        const delimiterSelect = document.getElementById('csv-delimiter');
        if (delimiterSelect && template.delimiter) delimiterSelect.value = template.delimiter;

        this.applyTemplateOptions(template);

        this.highlightMappedColumns(this.getCurrentMapping());
        this.validateMappingStep();
    }

    /**
     * Apply a template's cross-format options to the shared controls.
     * The user can still re-toggle them before importing (their value wins).
     */
    applyTemplateOptions(template) {
        const showDuplicates = document.getElementById('show-duplicates');
        if (showDuplicates && typeof template.skipDuplicates === 'boolean') {
            // The control is "show duplicates" — the inverse of "skip duplicates".
            showDuplicates.checked = !template.skipDuplicates;
        }
        const applyRules = document.getElementById('apply-rules');
        if (applyRules && typeof template.applyRules === 'boolean') {
            applyRules.checked = template.applyRules;
        }
    }

    // ============================================
    // Import Template Management (save / manage modals)
    // ============================================

    closeTemplateModal(id) {
        const modal = document.getElementById(id);
        if (modal) modal.style.display = 'none';
    }

    openSaveTemplateModal() {
        const format = this.importFormat || 'csv';
        if (format === 'csv') {
            if (!this.validateMappingStep()) {
                showWarning(t('budget', 'Map the required columns before saving a template'));
                return;
            }
        } else if (!this.hasAnyAccountMapping()) {
            showWarning(t('budget', 'Map at least one account before saving a template'));
            return;
        }
        const modal = document.getElementById('import-save-template-modal');
        if (!modal) return;
        const nameInput = document.getElementById('import-template-name');
        if (nameInput) nameInput.value = '';
        modal.style.display = 'flex';
        nameInput?.focus();
    }

    async saveCurrentTemplate() {
        const nameInput = document.getElementById('import-template-name');
        const name = (nameInput?.value || '').trim();
        if (!name) {
            showWarning(t('budget', 'Please enter a template name'));
            return;
        }

        const format = this.importFormat || 'csv';
        const skipDuplicates = !(document.getElementById('show-duplicates')?.checked ?? true);
        const requestBody = { name, format, skipDuplicates };

        if (format === 'csv') {
            const mapping = this.getCurrentMapping();
            requestBody.mapping = mapping;
            requestBody.delimiter = document.getElementById('csv-delimiter')?.value || ',';
            requestBody.skipFirstRow = !!mapping.skipFirstRow;
            requestBody.applyRules = !!mapping.applyRules;
            const accountId = parseInt(document.getElementById('import-account')?.value, 10);
            if (accountId) requestBody.accountId = accountId;
        } else {
            // OFX/QIF: the reusable payload is the source->destination account routing.
            requestBody.accountMapping = this.getAccountMapping();
            requestBody.applyRules = true;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import-templates'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify(requestBody)
            });
            const data = await response.json();
            if (response.ok) {
                showSuccess(t('budget', 'Import template saved'));
                this.closeTemplateModal('import-save-template-modal');
                await this.loadUserTemplates();
                // Select the newly saved template in whichever selector is active.
                this.selectedTemplate = data.id;
                this.selectedPreset = null;
                if (format === 'csv') {
                    this.renderPresetSelector();
                    const select = document.getElementById('import-preset');
                    if (select) select.value = `template:${data.id}`;
                } else {
                    this.renderRoutingTemplateBar();
                    const select = document.getElementById('import-routing-template');
                    if (select) select.value = `template:${data.id}`;
                }
            } else {
                showError(data.error || t('budget', 'Failed to save import template'));
            }
        } catch (error) {
            console.error('Failed to save import template:', error);
            showError(t('budget', 'Failed to save import template'));
        }
    }

    openManageTemplatesModal() {
        const modal = document.getElementById('import-templates-modal');
        if (!modal) return;
        this.renderManageTemplatesList();
        modal.style.display = 'flex';
    }

    renderManageTemplatesList() {
        const list = document.getElementById('import-templates-list');
        if (!list) return;

        if (!this.userTemplates.length) {
            list.innerHTML = `<p class="empty-state">${t('budget', 'No saved templates yet. Map your columns, then use “Save mapping as template”.')}</p>`;
            return;
        }

        list.innerHTML = this.userTemplates.map(tpl => {
            const format = (tpl.format || 'csv').toUpperCase();
            let meta;
            if ((tpl.format || 'csv') === 'csv') {
                const columnCount = Object.keys(tpl.mapping || {})
                    .filter(k => !['skipFirstRow', 'applyRules'].includes(k)).length;
                meta = n('budget', '%n column mapped', '%n columns mapped', columnCount);
            } else {
                const accountCount = Object.keys(tpl.accountMapping || {}).length;
                meta = n('budget', '%n account routed', '%n accounts routed', accountCount);
            }
            return `
                <div class="import-template-row" data-id="${tpl.id}">
                    <div class="import-template-info">
                        <span class="import-template-name">
                            <span class="import-template-format">${dom.escapeHtml(format)}</span>
                            ${dom.escapeHtml(tpl.name)}
                        </span>
                        <span class="import-template-meta">${meta}</span>
                    </div>
                    <div class="import-template-row-actions">
                        <button type="button" class="button" data-action="rename">${t('budget', 'Rename')}</button>
                        <button type="button" class="button button-danger" data-action="delete">${t('budget', 'Delete')}</button>
                    </div>
                </div>`;
        }).join('');
    }

    async renameTemplate(id) {
        const template = this.userTemplates.find(tpl => tpl.id === id);
        if (!template) return;
        const newName = window.prompt(t('budget', 'New template name'), template.name);
        if (newName === null) return;
        const name = newName.trim();
        if (!name || name === template.name) return;

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import-templates/' + id), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({ name })
            });
            const data = await response.json();
            if (response.ok) {
                showSuccess(t('budget', 'Import template renamed'));
                await this.loadUserTemplates();
                this.renderManageTemplatesList();
                this.refreshTemplateSelectors();
            } else {
                showError(data.error || t('budget', 'Failed to rename import template'));
            }
        } catch (error) {
            console.error('Failed to rename import template:', error);
            showError(t('budget', 'Failed to rename import template'));
        }
    }

    async deleteTemplate(id) {
        if (!confirm(t('budget', 'Are you sure you want to delete this import template?'))) return;

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import-templates/' + id), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });
            if (response.ok) {
                showSuccess(t('budget', 'Import template deleted'));
                if (this.selectedTemplate === id) {
                    this.selectedTemplate = null;
                }
                await this.loadUserTemplates();
                this.renderManageTemplatesList();
                this.refreshTemplateSelectors();
            } else {
                showError(t('budget', 'Failed to delete import template'));
            }
        } catch (error) {
            console.error('Failed to delete import template:', error);
            showError(t('budget', 'Failed to delete import template'));
        }
    }

    /** Refresh whichever template selector(s) are currently on screen. */
    refreshTemplateSelectors() {
        if (document.getElementById('import-preset-group')) this.renderPresetSelector();
        if (document.getElementById('multi-account-mapping')) this.renderRoutingTemplateBar();
    }

    // ============================================
    // OFX/QIF account-routing templates (step 3)
    // ============================================

    /**
     * Render the "Saved routing" bar above the source→destination account list,
     * populated with templates matching the uploaded file's format.
     */
    renderRoutingTemplateBar() {
        const container = document.getElementById('multi-account-mapping');
        if (!container) return;
        const format = this.importFormat || 'ofx';
        if (format === 'csv') return; // routing templates are OFX/QIF only

        let bar = document.getElementById('import-routing-template-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'form-group';
            bar.id = 'import-routing-template-bar';
            container.insertBefore(bar, container.firstChild);
        }

        const templates = this.userTemplates.filter(tpl => (tpl.format || 'csv') === format);
        const current = document.getElementById('import-routing-template')?.value
            ?? (this.selectedTemplate ? `template:${this.selectedTemplate}` : '');
        const options = templates
            .map(tpl => `<option value="template:${tpl.id}">${dom.escapeHtml(tpl.name)}</option>`)
            .join('');

        bar.innerHTML = `
            <label for="import-routing-template">${t('budget', 'Saved Account Routing')}</label>
            <select id="import-routing-template">
                <option value="">${t('budget', 'Manual (set below)')}</option>
                ${templates.length ? `<optgroup label="${t('budget', 'My Templates')}">${options}</optgroup>` : ''}
            </select>
            <div class="import-template-actions">
                <button type="button" class="button" id="save-routing-template-btn">${t('budget', 'Save routing as template…')}</button>
                <button type="button" class="button" id="manage-routing-templates-btn">${t('budget', 'Manage templates')}</button>
            </div>
        `;

        const select = document.getElementById('import-routing-template');
        if (select) select.value = current;
    }

    onRoutingTemplateChange(value) {
        this.selectedTemplate = null;
        if (!value.startsWith('template:')) return;

        const id = parseInt(value.slice('template:'.length), 10);
        const template = this.userTemplates.find(tpl => tpl.id === id);
        if (!template) return;

        this.selectedTemplate = id;
        this.applyRoutingTemplate(template);
    }

    /**
     * Fill the destination-account selects from a saved routing template,
     * apply its options, and trigger a preview.
     */
    applyRoutingTemplate(template) {
        const mapping = template.accountMapping || {};
        let appliedAny = false;
        document.querySelectorAll('.destination-account-select').forEach(select => {
            const sourceKey = select.dataset.sourceId;
            if (Object.prototype.hasOwnProperty.call(mapping, sourceKey)) {
                select.value = String(mapping[sourceKey]);
                if (select.value === String(mapping[sourceKey])) appliedAny = true;
            }
        });

        this.applyTemplateOptions(template);

        if (appliedAny && this.hasAnyAccountMapping()) {
            this.processImportData();
        } else if (!appliedAny) {
            showWarning(t('budget', 'This template’s accounts don’t match the uploaded file'));
        }
    }

    // ============================================
    // Enhanced Import System Methods
    // ============================================

    setupImportEventListeners() {
        // Tab navigation
        const tabButtons = document.querySelectorAll('.import-tab-btn');
        tabButtons.forEach(button => {
            button.addEventListener('click', (e) => {
                const tabName = e.target.dataset.tab;
                this.switchImportTab(tabName);
            });
        });

        // Wizard navigation
        const nextBtn = document.getElementById('next-step-btn');
        const prevBtn = document.getElementById('prev-step-btn');
        const importBtn = document.getElementById('import-btn');
        const cancelBtn = document.getElementById('cancel-import-btn');

        if (nextBtn) {
            nextBtn.addEventListener('click', () => this.nextImportStep());
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', () => this.prevImportStep());
        }
        if (importBtn) {
            importBtn.addEventListener('click', () => this.executeImport());
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => this.cancelImport());
        }

        // Account selection triggers preview loading
        const importAccountSelect = document.getElementById('import-account');
        if (importAccountSelect) {
            importAccountSelect.addEventListener('change', () => {
                if (importAccountSelect.value && this.currentImportStep === 3) {
                    this.processImportData();
                }
            });
        }

        // Column mapping change handlers
        const mappingSelects = document.querySelectorAll('#import-step-2 select');
        mappingSelects.forEach(select => {
            select.addEventListener('change', () => this.updatePreviewMapping());
        });

        // Preview filter checkboxes
        const showDuplicates = document.getElementById('show-duplicates');
        const showUncategorized = document.getElementById('show-uncategorized');
        if (showDuplicates) {
            showDuplicates.addEventListener('change', () => this.filterPreviewTransactions());
        }
        if (showUncategorized) {
            showUncategorized.addEventListener('change', () => this.filterPreviewTransactions());
        }

        // Save-template modal
        const saveTemplateForm = document.getElementById('import-save-template-form');
        if (saveTemplateForm) {
            saveTemplateForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveCurrentTemplate();
            });
        }
        document.querySelectorAll('#import-save-template-modal .modal-close, #import-save-template-modal .cancel-btn')
            .forEach(btn => btn.addEventListener('click', () => this.closeTemplateModal('import-save-template-modal')));

        // Manage-templates modal (event delegation for rename/delete)
        const templatesList = document.getElementById('import-templates-list');
        if (templatesList) {
            templatesList.addEventListener('click', (e) => {
                const action = e.target.dataset?.action;
                if (!action) return;
                const row = e.target.closest('.import-template-row');
                if (!row) return;
                const id = parseInt(row.dataset.id, 10);
                if (action === 'rename') this.renameTemplate(id);
                if (action === 'delete') this.deleteTemplate(id);
            });
        }
        document.querySelectorAll('#import-templates-modal .modal-close, #import-templates-modal .cancel-btn')
            .forEach(btn => btn.addEventListener('click', () => this.closeTemplateModal('import-templates-modal')));

        // OFX/QIF routing-template bar (delegated; the bar is rendered dynamically)
        const multiAccount = document.getElementById('multi-account-mapping');
        if (multiAccount) {
            multiAccount.addEventListener('change', (e) => {
                if (e.target.id === 'import-routing-template') this.onRoutingTemplateChange(e.target.value);
            });
            multiAccount.addEventListener('click', (e) => {
                if (e.target.closest('#save-routing-template-btn')) this.openSaveTemplateModal();
                if (e.target.closest('#manage-routing-templates-btn')) this.openManageTemplatesModal();
            });
        }

        // Initialize import state
        this.currentImportStep = 1;
        this.currentImportData = null;
        this.importHistory = [];
    }

    switchImportTab(tabName) {
        // Switch tab buttons
        document.querySelectorAll('.import-tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');

        // Switch tab content
        document.querySelectorAll('.import-tab-content').forEach(content => {
            content.classList.remove('active');
        });
        document.getElementById(`import-${tabName}-tab`).classList.add('active');

        // Load tab-specific data
        if (tabName === 'history') {
            this.loadImportHistory();
        }
    }

    async showImportMapping(uploadResult) {
        // Switch to wizard tab if not already active
        this.switchImportTab('wizard');

        // Store source accounts for multi-account mapping
        this.sourceAccounts = uploadResult.sourceAccounts || [];
        this.importFormat = uploadResult.format;
        this.currentDelimiter = uploadResult.delimiter || ',';

        // Update file info
        const fileDetails = document.querySelector('.file-details');
        if (fileDetails) {
            fileDetails.innerHTML = `
                <span class="file-name">${dom.escapeHtml(uploadResult.filename)}</span>
                <span class="file-size">${this.formatFileSize(uploadResult.size)}</span>
                <span class="record-count">${n('budget', '%n record', '%n records', uploadResult.recordCount)}</span>
            `;
        }

        // Show/hide CSV options based on format
        const csvOptions = document.getElementById('csv-options');
        if (csvOptions) {
            if (uploadResult.format === 'csv') {
                csvOptions.style.display = 'block';
                const delimiterSelect = document.getElementById('csv-delimiter');
                if (delimiterSelect) {
                    delimiterSelect.value = this.currentDelimiter;
                    // Add change handler for delimiter to reload columns
                    delimiterSelect.removeEventListener('change', this.handleDelimiterChange);
                    this.handleDelimiterChange = () => this.reloadColumnsWithDelimiter();
                    delimiterSelect.addEventListener('change', this.handleDelimiterChange);
                }
            } else {
                csvOptions.style.display = 'none';
            }
        }

        // Populate column mapping dropdowns
        this.populateColumnMappings(uploadResult.columns);

        // Show preview data
        this.showMappingPreview(uploadResult.preview);

        // Saved templates are available for every format (CSV column mappings,
        // OFX/QIF account routing), so load them regardless of format.
        await this.loadUserTemplates();

        // The Import Format selector (presets + CSV templates) is CSV-only;
        // OFX/QIF get their routing-template bar later, on the account step.
        if (uploadResult.format === 'csv') {
            if (this.presets.length === 0) {
                await this.loadPresets();
            }
            this.showPresetSelector();
        }

        // Move to step 2
        this.setImportStep(2);
    }

    reloadColumnsWithDelimiter() {
        const delimiterSelect = document.getElementById('csv-delimiter');
        if (!delimiterSelect) return;

        this.currentDelimiter = delimiterSelect.value;
        showInfo(t('budget', 'Delimiter changed. File will be re-parsed in the next step.'));
    }

    populateColumnMappings(columns) {
        const mappingSelects = {
            'map-date': document.getElementById('map-date'),
            'map-amount': document.getElementById('map-amount'),
            'map-income': document.getElementById('map-income'),
            'map-expense': document.getElementById('map-expense'),
            'map-description': document.getElementById('map-description'),
            'map-type': document.getElementById('map-type'),
            'map-vendor': document.getElementById('map-vendor'),
            'map-reference': document.getElementById('map-reference'),
            'map-category': document.getElementById('map-category'),
            'map-account': document.getElementById('map-account'),
            'map-currency': document.getElementById('map-currency')
        };

        // Clear existing options and add columns
        Object.values(mappingSelects).forEach(select => {
            if (!select) return;
            const firstOption = select.firstElementChild;
            select.innerHTML = '';
            if (firstOption) select.appendChild(firstOption);

            columns.forEach((column, _index) => {
                const option = document.createElement('option');
                option.value = column;
                option.textContent = column;
                select.appendChild(option);
            });
        });

        // Auto-detect common column mappings
        this.autoDetectMappings(columns, mappingSelects);
    }

    autoDetectMappings(columns, mappingSelects) {
        const patterns = {
            'map-date': ['date', 'transaction date', 'trans date', 'posting date'],
            'map-amount': ['amount', 'transaction amount', 'trans amount', 'value'],
            'map-income': ['income', 'credit', 'deposits', 'deposit', 'credits', 'receipts'],
            'map-expense': ['expense', 'debit', 'withdrawals', 'withdrawal', 'debits', 'payments', 'payment'],
            'map-description': ['description', 'memo', 'details', 'transaction details'],
            'map-type': ['type', 'transaction type', 'debit/credit', 'dr/cr'],
            'map-vendor': ['vendor', 'payee', 'merchant', 'counterparty'],
            'map-reference': ['reference', 'ref', 'check number', 'transaction id'],
            'map-category': ['category', 'kategorie', 'catégorie', 'group'],
            'map-account': ['account', 'account name', 'konto'],
            'map-currency': ['currency', 'währung', 'devise']
        };

        Object.entries(patterns).forEach(([fieldId, patternList]) => {
            const select = mappingSelects[fieldId];
            if (!select) return;

            const matchingColumn = columns.find(col =>
                patternList.some(pattern =>
                    col.toLowerCase().includes(pattern.toLowerCase())
                )
            );

            if (matchingColumn) {
                select.value = matchingColumn;
            }
        });
    }

    showMappingPreview(previewData) {
        const table = document.getElementById('mapping-preview-table');
        if (!table || !previewData.length) return;

        // Create header
        const thead = table.querySelector('thead');
        const tbody = table.querySelector('tbody');

        thead.innerHTML = '';
        tbody.innerHTML = '';

        const headerRow = document.createElement('tr');
        previewData[0].forEach((header, index) => {
            const th = document.createElement('th');
            th.textContent = `${index + 1}. ${header}`;
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);

        // Show first 5 rows of data
        previewData.slice(1, 6).forEach(row => {
            const tr = document.createElement('tr');
            row.forEach(cell => {
                const td = document.createElement('td');
                // Handle objects/arrays by converting to string
                if (cell === null || cell === undefined) {
                    td.textContent = '';
                } else if (typeof cell === 'object') {
                    td.textContent = JSON.stringify(cell);
                } else {
                    td.textContent = String(cell);
                }
                td.title = td.textContent; // Show full text on hover
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
    }

    updatePreviewMapping() {
        // A manual column change means the mapping has diverged from any saved
        // template, so fall back to a custom mapping for this import.
        if (this.selectedTemplate) {
            this.selectedTemplate = null;
            const select = document.getElementById('import-preset');
            if (select) select.value = '';
            const desc = document.getElementById('preset-description');
            if (desc) desc.style.display = 'none';
        }

        // Update the mapping preview when selections change
        const mapping = this.getCurrentMapping();
        // Update mapping indicators in preview table
        this.highlightMappedColumns(mapping);
        this.validateMappingStep();
    }

    getCurrentMapping() {
        return {
            date: document.getElementById('map-date')?.value || null,
            amount: document.getElementById('map-amount')?.value || null,
            incomeColumn: document.getElementById('map-income')?.value || null,
            expenseColumn: document.getElementById('map-expense')?.value || null,
            description: document.getElementById('map-description')?.value || null,
            type: document.getElementById('map-type')?.value || null,
            vendor: document.getElementById('map-vendor')?.value || null,
            reference: document.getElementById('map-reference')?.value || null,
            category: document.getElementById('map-category')?.value || null,
            account: document.getElementById('map-account')?.value || null,
            currency: document.getElementById('map-currency')?.value || null,
            skipFirstRow: document.getElementById('skip-first-row')?.checked || false,
            applyRules: document.getElementById('apply-rules')?.checked || false
        };
    }

    highlightMappedColumns(mapping) {
        const table = document.getElementById('mapping-preview-table');
        const headers = table.querySelectorAll('th');

        // Reset highlighting
        headers.forEach(th => th.classList.remove('mapped-column'));

        // Highlight mapped columns
        Object.values(mapping).forEach(columnIndex => {
            if (columnIndex !== null && columnIndex !== '') {
                const header = headers[parseInt(columnIndex)];
                if (header) header.classList.add('mapped-column');
            }
        });
    }

    async nextImportStep() {
        if (this.currentImportStep === 1) {
            // Step 1 → 2: File should be uploaded
            if (!this.currentImportData) {
                showWarning(t('budget', 'Please select a file first'));
                return;
            }
            this.setImportStep(2);
        } else if (this.currentImportStep === 2) {
            // Step 2 → 3: Validate mapping, then show step 3 with account selection
            if (!this.validateMappingStep()) {
                return;
            }
            this.setImportStep(3);
            // Preview will be loaded when user selects an account
        }
    }

    prevImportStep() {
        if (this.currentImportStep > 1) {
            this.setImportStep(this.currentImportStep - 1);
        }
    }

    setImportStep(step) {
        this.currentImportStep = step;

        // Update progress bar
        document.querySelectorAll('.wizard-step').forEach((stepEl, index) => {
            stepEl.classList.remove('active', 'completed');
            if (index + 1 < step) {
                stepEl.classList.add('completed');
            } else if (index + 1 === step) {
                stepEl.classList.add('active');
            }
        });

        // Show/hide steps
        document.querySelectorAll('.import-step').forEach((stepEl, index) => {
            stepEl.classList.remove('active');
            stepEl.style.display = 'none';
            if (index + 1 === step) {
                stepEl.classList.add('active');
                stepEl.style.display = 'block';
            }
        });

        // Update navigation buttons
        const prevBtn = document.getElementById('prev-step-btn');
        const nextBtn = document.getElementById('next-step-btn');
        const importBtn = document.getElementById('import-btn');

        if (prevBtn) {
            prevBtn.style.display = step > 1 ? 'block' : 'none';
        }

        if (nextBtn) {
            nextBtn.style.display = step < 3 ? 'block' : 'none';
            nextBtn.disabled = !this.canProceedToNextStep();
        }

        if (importBtn) {
            importBtn.style.display = step === 3 ? 'block' : 'none';
        }

        // Load step-specific data
        if (step === 3) {
            this.loadAccountsForImport();
        }
    }

    canProceedToNextStep() {
        if (this.currentImportStep === 1) {
            return this.currentImportData !== null;
        } else if (this.currentImportStep === 2) {
            return this.validateMappingStep();
        }
        return false;
    }

    validateMappingStep() {
        // If a preset is selected, mapping is pre-configured — always valid
        if (this.selectedPreset) {
            const nextBtn = document.getElementById('next-step-btn');
            if (nextBtn) nextBtn.disabled = false;
            return true;
        }

        const mapping = this.getCurrentMapping();

        // Check required fields: date and description
        const hasDate = mapping.date !== null && mapping.date !== '';
        const hasDescription = mapping.description !== null && mapping.description !== '';

        // Check amount: either single amount column OR both income and expense columns
        const hasAmount = mapping.amount !== null && mapping.amount !== '';
        const hasIncome = mapping.incomeColumn !== null && mapping.incomeColumn !== '';
        const hasExpense = mapping.expenseColumn !== null && mapping.expenseColumn !== '';
        const hasDualColumns = hasIncome || hasExpense;

        // Valid if we have (amount XOR dual-columns)
        const hasValidAmount = (hasAmount && !hasDualColumns) || (!hasAmount && hasDualColumns);

        const isValid = hasDate && hasDescription && hasValidAmount;

        // Update next button state
        const nextBtn = document.getElementById('next-step-btn');
        if (nextBtn) {
            nextBtn.disabled = !isValid;
        }

        return isValid;
    }

    async processImportData() {
        // Show loading indicator while preview is being generated
        const previewSection = document.getElementById('import-preview-section');
        if (previewSection) {
            previewSection.style.display = 'block';
            const previewTable = document.getElementById('preview-table');
            if (previewTable) previewTable.style.display = 'none';
            const previewInfo = document.getElementById('preview-info');
            if (previewInfo) previewInfo.textContent = t('budget', 'Processing file, please wait...');
        }

        const mapping = this.getCurrentMapping();
        const isMultiAccount = this.sourceAccounts && this.sourceAccounts.length > 0;

        // Build request body based on import type
        const requestBody = {
            fileId: this.currentImportData.fileId,
            mapping: mapping,
            skipDuplicates: !(document.getElementById('show-duplicates')?.checked ?? true),
            delimiter: document.getElementById('csv-delimiter')?.value || ','
        };

        // Include preset ID if selected
        if (this.selectedPreset) {
            requestBody.presetId = this.selectedPreset;
        }

        // Include saved template ID if selected (server resolves the mapping)
        if (this.selectedTemplate) {
            requestBody.templateId = this.selectedTemplate;
        }

        // Check if preset has accountColumn or manual mapping has account column
        const presetHasAccountColumn = this.selectedPreset && this.presets.find(p => p.id === this.selectedPreset)?.options?.accountColumn;
        const mappingHasAccountColumn = !!(mapping.account);

        if (isMultiAccount) {
            const accountMapping = this.getAccountMapping();
            if (Object.keys(accountMapping).length === 0) {
                showWarning(t('budget', 'Please map at least one account'));
                return;
            }
            requestBody.accountMapping = accountMapping;
        } else if (!presetHasAccountColumn && !mappingHasAccountColumn) {
            const accountId = document.getElementById('import-account')?.value;
            if (!accountId) {
                showWarning(t('budget', 'Please select an account first'));
                return;
            }
            requestBody.accountId = parseInt(accountId);
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import/preview'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(requestBody)
            });

            if (response.ok) {
                const result = await response.json();
                this.processedTransactions = result.transactions;
                this.previewTotalValid = result.validTransactions || result.transactions.length;
                this.updateImportSummary(result);
                const previewTable = document.getElementById('preview-table');
                if (previewTable) previewTable.style.display = '';
                this.showTransactionPreview(result.transactions);
                this.filterPreviewTransactions();
            } else {
                const errorData = await response.json();
                throw new Error(errorData.error || t('budget', 'Processing failed'));
            }
        } catch (error) {
            console.error('Failed to process import data:', error);
            showError(t('budget', 'Failed to process import data: {message}', { message: error.message }));
        }
    }

    updateImportSummary(result) {
        document.getElementById('total-transactions').textContent = result.totalRows || 0;
        document.getElementById('new-transactions').textContent = result.validTransactions || 0;
        document.getElementById('duplicate-transactions').textContent = result.duplicates || 0;
        // Count auto-categorized transactions (from preview sample)
        const previewCategorized = (result.transactions || []).filter(tx => tx.categoryId || tx._categoryName).length;
        const totalValid = result.validTransactions || result.transactions?.length || 0;
        const previewSize = (result.transactions || []).length;
        // If all preview items are categorized and there are more beyond the preview, extrapolate
        const categorized = (previewCategorized === previewSize && totalValid > previewSize)
            ? totalValid  // All sampled rows categorized → likely all are
            : previewCategorized;
        document.getElementById('categorized-transactions').textContent = categorized;

        // Show accounts to create for multi-account preset imports
        const accountsContainer = document.getElementById('accounts-to-create');
        if (result.accountsToCreate && result.accountsToCreate.length > 0) {
            if (!accountsContainer) {
                const summarySection = document.querySelector('.import-summary');
                if (summarySection) {
                    const div = document.createElement('div');
                    div.id = 'accounts-to-create';
                    div.className = 'preset-accounts-info';
                    summarySection.appendChild(div);
                }
            }
            const container = document.getElementById('accounts-to-create');
            if (container) {
                const newAccounts = result.accountsToCreate.filter(a => !a.exists);
                const existingAccounts = result.accountsToCreate.filter(a => a.exists);
                let html = '';
                if (newAccounts.length > 0) {
                    const accountNames = newAccounts.map(a => `${a.name} (${a.type}, ${a.currency})`);
                    html += `<p><strong>${t('budget', 'Accounts to create:')}</strong> ${dom.escapeHtml(accountNames.join(', '))}</p>`;
                }
                if (existingAccounts.length > 0) {
                    const existingNames = existingAccounts.map(a => a.name);
                    html += `<p><strong>${t('budget', 'Existing accounts matched:')}</strong> ${dom.escapeHtml(existingNames.join(', '))}</p>`;
                }
                container.innerHTML = html;
            }
        } else if (accountsContainer) {
            accountsContainer.innerHTML = '';
        }

        // Show categories to create for preset imports
        const categoriesContainer = document.getElementById('categories-to-create');
        if (result.categoriesToCreate && result.categoriesToCreate.length > 0) {
            if (!categoriesContainer) {
                const summarySection = document.querySelector('.import-summary');
                if (summarySection) {
                    const div = document.createElement('div');
                    div.id = 'categories-to-create';
                    div.className = 'preset-categories-info';
                    summarySection.appendChild(div);
                }
            }
            const container = document.getElementById('categories-to-create');
            if (container) {
                const names = result.categoriesToCreate.map(c => c.name);
                container.innerHTML = `<p><strong>${t('budget', 'Categories to create:')}</strong> ${dom.escapeHtml(names.join(', '))}</p>`;
                if (result.skippedByPreset > 0) {
                    container.innerHTML += `<p>${t('budget', '{count} transfer rows will be skipped', { count: result.skippedByPreset })}</p>`;
                }
            }
        } else if (categoriesContainer) {
            categoriesContainer.innerHTML = '';
        }
    }

    showTransactionPreview(transactions) {
        const tbody = document.querySelector('#preview-table tbody');
        if (!tbody) return;

        tbody.innerHTML = '';

        if (!transactions || transactions.length === 0) {
            const row = document.createElement('tr');
            row.innerHTML = `<td colspan="6" style="text-align: center; padding: 20px;">${t('budget', 'No transactions to import')}</td>`;
            tbody.appendChild(row);
            document.getElementById('preview-info').textContent = t('budget', 'No transactions found');
            return;
        }

        transactions.slice(0, 50).forEach((transaction, index) => {
            const row = document.createElement('tr');
            const amount = parseFloat(transaction.amount) || 0;
            const isDuplicate = transaction.isDuplicate || false;
            const statusBadge = isDuplicate
                ? `<span class="status-badge status-error">${t('budget', 'Duplicate')}</span>`
                : `<span class="status-badge status-success">${t('budget', 'New')}</span>`;

            row.innerHTML = `
                <td>
                    <input type="checkbox" ${isDuplicate ? '' : 'checked'} data-row-index="${transaction.rowIndex ?? index}">
                </td>
                <td>${transaction.date || ''}</td>
                <td>${transaction.description || ''}</td>
                <td class="${amount >= 0 ? 'positive' : 'negative'}">
                    ${this.formatCurrency(amount)}
                </td>
                <td>${this.getCategoryLabel(transaction)}</td>
                <td>
                    ${statusBadge}
                </td>
            `;

            tbody.appendChild(row);
        });

        const totalValid = this.previewTotalValid || transactions.length;
        document.getElementById('preview-info').textContent =
            t('budget', 'Showing {shown} of {total}', { shown: Math.min(50, transactions.length), total: totalValid });
    }

    filterPreviewTransactions() {
        const showDuplicates = document.getElementById('show-duplicates')?.checked ?? true;
        const showUncategorized = document.getElementById('show-uncategorized')?.checked ?? true;
        const tbody = document.querySelector('#preview-table tbody');

        if (!tbody) return;

        const rows = tbody.querySelectorAll('tr');
        let visibleCount = 0;

        rows.forEach(row => {
            const statusBadge = row.querySelector('.status-badge');
            const category = row.cells[4]?.textContent?.trim();

            const isDuplicate = statusBadge?.textContent?.trim() === t('budget', 'Duplicate');
            const isUncategorized = category === t('budget', 'Uncategorized');

            let shouldShow = true;

            if (isDuplicate && !showDuplicates) {
                shouldShow = false;
            }

            if (isUncategorized && !showUncategorized) {
                shouldShow = false;
            }

            if (shouldShow) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // Update the preview info text
        const totalCount = rows.length;
        document.getElementById('preview-info').textContent =
            t('budget', 'Showing {shown} of {total}', { shown: visibleCount, total: totalCount });
    }

    async loadAccountsForImport() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/accounts'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            const accounts = await response.json();
            this.availableAccounts = accounts;

            const singleAccountSection = document.getElementById('single-account-selection');
            const multiAccountSection = document.getElementById('multi-account-mapping');

            // Check if preset has accountColumn or manual mapping has account column
            const presetHasAccountColumn = this.selectedPreset && this.presets.find(p => p.id === this.selectedPreset)?.options?.accountColumn;
            const mapping = this.getCurrentMapping();
            const mappingHasAccountColumn = !!(mapping.account);

            if (presetHasAccountColumn || mappingHasAccountColumn) {
                // Hide account selection — accounts come from CSV
                if (singleAccountSection) singleAccountSection.style.display = 'none';
                if (multiAccountSection) multiAccountSection.style.display = 'none';
                // Auto-trigger preview since no account selection needed
                this.processImportData();
            } else if (this.sourceAccounts && this.sourceAccounts.length > 0) {
                // Show multi-account mapping UI
                if (singleAccountSection) singleAccountSection.style.display = 'none';
                if (multiAccountSection) multiAccountSection.style.display = 'block';

                this.renderAccountMappingUI(accounts);
            } else {
                // Show single account selection (for CSV)
                if (singleAccountSection) singleAccountSection.style.display = 'flex';
                if (multiAccountSection) multiAccountSection.style.display = 'none';

                const select = document.getElementById('import-account');
                if (select) {
                    select.innerHTML = `<option value="">${t('budget', 'Select account…')}</option>`;
                    accounts.forEach(account => {
                        const option = document.createElement('option');
                        option.value = account.id;
                        const accountNum = account.accountNumber ? ` - ${account.accountNumber}` : '';
                        option.textContent = `${account.name} (${account.type}${accountNum})`;
                        select.appendChild(option);
                    });
                }
            }
        } catch (error) {
            console.error('Failed to load accounts:', error);
        }
    }

    renderAccountMappingUI(accounts) {
        const container = document.getElementById('account-mapping-list');
        if (!container) return;

        // Show the saved-routing template bar for OFX/QIF.
        this.renderRoutingTemplateBar();

        container.innerHTML = '';

        this.sourceAccounts.forEach(sourceAccount => {
            const row = document.createElement('div');
            row.className = 'account-mapping-row';
            row.dataset.sourceAccountId = sourceAccount.accountId;

            // Build details string
            const details = [];
            if (sourceAccount.type) details.push(sourceAccount.type);
            if (sourceAccount.currency) details.push(sourceAccount.currency);
            if (sourceAccount.transactionCount) details.push(n('budget', '%n transaction', '%n transactions', sourceAccount.transactionCount));
            if (sourceAccount.ledgerBalance !== null && sourceAccount.ledgerBalance !== undefined) {
                details.push(t('budget', 'Balance: {balance}', { balance: this.formatCurrency(sourceAccount.ledgerBalance) }));
            }

            // Build account options HTML with auto-match selection
            const suggestedMatch = sourceAccount.suggestedMatch;
            let optionsHtml = `<option value="">${t('budget', 'Skip this account')}</option>`;
            accounts.forEach(account => {
                const accountNum = account.accountNumber ? ` - ${account.accountNumber}` : '';
                const selected = suggestedMatch === account.id ? ' selected' : '';
                optionsHtml += `<option value="${account.id}"${selected}>${account.name} (${account.type}${accountNum})</option>`;
            });

            row.innerHTML = `
                <div class="source-account-info">
                    <span class="source-account-id">${sourceAccount.accountId}</span>
                    <span class="source-account-details">${details.join(' • ')}</span>
                </div>
                <span class="mapping-arrow">→</span>
                <select class="destination-account-select" data-source-id="${sourceAccount.accountId}">
                    ${optionsHtml}
                </select>
            `;

            container.appendChild(row);
        });

        // Add change listeners to trigger preview
        container.querySelectorAll('.destination-account-select').forEach(select => {
            select.addEventListener('change', () => {
                if (this.hasAnyAccountMapping()) {
                    this.processImportData();
                }
            });
        });

        // Auto-trigger preview if any accounts were auto-matched
        if (this.hasAnyAccountMapping()) {
            this.processImportData();
        }
    }

    hasAnyAccountMapping() {
        const selects = document.querySelectorAll('.destination-account-select');
        return Array.from(selects).some(select => select.value);
    }

    getAccountMapping() {
        const mapping = {};
        document.querySelectorAll('.destination-account-select').forEach(select => {
            if (select.value) {
                mapping[select.dataset.sourceId] = parseInt(select.value);
            }
        });
        return mapping;
    }

    async executeImport() {
        if (!this.currentImportData?.fileId) {
            showError(t('budget', 'No file data available'));
            return;
        }

        const mapping = this.getCurrentMapping();
        const isMultiAccount = this.sourceAccounts && this.sourceAccounts.length > 0;

        // Build request body based on import type
        const requestBody = {
            fileId: this.currentImportData.fileId,
            mapping: mapping,
            skipDuplicates: !(document.getElementById('show-duplicates')?.checked ?? true),
            applyRules: true,
            delimiter: document.getElementById('csv-delimiter')?.value || ','
        };

        // Include preset ID if selected
        if (this.selectedPreset) {
            requestBody.presetId = this.selectedPreset;
        }

        // Include saved template ID if selected (server resolves the mapping/routing)
        if (this.selectedTemplate) {
            requestBody.templateId = this.selectedTemplate;
            // OFX/QIF routing templates carry their own apply-rules option (no UI control).
            const tpl = this.userTemplates.find(t => t.id === this.selectedTemplate);
            if (tpl && typeof tpl.applyRules === 'boolean') {
                requestBody.applyRules = tpl.applyRules;
            }
        }

        // Check if preset has accountColumn or manual mapping has account column
        const presetHasAccountColumn = this.selectedPreset && this.presets.find(p => p.id === this.selectedPreset)?.options?.accountColumn;
        const mappingHasAccountColumn = !!(mapping.account);

        if (isMultiAccount) {
            const accountMapping = this.getAccountMapping();
            if (Object.keys(accountMapping).length === 0) {
                showWarning(t('budget', 'Please map at least one account'));
                return;
            }
            requestBody.accountMapping = accountMapping;
        } else if (!presetHasAccountColumn && !mappingHasAccountColumn) {
            const accountId = document.getElementById('import-account').value;
            if (!accountId) {
                showWarning(t('budget', 'Please select an account'));
                return;
            }
            requestBody.accountId = parseInt(accountId);
        }

        // Show loading state on import button
        const importBtn = document.getElementById('import-btn');
        const originalText = importBtn.textContent;
        importBtn.disabled = true;
        importBtn.textContent = t('budget', 'Importing…');

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import/process'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(requestBody)
            });

            const responseText = await response.text();
            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Server response:', responseText);
                throw new Error(t('budget', 'Server error ({status}): Invalid response', { status: response.status }));
            }

            if (response.ok) {
                showSuccess(t('budget', 'Successfully imported {imported} transactions ({skipped} skipped)', { imported: result.imported, skipped: result.skipped }));
                if (result.categoriesCreated && result.categoriesCreated > 0) {
                    showInfo(n('budget', 'Import complete. %n category created — it may take a moment to appear.', 'Import complete. %n categories created — they may take a moment to appear.', result.categoriesCreated));
                }
                this.resetImportWizard();
                this.loadTransactions();
                this.app.loadAccounts();

                // Auto-match transfers in the background
                this.autoMatchTransfers();
            } else {
                throw new Error(result.error || t('budget', 'Import failed'));
            }
        } catch (error) {
            console.error('Failed to execute import:', error);
            showError(t('budget', 'Failed to import transactions: {message}', { message: error.message }));
        } finally {
            // Restore button state
            importBtn.disabled = false;
            importBtn.textContent = originalText;
        }
    }

    async autoMatchTransfers() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/transactions/bulk-match'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ dateWindow: 3 })
            });

            if (response.ok) {
                const result = await response.json();
                const matched = result.autoMatched?.length || 0;
                if (matched > 0) {
                    showSuccess(t('budget', 'Auto-linked {count} transfer pairs', { count: matched }));
                    this.loadTransactions();
                    this.app.loadAccounts();
                }
            }
        } catch (error) {
            // Silent failure — auto-matching is best-effort
            console.error('Auto-match transfers failed:', error);
        }
    }

    cancelImport() {
        this.resetImportWizard();
    }

    resetImportWizard() {
        this.currentImportStep = 1;
        this.currentImportData = null;
        this.processedTransactions = null;
        this.sourceAccounts = [];
        this.importFormat = null;
        this.selectedPreset = null;
        this.selectedTemplate = null;
        this.previewTotalValid = 0;

        this.setImportStep(1);

        // Reset preset selector
        const presetSelect = document.getElementById('import-preset');
        if (presetSelect) presetSelect.value = '';
        const presetDesc = document.getElementById('preset-description');
        if (presetDesc) presetDesc.style.display = 'none';
        const categoriesContainer = document.getElementById('categories-to-create');
        if (categoriesContainer) categoriesContainer.innerHTML = '';

        // Clear form fields
        document.getElementById('import-file-input').value = '';
        document.querySelectorAll('#import-step-2 select').forEach(select => {
            select.selectedIndex = 0;
        });

        // Reset account selection UI
        const singleAccountSection = document.getElementById('single-account-selection');
        const multiAccountSection = document.getElementById('multi-account-mapping');
        if (singleAccountSection) singleAccountSection.style.display = 'flex';
        if (multiAccountSection) multiAccountSection.style.display = 'none';

        // Clear preview tables
        const mappingPreviewBody = document.querySelector('#mapping-preview-table tbody');
        const previewTableBody = document.querySelector('#preview-table tbody');
        const accountMappingList = document.getElementById('account-mapping-list');
        if (mappingPreviewBody) mappingPreviewBody.innerHTML = '';
        if (previewTableBody) previewTableBody.innerHTML = '';
        if (accountMappingList) accountMappingList.innerHTML = '';
    }

    // Import History Management
    async loadImportHistory() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import/history'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            const history = await response.json();
            this.importHistory = history;
            this.renderImportHistory(history);
        } catch (error) {
            console.error('Failed to load import history:', error);
        }
    }

    renderImportHistory(history) {
        const tbody = document.querySelector('#history-table tbody');
        if (!tbody) return;

        tbody.innerHTML = '';

        history.forEach(item => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${this.formatDate(item.importDate)}</td>
                <td>${item.filename}</td>
                <td>${item.accountName}</td>
                <td>${item.transactionCount}</td>
                <td>
                    <span class="status-badge status-${item.status}">
                        ${item.status.charAt(0).toUpperCase() + item.status.slice(1)}
                    </span>
                </td>
                <td>
                    <button class="icon-download import-download-btn" data-import-id="${item.id}" title="${t('budget', 'Download')}"></button>
                    <button class="icon-delete import-rollback-btn" data-import-id="${item.id}" title="${t('budget', 'Rollback')}"></button>
                </td>
            `;
            tbody.appendChild(row);
        });

        // Setup event listeners
        document.querySelectorAll('.import-download-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const importId = parseInt(btn.dataset.importId);
                this.downloadImport(importId);
            });
        });

        document.querySelectorAll('.import-rollback-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const importId = parseInt(btn.dataset.importId);
                this.rollbackImport(importId);
            });
        });
    }

    formatFileSize(bytes) {
        if (bytes === 0) return t('budget', '0 Bytes');
        const k = 1024;
        const sizes = [t('budget', 'Bytes'), t('budget', 'KB'), t('budget', 'MB'), t('budget', 'GB')];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    async downloadImport(importId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/import/download/${importId}`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `import_${importId}.csv`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            } else {
                throw new Error(t('budget', 'Download failed'));
            }
        } catch (error) {
            console.error('Failed to download import:', error);
            showError(t('budget', 'Failed to download import file'));
        }
    }

    async rollbackImport(importId) {
        if (!confirm(t('budget', 'Are you sure you want to rollback this import? All imported transactions will be deleted.'))) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/import/rollback/${importId}`), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                }
            });

            if (response.ok) {
                const result = await response.json();
                showSuccess(n('budget', 'Rolled back %n transaction', 'Rolled back %n transactions', result.deleted));
                this.loadImportHistory();
                this.loadTransactions();
            } else {
                const errorData = await response.json();
                throw new Error(errorData.error || t('budget', 'Rollback failed'));
            }
        } catch (error) {
            console.error('Failed to rollback import:', error);
            showError(t('budget', 'Failed to rollback import: {message}', { message: error.message }));
        }
    }
}
