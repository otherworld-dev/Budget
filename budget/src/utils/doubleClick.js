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
