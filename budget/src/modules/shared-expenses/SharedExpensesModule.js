/**
 * Shared Expenses Module - Split expenses and settlements tracking
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError, showWarning } from '../../utils/notifications.js';

export default class SharedExpensesModule {
    constructor(app) {
        this.app = app;
        this._sharedEventsSetup = false;
    }

    // Getters for app state
    get settings() { return this.app.settings; }
    get contacts() { return this.app.contacts; }
    set contacts(value) { this.app.contacts = value; }
    get splitContacts() { return this.app.splitContacts; }
    set splitContacts(value) { this.app.splitContacts = value; }
    get currentContactDetails() { return this.app.currentContactDetails; }
    set currentContactDetails(value) { this.app.currentContactDetails = value; }

    async loadSharedExpensesView() {
        await this.loadBalanceSummary();
        await this.loadContacts();

        // Setup event listeners (only once)
        if (!this._sharedEventsSetup) {
            this.setupSharedExpenseEventListeners();
            this._sharedEventsSetup = true;
        }
    }

    async loadBalanceSummary() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/shared/balances'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to load balances');
            const data = await response.json();

            document.getElementById('split-total-owed').textContent = this.formatCurrency(data.totalOwed);
            document.getElementById('split-total-owing').textContent = this.formatCurrency(data.totalOwing);

            const netBalance = data.netBalance;
            const netEl = document.getElementById('split-net-balance');
            netEl.textContent = this.formatCurrency(Math.abs(netBalance));
            netEl.className = 'summary-value ' + (netBalance >= 0 ? 'positive' : 'negative');
            if (netBalance > 0) {
                netEl.textContent = '+' + netEl.textContent;
            } else if (netBalance < 0) {
                netEl.textContent = '-' + this.formatCurrency(Math.abs(netBalance));
            }

            this.splitContacts = data.contacts;
            this.renderContactsList(data.contacts);
        } catch (error) {
            console.error('Failed to load balances:', error);
        }
    }

    async loadContacts() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/shared/contacts'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to load contacts');
            this.contacts = await response.json();
        } catch (error) {
            console.error('Failed to load contacts:', error);
            this.contacts = [];
        }
    }

    renderContactsList(contacts) {
        const container = document.getElementById('contacts-list');
        if (!container) return;

        if (!contacts || contacts.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor" opacity="0.3">
                            <path d="M16,13C15.71,13 15.38,13 15.03,13.05C16.19,13.89 17,15 17,16.5V19H23V16.5C23,14.17 18.33,13 16,13M8,13C5.67,13 1,14.17 1,16.5V19H15V16.5C15,14.17 10.33,13 8,13M8,11A3,3 0 0,0 11,8A3,3 0 0,0 8,5A3,3 0 0,0 5,8A3,3 0 0,0 8,11M16,11A3,3 0 0,0 19,8A3,3 0 0,0 16,5A3,3 0 0,0 13,8A3,3 0 0,0 16,11Z"/>
                        </svg>
                    </div>
                    <p>Add contacts to start splitting expenses</p>
                </div>
            `;
            return;
        }

        container.innerHTML = contacts.map(item => {
            const balance = item.balance;
            const balanceClass = balance > 0 ? 'owed' : balance < 0 ? 'owing' : 'settled';
            const balanceText = balance === 0 ? 'Settled' :
                (balance > 0 ? `Owes you ${this.formatCurrency(balance)}` : `You owe ${this.formatCurrency(Math.abs(balance))}`);

            return `
                <div class="contact-card" data-contact-id="${item.contact.id}">
                    <div class="contact-card-main">
                        <div class="contact-avatar">
                            ${item.contact.name.charAt(0).toUpperCase()}
                        </div>
                        <div class="contact-info">
                            <span class="contact-name">${this.escapeHtml(item.contact.name)}</span>
                            ${item.contact.email ? `<span class="contact-email">${this.escapeHtml(item.contact.email)}</span>` : ''}
                        </div>
                        <div class="contact-balance ${balanceClass}">
                            ${balanceText}
                        </div>
                    </div>
                    <div class="contact-actions">
                        <button class="action-btn view-contact-btn" data-id="${item.contact.id}" title="View details">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12,9A3,3 0 0,0 9,12A3,3 0 0,0 12,15A3,3 0 0,0 15,12A3,3 0 0,0 12,9M12,17A5,5 0 0,1 7,12A5,5 0 0,1 12,7A5,5 0 0,1 17,12A5,5 0 0,1 12,17M12,4.5C7,4.5 2.73,7.61 1,12C2.73,16.39 7,19.5 12,19.5C17,19.5 21.27,16.39 23,12C21.27,7.61 17,4.5 12,4.5Z"/>
                            </svg>
                        </button>
                        <button class="action-btn edit-contact-btn" data-id="${item.contact.id}" title="Edit">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z"/>
                            </svg>
                        </button>
                        <button class="action-btn delete-contact-btn" data-id="${item.contact.id}" title="Delete">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z"/>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
        }).join('');

        // Add click handlers
        container.querySelectorAll('.view-contact-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.showContactDetails(parseInt(btn.dataset.id));
            });
        });

        container.querySelectorAll('.edit-contact-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.editContact(parseInt(btn.dataset.id));
            });
        });

        container.querySelectorAll('.delete-contact-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.deleteContact(parseInt(btn.dataset.id));
            });
        });

        container.querySelectorAll('.contact-card').forEach(card => {
            card.addEventListener('click', () => {
                this.showContactDetails(parseInt(card.dataset.contactId));
            });
        });
    }

    setupSharedExpenseEventListeners() {
        // Add contact button
        const addContactBtn = document.getElementById('add-contact-btn');
        if (addContactBtn) {
            addContactBtn.addEventListener('click', () => this.showContactModal());
        }

        // Contact form submission
        const contactForm = document.getElementById('contact-form');
        if (contactForm) {
            contactForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveContact();
            });
        }

        // Share expense form
        const shareForm = document.getElementById('share-expense-form');
        if (shareForm) {
            shareForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveShareExpense();
            });
        }

        // Split type change
        const splitType = document.getElementById('share-split-type');
        if (splitType) {
            splitType.addEventListener('change', () => {
                const customGroup = document.getElementById('share-custom-amount-group');
                if (customGroup) {
                    customGroup.style.display = splitType.value === 'custom' ? 'block' : 'none';
                }
            });
        }

        // Settlement form
        const settlementForm = document.getElementById('settlement-form');
        if (settlementForm) {
            settlementForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveSettlement();
            });
        }

        // Modal close buttons
        ['contact-modal', 'share-expense-modal', 'settlement-modal', 'contact-details-modal'].forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.querySelectorAll('.cancel-btn, .close-btn').forEach(btn => {
                    btn.addEventListener('click', () => this.closeModal(modal));
                });
            }
        });
    }

    showContactModal(contact = null) {
        const modal = document.getElementById('contact-modal');
        const title = document.getElementById('contact-modal-title');
        const form = document.getElementById('contact-form');

        form.reset();
        document.getElementById('contact-id').value = contact ? contact.id : '';
        title.textContent = contact ? 'Edit Contact' : 'Add Contact';

        if (contact) {
            document.getElementById('contact-name').value = contact.name || '';
            document.getElementById('contact-email').value = contact.email || '';
        }

        modal.style.display = 'flex';
    }

    async saveContact() {
        const id = document.getElementById('contact-id').value;
        const name = document.getElementById('contact-name').value.trim();
        const email = document.getElementById('contact-email').value.trim();

        if (!name) {
            showWarning('Name is required');
            return;
        }

        try {
            const url = id
                ? OC.generateUrl(`/apps/budget/api/shared/contacts/${id}`)
                : OC.generateUrl('/apps/budget/api/shared/contacts');

            const response = await fetch(url, {
                method: id ? 'PUT' : 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ name, email: email || null })
            });

            if (!response.ok) throw new Error('Failed to save contact');

            this.closeModal(document.getElementById('contact-modal'));
            showSuccess(id ? 'Contact updated' : 'Contact added');
            await this.loadBalanceSummary();
            await this.loadContacts();
        } catch (error) {
            console.error('Failed to save contact:', error);
            showError('Failed to save contact');
        }
    }

    async editContact(id) {
        const contact = this.contacts?.find(c => c.id === id);
        if (contact) {
            this.showContactModal(contact);
        }
    }

    async deleteContact(id) {
        if (!confirm('Are you sure you want to delete this contact? This will also remove all shared expense records with them.')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/shared/contacts/${id}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error('Failed to delete contact');

            showSuccess('Contact deleted');
            await this.loadBalanceSummary();
            await this.loadContacts();
        } catch (error) {
            console.error('Failed to delete contact:', error);
            showError('Failed to delete contact');
        }
    }

    async showContactDetails(contactId) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/shared/contacts/${contactId}/details`), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error('Failed to load contact details');
            const data = await response.json();

            this.currentContactDetails = data;

            // Populate modal
            document.getElementById('contact-details-name').textContent = data.contact.name;
            document.getElementById('contact-details-email').textContent = data.contact.email || '';

            const balanceEl = document.getElementById('contact-details-balance');
            const balance = data.balance;
            balanceEl.textContent = balance === 0 ? 'Settled' :
                (balance > 0 ? `Owes you ${this.formatCurrency(balance)}` : `You owe ${this.formatCurrency(Math.abs(balance))}`);
            balanceEl.className = 'balance-value ' + (balance > 0 ? 'owed' : balance < 0 ? 'owing' : 'settled');

            // Render shares
            this.renderContactShares(data.shares);
            this.renderContactSettlements(data.settlements);

            // Setup actions
            const settleAllBtn = document.getElementById('settle-all-btn');
            if (settleAllBtn) {
                settleAllBtn.onclick = () => this.settleAllWithContact(contactId);
            }

            const recordSettlementBtn = document.getElementById('record-settlement-btn');
            if (recordSettlementBtn) {
                recordSettlementBtn.onclick = () => this.showSettlementModal(contactId, data.contact.name, balance);
            }

            // Tab switching
            const tabs = document.querySelectorAll('#contact-details-modal .tab-button');
            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    tabs.forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');

                    document.getElementById('contact-shares-tab').style.display =
                        tab.dataset.tab === 'shares' ? 'block' : 'none';
                    document.getElementById('contact-settlements-tab').style.display =
                        tab.dataset.tab === 'settlements' ? 'block' : 'none';
                });
            });

            document.getElementById('contact-details-modal').style.display = 'flex';
        } catch (error) {
            console.error('Failed to load contact details:', error);
            showError('Failed to load contact details');
        }
    }

    renderContactShares(shares) {
        const container = document.getElementById('contact-shares-list');
        if (!shares || shares.length === 0) {
            container.innerHTML = '<div class="empty-state-small">No shared expenses</div>';
            return;
        }

        container.innerHTML = shares.map(item => {
            const share = item.share;
            const txn = item.transaction;
            const statusClass = share.isSettled ? 'settled' : (share.amount > 0 ? 'owed' : 'owing');

            return `
                <div class="share-item ${statusClass}">
                    <div class="share-date">${txn.date}</div>
                    <div class="share-desc">${this.escapeHtml(txn.description)}</div>
                    <div class="share-amount ${share.amount >= 0 ? 'positive' : 'negative'}">
                        ${share.amount >= 0 ? '+' : ''}${this.formatCurrency(share.amount)}
                    </div>
                    <div class="share-status">${share.isSettled ? 'Settled' : 'Open'}</div>
                </div>
            `;
        }).join('');
    }

    renderContactSettlements(settlements) {
        const container = document.getElementById('contact-settlements-list');
        if (!settlements || settlements.length === 0) {
            container.innerHTML = '<div class="empty-state-small">No settlements yet</div>';
            return;
        }

        container.innerHTML = settlements.map(settlement => `
            <div class="settlement-item">
                <div class="settlement-date">${settlement.date}</div>
                <div class="settlement-amount ${settlement.amount >= 0 ? 'received' : 'paid'}">
                    ${settlement.amount >= 0 ? 'Received' : 'Paid'} ${this.formatCurrency(Math.abs(settlement.amount))}
                </div>
                ${settlement.notes ? `<div class="settlement-notes">${this.escapeHtml(settlement.notes)}</div>` : ''}
            </div>
        `).join('');
    }

    showSettlementModal(contactId, contactName, balance) {
        this.closeModal(document.getElementById('contact-details-modal'));

        const modal = document.getElementById('settlement-modal');
        document.getElementById('settlement-contact-id').value = contactId;
        document.getElementById('settlement-contact-name').textContent = contactName;
        document.getElementById('settlement-balance').textContent = balance === 0 ? 'Settled' :
            (balance > 0 ? `Owes you ${this.formatCurrency(balance)}` : `You owe ${this.formatCurrency(Math.abs(balance))}`);

        document.getElementById('settlement-amount').value = Math.abs(balance).toFixed(2);
        document.getElementById('settlement-date').value = new Date().toISOString().split('T')[0];
        document.getElementById('settlement-notes').value = '';

        // Ensure form submit handler is attached
        const form = document.getElementById('settlement-form');
        if (form && !form.dataset.listenerAttached) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveSettlement();
            });
            form.dataset.listenerAttached = 'true';
        }

        modal.style.display = 'flex';
    }

    async saveSettlement() {
        const contactId = parseInt(document.getElementById('settlement-contact-id').value);
        const amount = parseFloat(document.getElementById('settlement-amount').value);
        const date = document.getElementById('settlement-date').value;
        const notes = document.getElementById('settlement-notes').value.trim();

        if (!amount || !date) {
            showWarning('Amount and date are required');
            return;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/shared/settlements'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ contactId, amount, date, notes: notes || null })
            });

            if (!response.ok) throw new Error('Failed to record settlement');

            this.closeModal(document.getElementById('settlement-modal'));
            showSuccess('Settlement recorded');
            await this.loadBalanceSummary();
        } catch (error) {
            console.error('Failed to record settlement:', error);
            showError('Failed to record settlement');
        }
    }

    async settleAllWithContact(contactId) {
        if (!confirm('This will mark all shared expenses with this contact as settled. Continue?')) {
            return;
        }

        try {
            const date = new Date().toISOString().split('T')[0];
            const response = await fetch(OC.generateUrl(`/apps/budget/api/shared/contacts/${contactId}/settle`), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ date })
            });

            if (!response.ok) throw new Error('Failed to settle');

            this.closeModal(document.getElementById('contact-details-modal'));
            showSuccess('All expenses settled');
            await this.loadBalanceSummary();
        } catch (error) {
            console.error('Failed to settle:', error);
            showError('Failed to settle expenses');
        }
    }

    async showShareExpenseModal(transaction) {
        const modal = document.getElementById('share-expense-modal');

        // Load contacts if not already loaded
        if (!this.contacts || this.contacts.length === 0) {
            await this.loadContacts();
        }

        // Check if there are any contacts
        if (!this.contacts || this.contacts.length === 0) {
            showWarning('Please add contacts first in Shared Expenses');
            return;
        }

        document.getElementById('share-transaction-id').value = transaction.id;
        document.getElementById('share-transaction-date').textContent = transaction.date;
        document.getElementById('share-transaction-desc').textContent = transaction.description;
        document.getElementById('share-transaction-amount').textContent = this.formatCurrency(Math.abs(transaction.amount));

        // Populate contacts dropdown
        const contactSelect = document.getElementById('share-contact');
        contactSelect.innerHTML = '<option value="">Select a contact...</option>' +
            (this.contacts || []).map(c => `<option value="${c.id}">${this.escapeHtml(c.name)}</option>`).join('');

        document.getElementById('share-split-type').value = '50-50';
        document.getElementById('share-custom-amount-group').style.display = 'none';
        document.getElementById('share-amount').value = '';
        document.getElementById('share-notes').value = '';

        modal.style.display = 'flex';
    }

    async saveShareExpense() {
        const transactionId = parseInt(document.getElementById('share-transaction-id').value);
        const contactId = parseInt(document.getElementById('share-contact').value);
        const splitType = document.getElementById('share-split-type').value;
        const notes = document.getElementById('share-notes').value.trim();

        if (!contactId) {
            showWarning('Please select a contact');
            return;
        }

        try {
            let url, body;

            if (splitType === '50-50') {
                url = OC.generateUrl('/apps/budget/api/shared/shares/split');
                body = { transactionId, contactId, notes: notes || null };
            } else {
                const amount = parseFloat(document.getElementById('share-amount').value);
                if (!amount) {
                    showWarning('Amount is required for custom splits');
                    return;
                }
                url = OC.generateUrl('/apps/budget/api/shared/shares');
                body = { transactionId, contactId, amount, notes: notes || null };
            }

            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(body)
            });

            if (!response.ok) throw new Error('Failed to share expense');

            this.closeModal(document.getElementById('share-expense-modal'));
            showSuccess('Expense shared');
            await this.loadBalanceSummary();
        } catch (error) {
            console.error('Failed to share expense:', error);
            showError('Failed to share expense');
        }
    }

    // Delegate helper methods to app
    formatCurrency(amount, currency = null) {
        return formatters.formatCurrency(amount, currency, this.settings);
    }

    escapeHtml(text) {
        return dom.escapeHtml(text);
    }

    closeModal(modal) {
        return dom.closeModal(modal);
    }
}
