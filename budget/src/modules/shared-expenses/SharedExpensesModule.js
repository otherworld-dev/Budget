/**
 * Shared Expenses Module - Split expenses and settlements tracking
 */
import { translate as t } from '@nextcloud/l10n';
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';
import { showSuccess, showError, showWarning } from '../../utils/notifications.js';
import { confirmDialog } from '../../utils/dialogs.js';
import { setDateValue } from '../../utils/datepicker.js';
import { computeSplit, toCents } from './splitMath.js';

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
        await this.loadSharedWithMe();

        // Setup event listeners (only once)
        if (!this._sharedEventsSetup) {
            this.setupSharedExpenseEventListeners();
            this._sharedEventsSetup = true;
        }
    }

    /**
     * Load and render expenses other users have split with the current user.
     * Read-only — the owner of each split manages settlement (#248).
     */
    async loadSharedWithMe() {
        const section = document.getElementById('shared-with-me-section');
        const list = document.getElementById('shared-with-me-list');
        if (!section || !list) return;

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/shared/shared-with-me'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const shares = await response.json();

            if (!Array.isArray(shares) || shares.length === 0) {
                section.style.display = 'none';
                list.innerHTML = '';
                return;
            }

            section.style.display = 'block';
            list.innerHTML = shares.map(s => {
                const desc = dom.escapeHtml(s.transactionDescription || t('budget', 'Shared expense'));
                const owner = dom.escapeHtml(s.ownerName || s.ownerUserId || '');
                const date = s.transactionDate ? formatters.formatDate(s.transactionDate, this.settings) : '';
                const amount = this.formatCurrency(s.amount, s.currency);
                const statusBadge = s.isSettled
                    ? `<span class="status-badge badge-settled">${t('budget', 'Settled')}</span>`
                    : `<span class="status-badge badge-outstanding">${t('budget', 'Outstanding')}</span>`;
                return `
                    <div class="shared-with-me-item">
                        <div class="shared-with-me-main">
                            <span class="shared-with-me-desc">${desc}</span>
                            <span class="shared-with-me-meta">${t('budget', 'from {owner}', { owner }, undefined, { escape: false })}${date ? ' · ' + date : ''}</span>
                        </div>
                        <div class="shared-with-me-right">
                            <span class="shared-with-me-amount">${amount}</span>
                            ${statusBadge}
                        </div>
                    </div>
                `;
            }).join('');
        } catch (error) {
            console.error('Failed to load shared-with-me expenses:', error);
            section.style.display = 'none';
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
            if (owedEl) {
                const totals = data.totalsByCurrency || {};
                const owedParts = Object.entries(totals)
                    .filter(([, v]) => v.owed > 0)
                    .map(([cur, v]) => this.formatCurrency(v.owed, cur));
                owedEl.textContent = owedParts.length > 0 ? owedParts.join(', ') : this.formatCurrency(0);
            }

            const owingEl = document.getElementById('split-total-owing');
            if (owingEl) {
                const totals = data.totalsByCurrency || {};
                const owingParts = Object.entries(totals)
                    .filter(([, v]) => v.owing > 0)
                    .map(([cur, v]) => this.formatCurrency(v.owing, cur));
                owingEl.textContent = owingParts.length > 0 ? owingParts.join(', ') : this.formatCurrency(0);
            }

            const netEl = document.getElementById('split-net-balance');
            if (netEl) {
                const totals = data.totalsByCurrency || {};
                const netParts = Object.entries(totals)
                    .map(([cur, v]) => ({ cur, net: v.owed - v.owing }))
                    .filter(({ net }) => Math.abs(net) > 0.005);

                if (netParts.length === 0) {
                    netEl.textContent = this.formatCurrency(0);
                    netEl.className = 'split-balance-value';
                } else {
                    const allPositive = netParts.every(p => p.net > 0);
                    const allNegative = netParts.every(p => p.net < 0);
                    netEl.innerHTML = netParts
                        .map(p => (p.net > 0 ? '+' : '-') + this.formatCurrency(Math.abs(p.net), p.cur))
                        .join('<br>');
                    netEl.className = 'split-balance-value ' + (allPositive ? 'positive' : allNegative ? 'negative' : '');
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
            const balanceLines = item.balances || [];
            const hasBalance = balanceLines.length > 0;
            const balanceClass = !hasBalance ? 'settled' : item.direction;
            const balanceText = !hasBalance ? t('budget', 'Settled') :
                balanceLines.map(b =>
                    b.amount > 0
                        ? t('budget', 'Owes you {amount}', { amount: this.formatCurrency(b.amount, b.currency) })
                        : t('budget', 'You owe {amount}', { amount: this.formatCurrency(Math.abs(b.amount), b.currency) })
                ).join('<br>');

            return `
                <div class="contact-card" data-contact-id="${item.contact.id}" tabindex="0">
                    <div class="contact-card-main">
                        <div class="contact-avatar">
                            ${this.escapeHtml(item.contact.name.charAt(0).toUpperCase())}
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
                        <button class="action-btn edit-contact-btn" data-id="${item.contact.id}" title="${t('budget', 'Edit')}" aria-label="${t('budget', 'Edit')}">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z"/></svg>
                        </button>
                        <button class="action-btn delete-contact-btn" data-id="${item.contact.id}" title="${t('budget', 'Delete')}" aria-label="${t('budget', 'Delete')}">
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
        title.textContent = contact ? t('budget', 'Edit Contact') : t('budget', 'Add Contact');

        if (contact) {
            document.getElementById('contact-name').value = contact.name || '';
            document.getElementById('contact-email').value = contact.email || '';
        }

        this.populateUserDropdown(contact?.nextcloudUserId || '');
        this.setupUserSelectHandler();
        modal.style.display = 'flex';
    }

    async populateUserDropdown(selectedUserId = '') {
        const select = document.getElementById('contact-user-select');
        if (!select) return;

        // Keep the manual option
        select.innerHTML = `<option value="">${t('budget', '— None (enter details manually) —')}</option>`;

        try {
            // Fetch all users (empty query with low minimum)
            const response = await fetch(OC.generateUrl('/apps/budget/api/shared/users/search?query=*'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) return;

            const users = await response.json();
            users.forEach(user => {
                const option = document.createElement('option');
                option.value = user.uid;
                option.textContent = `${user.displayName} (${user.uid})`;
                option.dataset.displayName = user.displayName;
                if (user.uid === selectedUserId) option.selected = true;
                select.appendChild(option);
            });
        } catch (error) {
            console.error('Failed to load users:', error);
        }
    }

    setupUserSelectHandler() {
        const select = document.getElementById('contact-user-select');
        if (!select) return;

        select.onchange = () => {
            const selectedOption = select.options[select.selectedIndex];
            const uid = select.value;
            document.getElementById('contact-nextcloud-user-id').value = uid;

            if (uid && selectedOption.dataset.displayName) {
                document.getElementById('contact-name').value = selectedOption.dataset.displayName;
            }
        };
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
        if (!await confirmDialog(t('budget', 'Are you sure you want to delete this contact? This will also remove all shared expense records with them.'), { destructive: true })) {
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
            const currencyBalances = data.balances || {};
            const balanceEntries = Object.entries(currencyBalances).filter(([, amt]) => Math.abs(amt) > 0.005);

            if (balanceEntries.length === 0) {
                balanceEl.textContent = t('budget', 'Settled');
                balanceEl.className = 'balance-value settled';
            } else {
                balanceEl.innerHTML = balanceEntries.map(([cur, amt]) =>
                    amt > 0
                        ? t('budget', 'Owes you {amount}', { amount: this.formatCurrency(amt, cur) })
                        : t('budget', 'You owe {amount}', { amount: this.formatCurrency(Math.abs(amt), cur) })
                ).join('<br>');
                const overallDirection = data.direction || 'settled';
                balanceEl.className = 'balance-value ' + overallDirection;
            }

            // Render shares
            this.renderContactShares(data.shares, data.contact.name);
            this.renderContactSettlements(data.settlements);

            // Settling only reaches your own splits; ones the contact split
            // with you are theirs to settle (#390)
            const hasOwnOpen = (data.shares || []).some(item => !item.incoming && !item.share.isSettled);

            // Setup actions
            const settleAllBtn = document.getElementById('settle-all-btn');
            if (settleAllBtn) {
                settleAllBtn.disabled = !hasOwnOpen;
                settleAllBtn.onclick = () => this.settleAllWithContact(contactId);
            }

            const recordSettlementBtn = document.getElementById('record-settlement-btn');
            if (recordSettlementBtn) {
                recordSettlementBtn.disabled = !hasOwnOpen;
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

    renderContactShares(shares, contactName = '') {
        const container = document.getElementById('contact-shares-list');
        if (!shares || shares.length === 0) {
            container.innerHTML = `<div class="empty-state-small">${t('budget', 'No shared expenses')}</div>`;
            return;
        }

        // Passed raw: t() HTML-escapes placeholder values itself
        const name = contactName;
        const hasIncomingOpen = shares.some(item => item.incoming && !item.share.isSettled);
        const note = hasIncomingOpen
            ? `<div class="shares-note">${t('budget', 'Only {name} can settle the expenses they split with you.', { name })}</div>`
            : '';

        container.innerHTML = note + shares.map(item => {
            const share = item.share;
            const txn = item.transaction;
            const statusClass = share.isSettled ? 'settled' : (share.amount > 0 ? 'owed' : 'owing');
            const origin = item.incoming
                ? ` <span class="share-origin">· ${t('budget', 'split by {name}', { name })}</span>`
                : '';

            return `
                <div class="share-item ${statusClass}">
                    <div class="share-date">${this.escapeHtml(txn.date)}</div>
                    <div class="share-desc">${this.escapeHtml(txn.description)}${origin}</div>
                    <div class="share-amount ${share.amount >= 0 ? 'positive' : 'negative'}">
                        ${share.amount >= 0 ? '+' : ''}${this.formatCurrency(share.amount, share.currency)}
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
                    ${settlement.amount >= 0 ? t('budget', 'Received') : t('budget', 'Paid')} ${this.formatCurrency(Math.abs(settlement.amount), settlement.currency)}
                </div>
                ${settlement.notes ? `<div class="settlement-notes">${this.escapeHtml(settlement.notes)}</div>` : ''}
            </div>
        `).join('');
    }

    async showSettlementModal(contactId, contactName, _balance) {
        this.closeModal(document.getElementById('contact-details-modal'));

        const modal = document.getElementById('settlement-modal');
        document.getElementById('settlement-contact-id').value = contactId;
        document.getElementById('settlement-contact-name').textContent = contactName;
        // Show per-currency balance in settlement modal
        const balanceData = this.currentContactDetails?.balances || {};
        const balEntries = Object.entries(balanceData).filter(([, amt]) => Math.abs(amt) > 0.005);
        const settlementBalanceEl = document.getElementById('settlement-balance');
        if (balEntries.length === 0) {
            settlementBalanceEl.textContent = t('budget', 'Settled');
        } else {
            settlementBalanceEl.innerHTML = balEntries.map(([cur, amt]) =>
                amt > 0
                    ? t('budget', 'Owes you {amount}', { amount: this.formatCurrency(amt, cur) })
                    : t('budget', 'You owe {amount}', { amount: this.formatCurrency(Math.abs(amt), cur) })
            ).join('<br>');
        }

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

            // Splits the contact made with you can only be settled by them (#390)
            const unsettledShares = (data.shares || []).filter(item => !item.incoming && !item.share.isSettled);

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
                            ${share.amount >= 0 ? '+' : ''}${this.formatCurrency(share.amount, share.currency)}
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
        if (!await confirmDialog(t('budget', 'This will mark all shared expenses with this contact as settled. Continue?'))) {
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

    /**
     * Split a transaction between any number of contacts at once (#391).
     * Opens on the transaction's current splits. Settled ones are shown but
     * locked, and what they cover is left out of what there is to split.
     */
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

        let shares;
        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/shared/transactions/${transaction.id}/shares`), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error('Failed to load shares');
            shares = await response.json();
        } catch (error) {
            console.error('Failed to load transaction shares:', error);
            showError(t('budget', 'Failed to load the split'));
            return;
        }

        const account = (this.app.accounts || []).find(a => a.id === transaction.accountId);
        const settled = shares.filter(s => s.isSettled);
        const open = shares.filter(s => !s.isSettled);
        const settledCents = settled.reduce((sum, s) => sum + Math.abs(toCents(s.amount)), 0);

        this._share = {
            transactionId: transaction.id,
            currency: account?.currency || null,
            isCredit: transaction.type === 'credit',
            availableCents: Math.max(0, Math.abs(toCents(transaction.amount)) - settledCents),
            settled,
            hadOpen: open.length > 0,
            // An existing split reopens as the amounts it was saved as
            method: open.length > 0 ? 'amount' : 'equal',
            includeMe: true,
            ticked: new Set(open.map(s => s.contactId)),
            values: {
                percent: new Map(),
                amount: new Map(open.map(s => [s.contactId, (Math.abs(toCents(s.amount)) / 100).toFixed(2)])),
            },
            // Until a percentage is typed, ticking someone re-spreads them evenly
            percentTyped: false,
        };

        document.getElementById('share-transaction-id').value = transaction.id;
        document.getElementById('share-transaction-date').textContent = transaction.date;
        document.getElementById('share-transaction-desc').textContent = transaction.description;
        document.getElementById('share-transaction-amount').textContent = this.formatCurrency(Math.abs(transaction.amount), this._share.currency);
        document.getElementById('share-split-type').value = this._share.method;
        document.getElementById('share-notes').value = open.find(s => s.notes)?.notes || '';

        const settledNote = document.getElementById('share-settled-note');
        settledNote.textContent = settledCents > 0
            ? t('budget', '{amount} of this has already been settled, so it is left out of the split.', { amount: this.formatCurrency(settledCents / 100, this._share.currency) })
            : '';
        settledNote.style.display = settledCents > 0 ? 'block' : 'none';

        this._renderSharePeople();
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }

    /** Ticked contacts in the order they are listed, which is who gets a spare penny first */
    _tickedContactIds() {
        return (this.contacts || []).filter(c => this._share.ticked.has(c.id)).map(c => c.id);
    }

    _spreadPercentEvenly() {
        const s = this._share;
        const ids = [...(s.includeMe ? ['me'] : []), ...this._tickedContactIds()];
        s.values.percent = new Map();
        if (ids.length === 0) return;
        const each = Math.floor(10000 / ids.length);
        const spare = 10000 - each * ids.length;
        ids.forEach((id, i) => s.values.percent.set(id, ((each + (i === 0 ? spare : 0)) / 100).toFixed(2)));
    }

    _computeShare() {
        const s = this._share;
        return computeSplit({
            totalCents: s.availableCents,
            method: s.method,
            includeMe: s.includeMe,
            myValue: s.values.percent.get('me') ?? null,
            people: this._tickedContactIds().map(id => ({ id, value: s.values[s.method]?.get(id) ?? null })),
        });
    }

    /** Your row, a row per contact, then any settled splits, locked */
    _renderSharePeople() {
        const s = this._share;
        if (s.method === 'percent' && !s.percentTyped) {
            this._spreadPercentEvenly();
        }

        const values = s.values[s.method];
        // Only people in the split get a box, so a long contact list stays readable
        const valueCell = (id, name, inSplit) => {
            if (s.method === 'equal' || id === null || !inSplit) {
                return '<span class="share-person-value-wrap"></span>';
            }
            // t() escapes the name itself
            const label = s.method === 'percent'
                ? t('budget', 'Percentage for {name}', { name })
                : t('budget', 'Amount for {name}', { name });
            return `
                <span class="share-person-value-wrap">
                    <input type="number" class="share-person-value" step="0.01" min="0" inputmode="decimal"
                           value="${this.escapeHtml(values?.get(id) ?? '')}" aria-label="${label}">
                    ${s.method === 'percent' ? '<span class="share-person-unit">%</span>' : ''}
                </span>`;
        };
        const row = (id, name, { checked, checkDisabled = false, value = '', amount = '', cls = '' }) => `
            <div class="share-person-row${cls}" data-person="${id}">
                <label class="share-person-name">
                    <input type="checkbox" class="share-person-check"${checked ? ' checked' : ''}${checkDisabled ? ' disabled' : ''}>
                    <span>${this.escapeHtml(name)}</span>
                </label>
                ${value}
                <span class="share-person-amount">${amount}</span>
            </div>`;

        const settledIds = new Set(s.settled.map(share => share.contactId));
        const me = t('budget', 'You');
        // By amount, you simply keep whatever the others do not owe
        const meIn = s.method === 'amount' || s.includeMe;

        const html = [
            row('me', me, {
                checked: meIn,
                checkDisabled: s.method === 'amount',
                value: valueCell(s.method === 'percent' ? 'me' : null, me, s.includeMe),
            }),
            ...(this.contacts || []).filter(c => !settledIds.has(c.id)).map(c => {
                const ticked = s.ticked.has(c.id);
                return row(c.id, c.name, { checked: ticked, value: valueCell(c.id, c.name, ticked) });
            }),
            ...s.settled.map(share => {
                const contact = (this.contacts || []).find(c => c.id === share.contactId);
                const amount = this.formatCurrency(Math.abs(share.amount), this._share.currency);
                return row(share.contactId, contact?.name || t('budget', 'Unknown'), {
                    checked: true,
                    checkDisabled: true,
                    amount: `${this.escapeHtml(amount)} · ${t('budget', 'Settled')}`,
                    cls: ' settled',
                });
            }),
        ];

        document.getElementById('share-people').innerHTML = html.join('');
        this._updateShareSummary();
    }

    /** Each person's share beside their name, and the total or what is wrong underneath */
    _updateShareSummary() {
        const s = this._share;
        const result = this._computeShare();
        const byId = new Map(result.shares.map(x => [String(x.id), x.cents]));
        const meIn = s.method === 'amount' || s.includeMe;

        document.querySelectorAll('#share-people .share-person-row:not(.settled)').forEach(rowEl => {
            const id = rowEl.dataset.person;
            let cents = null;
            if (!result.error) {
                cents = id === 'me' ? (meIn ? result.mineCents : null) : (byId.get(id) ?? null);
            }
            rowEl.querySelector('.share-person-amount').textContent =
                cents === null ? '' : this.formatCurrency(cents / 100, s.currency);
        });

        const removing = result.error?.code === 'nobody' && s.hadOpen;
        const summary = document.getElementById('share-summary');
        summary.classList.toggle('error', !!result.error && !removing);
        summary.textContent = this._shareSummaryText(result, removing);
    }

    _shareSummaryText(result, removing) {
        const s = this._share;
        const money = (cents) => this.formatCurrency(cents / 100, s.currency);

        if (removing) {
            return t('budget', 'Nobody is ticked, so saving removes this split.');
        }
        switch (result.error?.code) {
        case undefined: {
            const owed = money(result.shares.reduce((sum, x) => sum + x.cents, 0));
            const mine = money(result.mineCents);
            return s.isCredit
                ? t('budget', 'You owe the others {owed}. Your part is {mine}.', { owed, mine })
                : t('budget', 'The others owe you {owed}. Your share is {mine}.', { owed, mine });
        }
        case 'nobody':
            return t('budget', 'Tick at least one person to split with.');
        case 'too-small':
            return t('budget', 'There is not enough to split between this many people.');
        case 'missing':
            return s.method === 'percent'
                ? t('budget', 'Enter a percentage for everyone ticked.')
                : t('budget', 'Enter an amount for everyone ticked.');
        case 'percent-total':
            return t('budget', 'The percentages add up to {total} and need to add up to {full}.', {
                total: `${Number(result.error.percent.toFixed(2))}%`,
                full: '100%',
            });
        case 'over-total':
            return t('budget', 'The amounts add up to {amount} more than there is to split.', { amount: money(result.error.overCents) });
        default:
            return t('budget', 'Failed to save the split');
        }
    }

    _setShareMethod(method) {
        const s = this._share;
        if (!s || s.method === method) return;

        // Carry the amounts over, so switching to By amount starts from them
        const before = this._computeShare();
        if (method === 'amount' && !before.error) {
            before.shares.forEach(x => {
                if (!s.values.amount.get(x.id)) {
                    s.values.amount.set(x.id, (x.cents / 100).toFixed(2));
                }
            });
        }
        s.method = method;
        this._renderSharePeople();
    }

    async saveShareExpense() {
        const s = this._share;
        if (!s) return;

        const result = this._computeShare();
        const removing = result.error?.code === 'nobody' && s.hadOpen;
        if (result.error && !removing) {
            showWarning(this._shareSummaryText(result, false));
            return;
        }
        if (removing && !await confirmDialog(t('budget', 'Remove this split? Nobody will owe anything for this transaction.'))) {
            return;
        }

        // Money in is split the other way round: you owe them
        const sign = s.isCredit ? -1 : 1;
        const splits = removing ? [] : result.shares.map(x => ({
            contactId: x.id,
            amount: (sign * x.cents / 100).toFixed(2),
        }));
        const notes = document.getElementById('share-notes').value.trim();

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/shared/transactions/${s.transactionId}/shares`), {
                method: 'PUT',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ splits, notes: notes || null })
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => null);
                showError(errorData?.error || t('budget', 'Failed to save the split'));
                return;
            }

            this.closeModal(document.getElementById('share-expense-modal'));
            showSuccess(removing ? t('budget', 'Split removed') : t('budget', 'Split saved'));
            await this.app.loadSharedTransactionIds();
            // Refresh transaction table if visible to show shared badge
            const tbody = document.querySelector('#transactions-table tbody');
            if (tbody) {
                this.app.renderEnhancedTransactionsTable();
            }
        } catch (error) {
            console.error('Failed to save split:', error);
            showError(t('budget', 'Failed to save the split'));
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
            splitType.addEventListener('change', () => this._setShareMethod(splitType.value));
        }

        const people = document.getElementById('share-people');
        if (people) {
            people.addEventListener('change', (e) => {
                const check = e.target.closest('.share-person-check');
                if (!check || !this._share) return;
                const id = check.closest('.share-person-row').dataset.person;
                if (id === 'me') {
                    this._share.includeMe = check.checked;
                } else if (check.checked) {
                    this._share.ticked.add(Number(id));
                } else {
                    this._share.ticked.delete(Number(id));
                }
                this._renderSharePeople();
            });
            people.addEventListener('input', (e) => {
                const input = e.target.closest('.share-person-value');
                if (!input || !this._share) return;
                const id = input.closest('.share-person-row').dataset.person;
                this._share.values[this._share.method].set(id === 'me' ? 'me' : Number(id), input.value);
                if (this._share.method === 'percent') {
                    this._share.percentTyped = true;
                }
                this._updateShareSummary();
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
