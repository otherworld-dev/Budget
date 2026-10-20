/**
 * The "Link to bill" dialog a transaction's row menu opens (#399): pick the
 * bill a payment was for, and the payment is filed under it.
 *
 * The Bills Calendar counts the rows that carry a bill, and only Mark Paid
 * gave a row one, for the occurrence due now. A payment typed in by hand, or
 * one of a bill deleted and set up again, showed under no bill. Linking sets
 * the row's bill and nothing else: the bill keeps its due date and its Mark
 * Paid (TransactionService::attachToBill()).
 */
import { translate as t } from '@nextcloud/l10n';
import * as formatters from './formatters.js';
import * as dom from './dom.js';
import { toCents } from './money.js';
import { ignoreOpeningClickOnOptions, isOpeningClick, keepFocusOnOpeningPress } from './doubleClick.js';

/**
 * The bills a transaction can be filed under: the user's own, paid from the
 * transaction's account, active or not, as the payment may be of an
 * occurrence long gone. A transfer counts by the account it pays from. A
 * bill shared with the user is left out; the server only files payments
 * under the user's own.
 *
 * @param {Array<object>|null} bills as GET /api/bills returns them
 * @param {object} transaction the row being filed
 * @return {Array<object>}
 */
export function linkableBills(bills, transaction) {
    return (bills || []).filter(bill => !bill._shared
        && bill.accountId != null
        && Number(bill.accountId) === Number(transaction?.accountId));
}

/**
 * The date a bill was or is due: a one-time bill's own date (kept as its
 * start date once paid), a recurring bill's next due date, and none for a
 * recurring bill that has ended.
 *
 * @param {object} bill
 * @return {string|null} Y-m-d
 */
function dueDateOf(bill) {
    if (bill.frequency === 'one-time') {
        return bill.startDate || bill.nextDueDate || null;
    }
    return bill.isActive === false ? null : (bill.nextDueDate || null);
}

/** Two amounts the same to the cent, either sign */
function sameAmount(a, b) {
    const x = toCents(a);
    const y = toCents(b);
    return Number.isFinite(x) && Number.isFinite(y) && Math.abs(x) === Math.abs(y);
}

/**
 * Ask which bill a transaction paid.
 *
 * Bills of the row's amount come first, then the rest by name. The
 * reporter files every invoice as a one-time bill of its own, so one
 * account can hold 60 bills with twins among them (two Miele invoices of
 * 1,269.55, both paid). A one-time bill takes its payments into its own
 * month in the Bills Calendar, so picking the wrong twin moves the payment
 * to the other invoice's month. Each bill therefore says when it was due
 * and last paid, and bills of one name are listed by that date.
 *
 * @param {Array<object>} bills the bills to offer, see linkableBills()
 * @param {object} settings user settings, for formatting
 * @param {object} [transaction] the row being filed, for its amount
 * @return {Promise<number|null>} the chosen bill's id, or null when cancelled
 */
export function chooseBill(bills, settings, transaction = undefined) {
    return new Promise((resolve) => {
        const existing = document.getElementById('bill-link-modal');
        if (existing) existing.remove();

        const entries = (bills || []).map(bill => ({
            bill,
            due: dueDateOf(bill),
            exact: transaction != null && sameAmount(bill.amount, transaction.amount),
        }));
        // An undated bill goes last among those of its name
        const dateOf = (entry) => entry.due || entry.bill.lastPaidDate || '9999-12-31';
        entries.sort((a, b) => (Number(b.exact) - Number(a.exact))
            || String(a.bill.name ?? '').localeCompare(String(b.bill.name ?? ''))
            || dateOf(a).localeCompare(dateOf(b))
            || (Number(a.bill.id) - Number(b.bill.id)));

        const options = entries.map(({ bill, due, exact }) => {
            const currency = bill.currency || settings?.default_currency || '';
            // An inactive bill is a paid one-time bill or one that has ended,
            // and still takes payments of the occurrences it had. Markup
            // from t(), which escapes the date it is given.
            const status = [
                due ? t('budget', 'Due {date}', { date: formatters.formatDate(due, settings) }) : null,
                bill.lastPaidDate ? t('budget', 'Paid {date}', { date: formatters.formatDate(bill.lastPaidDate, settings) }) : null,
                bill.isActive === false ? t('budget', 'Inactive') : null,
            ].filter(Boolean).join(' · ');

            return `
                <label class="matching-tx-option">
                    <input type="radio" name="bill-link-choice" value="${Number(bill.id)}">
                    <div class="matching-tx-details">
                        <div class="matching-tx-primary">
                            <span class="matching-tx-desc">${dom.escapeHtml(bill.name)}</span>
                            <span class="matching-tx-amount">${dom.escapeHtml(formatters.formatCurrency(bill.amount, currency, settings))}</span>
                        </div>
                        ${status ? `<div class="matching-tx-reasons">${status}</div>` : ''}
                        ${exact ? `<span class="matching-tx-badge">${t('budget', 'Exact amount')}</span>` : ''}
                    </div>
                </label>
            `;
        }).join('');

        const body = entries.length > 0
            ? `<p class="matching-tx-intro">${t('budget', 'Choose the bill this transaction paid. It then counts as one of the bill\'s payments, in the Bills Calendar too. The bill\'s due date doesn\'t change.')}</p>
               <div class="matching-tx-list">${options}</div>`
            : `<p class="matching-tx-intro">${t('budget', 'There are no bills on this account yet. Add the bill first, then link this payment to it.')}</p>`;

        const modal = document.createElement('div');
        modal.id = 'bill-link-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `
            <div class="budget-modal" style="max-width: 520px;" role="dialog" aria-modal="true" aria-labelledby="bill-link-title">
                <div class="budget-modal-header">
                    <h2 id="bill-link-title">${t('budget', 'Link to bill')}</h2>
                    <button class="close-btn" title="${t('budget', 'Close')}" aria-label="${t('budget', 'Close')}">&times;</button>
                </div>
                <div class="budget-modal-body">
                    ${body}
                </div>
                <div class="budget-modal-footer">
                    <button class="cancel-btn">${t('budget', 'Cancel')}</button>
                    ${entries.length > 0 ? `<button class="confirm-btn primary" disabled>${t('budget', 'Link')}</button>` : ''}
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        // A double click on the menu item that opened it picks no bill with
        // its second click, nor closes the dialog from its backdrop (see
        // utils/doubleClick.js, #399)
        const openedAt = Date.now();
        ignoreOpeningClickOnOptions(modal, '.matching-tx-option', openedAt);
        keepFocusOnOpeningPress(modal, openedAt);

        const finish = (billId) => {
            document.removeEventListener('keydown', onKeydown, true);
            modal.remove();
            resolve(billId);
        };
        const onKeydown = (e) => {
            if (e.key === 'Escape' && document.body.contains(modal)) {
                e.preventDefault();
                e.stopPropagation();
                finish(null);
            }
        };
        document.addEventListener('keydown', onKeydown, true);

        modal.querySelector('.close-btn').addEventListener('click', () => finish(null));
        modal.querySelector('.cancel-btn').addEventListener('click', () => finish(null));
        modal.addEventListener('click', (e) => {
            if (e.target === modal && !isOpeningClick(e, openedAt)) finish(null);
        });

        const confirm = modal.querySelector('.confirm-btn');
        if (confirm) {
            // Nothing is picked to begin with: a quick Link must never file
            // the payment under a bill the user didn't choose
            modal.addEventListener('change', (e) => {
                if (e.target.name === 'bill-link-choice') confirm.disabled = false;
            });
            confirm.addEventListener('click', () => {
                const selected = modal.querySelector('input[name="bill-link-choice"]:checked');
                if (selected) finish(parseInt(selected.value, 10));
            });
        }

        (modal.querySelector('input[name="bill-link-choice"]') || modal.querySelector('.cancel-btn')).focus();
    });
}
