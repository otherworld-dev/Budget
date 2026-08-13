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
