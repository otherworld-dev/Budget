/**
 * Shared Expenses Module - Split expenses and settlements tracking
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError, showWarning } from '../../utils/notifications.js';
import { setDateValue } from '../../utils/datepicker.js';

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

            const owedEl = document.getElementById('split-total-owed');
            if (owedEl) owedEl.textContent = this.formatCurrency(data.totalOwed);

            const owingEl = document.getElementById('split-total-owing');
            if (owingEl) owingEl.textContent = this.formatCurrency(data.totalOwing);

            const netBalance = data.netBalance;
            const netEl = document.getElementById('split-net-balance');
            if (netEl) {
                if (netBalance > 0) {
                    netEl.textContent = '+' + this.formatCurrency(netBalance);
                    netEl.className = 'split-balance-value positive';
                } else if (netBalance < 0) {
                    netEl.textContent = '-' + this.formatCurrency(Math.abs(netBalance));
                    netEl.className = 'split-balance-value negative';
                } else {
                    netEl.textContent = this.formatCurrency(0);
                    netEl.className = 'split-balance-value';
                }
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
                    <p>${t('budget', 'Add contacts to start splitting expenses')}</p>
                </div>
            `;
            return;
        }

        container.innerHTML = contacts.map(item => {
            const balance = item.balance;
            const balanceClass = balance > 0 ? 'owed' : balance < 0 ? 'owing' : 'settled';
            const balanceText = balance === 0 ? t('budget', 'Settled') :
                (balance > 0 ? t('budget', 'Owes you {amount}', { amount: this.formatCurrency(balance) }) : t('budget', 'You owe {amount}', { amount: this.formatCurrency(Math.abs(balance)) }));

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
                    <div class="contact-actions-hover">
                        <button class="action-btn edit-contact-btn" data-id="${item.contact.id}" title="${t('budget', 'Edit')}">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z"/></svg>
                        </button>
                        <button class="action-btn delete-contact-btn" data-id="${item.contact.id}" title="${t('budget', 'Delete')}">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19,4H15.5L14.5,3H9.5L8.5,4H5V6H19M6,19A2,2 0 0,0 8,21H16A2,2 0 0,0 18,19V7H6V19Z"/></svg>
                        </button>
                    </div>
                </div>
            `;
        }).join('');

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

        // Share expense form (may already be attached via showShareExpenseModal)
        this._ensureShareFormListeners();

        // Settlement form
        const settlementForm = document.getElementById('settlement-form');
        if (settlementForm) {
            settlementForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveSettlement();
            });
        }

        // Modal close buttons (share-expense-modal handled by _ensureShareFormListeners)
        ['contact-modal', 'settlement-modal', 'contact-details-modal'].forEach(modalId => {
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
        document.getElementById('contact-nextcloud-user-id').value = contact ? (contact.nextcloudUserId || '') : '';
        document.getElementById('contact-user-search').value = '';
        document.getElementById('contact-user-suggestions').style.display = 'none';
        title.textContent = contact ? t('budget', 'Edit Contact') : t('budget', 'Add Contact');

        if (contact) {
            document.getElementById('contact-name').value = contact.name || '';
            document.getElementById('contact-email').value = contact.email || '';
        }

        this.setupUserSearch();
        modal.style.display = 'flex';
    }

    setupUserSearch() {
        const searchInput = document.getElementById('contact-user-search');
        const suggestions = document.getElementById('contact-user-suggestions');
        if (!searchInput || !suggestions) return;

        // Debounced search
        let searchTimeout;
        searchInput.oninput = () => {
            clearTimeout(searchTimeout);
            const query = searchInput.value.trim();
            if (query.length < 2) {
                suggestions.style.display = 'none';
                return;
            }
            searchTimeout = setTimeout(() => this.searchUsers(query, suggestions), 300);
        };

        // Close suggestions on blur (with delay for click to register)
        searchInput.onblur = () => {
            setTimeout(() => { suggestions.style.display = 'none'; }, 200);
        };
    }

    async searchUsers(query, suggestionsEl) {
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/shared/users/search?query=${encodeURIComponent(query)}`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) return;

            const users = await response.json();
            if (!users.length) {
                suggestionsEl.style.display = 'none';
                return;
            }

            suggestionsEl.innerHTML = users.map(user => `
                <div class="autocomplete-item" data-uid="${this.escapeHtml(user.uid)}" data-name="${this.escapeHtml(user.displayName)}">
                    <strong>${this.escapeHtml(user.displayName)}</strong>
                    <small>${this.escapeHtml(user.uid)}</small>
                </div>
            `).join('');

            suggestionsEl.style.display = 'block';

            // Click handler for suggestions
            suggestionsEl.querySelectorAll('.autocomplete-item').forEach(item => {
                item.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    const uid = item.dataset.uid;
                    const name = item.dataset.name;
                    document.getElementById('contact-nextcloud-user-id').value = uid;
                    document.getElementById('contact-name').value = name;
                    document.getElementById('contact-user-search').value = `${name} (${uid})`;
                    suggestionsEl.style.display = 'none';
                });
            });
        } catch (error) {
            console.error('User search failed:', error);
        }
    }

    async saveContact() {
        const id = document.getElementById('contact-id').value;
        const name = document.getElementById('contact-name').value.trim();
        const email = document.getElementById('contact-email').value.trim();
        const nextcloudUserId = document.getElementById('contact-nextcloud-user-id').value.trim() || null;

        if (!name) {
            showWarning(t('budget', 'Name is required'));
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
                body: JSON.stringify({ name, email: email || null, nextcloudUserId })
            });

            if (!response.ok) throw new Error('Failed to save contact');

            this.closeModal(document.getElementById('contact-modal'));
            showSuccess(id ? t('budget', 'Contact updated') : t('budget', 'Contact added'));
            await this.loadBalanceSummary();
            await this.loadContacts();
        } catch (error) {
            console.error('Failed to save contact:', error);
            showError(t('budget', 'Failed to save contact'));
        }
    }

    async editContact(id) {
        const contact = this.contacts?.find(c => c.id === id);
        if (contact) {
            this.showContactModal(contact);
        }
    }

    async deleteContact(id) {
        if (!confirm(t('budget', 'Are you sure you want to delete this contact? This will also remove all shared expense records with them.'))) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/shared/contacts/${id}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error('Failed to delete contact');

            showSuccess(t('budget', 'Contact deleted'));
            await this.loadBalanceSummary();
            await this.loadContacts();
        } catch (error) {
            console.error('Failed to delete contact:', error);
            showError(t('budget', 'Failed to delete contact'));
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
            balanceEl.textContent = balance === 0 ? t('budget', 'Settled') :
                (balance > 0 ? t('budget', 'Owes you {amount}', { amount: this.formatCurrency(balance) }) : t('budget', 'You owe {amount}', { amount: this.formatCurrency(Math.abs(balance)) }));
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
                recordSettlementBtn.onclick = () => this.showSettlementModal(contactId, data.contact.name, data.balance);
            }

            // Tab switching
            const tabs = document.querySelectorAll('#contact-details-modal .tab-button');
            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    tabs.forEach(el => el.classList.remove('active'));
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
            showError(t('budget', 'Failed to load contact details'));
        }
    }

    renderContactShares(shares) {
        const container = document.getElementById('contact-shares-list');
        if (!shares || shares.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No shared expenses')}</div>`;
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
                    <div class="share-status">${share.isSettled ? t('budget', 'Settled') : t('budget', 'Open')}</div>
                </div>
            `;
        }).join('');
    }

    renderContactSettlements(settlements) {
        const container = document.getElementById('contact-settlements-list');
        if (!settlements || settlements.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No settlements yet')}</div>`;
            return;
        }

        container.innerHTML = settlements.map(settlement => `
            <div class="settlement-item">
                <div class="settlement-date">${settlement.date}</div>
                <div class="settlement-amount ${settlement.amount >= 0 ? 'received' : 'paid'}">
                    ${settlement.amount >= 0 ? t('budget', 'Received') : t('budget', 'Paid')} ${this.formatCurrency(Math.abs(settlement.amount))}
                </div>
                ${settlement.notes ? `<div class="settlement-notes">${this.escapeHtml(settlement.notes)}</div>` : ''}
            </div>
        `).join('');
    }

    async showSettlementModal(contactId, contactName, balance) {
        this.closeModal(document.getElementById('contact-details-modal'));

        const modal = document.getElementById('settlement-modal');
        document.getElementById('settlement-contact-id').value = contactId;
        document.getElementById('settlement-contact-name').textContent = contactName;
        document.getElementById('settlement-balance').textContent = balance === 0 ? t('budget', 'Settled') :
            (balance > 0 ? t('budget', 'Owes you {amount}', { amount: this.formatCurrency(balance) }) : t('budget', 'You owe {amount}', { amount: this.formatCurrency(Math.abs(balance)) }));

        setDateValue('settlement-date', formatters.getTodayDateString());
        document.getElementById('settlement-notes').value = '';

        // Fetch unsettled shares for this contact
        const sharesList = document.getElementById('settlement-shares-list');
        sharesList.innerHTML = `<div class="loading">${t('budget', 'Loading...')}</div>`;
        modal.style.display = 'flex';

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/shared/contacts/${contactId}/details`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to load shares');
            const data = await response.json();

            const unsettledShares = (data.shares || []).filter(item => !item.share.isSettled);

            if (unsettledShares.length === 0) {
                sharesList.innerHTML = `<div class="empty-state-small">${t('budget', 'No unsettled expenses')}</div>`;
                return;
            }

            sharesList.innerHTML = unsettledShares.map(item => {
                const share = item.share;
                const txn = item.transaction;
                return `
                    <label class="settlement-share-item">
                        <input type="checkbox" class="settlement-share-checkbox"
                               data-share-id="${share.id}"
                               data-amount="${share.amount}"
                               checked>
                        <span class="settlement-share-date">${txn.date}</span>
                        <span class="settlement-share-desc">${this.escapeHtml(txn.description)}</span>
                        <span class="settlement-share-amount ${share.amount >= 0 ? 'positive' : 'negative'}">
                            ${share.amount >= 0 ? '+' : ''}${this.formatCurrency(share.amount)}
                        </span>
                    </label>
                `;
            }).join('');

            // Select all checkbox
            const selectAll = document.getElementById('settlement-select-all');
            selectAll.checked = true;
            selectAll.onchange = () => {
                sharesList.querySelectorAll('.settlement-share-checkbox').forEach(cb => {
                    cb.checked = selectAll.checked;
                });
                this._updateSettlementTotal();
            };

            // Individual checkbox changes
            sharesList.querySelectorAll('.settlement-share-checkbox').forEach(cb => {
                cb.addEventListener('change', () => this._updateSettlementTotal());
            });

            this._updateSettlementTotal();
        } catch (error) {
            console.error('Failed to load unsettled shares:', error);
            sharesList.innerHTML = `<div class="empty-state-small">${t('budget', 'Failed to load expenses')}</div>`;
        }
    }

    _updateSettlementTotal() {
        let total = 0;
        document.querySelectorAll('.settlement-share-checkbox:checked').forEach(cb => {
            total += parseFloat(cb.dataset.amount);
        });
        const totalEl = document.getElementById('settlement-total-amount');
        if (totalEl) {
            totalEl.textContent = this.formatCurrency(Math.abs(total));
            totalEl.className = total >= 0 ? 'positive' : 'negative';
        }
    }

    async saveSettlement() {
        const contactId = parseInt(document.getElementById('settlement-contact-id').value);
        const date = document.getElementById('settlement-date').value;
        const notes = document.getElementById('settlement-notes').value.trim();

        const shareIds = [];
        document.querySelectorAll('.settlement-share-checkbox:checked').forEach(cb => {
            shareIds.push(parseInt(cb.dataset.shareId));
        });

        if (shareIds.length === 0) {
            showWarning(t('budget', 'Please select at least one expense to settle'));
            return;
        }

        if (!date) {
            showWarning(t('budget', 'Date is required'));
            return;
        }

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/shared/settle-selected'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ shareIds, date, notes: notes || null })
            });

            if (!response.ok) throw new Error('Failed to settle expenses');

            this.closeModal(document.getElementById('settlement-modal'));
            showSuccess(t('budget', 'Expenses settled'));
            await this.loadBalanceSummary();
            await this.app.loadSharedTransactionIds();
            await this.showContactDetails(contactId);
        } catch (error) {
            console.error('Failed to settle expenses:', error);
            showError(t('budget', 'Failed to settle expenses'));
        }
    }

    async settleAllWithContact(contactId) {
        if (!confirm(t('budget', 'This will mark all shared expenses with this contact as settled. Continue?'))) {
            return;
        }

        try {
            const date = formatters.getTodayDateString();
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
            showSuccess(t('budget', 'All expenses settled'));
            await this.loadBalanceSummary();
            await this.app.loadSharedTransactionIds();
        } catch (error) {
            console.error('Failed to settle:', error);
            showError(t('budget', 'Failed to settle expenses'));
        }
    }

    async showShareExpenseModal(transaction) {
        const modal = document.getElementById('share-expense-modal');

        // Ensure form listeners are attached (may not be if user hasn't visited Shared Expenses page)
        this._ensureShareFormListeners();

        // Load contacts if not already loaded
        if (!this.contacts || this.contacts.length === 0) {
            await this.loadContacts();
        }

        // Check if there are any contacts
        if (!this.contacts || this.contacts.length === 0) {
            showWarning(t('budget', 'Please add contacts first in Shared Expenses'));
            return;
        }

        document.getElementById('share-transaction-id').value = transaction.id;
        document.getElementById('share-transaction-date').textContent = transaction.date;
        document.getElementById('share-transaction-desc').textContent = transaction.description;
        document.getElementById('share-transaction-amount').textContent = this.formatCurrency(Math.abs(transaction.amount));

        // Populate contacts dropdown
        const contactSelect = document.getElementById('share-contact');
        contactSelect.innerHTML = `<option value="">${t('budget', 'Select a contact...')}</option>` +
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
            showWarning(t('budget', 'Please select a contact'));
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
                    showWarning(t('budget', 'Amount is required for custom splits'));
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

            if (!response.ok) {
                const errorData = await response.json().catch(() => null);
                const message = errorData?.error || t('budget', 'Failed to share expense');
                showError(message);
                return;
            }

            this.closeModal(document.getElementById('share-expense-modal'));
            showSuccess(t('budget', 'Expense shared'));
            await this.app.loadSharedTransactionIds();
            // Refresh transaction table if visible to show shared badge
            const tbody = document.querySelector('#transactions-table tbody');
            if (tbody) {
                this.app.renderEnhancedTransactionsTable();
            }
        } catch (error) {
            console.error('Failed to share expense:', error);
            showError(t('budget', 'Failed to share expense'));
        }
    }

    _ensureShareFormListeners() {
        if (this._shareFormListenersAttached) return;

        const shareForm = document.getElementById('share-expense-form');
        if (!shareForm) return;

        shareForm.addEventListener('submit', (e) => {
            e.preventDefault();
            this.saveShareExpense();
        });

        const splitType = document.getElementById('share-split-type');
        if (splitType) {
            splitType.addEventListener('change', () => {
                const customGroup = document.getElementById('share-custom-amount-group');
                if (customGroup) {
                    customGroup.style.display = splitType.value === 'custom' ? 'block' : 'none';
                }
            });
        }

        // Close buttons for share expense modal
        const modal = document.getElementById('share-expense-modal');
        if (modal) {
            modal.querySelectorAll('.cancel-btn, .close-btn').forEach(btn => {
                btn.addEventListener('click', () => this.closeModal(modal));
            });
        }

        this._shareFormListenersAttached = true;
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
