/**
 * Transfers Module - Recurring transfer tracking between accounts
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError, showWarning } from '../../utils/notifications.js';
import { initSingleDatePicker } from '../../utils/datepicker.js';

export default class TransfersModule {
    constructor(app) {
        this.app = app;
        this._eventsSetup = false;
        this.transfers = [];
    }

    // Getters for app state
    get accounts() { return this.app.accounts; }
    get categories() { return this.app.categories; }
    get categoryTree() { return this.app.categoryTree; }
    get settings() { return this.app.settings; }

    async init() {
        await this.loadTransfers();
    }

    async loadTransfersView() {
        await this.loadTransfers();
        this.render();
        this.renderTransfers();
        this.updateSummary();
    }

    async loadTransfers() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bills?isTransfer=true'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            this.transfers = await response.json();
        } catch (error) {
            console.error('Failed to load transfers:', error);
            showError(t('budget', 'Failed to load transfers'));
        }
    }

    render() {
        const view = document.getElementById('transfers-view');
        if (!view) {
            console.error('Transfers view not found');
            return;
        }

        view.innerHTML = `
            <div class="view-header">
                <h2>${t('budget', 'Recurring Transfers')}</h2>
                <div class="view-controls">
                    <button id="add-transfer-btn" class="primary" aria-label="${t('budget', 'Add new transfer')}">
                        <span class="icon-add" aria-hidden="true"></span>
                        ${t('budget', 'Add Transfer')}
                    </button>
                </div>
            </div>

            <!-- Transfers Summary Cards -->
            <div class="bills-summary">
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-category-monitoring" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="transfers-active-count">0</div>
                        <div class="summary-label">${t('budget', 'Active Transfers')}</div>
                    </div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-icon">
                        <span class="icon-category-monitoring" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="transfers-due-count">0</div>
                        <div class="summary-label">${t('budget', 'Due This Month')}</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-quota" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="transfers-monthly-total">$0</div>
                        <div class="summary-label">${t('budget', 'Monthly Total')}</div>
                    </div>
                </div>
                <div class="summary-card success">
                    <div class="summary-icon">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="transfers-completed-count">0</div>
                        <div class="summary-label">${t('budget', 'Completed This Month')}</div>
                    </div>
                </div>
            </div>

            <!-- Transfers Filter Tabs -->
            <div class="bills-tabs">
                <button class="tab-button active" data-filter="all">${t('budget', 'All Transfers')}</button>
                <button class="tab-button" data-filter="due">${t('budget', 'Due Soon')}</button>
                <button class="tab-button" data-filter="overdue">${t('budget', 'Overdue')}</button>
                <button class="tab-button" data-filter="completed">${t('budget', 'Completed')}</button>
            </div>

            <!-- Transfers List -->
            <div class="bills-container">
                <div id="transfers-list" class="bills-list">
                    <!-- Transfers will be rendered here -->
                </div>

                <div class="empty-bills" id="empty-transfers" style="display: none;">
                    <div class="empty-content">
                        <span class="icon-link" aria-hidden="true"></span>
                        <h3>${t('budget', 'No recurring transfers yet')}</h3>
                        <p>${t('budget', 'Set up automatic transfers between your accounts to automate savings or bill payments.')}</p>
                        <button class="primary" id="empty-transfers-add-btn">
                            <span class="icon-add" aria-hidden="true"></span>
                            ${t('budget', 'Add Your First Transfer')}
                        </button>
                    </div>
                </div>
            </div>
        `;

        // Setup event listeners
        if (!this._eventsSetup) {
            this.setupEventListeners();
            this._eventsSetup = true;
        }

        // Render transfers
        this.renderTransfers();
        this.updateSummary();
    }

    setupEventListeners() {
        // Add transfer button
        document.addEventListener('click', (e) => {
            if (e.target.id === 'add-transfer-btn' || e.target.id === 'empty-transfers-add-btn' ||
                e.target.closest('#add-transfer-btn') || e.target.closest('#empty-transfers-add-btn')) {
                e.preventDefault();
                this.showTransferModal();
            }
        });

        // Mark transfer as paid
        document.addEventListener('click', (e) => {
            const paidBtn = e.target.closest('.transfer-paid-btn');
            if (paidBtn) {
                e.preventDefault();
                const transferId = parseInt(paidBtn.dataset.transferId);
                this.markTransferPaid(transferId);
            }
        });

        // Edit transfer
        document.addEventListener('click', (e) => {
            const editBtn = e.target.closest('.transfer-edit-btn');
            if (editBtn) {
                e.preventDefault();
                const transferId = parseInt(editBtn.dataset.transferId);
                const transfer = this.transfers.find(tx => tx.id === transferId);
                if (transfer) {
                    this.showTransferModal(transfer);
                }
            }
        });

        // Delete transfer
        document.addEventListener('click', (e) => {
            const deleteBtn = e.target.closest('.transfer-delete-btn');
            if (deleteBtn) {
                e.preventDefault();
                const transferId = parseInt(deleteBtn.dataset.transferId);
                this.deleteTransfer(transferId);
            }
        });

        // Tab filtering
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('tab-button') &&
                e.target.closest('.bills-tabs') &&
                document.getElementById('transfers-view')?.contains(e.target)) {
                e.preventDefault();

                // Update active tab
                document.querySelectorAll('.bills-tabs .tab-button').forEach(btn => {
                    btn.classList.remove('active');
                });
                e.target.classList.add('active');

                // Filter transfers
                const filter = e.target.dataset.filter;
                this.filterTransfers(filter);
            }
        });
    }

    renderTransfers() {
        const transfersList = document.getElementById('transfers-list');
        const emptyTransfers = document.getElementById('empty-transfers');

        if (!this.transfers || this.transfers.length === 0) {
            transfersList.innerHTML = '';
            emptyTransfers.style.display = 'flex';
            return;
        }

        emptyTransfers.style.display = 'none';

        transfersList.innerHTML = this.transfers.map(transfer => {
            const dueDate = transfer.nextDueDate || transfer.next_due_date;
            const isPaid = this.isTransferPaidThisMonth(transfer);
            const isOverdue = !isPaid && dueDate && dueDate < formatters.getTodayDateString();
            const isDueSoon = !isPaid && !isOverdue && dueDate && this.isDueSoon(dueDate);

            let statusClass = '';
            let statusText = '';
            if (isPaid) {
                statusClass = 'paid';
                statusText = t('budget', 'Paid');
            } else if (isOverdue) {
                statusClass = 'overdue';
                statusText = t('budget', 'Overdue');
            } else if (isDueSoon) {
                statusClass = 'due-soon';
                statusText = t('budget', 'Due Soon');
            } else {
                statusClass = 'upcoming';
                statusText = t('budget', 'Upcoming');
            }

            const fromAccount = this.accounts.find(a => a.id === transfer.accountId);
            const toAccount = this.accounts.find(a => a.id === transfer.destinationAccountId);
            const fromAccountName = fromAccount ? fromAccount.name : t('budget', 'Unknown Account');
            const toAccountName = toAccount ? toAccount.name : t('budget', 'Unknown Account');

            const frequency = transfer.frequency || 'monthly';
            const frequencyLabels = {
                'weekly': t('budget', 'Weekly'),
                'biweekly': t('budget', 'Bi-Weekly'),
                'monthly': t('budget', 'Monthly'),
                'quarterly': t('budget', 'Quarterly'),
                'semi-annually': t('budget', 'Semi-Annually'),
                'yearly': t('budget', 'Yearly')
            };
            const frequencyLabel = frequencyLabels[frequency] || frequency.charAt(0).toUpperCase() + frequency.slice(1);

            const autoPayEnabled = transfer.autoPayEnabled ?? transfer.auto_pay_enabled ?? false;
            const autoPayFailed = transfer.autoPayFailed ?? transfer.auto_pay_failed ?? false;

            return `
                <div class="bill-card ${statusClass}" data-bill-id="${transfer.id}" data-status="${statusClass}">
                    <div class="bill-header">
                        <div class="bill-info">
                            <h4 class="bill-name">${dom.escapeHtml(transfer.name)}</h4>
                            <span class="bill-frequency">${frequencyLabel}</span>
                        </div>
                        <div class="bill-amount">${formatters.formatCurrency(transfer.amount, null, this.settings)}</div>
                    </div>
                    <div class="bill-details">
                        <div class="bill-due-date">
                            <span class="icon-calendar" aria-hidden="true"></span>
                            ${dueDate ? formatters.formatDate(dueDate, this.settings) : t('budget', 'No due date')}
                        </div>
                        <div class="bill-status ${statusClass}">
                            <span class="status-badge">${statusText}</span>
                            ${autoPayEnabled ? `<span class="status-badge auto-pay" title="${t('budget', 'Auto-pay enabled')}" style="background: #007bff; margin-left: 5px;"><span class="icon-checkmark"></span> ${t('budget', 'Auto-pay')}</span>` : ''}
                            ${autoPayFailed ? `<span class="status-badge auto-pay-failed" title="${t('budget', 'Auto-pay failed - disabled')}" style="background: #ffc107; color: #856404; margin-left: 5px;"><span class="icon-error"></span> ${t('budget', 'Auto-pay Failed')}</span>` : ''}
                        </div>
                    </div>
                    <div class="bill-actions">
                        ${!isPaid ? `
                            <button class="bill-action-btn transfer-paid-btn" data-transfer-id="${transfer.id}" title="${t('budget', 'Mark as paid')}">
                                <span class="icon-checkmark" aria-hidden="true"></span>
                                ${t('budget', 'Mark Paid')}
                            </button>
                        ` : ''}
                        <button class="bill-action-btn transfer-edit-btn" data-transfer-id="${transfer.id}" title="${t('budget', 'Edit transfer')}">
                            <span class="icon-rename" aria-hidden="true"></span>
                        </button>
                        <button class="bill-action-btn transfer-delete-btn" data-transfer-id="${transfer.id}" title="${t('budget', 'Delete transfer')}">
                            <span class="icon-delete" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    }

    filterTransfers(filter) {
        const transferItems = document.querySelectorAll('#transfers-list .bill-item');

        transferItems.forEach(item => {
            const transferId = parseInt(item.dataset.id);
            const transfer = this.transfers.find(tx => tx.id === transferId);
            if (!transfer) {
                item.style.display = 'none';
                return;
            }

            const dueDate = transfer.nextDueDate || transfer.next_due_date;
            const isPaid = this.isTransferPaidThisMonth(transfer);
            const isOverdue = !isPaid && dueDate && dueDate < formatters.getTodayDateString();
            const isDueSoon = !isPaid && !isOverdue && dueDate && this.isDueSoon(dueDate);

            let show = false;

            switch (filter) {
                case 'all':
                    show = true;
                    break;
                case 'due':
                    show = isDueSoon;
                    break;
                case 'overdue':
                    show = isOverdue;
                    break;
                case 'completed':
                    show = isPaid;
                    break;
            }

            item.style.display = show ? '' : 'none';
        });
    }

    updateSummary() {
        const activeCount = this.transfers.filter(tx => tx.isActive).length;
        const dueThisMonth = this.transfers.filter(tx => {
            if (!tx.isActive) return false;
            const dueDate = tx.nextDueDate || tx.next_due_date;
            if (!dueDate) return false;
            const due = new Date(dueDate);
            const now = new Date();
            return due.getMonth() === now.getMonth() && due.getFullYear() === now.getFullYear();
        }).length;

        const completedThisMonth = this.transfers.filter(tx => {
            return this.isTransferPaidThisMonth(tx);
        }).length;

        const monthlyTotal = this.transfers
            .filter(tx => tx.isActive)
            .reduce((sum, tx) => sum + this.getMonthlyEquivalent(tx), 0);

        document.getElementById('transfers-active-count').textContent = activeCount;
        document.getElementById('transfers-due-count').textContent = dueThisMonth;
        document.getElementById('transfers-monthly-total').textContent =
            formatters.formatCurrency(monthlyTotal, null, this.settings);
        document.getElementById('transfers-completed-count').textContent = completedThisMonth;
    }

    showTransferModal(transfer = null) {
        const isEdit = transfer !== null;
        const title = isEdit ? t('budget', 'Edit Transfer') : t('budget', 'Add Transfer');

        // Debug logging
        console.log('Accounts available for transfer modal:', this.accounts);
        console.log('Number of accounts:', this.accounts ? this.accounts.length : 0);
        if (this.accounts && this.accounts.length > 0) {
            console.log('First account:', this.accounts[0]);
            console.log('Account IDs:', this.accounts.map(a => a.id));
            console.log('Account names:', this.accounts.map(a => a.name));
        }

        const modalHtml = `
            <div class="budget-modal-overlay">
                <div class="budget-modal">
                    <div class="budget-modal-header">
                        <h2>${title}</h2>
                        <button class="close-btn" id="close-transfer-modal">×</button>
                    </div>
                    <form id="transfer-form" class="budget-modal-body">
                        <div class="form-group">
                            <label for="transfer-name">${t('budget', 'Name')} *</label>
                            <input type="text" id="transfer-name" class="form-control"
                                   placeholder="${t('budget', 'e.g., Monthly Savings')}" required
                                   value="${isEdit ? dom.escapeHtml(transfer.name) : ''}">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="transfer-amount">${t('budget', 'Amount')} *</label>
                                <input type="number" id="transfer-amount" class="form-control"
                                       step="0.01" min="0" required
                                       value="${isEdit ? transfer.amount : ''}">
                            </div>
                            <div class="form-group">
                                <label for="transfer-frequency">${t('budget', 'Frequency')} *</label>
                                <select id="transfer-frequency" class="form-control" required>
                                    <option value="weekly" ${isEdit && transfer.frequency === 'weekly' ? 'selected' : ''}>${t('budget', 'Weekly')}</option>
                                    <option value="biweekly" ${isEdit && transfer.frequency === 'biweekly' ? 'selected' : ''}>${t('budget', 'Bi-Weekly')}</option>
                                    <option value="monthly" ${!isEdit || transfer.frequency === 'monthly' ? 'selected' : ''}>${t('budget', 'Monthly')}</option>
                                    <option value="quarterly" ${isEdit && transfer.frequency === 'quarterly' ? 'selected' : ''}>${t('budget', 'Quarterly')}</option>
                                    <option value="yearly" ${isEdit && transfer.frequency === 'yearly' ? 'selected' : ''}>${t('budget', 'Yearly')}</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="recurring-transfer-from-account">${t('budget', 'From Account')} *</label>
                                <select id="recurring-transfer-from-account" class="form-control" required>
                                    <option value="">${t('budget', 'Select account...')}</option>
                                    ${this.accounts.map(account => `
                                        <option value="${account.id}" ${isEdit && transfer.accountId === account.id ? 'selected' : ''}>
                                            ${dom.escapeHtml(account.name)}
                                        </option>
                                    `).join('')}
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="recurring-transfer-to-account">${t('budget', 'To Account')} *</label>
                                <select id="recurring-transfer-to-account" class="form-control" required>
                                    <option value="">${t('budget', 'Select account...')}</option>
                                    ${this.accounts.map(account => `
                                        <option value="${account.id}" ${isEdit && transfer.destinationAccountId === account.id ? 'selected' : ''}>
                                            ${dom.escapeHtml(account.name)}
                                        </option>
                                    `).join('')}
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="transfer-due-day">${t('budget', 'Day of Month (1-31)')}</label>
                            <input type="number" id="transfer-due-day" class="form-control"
                                   min="1" max="31" placeholder="${t('budget', 'e.g., 15')}"
                                   value="${isEdit && transfer.dueDay ? transfer.dueDay : ''}">
                            <small class="form-hint">${t('budget', 'Leave empty for weekly transfers')}</small>
                        </div>

                        <div class="form-group">
                            <label for="transfer-description-pattern">${t('budget', 'Transaction Description Pattern (Optional)')}</label>
                            <input type="text" id="transfer-description-pattern" class="form-control"
                                   placeholder="${t('budget', 'e.g., Savings Transfer')}"
                                   value="${isEdit && transfer.transferDescriptionPattern ? dom.escapeHtml(transfer.transferDescriptionPattern) : ''}">
                            <small class="form-hint">${t('budget', 'Used to match imported transactions')}</small>
                        </div>

                        <div class="form-group">
                            <label for="transfer-category">${t('budget', 'Category')}</label>
                            <select id="transfer-category" class="form-control">
                                <option value="">${t('budget', 'No category')}</option>
                                ${dom.buildCategoryOptionsHtml(this.categoryTree || this.categories, { typeFilter: 'expense', selectedId: isEdit ? transfer.categoryId : null })}
                            </select>
                            <small class="form-hint">${t('budget', 'Category for created transactions (optional)')}</small>
                        </div>

                        <div id="transfer-tags-container"></div>

                        <div class="form-group">
                            <label for="transfer-notes">${t('budget', 'Notes')}</label>
                            <textarea id="transfer-notes" class="form-control" rows="3"
                                      placeholder="${t('budget', 'Optional notes...')}">${isEdit && transfer.notes ? dom.escapeHtml(transfer.notes) : ''}</textarea>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="transfer-create-transaction"
                                       ${isEdit ? '' : ''}>
                                <span>${t('budget', 'Also create transactions now')}</span>
                            </label>
                            <small class="form-hint">${t('budget', 'Creates paired debit/credit transactions immediately')}</small>
                        </div>

                        <div class="form-group" id="transfer-transaction-date-group" style="display: none;">
                            <label for="transfer-transaction-date">${t('budget', 'Transaction Date')}</label>
                            <input type="date" id="transfer-transaction-date" class="form-control">
                            <small class="form-hint">${t('budget', 'Leave empty to use next due date')}</small>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="transfer-auto-pay"
                                       ${isEdit && transfer.autoPayEnabled ? 'checked' : ''}>
                                <span>${t('budget', 'Enable auto-pay (automatically create transactions when due)')}</span>
                            </label>
                        </div>

                        <div class="budget-modal-footer">
                            <button type="button" class="button secondary" id="cancel-transfer">${t('budget', 'Cancel')}</button>
                            <button type="submit" class="button primary">
                                ${isEdit ? t('budget', 'Update Transfer') : t('budget', 'Add Transfer')}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        // Remove existing modal if any
        const existingModal = document.querySelector('.budget-modal-overlay');
        if (existingModal) {
            existingModal.remove();
        }

        // Add modal to body
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        // Initialize flatpickr on the transaction date input
        const transferDateInput = document.getElementById('transfer-transaction-date');
        if (transferDateInput) {
            initSingleDatePicker(transferDateInput, this.app.settings);
        }

        // Debug: Check if dropdowns are populated
        setTimeout(() => {
            const fromSelect = document.getElementById('recurring-transfer-from-account');
            const toSelect = document.getElementById('recurring-transfer-to-account');
            console.log('From Account dropdown options:', fromSelect ? fromSelect.options.length : 'not found');
            console.log('To Account dropdown options:', toSelect ? toSelect.options.length : 'not found');
            if (toSelect && toSelect.options.length > 0) {
                console.log('First option in To Account:', toSelect.options[0].value, toSelect.options[0].text);
                if (toSelect.options.length > 1) {
                    console.log('Second option in To Account:', toSelect.options[1].value, toSelect.options[1].text);
                }
            }
        }, 100);

        // Category change listener - load tag sets for selected category
        const categorySelect = document.getElementById('transfer-category');
        if (categorySelect) {
            categorySelect.addEventListener('change', () => {
                this.loadTransferTagSets(categorySelect.value || null, isEdit ? transfer : null);
            });
            // Load tag sets for pre-selected category (edit mode)
            if (categorySelect.value) {
                this.loadTransferTagSets(categorySelect.value, isEdit ? transfer : null);
            }
        }

        // Create transaction checkbox (show/hide date field)
        const createTransactionCheckbox = document.getElementById('transfer-create-transaction');
        if (createTransactionCheckbox) {
            createTransactionCheckbox.addEventListener('change', (e) => {
                const dateGroup = document.getElementById('transfer-transaction-date-group');
                if (dateGroup) {
                    dateGroup.style.display = e.target.checked ? 'block' : 'none';
                }
            });
        }

        // Setup modal event listeners
        const modalOverlay = document.querySelector('.budget-modal-overlay');
        const form = document.getElementById('transfer-form');
        const closeBtn = document.getElementById('close-transfer-modal');
        const cancelBtn = document.getElementById('cancel-transfer');

        const closeModal = () => {
            modalOverlay.remove();
        };

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) closeModal();
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const success = await this.saveTransfer(transfer);
            if (success) {
                closeModal();
            }
        });
    }

    async saveTransfer(existingTransfer = null) {
        const name = document.getElementById('transfer-name').value;
        const amount = parseFloat(document.getElementById('transfer-amount').value);
        const frequency = document.getElementById('transfer-frequency').value;
        const fromAccountId = parseInt(document.getElementById('recurring-transfer-from-account').value);
        const toAccountId = parseInt(document.getElementById('recurring-transfer-to-account').value);
        const dueDay = document.getElementById('transfer-due-day').value ?
                       parseInt(document.getElementById('transfer-due-day').value) : null;
        const transferDescriptionPattern = document.getElementById('transfer-description-pattern').value || null;
        const categoryId = document.getElementById('transfer-category')?.value ? parseInt(document.getElementById('transfer-category').value) : null;
        const tagIds = this.getSelectedTagIds();
        const notes = document.getElementById('transfer-notes').value || null;
        const createTransaction = document.getElementById('transfer-create-transaction')?.checked || false;
        const transactionDate = document.getElementById('transfer-transaction-date')?.value || null;
        const autoPayEnabled = document.getElementById('transfer-auto-pay').checked;

        // Debug logging
        console.log('Transfer form values:', {
            name,
            amount,
            frequency,
            fromAccountId,
            toAccountId,
            fromAccountValue: document.getElementById('recurring-transfer-from-account').value,
            toAccountValue: document.getElementById('recurring-transfer-to-account').value
        });

        // Validation
        if (!fromAccountId || isNaN(fromAccountId)) {
            showWarning(t('budget', 'Please select a source account'));
            return false;
        }

        if (!toAccountId || isNaN(toAccountId)) {
            showWarning(t('budget', 'Please select a destination account'));
            return false;
        }

        if (fromAccountId === toAccountId) {
            showWarning(t('budget', 'Cannot transfer to the same account'));
            return false;
        }

        const data = {
            name,
            amount,
            frequency,
            accountId: fromAccountId,
            destinationAccountId: toAccountId,
            dueDay,
            transferDescriptionPattern,
            categoryId,
            tagIds,
            notes,
            createTransaction,
            transactionDate,
            autoPayEnabled,
            isTransfer: true
        };

        try {
            const url = existingTransfer ?
                OC.generateUrl(`/apps/budget/api/bills/${existingTransfer.id}`) :
                OC.generateUrl('/apps/budget/api/bills');

            const method = existingTransfer ? 'PUT' : 'POST';

            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(data)
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || t('budget', 'Failed to save transfer'));
            }

            showSuccess(
                existingTransfer ? t('budget', 'Transfer updated') : t('budget', 'Transfer added')
            );

            await this.loadTransfers();
            this.renderTransfers();
            this.updateSummary();
            return true;
        } catch (error) {
            console.error('Failed to save transfer:', error);
            showError(error.message || t('budget', 'Failed to save transfer'));
            return false;
        }
    }

    async deleteTransfer(transferId) {
        if (!confirm(t('budget', 'Are you sure you want to delete this transfer?'))) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${transferId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(t('budget', 'Failed to delete transfer'));

            showSuccess(t('budget', 'Transfer deleted'));

            await this.loadTransfers();
            this.renderTransfers();
            this.updateSummary();
        } catch (error) {
            console.error('Failed to delete transfer:', error);
            showError(t('budget', 'Failed to delete transfer'));
        }
    }

    async toggleTransferActive(transferId) {
        const transfer = this.transfers.find(tx => tx.id === transferId);
        if (!transfer) return;

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${transferId}`), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    active: !transfer.isActive
                })
            });

            if (!response.ok) throw new Error(t('budget', 'Failed to update transfer'));

            showSuccess(
                transfer.isActive ? t('budget', 'Transfer deactivated') : t('budget', 'Transfer activated')
            );

            await this.loadTransfers();
            this.renderTransfers();
            this.updateSummary();
        } catch (error) {
            console.error('Failed to toggle transfer:', error);
            showError(t('budget', 'Failed to update transfer'));
        }
    }

    async markTransferPaid(transferId) {
        const transfer = this.transfers.find(tx => tx.id === transferId);
        if (!transfer) return;

        try {
            const formattedDate = formatters.getTodayDateString();

            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${transferId}`), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    lastPaidDate: formattedDate
                })
            });

            if (!response.ok) throw new Error(t('budget', 'Failed to mark transfer as paid'));

            showSuccess(t('budget', 'Transfer marked as paid'));

            await this.loadTransfers();
            this.renderTransfers();
            this.updateSummary();
        } catch (error) {
            console.error('Failed to mark transfer as paid:', error);
            showError(t('budget', 'Failed to mark transfer as paid'));
        }
    }

    // Helper methods
    isTransferPaidThisMonth(transfer) {
        const lastPaid = transfer.lastPaidDate || transfer.last_paid_date;
        if (!lastPaid) return false;

        const paidDate = new Date(lastPaid);
        const now = new Date();
        return paidDate.getMonth() === now.getMonth() &&
               paidDate.getFullYear() === now.getFullYear();
    }

    isDueSoon(dueDate, days = 7) {
        const diffDays = formatters.daysBetweenDates(formatters.getTodayDateString(), dueDate);
        return diffDays >= 0 && diffDays <= days;
    }

    formatFrequency(frequency) {
        const map = {
            'weekly': t('budget', 'Weekly'),
            'biweekly': t('budget', 'Bi-Weekly'),
            'monthly': t('budget', 'Monthly'),
            'quarterly': t('budget', 'Quarterly'),
            'semi-annually': t('budget', 'Semi-Annually'),
            'yearly': t('budget', 'Yearly'),
            'one-time': t('budget', 'One-Time')
        };
        return map[frequency] || frequency;
    }

    async loadTransferTagSets(categoryId, existingTransfer = null) {
        const container = document.getElementById('transfer-tags-container');
        if (!container) return;

        try {
            // Load global tags and category tag sets in parallel
            const [globalTagsResponse, categoryTagSets] = await Promise.all([
                fetch(OC.generateUrl('/apps/budget/api/tags/global'), { headers: { 'requesttoken': OC.requestToken } }).then(r => r.ok ? r.json() : []).catch(() => []),
                categoryId ? fetch(OC.generateUrl(`/apps/budget/api/tag-sets?categoryId=${categoryId}`), { headers: { 'requesttoken': OC.requestToken } }).then(r => r.ok ? r.json() : []).catch(() => []) : Promise.resolve([])
            ]);

            const globalTags = globalTagsResponse || [];
            const tagSets = categoryTagSets || [];

            if (globalTags.length === 0 && tagSets.length === 0) {
                container.innerHTML = '';
                return;
            }

            // Get existing tag IDs if editing
            const existingTagIds = existingTransfer?.tagIds || [];

            let html = '';

            // Global tags section
            if (globalTags.length > 0) {
                html += `
                    <div class="form-group tag-set-selector">
                        <label class="tag-set-label">${t('budget', 'Tags')}</label>
                        <div class="tag-options" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;">
                            ${globalTags.map(tag => `
                                <label class="tag-option" style="cursor: pointer;">
                                    <input type="checkbox" class="transfer-tag-checkbox"
                                           value="${tag.id}"
                                           data-tag-set-id="global"
                                           ${existingTagIds.includes(tag.id) ? 'checked' : ''}
                                           style="display: none;">
                                    <span class="tag-badge" style="background-color: ${tag.color || '#666'}; color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; display: inline-block; opacity: ${existingTagIds.includes(tag.id) ? '1' : '0.5'};">
                                        ${dom.escapeHtml(tag.name)}
                                    </span>
                                </label>
                            `).join('')}
                        </div>
                    </div>
                `;
            }

            // Category tag sets
            tagSets.forEach(tagSet => {
                html += `
                    <div class="form-group tag-set-selector">
                        <label class="tag-set-label">${dom.escapeHtml(tagSet.name)}</label>
                        <div class="tag-options" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;">
                            ${tagSet.tags && tagSet.tags.length > 0 ? tagSet.tags.map(tag => `
                                <label class="tag-option" style="cursor: pointer;">
                                    <input type="checkbox" class="transfer-tag-checkbox"
                                           value="${tag.id}"
                                           data-tag-set-id="${tagSet.id}"
                                           ${existingTagIds.includes(tag.id) ? 'checked' : ''}
                                           style="display: none;">
                                    <span class="tag-badge" style="background-color: ${tag.color || '#666'}; color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; display: inline-block; opacity: ${existingTagIds.includes(tag.id) ? '1' : '0.5'};">
                                        ${dom.escapeHtml(tag.name)}
                                    </span>
                                </label>
                            `).join('') : `<span style="color: #999; font-size: 11px; font-style: italic;">${t('budget', 'No tags defined')}</span>`}
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;

            // Add click handlers for tag selection (multi-select for global, one per category tag set)
            container.querySelectorAll('.transfer-tag-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', (e) => {
                    const tagSetId = e.target.dataset.tagSetId;
                    if (e.target.checked && tagSetId !== 'global') {
                        container.querySelectorAll(`.transfer-tag-checkbox[data-tag-set-id="${tagSetId}"]`).forEach(cb => {
                            if (cb !== e.target) {
                                cb.checked = false;
                                cb.closest('.tag-option').querySelector('.tag-badge').style.opacity = '0.5';
                            }
                        });
                    }
                    e.target.closest('.tag-option').querySelector('.tag-badge').style.opacity = e.target.checked ? '1' : '0.5';
                });
            });
        } catch (error) {
            console.error('Failed to load tag sets:', error);
            container.innerHTML = '';
        }
    }

    getSelectedTagIds() {
        const container = document.getElementById('transfer-tags-container');
        if (!container) return [];
        return Array.from(container.querySelectorAll('.transfer-tag-checkbox:checked'))
            .map(cb => parseInt(cb.value));
    }

    getMonthlyEquivalent(transfer) {
        const amount = transfer.amount;
        const frequency = transfer.frequency;

        switch (frequency) {
            case 'weekly':
                return amount * 52 / 12;
            case 'biweekly':
                return amount * 26 / 12;
            case 'monthly':
                return amount;
            case 'quarterly':
                return amount / 3;
            case 'yearly':
                return amount / 12;
            default:
                return amount;
        }
    }
}
