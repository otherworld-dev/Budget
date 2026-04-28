/**
 * Bills Module - Recurring bill tracking and detection
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError, showWarning, showInfo } from '../../utils/notifications.js';
import { setDateValue, clearDateValue } from '../../utils/datepicker.js';

export default class BillsModule {
    constructor(app) {
        this.app = app;
        this._eventsSetup = false;
        this._detectedBills = [];
        this._undoTimer = null;
        this._undoData = null;
    }

    // Getters for app state
    get bills() { return this.app.bills; }
    set bills(value) { this.app.bills = value; }
    get accounts() { return this.app.accounts; }
    get categories() { return this.app.categories; }
    get categoryTree() { return this.app.categoryTree; }
    get settings() { return this.app.settings; }

    async loadBillsView() {
        try {
            // Load summary first
            await this.loadBillsSummary();

            // Load all bills (excluding transfers)
            const response = await fetch(OC.generateUrl('/apps/budget/api/bills?isTransfer=false'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            this.bills = await response.json();
            this.renderBills(this.bills);

            // Setup event listeners (only once)
            if (!this._eventsSetup) {
                this.setupBillsEventListeners();
                this._eventsSetup = true;
            }

            // Populate dropdowns in bill modal
            this.populateBillModalDropdowns();
        } catch (error) {
            console.error('Failed to load bills:', error);
            showError(t('budget', 'Failed to load bills'));
        }
    }

    async loadBillsSummary() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bills/summary'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const summary = await response.json();

            // Update summary cards
            document.getElementById('bills-due-count').textContent = summary.dueThisMonth || 0;
            document.getElementById('bills-overdue-count').textContent = summary.overdue || 0;
            document.getElementById('bills-monthly-total').textContent = formatters.formatCurrency(summary.monthlyTotal || 0, summary.baseCurrency || null, this.settings);
            document.getElementById('bills-paid-count').textContent = summary.paidThisMonth || 0;
        } catch (error) {
            console.error('Failed to load bills summary:', error);
        }
    }

    renderBills(bills) {
        const billsList = document.getElementById('bills-list');
        const emptyBills = document.getElementById('empty-bills');

        if (!bills || bills.length === 0) {
            billsList.innerHTML = '';
            emptyBills.style.display = 'flex';
            return;
        }

        emptyBills.style.display = 'none';

        billsList.innerHTML = bills.map(bill => {
            const dueDate = bill.nextDueDate || bill.next_due_date;
            const isPaid = this.isBillPaidThisMonth(bill);
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

            const frequency = bill.frequency || 'monthly';
            const frequencyLabels = {
                'weekly': t('budget', 'Weekly'),
                'biweekly': t('budget', 'Bi-Weekly'),
                'monthly': t('budget', 'Monthly'),
                'quarterly': t('budget', 'Quarterly'),
                'semi-annually': t('budget', 'Semi-Annually'),
                'yearly': t('budget', 'Yearly'),
                'one-time': t('budget', 'One-Time')
            };
            const frequencyLabel = frequencyLabels[frequency] || frequency.charAt(0).toUpperCase() + frequency.slice(1);

            const autoPayEnabled = bill.autoPayEnabled ?? bill.auto_pay_enabled ?? false;
            const autoPayFailed = bill.autoPayFailed ?? bill.auto_pay_failed ?? false;
            const remainingPayments = bill.remainingPayments ?? bill.remaining_payments ?? null;
            const endDate = bill.endDate ?? bill.end_date ?? null;
            const hasSplits = Array.isArray(bill.splitTemplate) && bill.splitTemplate.length >= 2;

            return `
                <div class="bill-card ${statusClass}" data-bill-id="${bill.id}" data-status="${statusClass}">
                    <div class="bill-header">
                        <div class="bill-info">
                            <h4 class="bill-name">${dom.escapeHtml(bill.name)}</h4>
                            <span class="bill-frequency">${frequencyLabel}</span>
                        </div>
                        <div class="bill-amount">${formatters.formatCurrency(bill.amount, bill.currency || null, this.settings)}</div>
                    </div>
                    <div class="bill-details">
                        <div class="bill-due-date">
                            <span class="icon-calendar" aria-hidden="true"></span>
                            ${dueDate ? formatters.formatDate(dueDate, this.settings) : t('budget', 'No due date')}
                        </div>
                        <div class="bill-status ${statusClass}">
                            <span class="status-badge">${statusText}</span>
                            ${hasSplits ? `<span class="status-badge" title="${t('budget', 'Split across categories')}" style="background: #6f42c1; margin-left: 5px;">${t('budget', 'Split')}</span>` : ''}
                            ${autoPayEnabled ? `<span class="status-badge auto-pay" title="${t('budget', 'Auto-pay enabled')}" style="background: #007bff; margin-left: 5px;"><span class="icon-checkmark"></span> ${t('budget', 'Auto-pay')}</span>` : ''}
                            ${autoPayFailed ? `<span class="status-badge auto-pay-failed" title="${t('budget', 'Auto-pay failed - disabled')}" style="background: #ffc107; color: #856404; margin-left: 5px;"><span class="icon-error"></span> ${t('budget', 'Auto-pay Failed')}</span>` : ''}
                            ${remainingPayments !== null ? `<span class="status-badge" title="${t('budget', 'Remaining payments')}" style="background: #6c757d; margin-left: 5px;">${t('budget', '{count} left', { count: remainingPayments })}</span>` : ''}
                            ${endDate ? `<span class="status-badge" title="${t('budget', 'Ends {date}', { date: formatters.formatDate(endDate, this.settings) })}" style="background: #6c757d; margin-left: 5px;">${t('budget', 'Ends {date}', { date: formatters.formatDate(endDate, this.settings) })}</span>` : ''}
                        </div>
                    </div>
                    <div class="bill-actions">
                        ${!isPaid ? `
                            <button class="bill-action-btn bill-paid-btn" data-bill-id="${bill.id}" title="${t('budget', 'Mark as paid')}">
                                <span class="icon-checkmark" aria-hidden="true"></span>
                                ${t('budget', 'Mark Paid')}
                            </button>
                        ` : ''}
                        ${!isPaid && frequency !== 'one-time' ? `
                            <button class="bill-action-btn bill-skip-btn" data-bill-id="${bill.id}" title="${t('budget', 'Skip this payment')}">
                                <span aria-hidden="true">&#x23ED;</span>
                                ${t('budget', 'Skip')}
                            </button>
                        ` : ''}
                        <button class="bill-action-btn bill-edit-btn" data-bill-id="${bill.id}" title="${t('budget', 'Edit bill')}">
                            <span class="icon-rename" aria-hidden="true"></span>
                        </button>
                        <button class="bill-action-btn bill-delete-btn" data-bill-id="${bill.id}" title="${t('budget', 'Delete bill')}">
                            <span class="icon-delete" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    }

    isBillPaidThisMonth(bill) {
        const lastPaid = bill.lastPaidDate || bill.last_paid_date;
        if (!lastPaid) return false;

        const paidDate = new Date(lastPaid);
        const now = new Date();
        return paidDate.getMonth() === now.getMonth() && paidDate.getFullYear() === now.getFullYear();
    }

    isDueSoon(dateStr) {
        const diffDays = formatters.daysBetweenDates(formatters.getTodayDateString(), dateStr);
        return diffDays >= 0 && diffDays <= 7;
    }

    filterBills(filter) {
        const billCards = document.querySelectorAll('.bill-card');
        billCards.forEach(card => {
            const status = card.dataset.status;
            let show = false;

            switch (filter) {
                case 'all':
                    show = true;
                    break;
                case 'due':
                    show = status === 'due-soon' || status === 'upcoming';
                    break;
                case 'overdue':
                    show = status === 'overdue';
                    break;
                case 'paid':
                    show = status === 'paid';
                    break;
                default:
                    show = true;
            }

            card.style.display = show ? 'flex' : 'none';
        });
    }

    setupBillsEventListeners() {
        // Add bill button
        const addBillBtn = document.getElementById('add-bill-btn');
        if (addBillBtn) {
            addBillBtn.addEventListener('click', () => this.showBillModal());
        }

        // Empty state add button
        const emptyBillsAddBtn = document.getElementById('empty-bills-add-btn');
        if (emptyBillsAddBtn) {
            emptyBillsAddBtn.addEventListener('click', () => this.showBillModal());
        }

        // Detect bills button
        const detectBillsBtn = document.getElementById('detect-bills-btn');
        if (detectBillsBtn) {
            detectBillsBtn.addEventListener('click', () => this.detectBills());
        }

        // Bill form submission
        const billForm = document.getElementById('bill-form');
        if (billForm) {
            billForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveBill();
            });
        }

        // Bill modal cancel button
        const billModal = document.getElementById('bill-modal');
        if (billModal) {
            const cancelBtn = billModal.querySelector('.cancel-btn');
            if (cancelBtn) {
                cancelBtn.addEventListener('click', () => this.hideBillModal());
            }
        }

        // Bill frequency change (show/hide due month for yearly)
        const billFrequency = document.getElementById('bill-frequency');
        if (billFrequency) {
            billFrequency.addEventListener('change', () => this.updateBillFormFields());
        }

        // Create transaction checkbox (show/hide date field)
        const createTransactionCheckbox = document.getElementById('bill-create-transaction');
        if (createTransactionCheckbox) {
            createTransactionCheckbox.addEventListener('change', (e) => {
                const dateGroup = document.getElementById('transaction-date-group');
                if (dateGroup) {
                    dateGroup.style.display = e.target.checked ? 'block' : 'none';
                }
            });
        }

        // Category change listener - load tag sets for selected category
        const billCategory = document.getElementById('bill-category');
        if (billCategory) {
            billCategory.addEventListener('change', () => {
                this.loadBillTagSets(billCategory.value || null, null);
            });
        }

        // Account dropdown change (enable/disable auto-pay checkbox + show currency)
        const billAccount = document.getElementById('bill-account');
        const autoPayCheckbox = document.getElementById('bill-auto-pay');
        if (billAccount && autoPayCheckbox) {
            billAccount.addEventListener('change', () => {
                if (!billAccount.value || billAccount.value === '') {
                    autoPayCheckbox.checked = false;
                    autoPayCheckbox.disabled = true;
                } else {
                    autoPayCheckbox.disabled = false;
                }
                this.updateBillAmountCurrency();
            });

            // Set initial state
            if (!billAccount.value || billAccount.value === '') {
                autoPayCheckbox.disabled = true;
            }
        }

        // Bills filter tabs
        document.querySelectorAll('.bills-tabs .tab-button').forEach(tab => {
            tab.addEventListener('click', (e) => {
                document.querySelectorAll('.bills-tabs .tab-button').forEach(el => el.classList.remove('active'));
                e.target.classList.add('active');
                this.filterBills(e.target.dataset.filter);
            });
        });

        // Close detected panel
        const closeDetectedPanel = document.getElementById('close-detected-panel');
        if (closeDetectedPanel) {
            closeDetectedPanel.addEventListener('click', () => {
                document.getElementById('detected-bills-panel').style.display = 'none';
            });
        }

        // Cancel detected
        const cancelDetectedBtn = document.getElementById('cancel-detected-btn');
        if (cancelDetectedBtn) {
            cancelDetectedBtn.addEventListener('click', () => {
                document.getElementById('detected-bills-panel').style.display = 'none';
            });
        }

        // Add selected bills from detection
        const addSelectedBillsBtn = document.getElementById('add-selected-bills-btn');
        if (addSelectedBillsBtn) {
            addSelectedBillsBtn.addEventListener('click', () => this.addSelectedDetectedBills());
        }

        // Split template toggle
        const splitEnabled = document.getElementById('bill-split-enabled');
        if (splitEnabled) {
            splitEnabled.addEventListener('change', (e) => {
                const container = document.getElementById('bill-split-container');
                const categoryGroup = document.getElementById('bill-category').closest('.form-group');
                if (e.target.checked) {
                    container.style.display = 'block';
                    categoryGroup.style.display = 'none';
                    // Add initial rows if empty
                    const rows = document.getElementById('bill-split-rows');
                    if (!rows.children.length) {
                        this.addBillSplitRow();
                        this.addBillSplitRow();
                    }
                    this.updateBillSplitRemaining();
                } else {
                    container.style.display = 'none';
                    categoryGroup.style.display = '';
                }
            });
        }

        // Add split row button
        const addSplitBtn = document.getElementById('bill-add-split-btn');
        if (addSplitBtn) {
            addSplitBtn.addEventListener('click', () => {
                this.addBillSplitRow();
                this.updateBillSplitRemaining();
            });
        }

        // Bill amount change updates split remaining
        const billAmount = document.getElementById('bill-amount');
        if (billAmount) {
            billAmount.addEventListener('input', () => {
                if (document.getElementById('bill-split-enabled')?.checked) {
                    this.updateBillSplitRemaining();
                }
            });
        }

        // Delegated event handlers for bill actions
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('bill-edit-btn') || e.target.closest('.bill-edit-btn')) {
                const button = e.target.classList.contains('bill-edit-btn') ? e.target : e.target.closest('.bill-edit-btn');
                const billId = parseInt(button.dataset.billId);
                this.editBill(billId);
            } else if (e.target.classList.contains('bill-delete-btn') || e.target.closest('.bill-delete-btn')) {
                const button = e.target.classList.contains('bill-delete-btn') ? e.target : e.target.closest('.bill-delete-btn');
                const billId = parseInt(button.dataset.billId);
                this.deleteBill(billId);
            } else if (e.target.classList.contains('bill-paid-btn') || e.target.closest('.bill-paid-btn')) {
                const button = e.target.classList.contains('bill-paid-btn') ? e.target : e.target.closest('.bill-paid-btn');
                const billId = parseInt(button.dataset.billId);
                this.markBillPaid(billId);
            } else if (e.target.classList.contains('bill-skip-btn') || e.target.closest('.bill-skip-btn')) {
                const button = e.target.classList.contains('bill-skip-btn') ? e.target : e.target.closest('.bill-skip-btn');
                const billId = parseInt(button.dataset.billId);
                this.skipBillPayment(billId);
            }
        });
    }

    showBillModal(bill = null) {
        const modal = document.getElementById('bill-modal');
        const title = document.getElementById('bill-modal-title');
        const form = document.getElementById('bill-form');

        form.reset();
        document.getElementById('bill-id').value = '';

        if (bill) {
            title.textContent = t('budget', 'Edit Bill');
            document.getElementById('bill-id').value = bill.id;
            document.getElementById('bill-name').value = bill.name || '';
            document.getElementById('bill-amount').value = bill.amount || '';
            document.getElementById('bill-frequency').value = bill.frequency || 'monthly';
            document.getElementById('bill-due-day').value = bill.dueDay || bill.due_day || '';
            document.getElementById('bill-due-month').value = bill.dueMonth || bill.due_month || '';
            document.getElementById('bill-category').value = bill.categoryId || bill.category_id || '';
            document.getElementById('bill-account').value = bill.accountId || bill.account_id || '';
            document.getElementById('bill-auto-pattern').value = bill.autoDetectPattern || bill.auto_detect_pattern || '';
            document.getElementById('bill-notes').value = bill.notes || '';
            const reminderDays = bill.reminderDays ?? bill.reminder_days;
            document.getElementById('bill-reminder-days').value = reminderDays !== null && reminderDays !== undefined ? reminderDays.toString() : '';

            // Parse and set custom recurrence pattern if present
            const customPattern = bill.customRecurrencePattern || bill.custom_recurrence_pattern;
            if (customPattern) {
                this.setCustomMonthsFromPattern(customPattern);
            } else {
                // Clear all month checkboxes
                document.querySelectorAll('#bill-custom-months input[type="checkbox"]').forEach(cb => cb.checked = false);
            }

            // Set end date / remaining payments
            setDateValue('bill-end-date', bill.endDate || bill.end_date || '');
            const remainingPayments = bill.remainingPayments ?? bill.remaining_payments;
            document.getElementById('bill-remaining-payments').value = remainingPayments !== null && remainingPayments !== undefined ? remainingPayments.toString() : '';

            // Reset transaction creation fields for edit mode
            document.getElementById('bill-create-transaction').checked = false;
            clearDateValue('bill-transaction-date');
            document.getElementById('transaction-date-group').style.display = 'none';

            // Set auto-pay fields
            const autoPayEnabled = bill.autoPayEnabled ?? bill.auto_pay_enabled ?? false;
            const autoPayFailed = bill.autoPayFailed ?? bill.auto_pay_failed ?? false;
            document.getElementById('bill-auto-pay').checked = autoPayEnabled;
            document.getElementById('auto-pay-failed-warning').style.display = autoPayFailed ? 'block' : 'none';

            // Load tag sets for bill's category
            const categoryId = bill.categoryId || bill.category_id;
            if (categoryId) {
                this.loadBillTagSets(categoryId, bill);
            } else {
                const tagsContainer = document.getElementById('bill-tags-container');
                if (tagsContainer) tagsContainer.innerHTML = '';
            }

            // Populate split template
            const splitTemplate = bill.splitTemplate || bill.split_template || [];
            const splitCheckbox = document.getElementById('bill-split-enabled');
            const splitContainer = document.getElementById('bill-split-container');
            const splitRows = document.getElementById('bill-split-rows');
            const categoryGroup = document.getElementById('bill-category').closest('.form-group');
            splitRows.innerHTML = '';
            if (splitTemplate.length >= 2) {
                splitCheckbox.checked = true;
                splitContainer.style.display = 'block';
                categoryGroup.style.display = 'none';
                splitTemplate.forEach(split => this.addBillSplitRow(split));
                this.updateBillSplitRemaining();
            } else {
                splitCheckbox.checked = false;
                splitContainer.style.display = 'none';
                categoryGroup.style.display = '';
            }
        } else {
            title.textContent = t('budget', 'Add Bill');
            // Clear all month checkboxes for new bill
            document.querySelectorAll('#bill-custom-months input[type="checkbox"]').forEach(cb => cb.checked = false);

            // Clear end date / remaining payments
            clearDateValue('bill-end-date');
            document.getElementById('bill-remaining-payments').value = '';

            // Reset transaction creation fields for new bill
            document.getElementById('bill-create-transaction').checked = false;
            clearDateValue('bill-transaction-date');
            document.getElementById('transaction-date-group').style.display = 'none';

            // Reset auto-pay fields for new bill
            document.getElementById('bill-auto-pay').checked = false;
            document.getElementById('auto-pay-failed-warning').style.display = 'none';

            // Clear tag sets
            const tagsContainer = document.getElementById('bill-tags-container');
            if (tagsContainer) tagsContainer.innerHTML = '';

            // Reset split template
            const splitCheckbox = document.getElementById('bill-split-enabled');
            if (splitCheckbox) splitCheckbox.checked = false;
            const splitContainer = document.getElementById('bill-split-container');
            if (splitContainer) splitContainer.style.display = 'none';
            const splitRows = document.getElementById('bill-split-rows');
            if (splitRows) splitRows.innerHTML = '';
            const categoryGroup = document.getElementById('bill-category').closest('.form-group');
            if (categoryGroup) categoryGroup.style.display = '';
        }

        this.updateBillFormFields();
        this.updateBillAmountCurrency();
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    hideBillModal() {
        const modal = document.getElementById('bill-modal');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    updateBillFormFields() {
        const frequency = document.getElementById('bill-frequency').value;
        const dueDayGroup = document.getElementById('due-day-group');
        const dueMonthGroup = document.getElementById('due-month-group');
        const customMonthsGroup = document.getElementById('custom-months-group');
        const endDateGroup = document.getElementById('end-date-group');
        const remainingPaymentsGroup = document.getElementById('remaining-payments-group');

        // Show/hide custom months selector for custom frequency
        if (frequency === 'custom') {
            customMonthsGroup.style.display = 'block';
            dueDayGroup.style.display = 'block';
            dueMonthGroup.style.display = 'none';
        } else if (frequency === 'yearly' || frequency === 'one-time') {
            customMonthsGroup.style.display = 'none';
            dueDayGroup.style.display = 'block';
            dueMonthGroup.style.display = 'block';
        } else {
            customMonthsGroup.style.display = 'none';
            dueDayGroup.style.display = 'block';
            dueMonthGroup.style.display = 'none';
        }

        // Hide end date/remaining payments for one-time (already auto-deactivates)
        const isOneTime = frequency === 'one-time';
        if (endDateGroup) endDateGroup.style.display = isOneTime ? 'none' : 'block';
        if (remainingPaymentsGroup) remainingPaymentsGroup.style.display = isOneTime ? 'none' : 'block';

        // Update due day label based on frequency
        const dueDayLabel = dueDayGroup.querySelector('label');
        const dueDayHelp = document.getElementById('bill-due-day-help');

        if (frequency === 'weekly' || frequency === 'biweekly') {
            dueDayLabel.textContent = t('budget', 'Due Day (1-7)');
            dueDayHelp.textContent = t('budget', 'Day of the week (1=Monday, 7=Sunday)');
            document.getElementById('bill-due-day').max = 7;
        } else if (frequency === 'custom') {
            dueDayLabel.textContent = t('budget', 'Due Day');
            dueDayHelp.textContent = t('budget', 'Day of the selected months when bill is due');
            document.getElementById('bill-due-day').max = 31;
        } else {
            dueDayLabel.textContent = t('budget', 'Due Day');
            dueDayHelp.textContent = t('budget', 'Day of the month when bill is due');
            document.getElementById('bill-due-day').max = 31;
        }
    }

    populateBillModalDropdowns() {
        // Populate category dropdown
        const categorySelect = document.getElementById('bill-category');
        if (categorySelect && this.categories) {
            const currentValue = categorySelect.value;
            categorySelect.innerHTML = `<option value="">${t('budget', 'No category')}</option>`;
            dom.populateCategorySelect(categorySelect, this.categoryTree || this.categories, { typeFilter: 'expense' });
            if (currentValue) categorySelect.value = currentValue;
        }

        // Populate account dropdown (show currency in label)
        const accountSelect = document.getElementById('bill-account');
        if (accountSelect && this.accounts) {
            const currentValue = accountSelect.value;
            accountSelect.innerHTML = `<option value="">${t('budget', 'No specific account')}</option>`;
            this.accounts.forEach(acc => {
                const currencyLabel = acc.currency ? ` (${acc.currency})` : '';
                accountSelect.innerHTML += `<option value="${acc.id}">${dom.escapeHtml(acc.name)}${currencyLabel}</option>`;
            });
            if (currentValue) accountSelect.value = currentValue;
        }
    }

    /**
     * Show/hide currency indicator next to the amount field based on selected account.
     */
    updateBillAmountCurrency() {
        const accountSelect = document.getElementById('bill-account');
        const amountInput = document.getElementById('bill-amount');
        if (!amountInput) return;

        // Remove existing currency indicator
        const existing = amountInput.parentElement.querySelector('.bill-currency-indicator');
        if (existing) existing.remove();

        if (accountSelect && accountSelect.value) {
            const account = this.accounts.find(a => a.id === parseInt(accountSelect.value));
            if (account && account.currency) {
                const indicator = document.createElement('span');
                indicator.className = 'bill-currency-indicator';
                indicator.textContent = account.currency;
                indicator.style.cssText = 'margin-left: 8px; color: var(--color-text-maxcontrast); font-size: 0.9em; font-weight: 500;';
                amountInput.parentElement.appendChild(indicator);
            }
        }
    }

    addBillSplitRow(split = null) {
        const container = document.getElementById('bill-split-rows');
        const row = document.createElement('div');
        row.className = 'bill-split-row';
        row.style.cssText = 'display: flex; gap: 8px; align-items: center; margin-bottom: 6px;';

        // Amount input
        const amountInput = document.createElement('input');
        amountInput.type = 'number';
        amountInput.step = '0.01';
        amountInput.min = '0';
        amountInput.placeholder = t('budget', 'Amount');
        amountInput.className = 'bill-split-amount';
        amountInput.style.cssText = 'width: 100px; flex-shrink: 0;';
        amountInput.value = split?.amount || '';
        amountInput.addEventListener('input', () => this.updateBillSplitRemaining());

        // Category select
        const categorySelect = document.createElement('select');
        categorySelect.className = 'bill-split-category';
        categorySelect.style.cssText = 'flex: 1;';
        categorySelect.innerHTML = `<option value="">${t('budget', 'No category')}</option>`;
        dom.populateCategorySelect(categorySelect, this.categoryTree || this.categories, { typeFilter: 'expense' });
        if (split?.categoryId) categorySelect.value = split.categoryId;

        // Description input
        const descInput = document.createElement('input');
        descInput.type = 'text';
        descInput.placeholder = t('budget', 'Description (optional)');
        descInput.className = 'bill-split-description';
        descInput.style.cssText = 'flex: 1;';
        descInput.value = split?.description || '';

        // Remove button
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'bill-split-remove';
        removeBtn.textContent = '\u00d7';
        removeBtn.title = t('budget', 'Remove split');
        removeBtn.style.cssText = 'width: 28px; height: 28px; padding: 0; border: none; background: var(--color-error); color: white; border-radius: 50%; cursor: pointer; font-size: 16px; flex-shrink: 0;';
        removeBtn.addEventListener('click', () => {
            row.remove();
            this.updateBillSplitRemaining();
        });

        row.appendChild(amountInput);
        row.appendChild(categorySelect);
        row.appendChild(descInput);
        row.appendChild(removeBtn);
        container.appendChild(row);
    }

    updateBillSplitRemaining() {
        const totalAmount = parseFloat(document.getElementById('bill-amount')?.value) || 0;
        const rows = document.querySelectorAll('.bill-split-row');
        let allocated = 0;
        rows.forEach(row => {
            allocated += parseFloat(row.querySelector('.bill-split-amount')?.value) || 0;
        });
        const remaining = totalAmount - allocated;
        const el = document.getElementById('bill-split-remaining');
        if (el) {
            const absRemaining = Math.abs(remaining);
            if (absRemaining < 0.01) {
                el.textContent = t('budget', 'Balanced');
                el.style.color = 'var(--color-success)';
            } else if (remaining > 0) {
                el.textContent = t('budget', '{amount} remaining', { amount: formatters.formatCurrency(remaining, null, this.settings) });
                el.style.color = 'var(--color-warning)';
            } else {
                el.textContent = t('budget', '{amount} over', { amount: formatters.formatCurrency(absRemaining, null, this.settings) });
                el.style.color = 'var(--color-error)';
            }
        }
    }

    getBillSplitTemplate() {
        if (!document.getElementById('bill-split-enabled')?.checked) {
            return null;
        }
        const rows = document.querySelectorAll('.bill-split-row');
        if (rows.length < 2) return null;

        const splits = [];
        rows.forEach(row => {
            const amount = parseFloat(row.querySelector('.bill-split-amount')?.value) || 0;
            if (amount > 0) {
                splits.push({
                    categoryId: row.querySelector('.bill-split-category')?.value ? parseInt(row.querySelector('.bill-split-category').value) : null,
                    amount: amount,
                    description: row.querySelector('.bill-split-description')?.value || null,
                });
            }
        });
        return splits.length >= 2 ? splits : null;
    }

    async saveBill() {
        const billId = document.getElementById('bill-id').value;
        const isNew = !billId;

        const reminderValue = document.getElementById('bill-reminder-days').value;
        const frequency = document.getElementById('bill-frequency').value;

        const billData = {
            name: document.getElementById('bill-name').value,
            amount: parseFloat(document.getElementById('bill-amount').value),
            frequency: frequency,
            dueDay: document.getElementById('bill-due-day').value ? parseInt(document.getElementById('bill-due-day').value) : null,
            dueMonth: document.getElementById('bill-due-month').value ? parseInt(document.getElementById('bill-due-month').value) : null,
            categoryId: document.getElementById('bill-category').value ? parseInt(document.getElementById('bill-category').value) : null,
            accountId: document.getElementById('bill-account').value ? parseInt(document.getElementById('bill-account').value) : null,
            autoDetectPattern: document.getElementById('bill-auto-pattern').value || null,
            notes: document.getElementById('bill-notes').value || null,
            reminderDays: reminderValue !== '' ? parseInt(reminderValue) : null,
            createTransaction: document.getElementById('bill-create-transaction')?.checked || false,
            transactionDate: document.getElementById('bill-transaction-date')?.value || null,
            autoPayEnabled: document.getElementById('bill-auto-pay')?.checked || false,
            tagIds: this.getSelectedBillTagIds(),
            endDate: document.getElementById('bill-end-date').value || null,
            remainingPayments: document.getElementById('bill-remaining-payments').value ? parseInt(document.getElementById('bill-remaining-payments').value) : null,
            splitTemplate: this.getBillSplitTemplate()
        };

        // When splits are defined, clear categoryId (splits define their own)
        if (billData.splitTemplate) {
            billData.categoryId = null;
        }

        // Add custom recurrence pattern if frequency is custom
        if (frequency === 'custom') {
            const customPattern = this.getCustomMonthsPattern();
            if (!customPattern) {
                showWarning(t('budget', 'Please select at least one month for custom frequency'));
                return;
            }
            billData.customRecurrencePattern = customPattern;
        } else {
            billData.customRecurrencePattern = null;
        }

        try {
            const url = isNew
                ? OC.generateUrl('/apps/budget/api/bills')
                : OC.generateUrl(`/apps/budget/api/bills/${billId}`);

            const response = await fetch(url, {
                method: isNew ? 'POST' : 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(billData)
            });

            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                const errorMessage = error.error || `HTTP ${response.status}: ${response.statusText}`;
                console.error('Server error:', error);
                throw new Error(errorMessage);
            }

            this.hideBillModal();
            showSuccess(isNew ? t('budget', 'Bill created successfully') : t('budget', 'Bill updated successfully'));
            await this.loadBillsView();
        } catch (error) {
            console.error('Failed to save bill:', error);
            showError(error.message || t('budget', 'Failed to save bill'));
        }
    }

    async editBill(billId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${billId}`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const bill = await response.json();
            this.showBillModal(bill);
        } catch (error) {
            console.error('Failed to load bill:', error);
            showError(t('budget', 'Failed to load bill'));
        }
    }

    async deleteBill(billId) {
        if (!confirm(t('budget', 'Are you sure you want to delete this bill?'))) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${billId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            showSuccess(t('budget', 'Bill deleted successfully'));
            await this.loadBillsView();
        } catch (error) {
            console.error('Failed to delete bill:', error);
            showError(t('budget', 'Failed to delete bill'));
        }
    }

    async markBillPaid(billId) {
        try {
            const bill = this.bills.find(b => b.id === billId);
            if (!bill) {
                throw new Error('Bill not found');
            }

            // Check for existing matching transactions before creating a new one
            if (bill.accountId || bill.account_id) {
                const matchResponse = await fetch(
                    OC.generateUrl(`/apps/budget/api/bills/${billId}/matching-transactions`),
                    { headers: { 'requesttoken': OC.requestToken } }
                );

                if (matchResponse.ok) {
                    const candidates = await matchResponse.json();
                    if (candidates.length > 0) {
                        // Show dialog and let user choose
                        const choice = await this._showMatchingTransactionDialog(bill, candidates);
                        if (choice === null) {
                            return; // User cancelled
                        }
                        await this._executeMarkPaid(billId, bill, choice);
                        return;
                    }
                }
            }

            // No matches found — proceed with normal flow (create new transaction)
            await this._executeMarkPaid(billId, bill, { action: 'create' });

        } catch (error) {
            console.error('Failed to mark bill as paid:', error);
            showError(t('budget', 'Failed to mark bill as paid'));
        }
    }

    /**
     * Execute the mark paid API call with the user's choice.
     * @param {number} billId
     * @param {object} bill
     * @param {object} choice - {action: 'create'|'link'|'skip', transactionId?: number}
     */
    async _executeMarkPaid(billId, bill, choice) {
        const body = {};

        if (choice.action === 'link') {
            body.existingTransactionId = choice.transactionId;
            body.createNextTransaction = false;
        } else if (choice.action === 'skip') {
            body.createNextTransaction = false;
        } else {
            body.createNextTransaction = true;
        }

        const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${billId}/paid`), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify(body)
        });

        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const result = await response.json();

        // Store undo data from server response BEFORE reloading
        this._undoData = {
            billId: billId,
            previousState: result.previousState,
            createdTransactionIds: result.createdTransactionIds || [],
            hadScheduledTransaction: result.hadScheduledTransaction || false,
            action: 'markPaid'
        };

        await this.loadBillsView();

        let message;
        if (choice.action === 'link') {
            message = t('budget', 'Bill marked as paid. Linked to existing transaction.');
        } else if (choice.action === 'skip') {
            message = t('budget', 'Bill marked as paid. No transaction created.');
        } else {
            const isOneTime = (bill.frequency === 'one-time');
            message = isOneTime
                ? t('budget', 'Bill marked as paid. Transaction created.')
                : t('budget', 'Bill marked as paid. Future transaction created.');
        }
        this.showUndoNotification(message, () => this.undoMarkBillPaid(), () => {
            this._undoData = null;
        });
    }

    /**
     * Show a dialog presenting matching transaction candidates.
     * Returns a Promise that resolves with the user's choice or null if cancelled.
     */
    _showMatchingTransactionDialog(bill, candidates) {
        return new Promise((resolve) => {
            const existing = document.getElementById('matching-tx-modal');
            if (existing) existing.remove();

            const currency = bill.currency || this.app.settings?.default_currency || '';
            const formatAmount = (amount) => formatters.formatCurrency(amount, currency);

            const candidateRows = candidates.map((c, i) => {
                const tx = c.transaction;
                const reasons = c.matchReasons.map(r => {
                    const labels = {
                        'exact_amount': t('budget', 'Exact amount'),
                        'similar_amount': t('budget', 'Similar amount (±5%)'),
                        'approximate_amount': t('budget', 'Approximate amount (±20%)'),
                        'exact_vendor': t('budget', 'Vendor matches'),
                        'partial_vendor': t('budget', 'Vendor partially matches'),
                        'exact_description': t('budget', 'Description matches'),
                        'partial_description': t('budget', 'Description partially matches'),
                        'same_day': t('budget', 'Same day'),
                        'next_day': t('budget', '±1 day'),
                        'within_3_days': t('budget', '±3 days'),
                        'within_7_days': t('budget', '±7 days'),
                    };
                    return labels[r] || r;
                }).join(', ');

                const desc = tx.description || tx.vendor || t('budget', '(no description)');
                const checked = i === 0 ? 'checked' : '';

                return `
                    <label class="matching-tx-option ${i === 0 ? 'recommended' : ''}">
                        <input type="radio" name="matching-tx-choice" value="${tx.id}" ${checked}>
                        <div class="matching-tx-details">
                            <div class="matching-tx-primary">
                                <span class="matching-tx-date">${formatters.formatDate(tx.date)}</span>
                                <span class="matching-tx-desc">${dom.escapeHtml(desc)}</span>
                                <span class="matching-tx-amount">${formatAmount(tx.amount)}</span>
                            </div>
                            <div class="matching-tx-reasons">${dom.escapeHtml(reasons)}</div>
                            ${c.score >= 50 ? `<span class="matching-tx-badge">${t('budget', 'Strong match')}</span>` : ''}
                        </div>
                    </label>
                `;
            }).join('');

            const modal = document.createElement('div');
            modal.id = 'matching-tx-modal';
            modal.className = 'budget-modal-overlay';
            modal.innerHTML = `
                <div class="budget-modal" style="max-width: 600px;">
                    <div class="budget-modal-header">
                        <h2>${t('budget', 'Existing Transaction Found')}</h2>
                        <button class="close-btn" title="${t('budget', 'Close')}">&times;</button>
                    </div>
                    <div class="budget-modal-body">
                        <p class="matching-tx-intro">${t('budget', 'We found existing transactions that may already represent this bill payment ({billName}, {amount}). Would you like to link one instead of creating a new transaction?', { billName: dom.escapeHtml(bill.name), amount: formatAmount(bill.amount) })}</p>
                        <div class="matching-tx-list">
                            ${candidateRows}
                        </div>
                        <label class="matching-tx-option matching-tx-create-new">
                            <input type="radio" name="matching-tx-choice" value="create">
                            <div class="matching-tx-details">
                                <span class="matching-tx-desc">${t('budget', 'Create a new transaction instead')}</span>
                            </div>
                        </label>
                        <label class="matching-tx-option matching-tx-skip">
                            <input type="radio" name="matching-tx-choice" value="skip">
                            <div class="matching-tx-details">
                                <span class="matching-tx-desc">${t('budget', 'Don\'t create any transaction (just mark as paid)')}</span>
                            </div>
                        </label>
                    </div>
                    <div class="budget-modal-footer">
                        <button class="cancel-btn">${t('budget', 'Cancel')}</button>
                        <button class="confirm-btn primary">${t('budget', 'Confirm')}</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            const cleanup = () => modal.remove();

            modal.querySelector('.close-btn').addEventListener('click', () => {
                cleanup();
                resolve(null);
            });
            modal.querySelector('.cancel-btn').addEventListener('click', () => {
                cleanup();
                resolve(null);
            });
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    cleanup();
                    resolve(null);
                }
            });

            modal.querySelector('.confirm-btn').addEventListener('click', () => {
                const selected = modal.querySelector('input[name="matching-tx-choice"]:checked');
                if (!selected) {
                    cleanup();
                    resolve(null);
                    return;
                }

                const value = selected.value;
                cleanup();

                if (value === 'create') {
                    resolve({ action: 'create' });
                } else if (value === 'skip') {
                    resolve({ action: 'skip' });
                } else {
                    resolve({ action: 'link', transactionId: parseInt(value, 10) });
                }
            });
        });
    }

    async undoMarkBillPaid() {
        if (!this._undoData) {
            return;
        }

        try {
            const { billId, previousState, createdTransactionIds, hadScheduledTransaction } = this._undoData;

            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${billId}/undo-paid`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ previousState, createdTransactionIds, hadScheduledTransaction })
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.error || `HTTP ${response.status}`);
            }

            this._undoData = null;
            await this.loadBillsView();

            showSuccess(t('budget', 'Action undone'));
        } catch (error) {
            console.error('Failed to undo mark paid:', error);
            showError(t('budget', 'Failed to undo action: {message}', { message: error.message }));
        }
    }

    async skipBillPayment(billId) {
        try {
            const bill = this.bills.find(b => b.id === billId);
            if (!bill) {
                throw new Error('Bill not found');
            }

            if (!confirm(t('budget', 'Skip this payment and advance to the next due date?'))) {
                return;
            }

            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${billId}/skip`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const result = await response.json();
            const previousNextDueDate = result.previousNextDueDate ?? null;

            this._undoData = {
                billId: billId,
                previousNextDueDate: previousNextDueDate,
                action: 'skip'
            };

            await this.loadBillsView();

            this.showUndoNotification(
                t('budget', 'Payment skipped. Advanced to next due date.'),
                () => this.undoSkipPayment(),
                () => { this._undoData = null; }
            );

        } catch (error) {
            console.error('Failed to skip bill payment:', error);
            showError(t('budget', 'Failed to skip bill payment'));
        }
    }

    async undoSkipPayment() {
        if (!this._undoData || this._undoData.action !== 'skip') {
            return;
        }

        try {
            const { billId, previousNextDueDate } = this._undoData;

            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${billId}/undo-skip`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ previousNextDueDate: previousNextDueDate })
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.error || `HTTP ${response.status}`);
            }

            this._undoData = null;
            await this.loadBillsView();

            showSuccess(t('budget', 'Action undone'));
        } catch (error) {
            console.error('Failed to undo skip:', error);
            showError(t('budget', 'Failed to undo action: {message}', { message: error.message }));
        }
    }

    showUndoNotification(message, undoCallback, onExpire) {
        const notification = document.createElement('div');
        notification.className = 'undo-notification';
        let expired = false;
        notification.innerHTML = `
            <span class="undo-message">${message}</span>
            <button class="undo-btn">${t('budget', 'Undo')}</button>
        `;

        Object.assign(notification.style, {
            position: 'fixed',
            bottom: '20px',
            left: '50%',
            transform: 'translateX(-50%)',
            backgroundColor: '#333',
            color: '#fff',
            padding: '12px 20px',
            borderRadius: '4px',
            display: 'flex',
            alignItems: 'center',
            gap: '15px',
            zIndex: '10000',
            boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
            animation: 'slideUp 0.3s ease-out'
        });

        const undoBtn = notification.querySelector('.undo-btn');
        Object.assign(undoBtn.style, {
            backgroundColor: '#fff',
            color: '#333',
            border: 'none',
            padding: '6px 12px',
            borderRadius: '3px',
            cursor: 'pointer',
            fontWeight: 'bold',
            fontSize: '13px'
        });

        undoBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (!expired) {
                expired = true;
                undoCallback();
            }
            notification.remove();
        });

        document.body.appendChild(notification);

        setTimeout(() => {
            notification.style.animation = 'slideDown 0.3s ease-in';
            setTimeout(() => {
                notification.remove();
                if (!expired && onExpire) {
                    expired = true;
                    onExpire();
                }
            }, 300);
        }, 5000);
    }

    async detectBills() {
        const detectBtn = document.getElementById('detect-bills-btn');
        detectBtn.disabled = true;
        detectBtn.innerHTML = `<span class="icon-loading-small" aria-hidden="true"></span> ${t('budget', 'Detecting...')}`;

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bills/detect?months=6'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const detected = await response.json();

            if (!detected || detected.length === 0) {
                showInfo(t('budget', 'No recurring transactions detected'));
                return;
            }

            this.renderDetectedBills(detected);
            document.getElementById('detected-bills-panel').style.display = 'flex';
        } catch (error) {
            console.error('Failed to detect bills:', error);
            showError(t('budget', 'Failed to detect recurring bills'));
        } finally {
            detectBtn.disabled = false;
            detectBtn.innerHTML = `<span class="icon-search" aria-hidden="true"></span> ${t('budget', 'Detect Bills')}`;
        }
    }

    renderDetectedBills(detected) {
        const list = document.getElementById('detected-bills-list');

        list.innerHTML = detected.map((item, index) => {
            const confidenceClass = item.confidence >= 0.8 ? 'high' : item.confidence >= 0.5 ? 'medium' : 'low';
            const confidencePercent = Math.round(item.confidence * 100);

            return `
                <div class="detected-bill-item" data-index="${index}">
                    <div class="detected-bill-select">
                        <input type="checkbox" id="detected-${index}" ${item.confidence >= 0.7 ? 'checked' : ''}>
                    </div>
                    <div class="detected-bill-info">
                        <label for="detected-${index}" class="detected-bill-name">${dom.escapeHtml(item.description || item.name)}</label>
                        <div class="detected-bill-meta">
                            <span class="detected-amount">${formatters.formatCurrency(item.avgAmount || item.amount, null, this.settings)}</span>
                            <span class="detected-frequency">${item.frequency}</span>
                            <span class="detected-confidence ${confidenceClass}">${t('budget', '{percent}% confidence', { percent: confidencePercent })}</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        // Store detected bills for later use
        this._detectedBills = detected;
    }

    async addSelectedDetectedBills() {
        const checkboxes = document.querySelectorAll('#detected-bills-list input[type="checkbox"]:checked');
        const selectedIndices = Array.from(checkboxes).map(cb => parseInt(cb.id.replace('detected-', '')));

        if (selectedIndices.length === 0) {
            showWarning(t('budget', 'Please select at least one bill to add'));
            return;
        }

        const billsToAdd = selectedIndices.map(i => this._detectedBills[i]);

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bills/create-from-detected'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ bills: billsToAdd })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const result = await response.json();
            document.getElementById('detected-bills-panel').style.display = 'none';
            showSuccess(n('budget', '%n bill added successfully', '%n bills added successfully', result.created));
            await this.loadBillsView();
        } catch (error) {
            console.error('Failed to add bills:', error);
            showError(t('budget', 'Failed to add selected bills'));
        }
    }

    /**
     * Get custom months pattern from checkboxes as JSON string.
     * @returns {string|null} JSON pattern or null if no months selected
     */
    getCustomMonthsPattern() {
        const checkboxes = document.querySelectorAll('#bill-custom-months input[type="checkbox"]:checked');
        const selectedMonths = Array.from(checkboxes).map(cb => parseInt(cb.value));

        if (selectedMonths.length === 0) {
            return null;
        }

        // Sort months in ascending order
        selectedMonths.sort((a, b) => a - b);

        return JSON.stringify({ months: selectedMonths });
    }

    /**
     * Set custom months checkboxes from JSON pattern.
     * @param {string} pattern JSON pattern string
     */
    async loadBillTagSets(categoryId, existingBill = null) {
        const container = document.getElementById('bill-tags-container');
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
            const existingTagIds = existingBill?.tagIds || [];

            let html = '';

            // Global tags section
            if (globalTags.length > 0) {
                html += `
                    <div class="form-group tag-set-selector">
                        <label class="tag-set-label">${t('budget', 'Tags')}</label>
                        <div class="tag-options" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;">
                            ${globalTags.map(tag => `
                                <label class="tag-option" style="cursor: pointer;">
                                    <input type="checkbox" class="bill-tag-checkbox"
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
                                    <input type="checkbox" class="bill-tag-checkbox"
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

            // Add click handlers for tag selection (one per tag set, multi-select for global)
            container.querySelectorAll('.bill-tag-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', (e) => {
                    const tagSetId = e.target.dataset.tagSetId;
                    // Deselect other tags in same tag set (radio-like behavior, not for global tags)
                    if (e.target.checked && tagSetId !== 'global') {
                        container.querySelectorAll(`.bill-tag-checkbox[data-tag-set-id="${tagSetId}"]`).forEach(cb => {
                            if (cb !== e.target) {
                                cb.checked = false;
                                cb.closest('.tag-option').querySelector('.tag-badge').style.opacity = '0.5';
                            }
                        });
                    }
                    // Update visual state
                    e.target.closest('.tag-option').querySelector('.tag-badge').style.opacity = e.target.checked ? '1' : '0.5';
                });
            });
        } catch (error) {
            console.error('Failed to load tag sets:', error);
            container.innerHTML = '';
        }
    }

    getSelectedBillTagIds() {
        const container = document.getElementById('bill-tags-container');
        if (!container) return [];
        return Array.from(container.querySelectorAll('.bill-tag-checkbox:checked'))
            .map(cb => parseInt(cb.value));
    }

    setCustomMonthsFromPattern(pattern) {
        // Clear all checkboxes first
        document.querySelectorAll('#bill-custom-months input[type="checkbox"]').forEach(cb => cb.checked = false);

        try {
            const parsed = JSON.parse(pattern);
            if (parsed && parsed.months && Array.isArray(parsed.months)) {
                parsed.months.forEach(month => {
                    const checkbox = document.querySelector(`#bill-custom-months input[value="${month}"]`);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
            }
        } catch (e) {
            console.error('Failed to parse custom recurrence pattern:', e);
        }
    }
}
