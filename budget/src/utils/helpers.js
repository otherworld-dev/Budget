/**
 * General utility helper functions
 */

/**
 * Debounce function calls to limit execution rate
 * @param {Function} func - Function to debounce
 * @param {number} wait - Wait time in milliseconds
 * @returns {Function} Debounced function
 */
export function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Download every transaction matching a filter as CSV.
 *
 * The server builds the file so the export covers all matching rows rather than
 * the page the browser happens to be holding (#344).
 *
 * @param {URLSearchParams} params - Transaction filters, as the list views build them
 * @param {string} filename - Suggested name (account or view); the server sanitises it
 * @returns {Promise<void>} Rejects if the request fails, for the caller to report
 */
export async function downloadTransactionsCsv(params, filename) {
    const query = new URLSearchParams(params);
    if (filename) {
        query.set('filename', filename);
    }

    const response = await fetch(
        OC.generateUrl('/apps/budget/api/transactions/export?' + query.toString()),
        { headers: { 'requesttoken': OC.requestToken } }
    );

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const named = /filename="?([^"]+)"?/.exec(disposition);

    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = named ? named[1] : 'transactions.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

/**
 * ISO weekday (1=Monday … 7=Sunday) of a Y-m-d date string, parsed as a plain
 * calendar date so no timezone can shift it. Mirrors PHP's format('N'), which
 * is what the server-side anchor maths uses (#363, #364).
 *
 * @param {string} dateStr - Date in Y-m-d form
 * @returns {number} 1-7, Monday first
 */
export function isoWeekday(dateStr) {
    const [y, m, d] = String(dateStr).split('-').map(Number);
    const day = new Date(y, m - 1, d).getDay(); // 0 = Sunday
    return day === 0 ? 7 : day;
}

/** Account types whose balance is stored negative (amount owed). */
export const LIABILITY_ACCOUNT_TYPES = ['credit_card', 'loan', 'mortgage', 'line_of_credit'];

/** Mirrors AccountType::isLiability() on the PHP side. */
export function isLiabilityType(type) {
    return LIABILITY_ACCOUNT_TYPES.includes(type);
}

/**
 * Whether a listed transaction is standing in for a share of itself.
 *
 * Filtering the list by a category also matches split transactions through
 * their parts, and the server marks such a row with the part that matched
 * (#359). A share of 0.00 is a real value, so this is a presence check, never
 * a truthiness one.
 *
 * @param {Object} tx - Transaction row as the API returns it
 * @returns {boolean}
 */
export function hasSplitPortion(tx) {
    return tx?.matchedSplitAmount !== undefined && tx?.matchedSplitAmount !== null;
}

/**
 * The magnitude a row should display and be totalled at: the share belonging
 * to the filtered category where there is one, otherwise the transaction's own
 * amount.
 *
 * @param {Object} tx - Transaction row as the API returns it
 * @returns {number}
 */
export function transactionDisplayAmount(tx) {
    return hasSplitPortion(tx) ? tx.matchedSplitAmount : (tx?.amount ?? 0);
}

/**
 * Build the message to show for a failed API response.
 *
 * The server's error handler attaches a sanitised driver error as `detail` when
 * the failure came from the database, so that a missing column or a constraint
 * violation is diagnosable without reading nextcloud.log. Showing only `error`
 * throws that away and leaves the user with a generic string (#362).
 *
 * @param {object|null|undefined} body - The parsed JSON error body
 * @param {string} fallback - Used when the server sent no message of its own
 * @returns {string}
 */
export function serverErrorMessage(body, fallback) {
    const message = body?.error || fallback;
    const detail = typeof body?.detail === 'string' ? body.detail.trim() : '';
    // A missing column or table means the app's update never finished, and the
    // server sends the command that finishes it. That is the only part of this
    // the reader can act on, so it goes last and unbracketed (#333).
    const hint = typeof body?.hint === 'string' ? body.hint.trim() : '';
    const withDetail = detail ? `${message} (${detail})` : message;
    return hint ? `${withDetail} — ${hint}` : withDetail;
}

/**
 * Collapse the per-row failures an import returns into one entry per reason.
 *
 * The server has always returned `errors` as {row, error} and the UI dropped
 * them into console.warn, telling the user to "check the server log" instead —
 * which on the instance in #333 meant a log the admin could not read at all.
 * A file where the same thing is wrong with fourteen rows is one problem, not
 * fourteen, so the rows are gathered under their message.
 *
 * @param {Array<{row: number|string, error: string}>} errors - As the import API returns them
 * @returns {Array<{message: string, rows: Array<number|string>, count: number}>} Most frequent first
 */
export function groupImportErrors(errors) {
    const groups = new Map();

    for (const entry of errors || []) {
        const message = (entry?.error ?? '').toString().trim() || 'Unknown error';
        if (!groups.has(message)) {
            groups.set(message, { message, rows: [], count: 0 });
        }
        const group = groups.get(message);
        group.count++;
        if (entry?.row !== undefined && entry?.row !== null) {
            group.rows.push(entry.row);
        }
    }

    return [...groups.values()].sort((a, b) => b.count - a.count);
}
