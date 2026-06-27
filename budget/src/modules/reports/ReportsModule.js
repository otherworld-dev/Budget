/**
 * Reports Module - Financial reporting and analysis
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import Chart from 'chart.js/auto';
import { showSuccess, showError, showWarning } from '../../utils/notifications.js';
import { setDateValue } from '../../utils/datepicker.js';
import { translate as t, translatePlural as n } from '@nextcloud/l10n';

export default class ReportsModule {
    constructor(app) {
        this.app = app;
        this.reportCharts = {};
        this.reportEventListenersSetup = false;
        this.yoyControlsSetup = false;
        this._yoyGenerated = false;
    }

    // Getters for app state
    get accounts() {
        return this.app.accounts;
    }

    get settings() {
        return this.app.settings;
    }

    get allTagSetsForReports() {
        return this.app.allTagSetsForReports;
    }

    set allTagSetsForReports(value) {
        this.app.allTagSetsForReports = value;
    }

    get categories() {
        return this.app.categories;
    }

    // Helper method delegations
    formatCurrency(amount, currency = null) {
        return formatters.formatCurrency(amount, currency, this.settings);
    }

    formatCurrencyCompact(amount, currency = null) {
        return formatters.formatCurrencyCompact(amount, currency, this.settings);
    }

    getPrimaryCurrency() {
        return formatters.getPrimaryCurrency(this.accounts, this.settings);
    }

    escapeHtml(text) {
        return dom.escapeHtml(text);
    }
    setupReportEventListeners() {
        // Period preset change
        const presetSelect = document.getElementById('report-period-preset');
        if (presetSelect) {
            presetSelect.addEventListener('change', (e) => {
                const customRange = document.getElementById('custom-date-range');
                if (customRange) {
                    customRange.style.display = e.target.value === 'custom' ? 'flex' : 'none';
                }
                if (e.target.value !== 'custom') {
                    this.setReportDateRange(e.target.value);
                }
            });
        }

        // Generate report button
        const generateBtn = document.getElementById('generate-report-btn');
        if (generateBtn) {
            generateBtn.addEventListener('click', () => this.generateReport());
        }

        // Report type change
        const typeSelect = document.getElementById('report-type');
        if (typeSelect) {
            typeSelect.addEventListener('change', () => this.generateReport());
        }

        // Account multi-select (checklist) — wires its own change handlers (#299)
        this.setupReportAccountMultiselect();

        // Saved reports (#299)
        this.setupSavedReports();

        // Export buttons
        document.getElementById('export-csv-btn')?.addEventListener('click', () => this.exportReport('csv'));
        document.getElementById('export-pdf-btn')?.addEventListener('click', () => this.exportReport('pdf'));

        // Category-by-month report: sort toggle (alphabetical / by total)
        const cmSort = document.getElementById('category-monthly-sort');
        if (cmSort) {
            cmSort.addEventListener('change', (e) => {
                this.categoryMonthlySort = e.target.value;
                this.generateReport();
            });
        }

        // "Exclude shared accounts" toggle — shown only when the user has shared
        // accounts (see syncExcludeSharedToggle); drives the whole reports page.
        const exclShared = document.getElementById('report-exclude-shared');
        if (exclShared) {
            exclShared.addEventListener('change', (e) => {
                this.excludeShared = e.target.checked;
                this.generateReport();
            });
        }

        // Initialize charts object for reports
        this.reportCharts = {};
    }

    setReportDateRange(preset) {
        const now = new Date();
        let startDate, endDate;

        switch (preset) {
            case 'this-month':
                startDate = new Date(now.getFullYear(), now.getMonth(), 1);
                endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                break;
            case 'last-3-months':
                startDate = new Date(now.getFullYear(), now.getMonth() - 2, 1);
                endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                break;
            case 'ytd':
                startDate = new Date(now.getFullYear(), 0, 1);
                endDate = now;
                break;
            case 'last-year':
                startDate = new Date(now.getFullYear() - 1, 0, 1);
                endDate = new Date(now.getFullYear() - 1, 11, 31);
                break;
            default:
                startDate = new Date(now.getFullYear(), now.getMonth() - 2, 1);
                endDate = now;
        }

        setDateValue('report-start-date', formatters.formatDateForAPI(startDate));
        setDateValue('report-end-date', formatters.formatDateForAPI(endDate));
    }

    async loadReportsView() {
        // Setup event listeners on first load
        if (!this.reportEventListenersSetup) {
            this.setupReportEventListeners();
            this.reportEventListenersSetup = true;
        }

        // Populate account dropdown
        this.populateReportAccountDropdown();

        // Load saved reports list
        await this.loadSavedReports();

        // Load and populate tags dropdown
        await this.loadAllTagsForReports();

        // Reflect the period selector's current value, which persists the user's
        // last choice across reopening the tab. Show the custom-range inputs when
        // a custom range is selected; otherwise generateReport() derives the dates
        // from the preset so the report matches what the selector shows (#300).
        const customRange = document.getElementById('custom-date-range');
        if (customRange) {
            const preset = document.getElementById('report-period-preset')?.value;
            customRange.style.display = preset === 'custom' ? 'flex' : 'none';
        }

        // Generate initial report
        await this.generateReport();
    }

    /** Selected report account IDs (empty = all accounts). #299 */
    getSelectedReportAccountIds() {
        return this.selectedReportAccounts ? Array.from(this.selectedReportAccounts) : [];
    }

    /** Append the selected account IDs to a URLSearchParams as accountIds[]. */
    appendAccountIds(params) {
        this.getSelectedReportAccountIds().forEach(id => params.append('accountIds[]', id));
        if (this.excludeShared) params.append('excludeShared', '1');
    }

    /** Build a query-string fragment (&accountIds[]=...) for the selected accounts. */
    accountIdsQuery() {
        const ids = this.getSelectedReportAccountIds().map(id => `&accountIds[]=${id}`).join('');
        return ids + (this.excludeShared ? '&excludeShared=1' : '');
    }

    setupReportAccountMultiselect() {
        const wrapper = document.getElementById('report-account-multiselect');
        const toggle = document.getElementById('report-account-toggle');
        const menu = document.getElementById('report-account-menu');
        if (!wrapper || !toggle || !menu) return;

        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = menu.style.display !== 'none';
            menu.style.display = open ? 'none' : 'block';
            toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
        });
        document.addEventListener('click', (e) => {
            if (!wrapper.contains(e.target)) {
                menu.style.display = 'none';
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        const allCb = document.getElementById('report-account-all');
        if (allCb) {
            allCb.addEventListener('change', () => {
                if (allCb.checked) {
                    this.selectedReportAccounts.clear();
                    document.querySelectorAll('#report-account-options input[type="checkbox"]')
                        .forEach(cb => { cb.checked = false; });
                    this.updateReportAccountSummary();
                    this.generateReport();
                } else {
                    // "All" can't be turned off directly — re-check it.
                    allCb.checked = true;
                }
            });
        }
    }

    populateReportAccountDropdown() {
        this.selectedReportAccounts = this.selectedReportAccounts || new Set();
        const container = document.getElementById('report-account-options');
        if (!container) return;

        // Drop selected ids that no longer exist
        this.selectedReportAccounts.forEach(id => {
            if (!this.accounts?.some(a => a.id === id)) this.selectedReportAccounts.delete(id);
        });

        container.innerHTML = '';
        if (Array.isArray(this.accounts)) {
            this.accounts.forEach(account => {
                const label = document.createElement('label');
                label.className = 'account-multiselect-option';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.value = account.id;
                cb.checked = this.selectedReportAccounts.has(account.id);
                cb.addEventListener('change', () => this.onReportAccountToggle(cb, account.id));
                const span = document.createElement('span');
                span.textContent = account.name;
                label.appendChild(cb);
                label.appendChild(span);
                container.appendChild(label);
            });
        }
        const allCb = document.getElementById('report-account-all');
        if (allCb) allCb.checked = this.selectedReportAccounts.size === 0;
        this.updateReportAccountSummary();
        this.syncExcludeSharedToggle();
    }

    /**
     * Show the "Exclude shared accounts" toggle only when the user actually has
     * shared accounts, and keep the checkbox in step with this.excludeShared.
     */
    syncExcludeSharedToggle() {
        const hasShared = Array.isArray(this.accounts) && this.accounts.some(a => a._shared);
        this.excludeShared = hasShared ? !!this.excludeShared : false;
        const wrap = document.getElementById('report-exclude-shared-wrap');
        if (wrap) wrap.style.display = hasShared ? '' : 'none';
        const cb = document.getElementById('report-exclude-shared');
        if (cb) cb.checked = this.excludeShared;
    }

    onReportAccountToggle(cb, accountId) {
        if (cb.checked) {
            this.selectedReportAccounts.add(accountId);
        } else {
            this.selectedReportAccounts.delete(accountId);
        }
        const allCb = document.getElementById('report-account-all');
        if (allCb) allCb.checked = this.selectedReportAccounts.size === 0;
        this.updateReportAccountSummary();
        this.generateReport();
    }

    updateReportAccountSummary() {
        const summary = document.getElementById('report-account-summary');
        if (!summary) return;
        const ids = this.getSelectedReportAccountIds();
        if (ids.length === 0) {
            summary.textContent = t('budget', 'All Accounts');
        } else if (ids.length === 1) {
            const acc = this.accounts?.find(a => a.id === ids[0]);
            summary.textContent = acc ? acc.name : t('budget', '1 account');
        } else {
            summary.textContent = t('budget', '{count} accounts', { count: ids.length });
        }
    }

    // ===== Saved reports (#299) =====

    setupSavedReports() {
        const select = document.getElementById('report-saved-select');
        const saveBtn = document.getElementById('report-save-btn');
        const delBtn = document.getElementById('report-delete-saved-btn');
        if (select && !select.dataset.wired) {
            select.dataset.wired = '1';
            select.addEventListener('change', () => this.onSavedReportSelect());
        }
        if (saveBtn && !saveBtn.dataset.wired) {
            saveBtn.dataset.wired = '1';
            saveBtn.addEventListener('click', () => this.saveCurrentReport());
        }
        if (delBtn && !delBtn.dataset.wired) {
            delBtn.dataset.wired = '1';
            delBtn.addEventListener('click', () => this.deleteSavedReport());
        }
    }

    async loadSavedReports() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/reports/saved'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) return;
            this.savedReports = await response.json();
            this.renderSavedReportsSelect();
        } catch (e) {
            console.error('Failed to load saved reports:', e);
        }
    }

    renderSavedReportsSelect(selectedId = '') {
        const select = document.getElementById('report-saved-select');
        if (!select) return;
        const reports = Array.isArray(this.savedReports) ? this.savedReports : [];
        select.innerHTML = `<option value="">${t('budget', 'Saved reports…')}</option>`;
        reports.forEach(r => {
            const opt = document.createElement('option');
            opt.value = r.id;
            opt.textContent = r.name;
            select.appendChild(opt);
        });
        select.value = selectedId ? String(selectedId) : '';
        const delBtn = document.getElementById('report-delete-saved-btn');
        if (delBtn) delBtn.disabled = !select.value;
    }

    /** Capture the current report filter state for saving. */
    getReportConfig() {
        const preset = document.getElementById('report-period-preset')?.value || 'last-3-months';
        const config = {
            reportType: document.getElementById('report-type')?.value || 'summary',
            periodPreset: preset,
            accountIds: this.getSelectedReportAccountIds(),
            tagIds: this.selectedReportTags ? Array.from(this.selectedReportTags) : [],
            includeUntagged: document.getElementById('report-include-untagged')?.checked || false,
            yoyComparisonType: document.getElementById('yoy-comparison-type')?.value || 'years',
            yoyYears: document.getElementById('yoy-years')?.value || '3',
            yoyMonth: document.getElementById('yoy-month')?.value || '',
            categoryMonthlySort: this.categoryMonthlySort || 'alpha',
            excludeShared: !!this.excludeShared,
        };
        if (preset === 'custom') {
            config.startDate = document.getElementById('report-start-date')?.value || '';
            config.endDate = document.getElementById('report-end-date')?.value || '';
        }
        return config;
    }

    /** Apply a saved config to the controls, then run the report. */
    applyReportConfig(config) {
        if (!config) return;
        const setVal = (id, val) => { const el = document.getElementById(id); if (el && val != null) el.value = val; };

        setVal('report-type', config.reportType);
        // NB: don't dispatch a synthetic 'change' here — its only listener is
        // generateReport(), which would fire with stale scope before excludeShared
        // and account selection are restored below. The generateReport() at the end
        // regenerates once all state is in place.

        const preset = config.periodPreset || 'last-3-months';
        setVal('report-period-preset', preset);
        const customRange = document.getElementById('custom-date-range');
        if (preset === 'custom') {
            if (customRange) customRange.style.display = '';
            if (config.startDate) setDateValue('report-start-date', config.startDate);
            if (config.endDate) setDateValue('report-end-date', config.endDate);
        } else {
            if (customRange) customRange.style.display = 'none';
            this.setReportDateRange(preset);
        }

        // Restore account selection (then re-render the checklist)
        this.selectedReportAccounts = new Set(Array.isArray(config.accountIds) ? config.accountIds : []);
        this.excludeShared = !!config.excludeShared; // checkbox synced by populateReportAccountDropdown
        this.populateReportAccountDropdown();

        // Restore tags
        this.selectedReportTags = new Set(Array.isArray(config.tagIds) ? config.tagIds : []);
        this.populateReportTagsDropdown();
        const inc = document.getElementById('report-include-untagged');
        if (inc) inc.checked = !!config.includeUntagged;

        // Report-specific options
        setVal('yoy-comparison-type', config.yoyComparisonType);
        setVal('yoy-years', config.yoyYears);
        if (config.yoyMonth) setVal('yoy-month', config.yoyMonth);
        if (config.categoryMonthlySort) {
            this.categoryMonthlySort = config.categoryMonthlySort;
            setVal('category-monthly-sort', config.categoryMonthlySort);
        }

        this.generateReport();
    }

    async saveCurrentReport() {
        const name = window.prompt(t('budget', 'Name this report:'));
        if (name === null) return;
        if (!name.trim()) {
            showWarning(t('budget', 'Please enter a name for the report'));
            return;
        }
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/reports/saved'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'requesttoken': OC.requestToken },
                body: JSON.stringify({ name: name.trim(), config: this.getReportConfig() })
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || t('budget', 'Failed to save report'));
            showSuccess(t('budget', 'Report saved'));
            await this.loadSavedReports();
            this.renderSavedReportsSelect(data.id);
        } catch (e) {
            showError(e.message || t('budget', 'Failed to save report'));
        }
    }

    onSavedReportSelect() {
        const select = document.getElementById('report-saved-select');
        const delBtn = document.getElementById('report-delete-saved-btn');
        if (delBtn) delBtn.disabled = !select?.value;
        if (!select?.value) return;
        const report = (this.savedReports || []).find(r => String(r.id) === select.value);
        if (report) this.applyReportConfig(report.config);
    }

    async deleteSavedReport() {
        const select = document.getElementById('report-saved-select');
        const id = select?.value;
        if (!id) return;
        const report = (this.savedReports || []).find(r => String(r.id) === id);
        if (!confirm(t('budget', 'Delete saved report "{name}"?', { name: report?.name || '' }))) return;
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/reports/saved/${id}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            showSuccess(t('budget', 'Saved report deleted'));
            await this.loadSavedReports();
        } catch (e) {
            showError(t('budget', 'Failed to delete saved report'));
        }
    }

    async loadAllTagsForReports() {
        try {
            const [tagSetsResponse, globalTagsResponse] = await Promise.all([
                fetch(OC.generateUrl('/apps/budget/api/tag-sets'), { headers: { 'requesttoken': OC.requestToken } }),
                fetch(OC.generateUrl('/apps/budget/api/tags/global'), { headers: { 'requesttoken': OC.requestToken } })
            ]);

            if (tagSetsResponse.ok) {
                this.allTagSetsForReports = await tagSetsResponse.json();
            }

            if (globalTagsResponse.ok) {
                const globalTags = await globalTagsResponse.json();
                if (globalTags.length > 0) {
                    this.allTagSetsForReports = this.allTagSetsForReports || [];
                    this.allTagSetsForReports.unshift({
                        id: 'global',
                        name: t('budget', 'Tags'),
                        tags: globalTags
                    });
                }
            }

            this.populateReportTagsDropdown();
        } catch (error) {
            console.error('Failed to load tags for reports:', error);
        }
    }

    populateReportTagsDropdown() {
        const container = document.getElementById('report-tags-filter');
        if (!container) return;

        // Remove previous document-level close listener to prevent stacking
        if (this._closeTagDropdown) {
            document.removeEventListener('click', this._closeTagDropdown);
            this._closeTagDropdown = null;
        }

        container.innerHTML = '';

        if (!this.allTagSetsForReports || this.allTagSetsForReports.length === 0) {
            container.innerHTML = `<div style="padding: 8px; color: var(--color-text-lighter); font-style: italic;">${t('budget', 'No tags available')}</div>`;
            return;
        }

        // Track selected tags
        this.selectedReportTags = this.selectedReportTags || new Set();

        // Create input field
        const input = document.createElement('input');
        input.type = 'text';
        input.id = 'report-tags-input';
        input.className = 'tags-autocomplete-input';
        input.placeholder = t('budget', 'Type to filter tags...');

        // Create dropdown
        const dropdown = document.createElement('div');
        dropdown.className = 'tags-autocomplete-dropdown';
        dropdown.style.display = 'none';

        container.appendChild(input);
        container.appendChild(dropdown);

        // Build flat list of all tags
        const allTags = [];
        this.allTagSetsForReports.forEach(tagSet => {
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

        // Render dropdown function
        const renderDropdown = (filter = '') => {
            const filtered = filter
                ? allTags.filter(tg =>
                    tg.name.toLowerCase().includes(filter.toLowerCase()) ||
                    tg.tagSetName.toLowerCase().includes(filter.toLowerCase())
                )
                : allTags;

            // Group by tag set
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
                    const isSelected = this.selectedReportTags.has(tag.id);
                    html += `
                        <div class="tags-autocomplete-item ${isSelected ? 'selected' : ''}"
                             data-tag-id="${tag.id}">
                            <span class="tag-chip"
                                  style="display: inline-flex; align-items: center; background-color: ${this.escapeHtml(tag.color || '#888')}; color: white;
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

        // Event listeners
        input.addEventListener('focus', () => renderDropdown(input.value));
        input.addEventListener('input', () => renderDropdown(input.value));

        // Handle tag selection on mousedown (Safari/WebKit may suppress click
        // events on non-interactive elements inside absolutely positioned containers
        // when mousedown default is prevented, so we handle selection here instead)
        dropdown.addEventListener('mousedown', (e) => {
            e.preventDefault();
            e.stopPropagation();

            const item = e.target.closest('.tags-autocomplete-item');
            if (item) {
                const tagId = parseInt(item.dataset.tagId);
                const clickedTag = allTags.find(tg => tg.id === tagId);
                if (!clickedTag) return;

                // Remove other tags from same tag set (single selection per set)
                const tagsFromSameSet = allTags.filter(tg => tg.tagSetId === clickedTag.tagSetId);
                tagsFromSameSet.forEach(tg => {
                    if (tg.id !== tagId) {
                        this.selectedReportTags.delete(tg.id);
                    }
                });

                // Toggle selection
                if (this.selectedReportTags.has(tagId)) {
                    this.selectedReportTags.delete(tagId);
                } else {
                    this.selectedReportTags.add(tagId);
                }

                renderDropdown(input.value);
            }
        });

        // Prevent clicks inside dropdown from closing it via document handler
        dropdown.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        // Close dropdown when clicking outside
        this._closeTagDropdown = (e) => {
            if (!container.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        };

        document.addEventListener('click', this._closeTagDropdown);
    }

    async generateReport() {
        const reportType = document.getElementById('report-type')?.value || 'summary';

        // Keep the date range in step with the period selector so the report
        // always matches the shown period (the selector persists the user's last
        // choice on reopen); a custom range keeps its own dates (#300).
        const periodPreset = document.getElementById('report-period-preset')?.value || 'last-3-months';
        if (periodPreset !== 'custom') {
            this.setReportDateRange(periodPreset);
        }

        const startDate = document.getElementById('report-start-date')?.value;
        const endDate = document.getElementById('report-end-date')?.value;

        // Get selected tags from dropdown state
        const selectedTags = this.selectedReportTags ? Array.from(this.selectedReportTags) : [];

        // Get include untagged checkbox
        const includeUntaggedCheckbox = document.getElementById('report-include-untagged');
        const includeUntagged = includeUntaggedCheckbox ? includeUntaggedCheckbox.checked : true;

        // Show loading
        const loadingEl = document.getElementById('report-loading');
        if (loadingEl) loadingEl.style.display = 'flex';

        // Hide all report sections
        document.querySelectorAll('.report-section').forEach(el => el.style.display = 'none');

        try {
            const params = new URLSearchParams({
                startDate,
                endDate
            });
            // Multi-select account scope (#299)
            this.appendAccountIds(params);

            // Add tag filters if any tags are selected
            if (selectedTags.length > 0) {
                selectedTags.forEach(tagId => {
                    params.append('tagIds[]', tagId);
                });
                // Only add includeUntagged if tags are selected (otherwise it's irrelevant)
                params.append('includeUntagged', includeUntagged.toString());
            }

            switch (reportType) {
                case 'summary':
                    await this.generateSummaryReport(params);
                    break;
                case 'spending':
                    await this.generateSpendingReport(params);
                    break;
                case 'cashflow':
                    await this.generateCashFlowReport(params);
                    break;
                case 'yoy':
                    this.showYoYReport();
                    // Re-run the comparison so control changes (date range,
                    // accounts, exclude-shared) re-scope an already-shown YoY
                    // report; first selection still waits for the Compare click.
                    if (this._yoyGenerated) this.generateYoYComparison();
                    break;
                case 'bills-calendar':
                    this.showBillsCalendarReport();
                    break;
                case 'category-monthly':
                    await this.generateCategoryMonthlyReport(params);
                    break;
            }
        } catch (error) {
            console.error('Failed to generate report:', error);
            showError(t('budget', 'Failed to generate report'));
        } finally {
            if (loadingEl) loadingEl.style.display = 'none';
        }
    }

    async generateSummaryReport(params) {
        const response = await fetch(
            OC.generateUrl(`/apps/budget/api/reports/summary-comparison?${params}`),
            { headers: { 'requesttoken': OC.requestToken } }
        );

        if (!response.ok) throw new Error('Failed to fetch summary');
        const data = await response.json();

        const section = document.getElementById('report-summary');
        if (section) section.style.display = 'block';

        const currency = this.getPrimaryCurrency();
        const totals = data.totals || {};
        const comparison = data.comparison?.changes || {};

        // Update summary cards
        this.updateReportCard('report-total-income', totals.totalIncome, currency, 'report-income-change', comparison.income);
        this.updateReportCard('report-total-expenses', totals.totalExpenses, currency, 'report-expenses-change', comparison.expenses);
        this.updateReportCard('report-net-income', totals.netIncome, currency, 'report-net-change', comparison.netIncome);

        // Savings rate
        const savingsRateEl = document.getElementById('report-savings-rate');
        if (savingsRateEl && totals.totalIncome > 0) {
            const rate = ((totals.netIncome / totals.totalIncome) * 100).toFixed(1);
            savingsRateEl.textContent = `${rate}%`;
        } else if (savingsRateEl) {
            savingsRateEl.textContent = '--';
        }

        // Render trend chart
        this.renderReportTrendChart(data.trends);

        // Render accounts table
        this.renderReportAccountsTable(data.accounts || [], currency);
    }

    updateReportCard(valueId, value, currency, changeId, change) {
        const valueEl = document.getElementById(valueId);
        if (valueEl) {
            valueEl.textContent = this.formatCurrency(value || 0, currency);
        }

        const changeEl = document.getElementById(changeId);
        if (changeEl && change) {
            const arrow = change.direction === 'up' ? '↑' : change.direction === 'down' ? '↓' : '';
            const colorClass = change.direction === 'up' ? 'positive' : change.direction === 'down' ? 'negative' : '';
            changeEl.innerHTML = `${arrow} ${t('budget', '{percentage}% vs prior period', { percentage: change.percentage })}`;
            changeEl.className = `summary-change ${colorClass}`;
        } else if (changeEl) {
            changeEl.innerHTML = '';
        }
    }

    renderReportTrendChart(trends) {
        const canvas = document.getElementById('report-trend-chart');
        if (!canvas || !trends) return;

        if (this.reportCharts.trend) {
            this.reportCharts.trend.destroy();
        }

        const ctx = canvas.getContext('2d');
        const currency = this.getPrimaryCurrency();

        this.reportCharts.trend = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: trends.labels || [],
                datasets: [
                    {
                        label: t('budget', 'Income'),
                        data: trends.income || [],
                        backgroundColor: 'rgba(46, 125, 50, 0.7)',
                        borderColor: 'rgba(46, 125, 50, 1)',
                        borderWidth: 1
                    },
                    {
                        label: t('budget', 'Expenses'),
                        data: trends.expenses || [],
                        backgroundColor: 'rgba(198, 40, 40, 0.7)',
                        borderColor: 'rgba(198, 40, 40, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${this.formatCurrency(context.raw, currency)}`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrencyCompact(value, currency)
                        }
                    }
                }
            }
        });
    }

    renderReportAccountsTable(accounts, currency) {
        const tbody = document.querySelector('#report-accounts-table tbody');
        if (!tbody) return;

        tbody.innerHTML = accounts.map(account => {
            // Each account reports its amounts in its own currency (the backend
            // keeps native values per account); fall back to the report currency.
            const accountCurrency = account.currency || currency;
            return `
            <tr>
                <td>${this.escapeHtml(account.name)}</td>
                <td class="text-right positive">${this.formatCurrency(account.income || 0, accountCurrency)}</td>
                <td class="text-right negative">${this.formatCurrency(account.expenses || 0, accountCurrency)}</td>
                <td class="text-right ${(account.net || 0) >= 0 ? 'positive' : 'negative'}">${this.formatCurrency(account.net || 0, accountCurrency)}</td>
                <td class="text-right">${this.formatCurrency(account.balance || 0, accountCurrency)}</td>
            </tr>
        `;
        }).join('');
    }

    async generateSpendingReport(params) {
        const [categoryResponse, vendorResponse] = await Promise.all([
            fetch(OC.generateUrl(`/apps/budget/api/reports/spending?${params}&groupBy=category`), {
                headers: { 'requesttoken': OC.requestToken }
            }),
            fetch(OC.generateUrl(`/apps/budget/api/reports/spending?${params}&groupBy=vendor`), {
                headers: { 'requesttoken': OC.requestToken }
            })
        ]);

        const categoryData = await categoryResponse.json();
        const vendorData = await vendorResponse.json();

        const section = document.getElementById('report-spending');
        if (section) section.style.display = 'block';

        const currency = this.getPrimaryCurrency();
        const totalSpending = categoryData.totals?.amount || 0;

        // Render category chart
        this.renderReportSpendingChart(categoryData.data || [], totalSpending);

        // Render category table
        this.renderReportCategoryTable(categoryData.data || [], totalSpending, currency);

        // Render vendor table
        this.renderReportVendorTable(vendorData.data || [], currency);
    }

    renderReportSpendingChart(data, totalSpending) {
        const canvas = document.getElementById('report-spending-chart');
        if (!canvas) return;

        if (this.reportCharts.spending) {
            this.reportCharts.spending.destroy();
        }

        const ctx = canvas.getContext('2d');
        const sortedData = [...data].sort((a, b) => b.total - a.total).slice(0, 10);

        const defaultColors = [
            '#4CAF50', '#2196F3', '#FF9800', '#9C27B0', '#F44336',
            '#00BCD4', '#FFEB3B', '#795548', '#607D8B', '#E91E63'
        ];
        const colors = sortedData.map((d, i) => d.color || defaultColors[i % defaultColors.length]);

        this.reportCharts.spending = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: sortedData.map(d => d.name),
                datasets: [{
                    data: sortedData.map(d => d.total),
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = context.raw;
                                const pct = totalSpending > 0 ? ((value / totalSpending) * 100).toFixed(1) : 0;
                                return `${context.label}: ${this.formatCurrency(value)} (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });

        // Render custom legend
        this.renderSpendingLegend(sortedData, totalSpending, 'report-spending-legend');
    }

    renderSpendingLegend(data, totalSpending, containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        const defaultColors = [
            '#4CAF50', '#2196F3', '#FF9800', '#9C27B0', '#F44336',
            '#00BCD4', '#FFEB3B', '#795548', '#607D8B', '#E91E63'
        ];

        container.innerHTML = data.slice(0, 8).map((item, i) => {
            const pct = totalSpending > 0 ? ((item.total / totalSpending) * 100).toFixed(1) : 0;
            const color = item.color || defaultColors[i % defaultColors.length];
            return `
                <div class="spending-legend-item">
                    <span class="spending-legend-color" style="background: ${color}"></span>
                    <span class="spending-legend-name">${item.name}</span>
                    <span class="spending-legend-value">${this.formatCurrency(item.total)}</span>
                    <span class="spending-legend-pct">${pct}%</span>
                </div>
            `;
        }).join('');
    }

    renderReportCategoryTable(data, totalSpending, currency) {
        const tbody = document.querySelector('#report-categories-table tbody');
        if (!tbody) return;

        const sortedData = [...data].sort((a, b) => b.total - a.total);

        tbody.innerHTML = sortedData.map(cat => {
            const pct = totalSpending > 0 ? ((cat.total / totalSpending) * 100).toFixed(1) : 0;
            return `
                <tr>
                    <td>
                        <span class="category-color" style="background: ${cat.color || '#888'}"></span>
                        ${cat.name}
                    </td>
                    <td class="text-right">${this.formatCurrency(cat.total, currency)}</td>
                    <td class="text-right">${pct}%</td>
                    <td class="text-right">${cat.count}</td>
                </tr>
            `;
        }).join('');
    }

    renderReportVendorTable(data, currency) {
        const tbody = document.querySelector('#report-vendors-table tbody');
        if (!tbody) return;

        tbody.innerHTML = data.map(vendor => `
            <tr>
                <td>${vendor.name}</td>
                <td class="text-right">${this.formatCurrency(vendor.total, currency)}</td>
                <td class="text-right">${vendor.count}</td>
            </tr>
        `).join('');
    }

    async generateCashFlowReport(params) {
        const response = await fetch(
            OC.generateUrl(`/apps/budget/api/reports/cashflow?${params}`),
            { headers: { 'requesttoken': OC.requestToken } }
        );

        if (!response.ok) throw new Error('Failed to fetch cash flow');
        const data = await response.json();

        const section = document.getElementById('report-cashflow');
        if (section) section.style.display = 'block';

        const currency = this.getPrimaryCurrency();
        const averages = data.averageMonthly || {};

        // Update average cards
        const avgIncomeEl = document.getElementById('report-avg-income');
        const avgExpensesEl = document.getElementById('report-avg-expenses');
        const avgNetEl = document.getElementById('report-avg-net');

        if (avgIncomeEl) avgIncomeEl.textContent = this.formatCurrency(averages.income || 0, currency);
        if (avgExpensesEl) avgExpensesEl.textContent = this.formatCurrency(averages.expenses || 0, currency);
        if (avgNetEl) avgNetEl.textContent = this.formatCurrency(averages.net || 0, currency);

        // Render chart
        this.renderCashFlowChart(data.data || []);

        // Render table
        this.renderCashFlowTable(data.data || [], currency);
    }

    async generateCategoryMonthlyReport(params) {
        const sort = this.categoryMonthlySort || 'alpha';
        const response = await fetch(
            OC.generateUrl(`/apps/budget/api/reports/categories/monthly?${params}&sort=${sort}`),
            { headers: { 'requesttoken': OC.requestToken } }
        );

        if (!response.ok) throw new Error('Failed to fetch category monthly report');
        const data = await response.json();

        const section = document.getElementById('report-category-monthly');
        if (section) section.style.display = 'block';

        // Keep the sort control in sync with the active sort
        const sortSel = document.getElementById('category-monthly-sort');
        if (sortSel && sortSel.value !== sort) sortSel.value = sort;

        this.renderCategoryMonthlyMatrix(data);
    }

    renderCategoryMonthlyMatrix(data) {
        const container = document.getElementById('category-monthly-table');
        if (!container) return;

        const months = data.period?.months || [];
        const currency = data.baseCurrency || this.getPrimaryCurrency();
        const rows = data.rows || [];

        if (rows.length === 0 || months.length === 0) {
            container.innerHTML = `<div class="empty-state"><p>${t('budget', 'No data for the selected period.')}</p></div>`;
            return;
        }

        const monthLabel = (m) => {
            const d = new Date(`${m}-01T00:00:00`);
            return isNaN(d.getTime()) ? m : d.toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
        };
        const cell = (v) => {
            const val = v || 0;
            if (val === 0) return '<td class="text-right cm-zero">—</td>';
            const cls = val < 0 ? 'negative' : 'positive';
            return `<td class="text-right ${cls}">${this.escapeHtml(this.formatCurrency(val, currency))}</td>`;
        };

        const headCells = months.map(m => `<th class="text-right">${this.escapeHtml(monthLabel(m))}</th>`).join('');
        const bodyRows = rows.map(row => {
            const indent = (row.depth || 0) * 16;
            const monthCells = months.map(m => cell(row.monthly?.[m])).join('');
            return `<tr class="${row.isParent ? 'cm-parent' : ''}">
                <td class="cm-name" style="padding-left:${8 + indent}px">${this.escapeHtml(row.name || '')}</td>
                ${monthCells}
                ${cell(row.total)}
            </tr>`;
        }).join('');

        const totals = data.totals || {};
        const totalCells = months.map(m => cell(totals.monthly?.[m])).join('');
        const totalRow = `<tr class="cm-total">
            <td class="cm-name">${t('budget', 'Net total')}</td>
            ${totalCells}
            ${cell(totals.total)}
        </tr>`;

        const notice = data.mixedCurrency
            ? `<div class="report-hint cm-currency-notice">${this.escapeHtml(t('budget', 'You have accounts in more than one currency. Amounts are summed in their original currency without conversion.'))}</div>`
            : '';

        container.innerHTML = `
            ${notice}
            <div class="cm-table-scroll">
                <table class="report-table category-monthly-matrix">
                    <thead>
                        <tr>
                            <th class="cm-name">${t('budget', 'Category')}</th>
                            ${headCells}
                            <th class="text-right">${t('budget', 'Overall')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${bodyRows}
                        ${totalRow}
                    </tbody>
                </table>
            </div>`;
    }

    renderCashFlowChart(data) {
        const canvas = document.getElementById('report-cashflow-chart');
        if (!canvas) return;

        if (this.reportCharts.cashflow) {
            this.reportCharts.cashflow.destroy();
        }

        const ctx = canvas.getContext('2d');
        const currency = this.getPrimaryCurrency();

        this.reportCharts.cashflow = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.map(d => this.formatReportMonthLabel(d.month)),
                datasets: [
                    {
                        label: t('budget', 'Income'),
                        data: data.map(d => d.income),
                        backgroundColor: 'rgba(46, 125, 50, 0.7)',
                        borderColor: 'rgba(46, 125, 50, 1)',
                        borderWidth: 1,
                        order: 2
                    },
                    {
                        label: t('budget', 'Expenses'),
                        data: data.map(d => d.expenses),
                        backgroundColor: 'rgba(198, 40, 40, 0.7)',
                        borderColor: 'rgba(198, 40, 40, 1)',
                        borderWidth: 1,
                        order: 2
                    },
                    {
                        label: t('budget', 'Net Cash Flow'),
                        data: data.map(d => d.net),
                        borderColor: 'rgba(33, 150, 243, 1)',
                        backgroundColor: 'rgba(33, 150, 243, 0.1)',
                        borderWidth: 2,
                        type: 'line',
                        tension: 0.3,
                        pointRadius: 4,
                        fill: true,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${this.formatCurrency(context.raw, currency)}`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrencyCompact(value, currency)
                        }
                    }
                }
            }
        });
    }

    renderCashFlowTable(data, currency) {
        const tbody = document.querySelector('#report-cashflow-table tbody');
        if (!tbody) return;

        let cumulative = 0;
        tbody.innerHTML = data.map(row => {
            cumulative += row.net;
            return `
                <tr>
                    <td>${this.formatReportMonthLabel(row.month)}</td>
                    <td class="text-right positive">${this.formatCurrency(row.income, currency)}</td>
                    <td class="text-right negative">${this.formatCurrency(row.expenses, currency)}</td>
                    <td class="text-right ${row.net >= 0 ? 'positive' : 'negative'}">${this.formatCurrency(row.net, currency)}</td>
                    <td class="text-right ${cumulative >= 0 ? 'positive' : 'negative'}">${this.formatCurrency(cumulative, currency)}</td>
                </tr>
            `;
        }).join('');
    }

    formatReportMonthLabel(yearMonth) {
        if (!yearMonth) return '';
        const [year, month] = yearMonth.split('-');
        const date = new Date(year, month - 1);
        return date.toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
    }

    // ==========================================
    // Year over Year Report Functions
    // ==========================================

    showYoYReport() {
        const section = document.getElementById('report-yoy');
        if (section) section.style.display = 'block';

        // Setup YoY controls if not already done
        if (!this.yoyControlsSetup) {
            this.setupYoYControls();
            this.yoyControlsSetup = true;

            // Default the month to the current month, but only on first setup so a
            // user's chosen month isn't reset when the view is re-shown/re-fetched.
            const monthSelect = document.getElementById('yoy-month');
            if (monthSelect) {
                monthSelect.value = new Date().getMonth() + 1;
            }
        }
    }

    setupYoYControls() {
        const comparisonType = document.getElementById('yoy-comparison-type');
        const generateBtn = document.getElementById('generate-yoy-btn');
        const monthSelect = document.querySelector('.yoy-month-select');

        if (comparisonType) {
            comparisonType.addEventListener('change', (e) => {
                if (monthSelect) {
                    monthSelect.style.display = e.target.value === 'month' ? '' : 'none';
                }
            });
        }

        if (generateBtn) {
            generateBtn.addEventListener('click', () => this.generateYoYComparison());
        }
    }

    async generateYoYComparison() {
        this._yoyGenerated = true;
        const comparisonType = document.getElementById('yoy-comparison-type')?.value || 'years';
        const years = document.getElementById('yoy-years')?.value || 3;
        const month = document.getElementById('yoy-month')?.value || new Date().getMonth() + 1;

        // Show loading
        const loadingEl = document.getElementById('report-loading');
        if (loadingEl) loadingEl.style.display = 'flex';

        // Hide all YoY sections
        document.getElementById('yoy-summary')?.style.setProperty('display', 'none');
        document.getElementById('yoy-chart-container')?.style.setProperty('display', 'none');
        document.getElementById('yoy-category-table-container')?.style.setProperty('display', 'none');

        try {
            const accountParam = this.accountIdsQuery(); // multi-select account scope (#299)
            let endpoint;
            switch (comparisonType) {
                case 'month':
                    endpoint = `/apps/budget/api/yoy/month?month=${month}&years=${years}${accountParam}`;
                    break;
                case 'categories':
                    endpoint = `/apps/budget/api/yoy/categories?years=${years}${accountParam}`;
                    break;
                default:
                    endpoint = `/apps/budget/api/yoy/years?years=${years}${accountParam}`;
            }

            const response = await fetch(OC.generateUrl(endpoint), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error('Failed to fetch YoY data');
            const data = await response.json();

            if (comparisonType === 'categories') {
                this.displayYoYCategories(data);
            } else {
                this.displayYoYComparison(data);
            }

        } catch (error) {
            console.error('Failed to generate YoY comparison:', error);
            showError(t('budget', 'Failed to generate comparison'));
        } finally {
            if (loadingEl) loadingEl.style.display = 'none';
        }
    }

    displayYoYComparison(data) {
        const currency = this.getPrimaryCurrency();
        const summaryEl = document.getElementById('yoy-summary');
        const cardsEl = document.getElementById('yoy-year-cards');
        const chartContainer = document.getElementById('yoy-chart-container');
        const chartTitle = document.getElementById('yoy-chart-title');

        if (!summaryEl || !cardsEl) return;

        summaryEl.style.display = '';
        if (chartContainer) chartContainer.style.display = '';

        // Build year cards
        cardsEl.innerHTML = data.years.map(year => {
            const changeHtml = year.incomeChange !== undefined ? `
                <div class="yoy-change-indicators">
                    <span class="yoy-change ${year.incomeChange >= 0 ? 'positive' : 'negative'}">
                        ${t('budget', 'Income')}: ${year.incomeChange >= 0 ? '+' : ''}${year.incomeChange?.toFixed(1) || '0'}%
                    </span>
                    <span class="yoy-change ${year.expenseChange <= 0 ? 'positive' : 'negative'}">
                        ${t('budget', 'Expenses')}: ${year.expenseChange >= 0 ? '+' : ''}${year.expenseChange?.toFixed(1) || '0'}%
                    </span>
                </div>
            ` : '';

            return `
                <div class="yoy-year-card ${year.isCurrent ? 'current-year' : ''}">
                    <div class="yoy-year-header">
                        <span class="yoy-year-label">${year.year}${year.isCurrent ? ` (${t('budget', 'YTD')})` : ''}</span>
                        ${year.monthName ? `<span class="yoy-month-label">${year.monthName}</span>` : ''}
                    </div>
                    <div class="yoy-year-stats">
                        <div class="yoy-stat">
                            <span class="yoy-stat-label">${t('budget', 'Income')}</span>
                            <span class="yoy-stat-value positive">${this.formatCurrency(year.income, currency)}</span>
                        </div>
                        <div class="yoy-stat">
                            <span class="yoy-stat-label">${t('budget', 'Expenses')}</span>
                            <span class="yoy-stat-value negative">${this.formatCurrency(year.expenses, currency)}</span>
                        </div>
                        <div class="yoy-stat">
                            <span class="yoy-stat-label">${t('budget', 'Savings')}</span>
                            <span class="yoy-stat-value ${year.savings >= 0 ? 'positive' : 'negative'}">${this.formatCurrency(year.savings, currency)}</span>
                        </div>
                    </div>
                    ${changeHtml}
                </div>
            `;
        }).join('');

        // Update chart title
        if (chartTitle) {
            chartTitle.textContent = data.type === 'month'
                ? t('budget', '{monthName} - Year over Year Comparison', { monthName: data.monthName })
                : t('budget', 'Annual Income & Expenses');
        }

        // Render chart
        this.renderYoYChart(data.years);
    }

    renderYoYChart(years) {
        const canvas = document.getElementById('yoy-chart');
        if (!canvas) return;

        if (this.reportCharts.yoy) {
            this.reportCharts.yoy.destroy();
        }

        const ctx = canvas.getContext('2d');
        const currency = this.getPrimaryCurrency();

        this.reportCharts.yoy = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: years.map(y => y.year.toString()),
                datasets: [
                    {
                        label: t('budget', 'Income'),
                        data: years.map(y => y.income),
                        backgroundColor: 'rgba(46, 125, 50, 0.7)',
                        borderColor: 'rgba(46, 125, 50, 1)',
                        borderWidth: 1
                    },
                    {
                        label: t('budget', 'Expenses'),
                        data: years.map(y => y.expenses),
                        backgroundColor: 'rgba(198, 40, 40, 0.7)',
                        borderColor: 'rgba(198, 40, 40, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                return `${context.dataset.label}: ${this.formatCurrency(context.raw, currency)}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrencyCompact(value, currency)
                        }
                    }
                }
            }
        });
    }

    displayYoYCategories(data) {
        const tableContainer = document.getElementById('yoy-category-table-container');
        const headerRow = document.getElementById('yoy-category-header');
        const tbody = document.getElementById('yoy-category-body');

        if (!tableContainer || !headerRow || !tbody) return;

        tableContainer.style.display = '';
        const currency = this.getPrimaryCurrency();

        // Build header with year columns
        const years = data.categories[0]?.years || [];
        headerRow.innerHTML = `<th>${t('budget', 'Category')}</th>` +
            years.map(y => `<th class="text-right">${y.year}</th>`).join('') +
            `<th class="text-right">${t('budget', 'Change')}</th>`;

        // Build table rows
        tbody.innerHTML = data.categories.map(cat => {
            const changeHtml = cat.change !== null
                ? `<span class="${cat.change <= 0 ? 'positive' : 'negative'}">${cat.change >= 0 ? '+' : ''}${cat.change.toFixed(1)}%</span>`
                : t('budget', 'N/A');

            return `
                <tr>
                    <td>${this.escapeHtml(cat.name)}</td>
                    ${cat.years.map(y => `<td class="text-right">${this.formatCurrency(y.spending, currency)}</td>`).join('')}
                    <td class="text-right">${changeHtml}</td>
                </tr>
            `;
        }).join('');
    }

    async exportReport(format) {
        const reportType = document.getElementById('report-type')?.value || 'summary';

        try {
            let response;

            if (reportType === 'yoy') {
                response = await this.exportYoYReport(format);
            } else if (reportType === 'bills-calendar') {
                response = await this.exportBillsCalendarReport(format);
            } else {
                response = await this.exportStandardReport(format, reportType);
            }

            if (!response.ok) throw new Error('Export failed');

            const blob = await response.blob();
            const filename = `${reportType}_report_${formatters.getTodayDateString()}.${format}`;

            // Trigger download
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);

            showSuccess(t('budget', 'Report exported as {format}', { format: format.toUpperCase() }));
        } catch (error) {
            console.error('Export failed:', error);
            showError(t('budget', 'Failed to export report'));
        }
    }

    async exportStandardReport(format, reportType) {
        const startDate = document.getElementById('report-start-date')?.value;
        const endDate = document.getElementById('report-end-date')?.value;

        return fetch(OC.generateUrl('/apps/budget/api/reports/export'), {
            method: 'POST',
            headers: {
                'requesttoken': OC.requestToken,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                type: reportType,
                format,
                startDate,
                endDate,
                accountIds: this.getSelectedReportAccountIds(), // multi-select scope (#299)
                excludeShared: !!this.excludeShared
            })
        });
    }

    async exportYoYReport(format) {
        const comparisonType = document.getElementById('yoy-comparison-type')?.value || 'years';
        const years = document.getElementById('yoy-years')?.value || 3;
        const month = document.getElementById('yoy-month')?.value || new Date().getMonth() + 1;

        return fetch(OC.generateUrl('/apps/budget/api/yoy/export'), {
            method: 'POST',
            headers: {
                'requesttoken': OC.requestToken,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                comparisonType,
                format,
                years: parseInt(years),
                month: parseInt(month),
                accountIds: this.getSelectedReportAccountIds(), // multi-select scope (#299)
                excludeShared: !!this.excludeShared
            })
        });
    }

    async exportBillsCalendarReport(format) {
        const year = document.getElementById('bills-calendar-year')?.value || new Date().getFullYear();
        const billStatus = document.getElementById('bills-calendar-status')?.value || 'active';
        const includeTransfers = document.getElementById('bills-calendar-include-transfers')?.checked || false;
        const accountId = document.getElementById('bills-calendar-account')?.value || '';

        return fetch(OC.generateUrl('/apps/budget/api/bills/export-calendar'), {
            method: 'POST',
            headers: {
                'requesttoken': OC.requestToken,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                format,
                year: parseInt(year),
                billStatus,
                includeTransfers: includeTransfers.toString(),
                ...(accountId && { accountId: parseInt(accountId) })
            })
        });
    }

    // ==========================================
    // Bills Calendar Report Functions
    // ==========================================

    showBillsCalendarReport() {
        const section = document.getElementById('report-bills-calendar');
        if (section) section.style.display = 'block';

        // Setup controls if not already done
        if (!this.billsCalendarControlsSetup) {
            this.setupBillsCalendarControls();
            this.billsCalendarControlsSetup = true;
        }

        // Populate year dropdown
        this.populateBillsCalendarYears();

        // Populate account dropdown
        this.populateBillsCalendarAccountDropdown();

        // Auto-generate on first load (like other reports)
        this.generateBillsCalendar();
    }

    populateBillsCalendarAccountDropdown() {
        const dropdown = document.getElementById('bills-calendar-account');
        if (!dropdown) return;

        dropdown.innerHTML = '';
        const allOption = document.createElement('option');
        allOption.value = '';
        allOption.textContent = t('budget', 'All Accounts');
        dropdown.appendChild(allOption);

        if (Array.isArray(this.accounts)) {
            this.accounts.forEach(account => {
                const option = document.createElement('option');
                option.value = account.id;
                option.textContent = account.name;
                dropdown.appendChild(option);
            });
        }
    }

    setupBillsCalendarControls() {
        const generateBtn = document.getElementById('generate-bills-calendar-btn');
        const viewSelect = document.getElementById('bills-calendar-view');
        const accountSelect = document.getElementById('bills-calendar-account');
        const yearSelect = document.getElementById('bills-calendar-year');
        const statusSelect = document.getElementById('bills-calendar-status');
        const transfersCheckbox = document.getElementById('bills-calendar-include-transfers');

        if (generateBtn) {
            generateBtn.addEventListener('click', () => this.generateBillsCalendar());
        }

        // Auto-regenerate when any filter changes
        if (accountSelect) accountSelect.addEventListener('change', () => this.generateBillsCalendar());
        if (yearSelect) yearSelect.addEventListener('change', () => this.generateBillsCalendar());
        if (statusSelect) statusSelect.addEventListener('change', () => this.generateBillsCalendar());
        if (transfersCheckbox) transfersCheckbox.addEventListener('change', () => this.generateBillsCalendar());

        if (viewSelect) {
            viewSelect.addEventListener('change', (e) => {
                const tableContainer = document.getElementById('bills-calendar-table-container');
                const heatmapContainer = document.getElementById('bills-calendar-heatmap-container');

                if (e.target.value === 'table') {
                    if (tableContainer) tableContainer.style.display = '';
                    if (heatmapContainer) heatmapContainer.style.display = 'none';
                } else {
                    if (tableContainer) tableContainer.style.display = 'none';
                    if (heatmapContainer) heatmapContainer.style.display = '';
                }
            });
        }
    }

    populateBillsCalendarYears() {
        const yearSelect = document.getElementById('bills-calendar-year');
        if (!yearSelect) return;

        const currentYear = new Date().getFullYear();
        yearSelect.innerHTML = '';

        // Add 5 years: 2 past, current, 2 future
        for (let year = currentYear - 2; year <= currentYear + 2; year++) {
            const option = document.createElement('option');
            option.value = year;
            option.textContent = year;
            if (year === currentYear) option.selected = true;
            yearSelect.appendChild(option);
        }
    }

    async generateBillsCalendar() {
        const year = document.getElementById('bills-calendar-year')?.value || new Date().getFullYear();
        const billStatus = document.getElementById('bills-calendar-status')?.value || 'active';
        const includeTransfers = document.getElementById('bills-calendar-include-transfers')?.checked || false;
        const accountId = document.getElementById('bills-calendar-account')?.value || '';

        // Show loading
        const loadingEl = document.getElementById('report-loading');
        if (loadingEl) loadingEl.style.display = 'flex';

        try {
            const params = new URLSearchParams({
                year: year.toString(),
                billStatus,
                includeTransfers: includeTransfers.toString(),
                ...(accountId && { accountId })
            });

            const response = await fetch(
                OC.generateUrl(`/apps/budget/api/bills/annual-overview?${params}`),
                { headers: { 'requesttoken': OC.requestToken } }
            );

            if (!response.ok) throw new Error('Failed to fetch bills calendar data');

            const data = await response.json();

            // Render monthly totals chart
            this.renderBillsCalendarChart(data.monthlyTotals);

            // Get current view
            const view = document.getElementById('bills-calendar-view')?.value || 'table';

            if (view === 'table') {
                this.renderBillsCalendarTable(data.bills, data.monthlyTotals);
            } else {
                this.renderBillsCalendarHeatmap(data.bills, data.monthlyTotals);
            }

            // Show appropriate containers
            document.getElementById('bills-calendar-chart-container').style.display = '';

            if (view === 'table') {
                document.getElementById('bills-calendar-table-container').style.display = '';
                document.getElementById('bills-calendar-heatmap-container').style.display = 'none';
            } else {
                document.getElementById('bills-calendar-table-container').style.display = 'none';
                document.getElementById('bills-calendar-heatmap-container').style.display = '';
            }

        } catch (error) {
            console.error('Failed to generate bills calendar:', error);
            showError(t('budget', 'Failed to generate bills calendar'));
        } finally {
            if (loadingEl) loadingEl.style.display = 'none';
        }
    }

    renderBillsCalendarChart(monthlyTotals) {
        const canvas = document.getElementById('bills-calendar-chart');
        if (!canvas) return;

        if (this.reportCharts.billsCalendar) {
            this.reportCharts.billsCalendar.destroy();
        }

        const ctx = canvas.getContext('2d');
        const currency = this.getPrimaryCurrency();

        const months = [t('budget', 'Jan'), t('budget', 'Feb'), t('budget', 'Mar'), t('budget', 'Apr'), t('budget', 'May'), t('budget', 'Jun'), t('budget', 'Jul'), t('budget', 'Aug'), t('budget', 'Sep'), t('budget', 'Oct'), t('budget', 'Nov'), t('budget', 'Dec')];
        const data = [];
        for (let i = 1; i <= 12; i++) {
            data.push(monthlyTotals[i] || 0);
        }

        this.reportCharts.billsCalendar = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: months,
                datasets: [{
                    label: t('budget', 'Total Bills'),
                    data: data,
                    backgroundColor: 'rgba(198, 40, 40, 0.7)',
                    borderColor: 'rgba(198, 40, 40, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${t('budget', 'Total')}: ${this.formatCurrency(context.raw, currency)}`
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => this.formatCurrencyCompact(value, currency)
                        }
                    }
                }
            }
        });
    }

    renderBillsCalendarTable(bills, monthlyTotals) {
        const tbody = document.getElementById('bills-calendar-table-body');
        const tfoot = document.getElementById('bills-calendar-table-footer');
        if (!tbody || !tfoot) return;

        const currency = this.getPrimaryCurrency();

        const calendarYear = parseInt(document.getElementById('bills-calendar-year')?.value) || new Date().getFullYear();

        // Render bills rows
        tbody.innerHTML = bills.map(bill => {
            // Determine which months are "paid" based on lastPaidDate
            const lastPaid = bill.lastPaidDate;
            const lastPaidYear = lastPaid ? parseInt(lastPaid.substring(0, 4)) : null;
            const lastPaidMonth = lastPaid ? parseInt(lastPaid.substring(5, 7)) : null;

            const months = [];
            for (let month = 1; month <= 12; month++) {
                const occurs = bill.occurrences[month];
                const amount = occurs ? this.formatCurrency(bill.amount, bill.currency || currency) : '';

                // A month is "paid" if it occurs and lastPaidDate is in or after that month
                const isPaid = occurs && lastPaidYear !== null && (
                    calendarYear < lastPaidYear ||
                    (calendarYear === lastPaidYear && month <= lastPaidMonth)
                );
                const cellClass = occurs ? (isPaid ? 'has-bill paid' : 'has-bill') : 'no-bill';
                months.push(`<td class="month-cell ${cellClass}">${amount}</td>`);
            }

            const transferBadge = bill.isTransfer ? ` <span class="transfer-badge" style="background: #0082c9; color: white; padding: 2px 6px; border-radius: 10px; font-size: 10px;">${t('budget', 'Transfer')}</span>` : '';

            return `
                <tr>
                    <td class="bill-name-col">${this.escapeHtml(bill.name)}${transferBadge}</td>
                    ${months.join('')}
                </tr>
            `;
        }).join('');

        // Render totals row
        const totals = [];
        for (let month = 1; month <= 12; month++) {
            totals.push(`<td class="total-cell">${this.formatCurrency(monthlyTotals[month] || 0, currency)}</td>`);
        }

        tfoot.innerHTML = `
            <tr class="totals-row">
                <td class="bill-name-col"><strong>${t('budget', 'Monthly Totals')}</strong></td>
                ${totals.join('')}
            </tr>
        `;
    }

    renderBillsCalendarHeatmap(bills, monthlyTotals) {
        const container = document.getElementById('bills-calendar-heatmap');
        if (!container) return;

        const currency = this.getPrimaryCurrency();
        const months = [t('budget', 'January'), t('budget', 'February'), t('budget', 'March'), t('budget', 'April'), t('budget', 'May'), t('budget', 'June'), t('budget', 'July'), t('budget', 'August'), t('budget', 'September'), t('budget', 'October'), t('budget', 'November'), t('budget', 'December')];

        // Find max total for color scaling
        const maxTotal = Math.max(...Object.values(monthlyTotals));

        let html = '<div class="heatmap-grid">';

        for (let month = 1; month <= 12; month++) {
            const total = monthlyTotals[month] || 0;
            const billsThisMonth = bills.filter(b => b.occurrences[month]).length;
            const intensity = maxTotal > 0 ? (total / maxTotal) : 0;

            // Color scale from light red to dark red
            const red = Math.round(198 * (0.3 + 0.7 * intensity));
            const green = Math.round(40 * (1 - 0.5 * intensity));
            const blue = Math.round(40 * (1 - 0.5 * intensity));

            html += `
                <div class="heatmap-month" style="background-color: rgba(${red}, ${green}, ${blue}, ${0.2 + 0.6 * intensity});" title="${months[month - 1]}: ${n('budget', '%n bill', '%n bills', billsThisMonth)}, ${this.formatCurrency(total, currency)}">
                    <div class="heatmap-month-name">${months[month - 1]}</div>
                    <div class="heatmap-month-count">${n('budget', '%n bill', '%n bills', billsThisMonth)}</div>
                    <div class="heatmap-month-total">${this.formatCurrency(total, currency)}</div>
                </div>
            `;
        }

        html += '</div>';

        container.innerHTML = html;
    }
}
