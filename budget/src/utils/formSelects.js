/**
 * Dropdowns on edit forms that may not list the value being edited.
 */
import { translate as t } from '@nextcloud/l10n';
import { escapeHtml } from './dom.js';
import { usableCategories } from './accounts.js';

/**
 * The <option>s of a split part's category picker: the categories of the
 * transaction's type, and only the owner's in an account someone shared
 * with you (usableCategories). The part's own category is always kept, as
 * a disabled "Unavailable" placeholder when it isn't shared with you, so a
 * save sends it back instead of clearing it.
 *
 * @param {Array} categories Flat list
 * @param {number|string|null} selectedId
 * @param {string|null} transactionType 'credit' lists income categories, else expense
 * @param {object|null} account The transaction's account
 * @returns {string}
 */
export function categoryOptionsHtml(categories, selectedId = null, transactionType = null, account = null) {
    if (!categories) return '';
    const categoryType = transactionType === 'credit' ? 'income' : 'expense';
    const offered = usableCategories(categories, [account], [selectedId]) || categories;
    const hasSelected = selectedId !== null && selectedId !== undefined && selectedId !== '';
    const unlisted = hasSelected && !offered.some(c => String(c.id) === String(selectedId))
        ? `<option value="${escapeHtml(String(selectedId))}" selected disabled data-unavailable="1">${escapeHtml(t('budget', 'Unavailable (not shared with you)'))}</option>`
        : '';
    return unlisted + offered
        .filter(c => c.type === categoryType)
        .map(c => `<option value="${c.id}" ${hasSelected && String(c.id) === String(selectedId) ? 'selected' : ''}>${escapeHtml(c.name)}</option>`)
        .join('');
}

/**
 * Select `value` even when the dropdown holds no option for it: a shared
 * bill, transfer or income whose category or account was not shared
 * alongside it. Assigning a missing value silently yields "", which the save
 * then submitted as null, stripping it off the owner's item, so a disabled
 * placeholder carrying the real id is added instead (#370).
 *
 * @param {HTMLSelectElement|null} select
 * @param {number|string|null|undefined} value
 */
export function selectPossiblyUnavailable(select, value) {
    if (!select) return;
    if (value === null || value === undefined || value === '') {
        select.value = '';
        return;
    }

    const wanted = String(value);
    select.value = wanted;
    if (select.value === wanted) return;

    const option = document.createElement('option');
    option.value = wanted;
    option.textContent = t('budget', 'Unavailable (not shared with you)');
    option.disabled = true;
    option.dataset.unavailable = '1';
    select.appendChild(option);
    select.value = wanted;
}

/**
 * Drop the placeholder options a previous item left behind, so a stale
 * "Unavailable" entry never lingers in the next item's dropdowns.
 *
 * @param {string[]} ids select element ids
 */
export function clearUnavailableOptions(ids) {
    ids.forEach(id => {
        const select = document.getElementById(id);
        if (!select) return;
        select.querySelectorAll('option[data-unavailable="1"], option[data-closed-account="1"]').forEach(opt => opt.remove());
    });
}
