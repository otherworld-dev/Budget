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
