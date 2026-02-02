/**
 * Bills Module - Recurring bill tracking and detection
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';

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
    get settings() { return this.app.settings; }

    async loadBillsView() {
        try {
            // Load summary first
            await this.loadBillsSummary();

            // Load all bills
            const response = await fetch(OC.generateUrl('/apps/budget/api/bills'), {
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
            OC.Notification.showTemporary('Failed to load bills');
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
            document.getElementById('bills-monthly-total').textContent = formatters.formatCurrency(summary.monthlyTotal || 0, null, this.settings);
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
            const isOverdue = !isPaid && dueDate && new Date(dueDate) < new Date();
            const isDueSoon = !isPaid && !isOverdue && dueDate && this.isDueSoon(dueDate);

            let statusClass = '';
            let statusText = '';
            if (isPaid) {
                statusClass = 'paid';
                statusText = 'Paid';
            } else if (isOverdue) {
                statusClass = 'overdue';
                statusText = 'Overdue';
            } else if (isDueSoon) {
                statusClass = 'due-soon';
                statusText = 'Due Soon';
            } else {
                statusClass = 'upcoming';
                statusText = 'Upcoming';
            }

            const frequency = bill.frequency || 'monthly';
            const frequencyLabel = frequency.charAt(0).toUpperCase() + frequency.slice(1);

            return `
                <div class="bill-card ${statusClass}" data-bill-id="${bill.id}" data-status="${statusClass}">
                    <div class="bill-header">
                        <div class="bill-info">
                            <h4 class="bill-name">${dom.escapeHtml(bill.name)}</h4>
                            <span class="bill-frequency">${frequencyLabel}</span>
                        </div>
                        <div class="bill-amount">${formatters.formatCurrency(bill.amount, null, this.settings)}</div>
                    </div>
                    <div class="bill-details">
                        <div class="bill-due-date">
                            <span class="icon-calendar" aria-hidden="true"></span>
                            ${dueDate ? formatters.formatDate(dueDate, this.settings) : 'No due date'}
                        </div>
                        <div class="bill-status ${statusClass}">
                            <span class="status-badge">${statusText}</span>
                        </div>
                    </div>
                    <div class="bill-actions">
                        ${!isPaid ? `
                            <button class="bill-action-btn bill-paid-btn" data-bill-id="${bill.id}" title="Mark as paid">
                                <span class="icon-checkmark" aria-hidden="true"></span>
                                Mark Paid
                            </button>
                        ` : ''}
                        <button class="bill-action-btn bill-edit-btn" data-bill-id="${bill.id}" title="Edit bill">
                            <span class="icon-rename" aria-hidden="true"></span>
                        </button>
                        <button class="bill-action-btn bill-delete-btn" data-bill-id="${bill.id}" title="Delete bill">
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
        const dueDate = new Date(dateStr);
        const now = new Date();
        const diffDays = Math.ceil((dueDate - now) / (1000 * 60 * 60 * 24));
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

        // Bill frequency change (show/hide due month for yearly)
        const billFrequency = document.getElementById('bill-frequency');
        if (billFrequency) {
            billFrequency.addEventListener('change', () => this.updateBillFormFields());
        }

        // Bills filter tabs
        document.querySelectorAll('.bills-tabs .tab-button').forEach(tab => {
            tab.addEventListener('click', (e) => {
                document.querySelectorAll('.bills-tabs .tab-button').forEach(t => t.classList.remove('active'));
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
            title.textContent = 'Edit Bill';
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
        } else {
            title.textContent = 'Add Bill';
        }

        this.updateBillFormFields();
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

        // Show due month only for yearly bills
        if (frequency === 'yearly') {
            dueMonthGroup.style.display = 'block';
        } else {
            dueMonthGroup.style.display = 'none';
        }

        // Update due day label based on frequency
        const dueDayLabel = dueDayGroup.querySelector('label');
        const dueDayHelp = document.getElementById('bill-due-day-help');

        if (frequency === 'weekly') {
            dueDayLabel.textContent = 'Due Day (1-7)';
            dueDayHelp.textContent = 'Day of the week (1=Monday, 7=Sunday)';
            document.getElementById('bill-due-day').max = 7;
        } else {
            dueDayLabel.textContent = 'Due Day';
            dueDayHelp.textContent = 'Day of the month when bill is due';
            document.getElementById('bill-due-day').max = 31;
        }
    }

    populateBillModalDropdowns() {
        // Populate category dropdown
        const categorySelect = document.getElementById('bill-category');
        if (categorySelect && this.categories) {
            const currentValue = categorySelect.value;
            categorySelect.innerHTML = '<option value="">No category</option>';
            this.categories
                .filter(c => c.type === 'expense')
                .forEach(cat => {
                    categorySelect.innerHTML += `<option value="${cat.id}">${dom.escapeHtml(cat.name)}</option>`;
                });
            if (currentValue) categorySelect.value = currentValue;
        }

        // Populate account dropdown
        const accountSelect = document.getElementById('bill-account');
        if (accountSelect && this.accounts) {
            const currentValue = accountSelect.value;
            accountSelect.innerHTML = '<option value="">No specific account</option>';
            this.accounts.forEach(acc => {
                accountSelect.innerHTML += `<option value="${acc.id}">${dom.escapeHtml(acc.name)}</option>`;
            });
            if (currentValue) accountSelect.value = currentValue;
        }
    }

    async saveBill() {
        const billId = document.getElementById('bill-id').value;
        const isNew = !billId;

        const reminderValue = document.getElementById('bill-reminder-days').value;
        const billData = {
            name: document.getElementById('bill-name').value,
            amount: parseFloat(document.getElementById('bill-amount').value),
            frequency: document.getElementById('bill-frequency').value,
            dueDay: document.getElementById('bill-due-day').value ? parseInt(document.getElementById('bill-due-day').value) : null,
            dueMonth: document.getElementById('bill-due-month').value ? parseInt(document.getElementById('bill-due-month').value) : null,
            categoryId: document.getElementById('bill-category').value ? parseInt(document.getElementById('bill-category').value) : null,
            accountId: document.getElementById('bill-account').value ? parseInt(document.getElementById('bill-account').value) : null,
            autoDetectPattern: document.getElementById('bill-auto-pattern').value || null,
            notes: document.getElementById('bill-notes').value || null,
            reminderDays: reminderValue !== '' ? parseInt(reminderValue) : null
        };

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
                const error = await response.json();
                throw new Error(error.error || 'Failed to save bill');
            }

            this.hideBillModal();
            OC.Notification.showTemporary(isNew ? 'Bill created successfully' : 'Bill updated successfully');
            await this.loadBillsView();
        } catch (error) {
            console.error('Failed to save bill:', error);
            OC.Notification.showTemporary(error.message || 'Failed to save bill');
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
            OC.Notification.showTemporary('Failed to load bill');
        }
    }

    async deleteBill(billId) {
        if (!confirm('Are you sure you want to delete this bill?')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${billId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            OC.Notification.showTemporary('Bill deleted successfully');
            await this.loadBillsView();
        } catch (error) {
            console.error('Failed to delete bill:', error);
            OC.Notification.showTemporary('Failed to delete bill');
        }
    }

    async markBillPaid(billId) {
        try {
            const bill = this.bills.find(b => b.id === billId);
            if (!bill) {
                throw new Error('Bill not found');
            }

            const previousPaidDate = bill.lastPaidDate || bill.last_paid_date || null;
            const currentDate = new Date().toISOString().split('T')[0];

            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${billId}/paid`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ paidDate: currentDate })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            // Store undo data BEFORE reloading
            this._undoData = {
                billId: billId,
                previousPaidDate: previousPaidDate,
                action: 'markPaid'
            };

            await this.loadBillsView();

            if (this._undoTimer) {
                clearTimeout(this._undoTimer);
            }

            this.showUndoNotification('Bill marked as paid', () => this.undoMarkBillPaid());

            this._undoTimer = setTimeout(() => {
                this._undoData = null;
                this._undoTimer = null;
            }, 5000);

        } catch (error) {
            console.error('Failed to mark bill as paid:', error);
            OC.Notification.showTemporary('Failed to mark bill as paid');
        }
    }

    async undoMarkBillPaid() {
        if (!this._undoData) {
            return;
        }

        try {
            const { billId, previousPaidDate } = this._undoData;

            if (this._undoTimer) {
                clearTimeout(this._undoTimer);
                this._undoTimer = null;
            }

            const response = await fetch(OC.generateUrl(`/apps/budget/api/bills/${billId}`), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ lastPaidDate: previousPaidDate })
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.error || `HTTP ${response.status}`);
            }

            this._undoData = null;
            await this.loadBillsView();

            OC.Notification.showTemporary('Action undone');
        } catch (error) {
            console.error('Failed to undo mark paid:', error);
            OC.Notification.showTemporary(`Failed to undo action: ${error.message}`);
        }
    }

    showUndoNotification(message, undoCallback) {
        const notification = document.createElement('div');
        notification.className = 'undo-notification';
        notification.innerHTML = `
            <span class="undo-message">${message}</span>
            <button class="undo-btn">Undo</button>
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
            undoCallback();
            notification.remove();
        });

        document.body.appendChild(notification);

        setTimeout(() => {
            notification.style.animation = 'slideDown 0.3s ease-in';
            setTimeout(() => notification.remove(), 300);
        }, 5000);
    }

    async detectBills() {
        const detectBtn = document.getElementById('detect-bills-btn');
        detectBtn.disabled = true;
        detectBtn.innerHTML = '<span class="icon-loading-small" aria-hidden="true"></span> Detecting...';

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/bills/detect?months=6'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const detected = await response.json();

            if (!detected || detected.length === 0) {
                OC.Notification.showTemporary('No recurring transactions detected');
                return;
            }

            this.renderDetectedBills(detected);
            document.getElementById('detected-bills-panel').style.display = 'flex';
        } catch (error) {
            console.error('Failed to detect bills:', error);
            OC.Notification.showTemporary('Failed to detect recurring bills');
        } finally {
            detectBtn.disabled = false;
            detectBtn.innerHTML = '<span class="icon-search" aria-hidden="true"></span> Detect Bills';
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
                            <span class="detected-confidence ${confidenceClass}">${confidencePercent}% confidence</span>
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
            OC.Notification.showTemporary('Please select at least one bill to add');
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
            OC.Notification.showTemporary(`${result.created} bills added successfully`);
            await this.loadBillsView();
        } catch (error) {
            console.error('Failed to add bills:', error);
            OC.Notification.showTemporary('Failed to add selected bills');
        }
    }
}
