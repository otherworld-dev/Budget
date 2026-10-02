/**
 * Dropdowns on edit forms that may not list the value being edited.
 */
import { translate as t } from '@nextcloud/l10n';

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
