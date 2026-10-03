/**
 * Cross-currency transfer fields on the transaction form.
 *
 * The form's DOM is permanent and opening it used to add another listener to
 * the account and amount fields every time, so after N opens one keystroke
 * sent N conversion requests. The replies were also applied in whatever order
 * they landed, so a slow earlier one could overwrite the destination amount
 * with an old conversion.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

vi.mock('../../src/utils/api.js', () => ({
    apiFetch: vi.fn(),
    ApiError: class extends Error {},
}));

import TransactionsModule from '../../src/modules/transactions/TransactionsModule.js';
import { apiFetch } from '../../src/utils/api.js';

const ACCOUNTS = [
    { id: 1, name: 'Current', currency: 'GBP' },
    { id: 2, name: 'Euro', currency: 'EUR' },
];

function makeModule() {
    const mod = Object.create(TransactionsModule.prototype);
    mod.app = { accounts: ACCOUNTS, settings: {} };
    return mod;
}

function mount() {
    document.body.innerHTML = `
        <select id="transaction-account">
            <option value="1">Current</option>
            <option value="2">Euro</option>
        </select>
        <select id="transfer-to-account">
            <option value="1">Current</option>
            <option value="2">Euro</option>
        </select>
        <label id="transaction-amount-label"></label>
        <input id="transaction-amount" type="number">
        <div id="transfer-dest-amount-wrapper" style="display:block">
            <label id="transfer-dest-amount-label"></label>
            <input id="transfer-dest-amount" type="number">
        </div>`;
    document.getElementById('transaction-account').value = '1';
    document.getElementById('transfer-to-account').value = '2';
}

function deferred() {
    let resolve;
    const promise = new Promise(r => { resolve = r; });
    return { promise, resolve };
}

const tick = () => new Promise(resolve => setTimeout(resolve, 0));

beforeEach(() => {
    mount();
});

afterEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '';
});

describe('transfer amount fields', () => {
    it('binds the listeners once however often the form opens', async () => {
        apiFetch.mockResolvedValue({ convertedAmount: 11.5 });
        const mod = makeModule();

        mod._bindTransferAmountFields();
        mod._bindTransferAmountFields();
        mod._bindTransferAmountFields();

        const amount = document.getElementById('transaction-amount');
        amount.value = '10';
        amount.dispatchEvent(new Event('input'));
        await tick();

        expect(apiFetch).toHaveBeenCalledOnce();
        expect(document.getElementById('transfer-dest-amount').value).toBe('11.5');
    });

    it('marks the destination amount as edited by hand', () => {
        const mod = makeModule();
        mod._bindTransferAmountFields();
        const dest = document.getElementById('transfer-dest-amount');

        dest.dispatchEvent(new Event('input'));

        expect(dest.dataset.userEdited).toBe('true');
    });

    it('keeps the latest conversion when an earlier reply lands last', async () => {
        const slow = deferred();
        const fast = deferred();
        apiFetch.mockReturnValueOnce(slow.promise).mockReturnValueOnce(fast.promise);
        const mod = makeModule();
        const amount = document.getElementById('transaction-amount');
        const dest = document.getElementById('transfer-dest-amount');

        amount.value = '1';
        const first = mod._updateDestAmountFromRate();
        amount.value = '12';
        const second = mod._updateDestAmountFromRate();

        fast.resolve({ convertedAmount: 13.8 });
        await second;
        slow.resolve({ convertedAmount: 1.15 });
        await first;

        expect(dest.value).toBe('13.8');
    });
});
