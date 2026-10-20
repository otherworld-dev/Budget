/**
 * The Transactions list's Configure columns setting, applied to the table.
 *
 * Each hidden column's cells are hidden, and the table carries a
 * `hides-<column>` class per hidden column. style.css reads those classes
 * to leave the column out of the table's minimum width, which is what keeps
 * Description from shrinking to nothing (#399).
 */

/** Setting key => the cells' class */
export const TRANSACTION_COLUMN_CLASSES = {
    date: 'date-column',
    description: 'description-column',
    vendor: 'vendor-column',
    category: 'category-column',
    tags: 'tags-column',
    amount: 'amount-column',
    balance: 'balance-column',
    account: 'account-column',
};

/**
 * @param {HTMLTableElement} table
 * @param {Object<string, boolean>} visibility - Setting key => shown
 * @param {object} [options]
 * @param {boolean} [options.hasBalances] - Running balances came with the
 *   rows. Without them the Balance column is hidden whatever the setting.
 */
export function applyTransactionColumnVisibility(table, visibility, { hasBalances = false } = {}) {
    if (!table) return;

    Object.entries(visibility || {}).forEach(([key, visible]) => {
        const className = TRANSACTION_COLUMN_CLASSES[key];
        if (!className) return;

        const isVisible = !!visible && !(key === 'balance' && !hasBalances);

        table.querySelectorAll(`th.${className}, td.${className}`).forEach(cell => {
            cell.style.display = isVisible ? '' : 'none';
        });
        table.classList.toggle(`hides-${key}`, !isVisible);
    });
}
