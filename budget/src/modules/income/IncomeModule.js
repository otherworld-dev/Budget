/**
 * Income Module - Recurring income tracking and detection
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';

export default class IncomeModule {
    constructor(app) {
        this.app = app;
        this._eventsSetup = false;
        this._detectedIncome = [];
        this._undoTimer = null;
        this._undoData = null;
    }

    // Getters for app state
    get recurringIncome() { return this.app.recurringIncome; }
    set recurringIncome(value) { this.app.recurringIncome = value; }
    get accounts() { return this.app.accounts; }
    get categories() { return this.app.categories; }
    get settings() { return this.app.settings; }

    async loadIncomeView() {
        try {
            // Load summary first
            await this.loadIncomeSummary();

            // Load all recurring income
            const response = await fetch(OC.generateUrl('/apps/budget/api/recurring-income'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            this.recurringIncome = await response.json();
            this.renderRecurringIncome(this.recurringIncome);

            // Setup event listeners (only once)
            if (!this._eventsSetup) {
                this.setupIncomeEventListeners();
                this._eventsSetup = true;
            }

            // Populate dropdowns in income modal
            this.populateIncomeModalDropdowns();
        } catch (error) {
            console.error('Failed to load recurring income:', error);
            OC.Notification.showTemporary('Failed to load recurring income');
        }
    }

    async loadIncomeSummary() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/recurring-income/summary'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const summary = await response.json();

            // Update summary cards
            document.getElementById('income-expected-count').textContent = summary.expectedThisMonth || 0;
            document.getElementById('income-monthly-total').textContent = formatters.formatCurrency(summary.monthlyTotal || 0, null, this.settings);
            document.getElementById('income-received-count').textContent = summary.receivedThisMonth || 0;
            document.getElementById('income-active-count').textContent = summary.activeCount || 0;
        } catch (error) {
            console.error('Failed to load income summary:', error);
        }
    }

    renderRecurringIncome(incomeItems) {
        const incomeList = document.getElementById('income-list');
        const emptyIncome = document.getElementById('empty-income');

        if (!incomeItems || incomeItems.length === 0) {
            incomeList.innerHTML = '';
            emptyIncome.style.display = 'flex';
            return;
        }

        emptyIncome.style.display = 'none';

        incomeList.innerHTML = incomeItems.map(income => {
            const nextDate = income.nextExpectedDate || income.next_expected_date;
            const isReceivedThisMonth = this.isIncomeReceivedThisMonth(income);
            const isExpectedSoon = !isReceivedThisMonth && nextDate && this.isExpectedSoon(nextDate);

            let statusClass = '';
            let statusText = '';
            if (isReceivedThisMonth) {
                statusClass = 'received';
                statusText = 'Received';
            } else if (isExpectedSoon) {
                statusClass = 'expected-soon';
                statusText = 'Expected Soon';
            } else {
                statusClass = 'upcoming';
                statusText = 'Upcoming';
            }

            const frequency = income.frequency || 'monthly';
            const frequencyLabel = frequency.charAt(0).toUpperCase() + frequency.slice(1);
            const source = income.source || '';

            return `
                <div class="income-card ${statusClass}" data-income-id="${income.id}" data-status="${statusClass}">
                    <div class="income-header">
                        <div class="income-info">
                            <h4 class="income-name">${dom.escapeHtml(income.name)}</h4>
                            <span class="income-frequency">${frequencyLabel}</span>
                            ${source ? `<span class="income-source">${dom.escapeHtml(source)}</span>` : ''}
                        </div>
                        <div class="income-amount">${formatters.formatCurrency(income.amount, null, this.settings)}</div>
                    </div>
                    <div class="income-details">
                        <div class="income-next-date">
                            <span class="icon-calendar" aria-hidden="true"></span>
                            ${nextDate ? formatters.formatDate(nextDate, this.settings) : 'No date set'}
                        </div>
                        <div class="income-status ${statusClass}">
                            <span class="status-badge">${statusText}</span>
                        </div>
                    </div>
                    <div class="income-actions">
                        ${!isReceivedThisMonth ? `
                            <button class="income-action-btn income-received-btn" data-income-id="${income.id}" title="Mark as received">
                                <span class="icon-checkmark" aria-hidden="true"></span>
                                Mark Received
                            </button>
                        ` : ''}
                        <button class="income-action-btn income-edit-btn" data-income-id="${income.id}" title="Edit income">
                            <span class="icon-rename" aria-hidden="true"></span>
                        </button>
                        <button class="income-action-btn income-delete-btn" data-income-id="${income.id}" title="Delete income">
                            <span class="icon-delete" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    }

    isIncomeReceivedThisMonth(income) {
        const lastReceived = income.lastReceivedDate || income.last_received_date;
        if (!lastReceived) return false;

        const receivedDate = new Date(lastReceived);
        const now = new Date();
        return receivedDate.getMonth() === now.getMonth() && receivedDate.getFullYear() === now.getFullYear();
    }

    isExpectedSoon(dateStr) {
        const expectedDate = new Date(dateStr);
        const now = new Date();
        const diffDays = Math.ceil((expectedDate - now) / (1000 * 60 * 60 * 24));
        return diffDays >= 0 && diffDays <= 7;
    }

    filterIncome(filter) {
        const incomeCards = document.querySelectorAll('.income-card');
        incomeCards.forEach(card => {
            const status = card.dataset.status;
            let show = false;

            switch (filter) {
                case 'all':
                    show = true;
                    break;
                case 'expected':
                    show = status === 'expected-soon' || status === 'upcoming';
                    break;
                case 'received':
                    show = status === 'received';
                    break;
                default:
                    show = true;
            }

            card.style.display = show ? 'flex' : 'none';
        });
    }

    setupIncomeEventListeners() {
        // Add income button
        const addIncomeBtn = document.getElementById('add-income-btn');
        const emptyIncomeAddBtn = document.getElementById('empty-income-add-btn');

        if (addIncomeBtn) {
            addIncomeBtn.addEventListener('click', () => this.showIncomeModal());
        }
        if (emptyIncomeAddBtn) {
            emptyIncomeAddBtn.addEventListener('click', () => this.showIncomeModal());
        }

        // Detect income button
        const detectIncomeBtn = document.getElementById('detect-income-btn');
        if (detectIncomeBtn) {
            detectIncomeBtn.addEventListener('click', () => this.detectIncome());
        }

        // Close detected income panel
        const closeDetectedIncomePanel = document.getElementById('close-detected-income-panel');
        if (closeDetectedIncomePanel) {
            closeDetectedIncomePanel.addEventListener('click', () => {
                document.getElementById('detected-income-panel').style.display = 'none';
            });
        }

        // Cancel detected income
        const cancelDetectedIncomeBtn = document.getElementById('cancel-detected-income-btn');
        if (cancelDetectedIncomeBtn) {
            cancelDetectedIncomeBtn.addEventListener('click', () => {
                document.getElementById('detected-income-panel').style.display = 'none';
            });
        }

        // Add selected income from detection
        const addSelectedIncomeBtn = document.getElementById('add-selected-income-btn');
        if (addSelectedIncomeBtn) {
            addSelectedIncomeBtn.addEventListener('click', () => this.addSelectedDetectedIncome());
        }

        // Income modal form
        const incomeForm = document.getElementById('income-form');
        if (incomeForm) {
            incomeForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveIncome();
            });
        }

        // Income modal cancel
        const incomeModal = document.getElementById('income-modal');
        if (incomeModal) {
            incomeModal.querySelectorAll('.cancel-btn').forEach(btn => {
                btn.addEventListener('click', () => this.hideIncomeModal());
            });
            incomeModal.addEventListener('click', (e) => {
                if (e.target === incomeModal) this.hideIncomeModal();
            });
        }

        // Frequency change (show/hide month selector)
        const frequencySelect = document.getElementById('income-frequency');
        if (frequencySelect) {
            frequencySelect.addEventListener('change', () => this.updateIncomeFormFields());
        }

        // Filter tabs
        const incomeTabs = document.querySelectorAll('.income-tabs .tab-button');
        incomeTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                incomeTabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                this.filterIncome(tab.dataset.filter);
            });
        });

        // Income list actions (delegated)
        const incomeList = document.getElementById('income-list');
        if (incomeList) {
            incomeList.addEventListener('click', (e) => {
                const editBtn = e.target.closest('.income-edit-btn');
                const deleteBtn = e.target.closest('.income-delete-btn');
                const receivedBtn = e.target.closest('.income-received-btn');

                if (editBtn) {
                    const incomeId = parseInt(editBtn.dataset.incomeId);
                    const income = this.recurringIncome.find(i => i.id === incomeId);
                    if (income) this.showIncomeModal(income);
                }

                if (deleteBtn) {
                    const incomeId = parseInt(deleteBtn.dataset.incomeId);
                    this.deleteIncome(incomeId);
                }

                if (receivedBtn) {
                    const incomeId = parseInt(receivedBtn.dataset.incomeId);
                    this.markIncomeReceived(incomeId);
                }
            });
        }
    }

    showIncomeModal(income = null) {
        const modal = document.getElementById('income-modal');
        const title = document.getElementById('income-modal-title');
        const form = document.getElementById('income-form');

        form.reset();
        document.getElementById('income-id').value = '';

        if (income) {
            title.textContent = 'Edit Recurring Income';
            document.getElementById('income-id').value = income.id;
            document.getElementById('income-name').value = income.name || '';
            document.getElementById('income-amount').value = income.amount || '';
            document.getElementById('income-source').value = income.source || '';
            document.getElementById('income-frequency').value = income.frequency || 'monthly';
            document.getElementById('income-expected-day').value = income.expectedDay || income.expected_day || '';
            document.getElementById('income-expected-month').value = income.expectedMonth || income.expected_month || '';
            document.getElementById('income-category').value = income.categoryId || income.category_id || '';
            document.getElementById('income-account').value = income.accountId || income.account_id || '';
            document.getElementById('income-auto-pattern').value = income.autoDetectPattern || income.auto_detect_pattern || '';
            document.getElementById('income-notes').value = income.notes || '';
        } else {
            title.textContent = 'Add Recurring Income';
        }

        this.updateIncomeFormFields();
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    hideIncomeModal() {
        const modal = document.getElementById('income-modal');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }

    updateIncomeFormFields() {
        const frequency = document.getElementById('income-frequency').value;
        const expectedDayGroup = document.getElementById('expected-day-group');
        const expectedMonthGroup = document.getElementById('expected-month-group');

        // Show expected month only for yearly income
        if (frequency === 'yearly') {
            expectedMonthGroup.style.display = 'block';
        } else {
            expectedMonthGroup.style.display = 'none';
        }

        // Update expected day label based on frequency
        const expectedDayLabel = expectedDayGroup.querySelector('label');
        const expectedDayHelp = document.getElementById('income-expected-day-help');

        if (frequency === 'weekly') {
            expectedDayLabel.textContent = 'Expected Day (1-7)';
            expectedDayHelp.textContent = 'Day of the week (1=Monday, 7=Sunday)';
            document.getElementById('income-expected-day').max = 7;
        } else {
            expectedDayLabel.textContent = 'Expected Day';
            expectedDayHelp.textContent = 'Day of the month when income is expected';
            document.getElementById('income-expected-day').max = 31;
        }
    }

    populateIncomeModalDropdowns() {
        // Populate category dropdown (income categories)
        const categorySelect = document.getElementById('income-category');
        if (categorySelect && this.categories) {
            const currentValue = categorySelect.value;
            categorySelect.innerHTML = '<option value="">No category</option>';
            this.categories
                .filter(c => c.type === 'income')
                .forEach(cat => {
                    categorySelect.innerHTML += `<option value="${cat.id}">${dom.escapeHtml(cat.name)}</option>`;
                });
            if (currentValue) categorySelect.value = currentValue;
        }

        // Populate account dropdown
        const accountSelect = document.getElementById('income-account');
        if (accountSelect && this.accounts) {
            const currentValue = accountSelect.value;
            accountSelect.innerHTML = '<option value="">No specific account</option>';
            this.accounts.forEach(account => {
                accountSelect.innerHTML += `<option value="${account.id}">${dom.escapeHtml(account.name)}</option>`;
            });
            if (currentValue) accountSelect.value = currentValue;
        }
    }

    async saveIncome() {
        try {
            const id = document.getElementById('income-id').value;
            const isNew = !id;

            const data = {
                name: document.getElementById('income-name').value.trim(),
                amount: parseFloat(document.getElementById('income-amount').value) || 0,
                source: document.getElementById('income-source').value.trim() || null,
                frequency: document.getElementById('income-frequency').value,
                expectedDay: parseInt(document.getElementById('income-expected-day').value) || null,
                expectedMonth: parseInt(document.getElementById('income-expected-month').value) || null,
                categoryId: parseInt(document.getElementById('income-category').value) || null,
                accountId: parseInt(document.getElementById('income-account').value) || null,
                autoDetectPattern: document.getElementById('income-auto-pattern').value.trim() || null,
                notes: document.getElementById('income-notes').value.trim() || null
            };

            const url = isNew
                ? OC.generateUrl('/apps/budget/api/recurring-income')
                : OC.generateUrl(`/apps/budget/api/recurring-income/${id}`);

            const response = await fetch(url, {
                method: isNew ? 'POST' : 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(data)
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || `HTTP ${response.status}`);
            }

            this.hideIncomeModal();
            OC.Notification.showTemporary(isNew ? 'Income source created successfully' : 'Income source updated successfully');
            await this.loadIncomeView();
        } catch (error) {
            console.error('Failed to save income:', error);
            OC.Notification.showTemporary(error.message || 'Failed to save income');
        }
    }

    async deleteIncome(incomeId) {
        if (!confirm('Are you sure you want to delete this recurring income?')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/recurring-income/${incomeId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            OC.Notification.showTemporary('Income source deleted successfully');
            await this.loadIncomeView();
        } catch (error) {
            console.error('Failed to delete income:', error);
            OC.Notification.showTemporary('Failed to delete income');
        }
    }

    async markIncomeReceived(incomeId) {
        try {
            // Find the income item to store its previous state
            const income = this.recurringIncome.find(i => i.id === incomeId);
            if (!income) {
                throw new Error('Income not found');
            }

            const previousReceivedDate = income.lastReceivedDate || income.last_received_date || null;
            const currentDate = new Date().toISOString().split('T')[0];

            // Mark as received on the server
            const response = await fetch(OC.generateUrl(`/apps/budget/api/recurring-income/${incomeId}/received`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ receivedDate: currentDate })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            // Store undo data BEFORE reloading
            this._undoData = {
                incomeId: incomeId,
                previousReceivedDate: previousReceivedDate,
                action: 'markReceived'
            };

            // Update local state immediately
            await this.loadIncomeView();

            // Clear any existing undo timer
            if (this._undoTimer) {
                clearTimeout(this._undoTimer);
            }

            // Show notification with undo option
            this.showUndoNotification('Income marked as received', () => this.undoMarkReceived());

            // Set timer to clear undo data after 5 seconds
            this._undoTimer = setTimeout(() => {
                this._undoData = null;
                this._undoTimer = null;
            }, 5000);

        } catch (error) {
            console.error('Failed to mark income as received:', error);
            OC.Notification.showTemporary('Failed to mark income as received');
        }
    }

    async undoMarkReceived() {
        if (!this._undoData) {
            return;
        }

        try {
            const { incomeId, previousReceivedDate } = this._undoData;

            // Clear the undo timer
            if (this._undoTimer) {
                clearTimeout(this._undoTimer);
                this._undoTimer = null;
            }

            // Use the update endpoint to restore the previous state
            // This allows us to set lastReceivedDate to null if needed
            const response = await fetch(OC.generateUrl(`/apps/budget/api/recurring-income/${incomeId}`), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ lastReceivedDate: previousReceivedDate })
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(errorData.error || `HTTP ${response.status}`);
            }

            // Clear undo data
            this._undoData = null;

            // Reload the view
            await this.loadIncomeView();

            OC.Notification.showTemporary('Action undone');
        } catch (error) {
            console.error('Failed to undo mark received:', error);
            OC.Notification.showTemporary(`Failed to undo action: ${error.message}`);
        }
    }

    showUndoNotification(message, undoCallback) {
        // Create a custom notification element with an undo button
        const notification = document.createElement('div');
        notification.className = 'undo-notification';
        notification.innerHTML = `
            <span class="undo-message">${message}</span>
            <button class="undo-btn">Undo</button>
        `;

        // Style the notification
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

        // Auto-remove after 5 seconds
        setTimeout(() => {
            notification.style.animation = 'slideDown 0.3s ease-in';
            setTimeout(() => notification.remove(), 300);
        }, 5000);
    }

    async detectIncome() {
        const detectBtn = document.getElementById('detect-income-btn');
        detectBtn.disabled = true;
        detectBtn.innerHTML = '<span class="icon-loading-small" aria-hidden="true"></span> Detecting...';

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/recurring-income/detect?months=6'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const detected = await response.json();

            if (detected.length === 0) {
                OC.Notification.showTemporary('No recurring income patterns found in your transactions');
                return;
            }

            this._detectedIncome = detected;
            this.renderDetectedIncome(detected);
            document.getElementById('detected-income-panel').style.display = 'flex';
        } catch (error) {
            console.error('Failed to detect income:', error);
            OC.Notification.showTemporary('Failed to detect recurring income');
        } finally {
            detectBtn.disabled = false;
            detectBtn.innerHTML = '<span class="icon-search" aria-hidden="true"></span> Detect Income';
        }
    }

    renderDetectedIncome(detected) {
        const list = document.getElementById('detected-income-list');

        list.innerHTML = detected.map((item, index) => {
            const confidenceClass = item.confidence >= 0.8 ? 'high' : item.confidence >= 0.5 ? 'medium' : 'low';
            const confidencePercent = Math.round(item.confidence * 100);

            return `
                <div class="detected-bill-item" data-index="${index}">
                    <div class="detected-bill-select">
                        <input type="checkbox" id="detected-income-${index}" ${item.confidence >= 0.7 ? 'checked' : ''}>
                    </div>
                    <div class="detected-bill-info">
                        <div class="detected-bill-name">${dom.escapeHtml(item.suggestedName)}</div>
                        <div class="detected-bill-meta">
                            <span class="detected-bill-amount">${formatters.formatCurrency(item.amount, null, this.settings)}</span>
                            <span class="detected-bill-frequency">${item.frequency}</span>
                            <span class="detected-bill-occurrences">${item.occurrences} occurrences</span>
                            <span class="detected-bill-source">Source: ${dom.escapeHtml(item.source)}</span>
                        </div>
                        <div class="detected-bill-confidence">
                            <span class="confidence-badge ${confidenceClass}">${confidencePercent}% confidence</span>
                            ${item.amountVariance ? `<span class="variance-info">±${formatters.formatCurrency(item.amountVariance, null, this.settings)}</span>` : ''}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    async addSelectedDetectedIncome() {
        const checkboxes = document.querySelectorAll('#detected-income-list input[type="checkbox"]:checked');
        const selectedIndices = Array.from(checkboxes).map(cb => parseInt(cb.id.replace('detected-income-', '')));

        if (selectedIndices.length === 0) {
            OC.Notification.showTemporary('Please select at least one income source to add');
            return;
        }

        const incomeToAdd = selectedIndices.map(i => this._detectedIncome[i]);

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/recurring-income/create-from-detected'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ incomes: incomeToAdd })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const result = await response.json();

            document.getElementById('detected-income-panel').style.display = 'none';
            OC.Notification.showTemporary(`${result.created} income sources added successfully`);
            await this.loadIncomeView();
        } catch (error) {
            console.error('Failed to add income:', error);
            OC.Notification.showTemporary('Failed to add selected income sources');
        }
    }
}
