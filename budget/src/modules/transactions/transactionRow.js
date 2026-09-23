/**
 * One transaction row, for the main transactions list and an account's
 * register.
 *
 * Both tables used to build their rows separately and drifted: the register
 * lost badges the main list had gained, and the two spelt the transfer badge
 * differently. The row is also the phone card on narrow screens (style.css
 * re-lays out the cells), so this is the only markup either list has.
 *
 * The two variants keep their own cell classes because their stylesheets and
 * handlers differ: the main list ('ledger') has a select box, inline-editable
 * cells, tags and account columns and a ⋮ menu; the register ('register')
 * has plain cells with edit and delete buttons.
 */

import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { escapeHtml } from '../../utils/dom.js';
import { hasSplitPortion, transactionDisplayAmount } from '../../utils/helpers.js';

/**
 * The badges shown under a transaction's description.
 *
 * @param {object} tx - Transaction row as the API returns it
 * @param {object} ctx - See renderTransactionRow
 * @returns {string} HTML, empty when there are none
 */
export function transactionBadges(tx, ctx) {
    const isSplit = tx.isSplit || tx.is_split;
    const isSplitPortion = hasSplitPortion(tx);

    const scheduled = tx.status === 'scheduled'
        ? `<span class="scheduled-badge" title="${t('budget', 'Future transaction — not counted in the current balance until it occurs')}">${t('budget', 'Scheduled')}</span>`
        : '';
    const pending = tx.status === 'pending'
        ? `<span class="pending-badge" title="${t('budget', 'Not yet posted by your bank')}">${t('budget', 'Pending')}</span>`
        : '';
    const forecastExcluded = tx.excludedFromForecast
        ? `<span class="forecast-excluded-badge" title="${t('budget', 'Excluded from forecast (extraordinary / one-time)')}">${t('budget', 'No forecast')}</span>`
        : '';
    const attachmentCount = ctx.attachmentCount;
    const attachment = attachmentCount
        ? `<span class="attachment-indicator" title="${n('budget', '%n receipt attached', '%n receipts attached', attachmentCount)}">&#x1F4CE;${attachmentCount > 1 ? ' ' + attachmentCount : ''}</span>`
        : '';

    let linked = '';
    if (tx.linkedTransactionId != null) {
        const accountName = tx.linkedAccountName
            || (ctx.accounts || []).find(a => a.id === tx.linkedAccountId)?.name
            || '';
        const direction = tx.type === 'debit' ? '→' : '←';
        // The names are escaped here and t() is told not to escape again, so
        // "B&Q" reads as B&Q rather than B&amp;Q.
        const label = accountName
            ? t('budget', 'Transfer {direction} {account}', { direction, account: escapeHtml(accountName) }, undefined, { escape: false })
            : t('budget', 'Transfer');
        const title = accountName
            ? t('budget', 'Click to view linked transaction in {account}', { account: escapeHtml(accountName) }, undefined, { escape: false })
            : t('budget', 'Linked transfer');
        linked = `<button type="button" class="linked-indicator" data-transaction-id="${tx.id}" data-linked-id="${tx.linkedTransactionId}" data-linked-account-id="${tx.linkedAccountId || ''}" title="${title}"><span aria-hidden="true">&#x1F517;</span> ${label}</button>`;
    }

    // Filtering by a category also matches a split through its parts, and
    // the row then stands for the part that matched rather than the whole
    // transaction (#359).
    const split = isSplit
        ? `<span class="split-indicator" title="${isSplitPortion
            ? t('budget', 'Part of a split transaction. The amount shown is the part in this category.')
            : t('budget', 'Split transaction')}">${isSplitPortion ? t('budget', 'Split part') : t('budget', 'Split')}</span>`
        : '';
    const shared = ctx.sharedStatus === 'shared'
        ? `<span class="shared-indicator" title="${t('budget', 'Shared expense - unsettled')}">&#x1F91D; ${t('budget', 'Shared')}</span>`
        : ctx.sharedStatus === 'settled'
            ? `<span class="shared-settled-indicator" title="${t('budget', 'Shared expense - settled')}">&#x2705; ${t('budget', 'Settled')}</span>`
            : '';
    const pension = tx.pensionContribId
        ? `<span class="pension-indicator" title="${t('budget', 'Funds a pension contribution — excluded from spending')}">${t('budget', 'Pension')}</span>`
        : '';

    const all = scheduled + pending + forecastExcluded + attachment + linked + split + shared + pension;
    return all ? `<div class="transaction-badges">${all}</div>` : '';
}

/**
 * What the amount cell shows.
 *
 * A split part can be negative — a receipt's discount line — which flips the
 * row's direction, so the direction comes from the signed share and not from
 * the parent's type. The sign is spelt out, not left to the colour, so it
 * reads for colour-blind users and in dark mode alike.
 *
 * @param {object} tx
 * @param {string} currency
 * @param {Function} formatCurrency - (amount, currency) => string
 * @returns {{isCredit: boolean, text: string, whole: string|null}} `whole` is
 *   the full transaction amount when the row shows only a part of it
 */
export function transactionAmountParts(tx, currency, formatCurrency) {
    const amount = parseFloat(tx.amount) || 0;
    const isSplitPortion = hasSplitPortion(tx);
    const portion = transactionDisplayAmount(tx);
    const signedPortion = tx.type === 'credit' ? portion : -portion;
    const isCredit = isSplitPortion ? signedPortion >= 0 : tx.type === 'credit';
    const shown = isSplitPortion ? Math.abs(portion) : Math.abs(amount);
    const showsWholeToo = isSplitPortion && Math.abs(Math.abs(portion) - Math.abs(amount)) > 0.005;

    return {
        isCredit,
        text: (isCredit ? '+' : '-') + formatCurrency(shown, currency),
        whole: showsWholeToo ? formatCurrency(Math.abs(amount), currency) : null,
    };
}

/**
 * The category cell's content for a split transaction: the parts' names, the
 * one that matched a category filter marked, and each part's amount in the
 * tooltip.
 *
 * @returns {{label: string, title: string}|null} null when the row carries no part list
 */
function splitCategoryParts(tx, currency, formatCurrency) {
    if (!tx.splitCategories) return null;
    const name = (part) => part.categoryName || t('budget', 'Uncategorized');
    return {
        label: tx.splitCategories.map(part =>
            '<span class="split-cat-item' + (part.matched ? ' is-match' : '') + '">' + escapeHtml(name(part)) + '</span>'
        ).join(' / '),
        title: tx.splitCategories.map(part => escapeHtml(name(part) + ': ' + formatCurrency(part.amount, currency))).join('&#10;'),
    };
}

function balanceCell(balance, projected, formatCurrency, currency) {
    const content = balance !== undefined && balance !== null
        ? `<span class="transaction-balance ${balance >= 0 ? 'positive' : 'negative'}${projected ? ' projected' : ''}">${formatCurrency(balance, currency)}</span>`
        : '';
    return `<td class="balance-column">${content}</td>`;
}

/**
 * Render one transaction as a table row.
 *
 * @param {object} tx - Transaction row as the API returns it
 * @param {object} ctx
 * @param {'ledger'|'register'} ctx.variant - Main list or account register
 * @param {string} ctx.currency - Currency the row's amounts are in
 * @param {Function} ctx.formatCurrency - (amount, currency) => string
 * @param {Function} ctx.formatDate - (date) => string
 * @param {Array} [ctx.accounts] - For the account column and transfer names
 * @param {Array} [ctx.categories] - For the category column
 * @param {number} [ctx.balance] - Running balance after this row, if known
 * @param {string} [ctx.sharedStatus] - 'shared' | 'settled' for a shared expense
 * @param {number} [ctx.attachmentCount] - Receipts attached
 * @param {boolean} [ctx.selected] - Ledger: the row's box is ticked
 * @param {string} [ctx.tagsHtml] - Ledger: the tags cell's chips
 * @param {Array<number>} [ctx.tagIds] - Ledger: the tags cell's value
 * @returns {string} The <tr> markup
 */
export function renderTransactionRow(tx, ctx) {
    const { currency, formatCurrency, formatDate } = ctx;
    const ledger = ctx.variant !== 'register';

    const category = (ctx.categories || []).find(c => c.id === tx.categoryId);
    const account = (ctx.accounts || []).find(a => a.id === tx.accountId);
    const isSplit = tx.isSplit || tx.is_split;
    const isSplitPortion = hasSplitPortion(tx);
    const isScheduled = tx.status === 'scheduled';
    const isLinked = tx.linkedTransactionId != null;
    const noDescription = t('budget', 'No description');
    const amount = transactionAmountParts(tx, currency, formatCurrency);
    const split = isSplit ? splitCategoryParts(tx, currency, formatCurrency) : null;
    const badges = transactionBadges(tx, ctx);

    const rowClasses = ['transaction-row'];
    if (isLinked) rowClasses.push('is-linked');
    if (tx.reconciled) rowClasses.push('is-reconciled');
    if (isScheduled) rowClasses.push('scheduled-transaction');
    if (tx.status === 'pending') rowClasses.push('pending-transaction');

    if (!ledger) {
        const categoryContent = split
            ? `<span class="category-name split-category" title="${split.title}">${split.label}</span>`
            : isSplit
                ? `<span class="category-name split-category">${t('budget', 'Split')}</span>`
                : `<span class="category-name ${category ? '' : 'uncategorized'}">${category ? escapeHtml(category.name) : t('budget', 'Uncategorized')}</span>`;

        return `
            <tr class="${rowClasses.join(' ')}" data-transaction-id="${tx.id}">
                <td class="date-column">
                    <span class="transaction-date">${formatDate(tx.date)}</span>
                </td>
                <td class="description-column">
                    <div class="transaction-description">
                        <span class="description-main">${escapeHtml(tx.description) || noDescription}</span>
                        ${tx.reference ? `<span class="secondary-text">${escapeHtml(tx.reference)}</span>` : ''}
                        ${badges}
                    </div>
                </td>
                <td class="vendor-column">${escapeHtml(tx.vendor || '')}</td>
                <td class="category-column">
                    ${categoryContent}
                    <div class="transaction-tags-display" data-transaction-id="${tx.id}" style="margin-top: 4px;"></div>
                </td>
                <td class="amount-column">
                    <span class="transaction-amount ${amount.isCredit ? 'credit' : 'debit'}">${amount.text}</span>
                    ${amount.whole ? `<span class="amount-whole">${t('budget', 'of {total}', { total: amount.whole })}</span>` : ''}
                </td>
                ${balanceCell(ctx.balance, isScheduled, formatCurrency, currency)}
                <td class="actions-column">
                    <div class="transaction-actions">
                        <button class="icon-rename edit-transaction-btn"
                                data-transaction-id="${tx.id}"
                                title="${t('budget', 'Edit transaction')}" aria-label="${t('budget', 'Edit transaction')}"></button>
                        <button class="icon-delete delete-transaction-btn"
                                data-transaction-id="${tx.id}"
                                title="${t('budget', 'Delete transaction')}" aria-label="${t('budget', 'Delete transaction')}"></button>
                    </div>
                </td>
            </tr>
        `;
    }

    const categoryContent = split
        ? `<span class="category-badge cell-display split-category">${split.label}</span>`
        : isSplit
            ? `<span class="category-badge cell-display split-category">${t('budget', 'Split')}</span>`
            : `<span class="category-badge cell-display ${category ? 'categorized' : 'uncategorized'}">${category && category.color ? `<span class="category-dot" style="background-color: ${escapeHtml(category.color)}" aria-hidden="true"></span>` : ''}${category ? escapeHtml(category.name) : t('budget', 'Uncategorized')}</span>`;

    return `
        <tr class="${rowClasses.join(' ')}" data-transaction-id="${tx.id}">
            <td class="select-column">
                <input type="checkbox" class="transaction-checkbox"
                       aria-label="${escapeHtml(t('budget', 'Select {description}', { description: tx.description || noDescription }, undefined, { escape: false }))}"
                       data-transaction-id="${tx.id}"
                       ${ctx.selected ? 'checked' : ''}>
            </td>
            <td class="date-column editable-cell"
                data-field="date"
                data-value="${tx.date}"
                data-transaction-id="${tx.id}">
                <span class="cell-display">${formatDate(tx.date)}</span>
            </td>
            <td class="description-column editable-cell"
                data-field="description"
                data-value="${escapeHtml(tx.description)}"
                data-transaction-id="${tx.id}">
                <div class="transaction-description">
                    <span class="primary-text cell-display">${escapeHtml(tx.description) || noDescription}</span>
                    ${tx.reference ? `<span class="secondary-text">${escapeHtml(tx.reference)}</span>` : ''}
                    ${badges}
                </div>
            </td>
            <td class="vendor-column editable-cell"
                data-field="vendor"
                data-value="${escapeHtml(tx.vendor || '')}"
                data-transaction-id="${tx.id}">
                <span class="cell-display">${escapeHtml(tx.vendor) || '-'}</span>
            </td>
            <td class="category-column ${isSplit ? '' : 'editable-cell'}"
                data-field="categoryId"
                data-value="${tx.categoryId || ''}"
                data-transaction-id="${tx.id}"
                ${split ? `title="${split.title}"` : ''}>
                ${categoryContent}
            </td>
            <td class="tags-column editable-cell"
                data-field="tags"
                data-value="${(ctx.tagIds || []).join(',')}"
                data-category-id="${tx.categoryId || ''}"
                data-transaction-id="${tx.id}">
                <span class="cell-display">
                    ${ctx.tagsHtml || ''}
                </span>
            </td>
            <td class="amount-column ${isSplitPortion ? 'split-portion' : 'editable-cell'}"
                data-field="amount"
                data-value="${tx.amount}"
                data-type="${tx.type}"
                data-transaction-id="${tx.id}"
                ${isSplitPortion ? `title="${t('budget', 'This amount is set by the transaction split. Open the split to change it.')}"` : ''}>
                <span class="amount cell-display ${amount.isCredit ? 'positive' : 'negative'}">${amount.text}</span>
                ${amount.whole ? `<span class="amount-whole">${t('budget', 'of {total}', { total: amount.whole })}</span>` : ''}
            </td>
            ${balanceCell(ctx.balance, isScheduled, formatCurrency, currency)}
            <td class="account-column editable-cell"
                data-field="accountId"
                data-value="${tx.accountId}"
                data-transaction-id="${tx.id}">
                <span class="account-name cell-display">${account ? escapeHtml(account.name) : t('budget', 'Unknown Account')}</span>
            </td>
            <td class="actions-column">
                <div class="transaction-actions">
                    <button class="action-btn edit-btn transaction-edit-btn"
                            data-transaction-id="${tx.id}"
                            title="${t('budget', 'Edit transaction')}"
                            aria-label="${t('budget', 'Edit transaction')}">
                        <span class="icon-rename" aria-hidden="true"></span>
                    </button>
                    <button class="action-btn more-actions-btn"
                            data-transaction-id="${tx.id}"
                            title="${t('budget', 'More actions')}"
                            aria-label="${t('budget', 'More actions')}"
                            aria-haspopup="menu"
                            aria-expanded="false">
                        <span aria-hidden="true">&#x22EE;</span>
                    </button>
                </div>
            </td>
        </tr>
    `;
}
