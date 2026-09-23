/**
 * Loading placeholder for a view's list while its data is fetched.
 *
 * Only a list that has never been filled gets the placeholder. On a later
 * visit the old content stays up until the fresh data replaces it, which
 * reads as instant rather than blinking to a spinner on every navigation.
 */
import { translate as t } from '@nextcloud/l10n';

const MARKER = 'budget-loading';

/**
 * Put a spinner in an empty container. Returns true when one was added.
 *
 * @param {HTMLElement|string|null} containerOrId
 * @param {string} [label] Replaces the default "Loading...".
 */
export function showLoading(containerOrId, label) {
    const container = typeof containerOrId === 'string'
        ? document.getElementById(containerOrId)
        : containerOrId;
    if (!container || container.children.length > 0) return false;

    const el = document.createElement('div');
    el.className = MARKER;
    el.setAttribute('role', 'status');

    const spinner = document.createElement('span');
    spinner.className = 'icon-loading';
    spinner.setAttribute('aria-hidden', 'true');

    const text = document.createElement('span');
    text.textContent = label || t('budget', 'Loading...');

    el.append(spinner, text);
    container.appendChild(el);
    return true;
}

/**
 * Remove the placeholder, e.g. when the fetch failed and nothing will be
 * rendered over it.
 */
export function clearLoading(containerOrId) {
    const container = typeof containerOrId === 'string'
        ? document.getElementById(containerOrId)
        : containerOrId;
    container?.querySelectorAll(`:scope > .${MARKER}`).forEach(el => el.remove());
}

/**
 * Replace a list's content with an inline "could not load" message and a
 * Retry button. Used when a view's first fetch fails, so the page says what
 * happened instead of showing an empty list (or a misleading "nothing here
 * yet") and gives a way to try again without reloading.
 *
 * A <tbody> gets a single full-width row, so the table keeps its shape.
 *
 * @param {HTMLElement|string|null} containerOrId
 * @param {string} message What failed to load, already translated.
 * @param {Function} [onRetry] Called when Retry is pressed; omitted = no button.
 * @param {object} [options]
 * @param {number} [options.colspan] Columns for a <tbody> row (default: the table's header count).
 * @returns {HTMLElement|null} The error element, or null when there is no container.
 */
export function showLoadError(containerOrId, message, onRetry, options = {}) {
    const container = typeof containerOrId === 'string'
        ? document.getElementById(containerOrId)
        : containerOrId;
    if (!container) return null;

    const box = document.createElement('div');
    box.className = 'budget-load-error';
    box.setAttribute('role', 'alert');

    const text = document.createElement('p');
    text.textContent = message;
    box.appendChild(text);

    if (typeof onRetry === 'function') {
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'budget-load-error-retry';
        retry.textContent = t('budget', 'Retry');
        retry.addEventListener('click', () => {
            retry.disabled = true;
            onRetry();
        });
        box.appendChild(retry);
    }

    container.replaceChildren();
    if (container.tagName === 'TBODY') {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        const headerCells = container.closest('table')?.querySelectorAll('thead th').length;
        cell.colSpan = options.colspan || headerCells || 1;
        cell.appendChild(box);
        row.appendChild(cell);
        container.appendChild(row);
    } else {
        container.appendChild(box);
    }
    return box;
}
