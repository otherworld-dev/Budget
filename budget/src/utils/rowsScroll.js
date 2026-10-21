/**
 * Where a list's rows start when it loads (#399).
 *
 * Above phone width the Transactions list and an account's register scroll
 * their rows in a box of their own (.table-scroll), so the header row can
 * stick to its top. That box kept its place from one load to the next, so
 * page 2 opened scrolled to where page 1 was left, its first rows out of
 * view. A list loading a different query (another page, rows per page,
 * filters, sort or account) starts at the top of the box; the same query
 * loaded again (a reload after a save) keeps its place, as does the table
 * drawn again for an inline edit, which doesn't come through here. The
 * sideways position is left as it is.
 */

/** The query each table last showed */
const shown = new WeakMap();

/**
 * Call once a load's rows are drawn in `table`, with what picked them
 * (the request's URL or query string).
 */
export function showRowsOf(table, query) {
    if (!table) return;
    const box = table.closest('.table-scroll');
    if (box && shown.get(table) !== query) box.scrollTop = 0;
    shown.set(table, query);
}
