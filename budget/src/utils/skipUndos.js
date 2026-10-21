/**
 * The open Undo toasts of skips (bills, transfers, income).
 *
 * Undoing a skip puts the item back on the date it skipped, and the server
 * takes every skip from that date on off with it. After two skips, the
 * older toast's Undo therefore undoes both, and the newer toast's Undo was
 * refused ('That date is after the bill's next due date') after 'Action
 * undone' (#399). An Undo that goes through settles the item's other skip
 * toasts from its date on: they close, and their Undo does nothing.
 */

const open = new Set();

/**
 * @param {object} undoData - The skip's undo data; marked spent when settled
 * @param {string} item - The item's key, e.g. 'bill-12'
 * @param {string|null} date - The date the skip skipped (Y-m-d)
 * @param {{dismiss: Function}|undefined} toast - The toast showUndoNotification gave
 */
export function trackSkipUndo(undoData, item, date, toast) {
    Object.assign(undoData, { skipItem: item, skipDate: date, skipToast: toast });
    open.add(undoData);
}

/** The skip's toast ran out or its Undo failed: it settles nothing */
export function forgetSkipUndo(undoData) {
    open.delete(undoData);
}

/** The skip was undone: so was every skip of the same item from its date on */
export function settleSkipUndos(undoData) {
    open.delete(undoData);
    if (!undoData.skipItem || !undoData.skipDate) return;
    for (const other of [...open]) {
        if (other.skipItem === undoData.skipItem && other.skipDate && other.skipDate >= undoData.skipDate) {
            other.spent = true;
            open.delete(other);
            other.skipToast?.dismiss?.();
        }
    }
}
