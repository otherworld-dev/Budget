/**
 * Whether the admin has bank sync switched on, and the nav entry shown or
 * hidden to match.
 *
 * Kept apart from BankSyncModule so the check can run on every page load
 * while the module itself only loads when the Bank Sync view is opened.
 */

import { apiFetch } from '../../utils/api.js';

/**
 * @returns {Promise<{enabled: boolean}>} The server's status; disabled when it could not be read
 */
export async function refreshBankSyncNav() {
    try {
        const data = await apiFetch('/apps/budget/api/bank-sync/status');
        const navItem = document.getElementById('bank-sync-nav');
        if (navItem) {
            navItem.style.display = data.enabled ? '' : 'none';
        }
        return data;
    } catch (error) {
        console.error('Failed to check bank sync status:', error);
        return { enabled: false };
    }
}
