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
