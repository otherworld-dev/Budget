/**
 * Transfers Module - Recurring transfer tracking between accounts
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { once } from '../../utils/submitGuard.js';
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { billRowState } from '../../utils/billDates.js';
import { showMatchingTransactionDialog } from '../../utils/matchingDialog.js';
import { showSuccess, showError, showWarning, showUndoNotification } from '../../utils/notifications.js';
import { confirmDialog } from '../../utils/dialogs.js';
import { initSingleDatePicker } from '../../utils/datepicker.js';
import { isoWeekday } from '../../utils/helpers.js';
import { apiFetch } from '../../utils/api.js';
import { offerableTags, offerableTagSets } from '../../utils/tags.js';
import { showLoadError } from '../../utils/loading.js';
import { openAccounts, pickableAccounts, accountOptionLabel, accountCurrency, linkableCandidates } from '../../utils/accounts.js';
import { requestMarkUnpaid } from '../../utils/billUnpaid.js';
import { selectPossiblyUnavailable, unavailableCategoryLabel } from '../../utils/formSelects.js';

/**
 * The date to open the form on for a one-time transfer saved before the
 * form had a date field (#395). Its only date is the next_due_date the
 * server worked out from the day alone, so that is shown - the wrong year
 * in plain view, ready to be corrected - rather than an empty required
 * field. Recurring transfers have no fallback: their start date is optional.
 */
function oneTimeFallbackDate(transfer) {
    if ((transfer.frequency || 'monthly') !== 'one-time') return '';
    return transfer.nextDueDate || transfer.next_due_date || '';
}

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
        const loaded = await this.loadTransfers();
        this.render();
        this.renderTransfers();
        this.updateSummary();
        if (!loaded) {
            // Say it failed rather than showing "No recurring transfers yet"
            const empty = document.getElementById('empty-transfers');
            if (empty) empty.style.display = 'none';
            showLoadError('transfers-list', t('budget', 'Failed to load transfers'), () => this.loadTransfersView());
        }
    }

    /**
     * @returns {Promise<boolean>} false when the fetch failed
     */
    async loadTransfers() {
        try {
            // Active transfers, plus ended ones that can still be reverted
            // (#365); every ended transfer used to stay listed as paid
            this.transfers = await apiFetch('/apps/budget/api/bills?isTransfer=true&revertibleToo=true');
            return true;
        } catch (error) {
            console.error('Failed to load transfers:', error);
            showError(t('budget', 'Failed to load transfers'));
            return false;
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
                    <button id="detect-transfers-btn" class="secondary" aria-label="${t('budget', 'Detect recurring transfers')}">
                        <span class="icon-search" aria-hidden="true"></span>
                        ${t('budget', 'Find Transfers')}
                    </button>
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

                <!-- Detected Transfers Panel -->
                <div class="detected-bills-panel" id="detected-transfers-panel" style="display: none;">
                    <div class="detected-bills-header">
                        <h3>${t('budget', 'Detected Recurring Transfers')}</h3>
                        <button class="icon-close" id="close-detected-transfers-panel" title="${t('budget', 'Close')}" aria-label="${t('budget', 'Close')}"></button>
                    </div>
                    <p class="detected-bills-description">${t('budget', 'These recurring transactions may be transfers between your accounts. Select the ones to add and choose the destination account.')}</p>
                    <div id="detected-transfers-list" class="detected-bills-list"></div>
                    <div class="detected-bills-actions">
                        <button class="primary" id="add-detected-transfers-btn">${t('budget', 'Add Selected as Transfers')}</button>
                        <button class="secondary" id="cancel-detected-transfers-btn">${t('budget', 'Cancel')}</button>
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
            // Detect transfers button
            if (e.target.closest('#detect-transfers-btn')) {
                e.preventDefault();
                this.detectTransfers();
            }
            // Close detected panel
            if (e.target.closest('#close-detected-transfers-panel') || e.target.closest('#cancel-detected-transfers-btn')) {
                e.preventDefault();
                const panel = document.getElementById('detected-transfers-panel');
                if (panel) panel.style.display = 'none';
            }
            // Add selected detected transfers
            if (e.target.closest('#add-detected-transfers-btn')) {
                e.preventDefault();
                this.addSelectedDetectedTransfers();
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

        // Skip an occurrence (#396)
        document.addEventListener('click', (e) => {
            const skipBtn = e.target.closest('.transfer-skip-btn');
            if (skipBtn) {
                e.preventDefault();
                const transferId = parseInt(skipBtn.dataset.transferId);
                this.skipTransfer(transferId);
            }
        });

        // Mark transfer as unpaid (revert the last payment, #365)
        document.addEventListener('click', (e) => {
            const unpaidBtn = e.target.closest('.transfer-unpaid-btn');
            if (unpaidBtn) {
                e.preventDefault();
                const transferId = parseInt(unpaidBtn.dataset.transferId);
                this.markTransferUnpaid(transferId);
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

        const today = formatters.getTodayDateString();
        transfersList.innerHTML = this.transfers.map(transfer => {
            // Status, date and actions follow the transfer's next occurrence,
            // not the calendar month (#399); an inactive transfer offers
            // nothing to pay, which markPaid would still execute (#365)
            const row = billRowState(transfer, today, this.settings, this.accounts);
            const statusClass = row.status;
            const statusText = row.statusText;

            const frequency = transfer.frequency || 'monthly';
            const frequencyLabels = {
                'one-time': t('budget', 'One-Time'),
                'weekly': t('budget', 'Weekly'),
                'biweekly': t('budget', 'Bi-Weekly'),
                'semi-monthly': t('budget', 'Semi-Monthly'),
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
                        <div class="bill-amount">${formatters.formatCurrency(transfer.amount, transfer.currency || null, this.settings)}${this.amountTypeBadge(transfer.amountType)}</div>
                    </div>
                    <div class="bill-details">
                        <div class="bill-due-date">
                            <span class="icon-calendar" aria-hidden="true"></span>
                            ${dom.escapeHtml(row.dateText)}
                        </div>
                        <div class="bill-status ${statusClass}">
                            <span class="status-badge">${statusText}</span>
                            ${autoPayEnabled ? `<span class="status-badge badge-extra auto-pay" title="${t('budget', 'Auto-pay enabled')}"><span class="icon-checkmark"></span> ${t('budget', 'Auto-pay')}</span>` : ''}
                            ${autoPayFailed ? `<span class="status-badge badge-extra auto-pay-failed" title="${t('budget', 'Auto-pay failed - disabled')}"><span class="icon-error"></span> ${t('budget', 'Auto-pay Failed')}</span>` : ''}
                        </div>
                    </div>
                    ${row.accountHint ? `<p class="bill-account-hint">${dom.escapeHtml(row.accountHint)}</p>` : ''}
                    <div class="bill-actions">
                        ${row.canPay ? `
                            <button class="bill-action-btn transfer-paid-btn" data-transfer-id="${transfer.id}" title="${t('budget', 'Mark as paid')}">
                                <span class="icon-checkmark" aria-hidden="true"></span>
                                ${t('budget', 'Mark Paid')}
                            </button>
                        ` : ''}
                        ${row.canSkip ? `
                            <button class="bill-action-btn transfer-skip-btn" data-transfer-id="${transfer.id}" title="${t('budget', 'Skip this payment')}">
                                <span aria-hidden="true">&#x23ED;</span>
                                ${t('budget', 'Skip')}
                            </button>
                        ` : ''}
                        ${row.canUnpay ? `
                            <button class="bill-action-btn transfer-unpaid-btn" data-transfer-id="${transfer.id}" title="${t('budget', 'Revert the last payment')}">
                                <span class="icon-history" aria-hidden="true"></span>
                                ${t('budget', 'Mark Unpaid')}
                            </button>
                        ` : ''}
                        ${row.canWrite ? `<button class="bill-action-btn transfer-edit-btn" data-transfer-id="${transfer.id}" title="${t('budget', 'Edit transfer')}" aria-label="${t('budget', 'Edit transfer')}">
                            <span class="icon-rename" aria-hidden="true"></span>
                        </button>` : ''}
                        ${transfer._shared && !transfer._canManage ? '' : `<button class="bill-action-btn transfer-delete-btn" data-transfer-id="${transfer.id}" title="${t('budget', 'Delete transfer')}" aria-label="${t('budget', 'Delete transfer')}">
                            <span class="icon-delete" aria-hidden="true"></span>
                        </button>`}
                    </div>
                </div>
            `;
        }).join('');
    }

    filterTransfers(filter) {
        // The cards carry their status. This looked for .bill-item cards with
        // a data-id, which the list no longer renders, so no tab filtered
        const shows = {
            all: () => true,
            due: (status) => status === 'due-soon',
            overdue: (status) => status === 'overdue',
            completed: (status) => status === 'paid',
        };
        const show = shows[filter] || shows.all;
        document.querySelectorAll('#transfers-list .bill-card').forEach(card => {
            card.style.display = show(card.dataset.status) ? '' : 'none';
        });
    }

    async updateSummary() {
        // Dates compared as Y-m-d text: parsed with new Date() they are UTC
        // midnight, the evening before west of UTC, so a transfer due or
        // paid on the 1st counted as last month's
        const today = formatters.getTodayDateString();
        const thisMonth = today.slice(0, 7);
        const activeCount = this.transfers.filter(tx => tx.isActive).length;
        const dueThisMonth = this.transfers.filter(tx => {
            if (!tx.isActive) return false;
            const dueDate = tx.nextDueDate || tx.next_due_date;
            return !!dueDate && dueDate.slice(0, 7) === thisMonth;
        }).length;

        const completedThisMonth = this.transfers.filter(tx => {
            const lastPaid = tx.lastPaidDate || tx.last_paid_date;
            return !!lastPaid && lastPaid.slice(0, 7) === thisMonth;
        }).length;

        document.getElementById('transfers-active-count').textContent = activeCount;
        document.getElementById('transfers-due-count').textContent = dueThisMonth;
        document.getElementById('transfers-completed-count').textContent = completedThisMonth;

        // Monthly Total comes from the server, which converts each transfer
        // from its account's currency to the base one. Adding the amounts
        // here put euros and dollars into a pound total as they were.
        try {
            const summary = await apiFetch('/apps/budget/api/bills/summary?isTransfer=true');
            const total = document.getElementById('transfers-monthly-total');
            if (total) {
                total.textContent = formatters.formatCurrency(summary.monthlyTotal || 0, summary.baseCurrency || null, this.settings);
            }
        } catch (error) {
            console.error('Failed to load transfers summary:', error);
        }
    }

    showTransferModal(transfer = null, prefill = null) {
        const isEdit = transfer !== null;
        const title = isEdit ? t('budget', 'Edit Transfer') : t('budget', 'Add Transfer');

        const modalHtml = `
            <div class="budget-modal-overlay modal-columns modal-columns-2">
                <div class="budget-modal modal-content">
                    <div class="budget-modal-header modal-head">
                        <h2>${title}</h2>
                        <button class="close-btn" id="close-transfer-modal" aria-label="${t('budget', 'Close')}">×</button>
                    </div>
                    <form id="transfer-form" class="wide-form">
                        <div class="modal-scroll">
                          <div class="form-columns form-columns-2">
                            <div class="form-col">
                              <div class="form-block">
                                <h4>${t('budget', 'Transfer')}</h4>
                                <div class="form-group">
                                <label for="transfer-name">${t('budget', 'Name')} <span class="required">*</span></label>
                                <input type="text" id="transfer-name"
                                placeholder="${t('budget', 'e.g., Monthly Savings')}" required
                                value="${isEdit ? dom.escapeHtml(transfer.name) : ''}">
                                </div>

                                <div class="form-group">
                                <label for="transfer-amount">${t('budget', 'Amount')} <span class="required">*</span></label>
                                <input type="number" id="transfer-amount"
                                step="0.01" min="0" required
                                value="${isEdit ? transfer.amount : ''}">
                                </div>

                                <div class="form-group" id="transfer-amount-type-group" style="display: none;">
                                <label for="transfer-amount-type">${t('budget', 'Amount type')}</label>
                                <select id="transfer-amount-type">
                                <option value="fixed">${t('budget', 'Fixed amount')}</option>
                                <option value="statement" ${isEdit && transfer.amountType === 'statement' ? 'selected' : ''}>${t('budget', 'Statement balance')}</option>
                                <option value="current_balance" ${isEdit && transfer.amountType === 'current_balance' ? 'selected' : ''}>${t('budget', 'Current balance')}</option>
                                <option value="minimum_payment" ${isEdit && transfer.amountType === 'minimum_payment' ? 'selected' : ''}>${t('budget', 'Minimum payment')}</option>
                                </select>
                                <small class="form-text" id="transfer-amount-type-hint"></small>
                                </div>

                                <div class="form-group">
                                <label for="transfer-frequency">${t('budget', 'Frequency')} <span class="required">*</span></label>
                                <select id="transfer-frequency" required>
                                <option value="one-time" ${isEdit && transfer.frequency === 'one-time' ? 'selected' : ''}>${t('budget', 'One-Time')}</option>
                                <option value="daily" ${isEdit && transfer.frequency === 'daily' ? 'selected' : ''}>${t('budget', 'Daily')}</option>
                                <option value="weekly" ${isEdit && transfer.frequency === 'weekly' ? 'selected' : ''}>${t('budget', 'Weekly')}</option>
                                <option value="biweekly" ${isEdit && transfer.frequency === 'biweekly' ? 'selected' : ''}>${t('budget', 'Bi-Weekly')}</option>
                                <option value="semi-monthly" ${isEdit && transfer.frequency === 'semi-monthly' ? 'selected' : ''}>${t('budget', 'Semi-Monthly')}</option>
                                <option value="monthly" ${!isEdit || transfer.frequency === 'monthly' ? 'selected' : ''}>${t('budget', 'Monthly')}</option>
                                <option value="quarterly" ${isEdit && transfer.frequency === 'quarterly' ? 'selected' : ''}>${t('budget', 'Quarterly')}</option>
                                <option value="semi-annually" ${isEdit && transfer.frequency === 'semi-annually' ? 'selected' : ''}>${t('budget', 'Semi-Annually')}</option>
                                <option value="yearly" ${isEdit && transfer.frequency === 'yearly' ? 'selected' : ''}>${t('budget', 'Yearly')}</option>
                                ${isEdit && transfer.frequency === 'custom' ? `<option value="custom" selected>${t('budget', 'Custom')}</option>` : ''}
                                </select>
                                </div>

                                <div class="form-group">
                                <label for="recurring-transfer-from-account">${t('budget', 'From Account')} <span class="required">*</span></label>
                                <select id="recurring-transfer-from-account" required>
                                <option value="">${t('budget', 'Select account...')}</option>
                                ${pickableAccounts(this.accounts, isEdit ? [transfer.accountId, transfer.destinationAccountId] : []).map(account => `
                                <option value="${account.id}" ${isEdit && transfer.accountId === account.id ? 'selected' : ''}>
                                ${dom.escapeHtml(accountOptionLabel(account))}
                                </option>
                                `).join('')}
                                </select>
                                </div>

                                <div class="form-group">
                                <label for="recurring-transfer-to-account">${t('budget', 'To Account')} <span class="required">*</span></label>
                                <select id="recurring-transfer-to-account" required>
                                <option value="">${t('budget', 'Select account...')}</option>
                                ${pickableAccounts(this.accounts, isEdit ? [transfer.accountId, transfer.destinationAccountId] : []).map(account => `
                                <option value="${account.id}" ${isEdit && transfer.destinationAccountId === account.id ? 'selected' : ''}>
                                ${dom.escapeHtml(accountOptionLabel(account))}
                                </option>
                                `).join('')}
                                </select>
                                </div>

                                <div class="form-group" id="transfer-due-day-group">
                                <label for="transfer-due-day" id="transfer-due-day-label">${t('budget', 'Day of Month (1-31)')}</label>
                                <input type="number" id="transfer-due-day"
                                min="1" max="31" placeholder="${t('budget', 'e.g., 15')}"
                                value="${isEdit && transfer.dueDay ? transfer.dueDay : ''}">
                                <small class="form-text" id="transfer-due-day-help"></small>
                                </div>

                                <div class="form-group" id="transfer-due-month-group" style="display: none;">
                                <label for="transfer-due-month">${t('budget', 'Due Month')}</label>
                                <select id="transfer-due-month">
                                ${['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'].map((m, i) => `<option value="${i + 1}" ${isEdit && transfer.dueMonth === i + 1 ? 'selected' : ''}>${t('budget', m)}</option>`).join('')}
                                </select>
                                <small class="form-text">${t('budget', 'The month the cycle starts from')}</small>
                                </div>

                                <div class="form-group" id="transfer-start-date-group" style="display: none;">
                                <label for="transfer-start-date" id="transfer-start-date-label">${t('budget', 'Start Date')}</label>
                                <input type="date" id="transfer-start-date"
                                value="${isEdit ? (transfer.startDate || oneTimeFallbackDate(transfer)) : ''}">
                                <small class="form-text" id="transfer-start-date-help"></small>
                                </div>
                              </div>
                            </div>
                            <div class="form-col">
                              <div class="form-block">
                                <h4>${t('budget', 'Details')}</h4>
                                <div class="form-group">
                                <label for="transfer-description-pattern">${t('budget', 'Transaction Description Pattern (Optional)')}</label>
                                <input type="text" id="transfer-description-pattern"
                                placeholder="${t('budget', 'e.g., Savings Transfer')}"
                                value="${isEdit && transfer.transferDescriptionPattern ? dom.escapeHtml(transfer.transferDescriptionPattern) : ''}">
                                <small class="form-text">${t('budget', 'Used to match imported transactions')}</small>
                                </div>

                                <div class="form-group">
                                <label for="transfer-category">${t('budget', 'Category')}</label>
                                <select id="transfer-category">
                                <option value="">${t('budget', 'No category')}</option>
                                ${dom.buildCategoryOptionsHtml(this.categoryTree || this.categories, { typeFilter: 'expense', selectedId: isEdit ? transfer.categoryId : null })}
                                </select>
                                <small class="form-text">${t('budget', 'Category for created transactions (optional)')}</small>
                                </div>

                                <div id="transfer-tags-container"></div>

                                <div class="form-group">
                                <label for="transfer-notes">${t('budget', 'Notes')}</label>
                                <textarea id="transfer-notes" rows="3"
                                placeholder="${t('budget', 'Optional notes...')}">${isEdit && transfer.notes ? dom.escapeHtml(transfer.notes) : ''}</textarea>
                                </div>

                                <div class="form-group">
                                <label class="form-check">
                                <input type="checkbox" id="transfer-create-transaction"
                                ${isEdit ? '' : ''}>
                                <span>${t('budget', 'Also create transactions now')}</span>
                                </label>
                                <small class="form-text">${t('budget', 'Creates paired debit/credit transactions immediately')}</small>
                                </div>

                                <div class="form-group" id="transfer-transaction-date-group" style="display: none;">
                                <label for="transfer-transaction-date">${t('budget', 'Transaction Date')}</label>
                                <input type="date" id="transfer-transaction-date">
                                <small class="form-text">${t('budget', 'Leave empty to use next due date')}</small>
                                </div>

                                <div class="form-group">
                                <label class="form-check">
                                <input type="checkbox" id="transfer-auto-pay"
                                ${isEdit && transfer.autoPayEnabled ? 'checked' : ''}>
                                <span>${t('budget', 'Enable auto-pay (automatically create transactions when due)')}</span>
                                </label>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>

                        <div class="modal-buttons">
                            <button type="submit" class="button primary">
                                ${isEdit ? t('budget', 'Update Transfer') : t('budget', 'Add Transfer')}
                            </button>
                            <button type="button" class="button secondary" id="cancel-transfer">${t('budget', 'Cancel')}</button>
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

        // A transfer shared with you may use a category or account its owner
        // didn't share with you, which the pickers can't list: the category
        // read "No category" and saving cleared it off the owner's transfer,
        // and a missing account stopped the form saving at all. They are kept
        // selected and sent back unchanged (#370, as bills and income do).
        // The category is named, as the transfer came; an account isn't.
        if (isEdit) {
            selectPossiblyUnavailable(document.getElementById('transfer-category'), transfer.categoryId ?? null,
                transfer.categoryName ? unavailableCategoryLabel(transfer.categoryName) : null);
            selectPossiblyUnavailable(document.getElementById('recurring-transfer-from-account'), transfer.accountId ?? null);
            selectPossiblyUnavailable(document.getElementById('recurring-transfer-to-account'), transfer.destinationAccountId ?? null);
        }

        // Initialize flatpickr on the transaction date input
        const transferDateInput = document.getElementById('transfer-transaction-date');
        if (transferDateInput) {
            initSingleDatePicker(transferDateInput, this.app.settings);
        }

        // Start date (anchors weekly/biweekly schedules server-side, #364).
        // Choosing one derives and locks the weekday field.
        const transferStartDateInput = document.getElementById('transfer-start-date');
        if (transferStartDateInput) {
            initSingleDatePicker(transferStartDateInput, this.app.settings);
            transferStartDateInput.addEventListener('change', () => this.updateTransferScheduleFields());
        }

        // Frequency-aware due day / start date fields (mirrors the bills form)
        const transferFrequencySelect = document.getElementById('transfer-frequency');
        if (transferFrequencySelect) {
            transferFrequencySelect.addEventListener('change', () => this.updateTransferScheduleFields());
        }
        this.updateTransferScheduleFields();

        // Category change listener - load tag sets for selected category
        const categorySelect = document.getElementById('transfer-category');
        if (categorySelect) {
            categorySelect.addEventListener('change', () => {
                this.loadTransferTagSets(categorySelect.value || null, isEdit ? transfer : null);
            });
        }
        // The picker always loads (global tags need no category): only
        // loading it for a preselected category left a transfer without one
        // with no boxes, and saving wiped its tags
        this.loadTransferTagSets(categorySelect?.value || null, isEdit ? transfer : null);

        // Dynamic amount types: only offered for card-like destinations (#347)
        const toAccountSelect = document.getElementById('recurring-transfer-to-account');
        const amountTypeGroup = document.getElementById('transfer-amount-type-group');
        const amountTypeSelect = document.getElementById('transfer-amount-type');
        const amountTypeHint = document.getElementById('transfer-amount-type-hint');
        const amountInput = document.getElementById('transfer-amount');
        const amountTypeHints = {
            statement: t('budget', 'Pays what was owed at the due date. Later charges roll to the next payment'),
            current_balance: t('budget', 'Pays everything owed on the card at payment time'),
            minimum_payment: t('budget', "Pays the card's minimum payment, never more than is owed"),
        };
        const updateAmountTypeVisibility = () => {
            const destination = this.accounts.find(a => a.id === parseInt(toAccountSelect.value));
            // A destination not shared with you can't be looked at, so the
            // transfer keeps the amount type it has rather than dropping to
            // fixed (the server refuses switching it to a dynamic one)
            const hiddenDestination = !destination && toAccountSelect.selectedOptions[0]?.dataset.unavailable === '1';
            const cardLike = hiddenDestination
                ? isEdit && (transfer.amountType || 'fixed') !== 'fixed'
                : !!destination && ['credit_card', 'line_of_credit'].includes(destination.type);
            if (!cardLike && amountTypeSelect.value !== 'fixed') {
                amountTypeSelect.value = 'fixed';
            }
            amountTypeGroup.style.display = cardLike ? 'block' : 'none';
            const isDynamic = cardLike && amountTypeSelect.value !== 'fixed';
            amountTypeHint.textContent = isDynamic ? amountTypeHints[amountTypeSelect.value] : '';
            amountInput.disabled = isDynamic;
            amountInput.required = !isDynamic;
            amountInput.placeholder = isDynamic ? t('budget', 'Resolved from the card at each payment') : '';
        };
        toAccountSelect.addEventListener('change', updateAmountTypeVisibility);
        amountTypeSelect.addEventListener('change', updateAmountTypeVisibility);

        if (!isEdit && prefill) {
            if (prefill.name) document.getElementById('transfer-name').value = prefill.name;
            if (prefill.destinationAccountId) toAccountSelect.value = String(prefill.destinationAccountId);
            if (prefill.dueDay) document.getElementById('transfer-due-day').value = String(prefill.dueDay);
            if (prefill.amountType) amountTypeSelect.value = prefill.amountType;
        }
        updateAmountTypeVisibility();

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

    /**
     * Make the schedule fields follow the frequency, exactly like the bills
     * form: weekly/biweekly take a weekday (1-7), everything else a day of
     * month (1-31). The start date shows for every recurring frequency —
     * for weekly/biweekly it anchors the schedule (weekday + fortnight,
     * #364), for the rest it floors the first occurrence (#268). For a
     * one-time transfer the same field is the due date and the whole
     * schedule, with the day of month hidden (#395, as bills since #375).
     */
    updateTransferScheduleFields() {
        const frequency = document.getElementById('transfer-frequency')?.value;
        const dueDayGroup = document.getElementById('transfer-due-day-group');
        const dueDayLabel = document.getElementById('transfer-due-day-label');
        const dueDayInput = document.getElementById('transfer-due-day');
        const dueDayHelp = document.getElementById('transfer-due-day-help');
        const startDateInput = document.getElementById('transfer-start-date');
        const startDateGroup = document.getElementById('transfer-start-date-group');
        const startDateLabel = document.getElementById('transfer-start-date-label');
        const startDateHelp = document.getElementById('transfer-start-date-help');
        if (!frequency || !dueDayLabel || !dueDayInput) return;

        // A one-time transfer has no day of month of its own: with only a
        // day to go on the server had to guess the month, and guessed
        // January, so "day 28" entered in September fell due the following
        // January. The date below is its schedule; day and month follow it
        // on save (#395)
        const isOneTime = frequency === 'one-time';
        if (dueDayGroup) dueDayGroup.style.display = isOneTime ? 'none' : 'block';
        // The month a yearly, half-yearly or quarterly transfer's cycle
        // starts from. There was no field: every yearly transfer fell in
        // January and quarterly ones on the January grid
        const dueMonthGroup = document.getElementById('transfer-due-month-group');
        if (dueMonthGroup) {
            dueMonthGroup.style.display = ['yearly', 'semi-annually', 'quarterly'].includes(frequency) ? 'block' : 'none';
        }
        if (startDateGroup) startDateGroup.style.display = 'block';
        if (startDateLabel) {
            startDateLabel.textContent = isOneTime ? t('budget', 'Due Date') : t('budget', 'Start Date');
        }
        if (startDateInput) startDateInput.required = isOneTime;
        if (isOneTime) {
            if (startDateHelp) {
                startDateHelp.textContent = t('budget', 'The date this transfer is due. It can be in the past.');
            }
            return;
        }

        const isAnchored = frequency === 'weekly' || frequency === 'biweekly';
        if (isAnchored) {
            dueDayLabel.textContent = t('budget', 'Due Day (1-7)');
            if (dueDayHelp) dueDayHelp.textContent = t('budget', 'Day of the week (1=Monday, 7=Sunday)');
            dueDayInput.max = 7;
        } else {
            dueDayLabel.textContent = t('budget', 'Day of Month (1-31)');
            if (dueDayHelp) dueDayHelp.textContent = t('budget', 'Day of the month when the transfer is due');
            dueDayInput.max = 31;
        }

        // With a start date set, the server anchors the schedule to it and
        // ignores the weekday input entirely — mirror that here instead of
        // letting two contradicting inputs sit side by side (#364 review)
        const anchorValue = isAnchored ? (startDateInput?.value || '') : '';
        if (anchorValue) {
            dueDayInput.value = String(isoWeekday(anchorValue));
            dueDayInput.disabled = true;
            if (dueDayHelp) dueDayHelp.textContent = t('budget', 'Follows the start date');
        } else {
            dueDayInput.disabled = false;
        }

        if (startDateHelp) {
            startDateHelp.textContent = isAnchored
                ? t('budget', 'The transfer repeats from this date (optional)')
                : t('budget', 'Transfer only occurs on or after this date (optional)');
        }
    }

    /** One at a time: a double click created two (see utils/submitGuard.js) */
    saveTransfer(existingTransfer = null) {
        return once('transfer-save', document.querySelector('#transfer-form [type="submit"]'), () => this._saveTransfer(existingTransfer));
    }

    async _saveTransfer(existingTransfer = null) {
        const name = document.getElementById('transfer-name').value;
        const amountType = document.getElementById('transfer-amount-type')?.value || 'fixed';
        const amountValue = parseFloat(document.getElementById('transfer-amount').value);
        // Dynamic-amount bills resolve server-side; the input is disabled
        const amount = amountType !== 'fixed' ? (isNaN(amountValue) ? 0 : amountValue) : amountValue;
        const frequency = document.getElementById('transfer-frequency').value;
        const fromAccountId = parseInt(document.getElementById('recurring-transfer-from-account').value);
        const toAccountId = parseInt(document.getElementById('recurring-transfer-to-account').value);
        let dueDay = document.getElementById('transfer-due-day').value ?
                     parseInt(document.getElementById('transfer-due-day').value) : null;
        let dueMonth;
        const startDate = document.getElementById('transfer-start-date')?.value || null;
        // A one-time transfer's date is its whole schedule: the day and
        // month follow it, and the hidden day-of-month input may still hold
        // a previous frequency's value (#395, as bills since #375)
        if (frequency === 'one-time') {
            if (!startDate) {
                showWarning(t('budget', 'Please enter the date the transfer is due'));
                return false;
            }
            const [, month, day] = startDate.split('-').map(Number);
            if (month && day) {
                dueDay = day;
                dueMonth = month;
            }
        }
        if (['yearly', 'semi-annually', 'quarterly'].includes(frequency)) {
            dueMonth = parseInt(document.getElementById('transfer-due-month')?.value) || null;
        }
        const transferDescriptionPattern = document.getElementById('transfer-description-pattern').value || null;
        const categoryId = document.getElementById('transfer-category')?.value ? parseInt(document.getElementById('transfer-category').value) : null;
        const tagIds = this.getSelectedTagIds();
        const notes = document.getElementById('transfer-notes').value || null;
        const createTransaction = document.getElementById('transfer-create-transaction')?.checked || false;
        const transactionDate = document.getElementById('transfer-transaction-date')?.value || null;
        const autoPayEnabled = document.getElementById('transfer-auto-pay').checked;

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
            amountType,
            frequency,
            accountId: fromAccountId,
            destinationAccountId: toAccountId,
            dueDay,
            ...(dueMonth !== undefined ? { dueMonth } : {}),
            startDate,
            transferDescriptionPattern,
            categoryId,
            tagIds,
            notes,
            autoPayEnabled,
            isTransfer: true
        };
        // "Also create transactions now" only applies when adding one. On the
        // server createTransaction is the pre-booking setting, so sending the
        // unticked box with every edit switched a transfer's pre-booking off
        if (!existingTransfer) {
            data.createTransaction = createTransaction;
            data.transactionDate = transactionDate;
        }

        try {
            const url = existingTransfer ?
                `/apps/budget/api/bills/${existingTransfer.id}` :
                '/apps/budget/api/bills';

            const method = existingTransfer ? 'PUT' : 'POST';

            await apiFetch(url, {
                method,
                body: data,
                errorMessage: t('budget', 'Failed to save transfer'),
            });

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
        if (!await confirmDialog(t('budget', 'Are you sure you want to delete this transfer?'), { destructive: true })) {
            return;
        }

        try {
            await apiFetch(`/apps/budget/api/bills/${transferId}`, {
                method: 'DELETE',
                errorMessage: t('budget', 'Failed to delete transfer'),
            });

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
            await apiFetch(`/apps/budget/api/bills/${transferId}`, {
                method: 'PUT',
                body: {
                    active: !transfer.isActive
                },
                errorMessage: t('budget', 'Failed to update transfer'),
            });

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
            // A withdrawal already in the source account (from a statement or
            // bank sync) may be this transfer: offer to link it, as the Bills
            // page does. Booking a new pair moved the money a second time.
            let choice = { action: 'create' };
            if (transfer.accountId || transfer.account_id) {
                const found = await apiFetch(`/apps/budget/api/bills/${transferId}/matching-transactions`)
                    .catch(() => null);
                // Only rows the payment can be linked to
                const candidates = found ? linkableCandidates(found, this.accounts) : null;
                if (candidates && candidates.length > 0) {
                    choice = await showMatchingTransactionDialog(transfer, candidates, this.settings);
                    if (choice === null) {
                        return; // cancelled
                    }
                }
            }

            // Use the dedicated mark-paid endpoint so the paired transfer
            // transactions are actually created. A plain PUT of lastPaidDate
            // records the date but creates no account entries (#291). It
            // names the occurrence the row showed, so a second click is
            // refused rather than paid twice.
            const body = {
                recordPayment: choice.action === 'create',
                dueDate: transfer.nextDueDate || transfer.next_due_date || null,
            };
            if (choice.action === 'link') {
                body.existingTransactionId = choice.transactionId;
            } else {
                body.paidDate = formatters.getTodayDateString();
            }
            const result = await apiFetch(`/apps/budget/api/bills/${transferId}/paid`, {
                method: 'POST',
                body,
                errorMessage: t('budget', 'Failed to mark transfer as paid'),
            });

            if (result && result.paymentTransactionRecorded === false) {
                // Marked paid, but no money moved: say so rather than report
                // a transfer that never reached either account
                showWarning(t('budget', 'The transfer was marked as paid, but no transactions were recorded. Check both accounts, or add the transfer manually.'));
            } else {
                showSuccess(t('budget', 'Transfer marked as paid'));
            }

            await this.loadTransfers();
            this.renderTransfers();
            this.updateSummary();
        } catch (error) {
            console.error('Failed to mark transfer as paid:', error);
            showError(error.message || t('budget', 'Failed to mark transfer as paid'));
        }
    }

    /**
     * Skip the next occurrence of a transfer (#396). The bill skip endpoint
     * already handles a transfer - both pre-booked legs for the skipped date
     * go, and the next pair is pre-booked - so this is the bills list's Skip
     * with the same confirm and the same short-lived undo.
     */
    async skipTransfer(transferId) {
        const transfer = this.transfers.find(tx => tx.id === transferId);
        if (!transfer) return;

        if (!await confirmDialog(t('budget', 'Skip this payment and advance to the next due date?'))) {
            return;
        }

        try {
            const result = await apiFetch(`/apps/budget/api/bills/${transferId}/skip`, {
                method: 'POST',
                errorMessage: t('budget', 'Failed to skip transfer'),
            });
            const undoData = {
                transferId,
                previousNextDueDate: result.previousNextDueDate ?? null,
                action: 'skip'
            };
            this._undoData = undoData;

            await this.loadTransfers();
            this.renderTransfers();
            this.updateSummary();

            // The toast undoes this skip, whatever was done since
            showUndoNotification(
                t('budget', 'Payment skipped. Advanced to next due date.'),
                () => this.undoSkipTransfer(undoData),
                () => this._dropUndo(undoData)
            );
        } catch (error) {
            console.error('Failed to skip transfer:', error);
            showError(error.message || t('budget', 'Failed to skip transfer'));
        }
    }

    /**
     * An undo toast ran out: its action can't be undone any more. A later
     * action may have replaced the latest undo data; only drop our own.
     */
    _dropUndo(undoData) {
        undoData.spent = true;
        if (this._undoData === undoData) this._undoData = null;
    }

    /**
     * @param {object} undoData - The skip to undo; each toast passes its own,
     *   so an older toast never reverts a later action
     */
    async undoSkipTransfer(undoData = this._undoData) {
        if (!undoData || undoData.spent || undoData.action !== 'skip') {
            return;
        }
        undoData.spent = true;

        try {
            const { transferId, previousNextDueDate } = undoData;

            await apiFetch(`/apps/budget/api/bills/${transferId}/undo-skip`, {
                method: 'POST',
                body: { previousNextDueDate },
            });

            if (this._undoData === undoData) this._undoData = null;
            await this.loadTransfers();
            this.renderTransfers();
            this.updateSummary();

            showSuccess(t('budget', 'Action undone'));
        } catch (error) {
            console.error('Failed to undo skip:', error);
            showError(t('budget', 'Failed to undo action: {message}', { message: error.message }));
        }
    }

    /**
     * Durable "mark as unpaid" for transfer bills (#365): the same snapshot
     * revert as the bills list — including the #347 statement-amount card
     * payments the snapshot restore exists for — with the same confirm copy.
     */
    async markTransferUnpaid(transferId) {
        const transfer = this.transfers.find(tx => tx.id === transferId);
        let message = t('budget', 'Mark this bill as unpaid? Transactions created for the payment will be deleted, a linked imported transaction will be unlinked, and the bill schedule rolled back.');
        const autoPayEnabled = transfer ? (transfer.autoPayEnabled ?? transfer.auto_pay_enabled ?? false) : false;
        if (autoPayEnabled) {
            message += '\n\n' + t('budget', 'Auto-pay is on for this bill — it may pay it again on the next run. Disable auto-pay first if the payment should not recur.');
        }
        if (!await confirmDialog(message, { destructive: true })) {
            return;
        }

        try {
            // Asks again when the payment was reconciled against a statement
            if (!await requestMarkUnpaid(transferId, t('budget', 'Failed to mark transfer as unpaid'))) {
                return;
            }

            await this.loadTransfers();
            this.renderTransfers();
            this.updateSummary();
            showSuccess(t('budget', 'Payment reverted — the transfer is marked as unpaid.'));
        } catch (error) {
            console.error('Failed to mark transfer as unpaid:', error);
            showError(error.message || t('budget', 'Failed to mark transfer as unpaid'));
        }
    }

    // Helper methods
    amountTypeBadge(amountType) {
        const labels = {
            statement: t('budget', 'Statement'),
            current_balance: t('budget', 'Full balance'),
            minimum_payment: t('budget', 'Minimum'),
        };
        if (!labels[amountType]) return '';
        return `<span class="statement-badge" title="${t('budget', 'Amount is resolved from the card at each payment')}">${labels[amountType]}</span>`;
    }

    formatFrequency(frequency) {
        const map = {
            'weekly': t('budget', 'Weekly'),
            'biweekly': t('budget', 'Bi-Weekly'),
            'semi-monthly': t('budget', 'Semi-Monthly'),
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
            // Load global tags and category tag sets in parallel; a category
            // not shared with you has none you can read (the server says 400)
            const listed = categoryId && (!this.categories?.length || this.categories.some(c => String(c.id) === String(categoryId)));
            const [globalTagsResponse, categoryTagSets] = await Promise.all([
                apiFetch('/apps/budget/api/tags/global').catch(() => []),
                listed ? apiFetch(`/apps/budget/api/tag-sets?categoryId=${categoryId}`).catch(() => []) : Promise.resolve([])
            ]);

            // Get existing tag IDs if editing
            const existingTagIds = existingTransfer?.tagIds || [];

            // Hidden tags are not offered, except ones already on this
            // transfer (#373) — saving reads every checked box, so an
            // unlisted tag would be stripped on the next edit.
            const globalTags = offerableTags(globalTagsResponse || [], existingTagIds);
            const tagSets = offerableTagSets(categoryTagSets || [], existingTagIds);

            if (globalTags.length === 0 && tagSets.length === 0) {
                container.innerHTML = '';
                return;
            }

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

    // ── Detect Transfers ────────────────────────────────────

    async detectTransfers() {
        const detectBtn = document.getElementById('detect-transfers-btn');
        if (!detectBtn) return;
        detectBtn.disabled = true;
        detectBtn.innerHTML = `<span class="icon-loading-small" aria-hidden="true"></span> ${t('budget', 'Detecting...')}`;

        try {
            // Detect Bills leaves out debits linked to a transfer's other leg;
            // here they are exactly what we're looking for
            const detected = await apiFetch('/apps/budget/api/bills/detect?months=6&transfers=true');

            if (!detected || detected.length === 0) {
                showWarning(t('budget', 'No recurring transactions detected'));
                return;
            }

            this.renderDetectedTransfers(detected);
            document.getElementById('detected-transfers-panel').style.display = 'flex';
        } catch (error) {
            showError(t('budget', 'Failed to detect recurring transfers'));
        } finally {
            detectBtn.disabled = false;
            detectBtn.innerHTML = `<span class="icon-search" aria-hidden="true"></span> ${t('budget', 'Find Transfers')}`;
        }
    }

    renderDetectedTransfers(detected) {
        const list = document.getElementById('detected-transfers-list');
        if (!list) return;

        const accounts = openAccounts(this.accounts);
        const accountOptions = accounts.map(a =>
            `<option value="${a.id}">${dom.escapeHtml(a.name)} (${a.currency || 'USD'})</option>`
        ).join('');

        list.innerHTML = detected.map((item, index) => {
            const confidenceClass = item.confidence >= 0.8 ? 'high' : item.confidence >= 0.5 ? 'medium' : 'low';
            const confidencePercent = Math.round(item.confidence * 100);
            const sourceAccount = accounts.find(a => a.id === item.accountId);
            const sourceName = sourceAccount ? sourceAccount.name : t('budget', 'Unknown');

            return `
                <div class="detected-bill-item" data-index="${index}">
                    <div class="detected-bill-select">
                        <input type="checkbox" id="detected-transfer-${index}" ${item.confidence >= 0.7 ? 'checked' : ''}>
                    </div>
                    <div class="detected-bill-info">
                        <label for="detected-transfer-${index}" class="detected-bill-name">${dom.escapeHtml(item.description || item.suggestedName)}</label>
                        <div class="detected-bill-meta">
                            <span class="detected-amount">${formatters.formatCurrency(item.amount, accountCurrency(this.accounts, item.accountId), this.settings)}</span>
                            <span class="detected-frequency">${item.frequency}</span>
                            <span class="detected-confidence ${confidenceClass}">${/* xgettext:no-javascript-format */ t('budget', '{percent}% confidence', { percent: confidencePercent })}</span>
                            <span>${t('budget', 'From: {account}', { account: sourceName })}</span>
                        </div>
                        <div class="detected-transfer-dest" style="margin-top: 4px;">
                            <label style="font-size: 12px; margin-right: 4px;">${t('budget', 'To:')}</label>
                            <select class="detected-dest-account" data-index="${index}" style="font-size: 12px; padding: 2px 4px;" aria-label="${t('budget', 'To:')}">
                                <option value="">${t('budget', '— Select destination —')}</option>
                                ${accountOptions}
                            </select>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        // Linked legs show where the money went: start with that account
        detected.forEach((item, index) => {
            const select = list.querySelector(`.detected-dest-account[data-index="${index}"]`);
            const suggested = item.suggestedDestinationAccountId;
            if (select && suggested && accounts.some(a => a.id === suggested)) {
                select.value = String(suggested);
            }
        });

        this._detectedTransfers = detected;
    }

    /** One at a time: a double click created two (see utils/submitGuard.js) */
    addSelectedDetectedTransfers() {
        return once('transfers-add-detected', document.getElementById('add-detected-transfers-btn'), () => this._addSelectedDetectedTransfers());
    }

    async _addSelectedDetectedTransfers() {
        const checkboxes = document.querySelectorAll('#detected-transfers-list input[type="checkbox"]:checked');
        const selectedIndices = Array.from(checkboxes).map(cb => parseInt(cb.id.replace('detected-transfer-', '')));

        if (selectedIndices.length === 0) {
            showWarning(t('budget', 'Please select at least one transfer to add'));
            return;
        }

        // Validate destination accounts
        const transfersToAdd = [];
        for (const i of selectedIndices) {
            const destSelect = document.querySelector(`.detected-dest-account[data-index="${i}"]`);
            const destAccountId = destSelect ? parseInt(destSelect.value) : null;

            if (!destAccountId) {
                showWarning(t('budget', 'Please select a destination account for all selected transfers'));
                return;
            }

            const item = this._detectedTransfers[i];
            if (destAccountId === item.accountId) {
                showWarning(t('budget', 'Source and destination accounts must be different'));
                return;
            }

            transfersToAdd.push({
                ...item,
                isTransfer: true,
                destinationAccountId: destAccountId,
            });
        }

        try {
            const result = await apiFetch('/apps/budget/api/bills/create-from-detected', {
                method: 'POST',
                body: { bills: transfersToAdd },
            });
            document.getElementById('detected-transfers-panel').style.display = 'none';
            showSuccess(n('budget', '%n transfer added successfully', '%n transfers added successfully', result.created));
            await this.loadTransfersView();
        } catch (error) {
            showError(t('budget', 'Failed to add selected transfers'));
        }
    }
}
