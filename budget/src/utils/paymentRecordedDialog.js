/**
 * Mark Paid and Mark Received on an occurrence the ledger already has a
 * payment for (#399). A bill made due again was paid a second time with one
 * click, so the server now books another only when asked to (409, code
 * payment_recorded or receipt_recorded, with the row it found) and the page
 * asks: use that payment, record another one, or leave it.
 */
import { translate as t } from '@nextcloud/l10n';
import * as formatters from './formatters.js';
import { apiFetch, ApiError } from './api.js';
import { isOpeningClick } from './doubleClick.js';

/** The 409 code each page's action answers with */
const CODES = { bill: 'payment_recorded', income: 'receipt_recorded' };

/** Closes the dialog on screen, if there is one */
let closeOpenDialog = null;

/**
 * Ask what to do about the payment the occurrence already has.
 *
 * @param {{id: number, date: string, amount: number}} existing the payment the server found
 * @param {object} settings user settings, for formatting
 * @param {object} [options]
 * @param {'bill'|'income'} [options.kind='bill'] the page asking
 * @param {string} [options.currency] the bill's or income's currency
 * @return {Promise<'use'|'another'|null>} null when the user leaves it
 */
export function askAboutRecordedPayment(existing, settings, { kind = 'bill', currency = '' } = {}) {
    return new Promise((resolve) => {
        // One at a time. A dialog still open for another bill is answered
        // as left: its action ends and lets go of that bill's lock, which
        // otherwise held its buttons until the page was reloaded.
        closeOpenDialog?.(null);
        document.getElementById('payment-recorded-modal')?.remove();

        const params = {
            date: formatters.formatDate(existing.date, settings),
            amount: formatters.formatCurrency(existing.amount, currency || settings?.default_currency || '', settings || {}),
        };
        // Plain text, set with textContent below
        const plain = { escape: false, sanitize: false };
        const message = kind === 'income'
            ? t('budget', 'This income already has a payment on {date} ({amount}).', params, undefined, plain)
            : t('budget', 'This bill already has a payment on {date} ({amount}).', params, undefined, plain);

        const modal = document.createElement('div');
        modal.id = 'payment-recorded-modal';
        modal.className = 'budget-modal-overlay';
        modal.innerHTML = `
            <div class="budget-modal" style="max-width: 480px;" role="dialog" aria-modal="true" aria-labelledby="payment-recorded-title">
                <div class="budget-modal-header">
                    <h2 id="payment-recorded-title">${t('budget', 'Existing Transaction Found')}</h2>
                    <button class="close-btn" title="${t('budget', 'Close')}" aria-label="${t('budget', 'Close')}">&times;</button>
                </div>
                <div class="budget-modal-body">
                    <p class="matching-tx-intro"></p>
                </div>
                <div class="budget-modal-footer">
                    <button class="cancel-btn">${t('budget', 'Cancel')}</button>
                    <button class="another-btn">${t('budget', 'Record another payment')}</button>
                    <button class="confirm-btn primary">${t('budget', 'Use that payment')}</button>
                </div>
            </div>
        `;
        modal.querySelector('.matching-tx-intro').textContent = message;
        document.body.appendChild(modal);
        const openedAt = Date.now();

        const close = (answer) => {
            if (closeOpenDialog === close) {
                closeOpenDialog = null;
            }
            document.removeEventListener('keydown', onKeydown, true);
            modal.remove();
            resolve(answer);
        };
        closeOpenDialog = close;
        const onKeydown = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                close(null);
            }
        };

        modal.querySelector('.close-btn').addEventListener('click', () => close(null));
        modal.querySelector('.cancel-btn').addEventListener('click', () => close(null));
        modal.querySelector('.another-btn').addEventListener('click', () => close('another'));
        modal.querySelector('.confirm-btn').addEventListener('click', () => close('use'));
        // Not on the second click of the double click on Mark Paid that
        // opened it, which lands on the backdrop (see utils/doubleClick.js)
        modal.addEventListener('click', (e) => {
            if (e.target === modal && !isOpeningClick(e, openedAt)) {
                close(null);
            }
        });
        document.addEventListener('keydown', onKeydown, true);
        modal.querySelector('.confirm-btn').focus();
    });
}

/**
 * Send a Mark Paid or Mark Received, asking first when the server finds the
 * occurrence already paid. "Use that payment" marks it paid (or received) on
 * that payment's date with nothing booked; "Record another payment" sends it
 * again, confirmed.
 *
 * @param {string} path the paid or received route
 * @param {object} body what the action sends
 * @param {object} settings user settings, for formatting
 * @param {object} [options]
 * @param {'bill'|'income'} [options.kind='bill'] the page sending it
 * @param {string} [options.currency] the bill's or income's currency
 * @param {string} [options.errorMessage] shown when a failure carries no message
 * @return {Promise<{result: *, usedPayment: {id: number, date: string, amount: number}|null}|null>}
 *   null when the user left it
 * @throws {ApiError} on any other failure
 */
export async function sendPayment(path, body, settings, { kind = 'bill', currency = '', errorMessage } = {}) {
    const post = (payload) => apiFetch(path, { method: 'POST', body: payload, errorMessage });
    let existing;
    try {
        return { result: await post(body), usedPayment: null };
    } catch (error) {
        existing = recordedPaymentIn(error, kind);
        if (existing === null) {
            throw error;
        }
    }

    const answer = await askAboutRecordedPayment(existing, settings, { kind, currency });
    if (answer === null) {
        return null;
    }
    if (answer === 'another') {
        return { result: await post({ ...body, confirmSecondPayment: true }), usedPayment: null };
    }
    const usePayment = kind === 'income'
        ? { createTransaction: false, receivedDate: existing.date, expectedDate: body.expectedDate ?? null }
        : { recordPayment: false, paidDate: existing.date, dueDate: body.dueDate ?? null };
    return { result: await post(usePayment), usedPayment: existing };
}

/**
 * The payment a 409 says the occurrence already has, or null for any other
 * failure (a refusal of another kind, or the other page's answer).
 *
 * @param {*} error
 * @param {'bill'|'income'} kind
 * @return {{id: number, date: string, amount: number}|null}
 */
function recordedPaymentIn(error, kind) {
    if (!(error instanceof ApiError) || error.status !== 409 || error.data?.code !== CODES[kind]) {
        return null;
    }
    const existing = error.data.existing;
    return existing && typeof existing.date === 'string' ? existing : null;
}
