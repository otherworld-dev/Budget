/**
 * Type-to-search picker for a Nextcloud user.
 *
 * The share and contact forms used to fill a dropdown with `query=*`, every
 * user the server would list. The server now follows Nextcloud's sharing
 * settings, and with user enumeration off that list is empty, while a
 * search for someone's exact name, id or email still finds them — the same as
 * the Files share dialog. So the user types, and the matches are offered as
 * they do.
 *
 * Markup: the text input is wrapped in a div.user-picker and becomes an ARIA
 * combobox; the listbox of matches follows it inside the wrapper.
 */

import { translate as t } from '@nextcloud/l10n';
import { apiFetch } from './api.js';
import { debounce } from './helpers.js';

const SEARCH_URL = '/apps/budget/api/shared/users/search?query=';
/** The server answers nothing for shorter queries (other than '*'). */
export const MIN_QUERY_LENGTH = 2;
const DELAY_MS = 300;

let pickerCount = 0;

/**
 * Turn a text input into a user picker.
 *
 * @param {HTMLInputElement} input
 * @param {object} [options]
 * @param {Function} [options.onSelect] - Called with {uid, displayName} when
 *   a user is picked, and with null when the text is edited afterwards
 * @returns {{selected: Function, setSelected: Function, clear: Function}}
 *   `selected()` is the picked user or null
 */
export function attachUserPicker(input, { onSelect } = {}) {
    const listId = `user-picker-list-${++pickerCount}`;
    const list = document.createElement('ul');
    list.id = listId;
    list.className = 'user-picker-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    if (input.getAttribute('aria-label')) {
        list.setAttribute('aria-label', input.getAttribute('aria-label'));
    } else if (input.labels?.[0]) {
        list.setAttribute('aria-labelledby', input.labels[0].id || (input.labels[0].id = `${listId}-label`));
    }

    const status = document.createElement('span');
    status.className = 'hidden-visually';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');

    // The list is positioned against a wrapper the size of the input
    const wrapper = document.createElement('div');
    wrapper.className = 'user-picker';
    input.insertAdjacentElement('beforebegin', wrapper);
    wrapper.append(input, list, status);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);
    input.setAttribute('autocomplete', 'off');
    if (!input.placeholder) {
        input.placeholder = t('budget', 'Type to search...');
    }

    const cache = new Map();
    let users = [];
    let active = -1;
    let selected = null;
    let requestSeq = 0;

    const label = (user) => (user.displayName && user.displayName !== user.uid
        ? `${user.displayName} (${user.uid})`
        : user.uid);

    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };

    const setActive = (index) => {
        const options = list.querySelectorAll('[role="option"]');
        options.forEach((option, i) => {
            const on = i === index;
            option.classList.toggle('active', on);
            option.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        active = index;
        if (index >= 0 && options[index]) {
            input.setAttribute('aria-activedescendant', options[index].id);
            options[index].scrollIntoView?.({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };

    const choose = (user) => {
        selected = user;
        input.value = label(user);
        close();
        onSelect?.(user);
    };

    const render = () => {
        list.innerHTML = '';
        if (users.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'user-picker-empty';
            empty.textContent = t('budget', 'Nextcloud user not found');
            list.appendChild(empty);
            status.textContent = empty.textContent;
        } else {
            users.forEach((user, i) => {
                const option = document.createElement('li');
                option.id = `${listId}-option-${i}`;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                option.className = 'user-picker-option';
                option.textContent = label(user);
                // mousedown, not click: it lands before the input's blur
                option.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    choose(user);
                });
                list.appendChild(option);
            });
            status.textContent = '';
        }
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        setActive(-1);
    };

    const search = debounce(async (query) => {
        const seq = ++requestSeq;
        let found = cache.get(query);
        if (!found) {
            found = await apiFetch(SEARCH_URL + encodeURIComponent(query)).catch((error) => {
                console.error('User search failed:', error);
                return null;
            });
            if (!Array.isArray(found)) found = [];
            cache.set(query, found);
        }
        // A slower, older answer must not replace the one for what is typed now
        if (seq !== requestSeq || input.value.trim() !== query || document.activeElement !== input) return;
        users = found;
        render();
    }, DELAY_MS);

    input.addEventListener('input', () => {
        if (selected) {
            selected = null;
            onSelect?.(null);
        }
        const query = input.value.trim();
        if (query.length < MIN_QUERY_LENGTH) {
            requestSeq++;
            close();
            return;
        }
        search(query);
    });

    input.addEventListener('keydown', (e) => {
        const open = !list.hidden;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!open || users.length === 0) return;
            e.preventDefault();
            const step = e.key === 'ArrowDown' ? 1 : -1;
            setActive((active + step + users.length) % users.length);
        } else if (e.key === 'Enter') {
            if (open && active >= 0 && users[active]) {
                e.preventDefault();
                choose(users[active]);
            }
        } else if (e.key === 'Escape') {
            if (open) {
                // Close the list, not the dialog the picker sits in
                e.preventDefault();
                e.stopPropagation();
                close();
            }
        }
    });

    input.addEventListener('blur', close);

    return {
        selected: () => selected,
        /** Show an existing choice, e.g. when editing a linked contact. */
        setSelected(user) {
            selected = user || null;
            input.value = user ? label(user) : '';
            close();
        },
        clear() {
            selected = null;
            input.value = '';
            requestSeq++;
            close();
        },
    };
}
