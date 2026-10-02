/**
 * The "Existing Transaction Found" dialog Mark Paid shows when transactions
 * already in the account may be the payment: link one, book a new one, or
 * record nothing.
 */
import { translate as t } from '@nextcloud/l10n';
import * as formatters from './formatters.js';
import * as dom from './dom.js';

/**
 * @param {object} bill the bill or transfer being paid
 * @param {Array<{transaction: object, score: number, matchReasons: string[]}>} candidates
 * @param {object} settings user settings, for formatting
 * @return {Promise<{action: 'create'|'link'|'skip', transactionId?: number}|null>} null when cancelled
 */
export function showMatchingTransactionDialog(bill, candidates, settings) {
    return new Promise((resolve) => {
        const existing = document.getElementById('matching-tx-modal');
        if (existing) existing.remove();

        const currency = bill.currency || settings?.default_currency || '';
        const formatAmount = (amount) => formatters.formatCurrency(amount, currency, settings);

        // Only pre-select a candidate when it is a strong match — for weak
        // matches the safe default is creating a new transaction, so a
        // quick Confirm never links (or skips) by accident (#274)
        const preselectFirst = candidates.length > 0 && candidates[0].score >= 50;

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
            const checked = (preselectFirst && i === 0) ? 'checked' : '';

            return `
                <label class="matching-tx-option ${(preselectFirst && i === 0) ? 'recommended' : ''}">
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
                    <button class="close-btn" title="${t('budget', 'Close')}" aria-label="${t('budget', 'Close')}">&times;</button>
                </div>
                <div class="budget-modal-body">
                    <p class="matching-tx-intro">${t('budget', 'We found existing transactions that may already represent this bill payment ({billName}, {amount}). Would you like to link one instead of creating a new transaction?', { billName: dom.escapeHtml(bill.name), amount: dom.escapeHtml(formatAmount(bill.amount)) }, undefined, { escape: false })}</p>
                    <p class="matching-tx-intro">${t('budget', 'Whichever you choose, the bill is marked as paid and moves on to its next due date.')}</p>
                    <div class="matching-tx-list">
                        ${candidateRows}
                    </div>
                    <label class="matching-tx-option matching-tx-create-new">
                        <input type="radio" name="matching-tx-choice" value="create" ${preselectFirst ? '' : 'checked'}>
                        <div class="matching-tx-details">
                            <span class="matching-tx-desc">${t('budget', 'Create a new transaction instead')}</span>
                        </div>
                    </label>
                    <label class="matching-tx-option matching-tx-skip">
                        <input type="radio" name="matching-tx-choice" value="skip">
                        <div class="matching-tx-details">
                            <span class="matching-tx-desc">${t('budget', 'Don\'t create any transaction (just mark as paid)')}</span>
                            <span class="matching-tx-skip-warning">${t('budget', 'Your account balance will NOT reflect this payment.')}</span>
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
