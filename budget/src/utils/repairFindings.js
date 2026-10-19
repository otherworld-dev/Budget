/**
 * What Settings > Repair Data shows for the findings of a scan.
 */
import { translate as t } from '@nextcloud/l10n';
import { escapeHtml } from './dom.js';

/**
 * The note beside a duplicate payment: the payment it repeats, and what
 * removing it takes along. Values go in through t()'s placeholders, which
 * escape them.
 *
 * - same_occurrence: two of a bill's payments settle one occurrence. When
 *   their amounts differ (an older version's pending row, booked at the
 *   bill's amount back then), the other payment's amount is shown too, so
 *   the two can be told apart.
 * - deleted_bill: two payments of a bill that no longer exists, booked a
 *   few days apart (#399).
 * - otherwise: a payment the app booked that an imported row records too.
 *
 * When removing a transfer's withdrawal takes the deposit the app booked
 * for it (removesDeposit), the note says so, as that row sits in another
 * account. A withdrawal with no deposit left goes on its own.
 *
 * @param {object} finding one of the scan's duplicateTransactions
 * @param {function(number): string} formatAmount
 * @return {string} HTML
 */
export function duplicateNote(finding, formatAmount) {
    let note;
    if (finding.reason === 'same_occurrence') {
        note = amountsDiffer(finding)
            ? t('budget', 'Looks like a second payment for {occurrence} (other payment of {amount} on {date})', {
                occurrence: finding.occurrence,
                amount: formatAmount(finding.originalAmount),
                date: finding.originalDate,
            })
            : t('budget', 'Looks like a second payment for {occurrence} (other payment on {date})', {
                occurrence: finding.occurrence,
                date: finding.originalDate,
            });
    } else if (finding.reason === 'deleted_bill') {
        note = t('budget', 'Looks like a second payment of {bill} (other payment on {date})', {
            bill: finding.billName || finding.vendor || t('budget', '(unnamed)'),
            date: finding.originalDate,
        });
    } else {
        note = t('budget', 'Also recorded as {description} on {date}', {
            description: finding.originalDescription || t('budget', '(unnamed)'),
            date: finding.originalDate,
        });
    }
    if (finding.originalReconciled) {
        note += ` (${t('budget', 'reconciled')})`;
    }
    if (finding.removesDeposit) {
        note += ` ${t('budget', 'The other side of the transfer is removed with it.')}`;
    }
    return note;
}

function amountsDiffer(finding) {
    const amount = Number(finding.amount);
    const original = Number(finding.originalAmount);
    return Number.isFinite(amount) && Number.isFinite(original) && Math.abs(amount - original) >= 0.005;
}

/**
 * One row of "Income Received Early but Still Expected": an income an older
 * version left expected on the date of a payment it had already recorded.
 * Repairing moves it on to the date shown.
 *
 * @param {object} income one of the scan's stuckIncome
 * @param {function(number): string} formatAmount
 * @return {string} HTML
 */
export function stuckIncomeItem(income, formatAmount) {
    return `<div class="repair-item">
        <span>${escapeHtml(income.name) || t('budget', '(unnamed)')}</span>
        <span>${formatAmount(income.amount)}</span>
        <span>${t('budget', 'Received {date}', { date: income.lastReceivedDate })}</span>
        <span class="repair-item-note">${t('budget', 'Moves on to {date}', { date: income.settledNextExpectedDate })}</span>
    </div>`;
}
