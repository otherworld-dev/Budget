/**
 * The second click of a double click, told apart from a click of its own
 * (#399).
 *
 * Plenty of people double-click buttons. The first click acts, and what it
 * does changes what is under the pointer: Mark Paid re-renders the list and
 * the next bill's button slides up, or Skip opens a confirm dialog. The
 * second click then paid that next bill, or landed on the dialog's backdrop
 * and cancelled the question the first click had just asked.
 */

/**
 * Whether a click is the second (or a later) click of a double click. The
 * browser numbers the clicks of one: a single click has detail 1, and a
 * button pressed from the keyboard 0.
 *
 * @param {MouseEvent} event
 * @return {boolean}
 */
export function isRepeatClick(event) {
    return (event?.detail ?? 0) > 1;
}

/** How long after a dialog appears a click on its backdrop is still taken for the one that opened it */
export const OPENING_CLICK_MS = 400;

/**
 * Whether a click on a dialog's backdrop came with the click that opened
 * the dialog rather than to dismiss it: the second click of that double
 * click, or a click too soon after the dialog appeared to have been meant
 * for it (the pointer moved between the two, so the browser counted a
 * single click). A click with no pointer behind it (detail 0) is always
 * meant.
 *
 * @param {MouseEvent} event
 * @param {number} openedAt Date.now() when the dialog appeared
 * @return {boolean}
 */
export function isOpeningClick(event, openedAt) {
    return isRepeatClick(event)
        || ((event?.detail ?? 0) === 1 && Date.now() - openedAt < OPENING_CLICK_MS);
}

/**
 * Let the press of a backdrop click that isOpeningClick() ignores leave the
 * focus where it is. A press on something that can't take the focus moves
 * it to the page: the second press of the double click that opened a
 * destructive confirm took it off Cancel, and Enter then confirmed (#399).
 * A mousedown is numbered as its click is.
 *
 * @param {HTMLElement} backdrop
 * @param {number} openedAt Date.now() when the dialog appeared
 */
export function keepFocusOnOpeningPress(backdrop, openedAt) {
    backdrop.addEventListener('mousedown', (e) => {
        if (e.target === backdrop && isOpeningClick(e, openedAt)) {
            e.preventDefault();
        }
    });
}

/**
 * Let no click that isOpeningClick() takes for the opening one pick an
 * option of the dialog. The second click of a double click on Mark Paid
 * landed on an option of the dialog the first had opened, and the browser
 * passed it on to the option's radio: an unrelated transaction was picked
 * over the safe default (#274), and Confirm linked it as the bill's payment.
 * Such a click is cancelled on its way in (the capture phase), before the
 * option's label or radio acts on it. A click of its own, or a key, still
 * picks.
 *
 * @param {HTMLElement} dialog
 * @param {string} options selector of its options: labels holding a radio
 * @param {number} openedAt Date.now() when the dialog appeared
 */
export function ignoreOpeningClickOnOptions(dialog, options, openedAt) {
    dialog.addEventListener('click', (e) => {
        if (e.target.closest?.(options) && isOpeningClick(e, openedAt)) {
            e.preventDefault();
        }
    }, true);
}
