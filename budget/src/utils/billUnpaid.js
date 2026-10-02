/**
 * Mark Unpaid for a bill or a recurring transfer.
 *
 * Reverting deletes the payment. When it was reconciled against a bank
 * statement the server refuses first (409, code "reconciled") so the user can
 * be told the account will no longer match that statement; only if they
 * still want to is the revert sent again with confirmReconciled.
 */

import { apiFetch, ApiError } from './api.js';
import { confirmDialog } from './dialogs.js';

/**
 * @param {number} billId
 * @param {string} errorMessage - Shown when a failure carries no message
 * @returns {Promise<boolean>} false when the user kept the reconciled payment
 * @throws {ApiError} on any other failure
 */
export async function requestMarkUnpaid(billId, errorMessage) {
    const url = `/apps/budget/api/bills/${billId}/unpaid`;
    try {
        await apiFetch(url, { method: 'POST', errorMessage });
        return true;
    } catch (error) {
        if (!(error instanceof ApiError) || error.status !== 409 || error.data?.code !== 'reconciled') {
            throw error;
        }
        if (!await confirmDialog(error.message, { destructive: true })) {
            return false;
        }
    }
    await apiFetch(url, { method: 'POST', body: { confirmReconciled: true }, errorMessage });
    return true;
}
