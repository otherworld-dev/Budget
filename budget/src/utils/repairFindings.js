/**
 * What Settings > Repair Data shows for the findings of a scan.
 */
import { translate as t } from '@nextcloud/l10n';
import { escapeHtml } from './dom.js';

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
