/**
 * What Settings > Repair Data shows for the findings of a scan, and the
 * message after a repair.
 *
 * Amounts and dates go in spans of their own (repair-item-amount,
 * repair-item-date) that the stylesheet keeps whole: beside a long note,
 * Chrome broke a date after its hyphens ('2026-10-' / '15'). So do the
 * dates and amounts inside a note (repair-note-value), as the note wraps.
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { escapeHtml } from './dom.js';

/**
 * A date or amount for a note, as HTML in a span the stylesheet keeps on
 * one line: in the note's free text, Chrome broke 'on 2026-03-28' after
 * '2026-' and '41.70 CHF' at its space (#399).
 *
 * @param {string} text
 * @return {string} HTML
 */
function noteValue(text) {
    return `<span class="repair-note-value">${escapeHtml(text)}</span>`;
}

/**
 * t() with values that are HTML already: each is escaped where it is made
 * (escapeHtml(), noteValue()), so t() must not escape it again.
 */
const HTML_VALUES = { escape: false };

/**
 * One row of "Duplicate Auto-Generated Transactions": its tick, the
 * payment's name, amount and date, and the note on what it repeats.
 *
 * The name is the payment's vendor. A payment with none goes by its own
 * description (the reporter's 2.x pending row had its bill's name there
 * only), then by its bill's name, else by the name the other payment is
 * recorded under: the scan only pairs the two when that names the same
 * bill (#399).
 *
 * @param {object} finding one of the scan's duplicateTransactions
 * @param {function(number): string} formatAmount
 * @return {string} HTML
 */
export function duplicateItem(finding, formatAmount) {
    const id = Number(finding.duplicateId);
    return `<div class="repair-item">
        <label class="repair-item-select">
            <input type="checkbox" class="repair-dup-checkbox" data-transaction-id="${id}"${finding.selected ? ' checked' : ''}>
            <span>${duplicateName(finding)}</span>
        </label>
        <span class="repair-item-amount">${formatAmount(finding.amount)}</span>
        <span class="repair-item-date">${escapeHtml(finding.date)}</span>
        <span class="repair-item-note">${duplicateNote(finding, formatAmount)}</span>
        <button type="button" class="repair-item-ignore" data-transaction-id="${id}">${t('budget', 'Ignore')}</button>
    </div>`;
}

/**
 * The name a duplicate row goes by (see duplicateItem()), as HTML.
 *
 * @param {object} finding
 * @return {string} HTML
 */
function duplicateName(finding) {
    const name = finding.vendor || finding.description || finding.billName || finding.originalDescription;
    return name ? escapeHtml(name) : t('budget', '(unnamed)');
}

/**
 * One row the user told Repair Data to ignore: no tick, as it is never
 * deleted, and a button to bring it back to the list.
 *
 * @param {object} finding one of the scan's ignoredDuplicates
 * @param {function(number): string} formatAmount
 * @return {string} HTML
 */
export function ignoredDuplicateItem(finding, formatAmount) {
    return `<div class="repair-item repair-item-ignored">
        <span>${duplicateName(finding)}</span>
        <span class="repair-item-amount">${formatAmount(finding.amount)}</span>
        <span class="repair-item-date">${escapeHtml(finding.date)}</span>
        <span class="repair-item-note">${duplicateNote(finding, formatAmount)}</span>
        <button type="button" class="repair-item-unignore" data-transaction-id="${Number(finding.duplicateId)}">${t('budget', 'Stop ignoring')}</button>
    </div>`;
}

/**
 * The inside of "Duplicate Auto-Generated Transactions": the help, the rows
 * still to decide on, and the ignored ones folded away under them.
 *
 * Ignoring is for a row the user has checked and wants to keep, such as one
 * an earlier balance correction already made up for. Without it the same
 * rows came back on every scan with no way to say so (#399).
 *
 * @param {object[]} active the scan's duplicateTransactions
 * @param {object[]} ignored the scan's ignoredDuplicates
 * @param {Set<number>} ticked ids of the rows ticked for deletion
 * @param {function(number): string} formatAmount
 * @param {boolean} ignoredOpen whether the ignored list is unfolded
 * @return {string} HTML
 */
export function duplicateDetails(active, ignored, ticked, formatAmount, ignoredOpen = false) {
    const rows = active.map(d => duplicateItem({ ...d, selected: ticked.has(Number(d.duplicateId)) }, formatAmount)).join('');
    const help = active.length > 0
        ? `<p class="repair-category-help">${t('budget', 'Only ticked rows are deleted. Rows that come ticked are bill payments the app recorded that an imported bank transaction records as well. The others only look like duplicates and may be real payments: check them in the account first, and tick only the ones you are sure about.')} ${t('budget', 'Ignore a row you have checked and want to keep, and it is no longer listed.')}</p>`
        : `<p class="repair-category-help">${t('budget', 'Nothing left to check.')}</p>`;
    const ignoredList = ignored.length > 0
        ? `<details class="repair-ignored"${ignoredOpen ? ' open' : ''}>
            <summary>${n('budget', '%n ignored row', '%n ignored rows', ignored.length)}</summary>
            ${ignored.map(d => ignoredDuplicateItem(d, formatAmount)).join('')}
        </details>`
        : '';
    return help + rows + ignoredList;
}

/**
 * The note beside a duplicate payment: the payment it repeats, and what
 * removing it takes along. Names go in escaped, and dates and amounts
 * escaped and kept whole (noteValue()).
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
                occurrence: noteValue(finding.occurrence),
                amount: noteValue(formatAmount(finding.originalAmount)),
                date: noteValue(finding.originalDate),
            }, undefined, HTML_VALUES)
            : t('budget', 'Looks like a second payment for {occurrence} (other payment on {date})', {
                occurrence: noteValue(finding.occurrence),
                date: noteValue(finding.originalDate),
            }, undefined, HTML_VALUES);
    } else if (finding.reason === 'deleted_bill') {
        note = t('budget', 'Looks like a second payment of {bill} (other payment on {date})', {
            bill: escapeHtml(finding.billName || finding.vendor || t('budget', '(unnamed)')),
            date: noteValue(finding.originalDate),
        }, undefined, HTML_VALUES);
    } else {
        note = t('budget', 'Also recorded as {description} on {date}', {
            description: escapeHtml(finding.originalDescription || t('budget', '(unnamed)')),
            date: noteValue(finding.originalDate),
        }, undefined, HTML_VALUES);
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
        <span class="repair-item-amount">${formatAmount(income.amount)}</span>
        <span class="repair-item-date">${t('budget', 'Received {date}', { date: income.lastReceivedDate })}</span>
        <span class="repair-item-note">${t('budget', 'Moves on to {date}', { date: noteValue(income.settledNextExpectedDate) }, undefined, HTML_VALUES)}</span>
    </div>`;
}

/**
 * The message after a repair: what each repaired category changed.
 *
 * The incomes line came with #399 and has its singular and plural. The
 * older lines keep their {count} wording, which is already translated.
 *
 * @param {object} result the repair endpoint's response
 * @return {string}
 */
export function repairResultMessage(result) {
    const parts = [];
    if (result.duplicateTransactions) {
        parts.push(t('budget', '{count} duplicate transactions removed', { count: result.duplicateTransactions.deleted }));
    }
    if (result.stuckBills) {
        parts.push(t('budget', '{count} bill due dates fixed', { count: result.stuckBills.fixed }));
    }
    if (result.paidOneTimeBills) {
        parts.push(t('budget', '{count} one-time bills deactivated', { count: result.paidOneTimeBills.fixed }));
    }
    if (result.stuckIncome) {
        parts.push(n('budget', '%n income moved on to its next date', '%n incomes moved on to their next date', result.stuckIncome.fixed));
    }
    if (result.futureClearedTransactions) {
        parts.push(t('budget', '{count} future transactions set to scheduled', { count: result.futureClearedTransactions.fixed }));
    }
    if (result.splitParentCategories) {
        parts.push(t('budget', '{count} split transactions had a stray category removed', { count: result.splitParentCategories.fixed }));
    }
    if (result.liabilitySignFlipped) {
        parts.push(t('budget', '{count} debts re-recorded as owed', { count: result.liabilitySignFlipped.fixed }));
        if (result.liabilitySignFlipped.confirmed > 0) {
            parts.push(t('budget', '{count} confirmed as genuine credits', { count: result.liabilitySignFlipped.confirmed }));
        }
    }
    if (result.balanceDrift) {
        parts.push(t('budget', '{count} account balances corrected', { count: result.balanceDrift.updated }));
    }

    return t('budget', 'Repair complete: {details}', { details: parts.join(', ') });
}
