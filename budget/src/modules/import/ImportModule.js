/**
 * Import Module - Bank statement import with CSV/OFX/QIF support
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError, showWarning, showInfo } from '../../utils/notifications.js';

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
        return formatters.formatDate(date);
    }

    getPrimaryCurrency() {
        return this.app.getPrimaryCurrency();
    }

    loadTransactions() {
        return this.app.loadTransactions();
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
                throw new Error('Upload failed');
            }
        } catch (error) {
            console.error('Failed to upload file:', error);
            showError('Failed to upload file');
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

    showImportMapping(uploadResult) {
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
                <span class="file-name">${uploadResult.filename}</span>
                <span class="file-size">${this.formatFileSize(uploadResult.size)}</span>
                <span class="record-count">${uploadResult.recordCount} records</span>
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

        // Move to step 2
        this.setImportStep(2);
    }

    reloadColumnsWithDelimiter() {
        const delimiterSelect = document.getElementById('csv-delimiter');
        if (!delimiterSelect) return;

        this.currentDelimiter = delimiterSelect.value;
        showInfo('Delimiter changed. File will be re-parsed in the next step.');
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
            'map-reference': document.getElementById('map-reference')
        };

        // Clear existing options and add columns
        Object.values(mappingSelects).forEach(select => {
            if (!select) return;
            const firstOption = select.firstElementChild;
            select.innerHTML = '';
            if (firstOption) select.appendChild(firstOption);

            columns.forEach((column, index) => {
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
            'map-reference': ['reference', 'ref', 'check number', 'transaction id']
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
                showWarning('Please select a file first');
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
        const mapping = this.getCurrentMapping();
        const isMultiAccount = this.sourceAccounts && this.sourceAccounts.length > 0;

        // Build request body based on import type
        const requestBody = {
            fileId: this.currentImportData.fileId,
            mapping: mapping,
            skipDuplicates: !(document.getElementById('show-duplicates')?.checked ?? true),
            delimiter: document.getElementById('csv-delimiter')?.value || ','
        };

        if (isMultiAccount) {
            const accountMapping = this.getAccountMapping();
            if (Object.keys(accountMapping).length === 0) {
                showWarning('Please map at least one account');
                return;
            }
            requestBody.accountMapping = accountMapping;
        } else {
            const accountId = document.getElementById('import-account')?.value;
            if (!accountId) {
                showWarning('Please select an account first');
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
                this.updateImportSummary(result);
                this.showTransactionPreview(result.transactions);
                this.filterPreviewTransactions();
            } else {
                const errorData = await response.json();
                throw new Error(errorData.error || 'Processing failed');
            }
        } catch (error) {
            console.error('Failed to process import data:', error);
            showError('Failed to process import data: ' + error.message);
        }
    }

    updateImportSummary(result) {
        document.getElementById('total-transactions').textContent = result.totalRows || 0;
        document.getElementById('new-transactions').textContent = result.validTransactions || 0;
        document.getElementById('duplicate-transactions').textContent = result.duplicates || 0;
        // Count transactions with categoryId set
        const categorized = (result.transactions || []).filter(t => t.categoryId).length;
        document.getElementById('categorized-transactions').textContent = categorized;
    }

    showTransactionPreview(transactions) {
        const tbody = document.querySelector('#preview-table tbody');
        if (!tbody) return;

        tbody.innerHTML = '';

        if (!transactions || transactions.length === 0) {
            const row = document.createElement('tr');
            row.innerHTML = '<td colspan="6" style="text-align: center; padding: 20px;">No transactions to import</td>';
            tbody.appendChild(row);
            document.getElementById('preview-info').textContent = 'No transactions found';
            return;
        }

        transactions.slice(0, 50).forEach((transaction, index) => {
            const row = document.createElement('tr');
            const amount = parseFloat(transaction.amount) || 0;
            const isDuplicate = transaction.isDuplicate || false;
            const statusBadge = isDuplicate
                ? '<span class="status-badge status-error">Duplicate</span>'
                : '<span class="status-badge status-success">New</span>';

            row.innerHTML = `
                <td>
                    <input type="checkbox" ${isDuplicate ? '' : 'checked'} data-row-index="${transaction.rowIndex ?? index}">
                </td>
                <td>${transaction.date || ''}</td>
                <td>${transaction.description || ''}</td>
                <td class="${amount >= 0 ? 'positive' : 'negative'}">
                    ${this.formatCurrency(amount)}
                </td>
                <td>${transaction.ruleName || 'Uncategorized'}</td>
                <td>
                    ${statusBadge}
                </td>
            `;

            tbody.appendChild(row);
        });

        document.getElementById('preview-info').textContent =
            `Showing ${Math.min(50, transactions.length)} of ${transactions.length}`;
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

            const isDuplicate = statusBadge?.textContent?.trim() === 'Duplicate';
            const isUncategorized = category === 'Uncategorized';

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
            `Showing ${visibleCount} of ${totalCount}`;
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

            // Check if we have multi-account OFX/QIF file
            if (this.sourceAccounts && this.sourceAccounts.length > 0) {
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
                    select.innerHTML = '<option value="">Select account...</option>';
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

        container.innerHTML = '';

        this.sourceAccounts.forEach(sourceAccount => {
            const row = document.createElement('div');
            row.className = 'account-mapping-row';
            row.dataset.sourceAccountId = sourceAccount.accountId;

            // Build details string
            const details = [];
            if (sourceAccount.type) details.push(sourceAccount.type);
            if (sourceAccount.currency) details.push(sourceAccount.currency);
            if (sourceAccount.transactionCount) details.push(`${sourceAccount.transactionCount} transactions`);
            if (sourceAccount.ledgerBalance !== null && sourceAccount.ledgerBalance !== undefined) {
                details.push(`Balance: ${this.formatCurrency(sourceAccount.ledgerBalance)}`);
            }

            // Build account options HTML with auto-match selection
            const suggestedMatch = sourceAccount.suggestedMatch;
            let optionsHtml = '<option value="">Skip this account</option>';
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
            showError('No file data available');
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

        if (isMultiAccount) {
            const accountMapping = this.getAccountMapping();
            if (Object.keys(accountMapping).length === 0) {
                showWarning('Please map at least one account');
                return;
            }
            requestBody.accountMapping = accountMapping;
        } else {
            const accountId = document.getElementById('import-account').value;
            if (!accountId) {
                showWarning('Please select an account');
                return;
            }
            requestBody.accountId = parseInt(accountId);
        }

        // Show loading state on import button
        const importBtn = document.getElementById('import-btn');
        const originalText = importBtn.textContent;
        importBtn.disabled = true;
        importBtn.textContent = 'Importing...';

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
                throw new Error(`Server error (${response.status}): Invalid response`);
            }

            if (response.ok) {
                showSuccess(`Successfully imported ${result.imported} transactions (${result.skipped} skipped)`);
                this.resetImportWizard();
                this.loadTransactions();
            } else {
                throw new Error(result.error || 'Import failed');
            }
        } catch (error) {
            console.error('Failed to execute import:', error);
            showError('Failed to import transactions: ' + error.message);
        } finally {
            // Restore button state
            importBtn.disabled = false;
            importBtn.textContent = originalText;
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

        this.setImportStep(1);

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
                    <button class="icon-download import-download-btn" data-import-id="${item.id}" title="Download"></button>
                    <button class="icon-delete import-rollback-btn" data-import-id="${item.id}" title="Rollback"></button>
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
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
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
                throw new Error('Download failed');
            }
        } catch (error) {
            console.error('Failed to download import:', error);
            showError('Failed to download import file');
        }
    }

    async rollbackImport(importId) {
        if (!confirm('Are you sure you want to rollback this import? All imported transactions will be deleted.')) {
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
                showSuccess(`Rolled back ${result.deleted} transactions`);
                this.loadImportHistory();
                this.loadTransactions();
            } else {
                const errorData = await response.json();
                throw new Error(errorData.error || 'Rollback failed');
            }
        } catch (error) {
            console.error('Failed to rollback import:', error);
            showError('Failed to rollback import: ' + error.message);
        }
    }
}
