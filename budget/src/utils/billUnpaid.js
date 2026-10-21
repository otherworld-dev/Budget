/**
 * Mark Unpaid for a bill or a recurring transfer.
 *
 * Reverting deletes the payment. When it was reconciled against a bank
 * statement the server refuses first (409, code "reconciled") so the user can
 * be told the account will no longer match that statement; only if they
 * still want to is the revert sent again with confirmReconciled.
 */

import { translate as t } from '@nextcloud/l10n';
import { apiFetch, ApiError } from './api.js';
import { confirmDialog } from './dialogs.js';

/**
 * The question Mark Unpaid asks before reverting the bill's last payment.
 * Created transactions are deleted, a linked imported one only unlinked.
 * A payment Mark Paid found already in the ledger ("Use that payment") is
 * neither: it stays in the account, still filed under the bill (#399), and
 * the bill says when its last payment was one (lastPaymentUsedExisting).
 *
 * @param {object|undefined} bill bill or transfer
 * @return {string}
 */
export function markUnpaidQuestion(bill) {
    if (bill?.lastPaymentUsedExisting) {
        return t('budget', 'Mark this bill as unpaid? The bill schedule will be rolled back, and any transaction created for the payment deleted. The transaction the payment used was already in the account: it stays there, still filed under the bill.');
    }
    return t('budget', 'Mark this bill as unpaid? Transactions created for the payment will be deleted, a linked imported transaction will be unlinked, and the bill schedule rolled back.');
}

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
