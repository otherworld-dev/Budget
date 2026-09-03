/**
 * Import Module - Bank statement import with CSV/OFX/QIF support
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError, showWarning, showInfo } from '../../utils/notifications.js';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { serverErrorMessage, groupImportErrors } from '../../utils/helpers.js';
import { openAccounts } from '../../utils/accounts.js';
import MultiSelect from '../../utils/multiselect.js';

/**
 * The free-text targets. Each can be fed from several source columns at once
 * (the server joins them with ", " in file order — #355), so these four are
 * checklists (MultiSelect) where every other target is a plain <select>.
 *
 * They are also exactly the mapping targets OFX/QIF/camt can resolve (#338):
 * those formats take date, amount and type from the file's own structure and
 * have no per-row account, currency or dual-amount columns, so the other
 * selects are hidden for them rather than left on screen implying an effect
 * they cannot have. A format missing from MAPPABLE_FIELDS maps everything,
 * which is what CSV wants.
 */
const TEXT_FIELDS = ['description', 'notes', 'vendor', 'reference'];

const MAPPABLE_FIELDS = {
    ofx: TEXT_FIELDS,
    qif: TEXT_FIELDS,
    camt: TEXT_FIELDS
};

/**
 * Port of (removed) `ParserFactory::sanitizeHeaders()` — same untranslated
 * "Column N" / "Column N (2)" fallback for blank or duplicate header cells.
 * @param {string[]} rawHeaders
 * @returns {string[]}
 */
function sanitizeHeaders(rawHeaders) {
    const headers = [];
    const seen = new Set();

    rawHeaders.forEach((rawHeader, i) => {
        let candidate = String(rawHeader ?? '').trim();

        if (candidate === '' || seen.has(candidate)) {
            const base = t('budget', 'Column: {number}', { number: i + 1 });
            candidate = base;
            let n = 2;
            while (seen.has(candidate)) {
                candidate = `${base} (${n})`;
                n++;
            }
        }

        seen.add(candidate);
        headers.push(candidate);
    });

    return headers;
}

/**
 * Mapping target -> the select that holds it. One list, so the mapping the UI
 * reads (getCurrentMapping) and the one it writes back from a saved template
 * cannot drift apart.
 */
const MAPPING_SELECT_IDS = {
    date: 'map-date',
    amount: 'map-amount',
    incomeColumn: 'map-income',
    expenseColumn: 'map-expense',
    description: 'map-description',
    notes: 'map-notes',
    type: 'map-type',
    vendor: 'map-vendor',
    reference: 'map-reference',
    category: 'map-category',
    account: 'map-account',
    currency: 'map-currency'
};

/** Select id -> mapping target, the inverse of MAPPING_SELECT_IDS. */
const SELECT_ID_TO_FIELD = Object.fromEntries(
    Object.entries(MAPPING_SELECT_IDS).map(([field, id]) => [id, field])
);

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

        // Multiselect component instances
        this.multiSelects = {};
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
        if (presetGroup) presetGroup.style.display = '';
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
        this.applyColumnMappingToForm(mapping, Object.keys(MAPPING_SELECT_IDS));

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
     * Write a saved column mapping into the step-2 selects.
     *
     * `fields` is the set the mapping is authoritative for — anything in it the
     * mapping does not carry is blanked, so a template can never leave a column
     * from an earlier file selected. An empty mapping is left alone entirely:
     * OFX/QIF templates saved before mappings were stored for them have none,
     * and blanking would wipe the format defaults instead (#340).
     *
     * @param {object} mapping Stored mapping (target -> column name)
     * @param {string[]} fields Mapping targets this call owns
     */
    /**
     * Build the checklist widgets for the text targets, once. Keyed by mapping
     * target; the widget replaces the <select> in the container of that id.
     */
    initMultiSelects() {
        TEXT_FIELDS.forEach(field => {
            const el = document.getElementById(MAPPING_SELECT_IDS[field]);
            if (el && !this.multiSelects[field]) {
                this.multiSelects[field] = new MultiSelect(el, {
                    labelledBy: `${el.id}-label`,
                    onChange: () => this.updatePreviewMapping()
                });
            }
        });
    }

    resetImportMultiSelects() {
        Object.values(this.multiSelects).forEach(multiSelect => multiSelect.setValue([]));
    }

    /**
     * Read a mapping control: the checklist's selection (null when empty,
     * string[] otherwise) for a text target, the <select>'s value for the rest.
     */
    getSelectValue(el) {
        if (!el) return null;
        const multiSelect = this.multiSelects[SELECT_ID_TO_FIELD[el.id]];
        if (multiSelect) {
            const vals = multiSelect.getValue();
            return vals.length > 0 ? vals : null;
        }
        return el.value || null;
    }

    /** Counterpart of getSelectValue: accepts a column name or a list of them. */
    setSelectValue(el, val) {
        if (!el) return;
        const multiSelect = this.multiSelects[SELECT_ID_TO_FIELD[el.id]];
        if (multiSelect) {
            multiSelect.setValue(val);
        } else {
            el.value = val ?? '';
        }
    }

    applyColumnMappingToForm(mapping, fields) {
        if (!mapping || !Object.keys(mapping).length) return;
        const isCsv = this.importFormat === 'csv';

        fields.forEach(field => {
            const el = document.getElementById(MAPPING_SELECT_IDS[field]);
            if (!el) return;
            let value = mapping[field];
            if (isCsv && value !== undefined && value !== null && value !== '') {
                value = Array.isArray(value)
                    ? value.map(v => this.resolveTemplateColumn(v)).filter(v => v !== null)
                    : this.resolveTemplateColumn(value);
            }
            this.setSelectValue(el, value);
        });
    }

    /**
     * Resolve one stored template value back to the live column index this
     * format's selects use: a string is tried as a verbatim header match
     * first, falling back to reading a `Column N` shaped string as index N-1.
     * And a bare number resolves to a 0-indexed column number.
     */
    resolveTemplateColumn(value) {
        if (typeof value === 'number') return String(value);
        if (typeof value !== 'string' || value === '') return null;

        const labels = this.columnLabels || [];
        const index = labels.indexOf(value);
        if (index !== -1) return String(index);

        const legacyMatch = value.match(/^Column (\d+)$/);
        if (legacyMatch) return String(Number(legacyMatch[1]) - 1);

        return null;
    }

    /**
     * Convert the live, always-index CSV mapping into what a template should
     * actually store: the real header name when it exists. Otherwise there is
     * no real header to store — so store the index.
     */
    toTemplateMapping(mapping) {
        if (this.importFormat !== 'csv') return mapping;

        const rawColumns = this.rawColumns || [];
        const labels = this.columnLabels || [];
        const hasHeaderRow = !!mapping.skipFirstRow;

        const columnValue = (value) => {
            const index = Number(value);
            const hasRealHeader = hasHeaderRow && (rawColumns[index] ?? '').trim() !== '';
            return hasRealHeader ? labels[index] : index;
        };

        const result = {};
        Object.entries(mapping).forEach(([field, value]) => {
            if (value === null || value === undefined || value === '' || typeof value === 'boolean') {
                result[field] = value;
            } else if (Array.isArray(value)) {
                result[field] = value.map(columnValue);
            } else {
                result[field] = columnValue(value);
            }
        });
        return result;
    }

    /**
     * Apply a template's cross-format options to the shared controls.
     * The user can still re-toggle them before importing (their value wins).
     */
    applyTemplateOptions(template) {
        const importDuplicates = document.getElementById('import-duplicates');
        if (importDuplicates && typeof template.skipDuplicates === 'boolean') {
            // The control is "import duplicates" — the inverse of "skip duplicates".
            importDuplicates.checked = !template.skipDuplicates;
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
        const skipDuplicates = !(document.getElementById('import-duplicates')?.checked ?? false);
        const requestBody = { name, format, skipDuplicates };

        if (format === 'csv') {
            const mapping = this.toTemplateMapping(this.getCurrentMapping());
            requestBody.mapping = mapping;
            requestBody.delimiter = document.getElementById('csv-delimiter')?.value || ',';
            requestBody.skipFirstRow = !!mapping.skipFirstRow;
            requestBody.applyRules = !!mapping.applyRules;
            const accountId = parseInt(document.getElementById('import-account')?.value, 10);
            if (accountId) requestBody.accountId = accountId;
        } else {
            // OFX/QIF: the reusable payload is the source->destination account routing.
            requestBody.accountMapping = this.getAccountMapping();
            // Since #338 these formats have their own text-target mapping, so
            // carry it along or a non-default Notes/Vendor choice is lost on
            // every later import. The server keeps only the four fields these
            // formats can resolve (#340).
            requestBody.mapping = this.getCurrentMapping();
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
                // A text target may hold several columns; count them all.
                const columnCount = Object.entries(tpl.mapping || {})
                    .filter(([k]) => !['skipFirstRow', 'applyRules'].includes(k))
                    .reduce((sum, [, v]) => sum + (Array.isArray(v) ? v.length : 1), 0);
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

        // Restore the text-target mapping too, before the re-preview below, so
        // the step-2 controls agree with what the server is about to apply.
        this.applyColumnMappingToForm(
            template.mapping || {},
            MAPPABLE_FIELDS[template.format] || Object.keys(MAPPING_SELECT_IDS)
        );

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
        this.initMultiSelects();

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

        this.renderEncodingPicker(uploadResult);

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
        this.applyFormatDefaults(uploadResult.format, uploadResult.columns || []);
        this.applyFormatFieldVisibility(uploadResult.format);

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
        } else {
            // Presets are CSV-only. Without this an OFX/QIF upload that follows
            // a preset-driven CSV import in the same page session inherits a
            // hidden mapping block and a stale bank dropdown (#338).
            this.selectedPreset = null;
            this.selectedTemplate = null;
            // Clear the control too, not just the state: renderPresetSelector
            // restores its selection from this element, so leaving it set would
            // show a later CSV import a preset that is no longer being applied.
            const presetSelect = document.getElementById('import-preset');
            if (presetSelect) presetSelect.value = '';
            const presetGroup = document.getElementById('import-preset-group');
            if (presetGroup) presetGroup.style.display = 'none';
            const mappingContainer = document.querySelector('#import-step-2 .mapping-container');
            const mappingOptions = document.querySelector('#import-step-2 .mapping-options');
            if (mappingContainer) mappingContainer.style.display = '';
            if (mappingOptions) mappingOptions.style.display = '';
        }

        // Move to step 2
        this.setImportStep(2);
    }

    /**
     * Character-encoding picker for the mapping screen (#371).
     *
     * Auto-detection can only get so far: every single-byte encoding accepts
     * every byte, so an undeclared Windows-1251 statement is indistinguishable
     * from a Windows-1252 one and the file has to be asked about. The preview
     * sits right below this, which is what makes the choice checkable — pick
     * wrong and the mojibake is on screen.
     */
    renderEncodingPicker(uploadResult) {
        const group = document.getElementById('import-encoding-options');
        const select = document.getElementById('import-encoding');
        if (!group || !select) return;

        const available = uploadResult.availableEncodings || {};
        const names = Object.keys(available);
        if (names.length === 0) {
            group.style.display = 'none';
            return;
        }

        select.innerHTML = `<option value="">${dom.escapeHtml(t('budget', 'Detect automatically'))}</option>`;
        names.forEach(name => {
            const option = document.createElement('option');
            option.value = name;
            option.textContent = available[name];
            select.appendChild(option);
        });
        select.value = uploadResult.encoding || '';

        const hint = document.getElementById('import-encoding-hint');
        if (hint) {
            hint.textContent = uploadResult.encoding
                ? t('budget', 'Reading this file as {encoding}.', { encoding: available[uploadResult.encoding] || uploadResult.encoding })
                : t('budget', 'Detected {encoding}. Change this if accented or non-Latin characters look wrong in the preview below.', {
                    encoding: available[uploadResult.detectedEncoding] || uploadResult.detectedEncoding || t('budget', 'Unicode (UTF-8)'),
                });
        }

        select.removeEventListener('change', this.handleEncodingChange);
        this.handleEncodingChange = () => this.reloadWithEncoding();
        select.addEventListener('change', this.handleEncodingChange);

        group.style.display = 'block';
    }

    /**
     * Re-read the stored file under the chosen encoding and redraw the
     * mapping screen from it. The upload keeps the original bytes, so this
     * decodes those afresh rather than re-decoding an earlier guess.
     */
    async reloadWithEncoding() {
        const select = document.getElementById('import-encoding');
        if (!select || !this.currentImportData?.fileId) return;

        const encoding = select.value;

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/import/reencode'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken,
                },
                body: JSON.stringify({
                    fileId: this.currentImportData.fileId,
                    fileName: this.currentImportData.filename || '',
                    encoding: encoding,
                }),
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(serverErrorMessage(error, `HTTP ${response.status}`));
            }

            const result = await response.json();
            this.currentImportData = result;
            await this.showImportMapping(result);
        } catch (error) {
            console.error('Failed to re-read file with encoding:', error);
            showError(error.message || t('budget', 'Failed to re-read the file with that encoding'));
            // Put the control back where the data actually is
            select.value = this.currentImportData?.encoding || '';
        }
    }

    reloadColumnsWithDelimiter() {
        const delimiterSelect = document.getElementById('csv-delimiter');
        if (!delimiterSelect) return;

        this.currentDelimiter = delimiterSelect.value;
        showInfo(t('budget', 'Delimiter changed. File will be re-parsed in the next step.'));
    }

    populateColumnMappings(columns) {
        const isCsv = this.importFormat === 'csv';

        // CSV columns are always mapped by index now, whether or not the file
        // has real headers — the label shown is the sanitized header text (or
        // a synthesized "Column N" placeholder), but the value the selects and
        // the live import request carry is the position, never the label.
        this.rawColumns = (columns || []).map(c => String(c));
        this.columnLabels = isCsv ? sanitizeHeaders(this.rawColumns) : this.rawColumns.slice();
        // Kept for highlightMappedColumns, which has to turn a select's value
        // back into its position in the preview table.
        this.previewColumns = isCsv ? this.columnLabels.map((_, i) => String(i)) : this.columnLabels.slice();

        this.initMultiSelects();
        this.resetImportMultiSelects();

        const mappingSelects = {
            'map-date': document.getElementById('map-date'),
            'map-amount': document.getElementById('map-amount'),
            'map-income': document.getElementById('map-income'),
            'map-expense': document.getElementById('map-expense'),
            'map-description': document.getElementById('map-description'),
            'map-notes': document.getElementById('map-notes'),
            'map-type': document.getElementById('map-type'),
            'map-vendor': document.getElementById('map-vendor'),
            'map-reference': document.getElementById('map-reference'),
            'map-category': document.getElementById('map-category'),
            'map-account': document.getElementById('map-account'),
            'map-currency': document.getElementById('map-currency')
        };

        // Options: for CSV, value is the column index and label is the
        // sanitized header text; for other formats, value and label are both
        // the format's own fixed field name, as before.
        const options = isCsv
            ? this.columnLabels.map((label, i) => ({ value: String(i), label }))
            : this.columnLabels;

        // Clear existing options and add columns
        Object.entries(mappingSelects).forEach(([id, select]) => {
            if (!select) return;
            const multiSelect = this.multiSelects[SELECT_ID_TO_FIELD[id]];
            if (multiSelect) {
                multiSelect.setOptions(options);
            } else {
                const firstOption = select.firstElementChild;
                select.innerHTML = '';
                if (firstOption) select.appendChild(firstOption);

                options.forEach(opt => {
                    const option = document.createElement('option');
                    if (isCsv) {
                        option.value = opt.value;
                        option.textContent = opt.label;
                    } else {
                        option.value = opt;
                        option.textContent = opt;
                    }
                    select.appendChild(option);
                });
            }
        });

        // Auto-detect common column mappings
        this.autoDetectMappings(this.columnLabels, mappingSelects, isCsv);
    }

    autoDetectMappings(columns, mappingSelects, isCsv = this.importFormat === 'csv') {
        const patterns = {
            'map-date': ['date', 'transaction date', 'trans date', 'posting date'],
            'map-amount': ['amount', 'transaction amount', 'trans amount', 'value'],
            'map-income': ['income', 'credit', 'deposits', 'deposit', 'credits', 'receipts'],
            'map-expense': ['expense', 'debit', 'withdrawals', 'withdrawal', 'debits', 'payments', 'payment'],
            'map-description': ['description', 'memo', 'details', 'transaction details'],
            // Deliberately not 'memo': for CSV that column is usually already
            // the description. OFX/QIF get memo->notes from applyFormatDefaults.
            'map-notes': ['notes', 'note'],
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

            const matchIndex = columns.findIndex(col =>
                patternList.some(pattern =>
                    col.toLowerCase().includes(pattern.toLowerCase())
                )
            );
            if (matchIndex === -1) return;

            this.setSelectValue(select, isCsv ? String(matchIndex) : columns[matchIndex]);
        });
    }

    /**
     * Preselect the source each field actually uses today, for formats whose
     * columns are fixed. Without this the OFX memo would stay unmapped and
     * keep being discarded (#338).
     */
    applyFormatDefaults(format, columns) {
        if (!['ofx', 'qif', 'camt'].includes(format)) return;

        const defaults = {
            'map-description': 'description',
            'map-notes': 'memo',
            'map-reference': 'reference'
        };
        // camt carries the counterparty separately — it is the natural vendor
        if (format === 'camt') {
            defaults['map-vendor'] = 'name';
        }

        Object.entries(defaults).forEach(([fieldId, column]) => {
            const select = document.getElementById(fieldId);
            if (select && columns.includes(column)) {
                this.setSelectValue(select, column);
            }
        });
    }

    /**
     * Show only the mapping fields the current format can resolve.
     *
     * Hidden selects are cleared so a value left over from an earlier CSV
     * import in the same page session cannot change the import silently — a
     * stale income/expense column fails the amount check, and a stale account
     * column reroutes the whole file down the single-account path. Date and
     * amount keep their values because validateMappingStep still requires them.
     */
    applyFormatFieldVisibility(format) {
        const step2 = document.getElementById('import-step-2');
        if (!step2) return;

        const mappable = MAPPABLE_FIELDS[format] || null;

        step2.querySelectorAll('[data-map-field]').forEach(wrapper => {
            const field = wrapper.dataset.mapField;
            const visible = !mappable || mappable.includes(field);

            wrapper.style.display = visible ? '' : 'none';

            if (visible || field === 'date' || field === 'amount') return;

            wrapper.querySelectorAll('select').forEach(select => {
                select.value = '';
            });
            wrapper.querySelectorAll('.custom-multiselect').forEach(msEl => {
                this.setSelectValue(msEl, null);
            });
            wrapper.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
            });
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

        // CSV header labels come from populateColumnMappings' sanitized list
        // (blank/duplicate cells already resolved); other formats still take
        // their fixed column names straight from the preview's own header row.
        const isCsv = this.importFormat === 'csv';
        const headerLabels = isCsv ? (this.columnLabels || previewData[0].map(h => String(h)))
            : previewData[0].map(header => String(header));
        if (!isCsv) {
        // The rendered header row is what highlightMappedColumns indexes into,
        // so take the column order from it rather than from the upload response.
            this.previewColumns = headerLabels;
        }

        const headerRow = document.createElement('tr');
        headerLabels.forEach((header, index) => {
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
            description: this.getSelectValue(document.getElementById('map-description')),
            notes: this.getSelectValue(document.getElementById('map-notes')),
            type: document.getElementById('map-type')?.value || null,
            vendor: this.getSelectValue(document.getElementById('map-vendor')),
            reference: this.getSelectValue(document.getElementById('map-reference')),
            category: document.getElementById('map-category')?.value || null,
            account: document.getElementById('map-account')?.value || null,
            currency: document.getElementById('map-currency')?.value || null,
            skipFirstRow: document.getElementById('skip-first-row')?.checked || false,
            applyRules: document.getElementById('apply-rules')?.checked || false
        };
    }

    highlightMappedColumns(mapping) {
        const table = document.getElementById('mapping-preview-table');
        if (!table) return;
        const headers = Array.from(table.querySelectorAll('th'));

        // Reset highlighting
        headers.forEach(th => th.classList.remove('mapped-column'));

        // A select's value is the column NAME, not its index — parseInt on a
        // name is NaN, so headers[NaN] was always undefined and this highlight
        // never fired for any ordinary CSV header.
        const columns = this.previewColumns || [];
        Object.values(mapping).forEach(column => {
            if (!column) return;
            const colList = Array.isArray(column) ? column : [column];
            colList.forEach(col => {
                if (typeof col !== 'string' || col === '') return;
                const header = headers[columns.indexOf(col)];
                if (header) header.classList.add('mapped-column');
            });
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

        // Check required fields: date and description (the latter is a
        // checklist: null when nothing is ticked, a non-empty list otherwise)
        const hasDate = mapping.date !== null && mapping.date !== '';
        const hasDescription = Array.isArray(mapping.description) && mapping.description.length > 0;

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

        // Build request body based on import type. The preview always includes
        // duplicates (flagged) so the user can see what would be skipped —
        // whether they import is decided at execute time (#327).
        const requestBody = {
            fileId: this.currentImportData.fileId,
            mapping: mapping,
            skipDuplicates: false,
            delimiter: document.getElementById('csv-delimiter')?.value || ',',
            // Whatever the mapping screen was decoded with must be what gets
            // parsed and imported, or the preview lies about the result (#371)
            encoding: this.currentImportData.encoding || null
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
        } else {
            // Account column mapped: the chosen account is the fallback for
            // rows whose cell is blank, and optional (#333)
            const fallbackId = document.getElementById('import-account')?.value;
            if (fallbackId) requestBody.accountId = parseInt(fallbackId);
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
        // The preview includes flagged duplicates in validTransactions, so
        // "new" is what remains once they are taken out
        document.getElementById('new-transactions').textContent =
            Math.max(0, (result.validTransactions || 0) - (result.duplicates || 0));
        document.getElementById('duplicate-transactions').textContent = result.duplicates || 0;
        // Auto-categorized count. Prefer the server's full-dataset count; the
        // transactions array is only a 50-row preview sample, so counting it
        // (with the old all-or-nothing extrapolation) was wrong for larger
        // imports (#285 audit).
        let categorized;
        if (typeof result.categorizedCount === 'number') {
            categorized = result.categorizedCount;
        } else {
            // Fallback for any preview path without the server field
            const previewCategorized = (result.transactions || []).filter(tx => tx.categoryId || tx._categoryName).length;
            const totalValid = result.validTransactions || result.transactions?.length || 0;
            const previewSize = (result.transactions || []).length;
            categorized = (previewCategorized === previewSize && totalValid > previewSize)
                ? totalValid
                : previewCategorized;
        }
        document.getElementById('categorized-transactions').textContent = categorized;

        this.renderDirectionWarnings(result.directionWarnings);

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

    /**
     * Say so up front when a batch is about to land on the opposite side from
     * everything already in the account — the usual cause is an unmapped or
     * ignored type column, which is invisible until the balances drift (#333).
     */
    renderDirectionWarnings(warnings) {
        let container = document.getElementById('import-direction-warnings');

        if (!warnings || warnings.length === 0) {
            if (container) container.innerHTML = '';
            return;
        }

        if (!container) {
            const summarySection = document.querySelector('.import-summary');
            if (!summarySection) return;
            container = document.createElement('div');
            container.id = 'import-direction-warnings';
            // Ahead of the stats: this changes whether you should import at all.
            summarySection.prepend(container);
        }

        container.innerHTML = warnings.map(warning => {
            const account = warning.accountName || t('budget', 'this account');
            let headline;
            let context;

            if (warning.kind === 'unresolved-type') {
                headline = t('budget', '{matching} of {total} rows have no usable transaction type', {
                    matching: warning.matching,
                    total: warning.total,
                });
                context = t('budget', 'The type column is empty or unrecognized on those rows, so they will be added based on whether the amount is negative. Fill the type in, or check the mapping, if that is not what you want.');
            } else if (warning.type === 'credit') {
                headline = t('budget', '{matching} of {total} rows would be added as income', {
                    matching: warning.matching,
                    total: warning.total,
                });
                context = t('budget', 'but {percent}% of what is already in {account} is an expense. If that looks wrong, go back and map the column holding the transaction type before importing.', {
                    percent: warning.existingOppositePercent,
                    account: account,
                });
            } else {
                headline = t('budget', '{matching} of {total} rows would be added as an expense', {
                    matching: warning.matching,
                    total: warning.total,
                });
                context = t('budget', 'but {percent}% of what is already in {account} is income. If that looks wrong, go back and map the column holding the transaction type before importing.', {
                    percent: warning.existingOppositePercent,
                    account: account,
                });
            }

            return `<div class="import-direction-warning">
                <span class="icon-error" aria-hidden="true"></span>
                <div>
                    <strong>${dom.escapeHtml(headline)}</strong>
                    <p>${dom.escapeHtml(context)}</p>
                </div>
            </div>`;
        }).join('');
    }

    showTransactionPreview(transactions) {
        const tbody = document.querySelector('#preview-table tbody');
        if (!tbody) return;

        tbody.innerHTML = '';

        // The notes column earns its space only when something is mapped into
        // it — otherwise the step whose job is "check this before it is
        // written" gains an empty column for everyone (#340).
        const rows = (transactions || []).slice(0, 50);
        const showNotes = rows.some(transaction => (transaction.notes ?? '') !== '');
        const notesHeader = document.getElementById('preview-th-notes');
        if (notesHeader) notesHeader.style.display = showNotes ? '' : 'none';

        if (!transactions || transactions.length === 0) {
            const row = document.createElement('tr');
            row.innerHTML = `<td colspan="6" style="text-align: center; padding: 20px;">${t('budget', 'No transactions to import')}</td>`;
            tbody.appendChild(row);
            document.getElementById('preview-info').textContent = t('budget', 'No transactions found');
            return;
        }

        rows.forEach((transaction) => {
            const row = document.createElement('tr');
            // Every amount arrives unsigned with the direction in `type`, so
            // reading the sign off the number showed an expense as income —
            // a file of nothing but "-91,29" previewed as all positive (#339).
            const magnitude = Math.abs(parseFloat(transaction.amount) || 0);
            const amount = transaction.type === 'debit' ? -magnitude : magnitude;
            const isDuplicate = transaction.isDuplicate || false;
            const statusBadge = isDuplicate
                ? `<span class="status-badge status-error">${t('budget', 'Duplicate')}</span>`
                : `<span class="status-badge status-success">${t('budget', 'New')}</span>`;

            const notes = transaction.notes ?? '';
            row.innerHTML = `
                <td>${dom.escapeHtml(transaction.date || '')}</td>
                <td>${dom.escapeHtml(transaction.description || '')}</td>
                <td class="preview-notes"${showNotes ? '' : ' style="display: none;"'} title="${dom.escapeHtml(notes)}">${dom.escapeHtml(notes)}</td>
                <td class="${amount < 0 ? 'negative' : 'positive'}">
                    ${this.formatCurrency(amount)}
                </td>
                <td data-preview-cell="category">${dom.escapeHtml(this.getCategoryLabel(transaction))}</td>
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
            // By cell name, not position: the notes column shifts the indexes.
            const category = row.querySelector('[data-preview-cell="category"]')?.textContent?.trim();

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

    /**
     * Fill the destination-account select and label it for its role: the
     * account everything goes to, or — when the file carries its own account
     * column — only the account for rows whose account cell is blank (#333).
     *
     * @param {Array} accounts - As the accounts API returns them
     * @param {boolean} asFallback - True when an account column is mapped
     */
    populateImportAccountSelect(accounts, asFallback) {
        const select = document.getElementById('import-account');
        if (!select) return;
        const current = select.value;
        select.innerHTML = `<option value="">${asFallback ? t('budget', 'Skip rows without an account') : t('budget', 'Select account…')}</option>`;
        openAccounts(accounts).forEach(account => {
            const option = document.createElement('option');
            option.value = account.id;
            const accountNum = account.accountNumber ? ` - ${account.accountNumber}` : '';
            option.textContent = `${account.name} (${account.type}${accountNum})`;
            select.appendChild(option);
        });
        if ([...select.options].some(o => o.value === current)) {
            select.value = current;
        }
        const label = document.querySelector('label[for="import-account"]');
        if (label) {
            label.textContent = asFallback
                ? t('budget', 'Account for rows without one:')
                : t('budget', 'Import to Account:');
        }
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
                // Accounts come from the file, but a row whose account cell is
                // blank still needs somewhere to go: the server files it under
                // the account chosen here, and drops it with a reason when
                // none is. This select used to be hidden outright in this
                // case, so that fallback could never be reached (#333).
                if (multiAccountSection) multiAccountSection.style.display = 'none';
                if (singleAccountSection) singleAccountSection.style.display = 'flex';
                this.populateImportAccountSelect(accounts, true);
                // Preview straight away; choosing a fallback re-runs it
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
                this.populateImportAccountSelect(accounts, false);
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
            openAccounts(accounts).forEach(account => {
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

    /**
     * Show why rows did not import, grouped by reason.
     *
     * One file usually fails one way, so fourteen identical messages are one
     * line with the row numbers behind it rather than fourteen lines.
     *
     * @param {Array<{row: number|string, error: string}>} errors - From the import API
     */
    showImportErrors(errors) {
        document.getElementById('import-errors-modal')?.remove();

        const total = errors.length;
        const groups = groupImportErrors(errors);
        const rowsLabel = (rows) => {
            if (rows.length === 0) {
                return '';
            }
            const shown = rows.slice(0, 12).join(', ');
            return rows.length > 12
                ? t('budget', 'Rows {rows} and {count} more', { rows: shown, count: rows.length - 12 })
                : t('budget', 'Rows {rows}', { rows: shown });
        };

        const modal = document.createElement('div');
        modal.id = 'import-errors-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `
            <div class="budget-modal" style="max-width: 640px;">
                <div class="budget-modal-header">
                    <h2>${n('budget', '%n row was not imported', '%n rows were not imported', total)}</h2>
                    <button class="close-btn" title="${t('budget', 'Close')}">&times;</button>
                </div>
                <div class="budget-modal-body">
                    <ul style="margin: 0; padding-left: 1.2em;">
                        ${groups.map(group => `
                            <li style="margin-bottom: 0.6em;">
                                <div>${dom.escapeHtml(group.message)}</div>
                                <div style="opacity: 0.7; font-size: 0.9em;">${dom.escapeHtml(rowsLabel(group.rows))}</div>
                            </li>
                        `).join('')}
                    </ul>
                </div>
                <div class="budget-modal-footer">
                    <button class="confirm-btn primary">${t('budget', 'Close')}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        const close = () => modal.remove();
        modal.querySelector('.close-btn').addEventListener('click', close);
        modal.querySelector('.confirm-btn').addEventListener('click', close);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                close();
            }
        });
    }

    async executeImport() {
        if (!this.currentImportData?.fileId) {
            showError(t('budget', 'No file data available'));
            return;
        }

        const mapping = this.getCurrentMapping();
        const isMultiAccount = this.sourceAccounts && this.sourceAccounts.length > 0;

        // Build request body based on import type. Duplicates are skipped
        // unless the user explicitly opted in — "Show duplicates" is only a
        // display filter and must not decide what gets imported (#327).
        const requestBody = {
            fileId: this.currentImportData.fileId,
            mapping: mapping,
            skipDuplicates: !(document.getElementById('import-duplicates')?.checked ?? false),
            applyRules: true,
            delimiter: document.getElementById('csv-delimiter')?.value || ',',
            // Whatever the mapping screen was decoded with must be what gets
            // parsed and imported, or the preview lies about the result (#371)
            encoding: this.currentImportData.encoding || null
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
        } else {
            // Account column mapped: optional fallback for blank cells (#333)
            const fallbackId = document.getElementById('import-account')?.value;
            if (fallbackId) requestBody.accountId = parseInt(fallbackId);
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
                if (result.billsMarkedPaid > 0) {
                    showSuccess(n(
                        'budget',
                        '%n bill was automatically marked as paid from matching transactions',
                        '%n bills were automatically marked as paid from matching transactions',
                        result.billsMarkedPaid
                    ));
                }
                if (result.errors && result.errors.length > 0) {
                    // Partial failure (e.g. a mapped destination account was
                    // deleted) — must not masquerade as a full success. The
                    // server sends a reason per row and this used to throw
                    // them away and point at nextcloud.log, which in #333 was
                    // a log the reporter could not read: 14 of 27 rows dropped
                    // and no way to find out why.
                    this.showImportErrors(result.errors);
                    console.warn('Import errors:', result.errors);
                }
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

        // The encoding picker belongs to one file — hide it and drop its
        // options, or the next upload starts out claiming the last one's
        const encodingGroup = document.getElementById('import-encoding-options');
        if (encodingGroup) encodingGroup.style.display = 'none';
        const encodingSelect = document.getElementById('import-encoding');
        if (encodingSelect) encodingSelect.value = '';
        const categoriesContainer = document.getElementById('categories-to-create');
        if (categoriesContainer) categoriesContainer.innerHTML = '';

        // Clear form fields
        document.getElementById('import-file-input').value = '';
        document.querySelectorAll('#import-step-2 select').forEach(select => {
            select.selectedIndex = 0;
        });

        // Restore the full field set, or the next CSV import inherits the
        // reduced OFX/QIF one (#338)
        this.applyFormatFieldVisibility(null);

        // Importing duplicates is a per-import opt-in — never carry it over
        const importDuplicates = document.getElementById('import-duplicates');
        if (importDuplicates) importDuplicates.checked = false;

        // A header row is the default assumption for a fresh, template-less upload
        const skipFirstRow = document.getElementById('skip-first-row');
        if (skipFirstRow) skipFirstRow.checked = true;

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
