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
