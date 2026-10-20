/**
 * Keyboard focus around the actions on a list row (#399).
 *
 * Mark Paid disables its button while it runs (see submitGuard.js), and a
 * browser drops focus from an element that becomes disabled; the list then
 * re-renders, so the button is gone too. Pressed from the keyboard, focus
 * fell to the page body and the next Tab started again from the top.
 */

/**
 * Run a row action and, when it was started from the keyboard, put focus
 * back on the row once it is done, if nothing else has taken it: the same
 * action's button when the row still offers it, else the row's first
 * button, else the row itself.
 *
 * @template T
 * @param {Event} event the click that started it; a key press on a button
 *   clicks it with detail 0
 * @param {string} rowSelector finds the row after a re-render
 * @param {string} buttonSelector the action's button within the row
 * @param {() => (Promise<T>|T)} action
 * @return {Promise<T>}
 */
export async function keepRowFocus(event, rowSelector, buttonSelector, action) {
    const fromKeyboard = event?.detail === 0;
    try {
        return await action();
    } finally {
        if (fromKeyboard) {
            refocusRow(rowSelector, buttonSelector);
        }
    }
}

/**
 * Focus a row again when focus has fallen to the page body
 *
 * @param {string} rowSelector
 * @param {string} buttonSelector the button to prefer
 * @return {boolean} whether focus was moved
 */
export function refocusRow(rowSelector, buttonSelector) {
    const active = document.activeElement;
    if (active && active !== document.body && active.isConnected) {
        return false;
    }
    const row = document.querySelector(rowSelector);
    if (!row) {
        return false;
    }
    const target = row.querySelector(`${buttonSelector}:not([disabled])`)
        || row.querySelector('button:not([disabled])');
    if (target) {
        target.focus();
        return true;
    }
    row.setAttribute('tabindex', '-1');
    row.focus();
    return true;
}
